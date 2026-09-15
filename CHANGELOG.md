# Changelog

All notable changes to this project, version by version. Each entry
links to the corresponding FIXES.md number for the full diagnostic
story — this file is the quick-reference summary, FIXES.md is the
detailed "why".

Format loosely follows [Keep a Changelog](https://keepachangelog.com/) -
adapted for an internal kickstarter project rather than a public
library, so there's no strict Added/Changed/Fixed split where it
wouldn't add value.

## [v34]

- Added: `make test-smoke` — 15 front-end tests checking that the pages
  a visitor actually sees still render what they should: category names,
  German-formatted prices, the add-to-cart form, nav and cart badge, and
  that the checkout guards redirect on an empty cart (FIXES.md No. 49)
- Added: the same suite verifies the Caddy routing, which nothing
  checked automatically before despite it having caused several real
  bugs — `/shop/` redirecting to the catalog, `/shop/admin` pointing at
  port 8082, the Sylius admin answering there, Mailpit reachable
- Corrected: FIXES.md No. 28 claimed `/admin/login` doesn't exist as a
  route in this Sylius version. It does and answers 200 — the 404 that
  led to that conclusion came from the request context used at the time
  (FIXES.md No. 49)
- Test suites are now: `make test` (no containers), `make
  test-integration`, `make test-smoke`, `make test-all` — 60 tests total

## [v33]

- Added: `make test-integration` and `make test-all` — 10 tests against
  the running Sylius Shop API, pinning down the facts a mock cannot
  verify (taxon filter, code-not-slug lookup, embedded price data, the
  full cart lifecycle, Swiss channel resolution). Kept separate from
  `make test` so the unit suite stays runnable without containers
  (FIXES.md No. 47)
- **Fixed a bug the new tests found immediately:** products only ever
  had German (`de_DE`) translations, so the Austrian and Swiss channels
  returned an empty shop through the API — configured, enabled, priced,
  and completely empty. Broken since v5, unnoticed because the
  storefront hard-codes the German channel. Caused by
  `setFallbackLocale('de_DE')` inside the per-locale loop, which made
  all three iterations write into the same translation (FIXES.md No. 48)
- Changed: `make phpstan` now covers `tests` as well as `src` on the
  Sulu side, and — importantly — runs `make sulu-theme` first. It had
  been analysing whatever was left in `./sulu` from an earlier run,
  which quietly meant results about the wrong version of the files
  (FIXES.md No. 47)

## [v32]

- Added: `make test` and the first automated tests — 35 unit tests
  covering `SyliusShopClient`, `CartManager` and `ShopExtension`. No new
  dependencies needed; PHPUnit was already present in both apps
  (FIXES.md No. 46)
- What they pin down: cents-to-euro conversion, collection unwrapping
  from `hydra:member` *or* `member`, price extraction from
  `defaultVariantData`, channel selection via the Host header,
  empty-result-instead-of-exception on API failure, and the cart token's
  session lifecycle
- Note: these are part A of three. Integration tests against a running
  Sylius API (B) and front-end smoke tests (C) are still to come

## [v31]

- Fixed: both Sylius template overrides were placed in
  `sulu-overlay/templates/bundles/` and therefore copied into the **Sulu**
  app, where nothing reads them — Symfony resolves bundle overrides
  relative to the rendering application, and both of these are rendered
  by Sylius. Moved to `templates/bundles/` in the project root
  (FIXES.md No. 45)
- Consequence: the dead "view order / change payment method" link in the
  order confirmation mail (supposedly removed in v20) was still being
  sent and produced a 404 when clicked. The Adyen thank-you redirect
  from v20 was equally inert, just never exercised
- Added: `verify.sh` section 16 — fails if any Sylius bundle override
  turns up under `sulu-overlay/`, naming the offending files. The
  existing section 13 checked that Sylius' *original* templates still
  exist, but never that our overrides sit where Sylius would look
  (FIXES.md No. 45)

## [v30]

- Changed: the Sulu webspace now declares German as its content
  localization instead of the skeleton's English — the admin language
  switcher showed "en" on a DACH storefront whose content is entirely
  German. Shipped as an overlay file
  (`sulu-overlay/config/webspaces/website.xml`) so it survives every
  rebuild; `SeedHomepageCommand` updated to match (FIXES.md No. 44)
- Added: `verify.sh` section 15 — checks the webspace localization and
  the seed command's locale against each other, and that the webspace's
  `<url>` tags cover the declared localization. A mismatch would store
  the homepage in a locale nobody can reach, with no error anywhere
  (FIXES.md No. 44)
- Note: this changes the **content** locale only. Admin interface labels
  follow the logged-in Sulu user's own UI language setting — the page
  template already ships German and English labels for every field
- **Requires `docker compose down -v` before setup:** content is stored
  per locale, so a homepage previously created under "en" won't appear
  under "de"

## [v29]

- Fixed: a real bug from the v27 rollback — the Sulu homepage template's
  `<view>` tag still pointed at the old `pages/landing` name after the
  Twig file itself had been renamed back to `rockband_landing`, causing
  a Sulu preview exception ("Page does not exist in 'html' format")
  whenever the homepage was opened in the admin (FIXES.md No. 43)
- Added: `verify.sh` section 14 — three generic cross-reference checks
  (Sulu template `<view>` → Twig file, controller `render()` → Twig
  file, Twig `path()` → defined route name) that would have caught the
  bug above automatically. Each re-derives what should exist from the
  actual source rather than a fixed file list, so they keep working as
  files are renamed later (FIXES.md No. 43)

## [v28]

- No functional changes to the shop itself — v27 was tested internally
  and not released (see below); v28 picks up where v26 left off
- Documented: `docker-compose.yaml`'s fixed project name means every
  versioned folder shares the same Docker volumes unless explicitly
  removed with `down -v` — found while diagnosing an unrelated caching
  question (FIXES.md No. 42)

## [v26]

- Fixed: the Sulu overlay classes were **not** actually passing
  PHPStan's `max` level, contrary to what v25 claimed — the check that
  produced that conclusion had run before the overlay files were copied
  into the Sulu app, so it analysed almost nothing. Real count: 77
  findings, now all fixed (FIXES.md No. 41)
- Changed: `SyliusShopClient` and `CheckoutController` now narrow API
  response values through small private helpers instead of casting
  `mixed` at every read site. Two of these changes alter real behavior,
  not just types: `extractCollection()` filters out non-array entries,
  and the checkout controller skips API calls when a required id or
  token is missing rather than passing a blind cast (FIXES.md No. 41)
- Verified: `make phpstan` clean on both sides, **and** a full checkout
  confirmed working in the browser — type-correctness alone wouldn't
  have proven the flow still runs

## [v25]

- Added: `make phpstan` — static analysis of this project's own PHP
  code. PHPStan turned out to be already installed in both apps as a
  transitive dev dependency; only configuration and a Make target were
  needed (FIXES.md No. 40)
- Fixed: three real PHPStan findings in `RockbandProductsFixture.php` —
  a wrong `TaxonInterface` import (`Taxonomy\Model` where Sylius expects
  `Core\Model`) causing two findings, and an always-true
  `method_exists()` guard left over from earlier API uncertainty
  (FIXES.md No. 40)
- Fixed: `make doctor`'s payment- and shipping-method queries still used
  the wrong column name `enabled` instead of `is_enabled` — the same bug
  fixed in `payments-demo`/`payments-live` in v17, but missed in
  `doctor` at the time (FIXES.md No. 40)
- Documented: the two remaining gaps for long-lived maintenance (no
  automated tests, no CI pipeline) are now named explicitly in
  README.md's "For development teams" section

## [v24]

- Fixed: a stale claim in FIXES.md No. 23 said landing-page product
  tiles were "deliberately not clickable" — true in v8, but outdated
  since the catalog build stage shipped; corrected to reflect current
  behavior (FIXES.md No. 39)
- Added: `CLAUDE.md`'s "Known risks" table hadn't been updated since
  v14 — added the three most relevant missing rows, most notably that
  Adyen has never been tested against a real sandbox (FIXES.md No. 39)

## [v23]

- Removed: the Pixabay product-image download tier entirely — confirmed
  in No. 35 that it reliably fails with `403 Forbidden`, so rather than
  leave permanently-dead code in place, `download-demo-images.sh`, the
  `make demo-images-download` target, and the corresponding fixture
  option were all deleted outright (FIXES.md No. 38)
- Fixed: a genuine duplicate fixture configuration found along the way -
  `rockband_products` options were defined identically in both
  `dach_products.yaml` and (incorrectly) `sylius_shipping_payment.yaml`;
  the stray copy is now gone (FIXES.md No. 38)
- Fixed: the placeholder icons from v20 looked broken in practice (a
  screenshot showed the guitar icon reading as an unrecognizable blob) -
  never actually rendered and looked at before shipping, only
  hand-calculated. Rebuilt using Python/PIL as a visual prototyping tool
  (unlike PHP/GD, actually available in the working environment),
  iterated until each of the five icons was clearly recognizable, then
  translated to GD and cross-checked once more before shipping
  (FIXES.md No. 37)

## [v22]

- Added: `verify.sh` now warns if the two Sylius templates our bundle
  overrides shadow (order thank-you page, order confirmation email) are
  missing from their expected vendor path - an early-warning sign that a
  future Sylius update silently broke one of our overrides, the same
  failure mode already seen once with `EnableFlushStamp` (FIXES.md
  No. 36)

## [v21]

- Added: `CHANGELOG.md` - this file. A quick, version-by-version summary
  linking back to the detailed FIXES.md entry for each change

## [v20]

- Fixed: Adyen's hard-coded success redirect (`/order/thank-you`) is now
  routed through Caddy and overridden to forward immediately to our own
  confirmation page instead of Sylius' generic one (FIXES.md No. 33)
- Fixed: the `/shop/admin` redirect no longer hard-codes `localhost` -
  uses Caddy's `{host}` placeholder, works on any server (FIXES.md
  No. 34)
