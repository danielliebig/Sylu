# FIXES.md — What was corrected since Version 1

Eight failed attempts, eight root causes. This documents what went wrong
and what the current package does about it — so it can be traced, and so
the fixes don't get lost again.

**Verified target environment:** Sylius 2.2.8, Symfony 7.4.16, PHP 8.3.33,
Doctrine ORM 3.6.8, Docker arm64.

---

## 1. `docker/` was missing entirely

**Error:** `lstat /Users/.../docker: no such file or directory`

**Cause:** Files were downloaded individually, which lost the folder
structure. Two filenames were also mangled in the process
(`_sylius.yaml` → `sylius`, `conf.d/app.ini` → `app`).

**Now:** Delivered exclusively as a `.tar.gz` with the full structure
intact.

---

## 2. Two compose files

**Error:** `Found multiple config files ... Using compose.yml` →
`no such service: sylius`

**Cause:** Sylius Standard ships its own `compose.yml`. Without `-f`, it
takes precedence over `docker-compose.yaml`.

**Now:**
- The Makefile and every script call `docker compose -f docker-compose.yaml`
- `install-apps.sh` moves Sylius' compose files into `.sylius-original/`
- `verify.sh` checks for collisions

---

## 3. `_sylius.yaml` got overwritten

**Error:** `Invalid configuration for path
"framework.workflows.workflows.sylius_payment": "supports" or
"support_strategy" should be configured.`

**Cause:** Sylius 2.x ships its own `config/packages/_sylius.yaml` with
the workflow definitions. My file of the same name displaced it, because
`install-apps.sh` used `cp -rn` (no-clobber) — my file was there first
and won.

**Now:**
- My file is called `dach_demo.yaml` and collides with nothing
- `install-apps.sh` uses an **overlay instead of no-clobber**: stash
  custom files → copy Sylius in fully → restore custom files
- `verify.sh` checks that `_sylius.yaml` is Sylius' original

Load order stays correct because it's alphabetical:
`_sylius.yaml` → `dach_demo.yaml` → `dach_products.yaml` →
`sylius_shipping_payment.yaml`.

---

## 4. `sylius_currency.currency` no longer exists

**Error:** `Unrecognized option "currency" under "sylius_currency".
Available options are "driver", "resources".`

**Cause:** Sylius 2.x cleaned up its configuration trees. Currency and
locale now come exclusively from the channel.

**Now removed:** `sylius_locale.locale`, `sylius_currency.currency`.
Also removed: `sylius_order.expiration` — the key was unverified, and
there was no appetite to send anyone into failed attempt number nine. For
production, revisit this for GDPR data minimization — see `CLAUDE.md`.

---

## 5. Missing autoload entry for `fixtures/`

**Error:** `class "App\Fixture\RockbandProductsFixture" does not exist`

**Cause:** The fixture lived in `fixtures/`, matching the original spec.
The `composer.json` entry needed for that was never written, because
`install-apps.sh` had already failed at point 2.

**Now:** The fixture lives in **`src/Fixture/`**. The namespace
`App\Fixture` stays unchanged, because `App\` → `src/` is already the
standard. No autoload special case, no maintenance risk on Sylius
updates.

This deviates from the original path spec. That special case cost an
entire failed attempt and could have struck again on every update.

---

## 6. `ObjectManager` not resolvable

**Error:** `argument "$manager" references interface
"Doctrine\Persistence\ObjectManager" but no such service exists`

**Cause:** There's no service alias for the generic interface.

**Now:** Type-hint on `Doctrine\ORM\EntityManagerInterface`, service ID
`@doctrine.orm.default_entity_manager`.

---

## 7. `OrderProcessorInterface` had moved

**Error:** The class check reported
`Sylius\Component\Core\OrderProcessing\OrderProcessorInterface` → MISSING

**Cause:** A namespace move within Sylius 2.x. The new FQCN couldn't be
verified.

**Now:** The parameter in the command is **untyped** (`object`). Since
`autowire` is off for the class and the service is passed explicitly via
`@sylius.order_processing.order_processor`, the class name isn't needed
at all. That removes the dependency on the FQCN entirely.

Ten out of eleven checked classes were correct, by the way — the
substance of the fixture and command matched Sylius 2.2 the whole time.

---

## 8. Service definition in the wrong place — the root cause

**Error:** `Cannot autowire service "App\Fixture\RockbandProductsFixture":
argument "$projectDir" ... is type-hinted "string", you should configure
its value explicitly.`

**Cause — and this is the core issue that four rounds missed:** Symfony
loads `config/services.yaml` **after** every file in
`config/packages/`. Sylius' `App\` auto-registration there overrides any
service definition coming from `config/packages/`. My `arguments` block
could structurally never take effect — no matter what was in it.

**Now:** The definitions live in `config/services_kickstarter.yaml` and
`install-apps.sh` appends them to the **end of `config/services.yaml`**,
with `autowire: false` and `autoconfigure: false`. Idempotent — running
it repeatedly doesn't append them twice.

---

## 9. Makefile and `docker/` overwritten by Sylius *(fixed in v2.1)*

**Error:** `make docker-start` no longer exists.

**Cause — a bug introduced while fixing No. 3:** to save Sylius'
`_sylius.yaml`, `cp -rn` was replaced with `cp -r`. But the stash list in
`install-apps.sh` only included config and PHP files. `Makefile`,
`docker/`, `docker-compose.yaml` and the READMEs weren't on it — Sylius
Standard ships its own `Makefile` and overwrote mine.

**Now:** No more hand-maintained list. Before installing Sylius, the
script takes a **snapshot of every existing file** (excluding `vendor/`,
`node_modules/`, `sulu/`, `var/`, `.env.docker`). Whatever was there
before belongs to us and gets restored afterward. One exception:
`config/packages/_sylius.yaml` is explicitly deleted from the snapshot,
so Sylius' original wins.

Tested with a simulation: the Makefile and `docker/` come back,
`_sylius.yaml` stays Sylius'.

**Manual repair, if this already happened to you:**

```bash
mkdir -p .sylius-original && cp Makefile .sylius-original/Makefile.sylius
tar xzf ~/Downloads/sulu-sylius-kickstarter-v2.tar.gz \
    ./Makefile ./docker-compose.yaml ./README.md ./CLAUDE.md ./FIXES.md \
    ./.gitignore ./.env.docker.example ./docker
