# Stage 06 — Iteration loop until success criteria met

> Variable. 30 minutes if alpha was clean; days if the security
> review found systemic issues. This is the stage that takes
> v0.1.0 → v1.0.0.

## The loop

This stage is intentionally repetitive. The job is to drive the
backlog of issues from stages 03/04/05 down to zero (or to
"deferred-to-v1.1 with documented rationale") while keeping the
quality gate green.

```
┌──────────────────────────────────────────────┐
│ 1. Pick the highest-severity unresolved      │
│    issue from the backlog                    │
│ 2. Open a branch: fix/<short-name>           │
│ 3. Write a test that reproduces the bug      │
│    (or expresses the missing feature)        │
│ 4. Fix the code; test passes                 │
│ 5. Run `composer check` — must pass          │
│ 6. Push + open PR                            │
│ 7. Wait for CI green                         │
│ 8. Merge (squash, with DCO sign-off          │
│    preserved) into main                      │
│ 9. Re-run any stage that the change          │
│    invalidates (security scan if security    │
│    code changed; demo if tax calc            │
│    code changed)                             │
│ 10. Update success-criteria.md tracker       │
│ 11. Pick next issue → goto 1                 │
└──────────────────────────────────────────────┘
```

When the backlog empties (or every remaining item is marked
deferred), exit the loop to stage 07.

## Backlog sources (in priority order)

1. **Critical / blocker issues from stage 04** (security)
2. **Issues from stage 03** that were "deferred to fix in
   iteration" (e.g., coverage gaps)
3. **Bugs found during the stage 05 demo** that affected the
   happy path
4. **SonarQube CRITICAL issues** filed during stage 04
5. **TODO / FIXME comments** the build left in the code
6. **Documentation gaps**: README too thin, missing migration
   notes, no admin-screenshot walkthrough
7. **Polish items**: better error messages, structured logs
   improvements, admin field help text

## Pacing the loop

Use `ScheduleWakeup` to wait for CI runs without burning
context. Typical cycle:

- Push commit
- `ScheduleWakeup(delaySeconds=270, reason="checking CI on
  fix/<name>", prompt="<same /loop prompt>")`
- On wake: `gh run list --limit 1` → if green, merge; if red,
  fix and repeat

Don't poll faster than every 60s (CI takes 2-4 min and faster
polling just burns cache). Don't sleep longer than 270s in
this loop — your prompt cache expires and the next iteration
pays for a full cold read.

## Exit conditions

Exit the loop and move to stage 07 when **all** of these are
true:

- [ ] `success-criteria.md` tracker shows every item
  resolved or explicitly deferred-to-v1.1
- [ ] `composer check` green on `main` HEAD
- [ ] CI green on `main` HEAD
- [ ] SonarQube dashboard shows 0 BLOCKER, 0 CRITICAL, A
  security rating
- [ ] The stage 05 demo still works (re-run the $100 MN
  checkout)
- [ ] README walks a new merchant from zero to live checkout
  in ≤10 minutes (time someone unfamiliar; or do a paper
  walkthrough)
- [ ] CHANGELOG.md documents every release from v0.1.0 to the
  next tag (will be v1.0.0)

If you reach a point where forward progress requires Eric's
input (architectural decision not pre-locked, conflict between
constitution and the work needed, etc.), pause the loop and ask
him directly. Don't spin wheels.

## When to ask the user

Per START-HERE.md, pause the loop and confirm with Eric only
when:

- A destructive operation needs explicit consent (e.g.,
  force-push to main; deleting a VM; rewriting git history)
- A key architectural decision needs to be made and isn't
  pre-locked in `specs/constitution.md` (the preference-vs-
  plugin ADR is pre-locked as task 4 of stage 02 — don't ask
  Eric for that)
- The loop has terminated with unresolved blocker(s) that
  require human judgment (e.g., a SonarQube finding flagged
  as security-critical that lacks an obvious fix)

Routine fixes, refactors, doc updates, and version bumps do
NOT need user permission — proceed and report in the final
summary.

## Tracking progress

Update `kickoff/success-criteria.md` after every meaningful
fix:

- Move items from "Open" → "Resolved (commit `<sha>`)"
- Add new items to "Open" as they surface
- Move things to "Deferred to v1.1" with a written reason
  (and create a tracking issue in GitHub)

Commit the updated tracker alongside the fix it documents
where reasonable, or as a `docs: update success-criteria
tracker` housekeeping commit at end of each work block.

## What "near-perfect" means here

Eric's word for the target state is "near-perfect." Operational
definition:

- Anyone can `composer require ejosterberg/module-opensalestax`
  into a clean Magento 2.4.6+ install, run
  `bin/magento module:enable EJOsterberg_OpenSalesTax &&
  bin/magento setup:upgrade`, configure the engine URL in
  admin, and start collecting US sales tax correctly within
  10 minutes
- Zero known security vulnerabilities (BLOCKER or CRITICAL)
- The code passes a stranger's code review without needing
  explanation comments per file
- The README + CHANGELOG are sufficient for a merchant to
  decide if this fits their needs without reading source

Don't chase 100% test coverage or zero MINOR SonarQube issues
— those are diminishing returns. The bar is "any reasonable
small-merchant could adopt this today and not regret it in
6 months."

## Acceptance for stage 06

Stage 06 is done when:

- [ ] Every exit condition above is checked
- [ ] `kickoff/success-criteria.md` shows zero "Open" items
- [ ] You're confident enough to tag v1.0.0

Mark stage 06 complete in TodoWrite. Proceed to `07-release.md`.
