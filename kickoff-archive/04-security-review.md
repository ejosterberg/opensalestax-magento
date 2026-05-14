# Stage 04 — Security review + SonarQube

> ~1 hour. OWASP-style code review of the alpha + SonarQube scan
> + dependency audit. Tax software handles PII and financial
> data — this stage is non-negotiable before any deployment.

## Manual OWASP review checklist

Walk through every checklist item against the v0.1.0 alpha
codebase. For each issue found, file it as a GitHub issue with
label `security` and severity tag (`severity-critical`,
`severity-high`, `severity-medium`, `severity-low`).

### A01: Broken access control

- [ ] Admin config section gated by ACL resource
  `EJOsterberg_OpenSalesTax::config` declared in `etc/acl.xml`
  and referenced from `etc/adminhtml/system.xml`
  `<section resource="...">`
- [ ] No new public-facing routes added (`grep -rn 'routes.xml'
  EJOsterberg/` should find only adminhtml route configs, if
  any)
- [ ] No "debug" / "test" controllers exposed in production
  (`grep -rn 'extends.*Action' EJOsterberg/`)
- [ ] Admin form submissions go through Magento's form_key
  CSRF protection (default behavior of `system.xml`-driven
  config forms — verify it isn't disabled)

### A02: Cryptographic failures

- [ ] API token stored encrypted via
  `Magento\Config\Model\Config\Backend\Encrypted` (declared as
  the `<backend_model>` in `system.xml` for the `api_token`
  field)
- [ ] Token decrypted via
  `Magento\Framework\Encryption\EncryptorInterface::decrypt()`
  — never stored in plaintext, never logged
- [ ] No hardcoded keys / tokens anywhere
  (`grep -rn 'eyJ\|sk_\|pk_\|Bearer ' EJOsterberg/`)
- [ ] TLS verification not disabled on outbound calls to OST
  engine (no `CURLOPT_SSL_VERIFYPEER => false` in the Curl
  client setup)

### A03: Injection

- [ ] OST API requests use parameterized JSON bodies via
  `Magento\Framework\Serialize\Serializer\Json::serialize()`;
  no string concatenation of user input into URLs or payloads
- [ ] No raw SQL anywhere — Magento ORM
  (`getConnection()->select()` / `->prepare()`) or repository
  pattern only
  (`grep -rn '->query(\|->rawQuery(' EJOsterberg/` returns 0)
- [ ] Log statements use structured args (Monolog context
  array), not string interpolation with user-controlled fields
- [ ] No `eval`, `create_function`, or `exec` with user input

### A04: Insecure design

- [ ] Fail-soft default behavior documented and tested:
  engine 5xx → return original Magento tax rate + warn log
- [ ] Fail-hard mode (`osstax/general/fail_hard=1`) returns
  exception that Magento's checkout surfaces, blocking the
  flow (not a 500 page)
- [ ] Non-USD currency / non-US country short-circuits to
  Magento's built-in calc without calling OST engine
  (constitution §5)
- [ ] No silent failures: every catch logs context

### A05: Security misconfiguration

- [ ] Dockerfile not in scope — module ships into merchant's
  Magento. (No Docker artifacts to harden.)
- [ ] Admin form fields validate input — URL field uses
  `validate-url` frontend class + saves through a backend
  model that re-validates (`Magento\Config\Model\Config\Backend\Url`
  if available, otherwise custom validator)
- [ ] No `display_errors=On` shipped — Magento's production
  mode handles this, just verify no `error_reporting(E_ALL)`
  injected
- [ ] Exception messages on the customer-facing path don't
  leak engine URL / token (catch + rethrow with a sanitized
  message)
- [ ] CORS not added — admin endpoints are same-origin only

### A06: Vulnerable & outdated components

- [ ] `composer audit` passes (from stage 03)
- [ ] `composer outdated --direct` reviewed; no major-version
  stragglers on security-critical deps
- [ ] PHP version constraint `^8.1` covers all currently-
  supported PHP minors (8.1, 8.2, 8.3 as of 2026)
- [ ] Magento framework constraint matches the constitution
  (`^103.0` for Magento 2.4.6+)

### A07: Identification & authentication failures

- [ ] No password-based auth added by the module (admin
  inherits Magento's session auth)
- [ ] Admin session timeout governed by Magento's
  `admin/security/session_lifetime` — module doesn't
  override it
- [ ] N/A with reason: module surface is admin-internal + the
  customer-facing tax calc path runs inside Magento's existing
  request lifecycle; no auth surface owned by this module

### A08: Software & data integrity failures

- [ ] `composer.lock` committed
- [ ] CI runs `composer install --no-dev` for production-shape
  audit; dev shape for tests
- [ ] No `post-install-cmd` / `post-update-cmd` scripts running
  arbitrary commands in `composer.json`
- [ ] If Marketplace signing is required (v1.1), document the
  signing process in a separate ADR

### A09: Security logging & monitoring failures

- [ ] All tax-calc paths log
  `['quote_id' => $id, 'line_count' => $n, 'api_status' => $s,
  'rtt_ms' => $rtt]` — but NOT customer addresses, line item
  descriptions, or full payloads
- [ ] Auth failures (admin config save with invalid token)
  logged at WARN level with reason
- [ ] OST engine errors logged at ERROR with HTTP status +
  rtt_ms
- [ ] Logs route through `Psr\Log\LoggerInterface` (Magento's
  injected logger), not direct file writes — admin can route
  per Magento's logger config

### A10: Server-side request forgery

- [ ] `osstax/general/api_url` validated at save time:
  must parse as URL, scheme must be `http://` or `https://`,
  host must not resolve to private IP ranges (RFC1918) when
  set to a non-localhost URL — OR document why localhost is
  permitted (for merchants self-hosting on the same VM)
- [ ] No request-time interpolation of merchant-controlled
  input into the engine URL
- [ ] Outbound Curl timeout set (`setOption(CURLOPT_TIMEOUT,
  10)`) so a malicious/slow URL can't stall a checkout
  indefinitely

## SonarQube scan

Per `~/.claude/sonarqube-playbook.md`:

```bash
# 1. Generate a scan token
TOKEN=$(curl -s -u "admin:TktCAD_Sonar_2026!" \
  -X POST "http://10.32.161.205:9000/api/user_tokens/generate" \
  -d "name=magento-scan-$(date +%s)&type=GLOBAL_ANALYSIS_TOKEN" \
  | jq -r .token)

# 2. Create the project (one-time)
curl -s -u "admin:TktCAD_Sonar_2026!" \
  -X POST "http://10.32.161.205:9000/api/projects/create" \
  -d "project=opensalestax-magento" \
  -d "name=opensalestax-magento"
```

Create `sonar-project.properties` at repo root:

```properties
sonar.projectKey=opensalestax-magento
sonar.projectName=opensalestax-magento
sonar.projectVersion=0.1.0
sonar.sources=EJOsterberg
sonar.tests=Test
sonar.sourceEncoding=UTF-8
sonar.exclusions=**/vendor/**,**/build/**,**/coverage*/**
sonar.test.inclusions=Test/**/*Test.php
sonar.php.coverage.reportPaths=coverage.xml
sonar.host.url=http://10.32.161.205:9000
```

Run the scan (coverage XML is generated by `phpunit
--coverage-clover` in stage 03):

```bash
"/c/Users/ejosterberg/Documents/GITprojects/TicketsCADFixes/sonar-scanner-temp/sonar-scanner-6.2.1.4610-windows-x64/bin/sonar-scanner.bat" \
  -Dsonar.token=$TOKEN
```

Wait 2-5 minutes for processing. Pull results:

```bash
curl -s -u "admin:TktCAD_Sonar_2026!" \
  "http://10.32.161.205:9000/api/measures/component?component=opensalestax-magento&metricKeys=bugs,vulnerabilities,code_smells,security_hotspots,reliability_rating,security_rating,sqale_rating,ncloc"
```

### PHP-specific rules likely to surface

From the SonarQube playbook (rules section), expect to see:

| Rule | Description | Likely action |
|---|---|---|
| `php:S2014` | `$this` outside class context (legacy PHP4 ctor) | Won't apply to new code — N/A |
| `php:S2115` | DB connection without password | N/A — Magento manages DB |
| `php:S6437` | Hardcoded password | Real finding if it surfaces — fix |
| `php:S6418` | Hardcoded secret | Often false positive when reading config — review case-by-case |
| `php:S1599` | Avoid variable variables | Real finding — fix |
| `php:S121` | Control structure body on same line | Style — fix in batches |

## SonarQube acceptance bar

For v1.0 release the SonarQube dashboard must show:

- **0 BLOCKER issues**
- **0 CRITICAL issues**
- **Security rating: A** (1.0)
- **Reliability rating: A or B**
- **0 unreviewed security hotspots** (every hotspot reviewed
  and marked Safe or Fixed)

For v0.1.0 alpha (just past stage 04), the bar relaxes to:

- 0 BLOCKER
- ≤3 CRITICAL with documented mitigation plans in
  `specs/security/audit-YYYY-MM-DD.md`
- Security rating A or B

If alpha can't hit the v1.0 bar, the iteration loop (stage 06)
exists to drive it there. Don't gate alpha on v1.0 perfection.

## Write the audit record

After the scan, create `specs/security/audit-2026-05-13.md` (or
current date) capturing:

- Scan timestamp and SonarQube project URL
- Issue counts by severity
- The list of BLOCKER + CRITICAL findings, with for each:
  - Rule ID and category
  - File:line
  - Disposition (Fixed in commit `<sha>` / Deferred to v1.1
    with rationale / False positive — marked Won't Fix in
    SonarQube with reason)
- Manual review checklist items not green, with same
  disposition tags
- `composer audit` output
- Date and reviewer (Claude session ID + stage 04)

This file is appended-only across audits — never edit a prior
audit; create a new dated file for each scan.

## Acceptance for stage 04

Stage 04 is done when:

- [ ] All 10 OWASP checklist sections walked; findings filed
  as issues
- [ ] SonarQube scan completed; results meet the alpha bar
  (BLOCKER=0; CRITICAL≤3 documented)
- [ ] `specs/security/audit-YYYY-MM-DD.md` committed
- [ ] All security hotspots reviewed
- [ ] Any new advisories from `composer audit` triaged

Mark stage 04 complete in TodoWrite. Proceed to
`05-demo-deployment.md`.
