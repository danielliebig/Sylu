#!/usr/bin/env python3
"""Derive PHP, MySQL and Node.js versions from the upstream CI configuration.

Input:  kickstarter.yaml    two Composer constraints (Sylius, Sulu)
Output: versions.env        resolved tags + derived image versions

The rule is ADR-11: take the highest PHP version that BOTH projects test,
then the highest MySQL version that both test TOGETHER WITH that PHP
version. Documentation is not consulted on purpose -- Sylius states open
minimum versions, Sulu states no database version at all.

Sources read (at the resolved tag, never at a moving branch):
  Sylius  .github/workflows/matrix.json     PHP x MySQL combinations
          package.json                      engines.node (a range only)
  Sulu    .github/workflows/test-application.yaml
                                            PHP x database-name pairs,
                                            Node versions
          tests/docker/docker-compose.<name>.yml
                                            database name -> image tag

Only the standard library is used, so this runs on any machine with
Python 3 and git. Every failure is loud: a source that cannot be read or
parsed aborts the run instead of falling back to a built-in default.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

RAW = "https://raw.githubusercontent.com"

PROJECTS = {
    "sylius": {"repo": "Sylius/Sylius", "tag_prefix": "v"},
    "sulu": {"repo": "sulu/sulu", "tag_prefix": ""},
}

# A parsed matrix with fewer entries than this means the upstream layout
# changed and our parser silently stopped seeing things. Abort instead.
MIN_PAIRS = 2
MIN_NODE_VERSIONS = 1


class Abort(Exception):
    """Something could not be read or parsed -- never continue past this."""


# --------------------------------------------------------------------------
#  Version helpers
# --------------------------------------------------------------------------

def as_tuple(version: str) -> tuple[int, ...]:
    """"8.4" -> (8, 4). Trailing non-numeric parts are dropped."""
    parts = []
    for chunk in str(version).split("."):
        match = re.match(r"^\d+", chunk)
        if not match:
            break
        parts.append(int(match.group()))
    if not parts:
        raise Abort(f"cannot read version number: {version!r}")
    return tuple(parts)


def highest(versions) -> str:
    return max(versions, key=as_tuple)


# --------------------------------------------------------------------------
#  Manifest and tag resolution
# --------------------------------------------------------------------------

def read_manifest(path: Path) -> dict[str, str]:
    """Read the two constraints. The format is ours, so it stays minimal."""
    if not path.exists():
        raise Abort(f"manifest not found: {path}")
    found = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        match = re.match(r'^\s*(sylius|sulu)\s*:\s*"([^"]+)"\s*$', line)
        if match:
            found[match.group(1)] = match.group(2)
    missing = set(PROJECTS) - set(found)
    if missing:
        raise Abort(f"manifest is missing: {', '.join(sorted(missing))}")
    return found


def constraint_bounds(constraint: str) -> tuple[tuple[int, ...], tuple[int, ...] | None]:
    """Return (minimum, exclusive upper bound) for "~X.Y.Z" or "X.Y.Z"."""
    tilde = re.match(r"^~(\d+)\.(\d+)\.(\d+)$", constraint)
    if tilde:
        major, minor, patch = (int(g) for g in tilde.groups())
        return (major, minor, patch), (major, minor + 1, 0)
    exact = re.match(r"^(\d+)\.(\d+)\.(\d+)$", constraint)
    if exact:
        version = tuple(int(g) for g in exact.groups())
        return version, version + (1,)
    raise Abort(
        f"unsupported constraint {constraint!r} -- "
        'use "~X.Y.Z" or an exact "X.Y.Z" (see kickstarter.yaml)'
    )


def resolve_tag(project: str, constraint: str) -> str:
    """Highest published tag matching the constraint, via git ls-remote."""
    repo = PROJECTS[project]["repo"]
    prefix = PROJECTS[project]["tag_prefix"]
    low, high = constraint_bounds(constraint)

    try:
        out = subprocess.run(
            ["git", "ls-remote", "--tags", f"https://github.com/{repo}.git"],
            capture_output=True, text=True, timeout=120, check=True,
        ).stdout
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        raise Abort(f"cannot list tags of {repo}: {error}") from error

    candidates = []
    pattern = re.compile(
        r"refs/tags/" + re.escape(prefix) + r"(\d+\.\d+\.\d+)$"
    )
    for line in out.splitlines():
        match = pattern.search(line)
        if not match:
            continue
        version = as_tuple(match.group(1))
        if low <= version < high:
            candidates.append(match.group(1))

    if not candidates:
        raise Abort(f"no tag of {repo} matches {constraint}")
    return highest(candidates)


# --------------------------------------------------------------------------
#  Fetching
# --------------------------------------------------------------------------

def fetch(project: str, tag: str, path: str) -> str:
    repo = PROJECTS[project]["repo"]
    prefix = PROJECTS[project]["tag_prefix"]
    url = f"{RAW}/{repo}/{prefix}{tag}/{path}"
    try:
        with urllib.request.urlopen(url, timeout=60) as response:
            return response.read().decode("utf-8")
    except (urllib.error.URLError, urllib.error.HTTPError) as error:
        raise Abort(f"cannot read {url}: {error}") from error


# --------------------------------------------------------------------------
#  Sylius: .github/workflows/matrix.json
# --------------------------------------------------------------------------

def sylius_pairs(matrix_json: str) -> set[tuple[str, str]]:
    """All (PHP, MySQL) combinations Sylius tests.

    Two shapes appear in the same file and both count:
      "minimal" lists explicit include entries, one job per entry.
      "full" lists arrays that GitHub expands into a cartesian product.

    Both are needed: PHP 8.5 together with MySQL is only in "minimal",
    while "full" covers MySQL 8.4 with PHP 8.3 and 8.4 only.
    """
    try:
        data = json.loads(matrix_json)
    except json.JSONDecodeError as error:
        raise Abort(f"matrix.json is not valid JSON: {error}") from error

    pairs: set[tuple[str, str]] = set()

    def collect(node) -> None:
        if isinstance(node, dict):
            if "php" in node and "mysql" in node:
                php_values = node["php"]
                mysql_values = node["mysql"]
                php_values = php_values if isinstance(php_values, list) else [php_values]
                mysql_values = mysql_values if isinstance(mysql_values, list) else [mysql_values]
                for php in php_values:
                    for mysql in mysql_values:
                        pairs.add((str(php), str(mysql)))
                return
            for value in node.values():
                collect(value)
        elif isinstance(node, list):
            for item in node:
                collect(item)

    collect(data)
    if len(pairs) < MIN_PAIRS:
        raise Abort(
            f"only {len(pairs)} PHP/MySQL combination(s) found in Sylius' "
            "matrix.json -- the layout probably changed"
        )
    return pairs


def sylius_node_range(package_json: str) -> str:
    """engines.node, e.g. ">=20". A range, not a tested version."""
    try:
        data = json.loads(package_json)
    except json.JSONDecodeError as error:
        raise Abort(f"Sylius package.json is not valid JSON: {error}") from error
    node = data.get("engines", {}).get("node")
    if not node:
        raise Abort("Sylius package.json has no engines.node")
    return str(node)


# --------------------------------------------------------------------------
#  Sulu: .github/workflows/test-application.yaml
# --------------------------------------------------------------------------

def sulu_matrix(workflow_yaml: str) -> tuple[set[tuple[str, str]], set[str]]:
    """(PHP, database-name) pairs and all Node versions Sulu tests.

    The file is GitHub Actions YAML with "include:" lists whose entries
    start at "- php-version:". Rather than parsing YAML in general, the
    block of each entry is read until the next entry or a dedent, which
    is enough for this fixed shape and fails loudly when it stops
    matching.
    """
    lines = workflow_yaml.splitlines()
    pairs: set[tuple[str, str]] = set()

    for index, line in enumerate(lines):
        start = re.match(r"^(\s*)-\s*php-version:\s*'?\"?([\d.]+)'?\"?\s*$", line)
        if not start:
            continue
        indent = len(start.group(1))
        php = start.group(2)

        # Walk the rest of this include entry looking for its database.
        for follow in lines[index + 1:]:
            if not follow.strip():
                continue
            follow_indent = len(follow) - len(follow.lstrip())
            if follow_indent <= indent:
                break  # next entry or end of the list
            database = re.match(r"^\s*database:\s*'?\"?([\w.-]+)'?\"?\s*$", follow)
            if database:
                pairs.add((php, database.group(1)))
                break

    node_versions = set(
        re.findall(r"^\s*-?\s*node-version:\s*'?\"?(\d+)'?\"?\s*$",
                   workflow_yaml, re.MULTILINE)
    )

    if len(pairs) < MIN_PAIRS:
        raise Abort(
            f"only {len(pairs)} PHP/database pair(s) found in Sulu's "
            "test-application.yaml -- the layout probably changed"
        )
    if len(node_versions) < MIN_NODE_VERSIONS:
        raise Abort(
            "no node-version found in Sulu's test-application.yaml -- "
            "the layout probably changed"
        )
    return pairs, node_versions


def sulu_mysql_version(tag: str, database: str) -> str:
    """Map a database name like "mysql-84" to the image tag it runs.

    The name itself is not the version: mysql-80 runs
    mysql/mysql-server:8.0, so the compose file decides.
    """
    compose = fetch("sulu", tag, f"tests/docker/docker-compose.{database}.yml")
    match = re.search(r"^\s*image:\s*\S*mysql\S*:([\w.]+)", compose, re.MULTILINE)
    if not match:
        raise Abort(f"no mysql image found for Sulu's {database}")
    return match.group(1)


# --------------------------------------------------------------------------
#  The rule (ADR-11)
# --------------------------------------------------------------------------

def derive(sylius: set[tuple[str, str]], sulu: set[tuple[str, str]]) -> tuple[str, str]:
    """Highest PHP both test, then highest MySQL both test with that PHP."""
    shared_php = {php for php, _ in sylius} & {php for php, _ in sulu}
    if not shared_php:
        raise Abort("Sylius and Sulu share no PHP version tested with MySQL")
    php = highest(shared_php)

    shared_mysql = ({mysql for p, mysql in sylius if p == php}
                    & {mysql for p, mysql in sulu if p == php})
    if not shared_mysql:
        raise Abort(f"no MySQL version is tested with PHP {php} by both projects")
    return php, highest(shared_mysql)


# --------------------------------------------------------------------------
#  Output
# --------------------------------------------------------------------------

def render(values: dict[str, str], sources: list[str]) -> str:
    head = [
        "# " + "=" * 73,
        "#  GENERATED by docker/scripts/resolve-versions.sh -- do not edit.",
        "#",
        "#  Derived from the two constraints in kickstarter.yaml following",
        "#  ADR-11: the highest PHP both projects test, plus the highest MySQL",
        "#  both test together with that PHP version.",
        "#",
        "#  Sources:",
    ]
    head += [f"#    {line}" for line in sources]
    head += [
        "#",
        "#  Checked in on purpose: make setup must not depend on network",
        "#  access to GitHub, and a change here is reviewable in the diff.",
        "# " + "=" * 73,
        "",
    ]
    body = [f"{key}={value}" for key, value in values.items()]
    return "\n".join(head + body) + "\n"


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    try:
        manifest = read_manifest(root / "kickstarter.yaml")

        tags = {name: resolve_tag(name, constraint)
                for name, constraint in manifest.items()}

        sylius_combinations = sylius_pairs(
            fetch("sylius", tags["sylius"], ".github/workflows/matrix.json")
        )
        node_range = sylius_node_range(
            fetch("sylius", tags["sylius"], "package.json")
        )

        sulu_named, sulu_nodes = sulu_matrix(
            fetch("sulu", tags["sulu"], ".github/workflows/test-application.yaml")
        )
        sulu_combinations = {
            (php, sulu_mysql_version(tags["sulu"], database))
            for php, database in sulu_named
            if database.startswith("mysql")
        }
        if not sulu_combinations:
            raise Abort("Sulu tests no MySQL database at this version")

        php, mysql = derive(sylius_combinations, sulu_combinations)
        node = highest(sulu_nodes)

        # DBAL 4 needs the full x.y.z form in serverVersion, otherwise it
        # silently picks the wrong platform (FIXES.md No. 51). The image tag
        # keeps the form upstream actually tests ("8.4").
        server_version = ".".join(
            str(part) for part in (as_tuple(mysql) + (0, 0))[:3]
        )

        values = {
            "SYLIUS_VERSION": tags["sylius"],
            "SULU_VERSION": tags["sulu"],
            "FRANKENPHP_VERSION": f"1-php{php}",
            "MYSQL_IMAGE": f"mysql:{mysql}",
            "MYSQL_SERVER_VERSION": server_version,
            "NODE_MAJOR": node,
        }
        sources = [
            f"Sylius {tags['sylius']}  .github/workflows/matrix.json, package.json",
            f"Sulu   {tags['sulu']}  .github/workflows/test-application.yaml,"
            " tests/docker/docker-compose.mysql-*.yml",
        ]
    except Abort as error:
        print(f"resolve-versions: {error}", file=sys.stderr)
        return 1

    (root / "versions.env").write_text(render(values, sources), encoding="utf-8")

    print(f"Sylius {manifest['sylius']:>8}  ->  {tags['sylius']}")
    print(f"Sulu   {manifest['sulu']:>8}  ->  {tags['sulu']}")
    print()
    print(f"  PHP      {php:<8} tested with MySQL by both projects")
    print(f"  MySQL    {mysql:<8} highest tested with PHP {php} by both")
    print(f"  Node.js  {node:<8} highest Sulu tests; Sylius requires {node_range}")
    print()
    print("Sylius PHP/MySQL:  "
          + ", ".join(f"{p}+{m}" for p, m in sorted(sylius_combinations, key=lambda x: (as_tuple(x[0]), as_tuple(x[1])))))
    print("Sulu   PHP/MySQL:  "
          + ", ".join(f"{p}+{m}" for p, m in sorted(sulu_combinations, key=lambda x: (as_tuple(x[0]), as_tuple(x[1])))))
    print()
    print("Written: versions.env")
    return 0


if __name__ == "__main__":
    sys.exit(main())
