# Gandalf: several outputs per table

[Version française](OUTPUTS.fr.md)

> **Status: proposal, nothing is implemented.** This document assesses the
> request, lists the impacts and proposes a plan. The
> [decisions](#decisions-to-make) remain to be made before the first batch.
> Until then, the behaviour described in [DOCUMENTATION.md](DOCUMENTATION.md),
> [API_GUIDE.md](API_GUIDE.md), [DRG.md](DRG.md) and [VERSIONS.md](VERSIONS.md)
> is the reference.
>
> Analysis of 12 October 2026, on `master` (API) and `main` (gandalf.web).

## Table of Contents

- [The request](#the-request)
- [Assessment](#assessment)
- [Prerequisites](#prerequisites)
- [Impacts](#impacts)
- [Decisions to make](#decisions-to-make)
- [Plan](#plan)
- [Risks](#risks)
- [Code anchors](#code-anchors)

---

## The request

- Today a table has a single output value, with a type and always the same
  name (`final_decision`).
- A table will declare **several outputs**, each with a **key**, a **name** and
  a **type**.
- In flows, each output can be used as a flow output or wired into a field of
  another table.
- Creating a decision table creates a default alphanumeric output; its key,
  name and type can be changed afterwards.
- Creating a scoring table makes the first output numeric, and its type cannot
  be changed.

---

## Assessment

The request fits the architecture. A decision response already holds an
`answer` / `answer_types` map, meant for several outputs, and
[DRG.md](DRG.md#extending-the-drg) sketches this extension.

It is nonetheless a **data model change**, comparable in size to table
versioning. The single value of a rule (`than`), the table type
(`decision_type`) and a variant's default value (`default_decision`) are read
by the engine, the decision history, analytics, versions, imports and exports,
flows and six screens of gandalf.web.

---

## Prerequisites

Three current gaps must be fixed first. Without them, declared outputs would
break flows in production.

1. **The output type is not versioned.** `matching_type` and `decision_type`
   live on the table, outside a version's content: neither the content hash
   nor the version copy include them. The engine and flow validation read them
   on the table, even for a pinned version. The `table_type_locked` lock
   planned by [VERSIONS.md](VERSIONS.md) is not implemented. With declared
   outputs, removing or retyping an output would break every pinned flow at
   once.
2. **Compatibility mode publishes flow versions without validating them.**
   When a table changes, `FlowVersioning::followTable` publishes and activates
   a new flow version without calling `validateGraph`.
3. **Old data was not migrated.** The renaming of table types (`decision`,
   `scoring`, `all` to `first`, `scoring_*`) shipped without a migration.
   Tables may still hold the old type or have no `decision_type`. The test data
   in `tests/_support/ApiTester.php` uses these old types, which validation
   rejects: API tests that create a table with them cannot pass.

---

## Impacts

| Area | What changes |
|---|---|
| API, model and validation | Output declarations (key, name, type). Values per output in each rule and in each variant's default value. Value validation by each output's type. The `$fillable` / `$visible` lists of `Table`, `Variant`, `Rule`, `TableVersion` and `Decision`: a key missing from them is dropped without error. |
| API, engine and responses | Computing each output: first matching rule, or aggregation for scoring. Storing it in the decision history. Compatible v1 and v2 responses: root `final_decision`, `answer`, `answer_types`, and `rules[].decision` when `show_meta` is on. |
| Analytics | A rule counts as fired through the comparison `than === decision`, which no longer works once the value is a map. Another criterion is needed (for instance a `matched` flag on the rule). |
| Versions, revisions, copy | Outputs join the versioned content: content hash, copy, drafts, rollback to a revision, copy and move between applications. |
| Imports and exports | The Excel workbook (gandalf-xlsx-v2) accepts a single `DECISION` column and a single type in `_meta`. It needs a format with several outputs, while old files stay importable. `TableMergeService::serializeVariant` would today drop the extra outputs of variants missing from the file. JSON and CSV imports do not validate values by type. |
| API, flows | One port per output, instead of a hardcoded `final_decision`. Validation against the outputs of the pinned version. Following output renames, whereas only fields are followed today (`renameEdges`). Typing of flow outputs, never checked today. A missing output gives `null` as a flow output (`assembleOutputs`) but an error on a wire (`resolveEdgeValue`): to be made consistent. |
| gandalf.web, tables | Creation: initial output. Editor: N columns pinned to the right, whereas the table handles one, and output management. Table page, test page, decision history (old and new decisions coexist), analytics. Draft saving: `VersionContent` only sends `fields`, `variants` and `variants_probability`. |
| gandalf.web, flows | One handle per output on each table node. Choosing the output when adding a flow output. Type checks. Pinned versions only pass their fields to the editor (`tablesForPins`). |
| Data | Migrating tables and all their versions. History (decisions, flow runs, revisions) stays in the old shape: screens must display both. |
| Documentation and tests | README, API_GUIDE, GUIDE, DOCUMENTATION, DRG and VERSIONS (EN and FR), `openapi.yaml`, the gandalf.web manual. Many tests and test data. |

---

## Decisions to make

Each point carries a recommendation, to be confirmed.

1. **Where outputs live.** Recommendation: in the versioned content, like
   fields. A published version freezes its outputs, which protects pinned
   flows. The table type (decision or scoring) is locked after the first
   publication.
2. **Scoring tables with several outputs.** The request does not say how rules
   combine. Recommendation: every output of a scoring table is numeric and
   aggregated by the table's function (sum, minimum, maximum, count). Other
   options: a single output for scoring, or an aggregation function per output.
3. **Output types.** Recommendation: the field types (text, number, boolean,
   date), so that an output can be wired into a field, plus JSON, which cannot
   be wired. Note: the current "alphanumeric" type (`alpha_num`) rejects spaces
   and hyphens. It must be decided whether a decision table's default output
   is "alphanumeric" in the strict sense or plain "text".
4. **Key of the default output.** Recommendation: existing tables migrate with
   the key `final_decision`, which breaks neither flows nor API clients; new
   tables get `decision`. In every case, the root `final_decision` of the
   response stays the value of the first output.
5. **Value when no rule matches.** Recommendation: one default value per
   output, required.
6. **Write compatibility.** Recommendation: the API keeps accepting the old
   shape (`than` and `decision_type`) on create and update. The current
   interface and existing scripts keep working during the transition, which
   lets the API ship before the interface.

---

## Plan

| Batch | Content |
|---|---|
| 0. Specification and prerequisites | Detailed specification (this document, completed with the decisions). Migration of old table types and fix of the test data. Versioning and locking of types. |
| 1. API, tables | Model, validation, engine, history, compatible responses, analytics, re-runnable migration, tests. |
| 2. API, flows | Ports per output, validation against the pinned version, following renames, typing of flow outputs, validation in compatibility mode. |
| 3. API, imports and exports | Excel workbook with several outputs, old formats still readable, JSON and CSV. |
| 4. gandalf.web, tables | Creation, multi-column editor and output management (numeric lock for scoring), table page, test, history, analytics. |
| 5. gandalf.web, flows | One handle per output, wiring, output choice, type checks. |
| 6. Delivery | API documentation and manual, migration rehearsal on a copy of real data, production release. |

Each batch goes through a branch, a code review and tests on a disposable
stack. Thanks to write compatibility (decision 6), batches 1 to 3 can ship
before the interface.

---

## Risks

1. **API client contract.** The root `final_decision`, `answer.final_decision`,
   scalar `rules[].decision` and `table.decision_type` are read by external
   clients; route v1 is deprecated but still served.
2. **Silent losses** through `$fillable` lists, during copies
   (`duplicateInto`) and during Excel imports.
3. **Migration.** It must rewrite tables and all their versions, stay
   re-runnable, and use the database directly (like the 2026 migrations), not
   Eloquent models.
4. **Mixed history.** Old decisions, flow runs and revisions keep the
   single-value shape. Rolling back to an old revision brings that shape back
   into a table.
5. **Flows.** `final_decision` is hardcoded in the API, the editor, tests and
   documentation. Old graphs must keep working.

---

## Code anchors

Line numbers drift; these are the files and functions to revisit.

**API**

| Topic | Files and functions |
|---|---|
| Model | [app/Models/Table.php](app/Models/Table.php), [app/Models/Variant.php](app/Models/Variant.php), [app/Models/Rule.php](app/Models/Rule.php), [app/Models/Concerns/HasTableContent.php](app/Models/Concerns/HasTableContent.php), [app/Models/TableVersion.php](app/Models/TableVersion.php) |
| Validation | [app/Validators/TableRulesProvider.php](app/Validators/TableRulesProvider.php), [app/Validators/TableValidator.php](app/Validators/TableValidator.php) (`ruleThanType`, `decisionType`) |
| Engine and decisions | [app/Services/Scoring.php](app/Services/Scoring.php) (`evaluate`), [app/Models/Decision.php](app/Models/Decision.php) (`toConsumerArray`, `getOutputType`) |
| Analytics | [app/Repositories/TablesRepository.php](app/Repositories/TablesRepository.php) (analytics, `duplicateInto`, `fieldRenamesBetween`) |
| Versions | [app/Services/TableVersioning.php](app/Services/TableVersioning.php) (`contentHash`, `copyContent`), [app/Repositories/TableVersionsRepository.php](app/Repositories/TableVersionsRepository.php), [app/Http/Controllers/TableVersionsController.php](app/Http/Controllers/TableVersionsController.php) |
| Imports and exports | `app/Services/Excel/` (`ExcelLayout`, `ExcelTableWriter`, `ExcelTableReader`, `TableMergeService`), [app/Services/TableExportService.php](app/Services/TableExportService.php), [app/Services/TableImportService.php](app/Services/TableImportService.php) |
| Flows | [app/Repositories/FlowRepository.php](app/Repositories/FlowRepository.php) (`validateGraph`, `tableOutputType`, `canStandIn`, `renameEdges`), [app/Services/FlowEngine.php](app/Services/FlowEngine.php) (`resolveEdgeValue`, `assembleOutputs`), [app/Services/FlowVersioning.php](app/Services/FlowVersioning.php) (`followTable`), [app/Repositories/FlowVersionsRepository.php](app/Repositories/FlowVersionsRepository.php) (`repin`) |
| Migrations | `database/migrations/`: model to follow, `2026_10_10_120000_TableVersions.php`; precedent for a shape change, `2016_05_26_180242_AddTableVariants.php` |
| Tests | `tests/_support/ApiTester.php`, `tests/api/TablesCest.php`, `DecisionsCest.php`, `TablesImportExportCest.php`, `FlowsCest.php`, `tests/unit/` |

**gandalf.web**

| Topic | Files |
|---|---|
| Types | `types/decision-table.ts`, `types/version.ts`, `types/flow.ts`, `composables/useGandalf.ts` (`VersionContent`) |
| Tables | `pages/tables/tableCreate.vue`, `pages/tables/[id]/[variantId]/edit.vue`, `components/decision-table/DecisionTable.vue`, `components/decision-table/DecisionInput.vue`, `pages/tables/[id]/tableInfo.vue` |
| Results | `pages/tables/[id]/debug.vue`, `components/HistoryTable.vue`, `pages/history/[id].vue`, `pages/tables/[id]/analytics.vue` |
| Flows | `components/flow-editor/FlowCanvas.vue`, `pages/flows/[id]/edit.vue`, `utils/flowTypes.ts` (`tableOutputType`), `utils/flowPins.ts` (`tablesForPins`) |
