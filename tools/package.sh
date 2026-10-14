#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

# The directory name has to be the slug WordPress.org will derive from the Plugin Name
# header, because the header is what the directory uses - not the folder, not the zip.
name="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Plugin Name:[[:space:]]*//p' igniter.php | head -1 | tr -d '\r')"
slug="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-//; s/-$//')"
dir_slug="$(basename "$root")"

if [ "$slug" != "$dir_slug" ]; then
	printf 'slug mismatch: Plugin Name "%s" derives "%s" but the directory is "%s"\n' "$name" "$slug" "$dir_slug" >&2
	exit 1
fi

domain="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Text Domain:[[:space:]]*//p' igniter.php | head -1 | tr -d '\r')"
if [ "$domain" != "$slug" ]; then
	printf 'text domain "%s" does not match slug "%s"\n' "$domain" "$slug" >&2
	exit 1
fi

php tools/build-css.php >/dev/null

dist="$root/dist"
rm -rf "$dist"
mkdir -p "$dist/$slug"

rsync -a --prune-empty-dirs \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude 'README.md' \
	--exclude 'docs' \
	--exclude '.github' \
	--exclude 'dist' \
	--exclude 'tools' \
	--exclude '.wporg' \
	--exclude 'node_modules' \
	--exclude '.DS_Store' \
	--exclude '*.zip' \
	./ "$dist/$slug/"

( cd "$dist" && zip -qr "$slug.zip" "$slug" )

printf 'packaged %s (%s)\n' "$slug" "$(du -h "$dist/$slug.zip" | cut -f1)"
printf 'files: %s\n' "$(find "$dist/$slug" -type f | wc -l | tr -d ' ')"

# The font is the one asset that must survive packaging intact: without it every plate
# falls back to whatever face the browser picks and every measured position is wrong.
for required in igniter.php readme.txt LICENSE assets/fonts/GL-Nummernschild-Mtl.woff assets/fonts/LICENSE-GL-Nummernschild.txt; do
	if [ ! -f "$dist/$slug/$required" ]; then
		printf 'packaged tree is missing %s\n' "$required" >&2
		exit 1
	fi
done

printf 'required files present in the packaged tree\n'
