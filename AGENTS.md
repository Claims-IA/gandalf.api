<!-- shared-guidance:begin -->
## Shared engineering rules

Before relevant work, read the locally committed rules below and
[REVIEW.md](REVIEW.md) before reviewing. These links are required reading,
not automatically discovered instruction files. Preserve stricter local rules.

- Read [development](.engineering/standards/development.md).
- Read [documentation](.engineering/standards/documentation.md).
- Read [git](.engineering/standards/git.md).
- Read [review](.engineering/standards/review.md).
- Read [verification](.engineering/standards/verification.md).

Read applicable nested instructions before changing their areas.
See [.engineering/NOTICE.md](.engineering/NOTICE.md) for attribution.
<!-- shared-guidance:end -->

# Project contributor guide

## Purpose

Maintained fork of a Lumen decision-table and flow API backed by MongoDB. Preserve upstream license attribution and compatibility of existing table, decision, import/export and flow contracts.

## Map

- [README.md](README.md), [API_GUIDE.md](API_GUIDE.md), [API_GUIDE.fr.md](API_GUIDE.fr.md), [openapi.yaml](openapi.yaml): public API documentation.
- [app/Http/routes.php](app/Http/routes.php), `app/Http/Controllers/` and `app/Repositories/`: request and persistence boundaries.
- [app/Services/FlowEngine.php](app/Services/FlowEngine.php) and `app/Services/Excel/`: flow execution and spreadsheet codecs.
- [tests/unit.suite.yml](tests/unit.suite.yml), [tests/api.suite.yml](tests/api.suite.yml), [codeception.yml](codeception.yml): distinct test surfaces.
- [Dockerfile](Dockerfile) and [docker-compose.yml](docker-compose.yml): current runtime, volumes and published ports.

## Protected state and authority

Preserve upstream LICENSE, existing developer work, database volumes, imports and dumps. `.history/`, `_ide_helper.php`, generated test support and dependency output are not canonical sources for new guidance. Never run database seeding, migration, Compose teardown with volume deletion, release/push scripts or provider integrations to validate documentation. Compose has fixed container names and ports: a Git worktree does not isolate its runtime. Use synthetic tables and an explicitly disposable runtime for authorized integration testing.

## Contribution base and metadata

The verified contribution base is `master`. Without a real ticket, use `feature/<work-description>` and descriptive commit/PR subjects; never invent tracking identifiers. Use normal follow-up commits, preserve genuine attribution and the organization template's conditional disclosure footer. Review in French, use la claim and chiffrage when relevant, and do not use em dashes. These shared rules are proposed pending review of their source revision.

## Verification

Composer requires PHP ^8.1 with platform 8.1.0; Docker uses PHP 8.2, while Lumen remains 5.2. Read composer.lock and existing compatibility patches before dependency work. The documented pure unit command is `vendor/bin/codecept run unit`; API tests use `vendor/bin/codecept run api` only with a deliberately configured disposable HTTP/database environment. Do not treat legacy defaults in codeception.yml as safe local targets. Current GitHub Actions builds Compose and accepts any HTTP response other than a connection failure: it demonstrates reachability, not functional correctness or successful API responses. The Docker image excludes dev dependencies. No documentation test framework is declared; guidance validation uses local links/paths, manifest fingerprints and diff checks. Application commands here are statically verified, not claimed run by the guidance PR.

## Canonical documentation

Keep [DOCUMENTATION.md](DOCUMENTATION.md), [DOCUMENTATION.fr.md](DOCUMENTATION.fr.md), [GUIDE.fr.md](GUIDE.fr.md) and API documentation aligned with relevant behavior. Choose the existing canonical page for the affected API or flow, preserving bilingual meaning. Validate rules against current code and tests instead of copying historical `.history/` pages or legacy Travis assumptions. Table variants share columns: each rule keeps one condition per field, in field order. Preserve neutral conditions for missing columns and round-trip serialization for values resembling operators. Consult the existing table and codec tests before changing this invariant.

## Nested guidance

No tracked nested AGENTS.md or AGENTS.override.md was found at adoption. Read this root guide, the locally committed shared rules and REVIEW.md explicitly before relevant work, including work started in a subdirectory. Do not rely on sibling checkouts or network access. Recheck applicable overrides when beginning a task; preserve personal overrides and report conflicts.

## Code Review Rules

Read the root REVIEW.md and relevant nested guidance before reviewing.

Rédiger la revue en français. Examiner l'autorisation et l'isolation des projets, les tokens, la persistance MongoDB, les imports/exports et les erreurs partielles. Préserver les valeurs zéro, false, null et cellule vide, l'ordre des règles et les variantes de table. Vérifier les limites de fichiers et l'échappement des cellules. Une réponse HTTP ne prouve pas le fonctionnement des contrats. Les dérogations d'audit héritées ne constituent pas une preuve de sécurité.