- Changed: order confirmation emails now use a project-specific sender
  instead of Sylius' `no-reply@example.com` default (FIXES.md No. 34)
- Removed: the dead "view order / change payment method" link in the
  order confirmation email, which pointed at Sylius' own switched-off
  shop frontend (FIXES.md No. 34)
- Changed: the generated product-image placeholder now draws a simple,
  category-specific icon (guitar/amp/drums/microphone/pedal) instead of
  plain text on a grey box (FIXES.md No. 35)
- Documented: dropping a JPEG into `var/demo-images/<code>.jpg` already
  overrides both the Pixabay download and the placeholder — this
  existing capability is now documented in README.md (FIXES.md No. 35)
- Confirmed: Pixabay's product-image URLs return `403 Forbidden` and are
  not being fixed automatically - no reliable, network-reachable
  replacement image source was found (FIXES.md No. 35)

## [v19]

- Fixed: buttons now show a pointer cursor on hover - was missing on
  real `<button>` elements (`<a>` links already had it by default)

## [v18]

- Added: Adyen's Drop-in widget is now embedded on the checkout payment
  page. Loads Sylius' own compiled shop bundle
  (`public/build/app/shop/app-shop-entry.js`, 5.88 MB) rather than a
  separate build pipeline or Adyen's own CDN script (FIXES.md No. 32)
