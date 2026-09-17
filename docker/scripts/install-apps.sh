#!/usr/bin/env bash
# ===========================================================================
#  First install: sets up Sylius (root) and Sulu (./sulu).
#
#  VERSION CHOICE - WHY SULU 3.0:
#  Sulu 2.6 internally requires "symfony/proxy-manager-bridge ^5.4 || ^6.0" -
#  a bridge deprecated since Symfony 5.4 that only exists up to Symfony 6.4.
#  The rest of Sulu's own composer.json allows Symfony up to ^7.0, so
#  Composer pulls most of the app to 7.4 and pins only the bridge to 6.4 -
#  two Symfony generations in the same install. Result: the generated
#  container code for "sulu_core.proxy_manager.configuration" gets a
#  self-referencing fallback getter and runs into an infinite recursion
#  that fills up memory, no matter how high memory_limit is set.
#  Sulu 3.0 removed ProxyManager entirely (native Symfony lazy loading) and
#  is officially supported on Symfony 6.4-7.4 - exactly the version the
#  Sylius side here also uses. Hence 3.0, not 2.6.
#
#  Since v37 the default is ~3.0.9 instead of ^3.0: it still picks up
#  every 3.0.x patch at install time, but no untested 3.1 (version rule:
#  docs/decisions.md, ADR-11).
#
#  OVERLAY INSTEAD OF NO-CLOBBER:
#  "cp -rn" protected our own files, but silently swallowed Sylius'
#  originals of the same name (config/packages/_sylius.yaml). Now: stash
#  our files -> install Sylius/Sulu COMPLETELY -> restore our files.
#
#  This script is idempotent - running it more than once is harmless.
# ===========================================================================
set -uo pipefail

DC="docker compose -f docker-compose.yaml --env-file .env.docker"
SYLIUS_VERSION="${SYLIUS_VERSION:-^2.2}"
SULU_VERSION="${SULU_VERSION:-~3.0.9}"
STASH=".kickstarter-overlay"

g() { printf "\033[0;32m%s\033[0m\n" "$1"; }
y() { printf "\033[0;33m%s\033[0m\n" "$1"; }
r() { printf "\033[0;31m%s\033[0m\n" "$1"; }
b() { printf "\n\033[0;34m>> %s\033[0m\n" "$1"; }

# Automatically determines what's ours: everything already in the project
# BEFORE the Sylius install runs. A hand-maintained list forgot the
# Makefile and docker/ in version 2 - Sylius overwrote them.
STASH_EXCLUDES="./.git ./vendor ./node_modules ./sulu ./var ./.kickstarter-overlay ./.sylius-original ./.env.docker"

# ---------------------------------------------------------------------------
b "0/6  Cleaning up compose file collisions"
# ---------------------------------------------------------------------------
# Sylius Standard ships its own compose.yml. Without -f, "docker compose"
# gives it precedence over docker-compose.yaml.
mkdir -p .sylius-original
for f in compose.yml compose.yaml compose.override.yml compose.override.yaml; do
  if [[ -f "$f" ]]; then
    mv "$f" .sylius-original/
    y "   $f -> .sylius-original/"
  fi
done
g "   no collisions"

# ---------------------------------------------------------------------------
b "1/6  Stashing our own files"
# ---------------------------------------------------------------------------
if [[ -f composer.json ]] && grep -q "sylius/sylius" composer.json 2>/dev/null; then
  g "   Sylius already installed - no snapshot needed"
else
  rm -rf "$STASH"
  mkdir -p "$STASH"
  EXCL=""
  for e in $STASH_EXCLUDES; do EXCL="$EXCL -path $e -prune -o"; done
  # shellcheck disable=SC2086
  find . $EXCL -type f -print | while read -r f; do
    mkdir -p "$STASH/$(dirname "$f")"
    cp "$f" "$STASH/$f"
  done
  g "   $(find "$STASH" -type f | wc -l | tr -d ' ') files stashed (snapshot before Sylius)"
fi

# ---------------------------------------------------------------------------
b "2/6  Sylius Standard into the project root"
# ---------------------------------------------------------------------------
if [[ -f composer.json ]] && grep -q "sylius/sylius" composer.json 2>/dev/null; then
  g "   already present - skipped"
else
  $DC run --rm --no-deps -T sylius bash -c "
    set -e
    rm -rf /tmp/sk
    composer create-project sylius/sylius-standard:${SYLIUS_VERSION} /tmp/sk \
      --no-interaction --no-scripts --prefer-dist
    # -r without -n: Sylius wins on filename collisions. Our files come
    # back in step 3.
    cp -r /tmp/sk/. /app/
    rm -rf /tmp/sk
  " || { r "   Sylius install failed"; exit 1; }
  g "   Sylius set up"
fi

# Move Sylius' own compose files aside again
for f in compose.yml compose.yaml compose.override.yml compose.override.yaml; do
  [[ -f "$f" ]] && mv "$f" .sylius-original/ && y "   $f -> .sylius-original/"
done

# ---------------------------------------------------------------------------
b "3/6  Restoring our own files"
# ---------------------------------------------------------------------------
# IMPORTANT: config/packages/_sylius.yaml belongs to Sylius and must NOT be
# restored - in case it ever ended up in the snapshot.
rm -f "$STASH/config/packages/_sylius.yaml"

