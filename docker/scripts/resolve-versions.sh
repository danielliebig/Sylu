#!/usr/bin/env bash
#
# Derive PHP, MySQL and Node.js versions from the two constraints in
# kickstarter.yaml and write versions.env. Runs on the host, not in a
# container: it is needed BEFORE the image can be built, because the PHP
# version of that image is one of its results.
#
# Needs git (to list upstream tags) and python3 (to read the upstream CI
# configuration). Both are host requirements of this project anyway.
#
# Usage: make versions
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

for tool in git python3; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "resolve-versions: $tool is required but not installed" >&2
        exit 1
    fi
done

exec python3 "$ROOT/docker/scripts/resolve_versions.py" "$@"
