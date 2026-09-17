# CLAUDE.md — Architecture and working documentation

> **Version 37, verified against Sulu 3.0.9 / Sylius 2.2.9 / Symfony 7.4.18 /
> PHP 8.5.10 / MySQL 8.4.11 / Node.js 24.21.0.** Every bug from previous attempts and its fix are recorded
> in FIXES.md. Wherever this document disagrees with FIXES.md, FIXES.md
> wins — especially point 10 (Sulu version), point 8 (service wiring via
> `#[Autowire]` attributes instead of `services.yaml`), and point 16
> (Caddy instead of nginx as the router). `make setup` has run cleanly
> through to a finished shop including Sulu since version 5, with zero
> manual intermediate steps.

Context for continuing this project with Claude, or for any human opening
it for the first time. It focuses on the **why** — including every place
where the original spec was deliberately not followed.

---

## 1. Project goal

A presentable demo shop for DACH customers, combining Sulu as CMS and
Sylius as the shop engine. Core requirement: a prospect must be able to
click through checkout live, in a meeting, with zero hiccups.

Target platforms: MacBook Air M5 and MacBook Pro (ARM64) locally, Linux
x86_64 as the demo/production server. Same images, no emulation.

---

## 2. Architectural decisions

### 2.1 Root = Sylius, `sulu/` = Sulu

The spec references `config/packages/…`, `src/Command/…`, and
`fixtures/…` without a prefix. That only works if one of the two apps
lives in the project root. Sylius was chosen, because that's where most
of the project logic lives (fixtures, checkout, test command) and Sulu
mainly supplies content in this demo.

Why two separate apps at all: in a shared codebase, Sulu and Sylius
collide in several places — two security firewalls with their own user
providers, competing Doctrine mappings, Sulu's PHPCR layer sitting next
to Sylius' plain ORM, two admin routing prefixes, conflicting
expectations around `default_locale`. Reference projects solve this the
same way, keeping them separate: the `sulu-sylius-showcase` connects the
two systems via API, `BitBag SyliusSuluPlugin` via a plugin. Neither
merges them into one app.

Side effect: this is also what makes the requested subpath routing
meaningful in the first place. In a single mono-app, `/shop/` would just
be a Symfony route prefix, and there'd be nothing for a router to do.

### 2.2 Subpath via X-Forwarded-Prefix, not alias — and via Caddy, not nginx

The spec mentions `alias` for `/shop/`. That doesn't work: `alias` is a
filesystem mechanism for static files. Two Symfony apps under one host
get connected with a reverse proxy plus `X-Forwarded-Prefix`:

```caddyfile
handle_path /shop/* {
    reverse_proxy sylius:80 {
        header_up X-Forwarded-Prefix "/shop"
    }
}
```

Symfony only honors `X-Forwarded-Prefix` when the proxy is listed in
`trusted_proxies` **and** the header is listed in `trusted_headers`. Both
are set in `config/packages/dach_demo.yaml`. Without those two entries,
Sylius generates links to `/checkout/…` instead of `/shop/checkout/…` —
the classic gotcha with subpath deployments and the most common cause of
what looks like a broken checkout.

`/media/` and `/bundles/sylius*` are also routed separately to the
Sylius container, because Sylius serves those paths at the root. Add
more here if other assets go missing.

**The router is Caddy, not nginx — and that wasn't always the case.** The
first version of this project used nginx as the reverse proxy, because
that's what the original spec said, without questioning whether it fit a
FrankenPHP setup. It didn't fit well: nginx **replaces**
`proxy_set_header` directives per `location` block entirely instead of
inheriting them. A block with just one directive of its own
(`X-Forwarded-Prefix`) lost the `Host` header it would otherwise have
inherited from the `server` block — nginx then fell back to its own
default for `Host`: the name of the `upstream` block itself
(`sylius_upstream` instead of `localhost`). Sylius' hostname-based channel
detection failed because of it, even though the channel and locale
configuration had been correct all along — a bug only diagnosable by
adding a timestamped marker to the Symfony log (FIXES.md No. 15).