if [[ -d "$STASH" ]]; then
  COUNT=0
  while IFS= read -r f; do
    rel="${f#$STASH/}"
    mkdir -p "$(dirname "$rel")"
    cp "$f" "$rel"
    COUNT=$((COUNT+1))
  done < <(find "$STASH" -type f)
  g "   $COUNT files restored"
  chmod +x docker/scripts/*.sh 2>/dev/null || true
else
  y "   no snapshot found - skipped"
fi

# In case Sylius ships its own _sylius.yaml: confirm it's there
if [[ -f config/packages/_sylius.yaml ]]; then
  g "   config/packages/_sylius.yaml (Sylius original) present"
else
  r "   WARNING: config/packages/_sylius.yaml is missing - incomplete Sylius copy?"
fi

# --- Service definitions: no longer needed ----------------------------------
# Fixture and command use #[Autowire] attributes. If a block still exists in
# services.yaml from an older version, it needs to go - otherwise it
# collides with the attributes.
if grep -q "RockbandProductsFixture\|CreateTestOrdersCommand" config/services.yaml 2>/dev/null; then
  cp config/services.yaml config/services.yaml.bak
  python3 - <<'PY'
import re
p = "config/services.yaml"
s = open(p).read()
for cls, tag in [("App\\Fixture\\RockbandProductsFixture", "sylius_fixtures.fixture"),
                 ("App\\Command\\CreateTestOrdersCommand", "console.command")]:
    i = s.find("    " + cls + ":")
    if i == -1:
        continue
    j = s.find("- { name: " + tag + " }", i)
    if j == -1:
        continue
    j = s.find("\n", j) + 1
    s = s[:i] + s[j:]
open(p, "w").write(s)
print("   old service definitions removed (backup: config/services.yaml.bak)")
PY
else
  g "   no old service definitions in services.yaml"
fi

# ---------------------------------------------------------------------------
b "4/6  Guest checkout in security.yaml"
# ---------------------------------------------------------------------------
SEC="config/packages/security.yaml"
if [[ -f "$SEC" ]] && grep -q "shop_regex%/checkout" "$SEC" && grep "shop_regex%/checkout" "$SEC" | grep -q "ROLE_USER"; then
  cp "$SEC" "$SEC.bak"
  sed -i.tmp 's|\(shop_regex%/checkout".*role:\) ROLE_USER|\1 PUBLIC_ACCESS|' "$SEC"
  rm -f "$SEC.tmp"
  g "   /checkout set to PUBLIC_ACCESS (backup: $SEC.bak)"
else
  g "   guest checkout OK or entry not present"
fi

# ---------------------------------------------------------------------------
b "5/6  Default locale in config/parameters.yaml"
# ---------------------------------------------------------------------------
# The Symfony skeleton generates "locale: en_US" - this global %locale%
# parameter propagates to, among others, sylius_locale.locale,
# sylius_money.locale and translation.default_locale, and decides where
# Sylius redirects BEFORE a channel has been determined from the hostname
# (e.g. /shop/ -> /shop/en_US/, even though the channel DB configuration
# correctly says de_DE - see FIXES.md No. 14).
PARAMS="config/parameters.yaml"
if [[ -f "$PARAMS" ]] && grep -q "locale: en_US" "$PARAMS"; then
  cp "$PARAMS" "$PARAMS.bak"
  sed -i.tmp 's/locale: en_US/locale: de_DE/' "$PARAMS"
  rm -f "$PARAMS.tmp"
  g "   locale: en_US -> de_DE set (backup: $PARAMS.bak)"
else
  g "   config/parameters.yaml already correct or not present"
fi

# ---------------------------------------------------------------------------
b "6/6  Sulu skeleton (${SULU_VERSION}) into ./sulu"
# ---------------------------------------------------------------------------
# Installed directly through the "sulu" service, whose ./sulu:/app mount is
# already declared in docker-compose.yaml - no extra -v mount needed.
mkdir -p sulu
if [[ -f sulu/composer.json ]]; then
  g "   already present - skipped"
else
  $DC run --rm --no-deps -T sulu bash -c "
    set -e
    rm -rf /tmp/sulu3
    composer create-project sulu/skeleton:${SULU_VERSION} /tmp/sulu3 \
      --no-interaction --no-scripts --prefer-dist
    cp -r /tmp/sulu3/. /app/
    rm -rf /tmp/sulu3
  " || { r "   Sulu install failed"; exit 1; }
  g "   Sulu ${SULU_VERSION} set up"
fi

# --- Safety net: the outdated proxy-manager-bridge must not show up again --
if $DC run --rm --no-deps -T sulu bash -c \
     "composer show symfony/proxy-manager-bridge >/dev/null 2>&1"; then
  r "   WARNING: symfony/proxy-manager-bridge is installed."
  r "   That was the cause of the infinite loop with Sulu 2.6. Check with:"
  r "     make shell-sulu -> composer why symfony/proxy-manager-bridge"
else
  g "   symfony/proxy-manager-bridge not present (expected for Sulu 3.x)"
fi

echo ""
g "First install done. Next step:  make verify"
