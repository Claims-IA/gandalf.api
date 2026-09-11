# Development

- Establish the task's outcome, authority, path scope, and acceptance criteria.
  Investigation and read-only work do not authorize edits or publication.
- Before editing, read applicable root/nested instructions, relevant code,
  analogous implementations, contracts, tests, history, and CI. Verify facts
  from current sources; do not invent commands, contracts, or ticket identifiers.
- Prefer existing shared mechanisms and extension points over duplicate flows.
  Use the repository's actual configuration mechanisms for tunable behavior;
  do not import another application's persistence or settings architecture.
- Make focused changes. Preserve public compatibility, local safeguards, legal
  notices, data boundaries, and non-obvious ordering and failure invariants.
- Test meaningful boundary cases: absent/null/zero/false values, disabled paths,
  retries, idempotency, and failures before and after external side effects.
  Queue acceptance does not prove delivery or successful processing.
- Use synthetic inputs for provider adapters and privacy/retention tests.
  Do not log credentials, personal data, raw provider payloads, or secrets.
- Document non-obvious discoveries locally and update canonical documentation
  when behavior changes. Avoid unrelated cleanup or mechanical comment insertion.
- Where the repository adopts an agent context-marker convention, agents follow
  it only in editable files with valid comment syntax. Preserve required leading
  declarations. Exclude generated/vendor files, binaries, and commentless formats.
  This is not a requirement on human or unknown authors.
- Complete the applicable verification and provide an evidence-based handoff.
  Implementation authority does not automatically authorize merge or deployment.