Since version 4, the router runs on Caddy (`caddy:2-alpine`), no longer
nginx. FrankenPHP *is* Caddy with embedded PHP — using Caddy for routing
too makes the whole stack a single web server technology. More
importantly: Caddy's `reverse_proxy` doesn't have that class of bug
structurally. The incoming `Host` header is forwarded unchanged by
default, `X-Forwarded-For`/`-Proto`/`-Host` are set correctly
automatically — regardless of how many extra headers a `handle` block
needs on top. Full bug history and the rebuild: FIXES.md No. 15 and 16.

### 2.3 FrankenPHP without worker mode

Worker mode is the main reason to choose FrankenPHP in the first place —
and it's still off here. Sulu's PHPCR session and parts of the Sylius
admin hold state that doesn't reset cleanly between requests. For a demo,
a stable shop is worth more than throughput.

For production it's worth trying to enable it specifically for the
Sylius shop frontend:

```yaml
environment:
  FRANKENPHP_CONFIG: "worker ./public/index.php"
```

Paired with load testing and a close eye on static properties and the
Doctrine identity map.

Image tag is `1-php8.5`, not `1.2-php8.5`: patch tags occasionally
disappear from the registry and break reproducible builds when they do.
Which PHP version (and which MySQL and Node.js version) is chosen
follows a fixed rule since v37: the highest version both Sylius and
Sulu test in their upstream CI, the database in combination with that
PHP version — see docs/decisions.md, ADR-11.

### 2.4 No hard-coded platform in docker-compose

The spec calls for `platform: linux/arm64` on every service. That
directly conflicts with requirements 3 and 4 (Linux x86_64) and is also
unnecessary: every image used here is multi-arch, and Docker picks the
host architecture automatically. On the M5 that means native arm64, no
Rosetta involved.

To force an architecture (e.g. to test an x86 build locally), set
`DOCKER_DEFAULT_PLATFORM` in `.env.docker`. `make doctor` shows what's
actually running.

### 2.5 Mailpit instead of Mailcatcher

`sj26/mailcatcher` has no official arm64 image. On Apple Silicon it would
run under QEMU emulation — slow and occasionally unstable. Mailpit is the
actively maintained successor: multi-arch, SMTP on 1025, UI on 8025, with
`MP_WEBROOT=/mail` mounted directly under the desired path.

### 2.6 Payment methods as offline gateways

PayPal, Klarna, Stripe and Sofort are configured with
`gatewayFactory: 'offline'`. Real gateways fail without credentials
exactly at the payment step — the one place requirement 5 says must never
fail. Names, descriptions and payment instructions are kept realistic so
the demo holds up in a live meeting.

Migration path: the comment block at the end of
`config/packages/sylius_shipping_payment.yaml`. For DACH, the Mollie
plugin covers PayPal, Klarna, Sofort, credit card and EPS in a single
integration and is usually the shortest path.

### 2.7 Sulu 3.0, not 2.6

`sulu/skeleton:^3.0` instead of `^2.6`. This isn't a stylistic detail —
it's the fix for the biggest bug in this entire project: Sulu 2.6
internally requires `symfony/proxy-manager-bridge (^5.4 || ^6.0)`, while
the rest of the app runs on Symfony 7.4. Composer resolves that by mixing
two Symfony generations in one install — and the compiled container code
generated for `sulu_core.proxy_manager.configuration` contains a
self-referencing fallback line that calls itself endlessly during
compilation, until memory runs out. No amount of `memory_limit` tuning
fixes this; it only postpones the crash. Sulu 3.0 removed ProxyManager
entirely (native Symfony lazy loading) and is officially supported on
Symfony 6.4–7.4. Full bug history with diagnostic path: FIXES.md No. 10.
Since v37 the default is `~3.0.9`: every 3.0.x patch, but no untested
3.1 (ADR-11).

`make verify` now actively checks that `symfony/proxy-manager-bridge`
isn't installed — if it shows up anyway, that's the early-warning sign
for exactly this problem.

### 2.8 Two .env files

`docker compose` and Symfony both want `.env` in the root. Docker
variables therefore live in `.env.docker`, and the Makefile consistently
calls `docker compose` with `--env-file .env.docker`. Remember this on
any manual invocation, or the defaults from the compose file take over.

---

## 3. Services

