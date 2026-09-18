# 🎸 Sulu & Sylius Kickstarter (Version 38)

Content managed with **Sulu**, shop powered by **Sylius**, both behind a
single Caddy router under one URL. Native on Apple Silicon and Linux x86_64.

**Verified against:** Sulu 3.0.9, Sylius 2.2.9, Symfony 7.4.18, PHP 8.5.10,
MySQL 8.4.11, Node.js 24.21.0, Docker arm64.

Since v38 those versions are not maintained by hand. `kickstarter.yaml`
holds two Composer constraints, one for Sylius and one for Sulu, and
`make versions` derives PHP, MySQL and Node.js from the upstream CI of
both projects into `versions.env` (version rule: docs/decisions.md,
ADR-11 and ADR-12).

> Thirty-five bugs and architecture corrections since the first delivery
> attempt are baked in — including a genuine infinite loop caused by a
> Sulu/Symfony version mismatch (Sulu 2.6 + Symfony 7.4) and an nginx
> header bug that led to switching the router to Caddy (FrankenPHP already
> uses Caddy for PHP anyway — now it's one web server technology
> throughout the stack). Exactly what was fixed and why: **[FIXES.md](FIXES.md)**.
> Quick version-by-version summary: **[CHANGELOG.md](CHANGELOG.md)**.

---

## 🚀 Installation

### One command

```bash
make setup
```

Runs every stage automatically through to a finished shop **including
Sulu**: build the image → generate Sylius (`./sylius`) and Sulu 3.0
(`./sulu`) from their overlays →
start containers → Composer dependencies → `verify` → Sylius DACH data and
products → **`sulu:build dev`** (Sulu database, PHPCR, search index,
`admin`/`admin` user) → 5 test orders. With 16 GB RAM and a decent
internet connection this takes **15–25 minutes**, most of it spent on the
two `composer install` runs and the Sulu cache build.

**`make setup` stops automatically if `verify` reports a finding** — the
rest of the chain never runs on top of a broken base. The verify output
stays in the terminal and shows exactly what needs attention.

Once `make setup` finishes, both systems are ready:

- **Shop backend:** http://localhost:8082/admin/ (Sylius product/order
  management, on its own port — see FIXES.md No. 28 for why — Sylius' own
  storefront is switched off, see the headless section below)
- **Storefront:** http://localhost/ (admin: http://localhost/admin/,
  `admin` / `admin`)

### Installation step by step

If you'd rather watch each stage, or one of them fails — every stage is
individually repeatable, nothing already done gets lost:

```bash
make docker-build      # PHP image, ~5 min
make install-apps      # ./sylius and ./sulu from their overlays, ~10 min
make docker-start      # containers up
make deps              # composer install + cache:clear, both apps
make verify             # <<< check the output — must be all green
make fixtures           # Sylius: DB, DACH data, products, assets
                         # + automatically calls "sulu-install" at the end:
                         #   Sulu DB, PHPCR, search index, admin/admin user
make test-checkout      # 5 test orders
```

`make sulu-install` is also available on its own, for whenever only the
Sulu side needs to be rebuilt (e.g. after `make reset-soft`).

### Updating to a new package version

**Fresh local folder, but keep the Git history.** Copy the `.git`
directory from the old folder into a new one, then extract the new
archive on top — that gives you guaranteed-clean files (nothing from an
older, patched state survives) *and* one continuous commit history across
every version:

```bash
# Stop the old containers and drop the volumes. The `-v` is not
# optional: every version folder uses the same Docker project name and
# therefore the same volumes, so a "fresh" folder would otherwise start
# on the old database (see the pitfall list in docs/status/handoff.md)
cd ~/Sites/Sylu/vOLD
make docker-destroy

# New folder, git history carried over
mkdir -p ~/Sites/Sylu/vNEXT
cp -a ~/Sites/Sylu/vOLD/.git ~/Sites/Sylu/vNEXT/.git
cd ~/Sites/Sylu/vNEXT

tar xzf ~/Downloads/sylu-vNEXT.tar.gz
chmod +x docker/scripts/*.sh
cp .env.docker.example .env.docker
make setup

# Document the version jump
git add -A
git commit -m "vNEXT: <short summary of what changed>"
git tag vNEXT
```

("vOLD" and "vNEXT" are placeholders here — e.g. "v11" and "v12".)

No file deletion step needed beforehand — every file that ships in the
archive gets freshly written into what starts as an otherwise-empty
folder (aside from `.git`), so there's no risk of an old, patched file
surviving into the new version. `.env.docker` isn't tracked by Git
(see `.gitignore`) and gets recreated fresh from the example file each
time.

