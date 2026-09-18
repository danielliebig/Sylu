#!/usr/bin/env bash
# ===========================================================================
#  Checks the environment BEFORE anything gets installed.
#
#  Motivation: with the first version of this kickstarter, the Sylius 2.x
#  incompatibilities surfaced one at a time while booting - eight failed
#  attempts in a row. This script finds all of them at once.
#
#  Usage:  make verify
# ===========================================================================
set -uo pipefail

DC="docker compose -f docker-compose.yaml --env-file versions.env --env-file .env.docker"
RUN="$DC exec -T sylius"
FAILED=0

# The two generated applications. Host paths need the prefix, container
# paths (/app/...) do not - the bind mount puts each app at /app.
SYLIUS_DIR="sylius"
SULU_DIR="sulu"

g() { printf "\033[0;32m%s\033[0m\n" "$1"; }
r() { printf "\033[0;31m%s\033[0m\n" "$1"; }
y() { printf "\033[0;33m%s\033[0m\n" "$1"; }
b() { printf "\n\033[0;34m== %s ==\033[0m\n" "$1"; }

mkdir -p var/reference

# ---------------------------------------------------------------------------
#  Section 0 runs always: it establishes what CAN be checked at all.
#
#  Before v38 every section ran unconditionally. With Docker stopped that
#  produced nineteen sections of missing classes, missing service IDs and
#  missing files - and the one line that mattered ("the Docker daemon is
#  not running") scrolled past at the very top. Now the prerequisites come
#  first, and whatever cannot be checked is skipped with a single line
#  instead of a wall of false findings.
# ---------------------------------------------------------------------------
DOCKER_UP=0
APPS_PRESENT=0
CONTAINERS_UP=0

section_0() {
  b "0. Prerequisites"

  if docker info >/dev/null 2>&1; then
    DOCKER_UP=1
    g "  Docker daemon reachable"
  else
    r "  Docker daemon not reachable - start Docker, then retry"
    FAILED=1
  fi

  if [[ -f "$SYLIUS_DIR/composer.json" ]] && [[ -f "$SULU_DIR/composer.json" ]]; then
    APPS_PRESENT=1
    g "  ./$SYLIUS_DIR and ./$SULU_DIR present"
  else
    r "  application folders incomplete - run: make install-apps"
    FAILED=1
  fi

  if [[ $DOCKER_UP -eq 1 ]]; then
    RUNNING="$($DC ps --services --filter status=running 2>/dev/null)"
    if grep -q "^sylius$" <<< "$RUNNING" && grep -q "^sulu$" <<< "$RUNNING"; then
      CONTAINERS_UP=1
      g "  containers sylius and sulu running"
    else
      r "  containers not running - run: make docker-start"
      FAILED=1
    fi
  fi
}

# ---------------------------------------------------------------------------
section_1() {
b "1. Versions"
# ---------------------------------------------------------------------------
$RUN php -v 2>/dev/null | head -1
$RUN composer show --locked 2>/dev/null \
  | grep -E "^(sylius/sylius|symfony/framework-bundle|doctrine/orm|sylius/fixtures-bundle) " \
  || y "  (composer.lock doesn't exist yet)"
}

# ---------------------------------------------------------------------------
section_2() {
b "2. PHP classes"
# ---------------------------------------------------------------------------
$RUN php -r '
require "/app/vendor/autoload.php";
$c = [
 "Sylius\\Abstraction\\StateMachine\\StateMachineInterface",
 "Sylius\\Component\\Resource\\Factory\\FactoryInterface",
 "Sylius\\Bundle\\FixturesBundle\\Fixture\\AbstractFixture",
 "Sylius\\Component\\Core\\OrderCheckoutTransitions",
 "Sylius\\Component\\Payment\\PaymentTransitions",
 "Sylius\\Component\\Core\\Uploader\\ImageUploaderInterface",
 "Sylius\\Component\\Order\\Processor\\OrderProcessorInterface",
 "Sylius\\Component\\Order\\Modifier\\OrderItemQuantityModifierInterface",
 "Sylius\\Component\\Order\\Modifier\\OrderModifierInterface",
 "Doctrine\\ORM\\EntityManagerInterface",
];
$bad = 0;
foreach ($c as $x) {
  $ok = interface_exists($x) || class_exists($x);
  if (!$ok) $bad++;
  printf("  %-68s %s\n", $x, $ok ? "OK" : "MISSING");
}
exit($bad > 0 ? 1 : 0);
' 2>/dev/null || { r "  -> Classes are missing. Please send the output."; FAILED=1; }
}

# ---------------------------------------------------------------------------
section_3() {
b "3. Service IDs (verified against Sylius 2.2.8)"
# ---------------------------------------------------------------------------
# This list wasn't guessed - it was cross-checked against a running
# Sylius 2.2.8 install via "debug:container". The fixture and the command
# reference every ID here directly via #[Autowire(service: '...')] on the
# constructor parameter - there's no service definition block left in
# config/services.yaml that could get overridden.
IDS="
sylius.factory.product
sylius.factory.product_variant
sylius.factory.channel_pricing
sylius.factory.product_image
sylius.factory.product_taxon
sylius.factory.order
sylius.factory.order_item
sylius.factory.customer
sylius.factory.address
sylius.repository.taxon
sylius.repository.channel
sylius.repository.tax_category
sylius.repository.product
sylius.repository.product_variant
sylius.repository.shipping_method
sylius.repository.payment_method
sylius.repository.customer
sylius.uploader.image
sylius.modifier.order_item_quantity
sylius.modifier.order
sylius.order_processing.order_processor
sylius_abstraction.state_machine
doctrine.orm.default_entity_manager
"
MISSING=""
for id in $IDS; do
  if $RUN php bin/console debug:container --format=txt "$id" >/dev/null 2>&1; then
    printf "  %-45s OK\n" "$id"
  else
    printf "  %-45s \033[0;31mMISSING\033[0m\n" "$id"
    MISSING="$MISSING $id"
    FAILED=1
  fi
done

if [[ -n "$MISSING" ]]; then
  y ""
  y "  Look up missing IDs with:"
  for id in $MISSING; do
    short=$(echo "$id" | awk -F. '{print $NF}')
    echo "    make shell-sylius -> php bin/console debug:container | grep $short"
  done
fi
}

