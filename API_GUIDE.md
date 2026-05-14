# Gandalf Decision Engine — API Guide

> **Machine-readable specification:** [openapi.yaml][1] (OpenAPI 3.0)

---

## Table of contents

1. [Overview][2]
2. [Core concepts][3]
3. [Authentication][4]
4. [Application context header][5]
5. [Request & response format][6]
6. [Pagination][7]
7. [Error handling][8]
8. [Sandbox mode][9]
9. [Endpoints reference][10]
   10. [Health][11]
   11. [Auth — OAuth 2.0][12]
   12. [Users][13]
   13. [Projects][14]
   14. [Decision Tables][15]
   15. [Decisions (Admin)][16]
   16. [Decisions (Consumer)][17]
   17. [Changelog][18]
10. [Decision engine deep dive][19]
	- [Matching types][20]
	- [Condition operators][21]
	- [Presets][22]
	- [Variant selection][23]
11. [Field types][24]
12. [Decision types][25]
13. [Quick-start guide][26]

---

## Overview

Gandalf is a **rule-based decision engine**. You define *decision tables* that describe the logic for a business decision (e.g. credit approval, fraud scoring,
routing). Your application then calls the API at runtime with field values and
receives back a deterministic (or probabilistic) decision.

Every evaluation is stored as an immutable *Decision* record, giving you a
complete audit trail with full input/output snapshots.

---

## Core concepts

| Concept       | Description                                                                                                                        |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Table**     | A decision table. Contains the field schema, matching strategy, and one or more variants.                                          |
| **Field**     | An input field the consumer must supply (e.g. `credit_score`, `age`). Has a type and an optional preset transform.                 |
| **Variant**   | An A/B testing variant within a table. Contains an ordered list of rules. Only one variant is evaluated per request.               |
| **Rule**      | A set of conditions (all must match — AND logic). If it fires, it contributes its `than` value to the final decision.              |
| **Condition** | A comparison of a field value against a threshold using an operator (e.g. `$gte 700`).                                             |
| **Preset**    | A pre-condition transform applied to a field value before conditions evaluate it (e.g. convert any non-null value to `true`).      |
| **Decision**  | Immutable audit record created every time a table is evaluated. Stores the full request snapshot, matched rules, and final result. |

---

## Authentication

The API uses **OAuth 2.0**. Three authentication methods are supported:

### 1. OAuth Bearer token (admin & user endpoints)

Obtain a token via `POST /oauth/token` with the `password` grant:

```http
POST /oauth/token
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/json

{
  "grant_type": "password",
  "username": "john.doe",
  "password": "s3cr3t!"
}
```

