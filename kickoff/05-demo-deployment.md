# Stage 05 — Demo deployment on Proxmox VM

> ~1-2 hours. Provision a Proxmox VM, run a real Magento 2.4.6+
> devbox + the OST engine, install the module via a Composer
> path repository, run a real US-address checkout, and verify
> per-jurisdiction tax breakdown matches expectations.

This stage proves the module works in a realistic environment
before claiming v1.0.

## Provision the VM

Follow `~/.claude/proxmox-playbook.md`. Saleor demo takes 901
and Vendure 902, so Magento demo defaults to **903**. Check
first with `ssh proxmox-workshop 'qm list'`.

```bash
ssh proxmox-workshop bash <<'PROVISION'
set -e
VMID=903
NAME=magento-demo
MEM=8192
CORES=4
DISK=80
ISO=/var/lib/vz/template/iso/debian-13-genericcloud-amd64.qcow2

[ -f $ISO ] || wget -qO $ISO https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2

qm create $VMID \
  --name $NAME --memory $MEM --cores $CORES --cpu host \
  --net0 virtio,bridge=vmbr0 \
  --scsihw virtio-scsi-single \
  --serial0 socket --vga serial0 \
  --agent enabled=1 --ostype l26

qm importdisk $VMID $ISO vmpool
qm set $VMID --scsi0 vmpool:vm-$VMID-disk-0,discard=on,ssd=1
qm set $VMID --ide2 vmpool:cloudinit
qm set $VMID --boot order=scsi0
qm resize $VMID scsi0 ${DISK}G

qm set $VMID --ciuser ejosterberg
qm set $VMID --sshkeys /root/.ssh/authorized_keys
qm set $VMID --ipconfig0 ip=dhcp

qm start $VMID
PROVISION
```

After ~60s, discover the IP via `arp-scan` or `tcpdump` (see the
proxmox-playbook). Add an SSH alias to `~/.ssh/config` on the
Windows box:

```
Host magento-demo
  HostName <discovered-ip>
  User ejosterberg
  IdentityFile ~/.ssh/proxmox_workshop
  IdentitiesOnly yes
  StrictHostKeyChecking accept-new
```

Verify: `ssh magento-demo 'uname -a'` returns Debian 13 kernel.

## Install Docker on the VM

```bash
ssh magento-demo bash <<'DOCKER'
set -e
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg git
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/debian trixie stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker ejosterberg
DOCKER
```

Log out and back in (`ssh magento-demo`) so the docker group
takes effect. Verify: `docker run --rm hello-world` succeeds.

## Stand up Magento via `markshust/docker-magento`

`markshust/docker-magento` is the de-facto community devbox for
Magento 2 — well-maintained, fully scripted, used by thousands
of merchants and devs. Docs:
<https://github.com/markshust/docker-magento>.

```bash
ssh magento-demo bash <<'MAGENTO'
set -e

# Bootstrap a fresh Magento 2.4.7 devbox at ~/magento
mkdir -p ~/magento && cd ~/magento
curl -s https://raw.githubusercontent.com/markshust/docker-magento/master/lib/onelinesetup | \
  bash -s -- magento.test 2.4.7

# The script:
#   - Pulls all required Docker images
#   - Runs `composer create-project magento/project-community-edition`
#   - Runs `bin/magento setup:install` with sensible defaults
#   - Generates an admin user with random password
#   - Imports SSL certs for magento.test

# Wait for it to finish; can be 10-15 min on first run (large pull + composer)
MAGENTO
```

After completion, the Magento admin password is printed to the
script output. Save it. Verify via web:

- `https://magento.test/` (storefront — add `magento.test`
  to `/etc/hosts` on your client pointing at the VM IP, or use
  `--resolve` curl flags)
- `https://magento.test/admin` (admin login)

## Stand up the OST engine

The merchant model has the engine on the same VM as Magento, so
mirror that. The engine is already shipped at
`http://10.32.161.126:8080` for shared use, but installing a
fresh copy in the demo VM proves the docs work.

```bash
ssh magento-demo bash <<'OST'
set -e
mkdir -p ~/ost && cd ~/ost
cat > docker-compose.yml <<'EOF'
services:
  ost-engine:
    image: ghcr.io/ejosterberg/opensalestax:latest
    ports:
      - "8080:8080"
    environment:
      - DATABASE_URL=postgres://ost:ost@ost-db:5432/ost
    depends_on: [ost-db]
  ost-db:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=ost
      - POSTGRES_PASSWORD=ost
      - POSTGRES_DB=ost
    volumes:
      - ost-db:/var/lib/postgresql/data
volumes:
  ost-db:
OST
docker compose up -d
OST
```

Wait for `curl http://<vm-ip>:8080/v1/health` to return
`{"ok": true, ...}`.

## Install the module into Magento via Composer path repository

Clone the module repo onto the VM, then point Magento's
composer at it as a `path` repository — this lets you iterate
on the module locally and have Magento pick up changes via
`composer update`.

