# Stage 01 — Create the GitHub repo

> ~5 minutes. Create `ejosterberg/opensalestax-magento` on GitHub
> and push the local scaffold.

## Decision: public or private?

Other OST connectors went public from day one — the constitution
in the orchestrator hub explicitly favors public visibility (the
free, self-hostable narrative requires it). But ask the user
**once** before creating, since it's a destructive-ish operation
(public repos can't easily be made private after they accrue
stars/forks).

Default if the user defers to you: **`--public`**, matching the
other shipped connectors.

## Create the repo

```bash
cd C:/Users/ejosterberg/Documents/GITprojects/opensalestax-magento

# Confirm gh auth before proceeding
gh auth status

# Create the repo. Pick --public or --private per the decision above.
gh repo create ejosterberg/opensalestax-magento \
  --public \
  --description "Magento 2 module for destination-based US sales tax via the self-hosted OpenSalesTax engine" \
  --source=. \
  --remote=origin \
  --push
```

After the push, the scaffold commit (specs + CLAUDE.md +
kickoff/) lands on `main`. Confirm:

```bash
git remote -v                # origin → github.com:ejosterberg/opensalestax-magento.git
gh repo view --web           # opens browser; or use 'gh repo view' for terminal output
```

## Configure repo settings

These can be done via `gh` or the web UI. Done via CLI:

```bash
# Default branch is 'main'; verify
gh repo edit --default-branch main

# Set topics for discoverability
gh repo edit \
  --add-topic magento \
  --add-topic magento2 \
  --add-topic magento-module \
  --add-topic tax \
  --add-topic sales-tax \
  --add-topic opensalestax \
  --add-topic us-tax \
  --add-topic tax-provider
```

## Enable branch protection (optional, v1.0 nice-to-have)

For v1.0-quality polish, require PRs to merge into `main`:

```bash
# Require status checks before merge (CI workflow added in stage 02)
gh api repos/ejosterberg/opensalestax-magento/branches/main/protection \
  --method PUT \
  --field 'required_status_checks[strict]=true' \
  --field 'required_status_checks[contexts][]=ci' \
  --field 'enforce_admins=false' \
  --field 'required_pull_request_reviews=null' \
  --field 'restrictions=null' \
  2>/dev/null || echo "branch-protection skipped (requires GitHub Pro on private repos)"
```

If this fails on a free private repo, skip it — note it in
`success-criteria.md` as deferred.

## Add a SECURITY.md and CONTRIBUTING.md stub now

So GitHub picks them up immediately in the repo's "Community
Standards" view. These get fleshed out in stage 02; just create
minimal stubs:

```bash
cat > SECURITY.md <<'EOF'
# Security Policy

## Reporting a vulnerability

Email **ejosterberg@gmail.com** with subject line starting
`[opensalestax-magento] security:`. Include affected version,
reproduction steps, and impact. Do not open a public GitHub
issue for security reports.

Acknowledgement target: 7 days. Critical issues (tax-correctness
or admin access): mark `[critical]` in subject, expect faster
turnaround.

## Supported versions

Latest minor on `main`. Older releases are not back-patched.
EOF

cat > CONTRIBUTING.md <<'EOF'
# Contributing to opensalestax-magento

## Developer Certificate of Origin (DCO)

Every commit must carry a DCO sign-off:

```bash
git commit -s -m "your message"
```

The `-s` flag appends `Signed-off-by: Name <email>` asserting your
right to contribute under the project license.

## No AI co-author trailers

Do not add `Co-Authored-By:` trailers attributing AI assistants.
Human authors take responsibility for their contributions.

## License

By contributing, you agree your contribution is licensed under
Apache-2.0 (see `LICENSE`).

## Quality gate

Before opening a PR, run `composer check` (added in v0.1.0). PRs
that fail CI cannot merge.
EOF

git add SECURITY.md CONTRIBUTING.md
git commit -s -m "Add SECURITY.md + CONTRIBUTING.md stubs"
git push
```

## Output

When stage 01 is done:

- `ejosterberg/opensalestax-magento` exists on GitHub
- Local `main` is pushed and matches origin
- SECURITY.md + CONTRIBUTING.md stubs landed
- Topics set for discoverability

Mark stage 01 complete in TodoWrite. Proceed to `02-build-alpha.md`.