| Service | Image | Purpose |
|---|---|---|
| `router` | caddy:2-alpine | main storefront port (80) + dedicated Sylius admin port (8082, see FIXES.md No. 28), subpath routing |
| `sylius` | custom FrankenPHP image | shop, `APP_ROOT=/app/public`, mounts `.` |
| `sulu` | same image | CMS, mounts `./sulu` |
| `database` | mysql:8.4 | two databases, health check (`MYSQL_*` variables; `serverVersion` kept in step by `make verify` section 17, FIXES.md No. 51) |
| `redis` | redis:7-alpine | runs (256 MB LRU), but is **not wired to either app** — Symfony cache and sessions use the filesystem; `config/packages/cache.yaml` is the unmodified recipe with Redis commented out. Redis 7.4 is licensed RSALv2/SSPLv1, not OSI open source. Kept as is by decision (v37) |
| `mailpit` | axllent/mailpit | SMTP sink + web UI |
| `phpmyadmin` | phpmyadmin:5-apache | DB inspection |

Both PHP containers share one image and differ only by `DATABASE_URL` and
the mount. One build, two applications.

---

## 4. Fixtures

The suite is called `dach_demo` and is split across two files. Symfony
merges configuration for the same extension across files; the order
follows the alphabetical load order inside `config/packages/`:

1. `dach_demo.yaml` — locales, currencies, countries, zones, tax
   categories, tax rates, taxonomy, channels, admin user
2. `sylius_shipping_payment.yaml` — shipping methods, payment methods,
   products last

The order isn't cosmetic: products need existing channels and taxons,
shipping methods need zones. `dach_demo.yaml` loads before
`sylius_shipping_payment.yaml` (alphabetically, "d" < "s"), and Sylius'
own `_sylius.yaml` (not ours — see 2.1) loads before both thanks to the
leading underscore.

### Product fixture

`App\Fixture\RockbandProductsFixture` lives in `src/Fixture/`. The
namespace `App\Fixture` resolves through the standard `App\` → `src/`
autoload — no special case needed in `composer.json` (see FIXES.md No. 5,
where exactly this was different in an earlier version and caused
problems).

The fixture is wired **not** through `config/services.yaml`, but through
`#[Autowire(service: '...')]` attributes right on each constructor
parameter, using service IDs verified against Sylius 2.2.8 (see FIXES.md
No. 8 for the bug history that led to this approach). That means: no
service definition block anywhere that a later configuration file could
silently override.

The fixture is idempotent: existing products are skipped, a second run
doesn't create duplicates.

Three-tier image strategy (local → download → GD placeholder). A plain
`file_get_contents()` with no fallback — as sketched in the original spec
— would crash the setup as soon as one URL is dead. And at least one of
the given URLs does exactly that: the SM58 URL points at the same photo
ID (`1149254`) as the Les Paul URL, so it can't show both subjects.

Prices are integers in the smallest currency unit: `129900` = €1,299.00.
CHF prices are maintained separately rather than converted, because
Swiss retail prices rarely follow the daily exchange rate.

---

## 5. Checkout and test command

`app:create-test-orders` runs through the same state machine as the
browser:

```
cart → addressed → shipping_selected → payment_selected → completed
```

Payment is then set to `completed`. The shipping state stays at `ready` —
exactly like after a real checkout, before the goods leave the warehouse.

That makes the command a smoke test: if it fails, the browser checkout is
broken too, and the error message names the transition and the state it
reached. Most common cause: no active shipping or payment method for the
channel. `make doctor` shows both tables.

**The state machine is deliberately not overridden.** The graph is
documented as a comment in `dach_demo.yaml`, not actively configured. A
full override disables the built-in callbacks — shipment and payment
creation, stock deduction, confirmation email — and is the most common
cause of a broken checkout in Sylius projects.

**Guest checkout** is possible in Sylius by default; in practice it's
almost always blocked by the security configuration:

```yaml
access_control:
    - { path: "%sylius.security.shop_regex%/checkout", role: PUBLIC_ACCESS }
```

If that line says `ROLE_USER` instead, the guest gets forced into
registration. `install-apps.sh` checks for this and warns.

**Version note:** the command and the fixture use Sylius 2.x namespaces
(`Sylius\Abstraction\StateMachine\StateMachineInterface`,
`Sylius\Component\Order\Processor\OrderProcessorInterface`). Every
constructor type and its corresponding service ID was verified against a
running Sylius 2.2.8 install via `debug:container` — not guessed.
`make verify` checks the same IDs again on every install, in case a
future Sylius version changes them once more.

---

