# 🎸 Sulu & Sylius Kickstarter — Quickstart (Version 38, headless)

This guide takes the project from an empty folder to a fully headless
shop: **Sylius only supplies data through its API**, the entire visible
frontend — storefront, product categories, product details — runs in
**Sulu**. Sylius' own shop frontend is switched off; only the admin
interface for product/order management stays reachable.

All known bugs from previous attempts have been fixed and verified
against a running installation (full history: FIXES.md).

**Prerequisite:** Docker Desktop is running, with at least 8 GB RAM
assigned (Settings → Resources → Memory).

---

## Installation — 5 commands

```bash
mkdir -p ~/Tools/sulu-sylius-kickstarter
cd ~/Tools/sulu-sylius-kickstarter
tar xzf ~/Downloads/sulu-sylius-kickstarter-v36.tar.gz
chmod +x docker/scripts/*.sh
cp .env.docker.example .env.docker
make setup
```

`make setup` runs through **without any further input** to a finished
system: build the image, install Sylius and Sulu 3.0, start containers,
load dependencies, verify the configuration, load DACH shop data, build
the Sulu database, wire up the rockband catalog (categories + products),
fill the homepage with demo copy, create five test orders.

**Duration:** 15–25 minutes, depending on internet connection and
machine. Most of it goes into the two `composer install` runs.

**If `make setup` stops with a finding from `verify`:** the terminal
output shows exactly what was checked and what failed — 22 service IDs,
PHP classes, configuration files and known version traps in a single
pass, before anything gets loaded. That's the only place worth checking.

---

## One click stays deliberately manual

After `make setup`, the homepage is already filled with a title, hero
text and intro copy — but not yet published. That's **intentional, not a
gap**: Sulu itself only ever writes changes to the draft, nothing in this
project switches something live automatically. Without this one click,
`/` keeps showing Sulu's default placeholder page.

1. Open **http://localhost/admin/**, `admin` / `admin`
2. Open the homepage in the page tree (content is already filled in)
3. Click **Publish**

Then reload `http://localhost/`.

---

## Result

| What | URL | Access |
|---|---|---|
| **Sulu storefront** | http://localhost/ | – |
| **Product categories** | http://localhost/produkte/ | – |
| **Product detail** (example) | http://localhost/produkte/guitars/fender_stratocaster | – |
| Sulu admin | http://localhost/admin/ | `admin` / `admin` |
| Sylius admin (manage products/orders) | http://localhost:8082/admin/ | `admin@demo.de` / `admin` |
| Mailpit (emails) | http://localhost/mail/ | – |
| phpMyAdmin | http://localhost:8081/ | `root` / `root` |

`http://localhost/shop/` (Sylius' own shop frontend) is deliberately
switched off and redirects to the Sulu storefront. Sylius admin runs on
its own port — see FIXES.md No. 23 and No. 28.

Six rockband products (guitars, amplifiers, drums, microphone, effects
pedal) are browsable live through Sulu, with real images and prices
pulled directly from Sylius' Shop API.

**Not yet included:** cart and checkout don't run in Sulu yet — that's
the next build stage of the headless rebuild (roadmap: FIXES.md No. 23).

---

## Afterward

```bash
make info       # show URLs and container status again
make doctor      # quick diagnosis: architecture, Sylius version, fixture suites
make logs        # follow logs of all containers live
make sulu-theme  # re-copy catalog/theme files into ./sulu (idempotent)
```

For everything else — individual steps, emergency exits, the
troubleshooting table, architectural decisions — see **README.md** and
**CLAUDE.md**. The full history of every bug fixed is in **FIXES.md**.