# ---------------------------------------------------------------------------
section_4() {
b "4. Saving the fixture reference"
# ---------------------------------------------------------------------------
if $RUN php bin/console config:dump-reference sylius_fixtures \
     > var/reference/sylius_fixtures.txt 2>/dev/null; then
  g "  var/reference/sylius_fixtures.txt written ($(wc -l < var/reference/sylius_fixtures.txt) lines)"
else
  y "  config:dump-reference failed (kernel not booting yet?)"
fi
}

# ---------------------------------------------------------------------------
section_5() {
b "5. Guest checkout"
# ---------------------------------------------------------------------------
SEC="$SYLIUS_DIR/config/packages/security.yaml"
if [[ -f "$SEC" ]]; then
  LINE=$(grep -n "shop_regex%/checkout" "$SEC" 2>/dev/null)
  if [[ -z "$LINE" ]]; then
    y "  No /checkout entry in $SEC."
    y "  Add under access_control:"
    y "    - { path: \"%sylius.security.shop_regex%/checkout\", role: PUBLIC_ACCESS }"
  elif echo "$LINE" | grep -q "ROLE_USER"; then
    r "  /checkout is set to ROLE_USER -> guest checkout is blocked!"
    r "  Change it to PUBLIC_ACCESS in $SEC."
    FAILED=1
  else
    g "  /checkout is public - guest checkout works"
  fi
else
  y "  $SEC not found."
fi
}

# ---------------------------------------------------------------------------
section_6() {
b "6. Default locale ($SYLIUS_DIR/config/parameters.yaml)"
# ---------------------------------------------------------------------------
# The Symfony skeleton default is "en_US" and propagates to several global
# parameters (sylius_locale.locale, sylius_money.locale,
# translation.default_locale). That decides the FIRST redirect (e.g.
# /shop/ -> /shop/en_US/), BEFORE a channel is determined from the
# hostname - regardless of what the channel DB says for default_locale_id.
# See FIXES.md No. 14.
PARAMS="$SYLIUS_DIR/config/parameters.yaml"
if [[ -f "$PARAMS" ]]; then
  if grep -q "locale: en_US" "$PARAMS"; then
    r "  $PARAMS is set to en_US - /shop/ redirects to en_US"
    r "  instead of de_DE, even though the channel configuration is correct."
    FAILED=1
  else
    g "  $PARAMS: locale set correctly"
  fi
else
  y "  $PARAMS not found"
fi
}

# ---------------------------------------------------------------------------
section_7() {
b "7. Sulu version / Symfony compatibility"
# ---------------------------------------------------------------------------
# Sulu 2.6 + Symfony 7.4 produced a broken mixed install
# (symfony/proxy-manager-bridge forced to 6.4, the rest on 7.4) and with it
# a container compilation that eats unbounded memory. Sulu 3.0 removed
# ProxyManager - it must not show up here anymore.
DC_RUN="$DC exec -T sulu"
if $DC_RUN bash -c "composer show symfony/proxy-manager-bridge >/dev/null 2>&1"; then
  r "  symfony/proxy-manager-bridge is installed - the known cause of an"
  r "  infinite container loop with Sulu < 3.0 on Symfony 7.4."
  FAILED=1
else
  g "  symfony/proxy-manager-bridge not present (expected)"
fi
$DC_RUN bash -c "composer show sulu/sulu 2>/dev/null | grep versions" || y "  Sulu version could not be determined (is the container running?)"
}

# ---------------------------------------------------------------------------
section_8() {
b "8. Configuration collisions"
# ---------------------------------------------------------------------------
for f in compose.yml compose.yaml compose.override.yml; do
  [[ -f "$f" ]] && { r "  $f exists - collides with docker-compose.yaml!"; FAILED=1; }
done
[[ -f "$SYLIUS_DIR/config/packages/_sylius.yaml" ]] \
  && { grep -q "Kickstarter\|dach_demo" "$SYLIUS_DIR/config/packages/_sylius.yaml" 2>/dev/null \
       && { r "  $SYLIUS_DIR/config/packages/_sylius.yaml has been overwritten!"; FAILED=1; } \
       || g "  $SYLIUS_DIR/config/packages/_sylius.yaml is Sylius' original"; }
# Fixture/Command have wired themselves via #[Autowire] attributes directly
# in PHP code since version 2.2 - an entry in services.yaml would now
# actually be a red flag (a duplicate, possibly conflicting definition).
if grep -q "RockbandProductsFixture\|CreateTestOrdersCommand" "$SYLIUS_DIR/config/services.yaml" 2>/dev/null; then
  r "  services.yaml still contains an old service definition -> remove it"
  r "  (fixture/command wire themselves via the #[Autowire] attribute)"
  FAILED=1
else
  g "  services.yaml clean - no leftover service definition"
fi
# "File missing" and "file present but wrong" are different findings. The
# old single-line check reported the second for both, which sent us
# looking for an outdated fixture when in fact the overlay had simply not
# been copied yet.
FIXTURE="$SYLIUS_DIR/src/Fixture/RockbandProductsFixture.php"
if [[ ! -f "$FIXTURE" ]]; then
  r "  $FIXTURE does not exist - run make sylius-theme"
  FAILED=1
elif grep -q "#\[Autowire" "$FIXTURE"; then
  g "  RockbandProductsFixture uses #[Autowire] attributes"
else
  y "  RockbandProductsFixture has no #[Autowire] attribute - an old version?"
fi
}