If this is the very first version you're installing, there's no old
`.git` to copy yet — just run `make git-init` after `make setup` instead
(see "Local Git repository" further down).

### Starting completely from zero

Only needed for a genuinely fresh machine, or if you want to throw away
the current state entirely:

```bash
mkdir -p ~/Tools/sulu-sylius-kickstarter
cd ~/Tools/sulu-sylius-kickstarter
tar xzf ~/Downloads/sulu-sylius-kickstarter-v36.tar.gz
chmod +x docker/scripts/*.sh
cp .env.docker.example .env.docker
make setup
```

### Why `make verify` is the most important command

It checks, in a single pass:

- PHP classes (namespace moves between Sylius 1.x and 2.x)
- All 22 required service IDs individually, verified against Sylius 2.2.8
- That `symfony/proxy-manager-bridge` is **not** installed — the
  fingerprint of the Sulu-2.6/Symfony-7.4 incompatibility that caused the
  infinite loop
- Guest checkout in `security.yaml`
- Configuration collisions (duplicate compose files, overwritten
  `_sylius.yaml`)
- Writes the fixture reference to `var/reference/sylius_fixtures.txt`

In the first two attempts, these were discovered one at a time while
booting — ten failed attempts in a row, one of which (the Sulu/Symfony
incompatibility) took hours of memory profiling to track down. The script
now finds all of it in one glance.

---

## 🔗 Access

| What | URL | Login |
|---|---|---|
| Sulu storefront | http://localhost/ | – |
| Sulu admin | http://localhost/admin/ | `admin` / `admin` (created by `sulu:build dev`) |
| Sylius admin (product/order management) | http://localhost:8082/admin/ | `admin@demo.de` / `admin` |
| Mailpit | http://localhost/mail/ | – |
| phpMyAdmin | http://localhost:8081/ | `root` / `root` |
| MySQL | `127.0.0.1:3307` | `demo` / `demo` |