## 6. Sulu integration — implemented since version 6

Sulu and Sylius have been connected through a real data integration since
version 6, not just through URL structure. Three options were on the
table, in ascending order of effort:

**a) Link level.** Sulu landing pages link to shop categories. Zero
integration effort, good enough for many agency projects.

**b) Sylius Shop API inside Sulu templates.** Sulu pulls product data
through the Sylius API and renders it in its own templates. This is the
path the official `sulu-sylius-showcase` takes. **Implemented** — see
`sulu-overlay/src/Service/SyliusShopClient.php` and
`sulu-overlay/templates/pages/rockband_landing.html.twig`. Reasoning
behind the deliberately conservative implementation: FIXES.md No. 18.

**c) BitBag SyliusSuluPlugin.** Brings Sulu content into the Sylius
admin. Useful when editors should have a single login; ties the two
systems' version states together in exchange. Not implemented —
unnecessary coupling for a demo.

**Build stages that (b) doesn't cover yet** (deliberately not implemented,
to avoid raising the risk of untested Sulu internals any further — see
FIXES.md No. 18 for the reasoning behind this boundary):

- An "add to cart" button directly on the Sulu page (would need cart
  token handling between the two systems)
- Product images in the featured-product tiles (Sylius' image-path shape
  in the Shop API couldn't be verified against a running instance at the
  time)
- Automatically setting the homepage template and content (Sulu's
  DocumentManager API — deliberately left as a manual two-minute step)
- Austria/Switzerland variants of the landing page (the Caddy router
  currently doesn't distinguish by hostname, see the comment in
  `sulu-overlay/templates/base.html.twig`)

*Update since version 8:* the headless rebuild replaced (b) with a much
deeper integration — see section 6a below.

---

## 6a. Headless rebuild — implemented since version 8

At the user's explicit request, Sylius now runs **fully headless**.
Its own shop frontend is switched off (Caddy redirects `/shop/*` to the
Sulu homepage; Sylius admin runs on its own port, see section 6b); the
entire visible catalog runs
in Sulu as an ordinary Symfony controller with its own routes
(`sulu-overlay/src/Controller/CatalogController.php`), not as Sulu page
documents — categories and products are machine-generated content from
Sylius, and Sulu's editorial page tree is the wrong tool for that.

**Chosen architecture: Sulu's PHP backend as proxy (option A over
option B).** Two options were weighed:

- **A) Sulu's backend proxies everything.** The browser never sees a
  Sylius URL; every cart/checkout action goes through a server-side call
  to Sylius (using the same Host-header trick from FIXES.md No. 15).
  Sulu's session would hold the Sylius cart token.
- **B) Client-side JavaScript talks to Sylius directly.** Sulu only
  serves the page shell; cart logic runs via fetch/AJAX against
  `/shop/api/v2/shop/...`. Less PHP code, but needs CORS configuration
  and exposes the Sylius API shape in the browser.

A was chosen and implemented for read access (catalog); it remains the
intended direction for cart/checkout, not yet built as of version 10.

**Every API quirk here is verified against real responses from the
running instance, not assumed from documentation** — see FIXES.md No. 23
for the full list (the correct taxon filter parameter, product identity
via `code` rather than `slug`, embedded price/stock data, etc.) and the
staged build plan for cart and checkout (No. 23 as well).

---

## 6b. Sylius admin on its own port

Sylius admin runs on a dedicated port (`:8082`, unprefixed), not under
`/shop/admin/` as in earlier versions. Reason: both Sulu and Sylius build
their admin frontend assets under the identical path `/build/admin/` —
two entirely independent build outputs sharing one URL prefix, with no
way to route that by path alone. Confirmed empirically, not assumed:
Sulu serves `/build/admin/main.<hash>.css`, Sylius serves
`/build/admin/admin-entry.css`, both real, both physically present in
their respective containers. See FIXES.md No. 28 for the full diagnostic
path and why a Caddy path rule couldn't solve this (unlike every other
path-based routing decision in this project).

---

## 7. Bundle strategy

A dedicated bundle only pays off once code is needed in **at least two**
projects. Until then: `src/`.

- Bundle-worthy: DACH tax logic, Swiss customs and shipping rules, a
  reusable Sulu-Sylius bridge, cookie-consent integration.
- Not bundle-worthy: demo fixtures, project-specific templates, this test
  command.

