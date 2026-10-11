# Gandalf: two-level administration

[Version française](ADMIN.fr.md)

Gandalf has two administration levels:

- the **superadmin**, reserved to Claims IA, creates and deletes the
  applications, names their admins and acts in every application. Each of
  their requests is logged;
- the **application admin**, who can be a client's user, manages the users,
  scopes and API keys of their application, never giving more than they hold.

API keys never reach the superadmin role.

## Table of Contents

- [Roles](#roles)
- [Granting the superadmin role](#granting-the-superadmin-role)
- [What a superadmin does](#what-a-superadmin-does)
- [What an application admin does](#what-an-application-admin-does)
- [Admin log](#admin-log)
- [HTTP API](#http-api)
- [Changes and migration](#changes-and-migration)
- [Code map](#code-map)
- [Tests](#tests)

---

## Roles

| Role | Held by | Can |
| ---- | ------- | --- |
| Superadmin | Claims IA accounts, flag `superadmin: true` on the user | Create, list and delete every application; act as an admin holding every user scope in every application, member or not; read the admin log |
| Application admin | A member with `role: admin` | Everything their scopes allow in their application: users, scopes, API keys, tables, settings. Invite another admin. Never delete the application |
| Member | A member with another role | What their scopes allow |
| API key (consumer) | `client_id` and `client_secret` of an application | Its consumer scopes (`decisions_make`, `decisions_view`) |

A superadmin keeps their own memberships: Claims IA can use its own
applications as an ordinary member. Their role does not appear in the
collaborators of the applications they do not belong to.

---

## Granting the superadmin role

Only the `gandalf:superadmin` Artisan command sets or removes the flag. No API
route writes it: `superadmin` is not a fillable attribute of
[app/Models/User.php](app/Models/User.php), so `PUT /api/v1/users/current`
ignores it.

From the API directory (`php artisan` in the PHP container):

```bash
php artisan gandalf:superadmin someone@claims-ia.fr            # grant
php artisan gandalf:superadmin someone@claims-ia.fr --remove   # revoke
php artisan gandalf:superadmin --list                          # list
```

The account must exist (it signs up as usual). Each grant and revocation is
written to the admin log. A revocation takes effect on the next request.

---

## What a superadmin does

- **Create an application** (`POST /api/v1/projects`): the superadmin does not
  become a member. The application has no member until its admin joins.
- **Name its admins**: in the new application (`X-Application`), invite with
  the admin role (`POST /api/v1/invite`, `role: admin`, every scope), then, for
  an existing account, confirm (`POST /api/v1/projects/collaborators/confirm`).
  The application runs decisions once it has an active admin
  (`admin_not_activated` otherwise, unchanged).
- **List every application** (`GET /api/v1/projects`).
- **Act in any application**: with its `X-Application`, the superadmin is an
  admin holding every user scope. `GET /api/v1/projects/users` answers that
  entry, with `superadmin: true` and `member` (whether they also belong to the
  application). Copy and move between two applications follow the same rule.
- **Delete an application** (`DELETE /api/v1/projects`).
- **Read the admin log** (`GET /api/v1/superadmin/log`).

An application admin cannot delete a superadmin's account through
`DELETE /api/v1/projects/collaborators/account` (403).

---

## What an application admin does

The admin of an application manages it without the superadmin, within their
scopes. The API checks that nobody gives more than they hold:

| Action | Check |
| ------ | ----- |
| Invite a user (`POST /api/v1/invite`) | Invited scopes held by the inviter; only an admin invites an admin |
| Add a user (`POST /api/v1/projects/users`) | Scopes held by the caller; the admin role cannot be given here |
| Change a user's scopes (`PUT /api/v1/projects/users`) | Scopes held by the caller; only an admin edits an admin; nobody edits themselves |
| Name an admin (`POST /api/v1/projects/users/admin`) | Admin only; the new admin gets the caller's scopes |
| Create an API key (`POST /api/v1/projects/consumers`) | Key scopes held by the caller |
| Change an API key (`PUT /api/v1/projects/consumers`) | Key scopes held by the caller |

Keys are managed in gandalf.web. Managing them through the API would need a
management key distinct from the decision keys; there is none.

---

## Admin log

Every API request made by a superadmin is recorded once answered, reads
included, refused and failed requests included, in the `admin_log` collection
([app/Models/AdminLogEntry.php](app/Models/AdminLogEntry.php)):

| Field | Content |
| ----- | ------- |
| `user_id`, `email` | The superadmin; null for a command |
| `action` | `request`, `superadmin.grant` or `superadmin.revoke` |
| `method`, `path` | The request as routed |
| `application_id` | The application of `X-Application`, if any |
| `status` | HTTP status of the response |
| `ip` | Client IP |
| `details` | What the controller noted: `application_id` and `title` of a created application, `title` of a deleted one; for a command, the account concerned |
| `created_at` | Time of the entry, ISO 8601 with its offset |

Request bodies are not kept: they hold the applications' data. Requests of
other users and of API keys are not recorded. Entries are never edited nor
purged by the application.

Recording happens after the response is sent
([SuperadminAuditMiddleware](app/Http/Middleware/SuperadminAuditMiddleware.php)):
a failure to record is written to the application log and does not change the
response.

---

## HTTP API

| Route | Who | Notes |
| ----- | --- | ----- |
| `POST /api/v1/projects` | Superadmin | 201 with the application, `users: []`; 403 `access_denied` otherwise |
| `GET /api/v1/projects` | Any user | Their applications; every application for a superadmin |
| `DELETE /api/v1/projects` | Superadmin | With `X-Application`; 403 otherwise |
| `GET /api/v1/projects/users` | Member or superadmin | The caller's entry; for a superadmin, `role: admin`, every user scope, `superadmin: true`, `member` |
| `PUT /api/v1/projects/consumers` | `consumers_manage` | 422 when a key scope is not held by the caller |
| `GET /api/v1/superadmin/log` | Superadmin | Most recent first; `application_id` (its creation included), `user_id`, `size` (1 to 200, default 50), `page`; `paging` as the other lists |
| `GET /api/v1/users/current` | Any user | `superadmin: true` for a superadmin, absent otherwise |

Superadmin routes need a user token (`Authorization: Bearer`): an API key gets
401 there, a user who is not a superadmin gets 403.

---

## Changes and migration

- **Creating an application** is reserved to superadmins. A user without an
  application can no longer create one: gandalf.web tells them to contact
  Claims IA.
- **Deleting an application** is reserved to superadmins. The user scope
  `project_delete` no longer exists.
- The migration `2026_10_11_100000_TwoLevelAdministration` removes
  `project_delete` from the members and the pending invitations, and indexes
  `admin_log`. Its `down` gives `project_delete` back to the application admins
  and drops the indexes. It names no superadmin.
- The local seed (`database/seeds/DatabaseSeeder.php`) makes `admin@gndf.io` a
  superadmin.

Deployment order:

1. Deploy the API and run `php artisan migrate`.
2. Grant the role to the Claims IA accounts with `php artisan gandalf:superadmin`.
3. Deploy gandalf.web.

Rollback: revert the API, then `php artisan migrate:rollback` for this
migration. The `superadmin` flags and the `admin_log` collection stay and are
ignored.

---

## Code map

| File | Role |
| ---- | ---- |
| [app/Models/User.php](app/Models/User.php) | `isSuperadmin()`, `memberOf()`: the superadmin's admin entry in every application |
| [app/Http/Controllers/ApplicationsController.php](app/Http/Controllers/ApplicationsController.php) | Replaces the package's `POST /projects`, `GET /projects`, `PUT /projects/consumers` |
| [app/Http/Controllers/SuperadminController.php](app/Http/Controllers/SuperadminController.php) | `GET /superadmin/log` |
| [app/Http/Middleware/SuperadminMiddleware.php](app/Http/Middleware/SuperadminMiddleware.php) | Route middleware `superadmin` |
| [app/Http/Middleware/SuperadminAuditMiddleware.php](app/Http/Middleware/SuperadminAuditMiddleware.php) | Global terminable middleware: records the superadmins' requests |
| [app/Services/AdminLog.php](app/Services/AdminLog.php) | Writes the log; `note()` adds a controller's details to the current request |
| [app/Console/Commands/Superadmin.php](app/Console/Commands/Superadmin.php) | `gandalf:superadmin` |
| [app/Http/routes.php](app/Http/routes.php) | The replaced routes, registered after the package routes |
| [config/applicationable.php](config/applicationable.php) | User scopes, ACL of `DELETE /projects` |

---

## Tests

- Unit, without database: [tests/unit/SuperadminTest.php](tests/unit/SuperadminTest.php)
  (membership, flag, routes, middleware, log) and
  [tests/unit/AclCoverageTest.php](tests/unit/AclCoverageTest.php). From the
  API directory:
  `vendor/bin/codecept run unit`.
- API: [tests/api/ProjectsCest.php](tests/api/ProjectsCest.php) (creation and
  deletion reserved to superadmins). The `ApiTester::createProject()` helper
  writes the application directly, its user being the admin, and
  `makeSuperadmin()` sets the flag.