# ---------------------------------------------------------------------------
section_9() {
b "9. Caddy router: base configuration"
# ---------------------------------------------------------------------------
# Unlike the earlier nginx setup (FIXES.md No. 15), there's no Host-header
# trap to check here: Caddy's reverse_proxy forwards the Host header
# unchanged by default, regardless of how many extra headers a block sets.
#
# Two things checked:
#   - /media/ and /bundles/sylius* still set X-Forwarded-Prefix (unchanged
#     by the admin-port move in FIXES.md No. 28 - these paths are plain
#     asset hosting, not the admin UI)
#   - the dedicated Sylius admin port (:8082) exists and proxies to
#     sylius:80 (see FIXES.md No. 28) - it must NOT set X-Forwarded-Prefix,
#     since Sylius needs to generate root-relative asset paths there
CADDYFILE="docker/caddy/Caddyfile"
if [[ -f "$CADDYFILE" ]]; then
  if grep -q "handle /media" "$CADDYFILE" && \
     grep -A3 "handle /media" "$CADDYFILE" | grep -q "X-Forwarded-Prefix"; then
    g "  Caddyfile: /media/ sets X-Forwarded-Prefix"
  else
    r "  Caddyfile: X-Forwarded-Prefix is missing in the /media/ block"
    FAILED=1
  fi

  if grep -q ":8082" "$CADDYFILE" && \
     grep -A5 ":8082" "$CADDYFILE" | grep -q "reverse_proxy sylius:80"; then
    g "  Caddyfile: dedicated Sylius admin port (:8082) present"
  else
    r "  Caddyfile: :8082 admin port block is missing or doesn't proxy to sylius:80"
    r "  Sylius admin would be unreachable, or share the /build/admin/ path with Sulu again (see FIXES.md No. 28)"
    FAILED=1
  fi
else
  y "  $CADDYFILE not found"
fi
}

# ---------------------------------------------------------------------------
section_10() {
b "10. Sulu-Sylius integration (sulu-overlay/)"
# ---------------------------------------------------------------------------
# A pure existence/consistency check, not a behavior test - whether the
# Sylius Shop API is actually reachable is checked by the landing page
# itself at runtime (with a fallback, see FIXES.md No. 18). This is only
# about making sure make sulu-theme doesn't copy nothing.
if [[ -d sulu-overlay ]]; then
  MISSING=""
  for f in \
    "sulu-overlay/config/webspaces/website.xml" \
    "sulu-overlay/config/templates/pages/rockband_landing.xml" \
    "sulu-overlay/templates/pages/rockband_landing.html.twig" \
    "sulu-overlay/templates/base.html.twig" \
    "sulu-overlay/public/css/site.css" \
    "sulu-overlay/src/Service/SyliusShopClient.php" \
    "sulu-overlay/src/Command/SeedHomepageCommand.php" \
    "sulu-overlay/src/Twig/ShopExtension.php" \
    "sulu-overlay/src/Controller/CatalogController.php" \
    "sulu-overlay/templates/catalog/taxons.html.twig" \
    "sulu-overlay/templates/catalog/taxon.html.twig" \
    "sulu-overlay/templates/catalog/product.html.twig" \
    "sulu-overlay/src/Service/CartManager.php" \
    "sulu-overlay/src/Controller/CartController.php" \
    "sulu-overlay/templates/cart/index.html.twig" \
    "sulu-overlay/src/Controller/CheckoutController.php" \
    "sulu-overlay/templates/checkout/address.html.twig" \
    "sulu-overlay/templates/checkout/shipping.html.twig" \
    "sulu-overlay/templates/checkout/payment.html.twig" \
    "sulu-overlay/templates/checkout/summary.html.twig" \
    "sulu-overlay/templates/checkout/confirmation.html.twig" \
    "sulu-overlay/tests/Twig/ShopExtensionTest.php" \
    "sulu-overlay/tests/Service/SyliusShopClientTest.php" \
    "sulu-overlay/tests/Service/CartManagerTest.php" \
    "sulu-overlay/tests/Integration/SyliusApiTest.php" \
    "sulu-overlay/tests/Smoke/StorefrontSmokeTest.php"
  do
    [[ -f "$f" ]] || MISSING="$MISSING $f"
  done
  if [[ -n "$MISSING" ]]; then
    r "  sulu-overlay/ is incomplete, missing:$MISSING"
    FAILED=1
  else
    g "  sulu-overlay/ complete (26 files)"
  fi
  if [[ -d sulu ]] && [[ ! -f sulu/templates/pages/rockband_landing.html.twig ]]; then
    y "  ./sulu is installed, but the theme hasn't been copied yet -> make sulu-theme"
  fi
else
  y "  sulu-overlay/ not found (integration missing from the package)"
fi
}

# ---------------------------------------------------------------------------
section_11() {
b "11. Adyen payment gateway configuration (see FIXES.md No. 30)"
# ---------------------------------------------------------------------------
# Read-only sanity checks - not a substitute for actually testing a
# payment with real sandbox credentials, which this script can't do.
if grep -q "gatewayFactory: .adyen." "$SYLIUS_DIR/config/packages/sylius_shipping_payment.yaml" 2>/dev/null; then
  g "  Adyen payment method fixture present"
else
  r "  Adyen payment method missing from $SYLIUS_DIR/config/packages/sylius_shipping_payment.yaml"
  FAILED=1
fi

if grep -A8 "code: .adyen." "$SYLIUS_DIR/config/packages/sylius_shipping_payment.yaml" 2>/dev/null | grep -q "enabled: false"; then
  g "  Adyen starts disabled by default (safe - demo mode is the default)"
else
  y "  Could not confirm Adyen starts disabled - check $SYLIUS_DIR/config/packages/sylius_shipping_payment.yaml"
fi

if grep -q "payment/adyen" docker/caddy/Caddyfile 2>/dev/null; then
  g "  Caddyfile routes the Adyen payment paths"
else
  r "  Caddyfile is missing the Adyen payment routes - the Drop-in widget"
  r "  would be unreachable from the browser (see FIXES.md No. 30)"
  FAILED=1
fi

if grep -q "build/app/shop" docker/caddy/Caddyfile 2>/dev/null; then
  g "  Caddyfile routes the Adyen widget asset bundle (see FIXES.md No. 32)"
else
  r "  Caddyfile is missing the /build/app/shop/ route - the Adyen"
  r "  Drop-in widget's JS/CSS bundle would be unreachable (see FIXES.md No. 32)"
  FAILED=1
fi

if grep -q "order/thank-you" docker/caddy/Caddyfile 2>/dev/null; then
  g "  Caddyfile routes Sylius' order thank-you page (see FIXES.md No. 33)"
else
  r "  Caddyfile is missing the /order/thank-you route - Adyen's hard-coded"
  r "  success redirect would land nowhere (see FIXES.md No. 33)"
  FAILED=1
fi

if [[ -f "$SYLIUS_DIR/templates/bundles/SyliusShopBundle/order/thank_you.html.twig" ]]; then
  g "  Sylius thank-you template override present (redirects to our own confirmation page)"
else
  r "  Missing $SYLIUS_DIR/templates/bundles/SyliusShopBundle/order/thank_you.html.twig"
  r "  Without it, a customer would briefly see Sylius' own confirmation page after paying (see FIXES.md No. 33)"
  FAILED=1
fi
}

