# Gandalf — Decision Requirement Graph (DRG)

> **Machine-readable specification:** [openapi.yaml](openapi.yaml) (the `Flows` tag)
> **Related docs:** [DOCUMENTATION.md](DOCUMENTATION.md) · [API_GUIDE.md](API_GUIDE.md)

A **Decision Requirement Graph** composes several existing decision **tables** into
one higher-level decision: the output of one table feeds an input field of
another. It is Gandalf's equivalent of a DMN Decision Requirements Graph (the
concept found in Camunda, Drools, etc.).

The tables themselves are **unchanged** — every field stays `source: request`.
All the wiring lives on the **Flow**. A table can be a node of several flows and
still be called directly through `POST /tables/{id}/decisions`.

## Table of Contents

1. [Mental model](#mental-model)
2. [Data model](#data-model)
3. [The unified `answer` contract](#the-unified-answer-contract)
4. [Lifecycle](#lifecycle)
5. [Graph validation rules](#graph-validation-rules)
6. [Execution](#execution)
7. [Type compatibility](#type-compatibility)
8. [HTTP API](#http-api)
9. [Worked example](#worked-example)
10. [Error handling](#error-handling)
11. [Code map](#code-map)
12. [Extending the DRG](#extending-the-drg)
13. [Current limitations](#current-limitations)

---

## Mental model

A Flow is a directed acyclic graph:

- **nodes** — each references one decision table (`{ node_id, table_id }`).
- **edges** — wire a value into a node's input field. The source is either a
  declared flow **input** or an **upstream node's output**.
- **inputs** — the flow's public input contract (`{ key, type }`).
- **outputs** — the flow's public output contract (`{ name, from_node, from_output }`),
  the named values the flow returns.

```
             flow input "salary" ─┐
                                   ▼
   ┌─────────────┐   edge   ┌──────────────┐
   │  n_risk     │─────────▶│  n_verdict   │──▶ output "verdict"
   │ (scoring)   │  risk →  │ (first-match)│
   └─────────────┘  field   └──────────────┘
        ▲                          ▲
        └── flow input "history" ──┘
```

At run time the engine evaluates the nodes in **topological order** (every node
after the nodes it depends on), feeding each table only the fields it declares,
and finally assembles the flow's named `outputs` from the per-node results.

---

## Data model

### Flow (`flows` collection)

`App\Models\Flow` — application-scoped (multi-tenant) via `ApplicationableTrait`.

```jsonc
{
  "_id": "6a5437d8ad556857e10c1380",
  "title": "Credit approval",
  "description": "",

  // Public input contract. `type` ∈ numeric | boolean | string.
  "inputs": [
    { "key": "salary",  "type": "numeric" },
    { "key": "history", "type": "string"  }
  ],

  // One entry per referenced table.
  "nodes": [
    { "node_id": "n_risk",    "table_id": "69b29698c33fe7db7106c697" },
    { "node_id": "n_verdict", "table_id": "69b0a1c2c33fe7db7106c701" }
  ],

  // Wiring. `from` is a flow input ({input}) OR an upstream node output
  // ({node, output}); `into` is a downstream field ({node, field}).
  "edges": [
    { "from": { "input": "salary" },              "into": { "node": "n_risk",    "field": "salary"  } },
    { "from": { "input": "history" },             "into": { "node": "n_risk",    "field": "history" } },
    { "from": { "node": "n_risk", "output": "final_decision" },
                                                  "into": { "node": "n_verdict", "field": "risk"    } }
  ],

  // Public output contract. Each output names a node output.
  "outputs": [
    { "name": "verdict", "from_node": "n_verdict", "from_output": "final_decision" },
    { "name": "risk",    "from_node": "n_risk",    "from_output": "final_decision" }
  ]
}
```

> `from_output` is always `final_decision` today (see [Current limitations](#current-limitations)).
> If an edge omits `output`, it defaults to `final_decision`.

### FlowRun (`flow_runs` collection)

`App\Models\FlowRun` — one document per execution, linking the several
`Decision` documents a single run produces. This fills the role the unused
`group` field on `Decision` once hinted at.

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
  "error": null   // { "errors": ["…"] } on a failed/partial run
}
```

---

## The unified `answer` contract

Both a single table and a DRG return the **same output envelope**, so a consumer
parses either the same way. It is produced by `Decision::toConsumerArray()` for a
table and by `FlowEngine::run()` for a flow.

| Key             | Table decision                                  | Flow (DRG) run                          |
| --------------- | ----------------------------------------------- | --------------------------------------- |
| `answer`        | `{ final_decision }`                            | `{ <outputName>: value, … }`            |
| `answer_types`  | `{ final_decision: <type> }`                    | `{ <outputName>: <type>, … }`           |
| `decision_kind` | `table_simple` (first) / `table_advanced` (scoring) | `drg`                               |
| (table only)    | `final_decision` also stays at the root         | —                                       |
| (flow only)     | —                                               | `flow_run_id`, `nodes[]` (per-node trace) |

Output **type** is derived, not stored:

- a `scoring_*` table → `numeric` (numeric by nature, structurally single-output);
- a `first`-match table → its declared `decision_type` (`alpha_num` | `numeric` | `string` | `json`).

`Decision::getOutputType()` and `FlowRepository::tableOutputType()` implement the
same mapping; keep them in sync if you change it.

---

## Lifecycle

```
  Author (web UI / API)                    Consumer
        │                                      │
        │ POST/PUT /admin/flows                │ POST /flows/{id}/decisions
        ▼                                      ▼
  FlowRepository::createOrUpdate         ConsumerController::flowCheck
        │  validateGraph() ──► 422 on error    │  assertActiveAdmin()
        ▼                                      ▼
  flows document saved                   FlowEngine::run()
                                               │  orderNodes() (topo)
                                               │  per node: buildNodeInput → Scoring::check
                                               │  assembleOutputs()
                                               ▼
                                         FlowRun saved + answer returned
```

Validation happens **twice**, by design:

- **at save** — `validateGraph()` rejects a malformed graph (references, coverage,
  types, cycles) so authoring errors surface immediately;
- **at run** — the engine re-checks a node's table still exists (a table may have
  been deleted after the flow was saved) and Lumen validates each node's fields.

---

## Graph validation rules

`FlowRepository::validateGraph()` collects **all** problems and throws a single
`FlowValidationException` (rendered as one 422 with a `data.errors` array). The
checks, and the message each produces:

| Rule | Example message |
| ---- | --------------- |
| Every node has a `node_id` | `A node is missing node_id.` |
| Node ids are unique | `Duplicate node_id 'n_risk'.` |
| A node's `table_id` exists **in this project** | `Node 'n_risk' references a table that does not exist in this project.` |
| An edge targets a known node | `Edge #2 targets an unknown node.` |
| The target field is a real field key on the node's table | `Edge #2 targets field 'x', which is not a field of node 'n_verdict'.` |
| No field is wired by more than one edge | `Field 'risk' of node 'n_verdict' is wired by more than one edge.` |
| An input source is a declared flow input | `Edge #2 sources input 'foo', which is not a declared flow input.` |
| A node source is a known node | `Edge #2 sources an unknown node.` |
| Source output is `final_decision` | `Edge #2 sources output 'x'; only 'final_decision' is available today.` |
| A `json` output cannot feed a table input | `Edge #2: node 'n_risk' outputs json, which cannot feed a table input.` |
| Source and target types are compatible | `Edge #2: output of 'n_risk' (numeric) is not compatible with field 'name' (string).` |
| **Field coverage** — every table field is fed by an edge or a same-named input | `Field 'salary' of node 'n_risk' is not fed by any edge or flow input.` |
| The graph is acyclic | `The graph contains a cycle; a decision graph must be acyclic.` |
| At least one output, uniquely named, resolving to a known node | `The flow must declare at least one output.` / `Duplicate output name 'verdict'.` / `Output #0 references an unknown node.` |

The cycle check runs **regardless of other errors**, so a graph that has both a
cycle and, say, a bad output reports both in one response instead of surfacing
the cycle only on a second save.

> **Field coverage** is why a partial run never silently fails on a missing
> field: if a table field is neither wired nor covered by a same-named input,
> the flow is rejected at save time rather than 422-ing on a later run.

---

## Execution

`FlowEngine::run($flowId, array $inputs, $appId, $showMeta)` returns:

```jsonc
{
  "flow_run_id": "6a5437d895812a10c20a7630",
  "answer":       { "verdict": "approved", "risk": 40 },
  "answer_types": { "verdict": "string",   "risk": "numeric" },
  "decision_kind": "drg",
  "nodes": [ /* per-node trace, same shape as FlowRun.nodes */ ]
}
```

Step by step:

1. **Order** — `orderNodes()` builds the dependency map from node→node edges and
   calls `GraphSort::order()` (Kahn's algorithm). A `null` result means a cycle →
   `FlowValidationException`.
2. **Per node**, in order:
   - `requireTable()` re-loads the table, scoped to the current application, and
     fails clearly if it was deleted since the flow was saved.
   - `buildNodeInput()` assembles **only that table's own fields**: each field is
     fed from its wired edge if present, otherwise from a same-named flow input.
     Unrelated inputs — including `variant_id`, which is table-local — are never
     forwarded to a node.
   - `Scoring::check()` evaluates the table unchanged and returns the unified
     envelope (carrying `answer`, `answer_types`, `_id`).
3. **Assemble** — `assembleOutputs()` reads each declared output's
   `from_node` / `from_output` from the per-node results into `answer` /
   `answer_types`.
4. **Persist** — a `FlowRun` links the run to every `Decision` it produced (via
   `nodes[].decision_id`), scoped to the application.

`resolveEdgeValue()` uses `array_key_exists` so it distinguishes an **absent**
upstream output (an error) from a legitimately **null** value (allowed to flow
downstream).

### GraphSort

`App\Services\GraphSort::order(array $nodeIds, array $dependencies)` is the single
source of truth for topological order / cycle detection, shared by the repository
(reject cyclic graphs at save) and the engine (evaluate in dependency order).
Returns the ordered ids, or `null` when a cycle remains.

---

## Type compatibility

A wire is valid only when its source and target belong to the **same type
family** (`FlowRepository::typesCompatible` / `typeFamily`):

| Family    | Members                          |
| --------- | -------------------------------- |
| `text`    | `string`, `alpha_num`            |
| `numeric` | `numeric`, `number`, `integer`   |
| `boolean` | `boolean`, `bool`                |
| —         | `json` (and any unknown type) is **never** wireable, in or out |

So a numeric score cannot feed a `string` field, and a `boolean` field can only
be fed by a `boolean` source. A mismatch is rejected at save with a 422.

> **Note:** a table output is always `numeric` (scoring) or the table's
> `decision_type` (`alpha_num` | `numeric` | `string` | `json`) — **never
> `boolean`**. A downstream `boolean` field can therefore only be fed by a
> `boolean` flow input, not by an upstream node.

---

## HTTP API

All routes are application-scoped (`X-Application` header) and ACL-gated.

| Method & path | Handler | ACL scope |
| ------------- | ------- | --------- |
| `GET    /api/v1/admin/flows` | list flows | `tables_view` |
| `POST   /api/v1/admin/flows` | create flow | `tables_create` |
| `GET    /api/v1/admin/flows/{id}` | read flow | `tables_view` |
| `PUT    /api/v1/admin/flows/{id}` | update flow | `tables_update` |
| `DELETE /api/v1/admin/flows/{id}` | delete flow | `tables_delete` |
| `GET    /api/v1/admin/flows/{id}/runs` | paginated run history | `tables_view` |
| `POST   /api/v1/flows/{id}/decisions` | **run the flow** | `decisions_make` |

CRUD is provided by the Nebo15 `AbstractController`; writes go through
`FlowRepository::createOrUpdate`, where the graph is validated.

**Update semantics:** a `PUT` only overrides the keys present in the body — a
partial update (e.g. `title` only) merges over the stored graph and never wipes
`nodes`/`edges`/`outputs`. The merged graph is what gets re-validated.

---

## Worked example

Create a two-node flow (`curl`, bearer token + `X-Application` assumed):

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

Run it:

```bash
curl -X POST https://gandalf.example/api/v1/flows/<flow_id>/decisions \
  -H "Authorization: Bearer $TOKEN" -H "X-Application: $APP" \
  -H "Content-Type: application/json" \
  -d '{ "salary": 1500, "history": "clean" }'
```

Response:

```jsonc
{
  "flow_run_id": "6a5437d895812a10c20a7630",
  "answer":       { "verdict": "approved", "risk": 40 },
  "answer_types": { "verdict": "alpha_num", "risk": "numeric" },
  "decision_kind": "drg",
  "nodes": [ /* … per-node trace … */ ]
}
```

Inspect history:

```bash
curl https://gandalf.example/api/v1/admin/flows/<flow_id>/runs \
  -H "Authorization: Bearer $TOKEN" -H "X-Application: $APP"
```

---

## Error handling

A `FlowValidationException` renders as **HTTP 422**:

```jsonc
{
  "meta": { "code": 422, "error": "flow_validation", "error_message": "Flow graph validation failed" },
  "data": {
    "errors": [ "Edge #2: output of 'n_risk' (numeric) is not compatible with field 'name' (string)." ],
    "flow_run_id": "6a54…"   // present only for a RUN-time failure, not a save-time one
  }
}
```

- **Save-time** failures (bad graph) have no `flow_run_id`.
- **Run-time** failures record a partial `FlowRun` first, then surface its
  `flow_run_id` so you can correlate the 422 with the persisted trace
  (`GET /admin/flows/{id}/runs`). The partial run stores the same messages under
  `error.errors`.
- A node's per-field validation error (a Lumen `ValidationException`, whose own
  `getMessage()` is empty) is flattened via `errors()->all()` so the messages are
  never lost — see `FlowEngine::errorMessages()`.

---

## Code map

| File | Responsibility |
| ---- | -------------- |
| `app/Models/Flow.php` | Flow document (title, inputs, outputs, nodes, edges) |
| `app/Models/FlowRun.php` | One recorded execution, linking its decisions |
| `app/Repositories/FlowRepository.php` | CRUD, `validateGraph()`, `getRuns()`, type compatibility |
| `app/Services/FlowEngine.php` | `run()` — topological execution, wiring, persistence |
| `app/Services/GraphSort.php` | Shared Kahn topo-sort / cycle detection |
| `app/Http/Controllers/FlowsController.php` | Admin CRUD + `runs()` |
| `app/Http/Controllers/ConsumerController.php` | `flowCheck()` — the run endpoint |
| `app/Exceptions/FlowValidationException.php` | Carries `errors[]` + optional `flow_run_id` |
| `app/Exceptions/Handler.php` | Renders the 422 |
| `app/Models/Decision.php` | Unified `answer`/`answer_types`/`decision_kind` envelope |
| `config/applicationable.php` | ACL rules for flow routes |
| `app/Http/routes.php` | Route registration |

---

## Extending the DRG

- **A new validation rule** → add it in `FlowRepository::validateGraph()`, pushing a
  message onto `$errors` (don't throw mid-loop — collect all problems, then throw
  once). Add a row to the [rules table](#graph-validation-rules) above.
- **A new type family / synonym** → edit `FlowRepository::typeFamily()`. If output
  typing changes, keep `Decision::getOutputType()` and
  `FlowRepository::tableOutputType()` in lockstep.
- **Multiple named outputs per table** → today an output is always
  `final_decision`. To support several, extend the `answer` map produced by
  `Scoring::check`/`toConsumerArray`, then relax the `from_output === 'final_decision'`
  checks in `validateGraph` and `FlowEngine`.
- **Anything touching execution order** → go through `GraphSort` so the acyclicity
  invariant stays in one place.

---

## Current limitations

Deferred by design (noted as future evolutions):

- **Single output per node** — `from_output` is always `final_decision`.
- **No `boolean` table output** — `decision_type` cannot be `boolean`, so boolean
  node-to-node wiring is impossible (see [Type compatibility](#type-compatibility)).
- **No per-node variant selection** — a node runs its table's auto-selected /
  default variant; `variant_id` is not threaded per node.
- **No nested flows** — a node references a table, not a sub-flow (recursion /
  inter-flow cycles were judged non-essential at first).
- **Sequential execution** — nodes run one at a time in topological order; there
  is no parallel evaluation of independent branches.