chmod +x docker/scripts/*.sh
```

---

## 10. Sulu 2.6 + Symfony 7.4: infinite loop inside the container (the big one) *(fixed in v3)*

**Error:** `Fatal error: Allowed memory size of ... exhausted` during
`cache:clear` for Sulu — reproducible at 1.5 GB, 6 GB, and (unwitnessed)
presumably at any value. Container memory usage climbed to the Docker
Desktop limit (11.67 GB) and then got killed by the kernel (exit code
137).

**Cause:** `sulu/sulu` version 2.6.25 requires
`symfony/proxy-manager-bridge (^5.4 || ^6.0)` for an internal dependency —
a package deprecated since Symfony 5.4 that only exists up to Symfony
6.4. The rest of Sulu's own `composer.json` allows Symfony up to `^7.0`.
Composer therefore resolved most of the install to Symfony 7.4.16 and
left only `symfony/proxy-manager-bridge` on the last compatible version,
6.4.28 — **two Symfony generations in the same install**.

The compiled result: the generated getter for
`sulu_core.proxy_manager.configuration` contains a self-referencing
fallback —

```php
new \ProxyManager\FileLocator\FileLocator(
    ($container->privates['sulu_core.proxy_manager.configuration']
        ?? $container->load('getSuluCore_ProxyManager_ConfigurationService'))
    ->getProxiesTargetDir()
);
```

The service tries to instantiate itself in order to obtain its own
argument. The `??` fallback calls itself again — a genuine infinite
recursion built directly into the generated code. More RAM only delays
the crash.

**Diagnostic path that led to the cause** (useful for similar cases):
1. `timeout 30 php -d memory_limit=1500M ... -vvv` → fatal error with a
   stack trace instead of a silent SIGKILL
2. Repeated the same test with 6144M → same getter, no termination →
   proof of a genuine loop, not just a size issue
3. `sed -n "1,40p"` on the generated cache file → the self-reference
   directly visible
4. `composer why symfony/proxy-manager-bridge` → `sulu/sulu 2.6.25
   requires symfony/proxy-manager-bridge (^5.4 || ^6.0)`
5. Research confirmed: Sulu 3.0 removed ProxyManager entirely and is
   officially supported on Symfony 6.4–7.4 — Sulu 2.6 is not.

**Now:** `SULU_VERSION` in `install-apps.sh` is set to `^3.0` instead of
`^2.6`. `make verify` now explicitly checks that
`symfony/proxy-manager-bridge` is **not** installed — if it shows up
anyway, that's the early-warning sign for exactly this problem, long
before the first `cache:clear` call fills up memory.

**Side lesson on Composer:** `composer require symfony/symfony:6.4.*` is
the wrong command to pin an app to a Symfony version — the
`symfony/symfony` metapackage sits under a `conflict` entry against the
root `composer.json` in modern skeletons (which prevents exactly this
trap: installing an outdated monolithic package). Individual components
(`symfony/framework-bundle:6.4.*` etc.) would have been the right
approach — but in this case it would still have been the wrong path,
because the app itself requires `^7.4`
(`symfony/web-profiler-bundle ^7.4`, `symfony/monolog-bundle ^4.0` with
`symfony/dependency-injection ^7.3`). A downgrade would have worked
against the skeleton's actual intent. The correct fix was the Sulu
version, not the Symfony version.

---

## 11. Fixture fields "translations" and "rules" no longer exist *(fixed in v3.1)*

**Error:** `Unrecognized option "translations" under
"shipping_method.custom.0"`, then `Unrecognized option "rules" under
"shipping_method.custom.2"` — after fixing the first error, the next one
appeared elsewhere.

**Cause:** `sylius_shipping_payment.yaml` was written against an older
Sylius fixture API. The installed version 2.2.8 uses
`ShippingMethodExampleFactory` and `PaymentMethodExampleFactory`
(`vendor/sylius/sylius/src/Sylius/Bundle/CoreBundle/Fixture/Factory/`),
and their `OptionsResolver` simply no longer knows a `translations` or a
`rules` field.

**Diagnostic path that led to the cause without guessing:**
1. The error message itself already lists the allowed options
   (`calculator, category, channels, code, description, enabled, name,
   tax_category, zone`) — enough for the first error.
2. `bin/console config:dump-reference sylius_fixtures` did NOT help: the
   reference only shows the generic suite/fixture/listener structure, not
   the fields of individual fixture types.
3. Direct search for the fixture classes:
   `find vendor/sylius -ipath '*fixture*' -iname '*shippingmethod*'`
4. `cat` on `ShippingMethodExampleFactory.php` and
   `PaymentMethodExampleFactory.php` — the `configureOptions()` method
   there is the authoritative, complete field list.

**Now:**
- `translations` removed. The factory sets `name`/`description` for
  every installed locale identically from the top-level fields — with
  de_DE as the default locale, one German text covers all three DACH
  locales.
- `rules` removed (conditional free shipping isn't expressible through
  this fixture factory). Replacement: after installation, create a rule
  "Order total >= €50" with action "Free shipping" under Marketing >
  Promotions in the admin — the intended path in Sylius 2.x.
- `position` removed from payment methods (the field doesn't exist; order
  follows the load order in the YAML list).

**General lesson for similar fixture bugs:** don't query
`config:dump-reference` (shows only the generic shell) — read the
`*ExampleFactory.php` class of the specific fixture directly, under
`vendor/sylius/sylius/.../Fixture/Factory/`. The `configureOptions()`
method there is always the authoritative source.

---

## 12. Sylius Product entity: `setMetaTitle` doesn't exist *(fixed in v3.1)*

**Error:** `Attempted to call an undefined method named "setMetaTitle" of
class "App\Entity\Product\Product"`.

**Cause:** My fixture called `setMetaTitle()` — a field that doesn't
exist on the `Product` model in this Sylius version. Verified against
`ProductTranslationInterface`: there's only `setMetaKeywords()` and
`setMetaDescription()`, no separate "meta title" field. The `<title>` tag
is generated from the product name, not maintained separately.

**Now:** The line is removed, `setMetaDescription()` stays (already
correctly placed inside the locale loop that sets translation fields).

**Practical lesson that cost time here twice:** a fix in my own working
copy changes nothing about the file that's actually sitting in the
running container. Every code change has to be actively shipped as a
patch or a complete archive and installed by the user — otherwise the
next attempt runs into the exact same error again.

---

## 13. Sulu 3.0: separate admin/website consoles, one command is enough *(fixed in v3.1)*

**Observation, not a bug:** `sulu:document:initialize` — the command that
initialized the PHPCR workspace in Sulu 2.x — no longer exists in 3.0.
`php bin/console list sulu` instead shows individual commands like
`sulu:page:initialize`, `sulu:security:init`, `sulu:media:init`.

**Cause:** Sulu 3.0 splits administration and website into two separate
Symfony kernel contexts with their own console scripts:
`bin/adminconsole` and `bin/websiteconsole`, in addition to the usual
`bin/console`. Piecing together individual init commands via
`bin/console` would have been the wrong path — they're building blocks of
a higher-level command.

**Found via the official documentation** (docs.sulu.io/3.x, "Getting
Started" chapter), not by trial and error with individual commands:

```bash
php bin/adminconsole sulu:build dev
```

A single command handles the database schema, PHPCR workspace init,
search index, and even creates the `admin`/`admin` user. The only
prerequisite is that the database itself exists
(`doctrine:database:create`).

**Now:** `make sulu-install` wraps exactly this one command and is part
of `make fixtures` (and therefore `make setup`) — no more manual
lookup needed on future installs.

**General lesson:** on a major version jump (2.x → 3.0), read the
official "Getting Started"/upgrade documentation for the target version
first, before piecing together individual command names from a `list`
output. Documentation knows about architectural changes (like the console
split here) that a plain command list doesn't reveal.

---

## 14. `config/parameters.yaml`: global `en_US` locale overrides channel config *(fixed in v3.3)*

**Error:** `http://localhost/shop/` redirected to `/shop/en_US/`, then:
`An exception has been thrown during the rendering of a template
("Channel could not be found!")`. The database was demonstrably correct
the whole time — `sylius_channel.default_locale_id` correctly pointed to
`de_DE` for all three DACH channels, `sylius_channel_locales` contained
no `en_US`.

**Cause:** `composer create-project sylius/sylius-standard` generates
`config/parameters.yaml` with `locale: en_US` by default. This global
Symfony parameter `%locale%` propagates to several places —
`kernel.default_locale`, `sylius_locale.locale`, `sylius_money.locale`,
`translation.default_locale` (confirmed via
`debug:container --parameters`). It governs the **first** redirect
(`/shop/` → `/shop/<locale>/`), **before** Sylius even determines a
channel from the hostname and its `default_locale_id` from the database
comes into play. The channel configuration was correct the entire time —
the global skeleton default just sat in front of it.

**Diagnostic path:**
1. `curl -sI http://localhost/shop/` shows `Location: /shop/en_US/` —
   identical with and without an `Accept-Language` header, ruling out
   browser/header-based language negotiation.
2. DB queries (`sylius_channel.default_locale_id`,
   `sylius_channel_locales`) show correct `de_DE` configuration — rules
   out a fixture bug.
   *(Side finding: `doctrine:query:sql` with a multi-line query or one
   starting with `SHOW` falsely reports "0 rows affected" — the query
   must start with `SELECT` on a single line, or the command doesn't
   recognize it as a query.)*
3. `grep -rn "en_US\|default_locale" config/` finds the hit in
   `config/parameters.yaml` — a file that comes straight from the
   Symfony skeleton and had never been touched manually.
4. `debug:container --parameters | grep locale` confirms the chain: the
   same value `en_US` shows up in six different parameters, all derived
   from `%locale%`.

**Now:** `install-apps.sh` sets `locale: de_DE` in
`config/parameters.yaml` automatically (step 5/6), `make verify` checks
it before every installation.

**General lesson:** for locale/language issues in Symfony apps, check the
global `%locale%` parameter in `config/parameters.yaml` first, before
looking in domain-specific configuration (here: Sylius channels). A
single skeleton default can override several seemingly independent
parameters at once.

---

## 15. nginx: `proxy_set_header` is replaced per `location`, not inherited *(fixed in v3.4)*

**Error:** `Channel could not be found!` — despite demonstrably correct
Sylius channel configuration (`default_locale_id` correct, `hostname`
correct, no `en_US` locale in the DB) and despite the fixed
`config/parameters.yaml` (see No. 14).

**Cause — a bug that had been in the project's very first `nginx.conf`
from the start:** nginx does **not** merge `proxy_set_header` directives
across configuration levels. As soon as a `location` block defines even a
single `proxy_set_header` line of its own (here: `X-Forwarded-Prefix` for
subpath routing), it discards every `proxy_set_header` directive it would
otherwise have inherited from the `server` block — including `Host`,
`X-Forwarded-Host`, `X-Real-IP`, etc. nginx then falls back to its own
default for `Host`: the name of the `upstream` block itself.

Concretely, Sylius received `sylius_upstream` as the host — literally the
name from `upstream sylius_upstream { server sylius:80; }` — instead of
`localhost`. Sylius' `HostnameBasedRequestResolver` naturally found no
channel for that, since no channel exists with `hostname =
'sylius_upstream'`.

**Diagnostic path that led to the cause** (after locale and DB
configuration were already ruled out):
1. `curl -sv http://localhost/shop/de_DE/ | grep Host` showed
   `> Host: localhost` — but that only checked the *client → nginx* hop,
   not *nginx → Sylius*. That check was misleading and sent things down
   the wrong path.
2. The decisive clue was in the Symfony log (`var/log/dev.log`), not in
   curl tests: `request_uri` showed
   `http://sylius_upstream/shop/de_DE/`, and the SQL query filtered on
   `hostname = 'sylius_upstream'`.
3. A timestamped marker in the log (`echo "MARKER $(date)" >> dev.log`,
   then `curl`, then `sed -n '/MARKER/,$p'`) made sure we were reading
   the log lines from the *current* request, not old entries from
   previous sessions — the log also contained a 20-second heartbeat from
   the Docker health check, which runs directly inside the container and
   bypasses the nginx layer entirely (which is why it never showed the
   error).
4. `HostnameBasedRequestResolver.php` and the composite context chain
   confirmed: the hostname value itself is what decides, not a session
   or locale mechanism.

**Now:** All shared headers live in a dedicated file
`docker/nginx/proxy_headers.conf` and get pulled into **every**
`location` block that uses `proxy_pass` via `include
/etc/nginx/proxy_headers.conf;` — even when that block needs extra
headers of its own. There's no server-wide `proxy_set_header` block left
that could silently disappear. `make verify` checks this automatically
(section 9): every `location` block with `proxy_pass` must contain either
the include or its own `Host` header line, or the check fails.

**General lesson:** nginx's inheritance rules for `proxy_set_header` (and
several other array-like directives such as `add_header`) are "all or
nothing" per context level, not additive. As soon as a `location` block
defines any one of these directives itself, it must bring **all** the
values it needs — most robustly through a shared `include` file rather
than copy-pasting.

---

## 16. nginx replaced by Caddy — this bug class structurally eliminated *(v4)*

**Not a bug fix, but a subsequent architecture correction**, made at the
user's explicit request after No. 15 was solved: *"But why nginx in the
first place? I wanted a FrankenPHP setup."*

**A fair point.** nginx was in the very first project spec and got
carried forward without questioning whether it fit a FrankenPHP stack. It
didn't fit well — and directly caused bug No. 15. FrankenPHP *is* Caddy
with embedded PHP. Two different web server technologies in the same
stack (nginx as router, Caddy inside every PHP container) means knowing
and maintaining two different behaviors around proxy headers — exactly
the kind of inconsistency that caused No. 15.

**Rebuild:** the `nginx` service is removed entirely. In its place runs a
slim `caddy:2-alpine` container (`router`) that only routes, runs no PHP.
Configuration lives in `docker/caddy/Caddyfile` instead of
`docker/nginx/nginx.conf` + `proxy_headers.conf`.

**Why this structurally eliminates the bug class from No. 15, rather than
just working around it:** Caddy's `reverse_proxy` directive forwards the
incoming `Host` header unchanged by default and sets
`X-Forwarded-For`/`-Proto`/`-Host` automatically — regardless of how many
extra headers (`X-Forwarded-Prefix`) a `handle` block needs. There's no
inheritance level that can be lost through an extra directive, because
Caddy treats headers additively instead of replacing them per block. The
only header still set manually is `X-Forwarded-Prefix` — that's
Sylius-specific, not something Caddy could cover automatically as
standard behavior.

**Routing differences from nginx, for completeness:**
- `handle_path /shop/*` corresponds to nginx's `proxy_pass .../;` with a
  trailing slash (the prefix is stripped before forwarding).
- `handle /media/*` and `handle /bundles/sylius*` (without `_path`)
  correspond to nginx's `location ^~ ...` without a trailing slash on the
  `proxy_pass` target (the full original path is preserved).
- `bundles/sylius*` covers `bundles/sylius`, `bundles/syliusadmin` and
  `bundles/syliusshop` in a single rule (all three share the "sylius"
  prefix), instead of needing three separate blocks like with nginx.
- WebSocket handling for Mailpit (`Connection: Upgrade`) needs no
  explicit `map` directive with Caddy, unlike nginx — `reverse_proxy`
  detects and handles it automatically.

**`make verify`** no longer checks for the nginx trap (which no longer
exists architecturally), only that `X-Forwarded-Prefix` is set in the
`/shop/` block of the Caddyfile — the one header Sylius genuinely needs
explicitly.

**General lesson:** adopting a project spec literally (here: "nginx")
without checking it against the rest of the chosen architecture can
create friction that only shows up much later as a hard-to-diagnose bug.
In a technology stack that already has a competent solution for a problem
(here: Caddy for routing, since FrankenPHP brings it along anyway), an
extra, different tool for the same job is a starting point for exactly
this kind of inconsistency bug.

---

## 17. `make setup` didn't cover Sulu — Makefile patch never shipped *(fixed in v5)*

**Not a Sylius/Sulu bug — a gap in my own delivery process.** Once it
became clear that `sulu:build dev` is the only Sulu install command
needed (No. 13), a `make sulu-install` target was added to the working
copy — but never shipped as a patch. Several subsequent patches (locale
fix, nginx→Caddy) deliberately contained only the affected files, never
the full Makefile. Result: `make sulu-install` failed for the user with
"No rule to make target", even though the functionality had long existed
in the working copy.

**Now:** `make fixtures` has automatically called `sulu-install` at the
end since version 5. `make setup` covers both systems end to end — no
more case where a Makefile target exists without being shipped.

**General lesson:** in an incrementally patched project, every change to
a shared file (here: `Makefile`) needs its own check for whether it's
included in the next patch — regardless of whether the file "really"
belongs to the current topic. A final, complete package (like this one)
is the most reliable way to close this class of gap: it contains every
file once, in full, instead of a history of partial patches.

---

## 18. Sulu-Sylius integration: landing page with live shop data *(new, v6)*

**Not a bug — an addition at the user's request**, once both systems ran
independently: "Build the integration between the two systems and make
it look good."

**Implementation follows CLAUDE.md section 6, option (b)** — the option
already recommended there, before implementation, as the most sensible
middle ground: Sulu pulls product data through the Sylius Shop API and
renders it in its own templates, instead of a shared login (option c) or
plain links with no real data integration (option a).

**Deliberate risk limiting, given how the day had gone:** two unknowns
couldn't be verified against a running instance — the exact Twig
variable binding of Sulu templates (particularly
`single_media_selection`) and the exact JSON schema of the Sylius Shop
API (image paths, price fields). Both were deliberately avoided rather
than guessed:

- **No image property in the Sulu template** — the hero comes from CSS
  alone (gradients), no dependency on an untested media-resolving
  mechanism.
- **Only `code` and `name` used from the Shop API response** — reliably
  stable across versions per Sylius' JSON-LD convention (`hydra:member`),
  verified through official Sylius documentation and reference examples
  (not pulled from memory).
- **Every API error results in an empty array, never an exception** —
  the page then shows six static fallback tiles instead of a 500. For a
  demo page, "always looks finished" matters more than "shows live data
  or nothing at all."

**The internal API connection uses the same Host-header mechanism that
No. 15 uncovered** — deliberately, not by coincidence. Sylius picks its
channel via the `Host` header (`HostnameBasedRequestResolver`); an
internal request to the Docker hostname `sylius` would find no channel on
its own. `SyliusShopClient` connects to the `sylius` container but sends
`Host: localhost` — exactly the mechanism earned through hours of
debugging in No. 15, deliberately reused here rather than reinvented.

**No `services.yaml` change needed:** both new PHP classes
(`SyliusShopClient`, `ShopExtension`) depend exclusively on
`HttpClientInterface` and `LoggerInterface` — both standard Symfony
interfaces that Symfony's autowiring resolves without any explicit
configuration. Exactly the pattern earned for the Sylius side in No. 8
only after several failed attempts, applied correctly here from the
start.

**The one step deliberately left unautomated:** which template the
homepage created by `sulu:build dev` gets, and with what content, could
only be set programmatically through Sulu's DocumentManager API — exactly
the kind of Sulu internals repeatedly gotten wrong earlier that same day
(`sulu:document:initialize` no longer existed, bundle namespaces had
changed). Rather than risk that untested, template selection stays a
two-minute manual step in the admin interface — a deliberate choice for
reliability over another round of trial and error.

---

## 19–22. Sulu 3: page architecture rebuilt from the ground up *(found and fixed in v6/v7)*

While trying to fill the homepage with content programmatically, it
turned out: **Sulu 3 rearchitected page management from scratch**, not
just renamed a few classes. Four connected findings, in the order they
surfaced during live debugging:

### 19. No more PHPCR/DocumentManager API for pages

Almost every Sulu example online (1.x/2.x documentation, most blog posts)
shows `DocumentManager::persist()`. In Sulu 3, that no longer exists for
pages — confirmed by an official GitHub discussion (sulu/sulu#8829):
*"In Sulu 3, pages are no longer created via the DocumentManager. You
should create/modify pages via messages […] and dispatch them through the
message bus."* Pages now live in ordinary Doctrine ORM tables
(`pa_pages`, `pa_page_dimension_contents`), verified via
`DESCRIBE`/`SELECT` against a real install — no longer in the PHPCR tree.

**Effect:** changes go through `ModifyPageMessage` / `CreatePageMessage`,
dispatched via a Symfony Messenger message bus, no longer through a
manager API with `persist()`/`flush()`.

### 20. The field type is `route`, not `resource_locator`

The admin UI error message itself listed the full set of valid field
types on the first attempt — `resource_locator` wasn't among them.
Sulu's own, demonstrably working
`config/templates/pages/homepage.xml` uses `type="route"`. That's not an
alias detail — it's Sulu's canonical field type for page URLs since
Sulu 3.

### 21. The controller is `ContentController`, not `DefaultController`

`Sulu\Bundle\WebsiteBundle\Controller\DefaultController` (carried over
from older examples) doesn't exist in this version —
`InvalidArgumentException: Controller … does neither exist as service nor
as class.` Again taken from `homepage.xml`, this time correctly:
`Sulu\Content\UserInterface\Controller\Website\ContentController::indexAction`.
Fits the pattern: Sulu 3 moved many core classes out of `Sulu\Bundle\*`
into new `packages/*` directories with new namespaces (see also No. 13,
the admin/website console split).

### 22. Twig variables live under `content.*`

Direct top-level variables (`{{ title }}`) as documented for Sulu 1.x/2.x
don't work in Sulu 3. Sulu's own `homepage.html.twig` shows the actual
access pattern: `{{ content.title }}`, `{{ content.article }}`. Every
template property sits under this namespace.

### Also required: `EnableFlushStamp`

Independent of the four points above: dispatching `ModifyPageMessage`
through `HandleTrait::handle()` ran **without errors, reported
success — but changed nothing in the database.** The handler ran to
completion, but without `flush()`. Only wrapping it in an `Envelope` with
`Sulu\Messenger\...\EnableFlushStamp` (comment in the source: *"Marker
stamp to enable DoctrineFlushMiddleware for envelopes with this
stamp"*) actually triggers the database write. Found not through an
error message, but through a direct `SELECT` against
`pa_page_dimension_contents` after a run that had reported success — the
command itself gave no hint of the problem. This is the most dangerous
kind of bug: no exception, just silent no-op.

### How these four findings came about — and why that's a lesson for the rest of the project

Unlike every Sylius service ID in this project, the Sulu template
integration in version 6 was initially **written from old Sulu 2.x
knowledge, without checking it against the real code** — a break from the
approach applied consistently everywhere else in this project (see No. 11,
15: "don't guess, read the source directly"). The result was four rounds
of correction for something that could have been right from the start
using the same approach used everywhere else in this project — the
decisive find (`ContentController`) ultimately came straight from
`homepage.xml`, not from memory.

**Since version 7, all four points are written correctly from the
start**, not patched afterward — `rockband_landing.xml`,
`SeedHomepageCommand.php` and the Twig templates consistently use the
values verified above. README.md, section "What's different in Sulu 3",
summarizes the table for anyone extending their own Sulu 3 templates.

---

## 23. Architecture shift: Sylius fully headless, frontend only in Sulu *(v8)*

**Not a bug fix — a deliberate requirement change** at the user's
request, once the landing page integration was running: instead of a
product preview on the homepage, **the entire shop frontend** should live
in Sulu — categories, product details, cart, complete checkout. Sylius
becomes a pure backend/API provider.

**Architecture decision: option A chosen.** Sulu's PHP backend acts as a
proxy for everything. The browser never sees a Sylius URL. Sulu's
Symfony session holds the Sylius cart token; every state-changing action
(cart, checkout steps) goes through a Sulu controller that calls the
Sylius Shop API internally — using the same Host-header mechanism
already working for the product preview (see No. 18).

**Sylius' own Twig frontend is switched off immediately**, not only at
the end of the rebuild. `docker/caddy/Caddyfile` redirects `/shop/*`
(except `/shop/admin/*`) to the Sulu homepage. This is deliberately
reversible: a commented-out original block sits right next to it in the
file. **Consequence explicitly stated to the user before making the
change:** from this point on, there is no working checkout at all until
it's rebuilt in Sulu (upcoming build stages). Acceptable for a demo
project with no real customers, would be a blocker for a production
system.

**What's unaffected by switching it off:**
- `/shop/admin/` stays reachable — product and order management continues
  through Sylius' admin UI, which isn't being rebuilt.
- The Sylius Shop API was never publicly reachable through the router —
  `SyliusShopClient` calls it exclusively container-internally. Switching
  off the Twig frontend doesn't change that.
- `/media/` and `/bundles/sylius*` stay routed — product images are still
  fetched directly from Sylius by the browser even in headless operation,
  that's plain asset hosting, not frontend logic.

**Sulu's own references to `/shop/de_DE/...` had to be updated too** —
header navigation, footer, and the landing page's product preview all
pointed there. At the time of this v8 change, standalone product pages
didn't exist yet, so the product tiles were deliberately left
unclickable rather than pointing at a URL that had just gone dead.
**Since the catalog build-stage below shipped, this no longer applies:**
the landing page's tiles link to `catalog_product` and work correctly —
this paragraph is kept for the historical record of the v8 state, not
as a description of current behavior.

**Staged build plan for the remaining stages** (in ascending order of
risk, each stage verified individually against the running instance
before the next begins — same discipline as No. 19–22):

1. Category/product listing pages (read-only)
2. Product detail pages (read-only)
3. Cart (the first stage that's stateful, cart-token handling)
4. Checkout: address → shipping → payment method → summary (multi-step)
5. Real payment processing (redirects for PayPal/Klarna — likely the
   most complex stage)

**Why the Shop API field structure was checked via a live query this
time, not a web search:** a web search for `/api/v2/shop/taxons` and
`/api/v2/shop/products?taxon=` returned mostly Sylius 1.x material and
GitHub issues of unclear version vintage — after the experience of
No. 19–22, not a reliable basis for writing code. Instead: `curl`
directly against the running Sylius instance (using the same Host-header
trick), fields taken from the real response, not from search results.

**Concretely verified, via a real API response instead of assumption:**

- The taxon filter for product lists is called
  **`productTaxons.taxon.code`**, not `taxon` — the latter is accepted
  syntactically by the API but ignored, returning
  `hydra:totalItems: 0`. The API itself reveals the correct name in the
  `hydra:search` field of every collection response.
- Products are only retrievable through their **`code`**
  (`/api/v2/shop/products/{code}`), not through `slug` — a direct call
  with the slug returned 404, `?slug=` as a query filter was silently
  ignored (not a registered filter). Catalog URLs in Sulu therefore
  deliberately use the `code`
  (`/produkte/guitars/fender_stratocaster`), no extra slug-to-code
  lookup.
- Price and stock data (`defaultVariantData.price`, `.inStock`) are
  already embedded in the product resource — both individually and in
  collections. No extra request per product needed, for either the
  listing or the detail page.
- Image paths in `images[].path` are already complete, directly usable
  URLs (`http://localhost/media/cache/resolve/...`).

**Catalog architecture deliberately outside Sulu's page tree:** categories
and products are machine-generated, data-driven content from Sylius —
for that, an ordinary Symfony controller with its own routes
(`CatalogController.php`, PHP attributes instead of a YAML route file)
is the right tool, not one Sulu page document per product. It still
renders into the shared `base.html.twig` layout, so catalog and CMS
pages look no different visually.

**One point remains best-effort but unverified:** whether Sulu's own
catch-all route for page content intercepts the new `/produkte/*`
routes before Symfony reaches them couldn't be fully confirmed without a
live test. The expectation (CMS bundles register their catch-all route
at low priority so application routes take precedence) is standard
Symfony behavior, but it's an expectation, not a fact checked against the
code — unlike the rest of this entry. A `curl -I http://localhost/produkte/`
after deploying settles it in seconds.

---

## 24. Separate working directories as a source of bugs *(identified, no code fix needed)*

**Not a bug in the package — a workflow pitfall.** After the controller
fix (No. 21), the same `DefaultController` error showed up again, even
though the shipped v8 archive demonstrably contained the correct value
(directly cross-checked: `tar xzf ... -O ... | grep controller` showed
`ContentController`, both in the working copy and in the actually shipped
`.tar.gz`).

**Cause:** the user kept working in the original directory, which had
grown through dozens of individual patches over the course of the day,
rather than in the fresh `v8` folder from the last complete package.
Individual files in that old directory were at different patch states —
specifically:
`sulu-overlay/config/templates/pages/rockband_landing.xml` had never
advanced past the controller fix, even though other files in the same
directory already contained newer corrections.

**Diagnostic path:** rather than immediately building a new patch, the
shipped archive was cross-checked first (`tar -O` against the source
archive, without extracting it) — ruling out a mistake on the delivery
side before asking the user to check their local copy. Only after that
did `grep "controller>" sulu-overlay/..."` on the user's machine show the
stale value and confirm the diagnosis.

**Lesson:** a single, internally consistent complete package (like
`sulu-sylius-kickstarter-vX.tar.gz`) is only a reliable base when it's
actually worked from — not from a directory that grew in parallel with
its own patch history. Starting with the next complete package: recommend
switching to a fresh folder rather than copying individual files into the
existing one.

---

## 25. Git repository and development workflow *(new, v10)*

**Not a bug — a legitimately requested maturity upgrade:** "Is the
current state built so that developers can pick it up cleanly?"

**Already in place, unchanged since the project's start:** no Sylius or
Sulu core code is checked in or patched — both come exclusively through
Composer (`composer.json`/`composer.lock`), custom code lives
structurally separate in app-native directories
(`config/packages/dach_*.yaml`, `src/Fixture/`, `src/Command/` on the
Sylius side, entirely in `sulu-overlay/` on the Sulu side). That
separation was designed in from the beginning, not established
retroactively.

**What was missing:** an actual Git repository. The entire day's
development — 24 previous fixes, the headless rebuild, the catalog — had
no version control at all.

**Added:**
- `.gitignore` extended and made symmetric for both apps (Sylius root and
  `./sulu`). `composer.lock` for both apps deliberately **not**
  ignored — without it, every fresh install could end up with different
  package versions, exactly the kind of version drift that repeatedly
  slowed this project down (No. 6, 10).
- `make git-init` — creates a repository and makes a clean initial
  commit. Tested for idempotency (a second run recognizes the existing
  repo and does nothing). Deliberately **not** an automatic part of
  `make setup` — initializing Git is a decision, not a silent side
  effect.
- **One repository for both apps**, not separate repos or submodules:
  Sylius and Sulu are one cohesive product here, not a pair of
  independently deployed services. Separate repos would be unnecessary
  overhead for this project — they only pay off with independent deploy
  cycles or separate teams.
- README.md, new section "For development teams": makes the
  vendor-code/custom-code separation explicit, explains the Composer
  update path for both apps, points to `CLAUDE.md` and `FIXES.md` as
  entry points for a Claude instance assisting with the project.

---

## 26. Headless build stage 3: shopping cart *(v12)*

Third build stage from the roadmap in No. 23. Read-only catalog access
(stages 1-2) needed no state; a cart is the first stage where Sulu has to
own something across requests — the Sylius cart token.

**All four cart operations verified against the running Shop API before
writing any code**, same discipline as No. 23:

- `POST /api/v2/shop/orders` with an empty JSON body `{}` creates a cart
  and returns the full order including `tokenValue`.
- `POST /api/v2/shop/orders/{token}/items` with
  `{"productVariant": "/api/v2/shop/product-variants/{code}", "quantity": N}`
  adds an item. `Content-Type: application/ld+json`.
- `PATCH /api/v2/shop/orders/{token}/items/{id}` with `{"quantity": N}`
  changes the quantity. **Different content type than POST:**
  `application/merge-patch+json`, not `application/ld+json` — confirmed
  by testing both; API Platform expects the RFC 7396 merge-patch type
  specifically for `PATCH`.
- `DELETE /api/v2/shop/orders/{token}/items/{id}` removes an item,
  returns `204 No Content`.

**Confirmed side effect:** as soon as a cart holds an item, Sylius
automatically assigns a default shipping method and payment method to it
(`dhl_standard_de` / `paypal` showed up in the response after the first
`POST .../items` call), even though `checkoutState` is still `cart`. This
is a pricing preview only (so the cart can show correct tax and shipping
totals) — not a real selection. The actual choice happens during checkout
through the state machine, the same one `CreateTestOrdersCommand.php`
already drives.

**Architecture, following option A from No. 23 (Sulu backend as proxy):**
the Sylius cart token lives exclusively in Sulu's own Symfony session
(`CartManager`), never in the browser. `SyliusShopClient` gained five new
methods (`createCart`, `fetchCart`, `addCartItem`,
`updateCartItemQuantity`, `removeCartItem`) — purely API calls, no
session knowledge, keeping the existing separation of concerns intact.
`CartManager` is a new, dedicated service that owns the session token, so
the controller and the Twig cart-badge function share one source of truth
instead of two independent "get or create a token" implementations.

**Variant code convention is our own, not a Sylius guess:** adding an
item needs a product-variant code, e.g. `fender_stratocaster_variant`.
That's not something retrieved from the API — it's the naming convention
established in our own `RockbandProductsFixture.php`
(`$variant->setCode($data['code'] . '_variant')`), deterministic because
every one of our fixture products has exactly one variant.

**Cart routes as an ordinary Symfony controller** (`CartController`),
same reasoning as the catalog (No. 23): a cart is stateful, per-visitor
data, not editorial content, so it doesn't belong in Sulu's page tree.
Every state-changing action follows POST-redirect-GET with CSRF
protection (`csrf_token('cart_action')` / `CsrfTokenManagerInterface`) —
standard Symfony, low additional complexity, and worth having even for a
demo project.

**Graceful degradation preserved:** every new `SyliusShopClient` method
catches `\Throwable`, logs a warning, and returns `null`/`false` rather
than letting an exception escape — an unreachable Sylius API means an
empty cart or a silently ignored add, never a 500, consistent with every
other method in this class.

**Not yet built:** the checkout flow itself (address, shipping selection,
payment selection, order completion — stage 4) and real payment
processing (stage 5). The cart page's "Zur Kasse" button is deliberately
disabled and unlinked until stage 4 exists.

---

## 27. Headless build stage 4: checkout, all payment methods testable *(v13)*

Fourth build stage from the roadmap in No. 23, prompted by an explicit
follow-up requirement: every configured payment method (PayPal, Klarna
invoice, prepayment, credit card) needs to be genuinely selectable and
testable in the headless checkout — not just the one Sylius
auto-assigns as a pricing preview (see No. 26).

**The full checkout chain verified against a running order, one step at
a time, before writing any controller code:**

- `PUT /api/v2/shop/orders/{token}` with
  `{"email": "...", "billingAddress": {...}, "shippingAddress": {...}}`
  → `checkoutState` becomes `addressed`. Confirmed this is a **partial**
  update, not a full-object replace — order items and everything else
  stayed intact even though they weren't part of the request body.
- `PATCH /api/v2/shop/orders/{token}/shipments/{id}` with
  `{"shippingMethod": "/api/v2/shop/shipping-methods/{code}"}`
  (`application/merge-patch+json`) → `checkoutState` becomes
  `shipping_selected`.
- `PATCH /api/v2/shop/orders/{token}/payments/{id}` with
  `{"paymentMethod": "/api/v2/shop/payment-methods/{code}"}` →
  `checkoutState` becomes `payment_selected`. **The actual test that
  matters here:** selecting Klarna explicitly, instead of the
  auto-assigned PayPal default, and confirming the order afterward
  really shows `"method": ".../klarna_invoice"` — proof that an explicit
  choice overrides the pricing-preview default, not just that the
  request returns 200.
- `PATCH /api/v2/shop/orders/{token}/complete` with `{"notes": ""}` →
  `checkoutState` becomes `completed`, `paymentState` becomes
  `awaiting_payment` (correct — no real payment has happened, same end
  state `CreateTestOrdersCommand.php` reaches before its own manual
  completion step), `shippingState` becomes `ready`, an order number gets
  assigned.

**Payment method list confirmed to return all four configured methods**
via `GET /api/v2/shop/orders/{token}/payments/{id}/methods` — PayPal,
Klarna invoice, prepayment/bank transfer, credit card, each with its
`instructions` text from the fixture (`sylius_shipping_payment.yaml`).
The checkout payment page renders this list directly, so every method a
demo needs to show is genuinely clickable, not hardcoded to one.

**Implementation:**

- `SyliusShopClient` gained six checkout methods
  (`setCheckoutAddress`, `fetchShippingMethods`, `selectShippingMethod`,
  `fetchPaymentMethods`, `selectPaymentMethod`, `completeCheckout`) plus
  a shared private `patchCheckoutSubResource()` helper, since the
  shipment and payment method selection calls have an identical shape.
- `CheckoutController` (new, PHP-attribute routes like `CatalogController`
  and `CartController`) implements a **linear, guarded state machine**:
  each step checks `checkoutState` against the minimum required state
  and redirects backward if a visitor tries to skip ahead (e.g. loading
  `/checkout/zahlung` before an address was ever set redirects to
  `/checkout/versand`).
- `CartManager` gained a `clear()` method, called right after a
  successful `completeCheckout()` — without it, the next visit would try
  to keep adding items to an order that Sylius already considers
  finished. This was a deliberate addition, not something the API forced
  us to discover through an error.
- Guest checkout throughout, no login — consistent with the rest of this
  project (see No. 5's `PUBLIC_ACCESS` requirement for Sylius' own
  checkout) and the fact that no customer-account system exists here.
- Country hard-coded to `DE` in the address form: the catalog and cart
  only support the `germany` channel so far (documented limitation from
  No. 23/base.html.twig) — a country selector would be premature.
- CSRF protection (`CsrfTokenManagerInterface`, one token ID
  `checkout_action` shared across all checkout POSTs) on every
  state-changing form, same pattern as the cart controller from No. 26.

**Not yet built:** real payment gateway processing — every payment
method here still runs as `gatewayFactory: 'offline'` (see
`sylius_shipping_payment.yaml`), so `completeCheckout()` finishes the
order without an actual payment redirect. That's stage 5 of the roadmap
in No. 23, and — per that entry's own assessment — likely the most
complex remaining stage, since PayPal and Klarna both need real
redirect flows with return URLs, which this headless architecture (Sulu
as the sole browser-facing origin) has to route through Sulu rather than
letting the browser follow a payment provider redirect directly against
Sylius.

---

## 28. Sylius admin moved to its own port — `/build/admin/` path collision *(v14)*

**Symptom, reported after the checkout stage was already confirmed
working:** the Sylius admin dashboard under `/shop/admin/` rendered with
no styling at all — a raw, unstyled HTML page.

**Diagnostic path, each step confirmed before moving to the next (same
discipline as every other fix in this project):**

1. Direct request to Sylius' own `/shop/admin/login` returned `404`.
   **The conclusion drawn at the time — that `/admin/login` doesn't
   exist as a standalone route in this Sylius version — was too broad,
   and is corrected here in v34:** the route does exist and answers
   `200`. The `404` came from the request context used during that
   investigation (straight at the `sylius` container with a plain
   `localhost` Host header), not from the route being absent. It only
   mattered for the diagnostic path below, not for the fix itself,
   which stands.
2. `/admin/` (no `/login`) returned `200` with real content — Sylius
   admin is a single-page application rendered at the bare `/admin/`
   route, confirmed by extracting its actual asset references:
   `/build/admin/admin-entry.css`.
3. Checking whether Sulu itself uses the same path prefix for its own
   admin assets, before touching any routing config — and it does: Sulu
   serves its own admin UI from `/build/admin/main.<hash>.css`. Same URL
   prefix, two completely independent, unrelated build outputs living in
   two different containers.

**Why this couldn't be fixed with a Caddy path rule, unlike every other
routing decision in this project (No. 15, 16, 23):** a path is a single
string. `/build/admin/` cannot simultaneously mean "ask Sulu" and "ask
Sylius" — there's no third signal available to disambiguate by path
alone. Sulu's own admin interface currently works correctly precisely
because `/build/admin/*` already routes to Sulu by default (it falls
through to the final `handle { reverse_proxy sulu:80 }` block) — Sylius
was the one losing out, invisibly, until someone actually looked at the
rendered page instead of just a `curl` status code.

**Fix: Sylius admin gets its own Caddy server block on a separate port
(`:8082`), running unprefixed at its own root.** No path collision is
possible anymore, by construction — the two admin UIs no longer share a
single origin at all. `docker-compose.yaml` publishes the new port
(`SYLIUS_ADMIN_PORT`, default `8082`); `docker-compose.yaml`'s `router`
service now listens on two ports instead of one.

**Confirmed, not assumed, that dropping `X-Forwarded-Prefix` on the new
port produces the right asset paths:** an earlier diagnostic request to
Sylius *without* that header (made for an unrelated reason, checking
whether Sulu's own login page used the same asset-reference pattern)
had already shown Sylius generating clean, root-relative paths
(`/build/admin/...`, no `/shop` prefix) when no prefix header was
present. The new `:8082` block was built to match that already-observed
behavior, not as an untested guess.

**What stays unaffected:** `/media/*` and `/bundles/sylius*` on port 80
(product images/assets, unrelated to the admin-specific `/build/admin/`
collision — these paths don't exist under Sulu at all, so no collision
risk there). The internal Shop API connection (`SyliusShopClient`) was
never routed through the public Caddy layer to begin with, so it's
completely untouched.

**Deliberately not fixed by changing Sylius' own Encore/webpack build
config** to output its assets under a different path: that would mean
editing vendor code, which this project has avoided everywhere else
(see README.md, "vendor code vs. custom code") — and any such change
would be silently lost on the next `composer update sylius/sylius-standard`.
A routing-level fix survives Sylius updates; a vendor-code fix wouldn't.

**Side finding, not yet resolved:** the checkout confirmation email
didn't visibly arrive in Mailpit during testing. The Symfony log showed
the mailer transport starting, handling a `SendEmailMessage`, and
stopping cleanly — no error surfaced in that log excerpt. Whether the
message actually reached Mailpit's inbox wasn't conclusively confirmed
before this fix took priority; worth revisiting with a direct query
against Mailpit's own API (`/mail/api/v1/messages`) rather than log
inspection, which showed only successful-looking transport events
without confirming final delivery.

---

## 29. `verify.sh` fell behind the Caddyfile it checks — and a real consequence *(v15)*

**Cause:** while building the dedicated Sylius admin port in No. 28, the
`/shop/` block in the Caddyfile changed from a `reverse_proxy` with
`X-Forwarded-Prefix` to a set of plain redirects — but `verify.sh`
section 9 still searched for the old structure. `make setup` correctly
stopped at `verify`, exactly as designed (see "What's structurally
different" below) — the check itself was simply checking for something
that no longer existed.

**Real consequence, not just a cosmetic check failure:** because `verify`
stopped the chain before `fixtures` (and therefore `sulu-theme`) ever
ran, the freshly created `./sulu` folder never got its overlay files
copied in. A `git add -A && git commit` run anyway (following the
"new folder + copy `.git`, then commit" workflow from README.md) honestly
recorded what it saw: 35 files gone, because they existed in the previous
commit but weren't physically present yet. Not data loss — every file
still exists both in the shipped archive (`sulu-overlay/`) and in the
prior commit — but a commit message describing a feature addition ended
up looking like a mass deletion.

**Fix:** section 9 now checks for what's actually true about the current
Caddyfile — `/media/` still setting `X-Forwarded-Prefix` (unchanged, pure
asset hosting, unrelated to the admin-port move) and the `:8082` block
existing and proxying to `sylius:80` (the actual fix from No. 28). Tested
against both the real Caddyfile and a deliberately broken test file
missing the admin block, same discipline as every other `verify.sh`
check in this project.

**Process lesson, not just a code fix:** `make setup && git add -A && git
commit` is not a command chain to run blindly — if `make setup` exits
non-zero partway through, whatever got committed afterward reflects an
incomplete, partially-built state, not the intended one. The safe
sequence is: confirm `make setup` actually reached its final success
message before committing anything. A failed `verify` (or any other
stage) is a stopping point, not something to shrug past on the way to a
commit.

---

## 30. Real payment gateway: Adyen — backend wiring complete, widget still open *(v16, in progress)*

**Not a bug fix — the fifth and last build stage from the roadmap in
No. 23**, at explicit user request: real payment processing, tested with
Adyen (chosen for European data sovereignty over US-based alternatives),
with a one-command switch back to demo mode for development/testing.

**Every architectural fact below was verified against the actual
installed plugin and Sylius core code before writing configuration**,
same discipline as every other stage:

- `sylius/adyen-plugin` (official Sylius plugin, not a community fork)
  was already installed, version 2.0.6, released two weeks before this
  session — confirmed via `composer show`.
- `gatewayFactory` value is `'adyen'` — confirmed directly from
  `AdyenClientProviderInterface::FACTORY_NAME`, not assumed from naming
  convention.
- The `gatewayConfig` fixture field is a **flat, 1:1 pass-through** —
  confirmed via `PaymentMethodExampleFactory.php`:
  `$gatewayConfig->setConfig($options['gatewayConfig'])`. No nested
  `config:` wrapper needed in the YAML, despite the admin form
  (`GatewayConfigType.php`) rendering it as a nested sub-form — the
  fixture factory bypasses that form entirely and writes the array
  straight through.
- The exact config keys (`environment`, `merchantAccount`, `apiKey`,
  `clientKey`, `hmacKey`, plus optional `authUser`/`authPassword`,
  `esdEnabled`, `captureMode`) came from reading
  `ConfigurationType.php`'s `buildForm()` directly — the actual admin
  form field list, not third-party documentation of uncertain version
  match.
- **One Adyen payment method, not three:** Adyen's own Drop-in widget
  bundles PayPal/Klarna/card selection *inside itself* — the customer
  picks the method inside the widget, not via separate Sylius payment
  methods. Confirmed via the plugin's own README ("enables multiple ...
  payment methods" as a single integration) and its route list
  (`sylius_adyen_shop_payments` etc. take one `{code}`, not per-method
  codes). Building three artificially separate "Adyen" entries would
  have fought the plugin's own intended model for no benefit.

**A genuine architecture exception, made deliberately, not by
accident:** the Adyen Drop-in widget runs as JavaScript directly in the
customer's browser and must talk to specific Sylius endpoints
(`/{locale}/payment/adyen/{code}`, `/api/v2/shop/payment/adyen/*`,
the `/payment/adyen/{code}/notifications` webhook) **directly** — card
data must never pass through our own server for PCI compliance. This is
a narrow, well-defined exception to "Sulu is the only browser-facing
origin" (No. 23's option A), not a reversal of it: every other part of
the shop stays exclusively on Sulu. The Caddy router now has explicit
`handle` blocks for exactly these paths, `header_up X-Forwarded-Prefix
"/shop"` set consistently with every other direct Sylius route
(`/media/`, `/bundles/sylius*`).

**A confirmed Caddy limitation shaped the routing syntax:** the first
draft used `/*/payment/adyen/*` (wildcard at the start, combined with a
wildcard in the middle) to cover all three DACH locales in one rule. A
web search before shipping turned up a confirmed open Caddy issue
(caddyserver/caddy#5029) showing exactly this combination doesn't match
as expected — even the maintainers' own example
(`*/containers/*/json`) fails to match its intended request. Since the
three DACH locales are fixed and known, the routing lists
`/de_DE/payment/adyen/*`, `/de_AT/payment/adyen/*`,
`/de_CH/payment/adyen/*` explicitly instead of relying on an unverified
wildcard combination — caught by checking before shipping, not by a
failed test later.

**Credentials never hard-coded:** all five Adyen config values come from
environment variables (`ADYEN_ENVIRONMENT`, `ADYEN_MERCHANT_ACCOUNT`,
`ADYEN_API_KEY`, `ADYEN_CLIENT_KEY`, `ADYEN_HMAC_KEY`), empty by default
in `.env.docker.example`. Symfony's `%env(VAR)%` syntax requires the
variable to actually exist in the container's environment (not just in
`.env.docker`, which only `docker compose` itself reads) — so
`docker-compose.yaml`'s `sylius` service now explicitly forwards all
five with empty-string defaults (`${ADYEN_API_KEY:-}`), avoiding a
container-build failure when they're unset.

**The one-command demo/live switch, built on a mechanism that already
existed, not a new admin UI:** `make payments-demo` and `make
payments-live` flip the `enabled` flag on the relevant
`sylius_payment_method` rows directly. This reuses Sylius' own, already
existing per-method "Enabled" toggle — the same field
`CreateTestOrdersCommand.php` already filters on
(`if (!$candidate->isEnabled()) continue;`) — rather than building a new
custom Sylius Admin settings screen. Building genuinely new Sylius Admin
UI would mean working with Sylius' grid/resource system, something this
project has never touched (every interaction with Sylius admin today
was read-only, via `curl`, for diagnostics) — a materially different and
riskier kind of work than anything else in this project, deliberately
avoided in favor of a mechanism that was already verified to exist.
Prepayment (`invoice`, bank transfer) stays enabled in **both** modes —
it's a genuinely valid payment method independent of any gateway, not a
demo stand-in, so it isn't part of the toggle.

**`make payments-live` refuses to run with empty credentials**
(`grep -qE '^ADYEN_API_KEY=.+' .env.docker`), rather than silently
enabling a payment method that would fail at checkout with no visible
warning until a customer actually tries it.

**What's NOT yet built, deliberately left for a following step:** the
actual Drop-in widget embedded into `checkout/payment.html.twig` —
fetching the widget configuration
(`sylius_adyen_api_shop_dropin_configuration`), rendering Adyen's own JS
component, and handling the result (`sylius_adyen_shop_payments`,
redirect/3DS via `sylius_adyen_shop_details`). Backend wiring (fixture,
routing, environment variables, the demo/live switch) is complete and
verified; the frontend integration is substantial enough to deserve its
own dedicated pass rather than being rushed into the same response.

**Also deliberately not covered:** Adyen's async webhook notifications
require a publicly reachable URL, which `localhost` isn't from Adyen's
servers — normally solved with a tunneling service like `ngrok` for
local development. Explicitly out of scope here: local testing stays in
Adyen's own debug/test mode, confirmed acceptable by the user rather
than assumed.

**Also deliberately not covered:** Adyen's own Express Checkout routes
(`/*/adyen/express-checkout/*` — Apple Pay / Google Pay buttons directly
on product pages) aren't routed through Caddy. That's a separate feature
never requested, not a gap in this routing.

---

## 31. `make payments-demo`/`make payments-live`: wrong column name, not verified before shipping *(v17)*

**A genuine slip, and an honest one to name:** the `payments-demo`/
`payments-live` Makefile targets in v16 used `SET enabled = ...` in raw
SQL against `sylius_payment_method` — copied from the fixture YAML's
field name (`enabled: true`) without checking the actual database
schema first. Every other raw-SQL interaction with this table earlier in
the project (`CreateTestOrdersCommand.php`) went through Doctrine's
`isEnabled()` method on the entity, never through a column name typed by
hand — this was the first time a column name was assumed rather than
looked up, and it was wrong.

**Real column name, found via the same reliable method used throughout
this project** (`information_schema.columns`, since `DESCRIBE`/`SHOW`
falsely report "0 rows affected" through `doctrine:query:sql` — see
FIXES.md No. 14):

```sql
SELECT column_name FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'sylius_payment_method'
ORDER BY ordinal_position
```

Result: `is_enabled`, not `enabled`. Fixed in both Makefile targets.

**Lesson, stated plainly:** the fixture YAML's field name and the
underlying database column name aren't always identical - a lesson this
project already had reason to be careful about (No. 11's
`PaymentMethodExampleFactory.php` fields aren't 1:1 with the DB schema
either), but this specific new-column-name assumption slipped through
without the same verification step applied everywhere else in this
project. Caught quickly because the failure was loud and immediate (a
clear SQL error, not a silent no-op), unlike the EnableFlushStamp issue
in No. 19-22 which failed silently.

---

## 32. Adyen Drop-in widget on the checkout payment page *(v18)*

**The frontend half of stage 5** (No. 30 built the backend: fixture,
routing, demo/live switch). Every integration detail here was traced
through Sylius' and the plugin's own real code before writing a single
line of our own — no third-party tutorial or AI-recalled snippet was
trusted at face value, following the same discipline as every stage
before it.

**Discovery path, step by step:**

1. Sylius' own Drop-in template
   (`templates/shop/order/show/content/form/select_payment/payment/choice/details/dropin.html.twig`)
   turned out to be almost nothing: a `<div class="dropin-container"
   data-config-url="...">`. All real logic sits in JavaScript.
2. That JavaScript
   (`vendor/sylius/adyen-plugin/assets/shop/js/dropin.js`) imports
   `AdyenCheckout` from `@adyen/adyen-web/auto` — an ES module import,
   meaning it needs a bundler, not a plain `<script>` tag as written.
3. **Three build strategies were weighed, not assumed:**
   - **Option A (chosen):** load Sylius' own already-compiled bundle
     directly.
   - **Option B:** set up a dedicated, slimmer build pipeline in our
     own project just for this one widget.
   - **Option C:** load Adyen's own official CDN script directly
     (`checkoutshopper-{env}.adyen.com/checkoutshopper/sdk/{version}/adyen.js`) -
     confirmed this exists and is Adyen's own sanctioned lightweight
     alternative to the npm/bundler route, **but** Adyen's own v6.0.0
     release notes explicitly state *"If you integrate with embedded
     scripts, we changed how we expose the AdyenCheckout and Drop-in/
     Components on the window object"* - and multiple searches plus a
     direct fetch of Adyen's own integration-guide landing page
     couldn't pin down the exact new syntax (the page is a multi-level
     navigation hub, not the concrete code snippet). Rather than guess
     at unverified window-object exposure syntax for a payment
     integration, option C was dropped.
4. **The bundle location itself required correction mid-investigation:**
   the first, smaller bundle checked (`public/build/shop/shop-entry.js`,
   712 KB) contained no trace of `AdyenCheckout` at all - only a single
   reference to `images/adyen-logo.svg`. Running `yarn build` directly
   (bypassing the Makefile's error-suppressing `-` prefix on this
   target) revealed a second, much larger entrypoint,
   `app-shop-entry.js` (5.88 MB), which does contain the Adyen module
   (confirmed: 132 matches for `AdyenCheckout`, 3 matches for
   `dropin-container`). **The honest cost of option A**: 5.88 MB of
   JavaScript plus 646 KB of CSS, all of Sylius' own shop-frontend
   bundle, not a slim Adyen-only artifact - loaded conditionally, only
   when the selected payment method is Adyen, never on every checkout
   page.
5. **Self-binding mechanism confirmed directly in the source**, not
   assumed: `document.addEventListener('DOMContentLoaded', (e) => {
   document.querySelectorAll('.dropin-container').forEach(instantiate);
   })`. Plain vanilla JS, no Symfony UX Stimulus controller involved -
   our simple `<div class="dropin-container" data-config-url="...">`
   markup is sufficient on its own.
6. **Button behavior confirmed, not assumed:** `injectOnSubmitHandler()`
   only intercepts any pre-existing `[type=submit]` elements as a
   safety net - it doesn't render a button itself. Combined with Adyen's
   v6 release notes stating `showPayButton` now defaults to `true`, the
   Drop-in component renders its own "Pay" button internally. Our
   template therefore shows either our own "place order" form (non-Adyen
   methods) or the widget (Adyen) - never both, since two competing pay
   buttons would confuse the customer.
7. **The submit flow bypasses our own `completeCheckout()` entirely for
   Adyen orders**, confirmed by reading `submitHandler()`: it `POST`s
   directly to `configuration.path.payments`
   (`sylius_adyen_shop_payments`), and the response contains either
   `action` (further interaction needed, e.g. 3D Secure -
   `dropin.handleAction()`) or `redirect` (a direct
   `window.location.replace()`). Sylius' own domain code completes the
   order server-side as part of that response - our controller doesn't
   drive this state transition for Adyen.
8. **A newly required Caddy route, checked for collision risk before
   adding it**, same discipline as No. 28: the widget bundle lives under
   `/build/app/shop/` on the public router (unlike the internal-only
   Shop API calls). Confirmed no collision with Sulu's own `/build/`
   output first (`find public/build -maxdepth 2 -type d` on the Sulu
   side showed only `/build/admin/`, no `/build/app/`) before adding the
   route - avoiding a second version of the exact bug class from No. 28.

**A real, not-yet-fixed problem found along the way, tracked separately
rather than silently worked around:** Adyen's own
`SuccessfulResponseProcessor.php` redirects to a hard-coded PHP constant,
`THANKS_ROUTE_NAME = 'sylius_shop_order_thank_you'`
(`/{_locale}/order/thank-you`) - not configurable via fixture or environment
variable. This is a genuine Sylius-native route our Caddy router doesn't
yet cover, and even once reachable it would show Sylius' own generic
Twig confirmation page, not our Sulu-side confirmation
(`/checkout/bestaetigung/{number}`). The agreed fix, deliberately not
built in this same session: route it through Caddy, then override just
that one Symfony/Sylius Twig template (a documented, standard Symfony
mechanism, not a vendor-code edit) to redirect immediately to our own
confirmation page.

**What's NOT covered by this stage, and deliberately not claimed to be
working without real credentials:** none of this has been exercised
end-to-end against a live Adyen sandbox, since no sandbox credentials
existed at the time of writing (see No. 30). Every architectural fact
above is verified against real, installed code - but the actual payment
flow itself (does the widget render correctly, does a test card
complete a payment, does the thank-you redirect actually work once
routed) remains unverified until real credentials are available and
`make payments-live` is run.

---

## 33. Adyen success redirect: hard-coded route made reachable and overridden *(v20)*

**A known gap from No. 32, now closed.** Adyen's own
`SuccessfulResponseProcessor.php` redirects to
`sylius_shop_order_thank_you` (`/{_locale}/order/thank-you`) after a
completed payment - a hard-coded PHP constant
(`THANKS_ROUTE_NAME`), not configurable via fixture or environment
variable. Two problems followed from that: the route wasn't reachable
through our Caddy router at all, and even once reachable it would show
Sylius' own generic Twig confirmation page, not our Sulu-side one.

**Traced through real Sylius core code, not assumed:**
`OrderController::thankYouAction()`
(`vendor/sylius/sylius/src/Sylius/Bundle/CoreBundle/Controller/OrderController.php`)
reads the order ID from the **Sylius session** (`sylius_order_id`, set
elsewhere during checkout - not from the URL), loads the order, and
renders it with `['order' => $order]` - meaning the order number is
reachable in the template as `order.number`, the same field this
project's own checkout code already reads elsewhere.

**Collision check performed before adding the Caddy route**, same
discipline as No. 28 and No. 32: a direct request to Sulu for the exact
path (`/de_DE/order/thank-you`) returned `404` - no existing Sulu route
uses this path, so routing it to Sylius creates no conflict.

**Three locale-specific `handle` blocks added**, not a wildcard
combination - same reasoning as No. 30/32's Adyen payment routes
(confirmed Caddy bug with wildcard-at-start-plus-middle combinations,
caddyserver/caddy#5029).

**Template override, not a vendor-code edit:** Symfony's standard
bundle-template-override mechanism
(`templates/bundles/<BundleClassName>/<path>`) lets an app-level
template take priority over a bundle's own, without touching the
bundle's source. The exact bundle class name was verified directly
(`grep` on `SyliusShopBundle.php` confirmed `final class
SyliusShopBundle extends Bundle`) rather than assumed from the `@SyliusShop`
Twig alias seen in the route's `_sylius` defaults - Symfony's
override-path convention uses the PHP class name, which isn't always
identical to a Twig namespace alias, so this was checked rather than
inferred.

**The override itself is deliberately minimal:** a plain HTML
`<meta http-equiv="refresh">` to `/checkout/bestaetigung/{{ order.number
}}`, with a visible fallback link for anyone whose browser doesn't honor
the meta-refresh. No JavaScript dependency, no Twig/PHP redirect
response that would need to survive being relayed correctly through
Caddy's `reverse_proxy` - the simplest mechanism that reliably works
regardless of how the response reaches the browser. A customer reaches
this page for a fraction of a second at most, never really "seeing"
Sylius' own confirmation page.

**Still unverified, same caveat as No. 30 and No. 32:** none of this has
been exercised against a real completed Adyen payment, since no sandbox
credentials existed at the time of writing. The redirect chain (Adyen →
`/order/thank-you` → our override → `/checkout/bestaetigung/{number}`)
is architecturally sound and each link individually verified against
real code, but the full chain hasn't run end-to-end with real money-flow
data.

---

## 34. Server portability and mail polish *(v20)*

**Three small, independent fixes, requested together after a review of
open items.**

### a) Hard-coded `localhost` in the admin redirect

`redir /shop/admin* http://localhost:8082/admin/ 302` would misdirect
anyone visiting on a real server (their own machine's `localhost`, not
the server) - a real portability bug, not a style nitpick. Fixed with
Caddy's `{host}` placeholder:
`redir /shop/admin* http://{host}:8082/admin/ 302`. Confirmed against
Caddy's own documentation before using it - not assumed: the official
"Common Caddyfile Patterns" page
(caddyserver.com/docs/caddyfile/patterns) uses this exact placeholder in
a `redir` directive as its own canonical example
(`redir https://www.{host}{uri}`). `{host}` resolves to whatever
hostname the request actually came in on, so this keeps working
unchanged whether the router is reached via `localhost`, an IP, or a
real domain.

### b) Default mail sender

Sylius' own default (`config/app/sylius/sylius_mailer.yml`, vendor
code) ships `Example.com` / `no-reply@example.com`. Overridden in
`dach_demo.yaml` (same override-instead-of-vendor-edit pattern used
throughout this project) with a project-appropriate sender name and
address.

### c) Dead link in the order confirmation email

**Traced through real Sylius code, not assumed:** the email template
chain is `orderConfirmation.html.twig` → (via `{% include %}`) →
`Blocks/OrderConfirmation/_content.html.twig` - the actual dead link
lives two levels deep, not in the top-level template initially
suspected. That template's "view order / change payment method" button
links to `sylius_shop_order_show`, a page belonging to Sylius' own shop
frontend - switched off entirely in this headless setup (No. 23). The
link would lead nowhere, and its label promises a "change payment
method after the fact" capability that was never built anywhere in the
Sulu frontend either.

**Two options were weighed, not decided unilaterally:** point the
button at our own `/checkout/bestaetigung/{number}` page instead, or
remove it entirely. Removal was chosen: that confirmation page is only
meant for the moment right after checkout completes (it has no
lookup-by-order-number capability of its own, no authentication, and
was never designed as a standing order-status page someone might reach
by clicking a link in an email opened days later).

**Fixed via the same bundle-template-override mechanism as No. 33** -
`templates/bundles/<Bundle class name>/<path>`, confirmed the exact
class name is `SyliusCoreBundle` (`grep` on `SyliusCoreBundle.php`)
rather than assumed from the `@SyliusCore` Twig alias, same discipline
as the `SyliusShopBundle` check in No. 33. Everything else in the
template - order number, "thank you" text - stays byte-for-byte
identical to Sylius' own version; only the broken button block is
removed.

---

## 35. Pixabay downloads confirmed broken; placeholder upgraded, local override documented *(v20)*

**Confirmed, not assumed, before touching any code:** a direct request
to one of the fixture's Pixabay URLs from inside the Sylius container
returned `403 Forbidden`. Not a one-off network hiccup specific to this
session either — the same grey-placeholder result had already been
observed by the user in an earlier session, meaning every fresh
`make setup` was silently falling through to tier 3 (the generated
placeholder) for every product, not occasionally.

**Real product photos considered, deliberately not pursued in this
session:** no reliable, license-clear source of matching product photos
(Fender Stratocaster, Gibson Les Paul, Marshall amp, Pearl drum kit,
Shure SM58, Boss pedal) could be found on a domain actually reachable
from this working environment's network allowlist (development-tool
domains only — GitHub, npm, PyPI, etc. — not general image hosts). A
search for a curated, GitHub-hosted CC0/public-domain instrument photo
set came up empty; general stock-photo sites like rawpixel.com aren't
reachable from here regardless. Real photos remain the better long-term
option, but weren't something this session could source itself.

**What was built instead - two independent, complementary
improvements:**

1. **The generated placeholder (tier 3 of `resolveImagePath()`) now
   draws a simple, category-specific icon** with GD's own drawing
   primitives (filled ellipses, rectangles, lines - no external image
   or font file needed) instead of just a grey box with the product
   name as text. Five categories, five icons: a guitar silhouette
   (body + neck + headstock + strings), an amp cabinet with two
   speakers and control knobs, a drum shell on a stand, a microphone
   capsule with grille lines, and a distortion pedal with a knob and
   footswitch. Every coordinate was hand-calculated against the fixed
   1200×900 canvas size with a safety margin, since GD silently clips
   out-of-bounds drawing rather than erroring - a coordinate mistake
   here wouldn't crash the build, it would just quietly cut off part of
   an icon, which is easy to miss without actually rendering the image.
   This is still clearly placeholder art, not a realistic product
   photo attempt - it's what a visitor sees only when both the local
   image and the Pixabay download failed, not the primary visual.

2. **The existing local-file tier of the fallback chain (already
   built, but not documented) is now called out explicitly in
   README.md.** Dropping a JPEG into
   `var/demo-images/<product-code>.jpg` before `make fixtures` already
   took priority over both the Pixabay download and the placeholder -
   this required no code change at all, only making the existing
   capability visible, since it wasn't mentioned anywhere before this
   entry.

**Not fixed, and not silently worked around:** the Pixabay download
tier itself remains in the code, unrepaired - the 403 is Pixabay's own
access restriction, not a bug in our request code, and no alternative
automated image source was found that this environment could reach.
Tier 2 will keep failing until either Pixabay changes its access
behavior or a different, network-reachable image source is identified
in a future session.

---

---

## 36. `verify.sh` now flags Sylius updates that could silently break our template overrides *(v22)*

**Not a bug — a preventive check added after being asked directly
whether Sylius/Sulu updates could overwrite our own changes.** The
honest answer for most of this project is "no" (our code never lives in
`vendor/`), but the two Symfony bundle-template overrides from No. 33
and No. 34 are a real exception: they only take effect while Sylius'
own original template still exists at the exact path we're shadowing.
A future `composer update sylius/sylius-standard` could move or rename
either original with no error message at all — our override would
simply stop applying, silently. That's the same failure mode already
seen once in this project with `EnableFlushStamp` (No. 19–22): no
exception, just quiet non-effect.

**`verify.sh` section 13 now checks both original paths directly**,
confirmed once more against the real vendor code before writing the
check (not re-derived from memory):

- `vendor/sylius/sylius/src/Sylius/Bundle/ShopBundle/templates/order/thank_you.html.twig`
- `vendor/sylius/sylius/src/Sylius/Bundle/CoreBundle/Resources/views/Email/Blocks/OrderConfirmation/_content.html.twig`

Worth noting purely as a small confirmation of "don't assume, check":
these two paths follow two different Symfony bundle conventions even
within the same Sylius package — `ShopBundle` uses the newer
`templates/` directory at the bundle root, `CoreBundle` still uses the
older `Resources/views/` layout. Assuming both followed the same
pattern would have given a wrong path for one of them.

**Deliberately a warning, not a hard failure:** a missing original
doesn't necessarily mean the override is broken (that depends on
whether Sylius' code still references the same template path at all),
so this can't be a reliable pass/fail signal on its own — it's the best
automatable early-warning signal available, flagging "this needs a
manual look" rather than blocking `make setup`.

**Scope, stated plainly:** this covers only the two template overrides.
Every other detail verified against this specific Sylius version
throughout this project — service IDs, database column names, form
field names, route names — is not covered by any automated check and
would need to be re-verified by hand after a real version update, the
same way each was originally discovered.

---

## 37. Placeholder icons looked broken — because they were never actually rendered and looked at *(v23)*

**A real methodology gap, not just a cosmetic bug.** The five
category-specific placeholder icons added in No. 35 were hand-calculated
against the 1200×900 canvas to make sure nothing fell outside its
bounds — but "the coordinates don't go negative" and "this looks like a
guitar" are two entirely different claims, and only the first one was
actually checked. No PHP/GD environment was available in the working
sandbox (confirmed earlier: `apt-get install php-cli` failed with a
`403` from the package mirror), so the icons shipped in No. 35 were
never rendered or looked at before release — only reasoned about on
paper. The user's screenshot of the shipped guitar icon confirmed
exactly the failure mode that risk implies: two overlapping,
similarly-sized circles read as a featureless blob, not a guitar, and
the neck sat dead-center through the middle instead of emerging
plausibly from the body.

**Fix for the process, not just the icons:** Python's PIL (confirmed
available in the sandbox, unlike PHP/GD) was used as a prototyping and
visual-verification tool. Every icon was drawn in PIL first, rendered to
a real PNG, and actually viewed before any coordinate was considered
final — several iterations for the guitar alone (a first redesign came
out looking like a violin due to a symmetric double-cutaway; a second
came out looking like a kidney bean with an oddly-angled neck; the
version that finally worked uses one large simple oval body with a
centered vertical neck — less anatomically precise than a real
solidbody guitar, but reliably recognizable, which matters more for an
icon than precision). The drum icon needed the same treatment — the
original single dark ring read as a grill or firepit, not a drum;
fixed with a properly closed, skinned drumhead and thicker stand legs.
The amp icon's knobs floated above the cabinet with nothing connecting
them; fixed by adding a control-panel strip for them to visually sit on.
The effects pedal gained jack sockets, knob-pointer lines, and a status
LED — details that make it read as "pedal" rather than "generic box".
The microphone icon needed no changes; it read correctly on the first
render.

**Confirmed matching, not just visually similar:** after translating
the verified PIL coordinates into GD calls
(`imagefilledellipse`/`imagefilledrectangle` map directly onto PIL's
center+size and bbox conventions respectively; PIL's `line(...,
width=N)` becomes `imagesetthickness($image, N)` before `imageline()`
in GD, reset to `1` afterward), the exact numbers now sitting in the PHP
source were re-rendered once more in Python as a final cross-check
before considering the fix complete — catching any transcription
mistake between "the prototype that was approved" and "what actually
ended up in the PHP file", not just re-trusting the translation by eye.

**Shipped together with No. 38** (the Pixabay download tier's removal),
as the user requested — not as its own separate version bump.

---

## 38. Pixabay download tier removed entirely, not just left disabled *(v23)*

**Not a new bug — a deliberate cleanup requested after No. 35 already
confirmed Pixabay's CDN reliably returns `403 Forbidden`** for this
project's automated image requests. Rather than leave permanently-dead
code in place (a download attempt that has never once succeeded and
never will under current Pixabay behavior), the entire tier was removed:

- `docker/scripts/download-demo-images.sh` deleted outright
- The `make demo-images-download` target and its automatic call from
  `make fixtures` removed from the Makefile
- `RockbandProductsFixture.php`: the `download_missing` fixture option,
  the `file_get_contents()`-based download block in
  `resolveImagePath()`, and the now-unused `'image' => 'https://cdn.pixabay.com/...'`
  key on every one of the six products removed. The image-resolution
  chain is now two tiers, not three: local file, then the generated
  placeholder icon (No. 37) — never a network call.

**A genuine duplicate configuration found and fixed along the way, not
part of the original ask:** the `rockband_products` fixture options
(`image_dir`, `download_missing`) turned out to be defined **twice** —
once correctly in `dach_products.yaml`, and a second, identical copy
sitting in `sylius_shipping_payment.yaml` under a "PRODUCTS" comment
block that has nothing to do with shipping or payment. Both values were
identical, so this was harmless in practice, but it was a real leftover
from earlier development, not something either file needed. The stray
copy in `sylius_shipping_payment.yaml` was removed entirely, not just
its `download_missing` line — the whole misplaced block.

**What stays:** the tier-1 local-file override
(`var/demo-images/<product-code>.jpg`) and the category-icon placeholder
from No. 37 are both untouched — removing a permanently-broken feature
doesn't affect the two that actually work.

---

## 39. Documentation drift: stale claims found and corrected *(v24)*

**Not a code bug — a documentation audit**, done after being asked
directly whether anything else could be improved, rather than assuming
everything written earlier was still accurate.

- **No. 23's claim that landing-page product tiles are "deliberately
  not clickable" was stale.** That was true at the time No. 23 was
  written (v8, before standalone product pages existed), but the
  catalog build stage shipped since then and the tiles have linked
  correctly to `catalog_product` ever since. The entry now says so
  explicitly, kept as a record of the v8 state rather than a
  description of current behavior — deleting it outright would have
  erased real historical context for no benefit.
- **`CLAUDE.md`'s "Known risks" table hadn't been touched since v14**
  (Sylius admin port move) — every risk added by checkout, the Adyen
  backend, and the Adyen widget was simply missing. Most notably: the
  single most important open risk in the entire project (Adyen has
  never been exercised against a real sandbox, see No. 30/32) wasn't
  listed anywhere in the one table meant to be the project's risk
  summary. Three rows added: the untested-Adyen risk itself, the
  5.88 MB widget-bundle-size tradeoff from No. 32, and the
  template-override fragility from No. 36 (already checked by
  `verify.sh`, but not previously cross-referenced here).

**Checked and found accurate, not just assumed:** `make help`'s target
list and `verify.sh`'s section coverage were both audited against the
actual Makefile/script content — every target has a help string, every
major feature area (including Adyen and the template-override check)
has a corresponding `verify.sh` section. No gap found there.

---

## 40. Static analysis: `make phpstan`, and why level 5 rather than 9 *(v25)*

**Added after the user asked what could still be improved technically
for an agency context.** An audit of what the project lacked as a
long-lived, team-maintained codebase turned up three gaps: no automated
tests, no CI pipeline, no static analysis. Static analysis was tackled
first — smallest effort, immediate value, and the foundation the other
two would build on anyway.

**Good starting position, verified rather than assumed:** PHPStan
turned out to already be installed in *both* apps (a transitive dev
dependency of Sylius and Sulu respectively), so nothing needed adding
to `composer.json`. Both apps also already ship their own
`phpstan.dist.neon` — Sylius at level 9, Sulu at level `max`. Finding
that out first mattered: the original plan had been to create a
`phpstan.dist.neon` in the project root, which would have silently
overwritten Sylius' own, repeating the exact mistake from No. 3
(our `_sylius.yaml` displacing Sylius' file of the same name). Our
config is therefore called `phpstan-kickstarter.dist.neon` and is
passed explicitly with `-c`.

**Why level 5 and not Sylius' own level 9 — measured, not guessed:**
running both levels against our two Sylius-side files gave 3 findings
at level 5 and 52 at level 9. The bulk of the level-9 findings come
from the deliberately untyped `object` parameters on the Sylius
repository constructor arguments — which are not an oversight but the
fix from No. 6/7, adopted specifically because Sylius moved interface
namespaces between versions and broke a hard type hint. Satisfying
level 9 would mean reintroducing exactly the brittleness that fix
removed. Level 5 still catches the class of bug that actually cost this
project time before: No. 12's call to a non-existent `setMetaTitle()`
method would have been caught here, before the first run.

**The three level-5 findings were real, and all three are now fixed:**
- Two `argument.type` findings traced to a single wrong import —
  `Sylius\Component\Taxonomy\Model\TaxonInterface` where Sylius' own
  `ProductTaxonInterface::setTaxon()` and
  `ProductInterface::setMainTaxon()` both expect the more specific
  `Sylius\Component\Core\Model\TaxonInterface`. Worked at runtime
  (the concrete object implements both), but the type hint was wrong.
- One `function.alreadyNarrowedType`: a `method_exists($variant,
  'setTaxCategory')` guard that always evaluates to true, since
  `ProductVariantInterface` declares the method. It was defensive code
  from when this project was still unsure which Sylius API version it
  targeted — an uncertainty long since resolved.

**The Sulu side was believed to need nothing** — all seven overlay
classes appeared to pass Sulu's own `max`-level config cleanly.
**That was wrong**, and is corrected in No. 41: the check that produced
that conclusion ran before the overlay files had been copied into the
Sulu app, so it analysed almost nothing. The real count was 77
findings.

**A separate bug found while editing the Makefile for this:** `make
doctor`'s payment- and shipping-method queries still used
`SELECT code,enabled` — the same wrong column name fixed in
`payments-demo`/`payments-live` back in No. 31, but missed in `doctor`
at the time. Both queries would simply have errored out when run.
Fixed to `is_enabled` in the same pass.

**Deliberately NOT wired into `make verify` or `make setup`.** A
kickstarter has to stay installable — if a developer later adds their
own code with a level-5 finding, that shouldn't block the whole
install. `make phpstan` stays a separate, explicit command.

**Still open, in decreasing order of value for a long-lived agency
project:** automated tests (the largest gap by far — every API
assumption verified manually throughout this project is verified
nowhere repeatably), and a CI pipeline to run `make setup` plus this
analysis on every commit.

---

## 41. The Sulu side wasn't actually clean — 77 findings, all fixed *(v26)*

**A wrong claim in No. 40, caught by the user running `make phpstan` for
real.** No. 40 stated the Sulu overlay classes "already pass Sulu's own
`max`-level config cleanly". That was based on a PHPStan run that
returned `[OK] No errors` — but that run happened before
`make sulu-theme` had copied the overlay files into the Sulu app at all.
PHPStan analysed a near-empty directory and, quite correctly, found
nothing wrong with it. Reading "no errors" as "our code is clean"
without checking whether anything had been analysed is exactly the kind
of unverified assumption this project has otherwise been careful about.

**The real number, once the files were actually there: 77 findings.**
Not the harmless `missingType.iterableValue` annotations the first few
lines of output suggested, either — the bulk were genuine `mixed`-typed
data access: reading `$cart['shipments'][0]['id']` and casting API
response fields to string, none of which static analysis can verify is
safe, because a Shop API response is `array<string, mixed>` and could
contain anything at any key.

**Fixed by narrowing once instead of casting everywhere.** Rather than
sprinkling `is_array()`/`is_string()` guards across ~60 call sites,
five small private helpers were added:

- `SyliusShopClient::str()` / `strOrEmpty()` — convert an arbitrary API
  value to a string (or null) safely; used at every field read
- `SyliusShopClient::toAssoc()` — narrows Symfony's plainly-typed
  `toArray()` result to `array<string, mixed>` in one place, replacing
  nine identical `return $response->toArray(false)` sites
- `CheckoutController::cartToken()`, `firstSubResourceId()`,
  `firstSubResourceMethodIri()` — the three cart-shape reads this
  controller performs over and over (`tokenValue`,
  `shipments[0].id`, `payments[0].method`)

**Two changes go beyond annotation and alter real behavior**, worth
naming explicitly rather than describing this as a typing-only pass:
`extractCollection()` now filters out non-array entries instead of
passing them through (every caller immediately does `$item['code']` on
them), and the checkout controller now skips the API call entirely when
a required id or token is missing, instead of calling with a
blind `(int)` cast of a possibly-absent value.

**Verified twice, not just once:** `make phpstan` reports clean on both
sides (Sylius level 5, Sulu level `max`), *and* the user confirmed a
complete checkout still runs through in the browser — static analysis
can prove type-correctness, not that a flow still works end to end.

**Method, worth keeping:** progress was measured after each file rather
than at the end (77 → 41 → 6 → 0), which caught early that the helper
approach worked before it was applied 41 more times to the largest
file.

---

## 42. Switching folders doesn't give you a fresh database — `docker-compose.yaml`'s fixed project name

**Not a bug in the shipped code — a fact about this project's Docker
setup, worth documenting explicitly** after it caused real confusion
while diagnosing an unrelated image-caching question.

`docker-compose.yaml` declares a fixed `name: sulu-sylius-kickstarter`
at the top level. Docker identifies volumes by this project name, not
by the folder path they're launched from — so **every versioned folder
(`sulu-sylius-kickstarter-v25`, `-v26`, `-v27`, ...) shares the exact
same MySQL data volume**, unless that volume is explicitly removed.
`docker compose down` (without `-v`) stops containers but leaves
volumes untouched.

**The practical consequence:** switching to a fresh version folder and
running `make setup` there does *not* guarantee a clean database. If an
older folder's containers were only stopped with a plain `down`, the
new folder's `make setup` reuses the same volume — old product rows,
old uploaded images, and old state carry over silently.
`doctrine:database:create --if-not-exists` won't recreate an existing
database, and this project's product fixture is idempotent
(`if (null !== $this->productRepository->findOneBy(...)) continue;`),
so a stale volume can make a "fresh" install look identical to the
previous one, with no error or warning anywhere.

**The fix is procedural, not a code change:** use `down -v` (not just
`down`) on whichever folder you're leaving, before setting up a new one,
whenever a genuinely clean slate matters - most notably when
diagnosing whether a problem is caused by stale data/cache versus an
actual code issue.

---

## 43. A real bug from the v27 rollback, and generic cross-reference checks added because of it *(v29)*

**The bug:** rolling v27 back to v26 (see the note without a numbered
entry, right after No. 41 in the project history) renamed
`DemoProductsFixture` back to `RockbandProductsFixture` and the Sulu
template key/file back from `landing` to `rockband_landing` - but one
reference was missed: the template XML's `<view>` tag still read
`pages/landing`, while the actual Twig file had already been renamed to
`rockband_landing.html.twig`. The XML was valid. The Twig file was
valid. The link between them pointed at a file that no longer existed.
Opening the homepage in Sulu's admin threw
`Sulu\Bundle\PreviewBundle\Preview\Exception\UnexpectedException`:
*"Page does not exist in 'html' format."* — a Sulu-internal exception
whose wording gives no hint that the actual cause is a page template
whose `<view>` doesn't resolve, not a headless-configuration problem
(a web search on the exact exception text initially pointed toward
Sulu's HeadlessBundle, which turned out not to be installed at all -
a red herring worth naming so it doesn't cost someone else the same
detour).

**Found by request, not by accident:** the user asked for a full
re-verification after the incident, explicitly switching to a stronger
model for it. Fourteen separate cross-reference checks were run by
hand (template chains, fixture chains, `verify.sh`'s own file list
against reality, `render()` targets, Twig `extends` targets, route
names, Caddy rule ordering, Makefile targets mentioned in docs, FIXES.md
number references, environment variable parity, `.PHONY` consistency) -
only the `<view>` tag and three explanatory comments referencing the
old name were actually wrong. Three false positives were caught and
dismissed only after checking them individually (a `sylius_price` Twig
filter that looked undefined because it's registered as
`\Twig\TwigFilter` with a fully-qualified name my search pattern
missed; two `sylius_shop_order_*` names that only appear inside
explanatory comments, not real calls; a `make sense` and a historical
CHANGELOG mention that looked like missing Make targets). Every "no
error" conclusion in that pass was reached by actually running the
check against real files, not by pattern-matching that looked
plausible - the same discipline this project has tried to apply
throughout, applied here to auditing itself.

**Fixed as a process change, not just a one-off patch:** three of these
checks were promising enough to add to `verify.sh` permanently as
section 14, generically rather than hard-coded to today's filenames -
each re-derives what "should" exist from the actual source (the XML,
the PHP, the Twig) rather than a fixed list, so they keep working as
files are renamed or added later, including for a future rename this
project hasn't done yet:

- Every Sulu page template's `<view>` must resolve to an actual
  `<view>.html.twig` file
- Every `render('...')` call in `sulu-overlay/src/` must target an
  existing template
- Every `path('...')` call in a Twig template must match a route name
  actually defined in a Controller

**Tested both directions before shipping**, same as everything else in
this project: each check was run against the real, currently-correct
files (all green), and separately against a deliberately broken
stand-in (a `<view>` pointing nowhere, a `render()` call to a
non-existent template, a `path()` call to an undefined route) to
confirm it actually catches what it's meant to catch, not just that it
runs without error.

---

## 44. Sulu content locale: German instead of the skeleton's English *(v30)*

**Noticed by the user in the Sulu admin**, where the language switcher
showed "en" — on a DACH storefront whose every page is written in
German. Not broken, but wrong for a project meant to be handed to a
team.

**Cause:** `sulu/config/webspaces/website.xml` is generated by the Sulu
skeleton and had never been touched by this project. It ships
`<localization language="en" default="true"/>` as the only content
localization. Sulu doesn't enforce any relationship between a locale
code and the language of the text stored in it, so German content sat
in an "en" slot without complaint — and `SeedHomepageCommand` wrote
`'locale' => 'en'` deliberately, with a comment saying so, precisely
because that was the only locale the webspace defined.

**Two questions verified against Sulu's own documentation before
changing anything**, since both could have had consequences well beyond
a one-word edit:

- **`de` or `de_DE`?** Sulu's localization docs are explicit:
  `<localization language="de"/>` produces the locale code `de`; a
  `country` attribute (`<localization language="de" country="de"/>`)
  produces `de-de`. For a single-language webspace the plain form is
  the correct one. Note this is entirely independent of Sylius' channel
  locales (`de_DE`/`de_AT`/`de_CH`), which live in the Sylius app and
  have nothing to do with Sulu's content localization.
- **Does this add a URL prefix?** No. Per Sulu's webspace docs, a
  `<url>` tag bound to exactly one localization serves it at the bare
  host; only the `{localization}` placeholder form generates
  per-language prefixes. The Caddy routes are therefore unaffected —
  worth checking rather than assuming, since a `/de/` prefix appearing
  in front of every URL would have broken the router config.

**Implemented as an overlay file, not an edit in place:**
`sulu-overlay/config/webspaces/website.xml`. Placing it there matters
for a specific reason — `make sulu-install` runs `sulu-theme` (which
copies the overlay into `./sulu`) *before* `sulu:build dev` (which
initialises PHPCR). Sulu's own docs note that the content tree has to
be re-initialised after adding localizations, so the file must be in
place before that step, and it is.

**`SeedHomepageCommand` updated to `'locale' => 'de'` in the same
change.** These two must agree: seeding into a locale the webspace
doesn't serve would store a homepage that exists in the database but is
unreachable, with no error anywhere — the same silent-failure class as
No. 43. `verify.sh` section 15 now checks the two against each other,
and also that the webspace's `<url>` tags actually cover the declared
localization. Tested in both directions, including against the exact
pre-fix state (webspace `de` + seed command `en`), which it correctly
reports as a hard failure.

**A user-visible caveat worth stating plainly:** this changes the
*content* locale (the language switcher in the admin). It does **not**
change the language of the admin interface itself, including the field
labels on the page form — those follow the logged-in Sulu user's own UI
language setting. The page template already ships both German and
English labels for every field, so nothing needed adding there; the
English labels in the admin come from the user profile, not from this
project's configuration.

**Requires a clean rebuild.** Content is stored per locale, so a
homepage previously created under "en" is simply not present under
"de". This is Sulu behaving correctly, not a migration bug — but it
means `docker compose down -v` (see No. 42) before setting up, or the
seeded homepage will appear to be missing.

**A bug in the new check itself, found while testing it:** section 15
originally grepped the webspace file directly for `<localization ...
default="true"/>`. That file carries a long explanatory comment block
which *itself* contains example `<localization>` tags — so `grep` +
`head -1` was reading the documentation, not the configuration. It
reported "all green" purely because the commented example and the real
value happened to say the same thing. Fixed by stripping XML comments
(`sed '/<!--/,/-->/d'`) before parsing, and verified by changing only
the real tag to a different language while leaving the comment
untouched: the corrected check reports the real value, the original
would have missed it entirely. Worth recording because the failure mode
is subtle — a check that passes for the wrong reason is worse than no
check, since it produces false confidence.

---

## 45. Both Sylius template overrides were in the wrong app entirely *(v31)*

**Reported by the user:** the order confirmation mail still contained
the "Bestellung anzeigen oder Zahlungsart ändern" link that No. 34
supposedly removed in v20 — and clicking it produced a 404
(`No route found for GET /de_DE/order/...`), exactly as predicted back
then. The fix had been written, documented, and shipped. It had simply
never taken effect.

**Cause — an outright placement error, not a subtlety:** both bundle
overrides were created under `sulu-overlay/templates/bundles/`. That
directory is copied by `make sulu-theme` into the **Sulu** app. But
Symfony resolves `templates/bundles/<Bundle>/...` relative to the
application that renders the template, and both of these are rendered
by **Sylius**: the order confirmation mail is sent by Sylius' mailer,
and `sylius_shop_order_thank_you` is a Sylius route. Sylius never saw
either file. Confirmed rather than assumed before changing anything:
`templates/bundles/` does not exist at all in the Sylius container,
while both files are present in the Sulu one.

**Both overrides were affected**, so two documented fixes were silently
inert for eleven versions:
- No. 34's dead-link removal from the order confirmation mail (the one
  the user hit)
- No. 33's thank-you page redirect after an Adyen payment — never
  exercised, because Adyen has never run against a real sandbox, so
  nothing had surfaced it

**Why no check caught it:** `verify.sh` section 13 (added in No. 36)
verifies that Sylius' *original* templates still exist at their
expected vendor paths — the right question, asked in the wrong place.
It never checked whether our overrides sit where the rendering
application would look for them. Section 10's file list did check the
overrides existed, but only that they existed *somewhere*.

**Fix:** both files moved to `templates/bundles/...` in the project
root (the Sylius app). Verified beforehand that this survives a
reinstall: `install-apps.sh` snapshots everything outside its exclude
list before running `composer create-project` and restores it
afterwards, and `templates/` is not on that exclude list — so our files
are stashed and put back over Sylius' own. Also verified that Sylius'
Twig base path really is `/app/templates`
(`debug:config twig default_path`) rather than assumed from convention.

**New guard, `verify.sh` section 16:** fails if any `*.twig` turns up
under `sulu-overlay/templates/bundles/`, naming each offending file,
and reports how many overrides are present in `templates/bundles/`.
Tested in both directions, including against a reconstruction of the
exact broken state.

**Both moved files now carry a "DO NOT MOVE THIS INTO sulu-overlay/"
note** explaining which app renders them — the placement looks
arbitrary from the file alone, and everything else Sulu-adjacent in
this project does live in the overlay, which is precisely how the
mistake happened.

**A near-miss while writing that note:** inserting it into the mail
template initially closed the existing Twig comment block early,
leaving the rest of the original comment outside `{# ... #}` — it would
have been rendered as visible text in every order confirmation mail.
Caught by a comment-balance check immediately afterwards, before
packaging.

---

## 46. Automated tests, part A: unit tests for our own classes *(v32)*

**The largest structural gap in this project, finally being closed.**
Every API detail this codebase relies on was discovered by hand against
a live instance and written into FIXES.md — thorough, but not
repeatable. After a Sylius or Sulu upgrade the whole investigation would
have to be redone from scratch. Being asked to start on tests, the plan
agreed with the user is three parts, in order: **A** unit tests for our
own classes (this entry), **B** integration tests against a running
Sylius API, **C** smoke/front-end tests checking that expected elements
actually render.

**Nothing had to be installed.** PHPUnit is already present in both
apps as a transitive dev dependency, both ship a `tests/` directory
with a `bootstrap.php`, and both configure a test suite pointing at
`tests` — so new test files are picked up automatically. Verified
before writing anything, the same way No. 40 checked for PHPStan.
Worth noting for anyone editing these: the two apps spell the config
file differently — Sylius uses `phpunit.xml.dist`, Sulu uses
`phpunit.dist.xml`. Neither was modified.

**Where the tests live, and why that mattered:** the three classes
covered here (`SyliusShopClient`, `CartManager`, `ShopExtension`) are
Sulu-overlay classes, so their tests go in `sulu-overlay/tests/` and
reach `sulu/tests/` via `make sulu-theme`. That question got asked
explicitly this time rather than assumed — No. 45 had just demonstrated
what happens when a file ends up in the wrong application. Also
verified up front that Sulu's `composer.json` maps `App\Tests\` to
`tests/`, so the namespaces actually resolve.

**A real blocker, found while writing rather than by the user:** the
first draft used `createMock(SyliusShopClient::class)`. All three
classes are `final`, and PHPUnit cannot mock final classes — every test
touching a collaborator would have failed immediately on first run.
Rewritten to construct real instances on top of Symfony's
`MockHttpClient`, simulating the API one layer lower at the HTTP
boundary. That turned out to be the better design anyway: the tests now
exercise the client's real request building and response parsing rather
than a stubbed-out stand-in, and no production code had to lose its
`final` for testability's sake.

**35 tests, 58 assertions, all passing** (confirmed by the user running
`make test` before this was packaged — Claude has no PHP available in
its own environment, so a test run on the user's machine is now a
required step before any version is built). What they pin down:

- **Prices:** cents-to-euro conversion, German separators, `null`
  rendering as empty rather than `0,00 €` (an absent price and a free
  product are different things), configurable currency for the Swiss
  channel
- **Collection unwrapping:** `hydra:member` *and* plain `member`, since
  which one appears is version-dependent (No. 23); non-array entries
  filtered out, which is the behaviour change from No. 41
- **Product mapping:** price read from `defaultVariantData.price` as an
  integer, a non-integer price discarded rather than coerced (a wrong
  price is worse than none), `mainTaxon` IRI reduced to its code,
  products lacking code or name skipped
- **Channel selection via the Host header** (No. 15's bug class):
  `switzerland` must send `switzerland.localhost` and `de_CH`, and
  `germany` must not leak `switzerland` into its headers. The first
  draft of this test asserted only that the header contained
  `localhost` — which `switzerland.localhost` also satisfies, so it
  would have passed without distinguishing the channels at all. Caught
  and tightened before shipping; the same "green for the wrong reason"
  failure as the one in No. 44.
- **Failure behaviour:** an unreachable API or malformed JSON yields
  empty results, never an exception — the storefront has to stay up
  when Sylius is down (No. 23)
- **Cart session logic:** token persisted on creation, reused while
  valid, replaced when the order moved past `cart` state (No. 27) or no
  longer resolves, and the defensive summary reads from No. 41

**`make test` added**, deliberately running `make sulu-theme` first so
the overlay (tests included) is in place. Like `make phpstan`, it is
**not** wired into `make setup` — a kickstarter has to stay installable.

**Still open:** parts B and C. Also untested by design here are the
controllers (they need the Symfony kernel, which belongs to part C),
`SeedHomepageCommand` and the product fixture (database and GD
respectively).

---

## 47. Automated tests, part B: integration tests against the live API *(v33)*

**What part A structurally cannot do.** The unit tests simulate API
responses, so they verify our handling but can never notice that Sylius
started answering differently — a mock happily returns last year's
shape forever. Part B closes that: 10 tests hitting the real Shop API,
asserting the specific facts from FIXES.md No. 23/26/27.

**Kept separate from `make test` on purpose.** A test command that
fails whenever Docker happens to be down stops being run at all:

- `make test` — unit tests only, no containers, `--exclude-group integration`
- `make test-integration` — `--group integration`, needs running containers with fixtures
- `make test-all` — both

Implemented with PHPUnit's `#[Group]` attribute, so Sulu's own
`phpunit.dist.xml` stays untouched. Each integration test also skips
itself with an explanatory message when the API is unreachable or the
fixtures aren't loaded, rather than failing.

**What they pin down**, beyond what a mock could:

- The taxon filter really is `productTaxons.taxon.code` — asserted
  *negatively* too (no microphone in the guitars category), because a
  wrong filter parameter is **silently ignored** rather than erroring
  (No. 23). Without the negative assertion, a regression would show
  every product in every category and still pass.
- Products resolve by `code` and *not* by `slug` — the asymmetry itself
  is pinned down, so a future Sylius making slugs work becomes visible
  rather than being silently relied upon.
- Price and stock really arrive embedded in `defaultVariantData`.
- The full cart lifecycle end to end: create, add item, change quantity
  via `merge-patch+json`, remove, verify empty. One test rather than
  four, because each step needs the previous one's output.
- The Swiss channel resolves *and* prices differently from the German
  one — identical prices would mean the Host header is not selecting
  the channel (No. 15's bug class).

**These tests create real carts** in the database. Deliberate and
harmless: an incomplete cart is an ordinary abandoned cart, and this is
demo data. Nothing completes a checkout or touches existing orders.

**PHPStan now covers `tests` as well**, not just `src`, at Sulu's own
`max` level. Requested explicitly, and it did surface real issues in
the new test code (`json_encode()` returning `string|false`, offset
access on `mixed` when reaching into nested cart structures). Fixing
those made the tests better, not just quieter — unpacking
`$cart['items'][0]['id']` step by step with explicit assertions turns
an "undefined index" crash into a readable failure message.

**A genuine flaw in `make phpstan` itself, found during this work:**
the target analysed `./sulu`, which receives its files from
`make sulu-theme` — but unlike `make test`, it never ran that copy step
first. So it was analysing whatever happened to be in `./sulu` from an
earlier run. Two rounds of "the fix didn't work" turned out to be "the
fix was never copied". Now `make phpstan` runs `sulu-theme` first, same
as `make test`. Worth noting how this hid itself: the target reported
clean results, they were simply about the wrong version of the files.

---

## 48. Only German product translations existed — Austria and Switzerland returned an empty shop *(v33)*

**Found by the first integration test run**, exactly the kind of thing
these tests were added for.

The Swiss channel test failed: `fetchProduct(..., 'switzerland')`
returned `null`. Narrowed down step by step rather than guessed at —
the channel exists and is enabled, its hostname matches what the client
sends, all six products are assigned to it, and all six have Swiss
channel prices. The product *list* endpoint even answered `200`. But it
answered with **zero products**, and the single-product endpoint
answered `404`.

**Cause:** `SELECT locale, COUNT(*) FROM sylius_product_translation`
returned exactly one row — `de_DE`, six products. No `de_AT`, no
`de_CH`. The client sends `Accept-Language: de_CH` for the Swiss
channel, Sylius finds no translation, and returns nothing at all.

**Why the fixture looked correct but wasn't:** it does loop over all
three locales and set name, slug and descriptions for each. But it also
called `setFallbackLocale('de_DE')` inside that loop. With a fallback
pointing at a different locale, Sylius' translatable layer resolves
`getTranslation()` to the fallback's record — so all three iterations
wrote into the same `de_DE` translation, and the other two were never
created. Fixed by setting the fallback to the locale currently being
written (`setFallbackLocale($locale)`), then restoring `de_DE` as the
default afterwards. Same change in the variant translations.

**Confirmed after the fix:** three locales, six translations each, and
the Swiss integration test passes.

**How long this was broken: since v5.** Every version shipped an
Austrian and Swiss channel that was configured, enabled, priced — and
completely empty through the API. It never surfaced because the entire
storefront hard-codes `germany` (see `CHANNEL` in all four
controllers), so nothing ever requested the other two.

---

## 49. Automated tests, part C: front-end smoke tests through Caddy *(v34)*

**The user's own idea**, and a good one: after every new version, check
that the things a visitor actually sees are still there — the checkout
button, the thank-you page, the product tiles. 15 tests, group `smoke`,
`make test-smoke`.

**Routed through Caddy (`http://router`), not straight at Sulu.** That
was the decision worth making carefully: going through the router
exercises the whole chain including the routing rules, which is
otherwise verified by nothing at all — despite the Caddy config having
caused several genuine bugs in this project (No. 15, 16, 28, 30, 33).
So the suite now also asserts that `/shop/` redirects to the Sulu
catalog, that `/shop/admin` points at port 8082, that the Sylius admin
answers there, and that Mailpit is reachable.

**What they check beyond status codes:** that the category overview
lists all five categories by name, that prices render in German format
(`1.299,00 €`, asserted by regex — an English-formatted price would
pass a mere "contains a number" check), that the product page carries
an add-to-cart form with a CSRF token, that every page has the nav and
cart badge, that `lang="de"` is set, and that the checkout guards from
No. 27 really redirect on an empty cart rather than rendering a broken
form. One test walks the whole path: overview → category → product →
cart → checkout address, asserting each page links to the next.

**Stated limits, rather than pretending otherwise:** this is curl, not
a browser. The Adyen Drop-in widget mounts itself with JavaScript, so
these tests only ever see the empty `<div class="dropin-container">` —
never the actual payment form. Same for the Sulu admin, a JavaScript
SPA where a `200` is all that can be asserted. Both would need Panther
or Playwright, which is substantially more infrastructure than this
project carries today.

**One test was wrong, not the code.** The Sylius admin check initially
followed redirects and expected `200`; it got `404`. The cause was the
test's own `Host: localhost` header — Sylius builds redirect URLs from
it, so the login redirect pointed at port 80. And even with
`Host: localhost:8082` it couldn't work, because from inside the Sulu
container `localhost` is that container, not the host machine.
Rewritten to assert what is actually meaningful from in there: `/admin/`
answers `302` toward the login page (running and protected), and
`/admin/login` itself renders `200`.

**That turned up a documentation error in No. 28.** It states that
`/admin/login` "doesn't exist as a standalone route" in this Sylius
version, based on a `404` seen during that investigation. The route
does exist and answers `200` — the old `404` came from the request
context used at the time, not from an absent route. Corrected in place
there; the fix that entry describes is unaffected.

**The three suites are now separate on purpose:**

| Command | Scope | Needs |
|---|---|---|
| `make test` | 35 unit tests | nothing |
| `make test-integration` | 10 tests against the Shop API | running containers, fixtures |
| `make test-smoke` | 15 front-end tests through Caddy | the above, plus a published homepage |
| `make test-all` | all three | as above |

**Still missing:** a CI pipeline tying `make setup`, `make phpstan` and
`make test-all` together. That is now the last item on the original
list of gaps.

## 50. `make test-integration` and `make test-smoke` reported success with zero tests run *(v35)*

**Started as cosmetics, turned out to be a false green.** Both targets
printed their prerequisites as unconditional yellow lines, which looked
like a warning on every successful run. The plan was simply to delete
them: the tests skip themselves in `setUp()` when the API or the stack
is unreachable, and those messages even name the remedy
(`make fixtures`, `make setup`).

**Deliberately breaking it first is what saved this.** With `sylius`
stopped, `make test-integration` skipped all 10 tests — and then printed
`>> Integration tests passed.` PHPUnit exits 0 on a skipped-only run, so
the success line fired with nothing verified. Worse, under `--testdox`
the skip *reasons* are never displayed: the output is ten `↩` glyphs and
no explanation. So the yellow lines were the only remaining hint, and
deleting them would have made things worse, not tidier.

In CI — the next item on the backlog — this would have meant a green
build over completely untested code.

**Fix, in two parts:**

- `--fail-on-skipped` on both phpunit calls. A skipped-only run now
  exits non-zero, the target aborts, and the success line cannot lie.
- The prerequisite hint moved into an `|| { ...; exit 1; }` arm. A
  successful run shows only progress and result; a failed one names the
  remedy. The wording covers a genuine test failure too, since that
  lands in the same arm.

`--display-skipped` was tried and dropped: it has no effect alongside
`--testdox`, and a flag that does nothing invites removal of the ones
that do.

**Corrected along the way:** the hint in `test-smoke` claimed a
published Sulu homepage was required. It is not — the 15 smoke tests
only request controller routes (`/produkte/*`, `/warenkorb/*`,
`/checkout/*`) plus the admin and Mailpit routes, never `/`. The table
in No. 49 above still carries the old claim; it stays as written, since
this file is a record. arc42 ch. 10 is corrected.

**Not changed:** the self-skip logic in the tests, and `make test`,
which has no skip path and still echoes its recipe line.

## 51. `serverVersion=8.0` in both DATABASE_URLs was a deprecated short form *(v37)*

**Found while planning the MySQL 8.4 upgrade, not through a failure.**
Both `DATABASE_URL`s in `docker-compose.yaml` carried
`serverVersion=8.0`. Doctrine picks its SQL platform from that value,
not from the server it actually talks to. Reading
`Driver/AbstractMySQLDriver.php` in DBAL 3.10.6 (the version locked on
the Sylius side) shows two
things: the short form triggers a deprecation (*"Version detection
logic for MySQL will change in DBAL 4. Please specify the version as
the server reports it, e.g. "8.4.0" instead of "8.4""*), and a value
that lags behind the image keeps generating SQL for the older platform
without any error.

**On the Sulu side it was worse than a deprecation.** The v37 test run
showed that Sulu resolves to DBAL 4.4.4, not 3.x. DBAL 4 no longer
normalizes the value; it compares it directly with PHP's
`version_compare()`, which ranks `"8.0"` *below* `"8.0.0"`. So
`serverVersion=8.0` failed the `>= 8.0.0` check and Sulu got DBAL's
generic `MySQLPlatform` for MySQL < 8 — on a MySQL 8.0 server, with no
visible error. (Verified: `version_compare("8.0", "8.0.0", ">=")` is
`false`, `version_compare("8.4", "8.4.0", ">=")` is `false` as well.)
The Sylius side on DBAL 3.10.6 normalizes the short form and picked the
correct 8.0 platform, which is why nothing stood out there.

The usual templates don't help: the Sulu CI itself uses
`serverVersion=8.4`, which on DBAL 4 would select the 8.0 platform, not
8.4, and Sylius' docs show `mariadb-<version>`, which DBAL deprecates as
well. Neither is a safe template.

**Fix:**

- Both URLs now read `serverVersion=8.4.0`, matching `mysql:8.4`.
- The service was renamed from `mysql` to `database` in the same
  version (container `ks_database`, volume `database_data`, init
  scripts under `docker/database/init/`). The `MYSQL_*` variables keep
  the names the official image documents.
- New `make verify` section 17 checks that the image tag, both
  `serverVersion` values (x.y.z form, same major.minor) and the running
  server agree, and that no line in `docker-compose.yaml` or the
  `Makefile` still refers to a service named `mysql`. Tested against
  the correct state and against eleven deliberately broken ones,
  including the unchanged v36 files.

**Not changed:** the fallback `DATABASE_URL` in `.env` and
`compose.override.dist.yml` — both are Sylius skeleton files that the
container environment overrides.

## 52. Own product photos never reached the container *(v38)*

**Symptom:** README promised that a photo dropped into
`var/demo-images/<product-code>.jpg` would be used by the fixture instead
of the generated placeholder. It never was — the fixture always drew the
icon placeholder, without any error.

**Cause:** `docker-compose.yaml` mounts the named volume `sylius_var` at
`/app/var`, on top of the bind mount of the application folder. A named
volume takes precedence at its mount point, and when it is created empty
Docker copies content from the *image*, not from the bind mount. So
everything the host wrote under `var/` was invisible inside the
container, including the photos. The generated placeholders were visible
because the fixture writes them from inside the container, into the
volume.

**Fix:** `install-apps.sh` seeds the photos through a container of its
own, mounting `sylius-overlay/var/demo-images` at `/seed` and copying
from there into `/app/var/demo-images`. Verified by file size: 5447 bytes
on the host, 5447 bytes in the container.

**Lesson:** a bind mount and a named volume on the same path is not a
merge. The more specific mount wins, and the one below it is simply gone
— silently, which is why this survived several versions.

## 53. The guest-checkout patch had been a no-op since version 1 *(v38)*

**Symptom:** While rewriting `install-apps.sh` for v38 the patch that
sets `/checkout` to `PUBLIC_ACCESS` was made strict — abort instead of
continue when the expected pattern is not found. The install then aborted
immediately: `neither ROLE_USER nor PUBLIC_ACCESS found for /checkout`.

**Cause:** Sylius 2.2's skeleton has no `access_control` entry for
`/checkout` at all. It has entries for `/login`, `/register`, `/verify`
and `/account`, and checkout is simply public. The patch had therefore
never changed anything, in any version — the old code reported "guest
checkout OK or entry not present" and moved on, which read like a
successful check.

**Fix:** the patch now distinguishes four states. No entry: nothing to
do. `ROLE_USER`: patch it. `PUBLIC_ACCESS`: already correct. Any other
role: abort, because that is the case where the patch would silently do
nothing and guest checkout would break.

**Lesson:** two failure modes hid behind one message. Making a check
strict is only safe after confirming what the expected state actually is
upstream — the strict version was shipped without that check, and turned
the normal case into a fatal error.

## What's structurally different

**Verify before installing.** `make verify` tests classes, service IDs,
guest checkout, and configuration collisions — all at once, before
anything gets loaded. This is exactly what would have saved the eight
earlier rounds.

**Stages instead of a monolith.** `make setup` stops after `verify`.
Every step is individually repeatable: `deps`, `verify`, `fixtures`,
`test-checkout`.

**`composer install --no-scripts`.** The auto-scripts used to call
`cache:clear`, and any configuration error would take the entire
installation down with it. Now `cache:clear` runs as its own step —
errors are attributable.

**Products can be switched off.** The riskiest component lives in
`config/packages/dach_products.yaml`. `make products-off` removes it, the
rest of the shop keeps running.

**Emergency exit.** `make fixtures-fallback` loads Sylius' default
fixtures. That gives a runnable checkout within minutes if the DACH
fixtures act up.

---

## What's still unverified

Honestly: the fixture option names. Whether `sylius_fixtures` in 2.2
still expects `custom:` under `channel:`, whether `menu_taxon` is still
the right name, whether `tax_calculation_strategy` still exists — none of
that could be verified ahead of time.

`make verify` writes the full reference to
`var/reference/sylius_fixtures.txt`. If `make fixtures` fails, send both
the error message **and** the matching section from that file — that
turns it into one round instead of eight.