Rule of thumb: build it in the project first, extract on the second need.
A bundle extracted too early costs more upkeep than it saves.

---

## 8. GDPR checklist (DACH)

**Already in place:**

- [x] Cart expiration after 3 days (Art. 5(1)(e) — storage limitation)
- [x] No outbound email (Mailpit catches everything)
- [x] No external payment gateways in demo mode
- [x] No tracking scripts, no CDN fonts
- [x] Gross prices per PAngV (German price indication law)

**Open before any real launch:**

- [ ] Legal notice: § 5 DDG (DE), § 5 ECG (AT), Art. 3 UWG (CH)
- [ ] Privacy policy naming every recipient and legal basis
- [ ] Cookie consent with **opt-in before** setting non-essential cookies
      (§ 25 TDDDG). A banner that already sets cookies on load is worthless.
- [ ] Right-of-withdrawal notice + model withdrawal form
- [ ] Terms and conditions
- [ ] Explicit "obligation to pay" button wording (§ 312j(3) BGB)
- [ ] Data processing agreements: hosting, payment provider, shipping
      provider, email delivery
- [ ] Record of processing activities (Art. 30)
- [ ] Deletion concept including retention periods. The 10-year retention
      requirement for invoices (§ 147 AO) sits opposite the deletion
      obligation — this tension needs to be documented.
- [ ] TLS in production, HSTS
- [ ] Switzerland: revDSG applies, differs from GDPR in several details

**Not legal advice.** Cease-and-desist letters over a faulty withdrawal
notice or cookie banner are routine business in Germany — a production
shop needs an actual legal review.

---

## 9. Next steps

Sorted by benefit per unit of effort:

1. **Run `make setup` end to end and log every error.** Composer may
   report conflicts on the first run — the exact messages are the
   foundation for everything after. Without them, any version number is
   a guess.
2. **Check fixture keys against the installed version:**
   `bin/console config:dump-reference sylius_fixtures`. Individual option
   names for the channel and payment fixtures changed between 1.13 and
   2.x.
3. **Click through checkout by hand once**, before running
   `make test-checkout`. The command covers the state machine, not the
   templates.
4. **Sulu landing page with product teaser** (approach 6b) — the actual
   moment that sells the combination in a demo.
5. **Cookie consent and legal texts**, as soon as the demo is publicly
   reachable. Even a demo behind a public URL needs a legal notice and
   privacy policy.
6. **Production hardening:** `APP_ENV=prod`,
   `opcache.validate_timestamps=0`, remove phpMyAdmin, stop mapping the
   MySQL port outward, real secrets via environment variables, TLS
   termination, Redis for sessions.

---

## 10. Known risks

| Item | Risk | Handling |
|---|---|---|
| Sulu 3.0 behavior not battle-tested in practice yet (young version) | medium | `make verify` checks core prerequisites; on new errors, check `composer show sulu/sulu` and the release notes first |
| Fixture option names are version-dependent | medium | `config:dump-reference sylius_fixtures`; result lands under `var/reference/` after `make verify` |
| Sylius assets under a subpath | medium | `/media/` and `/bundles/sylius*` are routed separately; add more here if other assets go missing |
| Worker mode disabled | low | Performance topic, not a functional one |
| Swiss customs and import tax | low for a demo | A real CH shipping setup needs its own project |
| Renewed Sulu/Symfony version drift on future updates | medium | `make verify` section 6 re-checks `symfony/proxy-manager-bridge` on every install |
| **Adyen has never been exercised against a real sandbox** | high until tested | No sandbox credentials existed as of this writing (FIXES.md No. 30/32). Every architectural fact is verified against real code, but the actual payment flow (widget rendering, a real test-card payment, the success/failure redirect) is unverified. Run `make payments-live` with real credentials and complete one real test payment before treating this as production-ready |
| Adyen's Drop-in widget loads Sylius' full compiled shop bundle (5.88 MB) | low, deliberate tradeoff | Chosen over building a separate slim asset pipeline (FIXES.md No. 32); only loaded when Adyen is the selected payment method, never on every checkout page |
| A Sylius update could silently break our two bundle-template overrides (order thank-you page, order confirmation email) | medium | `make verify` section 13 checks both original template paths still exist (FIXES.md No. 36) - a warning, not a guarantee the override itself still applies |
