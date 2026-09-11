# Safe Git and delivery

- Verify Git root, authenticated remote identity, actual contribution branch,
  current head, working/index/untracked state, worktrees, stashes, operations,
  and relevant existing PR before changing repository state.
- Preserve unexplained work. Never reset, clean, stash, rebase, repoint, or
  force-push another task's state. Use an authorized isolated checkout if needed.
- For new work fetch the authorized remote without pruning user refs, then
  branch from the verified contribution base. Resume an authorized existing PR
  at its current head with normal follow-up commits; no automatic rewriting.
- Follow the repository's verified ticket convention when a real ticket exists.
  Without a ticket use `feature/<work-description>` and descriptive commit/PR
  subjects. Never invent a ticket. Where adopted, keep tracking identifiers in
  Git metadata and out of source, comments, documentation, and changelogs.
- Preserve genuine authorship, license attribution, and required AI disclosure.
  Promotional tool branding is separate from required disclosure; do not silently
  remove a team footer to satisfy a contradictory branding rule.
- Publish only within task authority. Push a topic branch normally, open a PR,
  verify hosted head/diff/checks, and use the existing contribution template.
- Inspect configured review before requesting another cycle. Acknowledgement
  does not mean approval. Tie completed review to the current substantive head.
- Evaluate feedback with evidence. Fix valid in-scope issues, validate, commit,
  push, and reply with evidence before resolving. Explain incorrect findings;
  leave disputed safety questions and out-of-scope blockers explicit.
- Obtain fresh completed review after substantive fixes. Use bounded waits and
  an exact-head handoff if checks or review remain unavailable.
- Merge, deployment, settings changes, external messages, credential validation,
  and destructive recovery need their own authority. Never bypass protections
  or treat these standards as a standing merge or infrastructure grant.