# ---------------------------------------------------------------------------
section_12() {
b "12. Server portability and mail polish (see FIXES.md No. 34)"
# ---------------------------------------------------------------------------
if grep -q '{host}:8082' docker/caddy/Caddyfile 2>/dev/null; then
  g "  Caddyfile: /shop/admin redirect uses {host}, not a hard-coded localhost"
else
  r "  Caddyfile: /shop/admin redirect still hard-codes a hostname - would"
  r "  misdirect visitors on any host other than localhost (see FIXES.md No. 34)"
  FAILED=1
fi

if grep -q "sylius_mailer:" "$SYLIUS_DIR/config/packages/dach_demo.yaml" 2>/dev/null; then
  g "  Custom mail sender configured (overrides Sylius' no-reply@example.com default)"
else
  y "  No custom sylius_mailer sender found - order confirmation mail will use Sylius' default sender"
fi

if [[ -f "$SYLIUS_DIR/templates/bundles/SyliusCoreBundle/Email/Blocks/OrderConfirmation/_content.html.twig" ]]; then
  g "  Order confirmation mail template override present (dead order-view link removed)"
else
  r "  Missing $SYLIUS_DIR/templates/bundles/SyliusCoreBundle/Email/Blocks/OrderConfirmation/_content.html.twig"
  FAILED=1
fi

for var in ADYEN_ENVIRONMENT ADYEN_MERCHANT_ACCOUNT ADYEN_API_KEY ADYEN_CLIENT_KEY ADYEN_HMAC_KEY; do
  grep -q "^${var}=" .env.docker.example 2>/dev/null \
    || { r "  $var missing from .env.docker.example"; FAILED=1; }
done
g "  All 5 Adyen environment variables present in .env.docker.example"
}

# ---------------------------------------------------------------------------
section_13() {
b "13. Template overrides still match their Sylius original (see FIXES.md No. 36)"
# ---------------------------------------------------------------------------
# Our bundle-template overrides (No. 33, No. 34) only take effect while
# Sylius' own original template still lives at the exact path we're
# shadowing. A future "composer update sylius/sylius-standard" could
# move or rename either original with zero error message - our override
# would simply stop applying, silently (the exact failure mode already
# seen once in this project with EnableFlushStamp, No. 19-22). This is a
# warning, not a hard failure: a missing original doesn't necessarily
# mean our override broke, only that it's worth checking by hand.
# Without vendor/ there is nothing to compare against. Saying "a Sylius
# update may have moved it" in that case points at the wrong cause.
if [[ ! -d "$SYLIUS_DIR/vendor/sylius/sylius" ]]; then
  y "  $SYLIUS_DIR/vendor/sylius/sylius does not exist - run make deps"
  y "  (skipping the comparison against Sylius' originals)"
  SKIP_ORIGINALS=1
else
  SKIP_ORIGINALS=0
fi

ORIG_THANK_YOU="$SYLIUS_DIR/vendor/sylius/sylius/src/Sylius/Bundle/ShopBundle/templates/order/thank_you.html.twig"
if [[ $SKIP_ORIGINALS -eq 1 ]]; then
  :
elif [[ -f "$ORIG_THANK_YOU" ]]; then
  g "  Sylius' original thank_you.html.twig still present - our override should still apply"
else
  y "  $ORIG_THANK_YOU not found at its expected path."
  y "  A Sylius update may have moved it - our override in"
  y "  $SYLIUS_DIR/templates/bundles/SyliusShopBundle/order/thank_you.html.twig"
  y "  may no longer take effect. Worth checking by hand (see FIXES.md No. 33)."
fi

ORIG_ORDER_CONF="$SYLIUS_DIR/vendor/sylius/sylius/src/Sylius/Bundle/CoreBundle/Resources/views/Email/Blocks/OrderConfirmation/_content.html.twig"
if [[ $SKIP_ORIGINALS -eq 1 ]]; then
  :
elif [[ -f "$ORIG_ORDER_CONF" ]]; then
  g "  Sylius' original order-confirmation _content.html.twig still present - our override should still apply"
else
  y "  $ORIG_ORDER_CONF not found at its expected path."
  y "  A Sylius update may have moved it - our override in"
  y "  $SYLIUS_DIR/templates/bundles/SyliusCoreBundle/Email/Blocks/OrderConfirmation/_content.html.twig"
  y "  may no longer take effect, and the dead order-view link could be back (see FIXES.md No. 34)."
fi
}

