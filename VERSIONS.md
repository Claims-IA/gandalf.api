# Gandalf: table and flow versions

> **Status: design approved, implementation in progress.** This document
> describes the **planned** behaviour. Each section names the batch that
> delivers it (see [Batches and status](#batches-and-status)). Until a batch
> ships, the current behaviour described in [DOCUMENTATION.md](DOCUMENTATION.md),
> [API_GUIDE.md](API_GUIDE.md) and [DRG.md](DRG.md) remains the reference.

In production, tables and flows are no longer edited live. You prepare
**drafts**, **publish** them as frozen versions, and **activate** the version
the API runs. Variants stay inside each version, for A/B testing.

## Table of Contents

- [Mental model](#mental-model)
- [Data model](#data-model)
- [Lifecycle](#lifecycle)
- [Scopes](#scopes)
- [Execution](#execution)
- [HTTP API](#http-api)
- [Error handling](#error-handling)
- [Migration and compatibility mode](#migration-and-compatibility-mode)
- [Code map](#code-map)
- [Documentation to update](#documentation-to-update)
- [Batches and status](#batches-and-status)
- [Open points](#open-points)

---

## Mental model

| Notion | Role | Editable |
| ------ | ---- | -------- |
| **Table** | Stable identity: its id does not change for consumers or flows. Holds the title, description, category, table type (`matching_type`), decision type (`decision_type`) and the active version | Yes, except both types once a version is published |
| **Draft** | Version being prepared: name, author, origin version, full content (fields, variants, rules, conditions, split). Never run by an API key | Yes, and deletable |
| **Published version** | A frozen draft, numbered at publication (1, 2, 3 with no gaps) | No, except its variant split |
| **Active version** | Pointer to a published version: the one direct calls without a version run | You change which version is active |

Flows follow the same model (batch 4): a flow has drafts, published versions
and an active version. **Each flow version is pinned to a specific version of
every table it uses.** Activating a new table version therefore changes
nothing for flows in production.

Variants and versions are independent axes. The version answers "which
definition is in production?", the variant "which branch of that definition
handles this request?". The API first picks the version, then a variant
according to that version's split.

---

## Data model

### Table (`tables` collection): batch 1

Eventually, the table document only keeps its identity.

| Field | Type | Notes |
| ----- | ---- | ----- |
| `title`, `description`, `category_id`, `applications` | unchanged | |
| `matching_type`, `decision_type` | string | Stay on the table. **Locked once a version is published**: changing them would immediately change the behaviour of every version. To change them, create a new table. The lock applies with release B: during A, every table has a published version and the current editor can still change these types, as today |
| `active_version_id` | ObjectId | Published version run by default |

The content fields (`fields`, `variants`, `variants_probability`) live in its
versions. **During release A, the table document keeps a copy of its active
version's content**: the code that still reads the table (flows, export,
history, list) keeps working unchanged. Invariant: that content is always the
active version's. Each table save publishes a version when its content changes
(compatibility mode), and each activation copies the activated version's
content back. The copy disappears with release B, once all the code reads
versions.

### Table version (`table_versions` collection): batch 1

| Field | Type | Notes |
| ----- | ---- | ----- |
| `table_id` | ObjectId | Owning table |
| `applications` | array | Same application scoping as the table |
| `status` | `draft` \| `published` | |
| `number` | int \| null | Assigned at publication, unique per table, no gaps. `null` for a draft |
| `name` | string | Draft name ("Threshold 2000") |
| `comment` | string | Publication comment |
| `origin_version_id` | ObjectId \| null | Version or draft this draft comes from |
| `autopublished` | bool | Published by the compatibility mode or the migration, not by a person |
| `fields` | array | Same shape as today (`Field`) |
| `variants` | array | Same shape as today (`Variant` > `Rule` > `Condition`). A variant keeps its `_id` from one version to the next. There is no default variant (batch 2b): `is_default` is no longer stored nor returned |
| `variants_probability` | string | Split mode (`first`, `random`, `percent`). Editable on a published version |
| `created_by`, `created_at`, `updated_at` | | |
| `published_by`, `published_at` | | Set at publication |

A version has the same content shape as today's table: `Scoring` can evaluate
it without changing its rule logic.

The current variant invariant is kept: all variants of a version share the
same fields, and each rule keeps one condition per field, in field order.

### Flow and flow version (`flows`, `flow_versions` collections): batch 4

Same split as for tables. The `flows` document keeps the identity and
`active_version_id`. A flow version holds `inputs`, `outputs`, `nodes` and
`edges`. Each node adds `table_version_id`: the table version it is pinned to.

In place since batch 4 (model `FlowVersion`, service `FlowVersioning`); see
[DRG.md](DRG.md#versions) for the flow side. As for tables, the flow document
keeps a copy of its active version's graph, nodes pinned. A node saved without
a pin keeps its current one or takes its table's active version; a pin must be
a published version. A `FlowRun` records the flow version and each node's
table version, and a flow can be test-run (runs and decisions flagged `test`).

### Decision (`decisions` collection): batches 1, 3 and 4

| Added field | Batch | Notes |
| ----------- | ----- | ----- |
| `table.version` | 1 | `{ _id, number }` of the version run. `null` for decisions made before versioning |
| `consumer` | 3 | `client_id` of the calling API key; `null` for a user (OAuth token) |
| `test` | 3 | `true` for a test run from gandalf.web (draft or version), `false` otherwise; absent on older decisions. Test runs are left out of analytics and of the default decision history (`GET /api/v1/admin/decisions?test=true` lists them) |
| `flow.version` | 4 | `{ _id, number }` of the flow version, for a decision made inside a flow |

### Calls per key (`version_calls` collection): batch 3

One row per key, table (or flow) and requested version: `consumer` (the key's
`client_id`), `table_id` or `flow_id`, `requested_version` (`null` when the
call names no version), `last_call_at`, `count`, `applications`. It feeds the
activation screen ("keys calling without a version") and the guard against
deleting a version still in use.

In place since batch 3 for tables and batch 4 for flows, service
`App\Services\VersionCalls`. Only successful calls of API keys count: a user
has no code pinned to a version. A key counts as calling while its last call
is within the window (`TABLE_VERSIONS_CALL_WINDOW_DAYS`, 30 days by default)
and it is still a key of the application: a deleted key calls nothing. A
unique index (`version_calls_key`, migration
`2026_10_10_140000_VersionCalls`) keeps one row per key, table and requested
version.

---

## Lifecycle

```text
  Drafts (editable)                 Published versions (frozen)          Calls
  ┌──────────────────────┐ publish ┌──────────────────────────┐
  │ "Threshold 2000"     │───────► │ v4  active               │◄──── no version
  │ from v4              │         │ v3  published, inactive  │◄──── "version": 3 (dedicated scope)
  └──────────────────────┘         └──────────────────────────┘
            ▲                                   │
            └──────── create a draft from a version ──────────┘
```

| Action | Effect | Scope |
| ------ | ------ | ----- |
| Create a draft | From a published version or another draft. Several drafts can coexist | `tables_update` |
| Edit a draft | Fields, variants, rules, split | `tables_update` |
| Run a draft | From gandalf.web only; decisions flagged `test` | `tables_update` |
| Run a published version | From gandalf.web only, as a test; decisions flagged `test` | `decisions_make`, as calling it |
| Publish | Freezes the draft and gives it the next number. The active version does not change. Warns when the draft does not start from the active version | `tables_publish` |
| Activate | The chosen published version becomes the active one, atomically. Only affects direct calls: flows stay pinned to their versions | `tables_activate` |
| Roll back | Reactivate an earlier version | `tables_activate` |
| Change the split | Mode and percentages of a version's variants, published or not, without republishing | `tables_update` for a draft; `tables_activate` for a published version, which keys may call with `version` (see [Open points](#open-points)) |
| Delete a draft | At any time | `tables_update` |
| Delete a published version | Only if it is not active, no flow or flow version is pinned to it and no key still calls it | `tables_publish` |

There is no merge between drafts. Each draft, then each version, records its
origin version. Publishing and activating flag a draft that does not start from
the active version: it lacks the changes of later versions.

Publishing and activating are two separate actions, tied to two scopes. Nothing
activates a version automatically, outside the
[compatibility mode](#migration-and-compatibility-mode).

In place since batches 2 to 4. During release A, activating a version copies
its content onto the table. In compatibility mode the application's flows
follow: each gets a version pinning the activated table version, published and
activated, and they follow the field renames as they follow a table update.
Without it, flows stay on their pinned versions: the activation lists them
(`meta.flows_outdated`) and `POST /api/v1/admin/tables/{id}/flows/update`
brings them to it (see [DRG.md](DRG.md#versions)).

---

## Scopes

Scopes are added to `config/applicationable.php`. Every new route is added to
the `acl` rules of the same file; `tests/unit/AclCoverageTest.php` checks that
no route escapes them.

| Scope | Held by | Role |
| ----- | ------- | ---- |
| `tables_publish` | users | Publish a version; delete a published version |
| `tables_activate` | users | Activate a version (go to production); change the split of a published version; roll back to a revision of another version |
| `decisions_make_inactive` | API keys and users | "Make API call for inactive versions": call an inactive published version with the `version` field. A user holds it to give it to a key (a user only gives a key the scopes they hold) and to call the route v2 with their own token |

Existing scopes keep their meaning. A key holding only `decisions_make` runs
the active version only. Those who test and publish (QA, for example) are not
necessarily those who go to production.

In place since batches 2 and 3. A new application's owner gets every scope.
The `2026_10_10_130000_TableVersionScopes` migration grants `tables_publish`,
`tables_activate` and `decisions_make_inactive` to the admins of existing
applications; other members get them from an admin.

Two-level administration (application admin, superadmin reserved to Claims IA)
is a separate track: batch 7, on its own branch `feature/two-level-admin`
(ADMIN.md there).

---

## Execution

| Call | Table version run |
| ---- | ----------------- |
| `POST /api/v1/tables/{id}/decisions` (unchanged, deprecated) | The active version |
| `POST /api/v2/tables/{id}/decisions` without `version` | The active version |
| `POST /api/v2/tables/{id}/decisions` with `"version": 3` | Published version 3 if it is active; otherwise only with the `decisions_make_inactive` scope |
| Test run from gandalf.web | The chosen draft or version; decision flagged `test` |
| Flow node (batch 4) | The table version the flow version is pinned to |

Within the selected version, the variant is chosen as today: by `variant_id`
when given, otherwise according to `variants_probability`. A `variant_id`
missing from the version run returns an explicit error, so that a client never
believes it tests one variant while running another.

In place since batches 3 and 4. `App\Services\Scoring::evaluate()` runs any
version: `check()` (route v1) the active one, `evaluateRequest()` (v2, test
runs) the requested one with the v2 body, and `FlowEngine` each node's pinned
version.

---

## HTTP API

### Decision route v2: batch 3

```http
POST /api/v2/tables/{id}/decisions
Authorization: Basic <API key>

{
  "version": 4,
  "variant_id": "5f…",
  "data": { "age": 42, "material": "carpet" }
}
```

`version` and `variant_id` are optional. Input values are isolated in `data`:
no collision is possible with a table field (today `variant_id` is mixed with
the input values and reserved as a field key). The response is the v1 response,
which already holds the variant run (`table.variant`), plus the version run
(`table.version`):

```json
{
  "data": {
    "final_decision": "PNO",
    "table": {
      "_id": "…",
      "version": { "_id": "…", "number": 4 },
      "variant": { "_id": "…", "title": "Threshold 1600" }
    }
  }
}
```

The same shape applies to `POST /api/v2/flows/{id}/decisions` (batch 4),
without `variant_id`: `{ "version": 2, "data": { … } }`.

The v1 route keeps its flat body and runs the active version. It is declared
deprecated with an end date, through the `Deprecation` and `Sunset` headers.

In place since batch 3 for tables. Details:

- Callers: the same as v1, API keys and users (OAuth token). A published
  version other than the active one needs `decisions_make_inactive`, from the
  key or the user.
- Input errors come under `data.*` (`"data.age": [...]`); a body without
  `data` (the v1 shape) answers `422`. The decision's `request` holds `data`
  only: `version` and `variant_id` show in `table.version` and `table.variant`.
- The v1 headers come from two dates set in the environment,
  `DECISIONS_V1_DEPRECATED_AT` (`Deprecation: @<timestamp>`, plus
  `Link: </api/v2/tables/{id}/decisions>; rel="successor-version"`) and
  `DECISIONS_V1_SUNSET` (`Sunset: <HTTP date>`). Without a date, no header:
  the dates are still to decide (see [Open points](#open-points)).

### Admin routes: batch 2

| Method | Route | Role |
| ------ | ----- | ---- |
| GET | `/api/v1/admin/tables/{id}/versions` | Drafts and published versions, active version, origin version |
| POST | `/api/v1/admin/tables/{id}/versions` | Create a draft (`from_version_id`, `name`) |
| GET | `/api/v1/admin/tables/{id}/versions/{version_id}` | Read a version |
| PUT | `/api/v1/admin/tables/{id}/versions/{version_id}` | Edit a draft (refused on a published version) |
| DELETE | `/api/v1/admin/tables/{id}/versions/{version_id}` | Delete, with the [lifecycle](#lifecycle) guards |
| POST | `/api/v1/admin/tables/{id}/versions/{version_id}/publish` | Publish (`comment`) |
| PUT | `/api/v1/admin/tables/{id}/versions/{version_id}/split` | Change a version's split (`variants_probability`, `variants[]._id` and `probability`) |
| POST | `/api/v1/admin/tables/{id}/activate` | Activate (`version_id`); the response lists the keys calling without a version |
| POST | `/api/v1/admin/tables/{id}/versions/{version_id}/decisions` | Test run from gandalf.web (decision flagged `test`) |
| GET | `/api/v1/admin/tables/{id}/calls` | Recent calls of the project's API keys, per requested version |

The equivalent flow routes are in place since batch 4, without the split
(see [DRG.md](DRG.md#http-api)): `/api/v1/admin/flows/{id}/versions…`,
`/activate`, `/calls`, and the test run with `{ data }`. On the table side,
`GET /api/v1/admin/tables/{id}/flows` lists the flows using a table and
`POST /api/v1/admin/tables/{id}/flows/update` brings them to its active
version.

In place since batches 2 and 3. Details:

- **List**: versions without their content (`fields`, `variants`), drafts
  first (last edited first), then published versions (newest first). Each
  version, here and in the other responses, carries `is_active`.
- **Create a draft**: from `from_version_id`, by default the active version.
  Variant ids are kept, so a client forcing a variant keeps working.
- **Edit a draft**: same payload and validation as a table update (`fields`,
  `variants`, `variants_probability`), plus `name`. `matching_type` and
  `decision_type` are the table's. Conditions follow renamed fields.
- **Divergence**: publishing or activating a version that does not descend
  from the active one answers `200` with `meta.warning = "version_diverges"`
  and `meta.active_version = { _id, number }`.
- **Activate**: returns the table. When fields were renamed between the two
  versions, `meta` also holds `field_renames`, `flows_updated`,
  `flows_failed` and `flows_invalid`, as a table update does.
- **Split**: variants left out keep their percentage. In `percent` mode, the
  percentages must add up to 100.
- **Test run**: body of the route v2 without `version` (`variant_id`, `data`),
  scope `tables_update`. Runs any draft or version and always returns the
  rules, whatever the `show_meta` setting.
- **Calls**: rows `consumer`, `description` (the key's), `requested_version`,
  `last_call_at`, `count`, latest first; `meta.window_days` gives the window.
  The activation response carries the rows without a version in
  `meta.callers_without_version`: the keys whose behaviour the activation
  changes.

### Import and export: batch 2b

In place. `GET /api/v1/admin/tables/{id}/export` takes `version_id` (a draft
or a published version, the active one by default) and `variant_id` (Excel and
CSV, the first variant by default). The Excel `_meta` sheet records
`version_id` and `version_number`, and its lock tokens (`exported_at`,
`content_hash`) are the exported content's. While the compatibility mode is
on, the active version is exported from the table, which holds its copy.

Re-importing an Excel workbook (`POST /api/v1/admin/tables/import`) writes
to its origin:

| Workbook exported from | Import |
| ---------------------- | ------ |
| A draft | Updates the draft (`200`), lock on the draft |
| A published version (any other case) | Creates a draft from it, named "Import Excel" (`201`) |
| The active version, compatibility mode on | Updates the table, as before versions |
| A table, before versions (no `version_id`) | Updates the table |

A draft receives the content only: the title and description stay the
table's, and the content is validated against the table's types. The response
carries `meta.imported_into` (`table` or `version`).

`is_default` is gone: the split picks the variant that runs, an export names
the variant it exports, and the JSON export puts the first variant under its
`table` key. The flag stays in documents saved before, unread; the content
fingerprint ignores it, so their next save publishes nothing.

---

## Error handling

| Situation | HTTP | Code |
| --------- | ---- | ---- |
| Unknown requested version | 404 | `version_not_found` |
| Inactive published version, caller without `decisions_make_inactive` | 403 | `version_not_allowed` |
| Draft requested by an API key | 404 | `version_not_found` (a draft has no number) |
| `variant_id` missing from the version run | 422 | `variant_not_found` |
| Editing a published version (other than its split), or publishing it again | 409 | `version_published` |
| Activating a draft | 409 | `version_not_published` |
| Deleting the active version, a version pinned by a flow or still called | 409 | `version_in_use` |
| Changing `matching_type` or `decision_type` after publication | 409 | `table_type_locked` |
| Invalid input values | 422 | unchanged, errors under `data.*` in v2 |

In place since batches 2 to 4, except `table_type_locked` (release B). A request without
the scope a version needs (`tables_publish`, `tables_activate`) gets the usual
`403 access_denied`, with the missing scope in `meta.scopes`. The route v1
keeps its own error: a `variant_id` the active version lacks is a `404
variant_not_found`.

---

## Migration and compatibility mode

### Release A (batches 1 to 3)

- **Table migration** (`database/migrations`): each table becomes its version
  1, published and active; its content is copied to `table_versions` and stays
  on the table (copy of the active version). The migration is idempotent and
  **non-destructive**: its rollback removes `table_versions` and
  `active_version_id`. As [AGENTS.md](AGENTS.md) requires, it is never run to validate
  documentation; it is first tested on a copy of the data.
- **Existing decisions**: `table.version` is `null` (made before versioning).
- **Compatibility mode**: the current editor does not know about versions.
  Until release B, a save through `PUT /api/v1/admin/tables/{id}` creates a
  version, publishes it and activates it at once, as today. It is the only
  exception to the "nothing activates automatically" rule. It is driven by the
  `TABLE_VERSIONS_LEGACY_AUTOPUBLISH` environment variable (on by default) and
  disappears with release B. In place (batch 1): after each table save,
  `TableObserver` calls `TableVersioning::syncFromTable()`, which compares the
  content fingerprint (`contentHash()`) with the active version's. Every write
  path thus goes through the same check: editor, creation, copy, move, Excel
  import, changelog rollback. The changelog records the active version:
  rolling back to a revision reactivates its version.
- **Flows**: not versioned yet. They run the active version of each table, as
  they run the live table today.

### Release B (batches 4 to 6)

- **Flow migration**: each flow becomes its version 1, published and active,
  **pinned to the version of each table that is active at migration time**.
  The compatibility mode may have created table versions since release A:
  pinning flows to version 1 would change their behaviour. In place since
  batch 4 (`2026_10_10_150000_FlowVersions`). While the compatibility mode is
  on, flows keep following their tables, so their pins stay the active
  versions until the mode is turned off.
- **End of the compatibility mode and of the copy**: the content leaves the
  table document, whose identity is all the code still reads.

---

## Code map

Batch 0 inventory: the places that read or write table or flow content, and
what must change there. Paths start at the repository root.

| Area | Code | Change | Batch |
| ---- | ---- | ------ | ----- |
| Models | `app/Models/Table.php`: `fields()`, `variants()`, `setFields()`, `setVariants()`, `getVariantForCheck()`, `toListArray()` | Content moves to a new `TableVersion` model; the table list reads the active version | 1 |
| | `app/Models/Decision.php`: `getTableArray()` | Add and cast `table.version` | 1 |
| | `app/Models/Flow.php`, `app/Models/FlowRun.php` | Content in `FlowVersion`; a run records the flow version and each node's table version | 4 |
| Execution | `app/Services/Scoring.php`: `check()`, `createValidationRules()`, `prepareFieldPreset()` | Evaluate a version: active by default, requested in v2, pinned from a flow | 1, 3 |
| | `app/Services/FlowEngine.php`: `run()`, `buildNodeInput()`, `requireTable()`, `persistRun()` | Read the active flow version and pass each node's pinned version to `Scoring` | 4 |
| Tables | `app/Repositories/TablesRepository.php`: `createOrUpdate()`, field renames, `normalizeVariantConditions()` | Write content to a version, identity to the table | 1, 2 |
| | same file: `duplicateInto()`, `copyTo()`, `moveTo()` | Copy or move the versions with the table | 1 |
| | same file: `analyzeTableDecisions()` | Filter decisions by version instead of by last update date | later |
| | `AbstractRepository::delete()` (`nebo15/lumen.rest` package), `ProjectsController::deleteProject()` | Also delete the versions | 1 |
| Flows | `app/Repositories/FlowRepository.php`: `validateGraph()`, `findProjectTable()`, `tableHasField()`, `fieldType()`, `findReusableTable()`, `canStandIn()` | Read fields from the pinned table version; types are still read on the table | 4 |
| | same file: `followFieldRenames()`, `copyReferencedTables()`, `copyTo()`, `moveTo()` | Target flow drafts; copy the pinned versions | 4 |
| Validation | `app/Validators/TableRulesProvider.php`, `app/Validators/TableValidator.php` | Split identity rules from content rules; provide the table's `matching_type` and `decision_type` when validating a version | 1 |
| Import and export | `app/Services/TableImportService.php`, `app/Services/TableExportService.php`, `app/Services/Excel/` (`ExcelTableWriter`, `ExcelLayout`, `ExcelTableReader`, `ExcelImportResult`, `TableMergeService`) | Read and write a version; version id in the metadata sheet; optimistic lock on the version; removal of `is_default` | 1, 2b |
| History | `app/Observers/TableObserver.php`, `app/Observers/FlowObserver.php`, `app/Providers/ObserverServiceProvider.php`, `app/Http/Controllers/ChangelogController.php` (`rollback()`, `tableFields()`) | Content history on versions; a content rollback targets a version | 1, 2 |
| Project export | `app/Services/DbTransfer.php` | Export `table_versions`, then `flow_versions` | 1, 4 |
| Routes and scopes | `app/Http/routes.php`, `config/applicationable.php`, `tests/unit/AclCoverageTest.php` | New routes and scopes (see the findings below) | 2, 3 |
| Controllers | `TablesController` (`update()`, `analytics()`, `export()`, `import()`), `FlowsController`, `ConsumerController` (`tableCheck()`, `flowCheck()`) | Target a version; v2 route | 1 to 4 |
| Test data | `database/seeds/DatabaseSeeder.php`, `tests/_support/ApiTester.php` | Create a table with its version; adapt the shape assertions | 1 |

### Batch 0 findings

- **No API test for flows**: there is no `FlowsCest`. Batch 4 starts by
  writing one, as a safety net before touching flows. Written in batch 4
  (`tests/api/FlowsCest.php`).
- **No Eloquent save of a table outside an authenticated request**:
  `TableObserver::saved()` has no guard when there is no user, and
  `Table::setVariants()` reads the authenticated user. A migration or a command
  that saves a table through Eloquent fails. The migration therefore uses
  `\DB::collection`, like
  `database/migrations/2016_05_26_180242_AddTableVariants.php`, without
  triggering any observer.
- **Do not copy that migration's `BulkWrite`**: it is built once and executed
  on every loop iteration, which the MongoDB driver refuses.
- **No migration since 2016, and nothing in the repository runs them** (no
  script, Dockerfile or CI). Release A must document the procedure:
  `php artisan migrate`, prior backup, rollback.
- **ACL: the first matching rule wins.** GET, PUT and DELETE routes under
  `/admin/tables/{id}/…` already fall under the generic rules (`tables_view`,
  `tables_update`, `tables_delete`). The new POST routes (versions, publish,
  activate) match no rule. Routes that need another scope (deleting a
  published version, changing the active version's split) must be declared
  **before** the generic rules.
- **Table types and validation**: `ruleThanType` and `decisionType` validate
  `than` and `default_decision` against `matching_type` and `decision_type`.
  Changing them would make versions invalid, which confirms the lock.
- **Old content without defaults** (found in batch 1): a table never saved
  again may lack keys the editor adds (`probability`, `default_title`…). The
  content fingerprint applies the model defaults, otherwise the first save
  after the migration would publish a version with no real change.
- **Existing gaps, independent of versioning**: deleting a project only
  deletes its tables (not its flows, runs, decisions or history); a project
  export includes neither flows nor runs. To fix separately, or with batches 1
  and 4.

---

## Documentation to update

Each batch updates, in the same PR, the documentation it makes obsolete, in
English and in French.

| Batch | Documents |
| ----- | --------- |
| 1 | [DOCUMENTATION.md](DOCUMENTATION.md) / [.fr](DOCUMENTATION.fr.md) (`Table`, `TableVersion`, `Decision` models, engine), [GUIDE.fr.md](GUIDE.fr.md) (2.2 and 2.3) |
| 2 | [API_GUIDE.md](API_GUIDE.md) / [.fr](API_GUIDE.fr.md) (admin routes, scopes), [openapi.yaml](openapi.yaml), [DOCUMENTATION.md](DOCUMENTATION.md) / [.fr](DOCUMENTATION.fr.md) (controller, repository), [GUIDE.fr.md](GUIDE.fr.md) (scopes) |
| 3 | [API_GUIDE.md](API_GUIDE.md) / [.fr](API_GUIDE.fr.md) (v2 route, v1 deprecation), [openapi.yaml](openapi.yaml), [DOCUMENTATION.md](DOCUMENTATION.md) / [.fr](DOCUMENTATION.fr.md) (engine, calls per key), [GUIDE.fr.md](GUIDE.fr.md) (usage scenario) |
| 4 | [DRG.md](DRG.md) / [.fr](DRG.fr.md) (flow versions, pinned nodes, migration) |
| 7 | [GUIDE.fr.md](GUIDE.fr.md) (roles), [API_GUIDE.md](API_GUIDE.md) / [.fr](API_GUIDE.fr.md) |

This document moves each section from "planned" to "in place" as batches ship.

---

## Batches and status

| Batch | Content | Depends on | Status |
| ----- | ------- | ---------- | ------ |
| 0 | Technical framing (this document) | | done, to review |
| 1 | API: table version foundations, migration, compatibility mode | 0 | done, to review |
| 2 | API: drafts, publication, activation, split, scopes | 1 | done, to review |
| 2b | API: Excel import and export per version, removal of `is_default` | 2 | done, to review |
| 3 | API: v2 route, test runs, calls per key and the matching deletion guard, v1 deprecation | 2 | done, to review |
| 4 | API: flow versions, pinned nodes, bulk update of compatible flows | 1 | done, to review |
| 5 | gandalf.web: table versions | 2, 3 | done, to review (gandalf.web, branch `feature/table-versions`) |
| 6 | gandalf.web: flow versions | 4, 5 | done, to review (gandalf.web, branch `feature/table-versions`) |
| 7 | Two-level administration (independent) | | done, to review (branch `feature/two-level-admin` in gandalf.api and gandalf.web, documented in its ADMIN.md) |

Release A: batches 1 to 3. Release B: batches 4 to 6.

---

## Open points

- **Scope required to change the split of a published version**: it is a
  production change, for the active version and for the keys that call
  another one with `version`. Implemented with `tables_activate` (batch 2, then
  extended to every published version after review), to confirm.
- **"No key still calls it"**: window to define. Implemented as proposed in
  batch 3 (last call less than 30 days ago, `TABLE_VERSIONS_CALL_WINDOW_DAYS`),
  to confirm.
- **End date of the v1 route**: the headers are ready in batch 3
  (`DECISIONS_V1_DEPRECATED_AT`, `DECISIONS_V1_SUNSET`); the dates are to
  decide.
- **Production migration procedure**: none is documented today (who runs
  `php artisan migrate`, when, with which backup).