- Changed: when Adyen is the selected payment method, our own "place
  order" button is replaced by the widget's own "Pay" button - the
  widget drives the entire payment completion itself, bypassing our
  `completeCheckout()` (FIXES.md No. 32)

## [v17]

- Fixed: `make payments-demo`/`make payments-live` used the wrong
  database column name (`enabled` instead of the actual `is_enabled`) -
  corrected after checking the real schema via `information_schema`
  (FIXES.md No. 31)

## [v16]

- Added: real payment gateway backend for Adyen (chosen for European
  data sovereignty) - one payment method (Adyen bundles PayPal/Klarna/
  card selection inside its own widget, so a single entry is correct,
  not three), disabled by default, all config keys verified against the
  installed plugin's own code (FIXES.md No. 30)
- Added: `make payments-demo` / `make payments-live` - a one-command
  switch between demo and live payment methods, built on Sylius' own
  existing per-method enabled flag rather than a new custom admin
  screen (FIXES.md No. 30)
- Added: Caddy routes for the Adyen-specific paths the browser needs to
  reach directly (PCI compliance - card data never passes through our
  own server) (FIXES.md No. 30)

## [v15]

- Fixed: `verify.sh`'s Caddyfile check still looked for a `/shop/`
  routing structure removed in v14, causing `make setup` to fail after
  a correct v14 install; also fixed the resulting incomplete Git commit
  this caused (FIXES.md No. 29)