`http://localhost/shop/` (Sylius' own shop frontend) is intentionally
switched off and redirects to the Sulu storefront. Sylius admin runs on
its own port (`:8082`), not under `/shop/admin/` as in earlier versions —
see FIXES.md No. 23 and No. 28.

---

## 🆘 When something's stuck

### First stop

```bash
make verify     # before fixtures
make doctor     # while running
```

### Emergency exits

```bash
make products-off        # remove the product fixture, rest keeps running
make fixtures             # retry without products

make fixtures-fallback   # Sylius' default fixtures instead of our DACH data
                          # -> runnable checkout within minutes

make reset-soft           # volumes gone, code stays
```

Fastest path to a presentable shop if the DACH fixtures act up:
`make products-off && make fixtures-fallback`. Add the DACH channels by
hand in the admin afterward, or fix the fixtures directly.

### Common errors

| Symptom | Fix |
|---|---|
| `no such service: sylius` | Sylius' own `compose.yml` collides → `make install-apps` moves it aside |
| `framework.workflows.workflows.sylius_payment` | `sylius/config/packages/_sylius.yaml` got overwritten → see FIXES.md No. 3 |
| `Unrecognized option ... under sylius_*` | Outdated key from Sylius 1.x → remove the block, reference in `var/reference/` |
| `Cannot autowire service App\...` | Should no longer happen (attributes instead of `services.yaml`) → `make verify` section 7 |
| Sulu `cache:clear` eats memory until it dies | `make verify` section 7 checks for `symfony/proxy-manager-bridge` → see FIXES.md No. 10 |
| `/shop/` redirects to `/shop/en_US/`, then "Channel could not be found!" | `config/parameters.yaml` is set to `en_US` → `make verify` section 6 checks this → see FIXES.md No. 14 |
| "Channel could not be found!" despite correct locale/channel config | Was the earlier nginx setup → replaced by the Caddy router since v4, this bug class is structurally impossible now (FIXES.md No. 15+16) |
| `/shop/` returns 404 | `make cache-clear`; check `x-forwarded-prefix` in `dach_demo.yaml` |
| Guest checkout demands a login | `security.yaml`: set `/checkout` to `PUBLIC_ACCESS` |
| `make: missing separator` | Tabs got lost while copying → take the file from the archive |
| `zsh: command not found: docker compose ...` | Don't use a `DC="..."` variable in zsh — word splitting is missing. Spell the command out, or use a function `dc() { ... "$@"; }` |
| `zsh: no matches found: ...*` | Glob in a remote path → wrap it in `bash -c '...'`, e.g. `exec sulu bash -c 'rm -rf var/cache/*'` |

---

## 📁 Structure

The repository root is the kickstarter itself and holds no application
code. Each application is generated from its upstream skeleton plus
everything we own, which lives in its overlay. Both application folders
are in `.gitignore`.

```
.
├── kickstarter.yaml             (the only versions chosen by hand:
│                                 Sylius and Sulu)
├── versions.env                 (generated: PHP, MySQL, Node - derived
│                                 from those two, see make versions)
├── docker-compose.yaml
├── Makefile
├── README.md / CLAUDE.md / FIXES.md / CHANGELOG.md / QUICKSTART.md
├── .env.docker.example          (docker compose; .env belongs to Symfony!)
│
├── docker/
│   ├── php/Dockerfile, Caddyfile, conf.d/app.ini
│   ├── caddy/Caddyfile
│   ├── database/init/01-databases.sql
│   └── scripts/
│       ├── install-apps.sh          (skeleton + overlay per application)
│       ├── resolve-versions.sh      (reads the upstream CI)
│       └── verify.sh                (20 sections, individually callable)
│
├── sylius-overlay/                  (copied into ./sylius)
│   ├── composer.json / composer.lock    (the reviewed dependency set)
│   ├── config/packages/
│   │   ├── dach_demo.yaml               (channels, taxes, taxonomy)
│   │   ├── dach_products.yaml           (products - can be switched off)
│   │   └── sylius_shipping_payment.yaml (shipping, payment)
│   ├── src/Fixture/RockbandProductsFixture.php   (#[Autowire] attributes)
│   ├── src/Command/CreateTestOrdersCommand.php   (#[Autowire] attributes)
│   ├── templates/bundles/               (Sylius bundle template overrides -
│   │                                     belongs here, NOT in sulu-overlay:
│   │                                     Sylius renders these, FIXES.md No. 45)
│   ├── phpstan-kickstarter.dist.neon
│   └── var/demo-images/                 (own product photos, optional)
│
├── sulu-overlay/                    (copied into ./sulu)
│   ├── composer.json / composer.lock    (the reviewed dependency set)
│   └── ...                              (theme, catalog, Sylius bridge,
│                                         60 tests)
│
├── sylius/                          (generated - not in the repository)
└── sulu/                            (generated - not in the repository)
```

Everything under `sylius-overlay/` and `sulu-overlay/` is ours;
everything else inside `sylius/` and `sulu/` comes from upstream. That
separation is what the old structure lacked: up to v37 Sylius lived in
the repository root, mixed in with the kickstarter's own files, and the
installer had to snapshot and restore them around itself (docs/decisions.md,
ADR-12).

Two files inside the Sylius app are neither ours nor untouched: the
installer patches `config/packages/security.yaml` and
`config/parameters.yaml` instead of overlaying them, so that upstream
changes in those files are not frozen by us. Only the locale patch
actually changes anything today — Sylius 2.2 has no `/checkout` entry in
`security.yaml` at all, so don't go looking for that edit (FIXES.md
No. 53).

Fixture and Command wire themselves entirely through
`#[Autowire(service: '...')]` attributes right on the constructor — there
is **no** service definition left in `config/services.yaml`. That used to
be the root cause of Fix No. 8 (see FIXES.md): Symfony loads
`services.yaml` after `config/packages/`, silently overriding any
definition placed there.

Where and why this deviates from the original spec: see **FIXES.md**,
points 3, 5 and 10.

---

## 🌍 DACH configuration

| | 🇩🇪 | 🇦🇹 | 🇨🇭 |
|---|---|---|---|
| Channel | `germany` | `austria` | `switzerland` |
| Locale / currency | de_DE / EUR | de_AT / EUR | de_CH / CHF |
| VAT | 19% / 7% | 20% / 10% | 8.1% / 2.6% |
| Shipping | DHL €4.95 / Express €9.95 | DHL €6.95 | Post CHF 9.95 |
| Free from | €50 | €50 | CHF 50 |

Six rockband products from €69 to €1,499, images with a two-stage
fallback (local → generated placeholder icon — see FIXES.md No. 35 for
why a third, Pixabay-download tier was tried and abandoned, and No. 38
for why it was removed entirely rather than kept as permanently-dead
code).

**Want real product photos instead of the generated placeholder?** Drop
a JPEG into `var/demo-images/<product-code>.jpg` before running
`make fixtures` — e.g. `var/demo-images/fender_stratocaster.jpg`. The
local file always wins over the placeholder (tier 1 of the fallback
chain, see `RockbandProductsFixture.php`), no code changes needed.
Product codes match the fixture: `fender_stratocaster`,
`gibson_les_paul`, `marshall_dsl40cr`, `pearl_export_exx`, `shure_sm58`,
`boss_ds1_distortion`.

