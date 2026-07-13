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

## Table des matières

1. [Modèle mental](#modèle-mental)
2. [Modèle de données](#modèle-de-données)
3. [Le contrat `answer` unifié](#le-contrat-answer-unifié)
4. [Cycle de vie](#cycle-de-vie)
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

  // Contrat d'entrée public. `type` ∈ numeric | boolean | string.
  "inputs": [
    { "key": "salary",  "type": "numeric" },
    { "key": "history", "type": "string"  }
  ],

  // Une entrée par table référencée.
  "nodes": [
    { "node_id": "n_risk",    "table_id": "69b29698c33fe7db7106c697" },
    { "node_id": "n_verdict", "table_id": "69b0a1c2c33fe7db7106c701" }
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
  "flow": { "_id": "6a5437d8ad556857e10c1380", "title": "Credit approval" },
  "application": "507f1f77bcf86cd799439011",
  "inputs": { "salary": 1500, "history": "clean" },
  "answer": { "verdict": "approved", "risk": 40 },
  "nodes": [
    { "node_id": "n_risk",    "table_id": "69b2…", "input": { "salary": 1500, "history": "clean" },
      "decision_id": "6a54…a1", "answer": { "final_decision": 40 } },
    { "node_id": "n_verdict", "table_id": "69b0…", "input": { "risk": 40 },
      "decision_id": "6a54…b2", "answer": { "final_decision": "approved" } }
  ],
  "error": null   // { "errors": ["…"] } sur une exécution échouée/partielle
}
```

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

## Règles de validation du graphe

`FlowRepository::validateGraph()` collecte **tous** les problèmes et lève une
seule `FlowValidationException` (rendue en un unique 422 avec un tableau
`data.errors`). Les contrôles, et le message que chacun produit :

| Règle | Exemple de message |
| ----- | ------------------ |
| Chaque nœud a un `node_id` | `A node is missing node_id.` |
| Les node ids sont uniques | `Duplicate node_id 'n_risk'.` |
| Le `table_id` d'un nœud existe **dans ce projet** | `Node 'n_risk' references a table that does not exist in this project.` |
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
   - `Scoring::check()` évalue la table sans modification et renvoie l'enveloppe
     unifiée (portant `answer`, `answer_types`, `_id`).
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
| —         | `json` (et tout type inconnu) n'est **jamais** câblable, en entrée comme en sortie |

Ainsi un score numérique ne peut alimenter un champ `string`, et un champ
`boolean` ne peut être alimenté que par une source `boolean`. Un désaccord est
rejeté au save avec un 422.

> **Note :** une sortie de table est toujours `numeric` (scoring) ou le
> `decision_type` de la table (`alpha_num` | `numeric` | `string` | `json`) —
> **jamais `boolean`**. Un champ `boolean` aval ne peut donc être alimenté que par
> une entrée de flow `boolean`, pas par un nœud amont.

---

## API HTTP

Toutes les routes sont cloisonnées par application (en-tête `X-Application`) et
protégées par ACL.

| Méthode & chemin | Handler | Scope ACL |
| ---------------- | ------- | --------- |
| `GET    /api/v1/admin/flows` | lister les flows | `tables_view` |
| `POST   /api/v1/admin/flows` | créer un flow | `tables_create` |
| `GET    /api/v1/admin/flows/{id}` | lire un flow | `tables_view` |
| `PUT    /api/v1/admin/flows/{id}` | mettre à jour un flow | `tables_update` |
| `DELETE /api/v1/admin/flows/{id}` | supprimer un flow | `tables_delete` |
| `GET    /api/v1/admin/flows/{id}/runs` | historique paginé des exécutions | `tables_view` |
| `POST   /api/v1/flows/{id}/decisions` | **exécuter le flow** | `decisions_make` |

Le CRUD est fourni par l'`AbstractController` Nebo15 ; les écritures passent par
`FlowRepository::createOrUpdate`, où le graphe est validé.

**Sémantique du PUT :** un `PUT` ne remplace que les clés présentes dans le corps
— une mise à jour partielle (p. ex. `title` seul) fusionne par-dessus le graphe
stocké et n'efface jamais `nodes`/`edges`/`outputs`. C'est le graphe fusionné qui
est revalidé.

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
| `app/Models/Flow.php` | Document Flow (title, inputs, outputs, nodes, edges) |
| `app/Models/FlowRun.php` | Une exécution enregistrée, reliant ses décisions |
| `app/Repositories/FlowRepository.php` | CRUD, `validateGraph()`, `getRuns()`, compatibilité de types |
| `app/Services/FlowEngine.php` | `run()` — exécution topologique, câblage, persistance |
| `app/Services/GraphSort.php` | Tri topologique / détection de cycle partagés (Kahn) |
| `app/Http/Controllers/FlowsController.php` | CRUD admin + `runs()` |
| `app/Http/Controllers/ConsumerController.php` | `flowCheck()` — l'endpoint d'exécution |
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
- **Tout ce qui touche à l'ordre d'exécution** → passez par `GraphSort` pour que
  l'invariant d'acyclicité reste au même endroit.

---

## Limitations actuelles

Reportées à dessein (notées comme évolutions futures) :

- **Une seule sortie par nœud** — `from_output` vaut toujours `final_decision`.
- **Pas de sortie de table `boolean`** — `decision_type` ne peut pas être
  `boolean`, donc le câblage booléen de nœud à nœud est impossible (voir
  [Compatibilité de types](#compatibilité-de-types)).
- **Pas de sélection de variante par nœud** — un nœud exécute la variante
  auto-sélectionnée / par défaut de sa table ; `variant_id` n'est pas transmis par
  nœud.
- **Pas de flows imbriqués** — un nœud référence une table, pas un sous-flow (la
  récursion / les cycles inter-flows ont été jugés non essentiels au départ).
- **Exécution séquentielle** — les nœuds s'exécutent un par un en ordre
  topologique ; il n'y a pas d'évaluation parallèle des branches indépendantes.