```bash
ssh magento-demo bash <<'MODULE'
set -e

# Clone the module repo into ~/opensalestax-magento
cd ~
git clone https://github.com/ejosterberg/opensalestax-magento.git
cd ~/magento/src

# Add the local clone as a path repository
docker compose exec -u www-data app composer config \
  repositories.osstax path /var/www/html/../../opensalestax-magento

# Require the module (will resolve to the local path)
docker compose exec -u www-data app composer require \
  ejosterberg/module-opensalestax:@dev

# Enable + setup
docker compose exec -u www-data app bin/magento module:enable EJOsterberg_OpenSalesTax
docker compose exec -u www-data app bin/magento setup:upgrade
docker compose exec -u www-data app bin/magento setup:di:compile
docker compose exec -u www-data app bin/magento cache:clean
MODULE
```

The `markshust/docker-magento` setup mounts the Magento root at
`/var/www/html` inside the `app` container; the local clone of
the module needs to be reachable from inside that container.
The simplest pattern is to clone the module into
`~/opensalestax-magento` on the VM and configure Docker bind
mounts in the markshust setup to expose `~/opensalestax-magento`
at `/var/www/opensalestax-magento` inside the container. Adjust
paths as needed for your VM layout.

Verify the module is loaded:

```bash
ssh magento-demo \
  'cd ~/magento/src && docker compose exec -u www-data app bin/magento module:status | grep OpenSalesTax'
# Expected: "EJOsterberg_OpenSalesTax" under "List of enabled modules"
```

## Configure the module in the admin

In the Magento admin (`https://magento.test/admin`):

1. Log in with the bootstrap credentials.
2. Stores → Configuration → Sales → Tax → **OpenSalesTax**
3. Set:
   - **API URL**: `http://ost-engine:8080` (if reachable from
     inside the Magento app container) or
     `http://<vm-ip>:8080`
   - **API Token**: leave blank for a no-auth dev engine
   - **Fail Hard**: No (default)
4. Save.

Verify:

```bash
curl -sS http://<vm-ip>:8080/v1/health | jq .
# Confirms engine reachable from outside
```

## Configure a US ship-to address and run a test checkout

In the admin:

1. Stores → Configuration → Sales → **Shipping Methods** →
   Flat Rate → Enabled = Yes; Price = $10
2. Stores → Configuration → Sales → **Tax** → calculation
   settings → ensure shipping is tax-included and prices include
   tax = No (so taxes are added, not extracted)
3. Catalog → Products → Add Product → "Demo Product",
   price $100, tax class "Taxable Goods", save

Open the storefront (`https://magento.test/`), add the demo
product to cart, proceed to checkout, fill the shipping address:

- Name: Test User
- Street: 100 N 6th St
- City: Minneapolis
- Region: Minnesota
- ZIP: 55403
- Country: United States

**Expected behavior:**

- Checkout totals show:
  - Subtotal: $100.00
  - Shipping: $10.00
  - **Tax: ~$7-8** (Minneapolis combined rate is ~8.025% in
    2026 on $100 of taxable goods; ~$0.80 on $10 shipping if
    shipping is taxable; actual value comes from the engine)
  - Order Total: ~$117-$118
- Magento's `var/log/system.log` shows an INFO line:
  `{"quote_id": N, "line_count": 1, "api_status": 200, "rtt_ms": M}`

If any step fails, `docker compose logs -f app` (the Magento
container) surfaces the PHP errors. Common issues:

- Engine URL not reachable from the Magento container's network
  (use `host.docker.internal` or the VM's bridge IP)
- Tax class mismatch — product isn't marked taxable
- DI compile not run after the module enable

These are stage 06 iteration loop fodder.

## Test the non-USD short-circuit

Magento supports multiple currencies via Stores → Currency.
Add EUR as an allowed currency, switch the storefront to EUR,
build a synthetic checkout to a French address. Expected:

- Module's plugin sees country=FR, currency=EUR
- Returns control to Magento's built-in tax_rate calc
- No OST call made (verify via `var/log/system.log` — no
  `opensalestax` entries for this checkout)

## Acceptance for stage 05

Stage 05 is done when:

- [ ] Proxmox VM `magento-demo` (VMID ≥903) is up and reachable
- [ ] Magento 2.4.6+ devbox running via
  `markshust/docker-magento`
- [ ] OST engine running via docker-compose on the same VM
- [ ] Module installed via Composer path repository and enabled
- [ ] `bin/magento module:status` shows
  `EJOsterberg_OpenSalesTax` enabled
- [ ] Admin config screen accessible at Stores → Configuration
  → Sales → Tax → OpenSalesTax
- [ ] $100 MN checkout returns nonzero, plausible tax via the
  module
- [ ] EUR checkout falls back to Magento's built-in calc (no
  OST call logged)
- [ ] Module logs are clean (no errors during the happy path)
- [ ] VM IP + admin credentials documented in
  `specs/demo-deployment.md` (gitignored fields if any go to
  the user's password manager)

Mark stage 05 complete in TodoWrite. Proceed to
`06-iteration-loop.md`.
