# Demo deployment — magento-demo VM

**Status:** Infrastructure provisioned. Magento bootstrap requires Eric's Marketplace credentials. End-to-end checkout test pending.

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

   Then re-run the markshust bootstrap from the kickoff:

   ```bash
   ssh magento-demo bash <<'MAGENTO'
   mkdir -p ~/magento && cd ~/magento
   curl -s https://raw.githubusercontent.com/markshust/docker-magento/master/lib/onelinesetup | \
     bash -s -- magento.test 2.4.7
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
docker compose exec -u www-data app composer config \
  repositories.osstax path /opensalestax-magento
docker compose exec -u www-data app composer require \
  ejosterberg/module-opensalestax:@dev
docker compose exec -u www-data app bin/magento module:enable EJOsterberg_OpenSalesTax
docker compose exec -u www-data app bin/magento setup:upgrade
docker compose exec -u www-data app bin/magento setup:di:compile
docker compose exec -u www-data app bin/magento cache:clean
MODULE
```

## Configure the module via CLI (no admin browser needed)

```bash
ssh magento-demo bash <<'CONFIG'
cd ~/magento/src
docker compose exec -u www-data app bin/magento config:set osstax/general/api_url http://10.32.161.126:8080
docker compose exec -u www-data app bin/magento config:set osstax/general/fail_hard 0
docker compose exec -u www-data app bin/magento cache:clean
CONFIG
```

## Verify

1. Module enabled:
   ```bash
   ssh magento-demo 'cd ~/magento/src && docker compose exec -u www-data app bin/magento module:status | grep OpenSalesTax'
   # Expected: EJOsterberg_OpenSalesTax (in "List of enabled modules")
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

| ID | Criterion | Status |
|---|---|---|
| D1 | Demo Proxmox VM provisioned | ✓ (VM 914, IP 10.32.161.183) |
| D2 | Magento 2.4.6+ devbox running | Blocked on Marketplace credentials |
| D3 | OST engine running | Re-use shared engine at 10.32.161.126:8080 |
| D4 | Module installed via Composer path repo | Pending D2 |
| D5 | Module enabled and configured via admin | Pending D2 |
| D6 | Real $100 MN checkout returns plausible tax | Pending D2 (manual — Eric) |
