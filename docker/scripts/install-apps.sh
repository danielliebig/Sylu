#!/usr/bin/env bash
# ===========================================================================
#  First install: generates ./sylius and ./sulu from the two overlays.
#
#  STRUCTURE
#  The repository root is the kickstarter and holds no application code.
#  Each application is created from its upstream skeleton and then receives
#  everything we own from <name>-overlay/, including composer.json and
#  composer.lock. So the installed dependency set is the reviewed one, not
#  whatever Composer resolves today (version rule: docs/decisions.md,
#  ADR-11).
#
#  WHY THIS REPLACED THE OLD STASH MECHANISM
#  Up to v37 Sylius lived in the project root, so its installer overwrote
#  our files and they had to be snapshotted and restored around it. With
#  separate folders nothing collides, and the overlay is simply copied on
#  top afterwards.
#
#  OVERLAY VS PATCH
#  Files we own are in the overlay and are copied verbatim. Two files
#  belong to the skeleton and are only PATCHED, so that upstream changes in
#  them are not frozen by us: config/packages/security.yaml (guest
#  checkout) and config/parameters.yaml (default locale). Both patches
#  abort if neither the expected pattern nor the desired result is found -
#  a silently skipped patch is how the locale bug in FIXES.md No. 14 came
#  back twice.
#
#  SKELETON VERSIONS
#  The skeleton repositories are versioned independently of the frameworks
#  they install: sylius/sylius-standard stops at 2.2.4 while sylius/sylius
#  is at 2.2.9. The manifest constraint is therefore reduced to its minor
#  series (~2.2.9 -> ^2.2) for the skeleton. What actually decides the
#  framework version is the overlay's composer.lock; make verify checks it
#  against the manifest.
#
#  This script is idempotent - running it more than once is harmless.
# ===========================================================================
set -uo pipefail

DC="docker compose -f docker-compose.yaml --env-file versions.env --env-file .env.docker"
MANIFEST="kickstarter.yaml"

g() { printf "\033[0;32m%s\033[0m\n" "$1"; }
y() { printf "\033[0;33m%s\033[0m\n" "$1"; }
r() { printf "\033[0;31m%s\033[0m\n" "$1"; }
b() { printf "\n\033[0;34m>> %s\033[0m\n" "$1"; }

die() { r "   $1"; exit 1; }

# ---------------------------------------------------------------------------
#  Manifest
# ---------------------------------------------------------------------------
manifest_constraint() {
  local project="$1" value
  value="$(sed -n "s/^${project}: *\"\([^\"]*\)\".*/\1/p" "$MANIFEST" | head -1)"
  [[ -n "$value" ]] || die "$MANIFEST has no entry for $project"
  printf '%s' "$value"
}

# "~2.2.9" or "2.2.9" -> "^2.2"
skeleton_constraint() {
  local value="$1" stripped
  stripped="${value#\~}"
  [[ "$stripped" =~ ^([0-9]+)\.([0-9]+)\. ]] \
    || die "cannot read a minor series from constraint '$value'"
  printf '^%s.%s' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
}

SYLIUS_SKELETON="$(skeleton_constraint "$(manifest_constraint sylius)")"
SULU_SKELETON="$(skeleton_constraint "$(manifest_constraint sulu)")"

# ---------------------------------------------------------------------------
#  Skeletons
# ---------------------------------------------------------------------------
# --no-install on purpose: the overlay's composer.lock arrives in step 3
# and "make deps" installs from that. Installing twice would first resolve
# a dependency set nobody reviewed.
install_skeleton() {
  local service="$1" dir="$2" package="$3" constraint="$4"

  mkdir -p "$dir"
  if [[ -f "$dir/composer.json" ]]; then
    g "   already present - skipped"
    return 0
  fi
  $DC run --rm --no-deps -T "$service" bash -c "
    set -e
    rm -rf /tmp/skeleton
    composer create-project ${package}:${constraint} /tmp/skeleton \
      --no-interaction --no-scripts --no-install --prefer-dist
    cp -r /tmp/skeleton/. /app/
    rm -rf /tmp/skeleton
  " || die "$package install failed"
  g "   $package $constraint set up in $dir"
}

b "1/5  Sylius skeleton (${SYLIUS_SKELETON}) into ./sylius"
install_skeleton sylius sylius sylius/sylius-standard "$SYLIUS_SKELETON"

b "2/5  Sulu skeleton (${SULU_SKELETON}) into ./sulu"
install_skeleton sulu sulu sulu/skeleton "$SULU_SKELETON"