## [v14]

- Fixed: Sylius admin moved to its own port (`:8082`), unprefixed. Sulu
  and Sylius were both building their admin frontend assets under the
  identical path `/build/admin/` - a real namespace collision, not
  fixable with a Caddy path rule (FIXES.md No. 28)

## [v13]

- Added: full checkout flow (address → shipping → payment → summary →
  confirmation), verified step by step against the running Shop API
  (FIXES.md No. 27)
- Added: every configured payment method is genuinely selectable at
  checkout, not just the one Sylius auto-assigns as a pricing preview -
  confirmed by completing an order with Klarna instead of the default
  PayPal (FIXES.md No. 27)

## [v12]

- Added: shopping cart, headless end to end. Sylius' cart token lives
  exclusively in Sulu's own session, never exposed to the browser
  (FIXES.md No. 26)
- Confirmed: adding a cart item needs `Content-Type: application/ld+json`,
  changing its quantity needs `application/merge-patch+json` - different
  content types for the same resource (FIXES.md No. 26)

## [v11]

- Changed: all documentation and code comments translated to English.
  Shop-facing content (product names, DACH UI text, `/produkte/` URL
  segments) deliberately kept in German - this is a DACH storefront, not
  developer-facing text

## [v10]

- Added: local Git repository (`make git-init`) and a "for development
  teams" section in the README - vendor code vs. custom code separation
  documented explicitly (FIXES.md No. 25)

## [v9]

- Verified: a full from-scratch `make setup` run in a clean folder
  completed without incident - established as the clean reference state
  going forward

## [v8]

- Changed: Sylius now runs fully headless. Its own shop frontend is
  switched off; the entire storefront (categories, product pages) is
  rebuilt as Sulu-native routes calling the Sylius Shop API internally
  (FIXES.md No. 23)
- Architecture decision: Sulu's PHP backend acts as a proxy for
  everything (option A) rather than client-side JavaScript talking to
  Sylius directly (option B) (FIXES.md No. 23)
- Confirmed via live API calls: the taxon filter parameter is
  `productTaxons.taxon.code` (not `taxon`, which is silently ignored);
  products are addressed by `code`, not `slug`; price/stock data is
  already embedded in product responses (FIXES.md No. 23)
- Identified (not a code bug): switching between package versions in
  the same, incrementally-patched working directory can leave files at
  inconsistent patch states - a fresh folder per version is the
  reliable pattern from here on (FIXES.md No. 24)

## [v7]

- Fixed: the Sulu template integration now uses only values verified
  directly against Sulu 3 source code from the start, not old Sulu 2.x
  knowledge (FIXES.md No. 19–22)

## [v6]

- Added: Sulu landing page rendering live product data from the Sylius
  Shop API, connected via the same internal Host-header mechanism
  discovered in No. 15 (FIXES.md No. 18)
