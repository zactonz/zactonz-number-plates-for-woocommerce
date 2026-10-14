#!/usr/bin/env bash
set -uo pipefail

# Plugin Check, run against the PACKAGED tree rather than the working copy.
#
# Those differ in ways that matter: tools/ and README.md do not ship, and a check of the
# working copy would both miss problems the package has and report problems it does not.
# The slug is passed explicitly, because Plugin Check otherwise infers it from the
# directory, which is not necessarily what the Plugin Name header derives.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

# No default: a WordPress install to check against is specific to whoever is running
# this, and baking one developer's path in both leaks it and silently checks the wrong
# site for everyone else.
wp_path="${WP_PATH:-}"

if [ -z "$wp_path" ] || [ ! -f "$wp_path/wp-cli.phar" ]; then
	echo "Set WP_PATH to a WordPress install that has wp-cli.phar and plugin-check active." >&2
	exit 2
fi

wp_cli="${WP_CLI:-php $wp_path/wp-cli.phar}"

name="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Plugin Name:[[:space:]]*//p' igniter.php | head -1 | tr -d '\r')"
slug="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-//; s/-$//')"

if [ ! -d "dist/$slug" ]; then
	echo "no packaged tree at dist/$slug - run tools/package.sh first" >&2
	exit 1
fi

if ! $wp_cli --path="$wp_path" plugin is-active plugin-check >/dev/null 2>&1; then
	# A missing activation is the failure mode that once reported "0 errors, 0 warnings"
	# when the real output was that 'check' is not a registered subcommand.
	echo "plugin-check is not active in $wp_path; activate it first" >&2
	exit 1
fi

staged="$wp_path/wp-content/plugins/$slug-pcp"
rm -rf "$staged"
cp -R "dist/$slug" "$staged"

echo "== Plugin Check: $slug (packaged tree) =="
out="$(
	$wp_cli --path="$wp_path" plugin check "$slug-pcp" \
		--slug="$slug" \
		--format=csv \
		--include-experimental \
		--include-low-severity-errors \
		--include-low-severity-warnings 2>&1
)"
status=$?

rm -rf "$staged"

printf '%s\n' "$out"

if [ "$status" -ne 0 ]; then
	echo "FAIL  plugin check did not run cleanly" >&2
	exit 1
fi

# Warnings are treated as failures here. A directory reviewer reads them, so they are
# not advisory in practice.
problems="$( printf '%s\n' "$out" | grep -ciE ',(ERROR|WARNING),' )"

if [ "$problems" -gt 0 ]; then
	printf 'FAIL  %s error/warning line(s)\n' "$problems" >&2
	exit 1
fi

echo "  no errors or warnings"
