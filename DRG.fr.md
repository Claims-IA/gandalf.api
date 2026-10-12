# Gandalf — Graphe d'exigences décisionnelles (DRG)

> **Spécification lisible par machine :** [openapi.yaml](openapi.yaml) (tag `Flows`)
> **Docs associées :** [DOCUMENTATION.fr.md](DOCUMENTATION.fr.md) · [API_GUIDE.fr.md](API_GUIDE.fr.md)

Un **graphe d'exigences décisionnelles** (Decision Requirement Graph) compose
plusieurs **tables** de décision existantes en une décision de plus haut niveau :
la sortie d'une table alimente un champ d'entrée d'une autre. C'est l'équivalent
Gandalf du Decision Requirements Graph de DMN (le concept que l'on trouve dans
Camunda, Drools, etc.).

Les tables elles-mêmes restent **inchangées** — chaque champ garde
`source: request`. Tout le câblage vit sur le **Flow**. Une table peut être un
nœud de plusieurs flows et continuer d'être appelée directement via
`POST /tables/{id}/decisions`.

Les flows sont versionnés, et chaque nœud est figé sur une version de sa table :
voir [Versions](#versions).

## Table des matières

1. [Modèle mental](#modèle-mental)
2. [Modèle de données](#modèle-de-données)
3. [Le contrat `answer` unifié](#le-contrat-answer-unifié)
4. [Cycle de vie](#cycle-de-vie)
   - [Versions](#versions)
5. [Règles de validation du graphe](#règles-de-validation-du-graphe)
6. [Exécution](#exécution)
7. [Compatibilité de types](#compatibilité-de-types)
8. [API HTTP](#api-http)
9. [Exemple complet](#exemple-complet)
10. [Gestion des erreurs](#gestion-des-erreurs)
11. [Carte du code](#carte-du-code)
12. [Étendre le DRG](#étendre-le-drg)
13. [Limitations actuelles](#limitations-actuelles)

---

## Modèle mental

Un Flow est un graphe orienté acyclique :

- **nodes** — chacun référence une table de décision (`{ node_id, table_id }`).
- **edges** — câblent une valeur vers un champ d'entrée d'un nœud. La source est
  soit une **entrée** de flow déclarée, soit la **sortie d'un nœud amont**.
- **inputs** — le contrat d'entrée public du flow (`{ key, type }`).
- **outputs** — le contrat de sortie public du flow
  (`{ name, from_node, from_output }`), les valeurs nommées que le flow renvoie.

```
             entrée de flow "salary" ─┐
                                       ▼
   ┌─────────────┐   edge   ┌──────────────┐
   │  n_risk     │─────────▶│  n_verdict   │──▶ sortie "verdict"
   │ (scoring)   │  risk →  │ (first-match)│
   └─────────────┘  champ   └──────────────┘
        ▲                          ▲
        └── entrée de flow "history" ┘
```

À l'exécution, le moteur évalue les nœuds en **ordre topologique** (chaque nœud
après ceux dont il dépend), en alimentant chaque table uniquement avec les champs
qu'elle déclare, puis assemble les `outputs` nommés du flow à partir des
résultats de chaque nœud.

---

## Modèle de données

### Flow (collection `flows`)

`App\Models\Flow` — cloisonné par application (multi-tenant) via
`ApplicationableTrait`.

```jsonc
{
  "_id": "6a5437d8ad556857e10c1380",
  "title": "Credit approval",
  "description": "",

  // Contrat d'entrée public. `type` ∈ numeric | boolean | string | date.
  "inputs": [
    { "key": "salary",  "type": "numeric" },
    { "key": "history", "type": "string"  }
  ],

  // Une entrée par table référencée.
  "nodes": [
    { "node_id": "n_risk",    "table_id": "69b29698c33fe7db7106c697", "table_version_id": "6a54…c1" },
    { "node_id": "n_verdict", "table_id": "69b0a1c2c33fe7db7106c701", "table_version_id": "6a54…c2" }
  ],

  // Câblage. `from` est une entrée de flow ({input}) OU la sortie d'un nœud
  // amont ({node, output}) ; `into` est un champ aval ({node, field}).
  "edges": [
    { "from": { "input": "salary" },              "into": { "node": "n_risk",    "field": "salary"  } },
    { "from": { "input": "history" },             "into": { "node": "n_risk",    "field": "history" } },
    { "from": { "node": "n_risk", "output": "final_decision" },
                                                  "into": { "node": "n_verdict", "field": "risk"    } }
  ],

  // Contrat de sortie public. Chaque sortie nomme une sortie de nœud.
  "outputs": [
    { "name": "verdict", "from_node": "n_verdict", "from_output": "final_decision" },
    { "name": "risk",    "from_node": "n_risk",    "from_output": "final_decision" }
  ]
}
```

> `from_output` vaut toujours `final_decision` aujourd'hui (voir
> [Limitations actuelles](#limitations-actuelles)). Si une arête omet `output`,
> il vaut `final_decision` par défaut.

### FlowRun (collection `flow_runs`)

`App\Models\FlowRun` — un document par exécution, reliant les différents
documents `Decision` qu'une même exécution produit. Il remplit le rôle
qu'esquissait le champ `group` inutilisé de `Decision`.

```jsonc
{
  "_id": "6a5437d895812a10c20a7630",
  "flow": { "_id": "6a5437d8ad556857e10c1380", "title": "Credit approval",
            "version": { "_id": "6a5437d8ad556857e10c1390", "number": 3 } },
  "application": "507f1f77bcf86cd799439011",
  "inputs": { "salary": 1500, "history": "clean" },
  "answer": { "verdict": "approved", "risk": 40 },
  "nodes": [
    { "node_id": "n_risk",    "table_id": "69b2…", "input": { "salary": 1500, "history": "clean" },
      "decision_id": "6a54…a1", "answer": { "final_decision": 40 } },
    { "node_id": "n_verdict", "table_id": "69b0…", "input": { "risk": 40 },
      "decision_id": "6a54…b2", "answer": { "final_decision": "approved" } }
  ],
  "error": null,  // { "errors": ["…"] } sur une exécution échouée/partielle
  "test": false   // une exécution de test depuis gandalf.web
}
```

Chaque nœud de la trace porte aussi `table_version` (`{ _id, number }` de la
version de table exécutée), et la `Decision` de chaque nœud porte
`flow: { _id, version: { _id, number } }`. Le document du flow porte
`active_version_id`, et chaque nœud `table_version_id` (voir [Versions](#versions)).

---

## Le contrat `answer` unifié

Une table seule et un DRG renvoient la **même enveloppe de sortie**, si bien
qu'un consommateur parse les deux de la même façon. Elle est produite par
`Decision::toConsumerArray()` pour une table et par `FlowEngine::run()` pour un
flow.

| Clé             | Décision de table                                | Exécution de flow (DRG)                 |
| --------------- | ------------------------------------------------ | --------------------------------------- |
| `answer`        | `{ final_decision }`                             | `{ <nomSortie>: valeur, … }`            |
| `answer_types`  | `{ final_decision: <type> }`                     | `{ <nomSortie>: <type>, … }`            |
| `decision_kind` | `table_simple` (first) / `table_advanced` (scoring) | `drg`                                |
| (table seule)   | `final_decision` reste aussi à la racine         | —                                       |
| (flow seul)     | —                                                | `flow_run_id`, `nodes[]` (trace par nœud) |

Le **type** de sortie est dérivé, pas stocké :

- une table `scoring_*` → `numeric` (numérique par nature, mono-sortie par
  construction) ;
- une table `first`-match → son `decision_type` déclaré (`alpha_num` | `numeric`
  | `string` | `json`).

`Decision::getOutputType()` et `FlowRepository::tableOutputType()` implémentent
le même mapping ; gardez-les synchronisés si vous le modifiez.

---

## Cycle de vie

```
  Auteur (UI web / API)                    Consommateur
        │                                      │
        │ POST/PUT /admin/flows                │ POST /flows/{id}/decisions
        ▼                                      ▼
  FlowRepository::createOrUpdate         ConsumerController::flowCheck
        │  validateGraph() ──► 422 si erreur   │  assertActiveAdmin()
        ▼                                      ▼
  document flows sauvegardé              FlowEngine::run()
                                               │  orderNodes() (topo)
                                               │  par nœud : buildNodeInput → Scoring::check
                                               │  assembleOutputs()
                                               ▼
                                         FlowRun sauvegardé + answer renvoyé
```

La validation a lieu **deux fois**, à dessein :

- **au save** — `validateGraph()` rejette un graphe malformé (références,
  couverture, types, cycles) pour que les erreurs d'édition remontent
  immédiatement ;
- **au run** — le moteur revérifie que la table d'un nœud existe toujours (une
  table a pu être supprimée après la sauvegarde du flow) et Lumen valide les
  champs de chaque nœud.

---

## Versions

Les flows sont versionnés comme les tables ([VERSIONS.fr.md](VERSIONS.fr.md),
lot 4). Le document du flow garde son identité et pointe vers sa **version
active** (`active_version_id`) ; une **version de flow** (`flow_versions`) porte
le graphe : `inputs`, `outputs`, `nodes`, `edges`. Un brouillon se modifie, se
publie (figé et numéroté), puis s'active ; l'API exécute la version active.

**Chaque nœud est figé sur une version publiée de sa table**
(`nodes[].table_version_id`). Activer une nouvelle version de table ne change
donc rien pour un flow : il continue d'exécuter la version sur laquelle il est
figé jusqu'à ce qu'une nouvelle version du flow en fige une autre. Un nœud
enregistré sans version figée garde la sienne, ou prend la version active de sa
table ; figer un nœud sur un brouillon de table est refusé.

| Situation | Comportement d'un flow |
| --------- | ---------------------- |
| Mode de compatibilité actif (comportement de la mise en production A, `TABLE_VERSIONS_LEGACY_AUTOPUBLISH`) | Un enregistrement du flow publie et active une version quand le graphe change ; les flows suivent chaque activation des versions de leurs tables (une version qui fige la nouvelle, publiée et activée) et leurs renommages de champs |
| Mode de compatibilité inactif (mise en production B) | Les flows restent sur leurs versions figées. Activer une version de table liste les flows restés en arrière (`meta.flows_outdated`) ; `POST /admin/tables/{id}/flows/update` les amène à la version active |

**Amener des flows à la version active d'une table** : pour chaque flow dont la
version active fige la table ailleurs, un brouillon tiré de cette version fige
la version active de la table, et ses fils suivent les renommages de champs
entre les deux versions de table (mêmes règles qu'une mise à jour de table, voir
[API HTTP](#api-http)). Un flow dont le graphe ne valide plus est signalé
`incompatible` et laissé tel quel. Les brouillons peuvent être publiés, puis
activés, dans le même appel (`publish`, `activate`, avec les droits
`tables_publish` et `tables_activate`).

**Flows signalés** : la liste des flows porte `outdated: true` pour un flow qui
exécute une autre version que la version active de sa table, et
`GET /admin/tables/{id}/flows` liste les flows qui utilisent une table, avec la
version exécutée par chaque nœud.

**Garde-fous** : une version de table sur laquelle un flow ou une version de flow
est figé ne peut pas être supprimée (`409 version_in_use`), pas plus que la
version active d'un flow ou une version de flow qu'une clé d'API appelle encore.

**Migration** (`2026_10_10_150000_FlowVersions`) : chaque flow devient sa
version 1, publiée et active, chaque nœud figé sur la version de sa table active
au moment de la migration (celle qu'il exécutait). Idempotente ; le retour
arrière supprime `flow_versions`, les versions figées et `active_version_id`.

**Copie et déplacement** : une copie démarre ses propres versions, ses nœuds
figés sur les versions actives des tables cibles. Les versions d'un flow déplacé
figeaient les tables du projet source : elles sont supprimées et le flow repart
de la version 1 dans le projet cible.

---

## Règles de validation du graphe

`FlowRepository::validateGraph()` collecte **tous** les problèmes et lève une
seule `FlowValidationException` (rendue en un unique 422 avec un tableau
`data.errors`). Les contrôles, et le message que chacun produit :

| Règle | Exemple de message |
| ----- | ------------------ |
| Chaque nœud a un `node_id` | `A node is missing node_id.` |
| Les node ids sont uniques | `Duplicate node_id 'n_risk'.` |
| Le `table_id` d'un nœud existe **dans ce projet** | `Node 'n_risk' references a table that does not exist in this project.` |
| Le `table_version_id` d'un nœud est une version de sa table | `Node 'n_risk' is pinned to a version that is not a version of its table.` |
| ... et une version publiée | `Node 'n_risk' is pinned to a draft: pin a published version of its table.` |
| Une arête cible un nœud connu | `Edge #2 targets an unknown node.` |
| Le champ cible est un vrai champ de la table du nœud | `Edge #2 targets field 'x', which is not a field of node 'n_verdict'.` |
| Aucun champ câblé par plus d'une arête | `Field 'risk' of node 'n_verdict' is wired by more than one edge.` |
| Une source d'entrée est une entrée de flow déclarée | `Edge #2 sources input 'foo', which is not a declared flow input.` |
| Une source de nœud est un nœud connu | `Edge #2 sources an unknown node.` |
| La sortie source est `final_decision` | `Edge #2 sources output 'x'; only 'final_decision' is available today.` |
| Une sortie `json` ne peut alimenter une entrée de table | `Edge #2: node 'n_risk' outputs json, which cannot feed a table input.` |
| Types source et cible compatibles | `Edge #2: output of 'n_risk' (numeric) is not compatible with field 'name' (string).` |
| **Couverture des champs** — chaque champ de table est alimenté par une arête ou une entrée de même nom | `Field 'salary' of node 'n_risk' is not fed by any edge or flow input.` |
| Le graphe est acyclique | `The graph contains a cycle; a decision graph must be acyclic.` |
| Au moins une sortie, nommée uniquement, résolvant vers un nœud connu | `The flow must declare at least one output.` / `Duplicate output name 'verdict'.` / `Output #0 references an unknown node.` |

Le contrôle de cycle s'exécute **indépendamment des autres erreurs**, si bien
qu'un graphe qui comporte à la fois un cycle et, disons, une sortie erronée
signale les deux en une seule réponse au lieu de ne révéler le cycle qu'au second
save.

> La **couverture des champs** est la raison pour laquelle une exécution partielle
> n'échoue jamais silencieusement sur un champ manquant : si un champ de table
> n'est ni câblé ni couvert par une entrée de même nom, le flow est rejeté au save
> plutôt que de renvoyer un 422 à une exécution ultérieure.

---

## Exécution

`FlowEngine::run($flowId, array $inputs, $appId, $showMeta)` renvoie :

```jsonc
{
  "flow_run_id": "6a5437d895812a10c20a7630",
  "answer":       { "verdict": "approved", "risk": 40 },
  "answer_types": { "verdict": "string",   "risk": "numeric" },
  "decision_kind": "drg",
  "nodes": [ /* trace par nœud, même forme que FlowRun.nodes */ ]
}
```

Étape par étape :

1. **Ordonner** — `orderNodes()` construit la carte de dépendances à partir des
   arêtes nœud→nœud et appelle `GraphSort::order()` (algorithme de Kahn). Un
   résultat `null` signifie un cycle → `FlowValidationException`.
2. **Par nœud**, dans l'ordre :
   - `requireTable()` recharge la table, cloisonnée à l'application courante, et
     échoue clairement si elle a été supprimée depuis la sauvegarde du flow.
   - `buildNodeInput()` assemble **uniquement les champs propres à cette table** :
     chaque champ est alimenté par son arête câblée si elle existe, sinon par une
     entrée de flow de même nom. Les entrées non liées — y compris `variant_id`,
     qui est local à la table — ne sont jamais transmises à un nœud.
   - le nœud exécute la version de table sur laquelle il est figé (la version
     active de sa table s'il n'en a pas), et `buildNodeInput()` lit les champs
     de cette version ;
   - `Scoring::evaluate()` l'évalue et renvoie l'enveloppe unifiée (portant
     `answer`, `answer_types`, `_id`).

   Le moteur exécute la version active du flow, ou la version demandée, et sa
   réponse porte `flow: { _id, version }`.
3. **Assembler** — `assembleOutputs()` lit le `from_node` / `from_output` de
   chaque sortie déclarée depuis les résultats des nœuds vers `answer` /
   `answer_types`.
4. **Persister** — un `FlowRun` relie l'exécution à chaque `Decision` produite
   (via `nodes[].decision_id`), cloisonné à l'application.

`resolveEdgeValue()` utilise `array_key_exists` pour distinguer une sortie amont
**absente** (une erreur) d'une valeur légitimement **null** (autorisée à
circuler en aval).

### GraphSort

`App\Services\GraphSort::order(array $nodeIds, array $dependencies)` est l'unique
source de vérité pour l'ordre topologique / la détection de cycle, partagée par le
dépôt (rejeter les graphes cycliques au save) et le moteur (évaluer dans l'ordre
de dépendance). Renvoie les ids ordonnés, ou `null` s'il subsiste un cycle.

---

## Compatibilité de types

Une arête n'est valide que si sa source et sa cible appartiennent à la **même
famille de types** (`FlowRepository::typesCompatible` / `typeFamily`) :

| Famille   | Membres                          |
| --------- | -------------------------------- |
| `text`    | `string`, `alpha_num`            |
| `numeric` | `numeric`, `number`, `integer`   |
| `boolean` | `boolean`, `bool`                |
| `date`    | `date`                           |
| —         | `json` (et tout type inconnu) n'est **jamais** câblable, en entrée comme en sortie |

Ainsi un score numérique ne peut alimenter un champ `string`, un champ
`boolean` ne peut être alimenté que par une source `boolean`, et une entrée
`date` ne peut alimenter qu'un champ `date` (et un champ `date` ne peut être
alimenté que par une entrée `date`). Un désaccord est rejeté au save avec un 422.

> **Note :** une sortie de table est toujours `numeric` (scoring) ou le
> `decision_type` de la table (`alpha_num` | `numeric` | `string` | `json`) —
> **jamais `boolean` ni `date`**. Un champ `boolean` ou `date` aval ne peut donc
> être alimenté que par une entrée de flow du même type, pas par un nœud amont :
> une date ne circule jamais entre nœuds.

À l'exécution, une entrée `date` prend les mêmes valeurs qu'un champ `date` de
table : une date ISO 8601 `AAAA-MM-JJ` (une date-heure est acceptée, sa partie
heure ignorée) ; une valeur invalide fait échouer la validation des champs du
nœud avec une 422 (voir [API_GUIDE.fr.md](API_GUIDE.fr.md#types-de-champs)).

---

## API HTTP

Toutes les routes sont cloisonnées par application (en-tête `X-Application`) et
protégées par ACL.

| Méthode & chemin | Handler | Scope ACL |
| ---------------- | ------- | --------- |
| `GET    /api/v1/admin/flows` | lister les flows (filtres : `title`, `description`, `category_id`, `table_id`) | `tables_view` |
| `POST   /api/v1/admin/flows` | créer un flow | `tables_create` |
| `GET    /api/v1/admin/flows/{id}` | lire un flow | `tables_view` |
| `PUT    /api/v1/admin/flows/{id}` | mettre à jour un flow | `tables_update` |
| `DELETE /api/v1/admin/flows/{id}` | supprimer un flow | `tables_delete` |
| `GET    /api/v1/admin/flows/{id}/runs` | historique paginé des exécutions | `tables_view` |
| `POST   /api/v1/admin/flows/{id}/copyto/{project_id}` | copier le flow vers un autre projet | `tables_create` |
| `POST   /api/v1/admin/flows/{id}/moveto/{project_id}` | déplacer le flow vers un autre projet | `tables_create`, `tables_delete` |
| `POST   /api/v1/flows/{id}/decisions` | **exécuter le flow** (version active ; obsolète) | `decisions_make` |
| `POST   /api/v2/flows/{id}/decisions` | exécuter une version : `{ version, data }` (une autre version que la version active exige `decisions_make_inactive`) | `decisions_make` |
| `GET    /api/v1/admin/flows/{id}/versions` | brouillons et versions publiées | `tables_view` |
| `POST   /api/v1/admin/flows/{id}/versions` | créer un brouillon (`from_version_id`, `name`) | `tables_update` |
| `GET/PUT/DELETE /api/v1/admin/flows/{id}/versions/{version_id}` | lire, modifier un brouillon, supprimer (une version publiée : + `tables_publish`) | `tables_view` / `tables_update` |
| `POST   /api/v1/admin/flows/{id}/versions/{version_id}/publish` | publier un brouillon (`comment`) | `tables_publish` |
| `POST   /api/v1/admin/flows/{id}/versions/{version_id}/decisions` | exécution de test d'un brouillon ou d'une version (`{ data }`, marquée `test`) | `decisions_make` ; `tables_update` pour un brouillon |
| `POST   /api/v1/admin/flows/{id}/activate` | activer une version publiée (`version_id`) | `tables_activate` |
| `GET    /api/v1/admin/flows/{id}/calls` | appels récents des clés d'API du projet | `tables_view` |
| `GET    /api/v1/admin/tables/{id}/flows` | flows qui utilisent une table, version par nœud | `tables_view` |
| `POST   /api/v1/admin/tables/{id}/flows/update` | amener des flows à la version active de la table | `tables_update` (+ `tables_publish`, `tables_activate`) |

La liste des exécutions (`GET /admin/flows/{id}/runs`) exclut les exécutions de
test ; `test=true` les liste à la place.

Le CRUD est fourni par l'`AbstractController` Nebo15 ; les écritures passent par
`FlowRepository::createOrUpdate`, où le graphe est validé.

**Sémantique du PUT :** un `PUT` ne remplace que les clés présentes dans le corps
— une mise à jour partielle (p. ex. `title` seul) fusionne par-dessus le graphe
stocké et n'efface jamais `nodes`/`edges`/`outputs`. C'est le graphe fusionné qui
est revalidé.

**Renommage d'un champ de table** (même `_id` de champ, nouvelle `key`, via la
mise à jour de la table), en mode de compatibilité (voir [Versions](#versions) ;
sinon les renommages s'appliquent quand un flow est amené à la nouvelle version
de table) : les flows du projet qui utilisent la table suivent le
renommage. Les fils vers le champ visent la nouvelle clé, et un champ qui était
alimenté implicitement par l'entrée du flow de même nom reçoit un fil explicite
depuis cette entrée : le flow garde ses entrées et son comportement. Quand un
champ est supprimé et un autre renommé avec sa clé dans la même mise à jour, le
fil vers le champ supprimé est retiré ; un fil qu'aucun fil de champ renommé ne
remplace est conservé. Chaque flow est enregistré séparément, et le `meta` de la
réponse de mise à jour de la table indique `field_renames`, `flows_updated`,
`flows_failed` (non mis à jour, à corriger à la main) et `flows_invalid` (mis à
jour, mais le graphe ne passe plus la validation, par exemple une entrée non
typée qui alimentait implicitement un champ numérique l'alimente désormais par
un fil explicite, dont le type est vérifié : le flow s'exécute comme avant, son
prochain enregistrement est refusé tant qu'il n'est pas corrigé). Un rollback de
la table via le changelog fait suivre les clés restaurées de la même façon.
`GET /api/v1/admin/flows?table_id=…` liste les flows qui utilisent une table.

**Copie et déplacement vers un autre projet** (réservés aux admins du projet, qui
doivent aussi être membres du projet cible avec le scope `tables_create`) : un flow ne s'exécute que si ses
tables sont dans son propre projet, donc ses tables sont amenées dans le projet
cible et le `table_id` de chaque nœud est réaffecté. Une table déjà présente dans
la cible est réutilisée au lieu d'être recopiée si elle a la même origine (la
table d'origine elle-même, ou une copie antérieure : chaque copie de table retient
sa table racine) et si le graphe peut l'utiliser telle quelle : chaque champ
branché existe encore avec le même type, chaque autre champ est encore alimenté
par une entrée du flow de même nom, et sa sortie garde sa famille de types
lorsqu'elle alimente un autre nœud. Sinon la table est dupliquée. Copier plusieurs
flows qui partagent une table n'amène donc cette table qu'une fois. Un déplacement
ne déplace jamais les tables : le projet source les garde pour ses autres flows.

---

## Exemple complet

Créer un flow à deux nœuds (`curl`, token bearer + `X-Application` supposés) :

```bash
curl -X POST https://gandalf.example/api/v1/admin/flows \
  -H "Authorization: Bearer $TOKEN" -H "X-Application: $APP" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Credit approval",
    "inputs":  [ {"key":"salary","type":"numeric"}, {"key":"history","type":"string"} ],
    "nodes":   [ {"node_id":"n_risk","table_id":"<scoring_table>"},
                 {"node_id":"n_verdict","table_id":"<first_match_table>"} ],
    "edges":   [ {"from":{"input":"salary"},  "into":{"node":"n_risk","field":"salary"}},
                 {"from":{"input":"history"}, "into":{"node":"n_risk","field":"history"}},
                 {"from":{"node":"n_risk","output":"final_decision"},
                  "into":{"node":"n_verdict","field":"risk"}} ],
    "outputs": [ {"name":"verdict","from_node":"n_verdict","from_output":"final_decision"},
                 {"name":"risk","from_node":"n_risk","from_output":"final_decision"} ]
  }'
```

L'exécuter :

```bash
curl -X POST https://gandalf.example/api/v1/flows/<flow_id>/decisions \
  -H "Authorization: Bearer $TOKEN" -H "X-Application: $APP" \
  -H "Content-Type: application/json" \
  -d '{ "salary": 1500, "history": "clean" }'
```

Réponse :

```jsonc
{
  "flow_run_id": "6a5437d895812a10c20a7630",
  "answer":       { "verdict": "approved", "risk": 40 },
  "answer_types": { "verdict": "alpha_num", "risk": "numeric" },
  "decision_kind": "drg",
  "nodes": [ /* … trace par nœud … */ ]
}
```

Consulter l'historique :

```bash
curl https://gandalf.example/api/v1/admin/flows/<flow_id>/runs \
  -H "Authorization: Bearer $TOKEN" -H "X-Application: $APP"
```

---

## Gestion des erreurs

Une `FlowValidationException` est rendue en **HTTP 422** :

```jsonc
{
  "meta": { "code": 422, "error": "flow_validation", "error_message": "Flow graph validation failed" },
  "data": {
    "errors": [ "Edge #2: output of 'n_risk' (numeric) is not compatible with field 'name' (string)." ],
    "flow_run_id": "6a54…"   // présent seulement pour un échec à l'EXÉCUTION, pas au save
  }
}
```

- Les échecs **au save** (graphe erroné) n'ont pas de `flow_run_id`.
- Les échecs **à l'exécution** enregistrent d'abord un `FlowRun` partiel, puis
  exposent son `flow_run_id` pour que vous puissiez corréler le 422 avec la trace
  persistée (`GET /admin/flows/{id}/runs`). L'exécution partielle stocke les mêmes
  messages sous `error.errors`.
- L'erreur de validation par champ d'un nœud (une `ValidationException` Lumen,
  dont le `getMessage()` est vide) est aplatie via `errors()->all()` pour que les
  messages ne soient jamais perdus — voir `FlowEngine::errorMessages()`.

---

## Carte du code

| Fichier | Responsabilité |
| ------- | -------------- |
| `app/Models/Flow.php` | Document Flow (titre, version active, copie de son graphe) |
| `app/Models/FlowVersion.php` | Une version du graphe d'un flow, nœuds figés sur des versions de table |
| `app/Services/FlowVersioning.php` | Version active, versions figées, mode de compatibilité (`syncFromFlow`, `followTable`), publication |
| `app/Repositories/FlowVersionsRepository.php` | Brouillons, publication, activation, garde-fous, `updateToActive()` |
| `app/Http/Controllers/FlowVersionsController.php` | Routes des versions de flow |
| `app/Models/FlowRun.php` | Une exécution enregistrée, reliant ses décisions |
| `app/Repositories/FlowRepository.php` | CRUD, `validateGraph()`, `getRuns()`, compatibilité de types |
| `app/Services/FlowEngine.php` | `run()` — exécution topologique, câblage, persistance |
| `app/Services/GraphSort.php` | Tri topologique / détection de cycle partagés (Kahn) |
| `app/Http/Controllers/FlowsController.php` | CRUD admin + `runs()` |
| `app/Http/Controllers/ConsumerController.php` | `flowCheck()` et `flowCheckV2()` : les endpoints d'exécution |
| `app/Exceptions/FlowValidationException.php` | Porte `errors[]` + `flow_run_id` optionnel |
| `app/Exceptions/Handler.php` | Rend le 422 |
| `app/Models/Decision.php` | Enveloppe unifiée `answer`/`answer_types`/`decision_kind` |
| `config/applicationable.php` | Règles ACL des routes de flow |
| `app/Http/routes.php` | Enregistrement des routes |

---

## Étendre le DRG

- **Une nouvelle règle de validation** → ajoutez-la dans
  `FlowRepository::validateGraph()`, en poussant un message sur `$errors` (ne levez
  pas au milieu de la boucle — collectez tous les problèmes, puis levez une seule
  fois). Ajoutez une ligne au [tableau des règles](#règles-de-validation-du-graphe)
  ci-dessus.
- **Une nouvelle famille de types / un synonyme** → éditez
  `FlowRepository::typeFamily()`. Si le typage de sortie change, gardez
  `Decision::getOutputType()` et `FlowRepository::tableOutputType()` en phase.
- **Plusieurs sorties nommées par table** → aujourd'hui une sortie vaut toujours
  `final_decision`. Pour en gérer plusieurs, étendez la map `answer` produite par
  `Scoring::check`/`toConsumerArray`, puis assouplissez les contrôles
  `from_output === 'final_decision'` dans `validateGraph` et `FlowEngine`.
  L'évaluation complète et le plan sont dans [OUTPUTS.fr.md](OUTPUTS.fr.md).
- **Tout ce qui touche à l'ordre d'exécution** → passez par `GraphSort` pour que
  l'invariant d'acyclicité reste au même endroit.

---

## Limitations actuelles

Reportées à dessein (notées comme évolutions futures) :

- **Une seule sortie par nœud** — `from_output` vaut toujours `final_decision`.
- **Pas de sortie de table `boolean` ni `date`** — `decision_type` ne peut être
  ni `boolean` ni `date`, donc le câblage booléen ou date de nœud à nœud est
  impossible (voir [Compatibilité de types](#compatibilité-de-types)).
- **Pas de sélection de variante par nœud** — un nœud exécute la variante
  auto-sélectionnée / par défaut de sa table ; `variant_id` n'est pas transmis par
  nœud.
- **Pas de flows imbriqués** — un nœud référence une table, pas un sous-flow (la
  récursion / les cycles inter-flows ont été jugés non essentiels au départ).
- **Exécution séquentielle** — les nœuds s'exécutent un par un en ordre
  topologique ; il n'y a pas d'évaluation parallèle des branches indépendantes.