# ---------------------------------------------------------------------------
section_14() {
b "14. Cross-references between our own files (see FIXES.md No. 43)"
# ---------------------------------------------------------------------------
# Generic, not hard-coded to today's filenames - each check re-derives
# what "should" exist from the source of truth (the XML/PHP/Twig that
# references it) rather than a fixed list, so it keeps working as files
# are added, renamed, or removed. Added after a real bug: renaming a
# Sulu page template left one <view> pointing at the old name while the
# Twig file itself had already been renamed - valid XML, valid Twig,
# wrong link between them, so no syntax check caught it (FIXES.md No. 43).

# --- a) Sulu page template <view> -> actual Twig file ----------------------
XML_CHECK_FAILED=0
if [[ -d "sulu-overlay/config/templates/pages" ]]; then
  for xml in sulu-overlay/config/templates/pages/*.xml; do
    [[ -f "$xml" ]] || continue
    view=$(grep -oE "<view>[^<]*</view>" "$xml" | sed -E 's#</?view>##g')
    if [[ -z "$view" ]]; then
      y "  $xml has no <view> tag - skipped"
      continue
    fi
    target="sulu-overlay/templates/${view}.html.twig"
    if [[ -f "$target" ]]; then
      g "  $xml -> <view>$view</view> -> $target (exists)"
    else
      r "  $xml declares <view>$view</view>, but $target does not exist"
      XML_CHECK_FAILED=1
    fi
  done
else
  y "  sulu-overlay/config/templates/pages not found - skipped"
fi
[[ $XML_CHECK_FAILED -eq 1 ]] && FAILED=1

# --- b) Controller render() calls -> actual Twig files ----------------------
RENDER_CHECK_FAILED=0
if [[ -d "sulu-overlay/src" ]]; then
  while IFS= read -r tpl; do
    [[ -z "$tpl" ]] && continue
    target="sulu-overlay/templates/$tpl"
    if [[ -f "$target" ]]; then
      g "  render('$tpl') -> $target (exists)"
    else
      r "  A controller calls render('$tpl'), but $target does not exist"
      RENDER_CHECK_FAILED=1
    fi
  done < <(grep -rho "render('[^']*'" sulu-overlay/src/ 2>/dev/null | sed "s/render('//;s/'$//" | sort -u)
fi
[[ $RENDER_CHECK_FAILED -eq 1 ]] && FAILED=1

# --- c) Twig path()/url() route names -> actually defined in a Controller --
ROUTE_CHECK_FAILED=0
if [[ -d "sulu-overlay/src/Controller" ]] && [[ -d "sulu-overlay/templates" ]]; then
  grep -rho "name: '[^']*'" sulu-overlay/src/Controller/ 2>/dev/null | sed "s/name: '//;s/'$//" | sort -u > /tmp/verify_routes_defined.txt
  while IFS= read -r route; do
    [[ -z "$route" ]] && continue
    if grep -qx "$route" /tmp/verify_routes_defined.txt 2>/dev/null; then
      g "  path('$route') -> defined in a Controller"
    else
      r "  A template calls path('$route'), but no Controller defines that route name"
      ROUTE_CHECK_FAILED=1
    fi
  done < <(grep -rho "path('[^']*'" sulu-overlay/templates/ 2>/dev/null | sed "s/path('//;s/'$//" | sort -u)
  rm -f /tmp/verify_routes_defined.txt
fi
[[ $ROUTE_CHECK_FAILED -eq 1 ]] && FAILED=1
}

# ---------------------------------------------------------------------------
section_15() {
b "15. Sulu content locale consistency (see FIXES.md No. 44)"
# ---------------------------------------------------------------------------
# The webspace defines which content localizations exist; the seed
# command writes into one of them. If they drift apart, the seeded
# homepage lands in a locale the webspace doesn't serve - content that
# exists in the database but is unreachable, with no error anywhere.
# Same silent-mismatch class as section 14.
WEBSPACE_FILE="sulu-overlay/config/webspaces/website.xml"
SEED_FILE="sulu-overlay/src/Command/SeedHomepageCommand.php"

if [[ -f "$WEBSPACE_FILE" ]] && [[ -f "$SEED_FILE" ]]; then
  # Strip XML comments first. This file carries a long explanatory
  # comment block that itself contains example <localization> tags -
  # without this, grep would happily read the documentation instead of
  # the configuration (caught while testing this very check: it passed
  # only because the commented example and the real value happened to
  # agree).
  WS_CLEAN=$(sed '/<!--/,/-->/d' "$WEBSPACE_FILE")

  # Default localization: the <localization> carrying default="true",
  # falling back to the first one if none is marked default.
  WS_LOCALE=$(printf '%s' "$WS_CLEAN" | grep -oE '<localization[^>]*default="true"[^>]*/>' \
    | grep -oE 'language="[^"]*"' | head -1 | sed 's/language="//;s/"//')
  if [[ -z "$WS_LOCALE" ]]; then
    WS_LOCALE=$(printf '%s' "$WS_CLEAN" | grep -oE '<localization[^>]*language="[^"]*"' \
      | grep -oE 'language="[^"]*"' | head -1 | sed 's/language="//;s/"//')
  fi

  SEED_LOCALE=$(grep -oE "'locale'[[:space:]]*=>[[:space:]]*'[^']*'" "$SEED_FILE" \
    | head -1 | sed "s/.*=>[[:space:]]*'//;s/'//")

  if [[ -z "$WS_LOCALE" ]] || [[ -z "$SEED_LOCALE" ]]; then
    y "  Could not read both locales (webspace: '${WS_LOCALE:-?}', seed command: '${SEED_LOCALE:-?}') - check by hand"
  elif [[ "$WS_LOCALE" == "$SEED_LOCALE" ]]; then
    g "  Webspace localization and seed command agree on '$WS_LOCALE'"
  else
    r "  Locale mismatch: the webspace serves '$WS_LOCALE', but SeedHomepageCommand writes '$SEED_LOCALE'."
    r "  The seeded homepage would be stored in a locale nobody can reach (see FIXES.md No. 44)."
    FAILED=1
  fi

  # The URL tags must cover the localization, or the portal serves nothing.
  if printf '%s' "$WS_CLEAN" | grep -qE "<url language=\"$WS_LOCALE\""; then
    g "  Webspace <url> tags cover the '$WS_LOCALE' localization"
  else
    r "  No <url language=\"$WS_LOCALE\"> found in $WEBSPACE_FILE - the portal would not serve that locale"
    FAILED=1
  fi
