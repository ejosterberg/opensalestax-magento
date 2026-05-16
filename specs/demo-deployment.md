# Demo deployment — magento-demo VM

**Status (2026-05-16):** ✅ **All D1-D6 closed.** Magento 2.4.7-p3 + OST module v1.3.5 running on VM 914. End-to-end `collectTotals()` on a real persisted MN Quote returns `$ship->getTaxAmount() = $9.4763` ($100 product + $5 shipping, 8.5% combined MN rate over 6 jurisdictions). Reproducible across two back-to-back runs. The six-bug chain (A→B→C→D→E→F) is closed in v1.3.5 — see "Bug history" below for the full post-mortem.

## Infrastructure

| Resource | Value |
|---|---|
| Proxmox host | `pmvm1` (`10.32.161.114`) |
| VMID | **914** (903 — the kickoff default — was claimed by `geoserver-01`; 908 was claimed by `bloomington-auxcomm`. 914 was the next free in the 900-999 reserved range.) |
| VM name | `magento-demo` |
| IP | **10.32.161.183** |
| SSH alias | `magento-demo` (added to Eric's `~/.ssh/config`) |
| User | `ejosterberg` (the project default) |
| OS | Debian 13 (trixie), cloud image |
| Spec | 4 vCPU, 8 GB RAM, 80 GB disk |
| Docker | 29.4.3, installed and group-added for `ejosterberg` |

Verify reachability:

```bash
ssh magento-demo 'docker --version && uname -a'
```

## The credential gap

`markshust/docker-magento` (the de-facto community devbox specified in stage 05) bootstraps Magento by running `composer create-project magento/project-community-edition`, which pulls from `https://repo.magento.com/`. That repo requires Adobe Commerce Marketplace credentials — a public key + private key pair generated at <https://marketplace.magento.com/customer/accessKeys/>.

Eric does not currently have an `~/.composer/auth.json` with these keys. Until those are provided, the Magento install step cannot proceed unattended.

## Options to unblock

1. **Generate Marketplace credentials** (the canonical path). Eric creates a free Marketplace account, generates an access-key pair, and saves them to `~/.composer/auth.json` on Eric's box AND on `magento-demo`:

   ```json
   {
     "http-basic": {
       "repo.magento.com": {
         "username": "PUBLIC_KEY",
         "password": "PRIVATE_KEY"
       }
     }
   }
   ```

   Then re-run the markshust bootstrap from the kickoff. Note the **`community 2.4.7-p3`** invocation — passing just `magento.test 2.4.7` (per the original kickoff doc) tries to install `magento/project-2.4.7-edition` which doesn't exist; `community` is the required edition argument:

   ```bash
   ssh magento-demo bash <<'MAGENTO'
   sudo apt-get update && sudo apt-get install -y bc       # markshust bin/start needs bc
   mkdir -p ~/magento && cd ~/magento
   curl -s https://raw.githubusercontent.com/markshust/docker-magento/master/lib/onelinesetup | \
     bash -s -- magento.test community 2.4.7-p3
   # markshust ships compose.yaml pinned to mariadb:11.4 — Magento 2.4.7-p3
   # only supports MariaDB 10.2-10.6, so setup:install will abort. Patch
   # before bootstrap continues:
   sed -i 's|mariadb:11.4|mariadb:10.6|' ~/magento/compose.yaml
   bin/start && bin/setup magento.test
   MAGENTO
   ```

2. **Switch to Mage-OS** (the community fork). Mage-OS publishes a Marketplace-credential-free Magento distribution at `https://repo.mage-os.org/`. Their devbox lives at <https://github.com/mage-os/devbox> and follows the same shape as markshust. Trade-off: Mage-OS lags Adobe's releases by a few weeks and a small minority of merchants use it in production.

3. **Defer stage 05 entirely** and ship v1.0 on the strength of the unit tests + SonarQube clean + manual code review. Document the deferral in `specs/current-state.md` and mark stage 05 success-criteria rows as "Deferred to v1.1."

The constitution does not require a passing demo deployment to ship v1.0, but the success-criteria tracker has it as D1-D6. Whichever option Eric picks, update the tracker.

## OST engine for the demo

Once Magento is up, the engine side is straightforward. Either:

- **Re-use the shared engine** at `http://10.32.161.126:8080`. Saves disk + memory on `magento-demo`. Already running, `v0.55.4` confirmed.
- **Stand up a private engine** on `magento-demo` via docker-compose:

  ```yaml
  services:
    ost-engine:
      image: ghcr.io/ejosterberg/opensalestax:latest
      ports: ["8080:8080"]
      environment:
        - DATABASE_URL=postgres://ost:ost@ost-db:5432/ost
      depends_on: [ost-db]
    ost-db:
      image: postgres:16-alpine
      environment:
        - POSTGRES_USER=ost
        - POSTGRES_PASSWORD=ost
        - POSTGRES_DB=ost
      volumes: [ost-db:/var/lib/postgresql/data]
  volumes:
    ost-db:
  ```

For the demo, point the module at the shared engine (the simpler path).

## Module install (after Magento is up)

```bash
ssh magento-demo bash <<'MODULE'
cd ~
git clone https://github.com/ejosterberg/opensalestax-magento.git
cd ~/magento/src
docker compose exec -u app app composer config \
  repositories.osstax path /opensalestax-magento
docker compose exec -u app app composer require \
  ejosterberg/module-opensalestax:@dev
docker compose exec -u app app bin/magento module:enable EJOsterberg_OpenSalesTax
docker compose exec -u app app bin/magento setup:upgrade
docker compose exec -u app app bin/magento setup:di:compile
docker compose exec -u app app bin/magento cache:clean
MODULE
```

## Configure the module via CLI (no admin browser needed)

```bash
ssh magento-demo bash <<'CONFIG'
cd ~/magento/src
docker compose exec -u app app bin/magento config:set osstax/general/api_url http://10.32.161.126:8080
docker compose exec -u app app bin/magento config:set osstax/general/fail_hard 0
docker compose exec -u app app bin/magento cache:clean
CONFIG
```

## Verify

1. Module enabled:
   ```bash
   ssh magento-demo 'cd ~/magento/src && docker compose exec -u app app bin/magento module:status EJOsterberg_OpenSalesTax'
   # Expected: "Module is enabled" (the older `module:status | grep`
   # shape stopped reliably labeling enabled modules in 2.4.7)
   ```
2. DI compile succeeded: no errors in the previous block's `setup:di:compile` output.
3. Engine reachable from the Magento container's perspective:
   ```bash
   ssh magento-demo 'cd ~/magento/src && docker compose exec app curl -sS http://10.32.161.126:8080/v1/health'
   ```

## Live checkout test (Eric — manual)

The end-to-end checkout requires a browser. Eric runs this manually:

1. Add `10.32.161.183 magento.test` to `/etc/hosts` on the workstation.
2. Open `https://magento.test/admin`, log in with the admin credentials printed by the `markshust` bootstrap.
3. Catalog → Products → Add Product: "Demo Product", price $100, tax class "Taxable Goods".
4. Storefront → add to cart → checkout. Shipping address: `100 N 6th St, Minneapolis, MN 55403, US`. Shipping method: Flat Rate $10.
5. Expected totals: subtotal $100, shipping $10, **tax ~$7-8** (Minneapolis combined rate is ~8.025% on goods), order total ~$117-$118.
6. Check `var/log/system.log` for the structured INFO line: `{"quote_id": N, "line_count": 1, "api_status": 200, "rtt_ms": M}`.
7. Switch storefront currency to EUR, repeat. Expected: tax computed by Magento's built-in tables (no `opensalestax` log lines).

## Stage 05 status against success criteria

| ID | Criterion | Status (2026-05-16 after v1.3.5 verify PASS) |
|---|---|---|
| D1 | Demo Proxmox VM provisioned | ✓ (VM 914, IP 10.32.161.183) |
| D2 | Magento 2.4.7-p3 devbox running | ✓ markshust devbox; admin `https://magento.test/admin` (creds `john.smith` / `password123`); 7 healthy containers |
| D3 | OST engine running | ✓ Re-using shared engine at `http://10.32.161.126:8080` (v0.58.0) |
| D4 | Module installed via Composer path repo | ✓ v1.3.5 in container at `/opensalestax-magento`; DI compiled clean |
| D5 | Module enabled and configured via admin | ✓ `bin/magento config:set` works (Bug A fix); core_config_data has clean rows from CLI writes |
| D6 | Real MN Quote returns plausible tax | ✓ **`$ship->getTaxAmount() = $9.4763`** on $100 product + $5 shipping to MN/55403. 6 jurisdictions in `getAppliedTaxes`. Grand total balances exactly ($100 + $5 + $9.4763 = $114.4763). Engine call logged. Reproducible across two back-to-back runs. **Six-bug chain closed; all D2-D6 ✓.** |

## Bug history surfaced by the live bootstrap

The 2026-05-15 bootstrap + re-verify cycles surfaced **four** P0 bugs in v1.3.0 — each masked by the next-deeper one:

- **Bug A (latent since v1.1.0)** — backend-model ctors used a `(custom-dep, ...$parentArgs)` variadic pattern. Magento Interceptors forward parent ctor args by position, so position 1 landed on our custom dep instead of `Context`. `bin/magento config:set` and admin save crashed with TypeError. **Fixed in v1.3.1.**
- **Bug B** — `Plugin\QuoteTotalsTaxPlugin::buildPayload()` emitted the legacy `{quote_id, destination, lines, shipping_amount}` shape; engine v0.58 only accepts the SDK-canonical `{address: {zip5}, line_items[]}` shape. Live MN cart silently returned $0 tax under fail-soft default. **Fixed in v1.3.1.**
- **Bug C (latent since v0.1.0)** — `etc/di.xml` registered the totals plugin against `Magento\Quote\Model\Quote\Address\Total\Tax` (which doesn't exist in Magento 2.4.x). Real target is `Magento\Tax\Model\Sales\Total\Quote\Tax`. Magento's DI compiler silently no-ops plugins on non-existent targets — so even with v1.3.1 in place, every cart returned `tax_amount: 0` because the totals plugin never fired. **Fixed in v1.3.2** + `Test\Unit\Etc\DiXmlTargetClassTest`.
- **Bug D (latent since v0.1.0)** — `QuoteTotalsTaxPlugin::beforeCollect` declared `($subject, $shippingAssignment, $total)` (1+2 args). Magento Interceptors use the plugin signature to decide what to forward to the parent; the target `Tax::collect(Quote, ShippingAssignment, Total)` takes 3 args. Once Bug C unmasked the plugin in v1.3.2, every checkout crashed with `ArgumentCountError`. **Fixed in v1.3.3 (commit `d9b43ad`, tag `v1.3.3`)** + new `Test\Unit\Etc\PluginAritySignatureTest` (reflection-based generic regression coverage for the entire class of arity-mismatch bugs).

All four were invisible to unit-test CI because they manifest only under live conditions:
- **A** — live `setup:di:compile` against real Magento
- **B** — real engine v0.58
- **C** — real `collectTotals()` (and Bug A had to be fixed first to even reach the DI layer)
- **D** — plugin actually firing (and Bug C had to be fixed first to actually fire it)

Unit tests instantiate plugins directly with mocks, bypassing DI wiring entirely. Future Magento-tier work needs a **live-Magento integration test** (e.g. `@magento/testing` harness or markshust-in-CI smoke test) — the unit-test surface area cannot reach these classes of issue.

Re-deploy v1.3.3 on VM 914 + drive `collectTotals()` end-to-end is the captain's next step. v1.3.3 is the first release where Magento checkouts should actually compute tax.
