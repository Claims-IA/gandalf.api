# Gandalf : versions des tables et des flows

> **Statut : conception validée, implémentation en cours.** Ce document décrit
> le comportement **prévu**. Chaque section indique le lot qui l'apporte (voir
> [Lots et statut](#lots-et-statut)). Tant qu'un lot n'est pas livré, le
> comportement actuel décrit dans [DOCUMENTATION.fr.md](DOCUMENTATION.fr.md),
> [GUIDE.fr.md](GUIDE.fr.md), [API_GUIDE.fr.md](API_GUIDE.fr.md) et
> [DRG.fr.md](DRG.fr.md) reste la référence.

En production, on ne modifie plus une table ou un flow en direct. On prépare des
**brouillons**, on les **publie** en versions figées, et on **active** la
version que l'API exécute. Les variantes restent à l'intérieur de chaque
version, pour l'A/B testing.

## Table des matières

- [Modèle mental](#modèle-mental)
- [Modèle de données](#modèle-de-données)
- [Cycle de vie](#cycle-de-vie)
- [Droits](#droits)
- [Exécution](#exécution)
- [API HTTP](#api-http)
- [Gestion des erreurs](#gestion-des-erreurs)
- [Migration et mode de compatibilité](#migration-et-mode-de-compatibilité)
- [Carte du code](#carte-du-code)
- [Documentation à mettre à jour](#documentation-à-mettre-à-jour)
- [Lots et statut](#lots-et-statut)
- [Points ouverts](#points-ouverts)

---

## Modèle mental

| Notion | Rôle | Modifiable |
| ------ | ---- | ---------- |
| **Table** | Identité stable : son identifiant ne change pas pour les clients ni pour les flows. Porte le nom, la description, la catégorie, le type de table (`matching_type`), le type de décision (`decision_type`) et la version active | Oui, sauf les deux types dès qu'une version est publiée |
| **Brouillon** | Version en préparation : nom, auteur, version d'origine, contenu complet (champs, variantes, règles, conditions, répartition). Jamais exécuté par une clé d'API | Oui, et supprimable |
| **Version publiée** | Brouillon figé, numéroté à la publication (1, 2, 3 sans trou) | Non, sauf sa répartition des variantes |
| **Version active** | Pointeur vers une version publiée : c'est elle qu'exécutent les appels directs sans version | On change de version active |

Les flows suivent le même modèle (lot 4) : un flow a des brouillons, des
versions publiées et une version active. **Chaque version de flow est figée sur
une version précise de chaque table qu'elle utilise.** Activer une nouvelle
version d'une table ne change donc rien aux flows en production.

Les variantes et les versions sont deux axes indépendants. La version répond à
« quelle définition est en production ? », la variante à « quelle branche de
cette définition traite cette requête ? ». L'API choisit d'abord la version,
puis une variante selon la répartition de cette version.

---

## Modèle de données

### Table (collection `tables`) : lot 1

À terme, le document de la table ne garde que son identité.

| Champ | Type | Notes |
| ----- | ---- | ----- |
| `title`, `description`, `category_id`, `applications` | inchangés | |
| `matching_type`, `decision_type` | string | Restent au niveau de la table. **Verrouillés dès qu'une version est publiée** : les modifier changerait immédiatement le comportement de toutes les versions. Pour en changer, on crée une nouvelle table. Le verrou s'applique avec la mise en production B : pendant A, toutes les tables ont une version publiée et l'éditeur actuel peut encore changer ces types, comme aujourd'hui |
| `active_version_id` | ObjectId | Version publiée exécutée par défaut |

Les champs de contenu (`fields`, `variants`, `variants_probability`) vivent
dans ses versions. **Pendant la mise en production A, le document de la table
garde une copie du contenu de sa version active** : le code qui lit encore la
table (flows, export, historique, liste) continue de fonctionner sans
changement. Invariant : ce contenu est toujours celui de la version active.
Chaque enregistrement d'une table publie une version quand son contenu change
(mode de compatibilité), et chaque activation recopie le contenu de la version
activée. La copie disparaît avec la mise en production B, une fois tout le code
passé aux versions.

### Version de table (collection `table_versions`) : lot 1

| Champ | Type | Notes |
| ----- | ---- | ----- |
| `table_id` | ObjectId | Table propriétaire |
| `applications` | array | Même cloisonnement par application que la table |
| `status` | `draft` \| `published` | |
| `number` | int \| null | Attribué à la publication, unique par table, sans trou. `null` pour un brouillon |
| `name` | string | Nom du brouillon (« Seuil 2000 ») |
| `comment` | string | Commentaire de publication |
| `origin_version_id` | ObjectId \| null | Version ou brouillon d'où vient ce brouillon |
| `autopublished` | bool | Version publiée par le mode de compatibilité ou par la migration, et non par une personne |
| `fields` | array | Même forme qu'aujourd'hui (`Field`) |
| `variants` | array | Même forme qu'aujourd'hui (`Variant` > `Rule` > `Condition`). Une variante garde son `_id` d'une version à l'autre. Il n'y a pas de variante par défaut (lot 2b) : `is_default` n'est plus enregistré ni renvoyé |
| `variants_probability` | string | Mode de répartition (`first`, `random`, `percent`). Modifiable sur une version publiée |
| `created_by`, `created_at`, `updated_at` | | |
| `published_by`, `published_at` | | Renseignés à la publication |

Une version a la même forme de contenu qu'une table d'aujourd'hui : `Scoring`
peut l'évaluer sans changer sa logique de règles.

L'invariant actuel des variantes est conservé : toutes les variantes d'une
version partagent les mêmes champs, et chaque règle garde une condition par
champ, dans l'ordre des champs.

### Flow et version de flow (collections `flows`, `flow_versions`) : lot 4

Même découpage que pour les tables. Le document `flows` garde l'identité et
`active_version_id`. Une version de flow porte `inputs`, `outputs`, `nodes` et
`edges`. Chaque nœud ajoute `table_version_id` : la version de la table sur
laquelle il est figé.

### Décision (collection `decisions`) : lots 1, 3 et 4

| Champ ajouté | Lot | Notes |
| ------------ | --- | ----- |
| `table.version` | 1 | `{ _id, number }` de la version exécutée. `null` pour les décisions antérieures au versionnement |
| `consumer` | 3 | `client_id` de la clé d'API appelante ; `null` pour un utilisateur (token OAuth) |
| `test` | 3 | `true` pour une exécution de test depuis gandalf.web (brouillon ou version), `false` sinon ; absent des décisions plus anciennes. Les exécutions de test sont exclues des analytics et de l'historique des décisions par défaut (`GET /api/v1/admin/decisions?test=true` les liste) |
| `flow.version` | 4 | `{ _id, number }` de la version de flow, pour une décision prise dans un flow |

### Suivi des appels par clé (collection `version_calls`) : lot 3

Une ligne par clé, table (ou flow) et version demandée : `consumer` (le
`client_id` de la clé), `table_id` ou `flow_id`, `requested_version` (`null`
quand l'appel ne précise pas de version), `last_call_at`, `count`,
`applications`. Elle alimente l'écran d'activation (« clés qui appellent sans
préciser de version ») et le garde-fou de suppression d'une version.

En place depuis le lot 3 pour les tables (flows : lot 4), service
`App\Services\VersionCalls`. Seuls les appels réussis des clés d'API comptent :
un utilisateur n'a pas de code figé sur une version. Une clé compte comme
appelante tant que son dernier appel est dans la fenêtre
(`TABLE_VERSIONS_CALL_WINDOW_DAYS`, 30 jours par défaut) et qu'elle est encore
une clé de l'application : une clé supprimée n'appelle plus rien. Un index
unique (`version_calls_key`, migration `2026_10_10_140000_VersionCalls`) garde
une seule ligne par clé, table et version demandée.

---

## Cycle de vie

```text
  Brouillons (modifiables)          Versions publiées (figées)          Appels
  ┌──────────────────────┐ publier ┌──────────────────────────┐
  │ « Seuil 2000 »       │───────► │ v4  active               │◄──── sans version
  │ issu de v4           │         │ v3  publiée, inactive    │◄──── "version": 3 (droit dédié)
  └──────────────────────┘         └──────────────────────────┘
            ▲                                   │
            └──── créer un brouillon à partir d'une version ┘
```

| Action | Effet | Droit |
| ------ | ----- | ----- |
| Créer un brouillon | À partir d'une version publiée ou d'un autre brouillon. Plusieurs brouillons peuvent coexister | `tables_update` |
| Modifier un brouillon | Champs, variantes, règles, répartition | `tables_update` |
| Exécuter un brouillon | Depuis gandalf.web uniquement ; décisions marquées `test` | `tables_update` |
| Publier | Fige le brouillon et lui attribue le numéro suivant. La version active ne change pas. Avertit si le brouillon ne part pas de la version active | `tables_publish` |
| Activer | La version publiée choisie devient la version active, de façon atomique. Ne concerne que les appels directs : les flows restent figés sur leurs versions | `tables_activate` |
| Revenir en arrière | Réactiver une version précédente | `tables_activate` |
| Changer la répartition | Mode et pourcentages des variantes d'une version, publiée ou non, sans republier | `tables_update` ; `tables_activate` pour la version active (voir [Points ouverts](#points-ouverts)) |
| Supprimer un brouillon | À tout moment | `tables_update` |
| Supprimer une version publiée | Seulement si elle n'est pas active, qu'aucune version de flow n'est figée dessus (lot 4) et qu'aucune clé ne l'appelle encore | `tables_publish` |

Il n'y a pas de fusion entre brouillons. Chaque brouillon, puis chaque version,
enregistre sa version d'origine. La publication et l'activation signalent un
brouillon qui ne part pas de la version active : il ne contient pas les
changements des versions suivantes.

Publier et activer sont deux actions distinctes, liées à deux droits. Rien
n'active une version automatiquement, hors [mode de compatibilité](#migration-et-mode-de-compatibilité).

En place depuis les lots 2 et 3, sauf le garde-fou de suppression lié aux
flows (lot 4). Pendant
la mise en production A, activer une version recopie son contenu sur la table,
et les flows de l'application suivent les renommages de champs entre
l'ancienne version active et la nouvelle, comme pour une mise à jour de table.

---

## Droits

Les droits s'ajoutent à `config/applicationable.php`. Chaque nouvelle route
s'ajoute aux règles `acl` du même fichier ; `tests/unit/AclCoverageTest.php`
vérifie qu'aucune route n'y échappe.

| Droit | Porté par | Rôle |
| ----- | --------- | ---- |
| `tables_publish` | utilisateurs | Publier une version ; supprimer une version publiée |
| `tables_activate` | utilisateurs | Activer une version (mise en production) ; changer la répartition de la version active |
| `decisions_make_inactive` | clés d'API et utilisateurs | « Make API call for inactive versions » : appeler une version publiée inactive avec le champ `version`. Un utilisateur le détient pour le donner à une clé (un utilisateur ne donne à une clé que les droits qu'il a) et pour appeler la route v2 avec son propre token |

Les droits existants gardent leur sens. Une clé qui n'a que `decisions_make`
n'exécute que la version active. Ceux qui testent et publient (par exemple les
QA) ne sont pas forcément ceux qui mettent en production.

En place depuis les lots 2 et 3. Le propriétaire d'une nouvelle application
reçoit tous les droits. La migration `2026_10_10_130000_TableVersionScopes`
donne `tables_publish`, `tables_activate` et `decisions_make_inactive` aux
admins des applications existantes ; les autres membres les reçoivent d'un
admin.

L'administration à deux niveaux (admin d'application, superadmin réservé à
Claims IA) est un chantier indépendant : lot 7.

---

## Exécution

| Appel | Version de table exécutée |
| ----- | ------------------------- |
| `POST /api/v1/tables/{id}/decisions` (inchangé, obsolète) | La version active |
| `POST /api/v2/tables/{id}/decisions` sans `version` | La version active |
| `POST /api/v2/tables/{id}/decisions` avec `"version": 3` | La version publiée 3 si elle est active ; sinon seulement avec le droit `decisions_make_inactive` |
| Exécution de test depuis gandalf.web | Le brouillon ou la version choisie ; décision marquée `test` |
| Nœud d'un flow (lot 4) | La version de table sur laquelle la version du flow est figée |

Dans la version retenue, la variante est choisie comme aujourd'hui : par
`variant_id` s'il est fourni, sinon selon `variants_probability`. Un
`variant_id` absent de la version exécutée renvoie une erreur explicite, pour
qu'un client ne croie pas tester une variante en en exécutant une autre.

En place depuis le lot 3, sauf les nœuds de flow (lot 4 : d'ici là, ils
exécutent la version active de chaque table). `App\Services\Scoring::evaluate()`
exécute n'importe quelle version ; `check()` (v1, flows) exécute la version
active et `evaluateRequest()` (v2, exécutions de test) prend le corps v2.

---

## API HTTP

### Route de décision v2 : lot 3

```http
POST /api/v2/tables/{id}/decisions
Authorization: Basic <clé d'API>

{
  "version": 4,
  "variant_id": "5f…",
  "data": { "age": 42, "material": "moquette" }
}
```

`version` et `variant_id` sont facultatifs. Les données d'entrée sont isolées
dans `data` : aucune collision possible avec un champ de table (aujourd'hui,
`variant_id` est mêlé aux données et réservé comme clé de champ). La réponse
reprend celle de la v1, qui contient déjà la variante exécutée
(`table.variant`), et y ajoute la version exécutée (`table.version`) :

```json
{
  "data": {
    "final_decision": "PNO",
    "table": {
      "_id": "…",
      "version": { "_id": "…", "number": 4 },
      "variant": { "_id": "…", "title": "Seuil 1600" }
    }
  }
}
```

La même forme vaut pour `POST /api/v2/flows/{id}/decisions` (lot 4).

La route v1 garde son corps à plat et exécute la version active. Elle est
déclarée obsolète avec une date de fin, par les en-têtes `Deprecation` et
`Sunset`.

En place depuis le lot 3 pour les tables. Détails :

- Appelants : les mêmes qu'en v1, clés d'API et utilisateurs (token OAuth).
  Une version publiée autre que la version active exige
  `decisions_make_inactive`, pour la clé ou l'utilisateur.
- Les erreurs de saisie arrivent sous `data.*` (`"data.age": [...]`) ; un
  corps sans `data` (la forme v1) répond `422`. Le `request` de la décision ne
  contient que `data` : `version` et `variant_id` se lisent dans
  `table.version` et `table.variant`.
- Les en-têtes de la v1 viennent de deux dates réglées dans l'environnement,
  `DECISIONS_V1_DEPRECATED_AT` (`Deprecation: @<timestamp>`, plus
  `Link: </api/v2/tables/{id}/decisions>; rel="successor-version"`) et
  `DECISIONS_V1_SUNSET` (`Sunset: <date HTTP>`). Sans date, pas d'en-tête :
  les dates restent à fixer (voir [Points ouverts](#points-ouverts)).

### Routes d'administration : lot 2

| Méthode | Route | Rôle |
| ------- | ----- | ---- |
| GET | `/api/v1/admin/tables/{id}/versions` | Liste des brouillons et versions publiées, version active, version d'origine |
| POST | `/api/v1/admin/tables/{id}/versions` | Créer un brouillon (`from_version_id`, `name`) |
| GET | `/api/v1/admin/tables/{id}/versions/{version_id}` | Lire une version |
| PUT | `/api/v1/admin/tables/{id}/versions/{version_id}` | Modifier un brouillon (refusé sur une version publiée) |
| DELETE | `/api/v1/admin/tables/{id}/versions/{version_id}` | Supprimer, avec les garde-fous du [cycle de vie](#cycle-de-vie) |
| POST | `/api/v1/admin/tables/{id}/versions/{version_id}/publish` | Publier (`comment`) |
| PUT | `/api/v1/admin/tables/{id}/versions/{version_id}/split` | Changer la répartition d'une version (`variants_probability`, `variants[]._id` et `probability`) |
| POST | `/api/v1/admin/tables/{id}/activate` | Activer (`version_id`) ; la réponse liste les clés qui appellent sans préciser de version |
| POST | `/api/v1/admin/tables/{id}/versions/{version_id}/decisions` | Exécution de test depuis gandalf.web (décision marquée `test`) |
| GET | `/api/v1/admin/tables/{id}/calls` | Appels récents des clés d'API du projet, par version demandée |

Les routes équivalentes pour les flows arrivent au lot 4.

En place depuis les lots 2 et 3. Détails :

- **Liste** : les versions sans leur contenu (`fields`, `variants`), les
  brouillons d'abord (dernier modifié en premier), puis les versions publiées
  (la plus récente en premier). Chaque version, ici et dans les autres
  réponses, porte `is_active`.
- **Créer un brouillon** : à partir de `from_version_id`, par défaut la
  version active. Les identifiants des variantes sont conservés : un client qui
  force une variante continue de fonctionner.
- **Modifier un brouillon** : même corps et même validation qu'une mise à jour
  de table (`fields`, `variants`, `variants_probability`), plus `name`.
  `matching_type` et `decision_type` sont ceux de la table. Les conditions
  suivent les champs renommés.
- **Divergence** : publier ou activer une version qui ne descend pas de la
  version active répond `200` avec `meta.warning = "version_diverges"` et
  `meta.active_version = { _id, number }`.
- **Activer** : renvoie la table. Si des champs ont été renommés entre les
  deux versions, `meta` contient aussi `field_renames`, `flows_updated`,
  `flows_failed` et `flows_invalid`, comme une mise à jour de table.
- **Répartition** : les variantes omises gardent leur pourcentage. En mode
  `percent`, la somme des pourcentages doit faire 100.
- **Exécution de test** : corps de la route v2 sans `version` (`variant_id`,
  `data`), droit `tables_update`. Exécute n'importe quel brouillon ou version
  et renvoie toujours les règles, quel que soit le réglage `show_meta`.
- **Appels** : lignes `consumer`, `description` (celle de la clé),
  `requested_version`, `last_call_at`, `count`, la plus récente en premier ;
  `meta.window_days` donne la fenêtre. La réponse d'activation reprend les
  lignes sans version dans `meta.callers_without_version` : les clés dont
  l'activation change le comportement.

### Import et export : lot 2b

En place. `GET /api/v1/admin/tables/{id}/export` prend `version_id` (un
brouillon ou une version publiée, la version active par défaut) et `variant_id`
(Excel et CSV, la première variante par défaut). La feuille `_meta` du classeur
Excel enregistre `version_id` et `version_number`, et ses jetons de verrou
(`exported_at`, `content_hash`) sont ceux du contenu exporté. Tant que le mode
de compatibilité est actif, la version active est exportée depuis la table, qui
en garde la copie.

Réimporter un classeur Excel (`POST /api/v1/admin/tables/import`) écrit dans
son origine :

| Classeur exporté depuis | Import |
| ----------------------- | ------ |
| Un brouillon | Met le brouillon à jour (`200`), verrou sur le brouillon |
| Une version publiée | Crée un brouillon à partir d'elle, nommé « Import Excel » (`201`) |
| Une version publiée, mode de compatibilité actif | Met la table à jour, comme avant les versions |
| Une table, avant les versions (pas de `version_id`) | Met la table à jour |

Un brouillon ne reçoit que le contenu : le titre et la description restent ceux
de la table, et le contenu est validé avec les types de la table. La réponse
porte `meta.imported_into` (`table` ou `version`).

`is_default` disparaît : la répartition choisit la variante exécutée, un export
désigne la variante qu'il exporte, et l'export JSON place la première variante
sous sa clé `table`. Le marqueur reste dans les documents enregistrés avant,
sans être lu ; l'empreinte du contenu l'ignore, si bien que leur prochain
enregistrement ne publie rien.

---

## Gestion des erreurs

| Situation | HTTP | Code |
| --------- | ---- | ---- |
| Version demandée inconnue | 404 | `version_not_found` |
| Version publiée inactive, appelant sans `decisions_make_inactive` | 403 | `version_not_allowed` |
| Brouillon demandé par une clé d'API | 404 | `version_not_found` (un brouillon n'a pas de numéro) |
| `variant_id` absent de la version exécutée | 422 | `variant_not_found` |
| Modification d'une version publiée (hors répartition), ou nouvelle publication | 409 | `version_published` |
| Activation d'un brouillon | 409 | `version_not_published` |
| Suppression de la version active, d'une version figée par un flow ou encore appelée | 409 | `version_in_use` |
| Changement de `matching_type` ou `decision_type` après publication | 409 | `table_type_locked` |
| Données d'entrée invalides | 422 | inchangé, erreurs sous `data.*` en v2 |

En place depuis les lots 2 et 3, sauf `table_type_locked` (mise en production
B) et `version_in_use` pour une version figée par un flow (lot 4). Une requête
sans le droit qu'exige la version (`tables_publish`, `tables_activate`) reçoit
le `403 access_denied` habituel, avec le droit manquant dans `meta.scopes`. La
route v1 garde sa propre erreur : un `variant_id` absent de la version active
répond `404 variant_not_found`.

---

## Migration et mode de compatibilité

### Mise en production A (lots 1 à 3)

- **Migration des tables** (`database/migrations`) : chaque table devient sa
  version 1, publiée et active ; son contenu est copié dans `table_versions`
  et reste sur la table (copie de la version active). La migration est
  idempotente et **non destructive** : son retour arrière supprime
  `table_versions` et `active_version_id`. Conformément à
  [AGENTS.md](AGENTS.md), elle n'est jamais lancée pour valider la
  documentation ; on la teste d'abord sur une copie des données.
- **Décisions existantes** : `table.version` vaut `null` (antérieures au
  versionnement).
- **Mode de compatibilité** : l'éditeur actuel ne connaît pas les versions.
  Tant que la mise en production B n'est pas faite, un enregistrement par
  `PUT /api/v1/admin/tables/{id}` crée une version, la publie et l'active
  aussitôt, comme aujourd'hui. C'est la seule exception à la règle « rien ne
  s'active automatiquement ». Elle est pilotée par la variable
  d'environnement `TABLE_VERSIONS_LEGACY_AUTOPUBLISH` (active par défaut) et
  disparaît avec la mise en production B. En place (lot 1) : après chaque
  enregistrement d'une table, `TableObserver` appelle
  `TableVersioning::syncFromTable()`, qui compare l'empreinte du contenu
  (`contentHash()`) à celle de la version active. Tous les chemins d'écriture
  passent ainsi par le même contrôle : éditeur, création, copie, déplacement,
  import Excel, retour arrière du changelog. Le changelog enregistre la version
  active : revenir à une révision réactive sa version.
- **Flows** : pas encore versionnés. Ils exécutent la version active de chaque
  table, comme ils exécutent aujourd'hui la table en service.

### Mise en production B (lots 4 à 6)

- **Migration des flows** : chaque flow devient sa version 1, publiée et
  active, **figée sur la version active de chaque table au moment de la
  migration**. Le mode de compatibilité a pu créer des versions de tables
  depuis la mise en production A : figer les flows sur la version 1 changerait
  leur comportement.
- **Fin du mode de compatibilité et de la copie** : le contenu quitte le
  document de la table, dont le code ne lit plus que l'identité.

---

## Carte du code

Inventaire du lot 0 : les endroits qui lisent ou écrivent le contenu d'une
table ou d'un flow, et ce qui doit y changer. Les chemins partent de la racine
du dépôt.

| Zone | Code | Changement | Lot |
| ---- | ---- | ---------- | --- |
| Modèles | `app/Models/Table.php` : `fields()`, `variants()`, `setFields()`, `setVariants()`, `getVariantForCheck()`, `toListArray()` | Le contenu passe dans un nouveau modèle `TableVersion` ; la liste des tables lit la version active | 1 |
| | `app/Models/Decision.php` : `getTableArray()` | Ajouter et convertir `table.version` | 1 |
| | `app/Models/Flow.php`, `app/Models/FlowRun.php` | Contenu dans `FlowVersion` ; une exécution enregistre la version du flow et la version de table de chaque nœud | 4 |
| Exécution | `app/Services/Scoring.php` : `check()`, `createValidationRules()`, `prepareFieldPreset()` | Évaluer une version : active par défaut, demandée en v2, figée depuis un flow | 1, 3 |
| | `app/Services/FlowEngine.php` : `run()`, `buildNodeInput()`, `requireTable()`, `persistRun()` | Lire la version active du flow et passer à `Scoring` la version figée de chaque nœud | 4 |
| Tables | `app/Repositories/TablesRepository.php` : `createOrUpdate()`, renommage de champs, `normalizeVariantConditions()` | Écrire le contenu dans une version, l'identité dans la table | 1, 2 |
| | même fichier : `duplicateInto()`, `copyTo()`, `moveTo()` | Copier ou déplacer les versions avec la table | 1 |
| | même fichier : `analyzeTableDecisions()` | Filtrer les décisions par version, au lieu de la date de dernière modification | suites |
| | `AbstractRepository::delete()` (paquet `nebo15/lumen.rest`), `ProjectsController::deleteProject()` | Supprimer aussi les versions | 1 |
| Flows | `app/Repositories/FlowRepository.php` : `validateGraph()`, `findProjectTable()`, `tableHasField()`, `fieldType()`, `findReusableTable()`, `canStandIn()` | Lire les champs de la version de table figée ; les types restent lus sur la table | 4 |
| | même fichier : `followFieldRenames()`, `copyReferencedTables()`, `copyTo()`, `moveTo()` | Viser les brouillons de flows ; copier les versions figées | 4 |
| Validation | `app/Validators/TableRulesProvider.php`, `app/Validators/TableValidator.php` | Séparer les règles d'identité et de contenu ; fournir `matching_type` et `decision_type` de la table à la validation d'une version | 1 |
| Import et export | `app/Services/TableImportService.php`, `app/Services/TableExportService.php`, `app/Services/Excel/` (`ExcelTableWriter`, `ExcelLayout`, `ExcelTableReader`, `ExcelImportResult`, `TableMergeService`) | Lire et écrire une version ; identifiant de version dans la feuille de métadonnées ; verrou optimiste sur la version ; suppression de `is_default` | 1, 2b |
| Historique | `app/Observers/TableObserver.php`, `app/Observers/FlowObserver.php`, `app/Providers/ObserverServiceProvider.php`, `app/Http/Controllers/ChangelogController.php` (`rollback()`, `tableFields()`) | Historique du contenu sur les versions ; un retour arrière de contenu vise une version | 1, 2 |
| Export de projet | `app/Services/DbTransfer.php` | Exporter `table_versions`, puis `flow_versions` | 1, 4 |
| Routes et droits | `app/Http/routes.php`, `config/applicationable.php`, `tests/unit/AclCoverageTest.php` | Nouvelles routes et nouveaux droits (voir les constats ci-dessous) | 2, 3 |
| Contrôleurs | `TablesController` (`update()`, `analytics()`, `export()`, `import()`), `FlowsController`, `ConsumerController` (`tableCheck()`, `flowCheck()`) | Viser une version ; route v2 | 1 à 4 |
| Données de test | `database/seeds/DatabaseSeeder.php`, `tests/_support/ApiTester.php` | Créer une table avec sa version ; adapter les vérifications de forme | 1 |

### Constats du lot 0

- **Aucun test API des flows** : il n'existe pas de `FlowsCest`. Le lot 4
  commence par en écrire un, comme filet de sécurité avant de toucher aux
  flows.
- **Pas de sauvegarde Eloquent d'une table hors requête authentifiée** :
  `TableObserver::saved()` n'a pas de garde quand il n'y a pas d'utilisateur,
  et `Table::setVariants()` lit l'utilisateur connecté. Une migration ou une
  commande qui sauvegarde une table par Eloquent échoue. La migration passe
  donc par `\DB::collection`, comme
  `database/migrations/2016_05_26_180242_AddTableVariants.php`, sans
  déclencher d'observateur.
- **Ne pas recopier le `BulkWrite` de cette migration** : il est construit une
  seule fois et exécuté à chaque tour de boucle, ce que le pilote MongoDB
  refuse.
- **Aucune migration depuis 2016, et rien dans le dépôt ne les exécute** (ni
  script, ni Dockerfile, ni CI). La mise en production A doit documenter la
  procédure : `php artisan migrate`, sauvegarde préalable, retour arrière.
- **ACL : la première règle qui correspond l'emporte.** Les routes GET, PUT et
  DELETE sous `/admin/tables/{id}/…` tombent déjà sous les règles génériques
  (`tables_view`, `tables_update`, `tables_delete`). Les nouvelles routes POST
  (versions, publication, activation) ne sont couvertes par aucune règle. Les
  routes qui demandent un autre droit (supprimer une version publiée, changer
  la répartition de la version active) doivent être déclarées **avant** les
  règles génériques.
- **Types de table et validation** : `ruleThanType` et `decisionType` valident
  `than` et `default_decision` d'après `matching_type` et `decision_type`. Les
  changer rendrait des versions invalides, ce qui confirme leur verrouillage.
- **Contenu ancien sans valeurs par défaut** (constaté au lot 1) : une table
  jamais réenregistrée peut manquer de clés que l'éditeur ajoute (`probability`,
  `default_title`…). L'empreinte du contenu applique les valeurs par défaut des
  modèles, sinon le premier enregistrement après la migration publierait une
  version sans changement réel.
- **Lacunes existantes, indépendantes du versionnement** : la suppression d'un
  projet ne supprime que ses tables (ni ses flows, ni ses exécutions, ni ses
  décisions, ni son historique) ; l'export d'un projet n'inclut ni les flows ni
  les exécutions. À corriger à part, ou avec les lots 1 et 4.

---

## Documentation à mettre à jour

Chaque lot met à jour, dans la même PR, la documentation qu'il rend obsolète,
en anglais et en français.

| Lot | Documents |
| --- | --------- |
| 1 | [DOCUMENTATION.md](DOCUMENTATION.md) / [.fr](DOCUMENTATION.fr.md) (modèles `Table`, `TableVersion`, `Decision`, moteur), [GUIDE.fr.md](GUIDE.fr.md) (2.2 et 2.3) |
| 2 | [API_GUIDE.md](API_GUIDE.md) / [.fr](API_GUIDE.fr.md) (routes d'administration, droits), [openapi.yaml](openapi.yaml), [DOCUMENTATION.md](DOCUMENTATION.md) / [.fr](DOCUMENTATION.fr.md) (contrôleur, dépôt), [GUIDE.fr.md](GUIDE.fr.md) (droits) |
| 3 | [API_GUIDE.md](API_GUIDE.md) / [.fr](API_GUIDE.fr.md) (route v2, obsolescence de la v1), [openapi.yaml](openapi.yaml), [DOCUMENTATION.md](DOCUMENTATION.md) / [.fr](DOCUMENTATION.fr.md) (moteur, suivi des appels), [GUIDE.fr.md](GUIDE.fr.md) (scénario d'utilisation) |
| 4 | [DRG.md](DRG.md) / [.fr](DRG.fr.md) (versions de flows, nœuds figés, migration) |
| 7 | [GUIDE.fr.md](GUIDE.fr.md) (rôles), [API_GUIDE.md](API_GUIDE.md) / [.fr](API_GUIDE.fr.md) |

Ce document passe d'une section « prévue » à une section « en place » à chaque
livraison.

---

## Lots et statut

| Lot | Contenu | Dépend de | Statut |
| --- | ------- | --------- | ------ |
| 0 | Cadrage technique (ce document) | | fait, à relire |
| 1 | API : fondations des versions de tables, migration, mode de compatibilité | 0 | fait, à relire |
| 2 | API : brouillons, publication, activation, répartition, droits | 1 | fait, à relire |
| 2b | API : import et export Excel par version, suppression de `is_default` | 2 | fait, à relire |
| 3 | API : route v2, exécution de test, suivi des appels par clé et garde-fou de suppression associé, obsolescence v1 | 2 | fait, à relire |
| 4 | API : versions de flows, nœuds figés, mise à jour groupée des flows compatibles | 1 | à faire |
| 5 | gandalf.web : versions de tables | 2, 3 | à faire |
| 6 | gandalf.web : versions de flows | 4, 5 | à faire |
| 7 | Administration à deux niveaux (indépendant) | | à faire |

Mise en production A : lots 1 à 3. Mise en production B : lots 4 à 6.

---

## Points ouverts

- **Droit pour changer la répartition d'une version active** : c'est un
  changement de production. Mis en place tel que proposé au lot 2
  (`tables_activate`), à confirmer.
- **« Aucune clé ne l'appelle encore »** : fenêtre à fixer. Mis en place tel
  que proposé au lot 3 (dernier appel il y a moins de 30 jours,
  `TABLE_VERSIONS_CALL_WINDOW_DAYS`), à confirmer.
- **Date de fin de la route v1** : les en-têtes sont prêts au lot 3
  (`DECISIONS_V1_DEPRECATED_AT`, `DECISIONS_V1_SUNSET`) ; les dates restent à
  fixer.
- **Procédure de migration en production** : aucune n'est documentée aujourd'hui
  (qui lance `php artisan migrate`, quand, avec quelle sauvegarde).