---

## 🛍️ Headless catalog: categories and products in Sulu

Sylius now runs fully headless. Its own shop frontend (`/shop/`) is
switched off and redirects to the Sulu storefront — Sylius admin stays
reachable on its own port (`http://localhost:8082/admin/`, see FIXES.md
No. 28) for product/order management. The entire visible catalog runs
in Sulu:

| URL | Content |
|---|---|
| `/produkte/` | All categories |
| `/produkte/{category}/` | Products within a category, e.g. `/produkte/guitars/` |
| `/produkte/{category}/{code}` | Product detail, e.g. `/produkte/guitars/fender_stratocaster` |

Technically an ordinary Symfony controller
(`sulu-overlay/src/Controller/CatalogController.php`) with its own routes,
not a Sulu page document — categories and products are machine-generated
content pulled from Sylius; Sulu's editorial page tree is the wrong tool
for that. Visually it still blends in seamlessly, because these pages use
the same shared `base.html.twig` layout.

**Every API quirk here was checked against real responses from the running
instance, not copied from documentation** (details: FIXES.md No. 23): the
taxon filter is called `productTaxons.taxon.code`, not `taxon`; products
are only reachable through their `code`, not the nicer-looking `slug`;
price and stock are already embedded in the product response, no extra
request per item.

**Current state:** categories and products are fully browsable, including
images and prices, and a real shopping cart is live (see next section).

## 🛒 Headless cart

Adding, updating and removing items works end to end, verified against
the running Shop API (details: FIXES.md No. 26):

- `POST /produkte/{category}/{code}` page → "In den Warenkorb" form →
  `cart_add` route → `CartManager` gets or creates a cart, stores the
  Sylius cart token in Sulu's own session, `SyliusShopClient::addCartItem`
  calls the API
- `/warenkorb/` shows the cart, with quantity update and per-item removal
- A small badge in the navigation shows the item count, backed by a
  lightweight `sylius_cart_summary()` Twig function that never creates a
  cart just to display a badge

**The browser never sees a Sylius cart token** — only Sulu's own session
cookie. That's the headless architecture decision from FIXES.md No. 23
(option A) actually implemented, not just planned.

One genuinely useful finding while verifying this: changing an item's
quantity needs `Content-Type: application/merge-patch+json`, while adding
an item needs `application/ld+json` — API Platform expects different
content types for `POST` vs. `PATCH`. Easy to get wrong, confirmed by
testing both against the real API rather than assuming they'd match.

**Not yet built:** real payment processing (stage 5 of the roadmap in
FIXES.md No. 23) — checkout itself is now fully built, see below.

## 💳 Headless checkout — every payment method testable

The full flow — address → shipping → payment → summary → confirmation —
runs end to end, verified against the running Shop API step by step
(details: FIXES.md No. 27):

| URL | Step |
|---|---|
| `/checkout/adresse` | Email + shipping/billing address |
| `/checkout/versand` | Shipping method selection |
| `/checkout/zahlung` | **Payment method selection — all four configured methods, genuinely selectable** |
| `/checkout/uebersicht` | Order summary, "Jetzt kostenpflichtig bestellen" |
| `/checkout/bestaetigung/{number}` | Confirmation page |

**The actual point of this stage:** the payment step shows every payment
method configured for the channel — PayPal, Klarna invoice, prepayment,
credit card (`sylius_shipping_payment.yaml`) — not just the one Sylius
auto-assigns as a pricing preview as soon as a cart holds an item.
Verified concretely: selecting Klarna instead of the auto-assigned
PayPal default, then confirming the completed order actually shows
Klarna as its payment method — proof an explicit choice sticks, not just
that the request returns a 200.

Each checkout step is guarded by `checkoutState`: skipping ahead (e.g.
opening `/checkout/zahlung` before ever setting an address) redirects
back to the step that's actually next, following the same linear state
machine `CreateTestOrdersCommand.php` already drives
(`cart → addressed → shipping_selected → payment_selected → completed`).

Guest checkout only — no login system exists in this project, matching
Sylius' own `PUBLIC_ACCESS` checkout requirement (FIXES.md No. 5). The
address form hard-codes the country to Germany, because the catalog and
cart only support the `germany` channel so far.

**Not yet built at the time this section was first written:** real
payment gateway processing — see the dedicated section below, that's now
partially built.

