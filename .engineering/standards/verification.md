# Verification

- Derive commands, working directories, runtime versions, required fixtures,
  and services from this repository's sources and CI. Record their prerequisites.
- Run targeted checks while iterating and the required applicable gate before
  handoff. Add focused regressions for behavior changes; do not invent an
  application test suite for documentation, SQL, or configuration repositories.
- Record each check as `passed`, `failed`, `unavailable`, or `not-applicable`.
  Include the command, relevant source head/state, and what the result proves.
  Unexecuted checks are unavailable or not applicable with a reason, never passed.
- Distinguish static validation, build/package success, service reachability,
  application behavior, and live operational proof. CI comments are not evidence
  that a test ran; inspect the actual jobs and results.
- Keep validation within the task's authority. Installing dependencies, starting
  services, provider calls, destructive fixtures, migrations, and deployment can
  require additional scope. Prefer approved isolated and synthetic fixtures.
- Reuse results only while relevant code, environment, and prerequisites remain
  valid. After substantive fixes, rerun affected checks and required full gates.
- Inspect the full proposed diff and whitespace checks. For publication verify
  the hosted head and file list match the validated change; inspect actual CI.
- State failures and unavailable infrastructure honestly. Do not label the work
  fully verified when a required gate or current-head review remains unavailable.