# ---------------------------------------------------------------------------
b "3/5  Copying both overlays into the applications"
# ---------------------------------------------------------------------------
copy_overlay() {
  local overlay="$1" dir="$2"
  [[ -d "$overlay" ]] || die "$overlay/ is missing - package incomplete"
  cp -r "$overlay/." "$dir/"
  g "   $(find "$overlay" -type f | wc -l | tr -d ' ') files from $overlay -> $dir"
}

copy_overlay sylius-overlay sylius
copy_overlay sulu-overlay sulu

# var/ is a named volume inside the container, which shadows the bind
# mount: files copied on the host never show up under /app/var. The demo
# photos therefore have to go in through a container of their own.
if [[ -n "$(find sylius-overlay/var/demo-images -type f 2>/dev/null)" ]]; then
  $DC run --rm --no-deps -T \
    -v "$PWD/sylius-overlay/var/demo-images:/seed:ro" sylius bash -c "
      set -e
      mkdir -p /app/var/demo-images
      cp -a /seed/. /app/var/demo-images/
    " && g "   demo photos copied into the var volume" \
      || y "   demo photos could not be copied - the fixture falls back to icons"
else
  g "   no demo photos supplied - the fixture uses icons"
fi

# ---------------------------------------------------------------------------
b "4/5  Patching two skeleton files"
# ---------------------------------------------------------------------------
# Guest checkout: if an access_control entry for /checkout exists and puts
# it behind ROLE_USER, every anonymous cart lands on the login form.
#
# Sylius 2.2's skeleton ships NO such entry - checkout is public by
# default, so there is nothing to patch and that is not a finding. Only an
# entry with an unexpected role aborts: that is the case where the patch
# would silently do nothing and guest checkout would break. make verify
# section 5 reports the same three states.
SEC="sylius/config/packages/security.yaml"
[[ -f "$SEC" ]] || die "$SEC not found - incomplete Sylius skeleton?"
CHECKOUT_LINE="$(grep "shop_regex%/checkout" "$SEC")"
if [[ -z "$CHECKOUT_LINE" ]]; then
  g "   no /checkout entry - checkout is public in this Sylius version"
elif grep -q "ROLE_USER" <<< "$CHECKOUT_LINE"; then
  cp "$SEC" "$SEC.bak"
  sed -i.tmp 's|\(shop_regex%/checkout".*role:\) ROLE_USER|\1 PUBLIC_ACCESS|' "$SEC"
  rm -f "$SEC.tmp"
  g "   /checkout set to PUBLIC_ACCESS (backup: $SEC.bak)"
elif grep -q "PUBLIC_ACCESS" <<< "$CHECKOUT_LINE"; then
  g "   /checkout already PUBLIC_ACCESS"
else
  die "/checkout entry in $SEC has an unexpected role: $CHECKOUT_LINE"
fi

# Default locale: the global %locale% parameter propagates to
# sylius_locale.locale, sylius_money.locale and translation.default_locale
# and decides where Sylius redirects BEFORE a channel has been determined
# from the hostname (FIXES.md No. 14).
PARAMS="sylius/config/parameters.yaml"
[[ -f "$PARAMS" ]] || die "$PARAMS not found - incomplete Sylius skeleton?"
if grep -q "locale: en_US" "$PARAMS"; then
  cp "$PARAMS" "$PARAMS.bak"
  sed -i.tmp 's/locale: en_US/locale: de_DE/' "$PARAMS"
  rm -f "$PARAMS.tmp"
  g "   locale: en_US -> de_DE (backup: $PARAMS.bak)"
elif grep -q "locale: de_DE" "$PARAMS"; then
  g "   locale already de_DE"
else
  die "no 'locale:' entry found in $PARAMS"
fi

# Sylius' own _sylius.yaml must be there - it used to get lost in the old
# stash-and-restore dance.
if [[ -f sylius/config/packages/_sylius.yaml ]]; then
  g "   config/packages/_sylius.yaml (Sylius original) present"
else
  die "sylius/config/packages/_sylius.yaml is missing - incomplete copy?"
fi

# ---------------------------------------------------------------------------
b "5/5  Dependency state"
# ---------------------------------------------------------------------------
# A missing lock is not an error on a first run of a new Sulu or Sylius
# series - but it must not pass unnoticed, because the next install would
# then resolve something different again.
MISSING=""
for app in sylius sulu; do
  [[ -f "$app-overlay/composer.lock" ]] || MISSING="$MISSING $app"
done

if [[ -n "$MISSING" ]]; then
  y "   no reviewed composer.lock for:$MISSING"
  y "   make deps will resolve dependencies fresh. Once the stack is"
  y "   verified, freeze that state so it is reproducible:"
  y "     make verify && make test-all && make freeze-locks"
else
  g "   reviewed composer.lock present for both applications"
fi

echo ""
g "First install done. Next step:  make docker-start && make deps && make verify"