### Note on Sulu URLs still using German path segments

`/produkte/`, `/produkte/guitars/`, `/warenkorb/` etc. keep their German
path segments intentionally — this is a DACH storefront, and German URLs
match the German-language content shown on the page. Only the *documentation* and
*code comments* in this project were translated to English, not the shop
content itself (see the translation note below).

---

## 💶 Real payment gateway: Adyen (backend complete, widget in progress)

Stage 5 of the headless roadmap, chosen provider: **Adyen** (European
data sovereignty, official Sylius plugin already installed — see
FIXES.md No. 30 for the full verification trail).

**What's done and verified:**
- One Adyen payment method in the fixture (`code: adyen`), **disabled by
  default** — demo mode stays the safe out-of-the-box state
- All five credentials come from environment variables
  (`ADYEN_ENVIRONMENT`, `ADYEN_MERCHANT_ACCOUNT`, `ADYEN_API_KEY`,
  `ADYEN_CLIENT_KEY`, `ADYEN_HMAC_KEY`) — nothing hard-coded, empty
  placeholders in `.env.docker.example`
- Caddy routes the Adyen-specific paths the browser needs to reach
  directly (the Drop-in widget talks to Sylius, not to Sulu, for PCI
  reasons — a narrow, deliberate exception to "Sulu is the only
  browser-facing origin", not a reversal of it)

**The one-command switch you asked for:**

```bash
make payments-demo   # PayPal/Klarna/card (offline demo) + prepayment active, Adyen off
make payments-live   # Adyen active, prepayment stays active, demo methods off
```

Built on Sylius' own existing per-payment-method "Enabled" toggle — no
new Sylius Admin screen was built for this (see FIXES.md No. 30 for why
that was a deliberate choice, not a shortcut). `make payments-live`
refuses to run while `ADYEN_API_KEY` is empty in `.env.docker`, so it
can't silently enable a payment method that would fail at checkout.

**Setting up real Adyen sandbox credentials:**

```bash
# In .env.docker:
ADYEN_MERCHANT_ACCOUNT=YourMerchantAccount
ADYEN_API_KEY=your_test_api_key
ADYEN_CLIENT_KEY=your_test_client_key
ADYEN_HMAC_KEY=your_webhook_hmac_key

# Recreate the container so it picks up the new environment variables:
docker compose -f docker-compose.yaml --env-file .env.docker up -d --force-recreate sylius

make payments-live
```

**The Drop-in widget is now embedded** on the checkout payment page
(`checkout/summary.html.twig`) — see FIXES.md No. 32 for the full
discovery path. When Adyen is the selected payment method, the page
loads Sylius' own compiled shop bundle
(`public/build/app/shop/app-shop-entry.js`, 5.88 MB — chosen over
building a separate slim pipeline or Adyen's own CDN script, see No. 32
for the tradeoff) and renders the widget instead of our usual "place
order" button. Adyen's widget renders its own pay button and drives the
entire payment completion itself — our `completeCheckout()` is never
called for Adyen orders.

**Fixed since v20:** Adyen's own success handler redirects to a
hard-coded Sylius route (`/{_locale}/order/thank-you`) — now routed
through Caddy and overridden with a minimal template that immediately
forwards to our own confirmation page (`/checkout/bestaetigung/{number}`).
See FIXES.md No. 33 for the full verification trail (order number
availability, bundle-class-name check for the template override,
collision check against Sulu before adding the route).

**None of this has been tested against a real Adyen sandbox** — no
credentials existed at the time this was built (see above). Every
architectural fact is verified against real, installed code; the actual
payment flow itself is unverified until real credentials are available.

**Also out of scope for local development:** Adyen's webhook
notifications need a publicly reachable URL, which `localhost` isn't
from Adyen's servers — normally solved with a tunnel service like
`ngrok`. Local testing stays in Adyen's own test/debug mode instead.

---

## 🛠️ Server portability and mail polish

Three small fixes, see FIXES.md No. 34 for the full reasoning:

- **Admin redirect works on any host, not just `localhost`.** The
  `/shop/admin` redirect used to hard-code `localhost` — broken on a
  real server. Now uses Caddy's `{host}` placeholder, confirmed against
  Caddy's own documentation.
- **Custom mail sender.** Order confirmation emails no longer come from
  Sylius' default `no-reply@example.com` — configured in
  `dach_demo.yaml`.
- **Dead link removed from the order confirmation email.** The original
  template's "view order / change payment method" button pointed at
  Sylius' own (switched-off) shop frontend. Removed rather than
  repointed — our own confirmation page isn't designed as a standing
  order-lookup page for a link opened days later.

---

## 🔗 Integration: Sulu landing page with live Sylius products

Sulu ships its own homepage template ("Rockband Landingpage") that pulls
products **live from the Sylius Shop API** — not static text, real data
from the shop, in a shared visual design (dark concert-poster layout,
amber as the main accent, matching the `germany` channel color).

### What was added

| File | Purpose |
|---|---|
| `sulu-overlay/config/templates/pages/rockband_landing.xml` | New Sulu page template (title, hero subline, intro text, Sylius channel) |
| `sulu-overlay/templates/pages/rockband_landing.html.twig` | Rendering: hero, intro, product tiles |
| `sulu-overlay/templates/base.html.twig` | Shared layout: navigation, footer |
| `sulu-overlay/public/css/site.css` | Visual identity |
| `sulu-overlay/src/Service/SyliusShopClient.php` | Calls Sylius' Shop API internally |
| `sulu-overlay/src/Twig/ShopExtension.php` | Exposes that as a Twig function |

`make sulu-theme` copies these files into `./sulu` and is part of
`make sulu-install` (and therefore `make setup`) — a fresh install
includes the integration automatically.

**For an already-running installation**, it's enough to run:

```bash
make sulu-theme
```

### How the Sylius connection works technically

Sulu calls Sylius internally, inside the Docker network
(`http://sylius/...`), never through the public router. The trick: Sylius
picks its channel based on the HTTP `Host` header (see FIXES.md No. 15) —
the internal Docker hostname `sylius` isn't registered as a hostname on
any channel. So the service connects to the `sylius` container but
explicitly sends `Host: localhost` — exactly the mechanism that also lets
public traffic find the `germany` channel. This isn't a new source of
bugs, it's a deliberate reuse of what No. 15 already taught us.

### Deliberate caution: no more guessing games

The exact JSON shape of Sylius' Shop API (`/api/v2/shop/products`) could
not be verified against a running instance ahead of time — unlike
practically every other line in this project. So:

- **Only two fields are used** (`code`, `name`) — reliably present per
  Sylius' JSON-LD convention (`hydra:member`), regardless of details like
  image paths or price structure.
- **Any error results in an empty list, never a 500.** If the API is
  unreachable or the shape is unexpected, the page falls back to showing
  the six known products statically — the landing page stays
  presentable no matter what.

If the live tiles don't show up: that's expected the very first time it's
called, not a bug — it falls back reliably. To check whether the API
itself is reachable:

```bash
docker compose -f docker-compose.yaml --env-file versions.env --env-file .env.docker exec -T sulu \
  bash -c 'curl -s -H "Host: localhost" -H "Accept: application/ld+json" \
    http://sylius/api/v2/shop/products?itemsPerPage=1 | head -c 500'
```

### Sulu content: filled in automatically, publishing stays manual

`make sulu-install` (and therefore `make setup`) automatically calls
`app:seed-homepage` at the end — a console command that fills the
homepage with finished demo copy. **Unlike an earlier version of
this project, this is no longer a blind attempt:** every step in it is
verified against the actual Sulu 3 code — Sulu rebuilt its page
architecture from the ground up (Symfony Messenger messages instead of
the old PHPCR `DocumentManager` API, storage in ordinary Doctrine tables
instead of PHPCR), and the command uses exactly that new path.

**One step stays deliberately manual:** `ModifyPageMessage` only writes to
the draft, never directly to production — that's intentional (see the
comment in Sulu's own handler code), not an automatic go-live without a
human in the loop. After `make setup`:

1. Open **http://localhost/admin/**, log in as `admin` / `admin`
2. Open the homepage in the page tree — title, hero subline, intro text
   are already filled in
3. Click **Publish**

Ten seconds instead of a form. If the seed command didn't run for some
reason (e.g. a very old install without this feature), it can be run
separately at any time:

```bash
docker compose -f docker-compose.yaml --env-file versions.env --env-file .env.docker exec -T sulu \
  bash -c 'php bin/console app:seed-homepage'
```

And if that also fails for some reason (e.g. a future Sulu update
reshuffles these message classes again — not unlikely for a still-young
major version): enter title, hero subline, intro text and Sylius channel
by hand in the same admin form, after switching the template to
**"Rockband Landingpage"**. This path always works, independent of
whatever's happening inside that Sulu version.

### What's different in Sulu 3 compared to older Sulu documentation

Anyone extending this template will quickly run into three changes that
many older guides (Sulu 1.x/2.x) don't mention yet:

| Area | Sulu 1.x/2.x | Sulu 3.0 |
|---|---|---|
| Modifying pages | `DocumentManager` (PHPCR) | Symfony Messenger messages (`ModifyPageMessage`) |
| Storage | PHPCR tree | Ordinary Doctrine ORM tables (`pa_pages`, ...) |
| URL field type | `resource_locator` | `route` |
| Controller for pages | `Sulu\Bundle\WebsiteBundle\Controller\DefaultController` | `Sulu\Content\UserInterface\Controller\Website\ContentController` |
| Twig variables | Direct (`{{ title }}`) | Under `content.*` (`{{ content.title }}`) |

Full derivation with diagnostic path: FIXES.md No. 19–22.

---

## 👩‍💻 For development teams

This project is built so that both a human and Claude Code can pick it up
cleanly — not just for demoing, but as a genuine starting point for a
real project.

### Static analysis

```bash
make phpstan
```

Runs PHPStan against this project's own PHP code — level 5 on the
Sylius side (`sylius-overlay/src/Fixture/`, `src/Command/`, config in
`phpstan-kickstarter.dist.neon`) and Sulu's own `max` level on the
overlay classes. Both pass clean.

The Sulu overlay reaching `max` cleanly took real work: 77 findings,
almost all genuine `mixed`-typed data access on Shop API responses
rather than annotation cosmetics. Fixed by narrowing values once
through small private helpers rather than casting at every read site —
see FIXES.md No. 41, which also documents the two places where this
changed real behavior, not just types.

Level 5 rather than Sylius' own level 9 is a deliberate, measured
choice: at level 9 the Sylius-side files produce 52 findings, almost
all from the intentionally untyped `object` repository parameters that
exist precisely to survive Sylius interface-namespace moves between
versions (see FIXES.md No. 6/7 and No. 40). Level 5 still catches the
bug class that actually cost this project time before — a call to a
method that doesn't exist.

`make phpstan` is deliberately **not** part of `make verify` or
`make setup`: a kickstarter needs to stay installable even if a
developer's own new code has a finding.

### Tests

```bash
make test              # unit tests, no containers needed
make test-integration  # against the running Sylius API
make test-smoke        # the storefront through Caddy
make test-all          # all three
```

**Unit tests (35)** cover the three classes carrying the Sylius
integration: `SyliusShopClient`, `CartManager` and `ShopExtension`. The
API is simulated at the HTTP boundary with Symfony's `MockHttpClient`,
so they run anywhere.

**Integration tests (10)** hit the real Shop API and pin down what a
mock structurally cannot: that the taxon filter parameter still works
(asserted negatively too — a wrong one is silently ignored rather than
erroring), that products resolve by code and not slug, that price data
still arrives embedded, and that the full cart lifecycle holds. They
need running containers with fixtures loaded, and skip themselves with
an explanatory message otherwise.

**Smoke tests (15)** go through Caddy and check the pages a visitor
actually sees: all five categories listed, prices in German format,
the add-to-cart form with its CSRF token, nav and cart badge on every
page, and the checkout guards redirecting on an empty cart. They also
cover the routing itself — `/shop/` → catalog, `/shop/admin` → port
8082, Sylius admin answering there, Mailpit reachable — which nothing
else verifies automatically. Needs a published homepage on top of the
above.

They earned their keep on the first run: the Austrian and Swiss
channels turned out to have been returning an empty shop since v5,
because only German product translations were ever created (FIXES.md
No. 48). Nobody noticed, because the storefront only ever requests the
German channel.

**What the smoke tests cannot do:** they use curl, not a browser. The
Adyen Drop-in widget mounts itself with JavaScript, so they only see
the empty container it renders into — never the actual payment form.
The Sulu admin is a JavaScript SPA, so a `200` is all that can be
asserted there. Both would need Panther or Playwright.

See FIXES.md No. 46, 47 and 49 for what each test pins down and why.
Like `make phpstan`, none of this is wired into `make setup`.

**No CI pipeline is included, on purpose.** The choice of platform
depends on the infrastructure this project lands in, so tying `make
setup`, `make phpstan` and `make test-all` together is left to the team
taking over. `docs/decisions.md` (ADR-10) records the decision;
`docs/status/backlog.md` under "P2 — CI-Pipeline" collects the verified
groundwork — which targets need running containers, where the upstream
versions are unpinned, which PHPStan configuration a bare
`vendor/bin/phpstan` picks up, and the one place where `make setup`
would report a false green.

### Vendor code vs. your own customizations

**No Sylius or Sulu core code is checked into the repository.** Both come
exclusively through Composer:

```bash
kickstarter.yaml               # declares the INTENT: two constraints
<app>-overlay/composer.json    # the application's own declaration
<app>-overlay/composer.lock    # pins the EXACT versions - CHECKED IN
<app>/vendor/                  # result of "composer install" - NOT checked in
```

Both `composer.lock` files are deliberately checked in: without them,
every developer (and every fresh install) could end up with different
package versions — exactly the kind of version drift that slowed this
project down repeatedly (see FIXES.md). `make setup` always installs
from the reviewed lock, never from a fresh resolution.

The two layers do different jobs. `kickstarter.yaml` is the shopping
list, `composer.lock` is the receipt. Changing the list is an explicit
step:

```bash
make versions        # re-derive PHP/MySQL/Node from the upstream CI
make docker-build    # the PHP image carries those versions
make deps            # resolve the new dependency set
make verify && make test-all
make freeze-locks    # write the new locks back into the overlays
```

Until `make freeze-locks` runs, nothing about the reviewed state has
changed — which is the point: the new versions have to pass the tests
first.

**All custom code lives structurally separate from vendor code** — not as
patches on top of `vendor/`, but in the app-native directories meant for
exactly this purpose:

| Where | What |
|---|---|
| `sylius-overlay/config/packages/dach_demo.yaml`, `sylius_shipping_payment.yaml`, `dach_products.yaml` | Sylius: DACH configuration |
| `sylius-overlay/src/Fixture/`, `src/Command/` | Sylius: custom fixture and console classes |
| `sylius-overlay/templates/bundles/` | Sylius: bundle template overrides |
| `sulu-overlay/` | Sulu: template, theme, catalog controller, Sylius bridge, tests |

Each overlay is copied into its application by `make sylius-theme` and
`make sulu-theme`, and by `make install-apps` as part of the install. The
overlay is the source of truth: a change made directly inside `sylius/`
or `sulu/` is lost on the next copy, and `make verify` section 19 reports
an overlay file that has not arrived in its application.

Updating Sylius or Sulu means editing the constraint in
`kickstarter.yaml` and running the five steps above — no risk of losing
custom changes, because none of that code lives inside `vendor/` or in a
generated folder.

### Local Git repository

After installation:

```bash
make git-init
```

Creates a repository in the project root and makes an initial commit with
everything a repo should contain — **not** the two generated application
folders `sylius/` and `sulu/` and not `.env.docker` (secrets), but **very
much including** `kickstarter.yaml`, `versions.env`, both overlays with
their `composer.json` + `composer.lock`, and all custom code. Deliberately not an automatic part of `make setup` —
initializing a Git repository should be a conscious decision, not a
silent side effect (e.g. if a remote repository is already prepared to
clone into instead).

**One repository for both apps, not three separate ones:** Sylius
(`./sylius`) and Sulu (`./sulu`) are one cohesive product here — a
kickstarter for one shop, not two independently deployed services with
separate teams. A single repository keeps changes that span both systems
(like the entire headless rebuild in this project) traceable through one
coherent commit history. Split repos (classically via Git submodules)
only make sense once Sylius and Sulu are actually deployed independently
or owned by separate teams — unnecessary overhead for this project.

**Updating to a new package version:** copy `.git` into the new folder,
extract the new archive on top, then commit and tag — see "Updating to a
new package version" above for the full command sequence.

### Getting Claude Code up to speed

**`CLAUDE.md`** is written exactly for this purpose — every architectural
decision with its reasoning, not just the outcome. **`FIXES.md`**
documents every bug already solved, along with how it was diagnosed;
before tackling a new problem, it's worth checking there first in case
the pattern is already known. `make verify` is the first command to run
for any new problem — it checks service IDs, PHP classes and known
version traps in a single pass, before anything gets loaded.

---

## 💡 For later

Your install already ships **Mollie, PayPal, and Adyen as plugins**
(Sylius Standard 2.2). The offline-gateway configuration here was
unnecessarily defensive in hindsight — once the shop is running, real
test credentials can replace the placeholders for a demo. Mollie covers
PayPal, Klarna, Sofort and credit card in a single integration and is
usually the shortest path for DACH.

---

## 🌐 A note on language

This documentation and all code comments are in English, following
standard practice for international development teams. The **shop
content itself** — product names, marketing copy on the storefront,
German URL path segments like `/produkte/` — stays in German
intentionally, because that's the language of the DACH target audience
this demo shop is built for. Translating the documentation doesn't mean
translating what German-speaking customers actually see.
