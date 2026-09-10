# Review

- Read the local root review guide and relevant nested instructions. Review the
  actual current diff, callers, contracts, tests, history, and CI evidence.
- Write findings, summaries, and suggestions in French unless explicit local
  policy requires otherwise. Use `la claim`, `une claim`, `chiffrage`, and
  `données de chiffrage` when discussing those concepts. Do not use em dashes.
- Prioritize concrete correctness, compatibility, security/privacy, data
  integrity, architecture, and operational risks. Identify the triggering case,
  consequence, supporting path/line or test, and a focused correction.
- Inspect missing versus falsey values, access boundaries, configuration
  fallbacks, side-effect order, retries, data retention, and packaged inputs
  when relevant. Existing internal-network assumptions are not security proof.
- Prefer shared mechanisms and existing extension points, without prescribing
  another repository's architecture or demanding unrelated consolidation.
- Evaluate suggestions against authoritative contracts. A merged conversation
  or reviewer preference alone does not establish a general requirement.
- Do not request agent breadcrumbs from human contributors. If authorship is
  unknown, do not flag their absence. Leave mechanical formatting to checks.
- Preserve contributor attribution and team-required disclosure. Do not add
  promotional tool branding where local policy prohibits it.
- Separate actionable findings from uncertainty. Explain unavailable evidence
  and avoid generic nits or speculative blockers without a plausible trigger.
- Review completion applies to an identified head. Acknowledgement, silence,
  stale reactions, and historical approval do not clear a new substantive head.