Response:
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJhbGci...",
  "refresh_token": "def502..."
}
```

Then pass the token in every request:
```http
Authorization: Bearer eyJhbGci...
```

### 2. OAuth basic client credentials (public user endpoints)

Required for registration and password reset endpoints. Encode your OAuth
`client_id:client_secret` as HTTP Basic auth.

### 3. Consumer API key (decision evaluation endpoints)

Your API consumer credentials (issued when a consumer is created in the
Applicationable system) are passed as HTTP Basic auth.

---

## Application context header

Most endpoints require the **`X-Application`** header. This is the MongoDB
ObjectID of the *application* (project) that owns the resource being accessed.

```http
X-Application: 507f1f77bcf86cd799439011
```

All queries are automatically scoped to this application for multi-tenant
isolation — you cannot see resources belonging to other applications.

---

## Request & response format

All requests and responses use **JSON**.

```http
Content-Type: application/json
Accept: application/json
```

Successful responses follow this envelope:

```json
{
  "meta": { "code": 200 },
  "data": { ... }
}
```

---

## Pagination

List endpoints return paginated results:

```json
{
  "meta": { "code": 200 },
  "data": [ ... ],
  "paging": {
    "total": 42,
    "per_page": 20,
    "current_page": 1,
    "last_page": 3,
    "next_page_url": "https://api.example.com/api/v1/admin/tables?page=2",
    "prev_page_url": null
  }
}
```

Use the `size` query parameter to control page size (default: `20`):

```
GET /api/v1/admin/tables?size=50&page=2
```

---

## Error handling

```json
{
  "meta": { "code": 422 },
  "error": {
    "type": "validation_failed",
    "message": "The given data failed to pass validation.",
    "fields": {
      "email": ["The email has already been taken."],
      "password": ["The password must be at least 6 characters."]
    }
  }
}
```

| HTTP Code | Meaning                         |
| --------- | ------------------------------- |
| `200`     | Success                         |
| `201`     | Resource created                |
| `401`     | Unauthenticated                 |
| `403`     | Forbidden (e.g. inactive admin) |
| `404`     | Resource not found              |
| `410`     | Token expired                   |
| `422`     | Validation error                |

---

## Sandbox mode

When the server runs with `APP_ENV=local`, certain responses include a `sandbox`
object containing tokens normally delivered by email. This lets you test the
full authentication flow without an email server.

**Registration** (`POST /api/v1/users`):
```json
{
  "sandbox": {
    "token_email": { "token": "abc123...", "expired": 1705320600 }
  }
}
```

**Password reset** (`POST /api/v1/users/password/reset`):
```json
{
  "sandbox": {
    "reset_password_token": { "token": "def456...", "expired": 1705320600 }
  }
}
```

---

## Endpoints reference

### Health

#### `GET /`

Returns `ok` (plain text). No authentication required. For load balancer health checks.

---

### Auth — OAuth 2.0

#### `POST /oauth/token`

| Grant type           | Use case                                        |
| -------------------- | ----------------------------------------------- |
| `password`           | User logs in with username + password           |
| `client_credentials` | Machine-to-machine (consumer) auth              |
| `refresh_token`      | Exchange a refresh token for a new access token |

---

### Users

All public user endpoints require OAuth basic client credentials (`Authorization: Basic ...`).

#### `GET /api/v1/users/username?username=john.doe`

Check username availability. Returns `200` if available, `422` if taken or invalid.
Format: 2–32 characters, alphanumeric, dashes, dots.

---

#### `POST /api/v1/users` — Register

```json
{
  "username": "john.doe",
  "email": "john.doe@example.com",
  "password": "s3cr3tPass!",
  "first_name": "John",
  "last_name": "Doe"
}
```

After creation, `active` is `false` until the email is verified.
Password: 6–32 characters.

---

#### `POST /api/v1/users/verify/email` — Verify email

```json
{ "token": "abc123..." }
```

Promotes `temporary_email` → `email`, sets `active: true`. Token TTL: **1 hour**.

---

#### `POST /api/v1/users/verify/email/resend` — Resend verification

```json
{ "email": "john.doe@example.com" }
```

---

#### `POST /api/v1/users/password/reset` — Request password reset

```json
{ "email": "john.doe@example.com" }
```

Sends a recovery email. Token TTL: **1 hour**.

---

#### `PUT /api/v1/users/password/reset` — Complete password reset

```json
{ "token": "abc123...", "password": "newS3cr3tPass!" }
```

---

#### `GET /api/v1/users/current` — Get profile

Requires Bearer token. Returns user profile + Intercom `secure_code` when enabled.

---

#### `PUT /api/v1/users/current` — Update profile

Requires Bearer token. All fields optional. Changing `password` requires `current_password`.

```json
{
  "username": "john.doe2",
  "email": "new@example.com",
  "password": "newPass!",
  "current_password": "oldPass!"
}
```

Changing `email` triggers a new verification flow — the new address goes into
`temporary_email` first.

---

#### `GET /api/v1/users?name=john` — Search users

Requires Bearer token. If `name` contains `@`, searches email; otherwise searches username.
Excludes the requesting user. Paginated.

---

#### `POST /api/v1/invite` — Invite a user

Requires Bearer token + `X-Application`.

```json
{
  "email": "colleague@example.com",
  "role": "manager",
  "scope": ["tables_read", "decisions_read"]
}
```

> The requested `scope` must be a **subset** of the inviting user's own scope.

---

### Projects

All project endpoints require Bearer token + `X-Application`.

#### `DELETE /api/v1/projects`

Permanently deletes the application and **all** its decision tables. **Irreversible.**

---

#### `GET /api/v1/projects/export`

Runs `mongoexport` and returns a download URL for a `.tar.gz` archive.

```json
{ "data": { "url": "https://api.example.com/dump/export_....tar.gz" } }
```

---

### Decision Tables

All table endpoints require Bearer token + `X-Application`.

#### `GET /api/v1/admin/tables`

Query params: `title`, `description`, `matching_type`, `size`.

---

#### `POST /api/v1/admin/tables` — Create table

```json
{
  "title": "Credit Scoring",
  "description": "Approves or denies credit applications.",
  "matching_type": "first",
  "decision_type": "string",
  "variants_probability": "first",
  "fields": [
    {
      "key": "credit_score",
      "title": "Credit Score",
      "type": "numeric",
      "source": "request",
      "preset": {}
    }
  ],
  "variants": [
    {
      "title": "Standard",
      "default_decision": "denied",
      "default_title": "Application denied",
      "default_description": "Does not meet minimum requirements.",
      "rules": [
        {
          "than": "approved",
          "title": "Good credit",
          "description": "Score is 700 or above.",
          "conditions": [
            { "field_key": "credit_score", "condition": "$gte", "value": 700 }
          ]
        }
      ]
    }
  ]
}
```

---

#### `GET /api/v1/admin/tables/{id}` — Get table

Returns full table with all fields, variants, and rules.

---

#### `PUT /api/v1/admin/tables/{id}` — Update table

Full replacement. Provide `_id` for existing sub-resources to preserve identifiers in changelog history.

---

#### `DELETE /api/v1/admin/tables/{id}` — Delete table

---

#### `GET /api/v1/admin/tables/{id}/{variant_id}/analytics` — Analytics

Returns hit-rate statistics per rule and condition (computed from historical decisions).

`probability` is a float `0.0–1.0` representing the fraction of decisions where each rule/condition fired.

---

### Decisions (Admin)

All admin decision endpoints require Bearer token + `X-Application`.

#### `GET /api/v1/admin/decisions`

Query params: `size`, `table_id`, `variant_id`.

---

#### `GET /api/v1/admin/decisions/{id}`

Full audit record with rules, conditions, and `matched` states.

---

#### `PUT /api/v1/admin/decisions/{id}/meta`

Attach metadata to a decision:

```json
{
  "meta": {
    "order_id": "ORD-12345",
    "customer_ref": "CUST-789"
  }
}
```

Limits: max 24 keys, keys max 100 chars (alphanumeric + dashes), values max 500 chars.

---

### Decisions (Consumer)

Consumer endpoints use consumer credentials (`Authorization: Basic ...`) or Bearer token.

#### `POST /api/v1/tables/{id}/decisions` — Evaluate

Submit all field values. All table fields must be present (use `null` for absent values).

```json
{
  "credit_score": 750,
  "annual_income": 60000,
  "variant_id": "507f1f77bcf86cd799439016"
}
```

The optional `variant_id` forces a specific variant.

**Prerequisites:**
- The application must have at least one active (email-verified) admin user.

**Response:**
```json
{
  "data": {
    "_id": "507f1f77bcf86cd799439018",
    "table": {
      "_id": "507f1f77bcf86cd799439017",
      "title": "Credit Scoring",
      "matching_type": "first",
      "variant": { "_id": "...", "title": "Standard" }
    },
    "title": "Good credit",
    "description": "Score is 700 or above.",
    "final_decision": "approved",
    "request": { "credit_score": 750, "annual_income": 60000 },
    "created_at": "2024-01-15T10:30:00+0000",
    "updated_at": "2024-01-15T10:30:00+0000"
  }
}
```

The `rules` array is included only when the application's `show_meta` setting is enabled.

---

#### `GET /api/v1/decisions/{id}` — Get decision (consumer view)

---

### Changelog

Every table save creates an automatic changelog snapshot. All queries are scoped to the current application.

#### `GET /api/v1/admin/{collection}/changelog`

List all changelog entries for a collection (e.g. `tables`).

#### `GET /api/v1/admin/{collection}/{model_id}/changelog`

Changelog history for a specific resource.

#### `GET /api/v1/admin/{collection}/{model_id}/diff?compare_with={changelog_id}`

Structured diff between two snapshots. Returns `added`, `removed`, `changed`.

#### `PUT /api/v1/admin/{collection}/{model_id}/{changelog_id}/rollback`

Restore a resource to a previous snapshot. A new changelog entry is created for the rollback.

---

## Decision engine deep dive

### Matching types

| Type            | Behaviour                                                   |
| --------------- | ----------------------------------------------------------- |
| `first`         | Stop at first matching rule. `than` value = final decision. |
| `scoring_sum`   | Evaluate all rules. Sum matching `than` values.             |
| `scoring_max`   | Evaluate all rules. Take the highest matching `than` value. |
| `scoring_min`   | Evaluate all rules. Take the lowest matching `than` value.  |
| `scoring_count` | Evaluate all rules. Count how many matched.                 |

Fallback: when no rule matches (or score is zero), the variant's `default_decision` is used.

---

### Condition operators

| Operator         | Description                    | `value` format      |
| ---------------- | ------------------------------ | ------------------- |
| `$is_set`        | Always `true`                  | —                   |
| `$is_null`       | Field value is `null`          | —                   |
| `$any`           | Always `true` including `null` | —                   |
| `$eq`            | Equal to                       | any scalar          |
| `$ne`            | Not equal to                   | any scalar          |
| `$gt`            | Greater than                   | numeric             |
| `$gte`           | Greater than or equal          | numeric             |
| `$lt`            | Less than                      | numeric             |
| `$lte`           | Less than or equal             | numeric             |
| `$between`       | `min ≤ x ≤ max`                | `"300;700"`         |
| `$between_excl`  | `min < x < max`                | `"300;700"`         |
| `$between_lexcl` | `min < x ≤ max`                | `"300;700"`         |
| `$between_rexcl` | `min ≤ x < max`                | `"300;700"`         |
| `$in`            | Value in list                  | `"visa,mastercard"` |
| `$nin`           | Value not in list              | `"visa,mastercard"` |

> **Null values:** Only `$is_null` and `$any` match a `null` field value. All other operators return `false`.

`$in` / `$nin` support quoted tokens for values with commas: `"'visa, mastercard', amex"`.

---

### Presets

A preset transforms a field value *before* rule evaluation. Example: convert any non-null value to a boolean:

```json
{
  "key": "has_income",
  "type": "boolean",
  "preset": { "condition": "$is_set", "value": null }
}
```

Submitting `"has_income": 60000` → preset converts to `true` → conditions evaluate `true`.

Preset results are cached per field per scoring run.

---

### Variant selection

| Strategy  | Behaviour                                                                       |
| --------- | ------------------------------------------------------------------------------- |
| `first`   | Always use the first variant. Deterministic.                                    |
| `random`  | Uniform random pick across all variants.                                        |
| `percent` | Weighted random. Each variant has a `probability` (1–100). All must sum to 100. |

Override per-request: include `variant_id` in the decision request body.

---

## Field types

| Type      | Accepted values     |
| --------- | ------------------- |
| `numeric` | `700`, `3.14`, `-1` |
| `boolean` | `true`, `false`     |
| `string`  | Any text            |

Keys are normalised: lowercased, spaces → underscores. The key `variant_id` is reserved.

---

## Decision types

| Type        | Description                          |
| ----------- | ------------------------------------ |
| `alpha_num` | Alphanumeric string                  |
| `numeric`   | Number (required for scoring tables) |
| `string`    | Any string                           |
| `json`      | JSON-encoded string                  |

---

## Quick-start guide

### 1. Get a token

```bash
curl -X POST https://api.example.com/oauth/token \
  -H "Authorization: Basic $(echo -n 'client_id:client_secret' | base64)" \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"password","username":"admin","password":"admin"}'
