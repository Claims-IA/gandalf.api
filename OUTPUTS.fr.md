# Gandalf : plusieurs sorties par table

[English version](OUTPUTS.md)

> **Statut : proposition, rien n'est implémenté.** Ce document évalue la
> demande, recense les impacts et propose un plan. Les
> [décisions](#décisions-à-prendre) restent à prendre avant le premier lot.
> Jusque-là, le comportement décrit dans [DOCUMENTATION.fr.md](DOCUMENTATION.fr.md),
> [API_GUIDE.fr.md](API_GUIDE.fr.md), [DRG.fr.md](DRG.fr.md) et
> [VERSIONS.fr.md](VERSIONS.fr.md) fait foi.
>
> Analyse du 12 octobre 2026, sur `master` (API) et `main` (gandalf.web).

## Sommaire

- [La demande](#la-demande)
- [Évaluation](#évaluation)
- [Prérequis](#prérequis)
- [Impacts](#impacts)
- [Décisions à prendre](#décisions-à-prendre)
- [Plan](#plan)
- [Risques](#risques)
- [Points d'ancrage dans le code](#points-dancrage-dans-le-code)

---

## La demande

- Aujourd'hui, une table n'a qu'une valeur de sortie, avec un type et
  toujours le même nom (`final_decision`).
- Une table pourra déclarer **plusieurs sorties**, chacune avec une **clé**,
  un **nom** et un **type**.
- Dans les flows, chaque sortie pourra servir de sortie du flow ou être reliée
  au champ d'une autre table.
- À la création d'une table de décision, une sortie par défaut de type
  alphanumérique est créée ; sa clé, son nom et son type se modifient ensuite.
- À la création d'une table de scoring, la première sortie est numérique et
  son type ne se modifie pas.

---

## Évaluation

La demande est cohérente avec l'architecture. La réponse d'une décision
contient déjà une map `answer` / `answer_types`, prévue pour plusieurs sorties,
et [DRG.fr.md](DRG.fr.md#étendre-le-drg) esquisse cette extension.

C'est toutefois un **changement de modèle de données**, d'une ampleur
comparable au versionnement des tables. La valeur unique d'une règle (`than`),
le type de la table (`decision_type`) et la valeur par défaut d'une variante
(`default_decision`) sont lus par le moteur, l'historique, les statistiques,
les versions, les imports et exports, les flows et six écrans de gandalf.web.

---

## Prérequis

Trois manques actuels sont à corriger d'abord. Sans eux, des sorties
déclarées casseraient des flows en production.

1. **Le type de sortie n'est pas versionné.** `matching_type` et
   `decision_type` vivent sur la table, hors du contenu d'une version : ni
   l'empreinte, ni la copie d'une version ne les incluent. Le moteur et la
   validation des flows les lisent sur la table, même pour une version
   épinglée. Le verrou `table_type_locked`, prévu par
   [VERSIONS.fr.md](VERSIONS.fr.md), n'est pas implémenté. Avec des sorties
   déclarées, supprimer ou retyper une sortie casserait d'un coup tous les
   flows épinglés.
2. **Le mode compatibilité publie des versions de flow sans les valider.**
   Quand une table change, `FlowVersioning::followTable` publie et active une
   nouvelle version de flow sans appeler `validateGraph`.
3. **Des données anciennes ne sont pas migrées.** Le renommage des types de
   table (`decision`, `scoring`, `all` vers `first`, `scoring_*`) s'est fait
   sans migration. Des tables peuvent encore porter l'ancien type ou n'avoir
   aucun `decision_type`. Les données de test de `tests/_support/ApiTester.php`
   utilisent ces anciens types, que la validation refuse : les tests API qui
   créent une table avec elles ne peuvent pas passer.

---

## Impacts

| Zone | Ce qui change |
|---|---|
| API, modèle et validation | Déclaration des sorties (clé, nom, type). Valeurs par sortie dans chaque règle et dans la valeur par défaut de chaque variante. Validation des valeurs selon le type de chaque sortie. Listes `$fillable` / `$visible` de `Table`, `Variant`, `Rule`, `TableVersion` et `Decision` : une clé absente de ces listes est perdue sans erreur. |
| API, moteur et réponses | Calcul de chaque sortie : première règle qui correspond, ou agrégation pour le scoring. Enregistrement dans l'historique. Réponses v1 et v2 compatibles : `final_decision` à la racine, `answer`, `answer_types`, et `rules[].decision` quand `show_meta` est actif. |
| Statistiques | Une règle compte comme déclenchée par la comparaison `than === decision` : elle ne fonctionne plus si la valeur devient une map. Il faut un autre critère (par exemple un indicateur `matched` sur la règle). |
| Versions, révisions, copie | Les sorties entrent dans le contenu versionné : empreinte, copie, brouillons, retour à une révision, copie et déplacement entre applications. |
| Imports et exports | Le classeur Excel (gandalf-xlsx-v2) n'accepte qu'une colonne `DECISION` et un seul type dans `_meta`. Il faut un format à plusieurs sorties, tout en gardant l'import des anciens fichiers. `TableMergeService::serializeVariant` perdrait aujourd'hui les sorties supplémentaires des variantes absentes du fichier. Les imports JSON et CSV ne valident pas les valeurs par type. |
| API, flows | Un port par sortie, au lieu de `final_decision` en dur. Validation contre les sorties de la version épinglée. Suivi des renommages de sortie, alors que seuls les champs sont suivis aujourd'hui (`renameEdges`). Type des sorties de flow, jamais contrôlé aujourd'hui. Une sortie manquante donne `null` en sortie de flow (`assembleOutputs`), mais une erreur sur un lien (`resolveEdgeValue`) : à harmoniser. |
| gandalf.web, tables | Création : sortie initiale. Éditeur : N colonnes figées à droite, alors que le tableau n'en gère qu'une, et gestion des sorties. Fiche de la table, page de test, historique (les anciennes et les nouvelles décisions coexistent), statistiques. Enregistrement des brouillons : `VersionContent` ne transmet que `fields`, `variants` et `variants_probability`. |
| gandalf.web, flows | Une poignée par sortie sur chaque table. Choix de la sortie à l'ajout d'une sortie de flow. Contrôles de type. Les versions épinglées ne transmettent que leurs champs à l'éditeur (`tablesForPins`). |
| Données | Migration des tables et de toutes leurs versions. L'historique (décisions, exécutions de flow, révisions) reste dans l'ancien format : les écrans doivent afficher les deux. |
| Documentation et tests | README, API_GUIDE, GUIDE, DOCUMENTATION, DRG et VERSIONS (EN et FR), `openapi.yaml`, le manuel de gandalf.web. De nombreux tests et données de test. |

---

## Décisions à prendre

Chaque point porte une recommandation, à confirmer.

1. **Où vivent les sorties.** Recommandation : dans le contenu versionné,
   comme les champs. Une version publiée fige ses sorties, ce qui protège les
   flows épinglés. Le type de table (décision ou scoring) est verrouillé après
   la première publication.
2. **Tables de scoring à plusieurs sorties.** La demande ne dit pas comment
   combiner les règles. Recommandation : toutes les sorties d'une table de
   scoring sont numériques et agrégées par la fonction de la table (somme,
   minimum, maximum, comptage). Autres possibilités : une seule sortie pour le
   scoring, ou une fonction d'agrégation par sortie.
3. **Types de sortie.** Recommandation : les types des champs (texte, nombre,
   booléen, date), pour pouvoir câbler une sortie vers un champ, plus JSON, qui
   ne se câble pas. Attention : le type « alphanumérique » actuel (`alpha_num`)
   refuse les espaces et les tirets. Il faut choisir si la sortie par défaut
   d'une table de décision est « alphanumérique » au sens strict ou un vrai
   « texte ».
4. **Clé de la sortie par défaut.** Recommandation : les tables existantes
   migrent avec la clé `final_decision`, ce qui ne casse ni les flows ni les
   clients de l'API ; les nouvelles tables reçoivent `decision`. Dans tous les
   cas, `final_decision` à la racine de la réponse reste la valeur de la
   première sortie.
5. **Valeur quand aucune règle ne correspond.** Recommandation : une valeur
   par défaut par sortie, obligatoire.
6. **Compatibilité en écriture.** Recommandation : l'API continue d'accepter
   l'ancien format (`than` et `decision_type`) en création et en modification.
   L'interface actuelle et les scripts existants marchent pendant la
   transition, ce qui permet de livrer l'API avant l'interface.

---

## Plan

| Lot | Contenu |
|---|---|
| 0. Spécification et prérequis | Spécification détaillée (ce document, complété par les décisions). Migration des anciens types de table et correction des données de test. Versionnement et verrouillage des types. |
| 1. API, tables | Modèle, validation, moteur, historique, réponses compatibles, statistiques, migration rejouable, tests. |
| 2. API, flows | Ports par sortie, validation contre la version épinglée, suivi des renommages, type des sorties de flow, validation en mode compatibilité. |
| 3. API, imports et exports | Classeur Excel à plusieurs sorties, lecture des anciens formats conservée, JSON et CSV. |
| 4. gandalf.web, tables | Création, éditeur à plusieurs colonnes et gestion des sorties (verrou numérique en scoring), fiche, test, historique, statistiques. |
| 5. gandalf.web, flows | Une poignée par sortie, câblage, choix de la sortie, contrôles de type. |
| 6. Livraison | Documentation API et manuel, essai de la migration sur une copie des données réelles, mise en production. |

Chaque lot passe par une branche, une revue de code et des tests sur une pile
jetable. Grâce à la compatibilité en écriture (décision 6), les lots 1 à 3
peuvent être livrés avant l'interface.

---

## Risques

1. **Contrat des clients de l'API.** `final_decision` à la racine,
   `answer.final_decision`, `rules[].decision` (scalaire) et
   `table.decision_type` sont lus par des clients externes ; la route v1 est
   dépréciée mais toujours servie.
2. **Pertes silencieuses** par les listes `$fillable`, lors des copies
   (`duplicateInto`) et lors des imports Excel.
3. **Migration.** Elle doit réécrire les tables et toutes leurs versions,
   rester rejouable, et utiliser la base directement (comme les migrations de
   2026), pas les modèles Eloquent.
4. **Historique mixte.** Les décisions, exécutions de flow et révisions
   anciennes restent au format à une seule valeur. Un retour à une ancienne
   révision réintroduit ce format dans une table.
5. **Flows.** `final_decision` est écrit en dur dans l'API, l'éditeur, les
   tests et la documentation. Les anciens graphes doivent continuer de
   fonctionner.

---

## Points d'ancrage dans le code

Les numéros de ligne évoluent ; ce sont les fichiers et les fonctions à
reprendre.

**API**

| Sujet | Fichiers et fonctions |
|---|---|
| Modèle | [app/Models/Table.php](app/Models/Table.php), [app/Models/Variant.php](app/Models/Variant.php), [app/Models/Rule.php](app/Models/Rule.php), [app/Models/Concerns/HasTableContent.php](app/Models/Concerns/HasTableContent.php), [app/Models/TableVersion.php](app/Models/TableVersion.php) |
| Validation | [app/Validators/TableRulesProvider.php](app/Validators/TableRulesProvider.php), [app/Validators/TableValidator.php](app/Validators/TableValidator.php) (`ruleThanType`, `decisionType`) |
| Moteur et décisions | [app/Services/Scoring.php](app/Services/Scoring.php) (`evaluate`), [app/Models/Decision.php](app/Models/Decision.php) (`toConsumerArray`, `getOutputType`) |
| Statistiques | [app/Repositories/TablesRepository.php](app/Repositories/TablesRepository.php) (analytics, `duplicateInto`, `fieldRenamesBetween`) |
| Versions | [app/Services/TableVersioning.php](app/Services/TableVersioning.php) (`contentHash`, `copyContent`), [app/Repositories/TableVersionsRepository.php](app/Repositories/TableVersionsRepository.php), [app/Http/Controllers/TableVersionsController.php](app/Http/Controllers/TableVersionsController.php) |
| Imports et exports | `app/Services/Excel/` (`ExcelLayout`, `ExcelTableWriter`, `ExcelTableReader`, `TableMergeService`), [app/Services/TableExportService.php](app/Services/TableExportService.php), [app/Services/TableImportService.php](app/Services/TableImportService.php) |
| Flows | [app/Repositories/FlowRepository.php](app/Repositories/FlowRepository.php) (`validateGraph`, `tableOutputType`, `canStandIn`, `renameEdges`), [app/Services/FlowEngine.php](app/Services/FlowEngine.php) (`resolveEdgeValue`, `assembleOutputs`), [app/Services/FlowVersioning.php](app/Services/FlowVersioning.php) (`followTable`), [app/Repositories/FlowVersionsRepository.php](app/Repositories/FlowVersionsRepository.php) (`repin`) |
| Migrations | `database/migrations/` : modèle à suivre, `2026_10_10_120000_TableVersions.php` ; précédent de changement de forme, `2016_05_26_180242_AddTableVariants.php` |
| Tests | `tests/_support/ApiTester.php`, `tests/api/TablesCest.php`, `DecisionsCest.php`, `TablesImportExportCest.php`, `FlowsCest.php`, `tests/unit/` |

**gandalf.web**

| Sujet | Fichiers |
|---|---|
| Types | `types/decision-table.ts`, `types/version.ts`, `types/flow.ts`, `composables/useGandalf.ts` (`VersionContent`) |
| Tables | `pages/tables/tableCreate.vue`, `pages/tables/[id]/[variantId]/edit.vue`, `components/decision-table/DecisionTable.vue`, `components/decision-table/DecisionInput.vue`, `pages/tables/[id]/tableInfo.vue` |
| Résultats | `pages/tables/[id]/debug.vue`, `components/HistoryTable.vue`, `pages/history/[id].vue`, `pages/tables/[id]/analytics.vue` |
| Flows | `components/flow-editor/FlowCanvas.vue`, `pages/flows/[id]/edit.vue`, `utils/flowTypes.ts` (`tableOutputType`), `utils/flowPins.ts` (`tablesForPins`) |
