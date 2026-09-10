# Documentation

- Search existing canonical documentation and indexes before creating a page.
  Update the maintained source instead of adding a competing explanation.
- Change documentation in the same PR as behavior that invalidates it. Cover
  configuration, compatibility, authentication, failure/retry behavior, testing,
  operations, and rollback where the change affects them.
- Link real source paths and commands with their correct working directories.
  Keep navigation, anchors, and related references current after moves.
- Distinguish verified current guidance from draft, uncertain legacy material,
  and historical notes. Do not promote unverified information to canonical rules.
- Record durable decisions and non-obvious invariants. Keep temporary execution
  logs, credentials, absolute local paths, and private operational state outside
  tracked guidance. Follow local rules about tracking identifiers.
- Use the repository's own documentation format and gate. Do not impose a site
  framework, front-matter schema, or application-specific taxonomy everywhere.
- Preserve existing notices and attribution; avoid promotional tool branding.
  Do not use em dashes. Review output follows the local review language policy.
- Explain when no documentation change is needed. Agent context markers, where
  locally adopted, must be syntax-aware and must not corrupt data formats.