```

### 2. Create a decision table

```bash
curl -X POST https://api.example.com/api/v1/admin/tables \
  -H "Authorization: Bearer <token>" \
  -H "X-Application: <app_id>" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Fraud Score",
    "matching_type": "scoring_sum",
    "decision_type": "numeric",
    "fields": [
      {"key":"amount",  "title":"Amount",  "type":"numeric", "source":"request","preset":{}},
      {"key":"country", "title":"Country", "type":"string",  "source":"request","preset":{}},
      {"key":"vpn",     "title":"VPN",     "type":"boolean", "source":"request","preset":{}}
    ],
    "variants": [{
      "title": "v1",
      "default_decision": "0",
      "rules": [
        {"than":"10","title":"High amount",       "conditions":[{"field_key":"amount", "condition":"$gt",  "value":1000}]},
        {"than":"20","title":"High-risk country", "conditions":[{"field_key":"country","condition":"$in",  "value":"XX,YY"}]},
        {"than":"30","title":"VPN detected",      "conditions":[{"field_key":"vpn",    "condition":"$eq",  "value":true}]}
      ]
    }]
  }'
```

### 3. Evaluate

```bash
curl -X POST https://api.example.com/api/v1/tables/<table_id>/decisions \
  -H "Authorization: Basic $(echo -n 'consumer_id:consumer_secret' | base64)" \
  -H "X-Application: <app_id>" \
  -H "Content-Type: application/json" \
  -d '{"amount":1500,"country":"XX","vpn":false}'
```

Result: `final_decision = "30"` (10 + 20 for high amount + high-risk country).

### 4. Attach metadata to a decision

```bash
curl -X PUT https://api.example.com/api/v1/admin/decisions/<decision_id>/meta \
  -H "Authorization: Bearer <token>" \
  -H "X-Application: <app_id>" \
  -H "Content-Type: application/json" \
  -d '{"meta":{"transaction_id":"TXN-99999"}}'
```

[1]:	openapi.yaml
[2]:	#overview
[3]:	#core-concepts
[4]:	#authentication
[5]:	#application-context-header
[6]:	#request--response-format
[7]:	#pagination
[8]:	#error-handling
[9]:	#sandbox-mode
[10]:	#endpoints-reference
[11]:	#health
[12]:	#auth--oauth-20
[13]:	#users
[14]:	#projects
[15]:	#decision-tables
[16]:	#decisions-admin
[17]:	#decisions-consumer
[18]:	#changelog
[19]:	#decision-engine-deep-dive
[20]:	#matching-types
[21]:	#condition-operators
[22]:	#presets
[23]:	#variant-selection
[24]:	#field-types
[25]:	#decision-types
[26]:	#quick-start-guide