else
  y "  Webspace or seed command not found - skipped"
fi
}

# ---------------------------------------------------------------------------
section_16() {
b "16. Sylius bundle overrides sit in the Sylius app, not the Sulu overlay (see FIXES.md No. 45)"
# ---------------------------------------------------------------------------
# Symfony resolves templates/bundles/<Bundle>/... relative to the app
# that renders them. A Sylius*Bundle override therefore has to live in
# the Sylius app's own templates/ (the project root). Putting it in
# sulu-overlay/ makes "make sulu-theme" copy it into the Sulu app
# instead, where nothing ever reads it - no error, no warning, just an
# override that does nothing. That happened for two versions with both
# of this project's Sylius overrides (No. 45), so it's checked now.
if [[ -d "sulu-overlay/templates/bundles" ]]; then
  STRAY=$(find sulu-overlay/templates/bundles -type f -name "*.twig" 2>/dev/null)
  if [[ -n "$STRAY" ]]; then
    r "  Sylius bundle override(s) found under sulu-overlay/ - they will be copied"
    r "  into the Sulu app and silently never used. Move these to sylius-overlay/templates/bundles/:"
    while IFS= read -r f; do
      [[ -n "$f" ]] && r "    $f"
    done <<< "$STRAY"
    FAILED=1
  else
    g "  No stray Sylius overrides under sulu-overlay/templates/bundles"
  fi
else
  g "  No sulu-overlay/templates/bundles directory (correct - Sylius overrides belong in sylius-overlay)"
fi

# The overlay is the source of truth; the app folder only holds the copy.
# Both are checked, because an override that exists in the overlay but not
# in the app means the copy step did not run.
if [[ -d "sylius-overlay/templates/bundles" ]]; then
  OVERRIDE_COUNT=0
  MISSING_OVERRIDE=0
  while IFS= read -r f; do
    [[ -z "$f" ]] && continue
    OVERRIDE_COUNT=$((OVERRIDE_COUNT + 1))
    rel="${f#sylius-overlay/}"
    if [[ ! -f "$SYLIUS_DIR/$rel" ]]; then
      r "  $f has not arrived in $SYLIUS_DIR/$rel - run make sylius-theme"
      MISSING_OVERRIDE=1
    fi
  done < <(find sylius-overlay/templates/bundles -type f -name "*.twig" 2>/dev/null)
  [[ $MISSING_OVERRIDE -eq 1 ]] && FAILED=1
  g "  $OVERRIDE_COUNT Sylius bundle override(s) in sylius-overlay/templates/bundles"
else
  y "  No sylius-overlay/templates/bundles - this project expects two Sylius overrides there (No. 33, No. 34)"
fi
}

# ---------------------------------------------------------------------------
section_17() {
b "17. Database version consistency (see FIXES.md No. 51)"
# ---------------------------------------------------------------------------
# The MySQL version lives in three places that must agree: the image tag
# of the "database" service and serverVersion in both DATABASE_URLs.
# Doctrine picks its SQL platform from serverVersion, not from the server
# it talks to, so a stale value keeps generating SQL for the old version
# without any error. The short form ("8.0", "8.4") is deprecated in DBAL 3
# and silently wrong in DBAL 4, which the Sulu side uses: "8.0" ranks
# below "8.0.0" there and selects the platform for MySQL < 8. DBAL wants
# the version as the server reports it ("8.4.0"). Before v37 both
# URLs carried the deprecated "8.0" (No. 51), and the service was renamed
# from "mysql" to "database" in the same version, so leftovers of the old
# name are checked too.
DB_CHECK_FAILED=0
SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+$'
MINOR_RE='^[0-9]+\.[0-9]+'

# --- a) the derived MySQL version ------------------------------------------
# Since v38 the version is not written in docker-compose.yaml any more; it
# is derived into versions.env (make versions) and read from there.
DB_IMAGE=$(sed -n 's/^MYSQL_IMAGE=//p' versions.env 2>/dev/null | head -1)
DB_SERVER=$(sed -n 's/^MYSQL_SERVER_VERSION=//p' versions.env 2>/dev/null | head -1)
DB_MINOR=""
if [[ -z "$DB_IMAGE" || -z "$DB_SERVER" ]]; then
  r "  MYSQL_IMAGE or MYSQL_SERVER_VERSION missing from versions.env - run make versions"
  DB_CHECK_FAILED=1
else
  DB_TAG="${DB_IMAGE##*:}"
  if [[ "$DB_TAG" =~ $MINOR_RE ]]; then
    DB_MINOR="${BASH_REMATCH[0]}"
    g "  versions.env: $DB_IMAGE (major.minor $DB_MINOR)"
  else
    r "  image tag \"$DB_IMAGE\" has no major.minor version - pin at least x.y"
    DB_CHECK_FAILED=1
  fi
  if ! [[ "$DB_SERVER" =~ $SEMVER_RE ]]; then
    r "  MYSQL_SERVER_VERSION=$DB_SERVER is not in x.y.z form (deprecated by Doctrine DBAL, see No. 51)"
    DB_CHECK_FAILED=1
  elif [[ -n "$DB_MINOR" ]] && [[ "$DB_SERVER" != "$DB_MINOR".* ]]; then
    r "  MYSQL_SERVER_VERSION=$DB_SERVER does not match $DB_IMAGE"
    DB_CHECK_FAILED=1
  else
    g "  versions.env: serverVersion=$DB_SERVER"
  fi
fi

# --- b) both DATABASE_URLs use that value, and the right host --------------
URL_COUNT=0
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  URL_COUNT=$((URL_COUNT + 1))
  host=$(printf '%s' "$line" | sed -E 's#.*@([^:/]+)[:/].*#\1#')
  if [[ "$host" != "database" ]]; then
    r "  DATABASE_URL points to host \"$host\" instead of \"database\""
    DB_CHECK_FAILED=1
  fi
  if printf '%s' "$line" | grep -q 'serverVersion=${MYSQL_SERVER_VERSION'; then
    g "  DATABASE_URL -> host $host, serverVersion from versions.env"
  else
    r "  DATABASE_URL does not take serverVersion from \${MYSQL_SERVER_VERSION} -"
    r "  a hard-coded value here drifts away from versions.env unnoticed"
    DB_CHECK_FAILED=1
  fi
done < <(grep -E '^[[:space:]]+DATABASE_URL:' docker-compose.yaml 2>/dev/null)
if [[ $URL_COUNT -lt 2 ]]; then
  r "  Expected a DATABASE_URL for both sylius and sulu in docker-compose.yaml, found $URL_COUNT"
  DB_CHECK_FAILED=1
fi

# --- c) leftovers of the old service name "mysql" --------------------------
LEFTOVER=$( { grep -nE '^[[:space:]]+mysql:[[:space:]]*$|PMA_HOST:[[:space:]]*mysql[[:space:]]*$' docker-compose.yaml 2>/dev/null | sed 's#^#docker-compose.yaml:#'; \
              grep -nE 'exec[[:space:]]+(-T[[:space:]]+)?mysql([[:space:]]|$)' Makefile 2>/dev/null | sed 's#^#Makefile:#'; } )
if [[ -n "$LEFTOVER" ]]; then
  r "  The database service is called \"database\" now, but these lines still use \"mysql\":"
  while IFS= read -r l; do [[ -n "$l" ]] && r "    $l"; done <<< "$LEFTOVER"
  DB_CHECK_FAILED=1
else
  g "  No references to a service named \"mysql\" in docker-compose.yaml or Makefile"
fi

# --- d) the running server really is that version --------------------------
DB_RUNNING=$($DC exec -T database mysqld --version 2>/dev/null | grep -oE 'Ver [0-9]+\.[0-9]+\.[0-9]+' | sed 's/Ver //')
if [[ -z "$DB_RUNNING" ]]; then
  y "  Running MySQL version could not be determined (is the database container up?) - skipped"
elif [[ -n "$DB_MINOR" ]] && [[ "$DB_RUNNING" != "$DB_MINOR".* ]]; then
  r "  Running server is MySQL $DB_RUNNING, but versions.env asks for $DB_IMAGE"
  r "  (container from an old image? try: make docker-stop && make docker-start)"
  DB_CHECK_FAILED=1
else
  g "  Running server: MySQL $DB_RUNNING"
fi
[[ $DB_CHECK_FAILED -eq 1 ]] && FAILED=1
}

