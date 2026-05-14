# Stage 00 — Pre-flight

> ~10 minutes. Read the canonical specs and verify your toolchain
> works. Skip nothing — every later stage assumes you understand
> the constraints captured here.

## Read these files in order

1. `../specs/constitution.md` — non-negotiable principles. If a
   later decision conflicts with constitution, the constitution
   wins.
2. `../specs/current-state.md` — what's shipped (nothing yet) +
   sibling-project map (other OST connectors).
3. `../specs/handoff.md` — 9-step v0.1.0 alpha task list. The
   anchor for stage 02.
4. `../specs/research/magento-tax-module.md` — Magento 2 tax
   extension surface, DI patterns, admin config patterns. The
   anchor for everything Magento-related.
5. `../CLAUDE.md` — project memory. Architectural anchors +
   file-layout sketch + "what NOT to do" list.

After reading, you should be able to answer:

- Why composer-installable module, not a standalone service?
  (constitution §2 — in-process inside Magento's PHP request
  lifecycle; no webhooks, no separate server)
- Why Apache-2.0 not LGPL/AGPL? (constitution §3 — matches engine
  + Python SDK + Medusa + Saleor; Magento has no OCA-equivalent
  AGPL requirement)
- What happens when a non-USD checkout arrives? (constitution §5
  — return control to Magento's built-in `tax_rate` calc, no OST
  call)
- Where does CSRF protection live? (Magento's form_key on the
  admin config form; constitution §7 — we don't add public
  endpoints)
- Which Magento APIs do we hook? (research §2 — either
  `<preference>` on `Magento\Tax\Api\TaxCalculationInterface` OR
  `<plugin>` on `Magento\Tax\Model\Calculation::getRate`, plus
  always a `<plugin>` on
  `Magento\Quote\Model\Quote\Address\Total\Tax::collect`)

If you can't, re-read.

## Verify toolchain

Run each of these. Note the version. If anything fails, fix it
before continuing (don't paper over).

```bash
php --version                # expect 8.1.x or later
composer --version           # expect 2.x
git --version
gh --version
gh auth status               # expect "Logged in to github.com as ejosterberg"
docker --version
docker compose version
ssh proxmox-workshop 'echo ok'   # expect "ok" (Proxmox SSH)
curl -sS http://10.32.161.126:8080/v1/health | head    # OST engine
curl -sS -u admin:'TktCAD_Sonar_2026!' http://10.32.161.205:9000/api/system/status | head   # SonarQube
```

On Eric's Windows box, the PHP CLI lives at
`/c/xampp/8.2.4/php/php.exe`. If `php --version` doesn't resolve
in the current shell, alias or use the absolute path.

If the OST engine `/v1/health` is unreachable, stop and ask the
user — every later stage depends on it.

If SonarQube is down, you can defer stage 04's SonarQube scan
but should still run the manual review checklist. Note the
deferral in `success-criteria.md`'s tracker.

## Re-verify Magento 2.4.6+ is still the supported floor

Adobe's lifecycle policy at
<https://experienceleague.adobe.com/docs/commerce-operations/release/planning/lifecycle-policy.html>
moves. Confirm `^2.4.6` is still under active support (expected
through 2027). If a newer minor has dropped (`2.4.8`, `2.4.9`)
and `2.4.6` has aged out, update the constitution + composer
constraints before writing code.

## Verify the repo state

You're working in `opensalestax-magento/`. Confirm:

```bash
cd C:/Users/ejosterberg/Documents/GITprojects/opensalestax-magento
git log --oneline -5         # should show the scaffold commit
git status                   # should be clean
git branch                   # one branch (main or master); confirm name
ls specs/                    # constitution.md, current-state.md, handoff.md, research/
ls kickoff/                  # this directory
```

If `git status` shows changes from a prior session, ask the user
before discarding.

## Open a TodoWrite list

Use the `TodoWrite` tool to create your initial task list now.
Suggested shape:

```
[
  {"content": "Stage 00 — Pre-flight (read specs, verify tools)",  "status": "in_progress"},
  {"content": "Stage 01 — Create GitHub repo + push scaffold",     "status": "pending"},
  {"content": "Stage 02 — Build v0.1.0 alpha (9-task handoff)",    "status": "pending"},
  {"content": "Stage 03 — Quality gate (tests, lint, audit)",      "status": "pending"},
  {"content": "Stage 04 — Security review + SonarQube",            "status": "pending"},
  {"content": "Stage 05 — Demo deployment on Proxmox VM",          "status": "pending"},
  {"content": "Stage 06 — Iteration loop until success criteria",  "status": "pending"},
  {"content": "Stage 07 — Release v1.0",                           "status": "pending"}
]
```

Update statuses as you go.

## Output

When stage 00 is done:

- All five docs read; you can summarize each in one sentence.
- Toolchain verified; versions noted in your scratch notes.
- Magento 2.4.6+ lifecycle status re-confirmed.
- Repo state confirmed.
- TodoWrite list initialized.

Proceed to `01-github-repo.md`.