- Found and partially fixed: Sulu 3 rearchitected page management
  entirely compared to 1.x/2.x - no more PHPCR/DocumentManager API,
  field type is `route` not `resource_locator`, controller is
  `ContentController` not `DefaultController`, Twig variables live
  under `content.*`, and `EnableFlushStamp` is required for any write to
  actually persist (FIXES.md No. 19–22, fully corrected in v7)

## [v5]

- Fixed: `make setup` now actually covers Sulu installation end to end -
  a previous session had built `make sulu-install` but never shipped it
  as part of a patch (FIXES.md No. 17)

## [v4]

- Changed: nginx replaced by Caddy as the router entirely. Two different
  web server technologies in one stack (nginx routing, Caddy inside
  every PHP container via FrankenPHP) was the root inconsistency behind
  No. 15's bug class - Caddy's `reverse_proxy` doesn't have nginx's
  header-inheritance problem structurally (FIXES.md No. 16)

## [v3.4]

- Fixed: nginx's `proxy_set_header` directives are replaced per
  `location` block, not inherited from `server` - a block adding just
  one header (`X-Forwarded-Prefix`) silently lost the `Host` header,
  breaking Sylius' channel detection. Root cause of what looked like a
  channel configuration problem but wasn't (FIXES.md No. 15)

## [v3.3]

- Fixed: the Symfony skeleton's global `locale: en_US` default in
  `config/parameters.yaml` was overriding the correctly-configured
  Sylius channel locales for the very first redirect, before a channel
  was even determined (FIXES.md No. 14)

## [v3.1]

- Fixed: fixture fields `translations` and `rules` no longer exist on
  the installed Sylius version's shipping/payment example factories -
  found by reading the factory source directly, not by guessing
  (FIXES.md No. 11)
- Fixed: `setMetaTitle()` doesn't exist on this Sylius version's Product
  model (FIXES.md No. 12)
- Fixed: Sulu 3.0 splits admin/website into separate consoles;
  `sulu:build dev` alone handles schema, PHPCR init, search index, and
  admin user creation (FIXES.md No. 13)

## [v3]

- Fixed: Sulu 2.6 required a Symfony bridge package incompatible with
  Symfony 7.4, causing an actual infinite recursion in generated
  container code (not just a memory-tuning issue) during `cache:clear`.
  Switched to Sulu `^3.0`, which removed the incompatible dependency
  entirely (FIXES.md No. 10)

## [v2.1]

- Fixed: the Makefile and `docker/` directory were being overwritten by
  Sylius' own installer - a hand-maintained stash list missed them.
  Replaced with a full pre-install snapshot/restore instead (FIXES.md
  No. 9)

## [v1]/[v2] — initial delivery and first correction round

- Fixed: `docker/` was missing from the delivered archive entirely -
  now shipped as a single `.tar.gz` (FIXES.md No. 1)
- Fixed: a stray `compose.yml` from Sylius Standard silently took
  precedence over `docker-compose.yaml` (FIXES.md No. 2)
- Fixed: our own `_sylius.yaml` collided with and overwrote Sylius'
  actual core config file of the same name - renamed to `dach_demo.yaml`
  (FIXES.md No. 3)
- Fixed: `sylius_locale.locale` and `sylius_currency.currency` no longer
  exist as configuration keys in this Sylius version (FIXES.md No. 4)
- Fixed: the product fixture's autoload path didn't match its actual
  location - moved to `src/Fixture/`, the standard `App\` autoload path
  (FIXES.md No. 5)
- Fixed: `Doctrine\Persistence\ObjectManager` has no service alias -
  type-hinted `EntityManagerInterface` instead (FIXES.md No. 6)
- Fixed: `OrderProcessorInterface`'s namespace had moved between Sylius
  versions - removed the unnecessary type hint entirely rather than
  guess at the new path (FIXES.md No. 7)
- Fixed (root cause of four earlier failed attempts): service
  definitions placed in `config/packages/` were being silently
  overridden by `config/services.yaml`, which loads later. Switched to
  `#[Autowire]` attributes directly on constructor parameters (FIXES.md
  No. 8)