# ---------------------------------------------------------------------------
section_18() {
b "18. Derived versions (kickstarter.yaml -> versions.env)"
# ---------------------------------------------------------------------------
# Everything about PHP, MySQL and Node is derived from the two constraints
# in kickstarter.yaml (docs/decisions.md, ADR-11). Three ways that can go
# wrong silently, so all three are checked: versions.env drifting away from
# the manifest, the fallback defaults in docker-compose.yaml/Dockerfile
# drifting away from versions.env, and .env.docker overriding a derived
# value because it was copied over from an older version folder.
VER_CHECK_FAILED=0
DERIVED_KEYS="SYLIUS_VERSION SULU_VERSION FRANKENPHP_VERSION MYSQL_IMAGE MYSQL_SERVER_VERSION NODE_MAJOR"

if [[ ! -f versions.env ]]; then
  r "  versions.env is missing - run make versions"
  VER_CHECK_FAILED=1
else
  for key in $DERIVED_KEYS; do
    grep -q "^${key}=" versions.env \
      || { r "  $key missing from versions.env - run make versions"; VER_CHECK_FAILED=1; }
  done

  # --- a) versions.env satisfies the manifest -------------------------------
  for project in sylius sulu; do
    constraint=$(sed -n "s/^${project}: *\"\([^\"]*\)\".*/\1/p" kickstarter.yaml | head -1)
    upper=$(printf '%s' "$project" | tr '[:lower:]' '[:upper:]')
    resolved=$(sed -n "s/^${upper}_VERSION=//p" versions.env | head -1)
    if [[ -z "$constraint" || -z "$resolved" ]]; then
      r "  cannot compare $project: constraint or resolved version missing"
      VER_CHECK_FAILED=1
      continue
    fi
    wanted="${constraint#\~}"
    if [[ "${resolved%.*}" != "${wanted%.*}" ]]; then
      r "  $project $resolved is outside $constraint (different minor series)"
      VER_CHECK_FAILED=1
    elif [[ "${resolved##*.}" -lt "${wanted##*.}" ]]; then
      r "  $project $resolved is older than the $constraint minimum"
      VER_CHECK_FAILED=1
    else
      g "  $project $constraint -> $resolved"
    fi
  done

  # --- b) fallback defaults still match ------------------------------------
  check_default() {
    local file="$1" key="$2" expected="$3" found
    found=$(grep -oE "\\$\{${key}:-[^}]*\}" "$file" 2>/dev/null | head -1 | sed -E "s/.*:-//; s/\}//")
    if [[ -z "$found" ]]; then
      found=$(grep -oE "^ARG ${key}=.*" "$file" 2>/dev/null | head -1 | sed -E "s/^ARG ${key}=//")
    fi
    if [[ -z "$found" ]]; then
      y "  $key has no fallback default in $file - skipped"
    elif [[ "$found" != "$expected" ]]; then
      r "  $file falls back to $key=$found, versions.env says $expected"
      VER_CHECK_FAILED=1
    else
      g "  $file fallback $key=$found matches versions.env"
    fi
  }
  for key in FRANKENPHP_VERSION NODE_MAJOR MYSQL_IMAGE MYSQL_SERVER_VERSION; do
    check_default docker-compose.yaml "$key" "$(sed -n "s/^${key}=//p" versions.env | head -1)"
  done
  check_default docker/php/Dockerfile FRANKENPHP_VERSION "$(sed -n 's/^FRANKENPHP_VERSION=//p' versions.env | head -1)"
  check_default docker/php/Dockerfile NODE_MAJOR "$(sed -n 's/^NODE_MAJOR=//p' versions.env | head -1)"

  # --- c) .env.docker must not override a derived value ---------------------
  if [[ -f .env.docker ]]; then
    for key in $DERIVED_KEYS; do
      grep -qE "^${key}=" .env.docker \
        && { r "  .env.docker sets $key - that overrides versions.env, remove the line"; VER_CHECK_FAILED=1; }
    done
    g "  .env.docker checked for version overrides"
  fi

  # --- d) the reviewed lock really holds that Sylius version ---------------
  LOCK="sylius-overlay/composer.lock"
  if [[ -f "$LOCK" ]]; then
    LOCKED=$(grep -A2 '"name": "sylius/sylius"' "$LOCK" | sed -n 's/.*"version": "v\{0,1\}\([0-9][^"]*\)".*/\1/p' | head -1)
    WANT=$(sed -n 's/^SYLIUS_VERSION=//p' versions.env | head -1)
    if [[ -z "$LOCKED" ]]; then
      y "  could not read sylius/sylius from $LOCK - skipped"
    elif [[ "$LOCKED" != "$WANT" ]]; then
      r "  $LOCK holds sylius/sylius $LOCKED, versions.env says $WANT"
      r "  (after a version change: make deps && make verify && make freeze-locks)"
      VER_CHECK_FAILED=1
    else
      g "  $LOCK holds sylius/sylius $LOCKED"
    fi
  else
    y "  $LOCK not present yet - make freeze-locks writes it"
  fi
fi
[[ $VER_CHECK_FAILED -eq 1 ]] && FAILED=1
}

# ---------------------------------------------------------------------------
section_19() {
b "19. Structure: kickstarter in the root, applications in their folders"
# ---------------------------------------------------------------------------
# Up to v37 Sylius lived in the repository root, which is what made the
# stash-and-restore dance in install-apps.sh necessary. If application
# files reappear up here, that mixing is back - and it is hard to spot,
# because everything keeps working until the next Sylius update.
STRUCT_CHECK_FAILED=0
for leftover in composer.json composer.lock src templates public var/cache vendor bin/console; do
  if [[ -e "$leftover" ]]; then
    r "  $leftover is back in the repository root - application code belongs in $SYLIUS_DIR/"
    STRUCT_CHECK_FAILED=1
  fi
done
[[ $STRUCT_CHECK_FAILED -eq 0 ]] && g "  No application files in the repository root"

# Every overlay file has to exist in its application. var/ is skipped: it
# is a named volume inside the container, so the host copy is invisible
# there by design (install-apps.sh seeds it through a container).
for pair in "sylius-overlay:$SYLIUS_DIR" "sulu-overlay:$SULU_DIR"; do
  overlay="${pair%%:*}"
  app="${pair##*:}"
  [[ -d "$overlay" ]] || { r "  $overlay/ is missing - package incomplete"; STRUCT_CHECK_FAILED=1; FAILED=1; continue; }
  if [[ ! -d "$app" ]]; then
    y "  $app/ does not exist yet - run make install-apps"
    continue
  fi
  MISSING=0
  TOTAL=0
  while IFS= read -r f; do
    [[ -z "$f" ]] && continue
    rel="${f#"$overlay"/}"
    [[ "$rel" == var/* ]] && continue
    TOTAL=$((TOTAL + 1))
    [[ -f "$app/$rel" ]] || { r "  $f has not arrived in $app/$rel"; MISSING=1; }
  done < <(find "$overlay" -type f 2>/dev/null)
  if [[ $MISSING -eq 1 ]]; then
    r "  run: make ${app}-theme"
    STRUCT_CHECK_FAILED=1
  else
    g "  all $TOTAL files from $overlay/ are present in $app/"
  fi
done
[[ $STRUCT_CHECK_FAILED -eq 1 ]] && FAILED=1
}

# ---------------------------------------------------------------------------
#  Dispatcher
#
#  Without arguments every section runs, as before. With arguments only
#  those run - "verify.sh 18 19" while working on one of them, instead of
#  sitting through all nineteen.
# ---------------------------------------------------------------------------
ALL_SECTIONS="1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19"

# Sections that talk to a running container, and sections that read files
# inside the two applications. Both are pointless before the install ran.
NEEDS_CONTAINER=" 1 2 3 4 7 "
NEEDS_APPS=" 5 6 8 11 12 13 16 "

section_0

SECTIONS="$ALL_SECTIONS"
[[ $# -gt 0 ]] && SECTIONS="$*"

for n in $SECTIONS; do
  if ! declare -F "section_$n" >/dev/null 2>&1; then
    r "No section $n - available: 0 $ALL_SECTIONS"
    exit 2
  fi
  if [[ $CONTAINERS_UP -eq 0 ]] && [[ "$NEEDS_CONTAINER" == *" $n "* ]]; then
    y "== $n. skipped - needs running containers =="
    continue
  fi
  if [[ $APPS_PRESENT -eq 0 ]] && [[ "$NEEDS_APPS" == *" $n "* ]]; then
    y "== $n. skipped - needs ./$SYLIUS_DIR and ./$SULU_DIR =="
    continue
  fi
  "section_$n"
done

# ---------------------------------------------------------------------------
echo ""
if [[ $FAILED -eq 0 ]]; then
  g "=== All green. Continue with: make fixtures ==="
  exit 0
else
  r "=== There are findings. Please review them above. ==="
  y "Emergency exit: disable products and start without them:"
  y "  make products-off && make fixtures"
  exit 1
fi
