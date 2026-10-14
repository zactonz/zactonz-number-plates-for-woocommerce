#!/usr/bin/env bash
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
status=0

echo "== php -l =="
while IFS= read -r f; do
	if ! out=$(php -l "$f" 2>&1); then
		echo "  FAIL  $out"
		status=1
	fi
done < <(find . -name '*.php' -not -path './dist/*' -not -path './.git/*')
[ "$status" -eq 0 ] && echo "  all PHP files parse"

echo
echo "== PHP 7.4 compatibility =="
# The floor both WordPress 7.1 and WooCommerce 11.2 declare, so the header's claim of
# 7.4 is load-bearing rather than decorative.
if command -v phpcs >/dev/null 2>&1; then
	if phpcs -q --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- \
		--extensions=php --report=summary \
		--ignore='*/dist/*' . ; then
		echo "  no syntax or function use that PHP 7.4 would reject"
	else
		echo "  FAIL  code requires a PHP newer than the 7.4 the header claims"
		status=1
	fi
else
	echo "  SKIP  phpcs not installed; PHPCompatibilityWP not run"
fi

echo
echo "== css build is current =="
# --check compares and writes nothing: a gate must not modify the tree it inspects.
if php tools/build-css.php --check >/dev/null; then
	echo "  minified CSS is up to date"
else
	echo "  FAIL  minified CSS is stale; run php tools/build-css.php"
	status=1
fi

echo
echo "== font metrics match the font =="
# The renderer positions every glyph from this table. If it drifts from the file, plates
# are wrong at full size while still looking plausible in a preview.
for metrics in data/*-font-metrics.php; do
	[ -e "$metrics" ] || continue
	face=$(sed -n 's~^ \* Measured from \(assets/fonts/[^ ]*\) .*~\1~p' "$metrics" | head -1)
	if [ -z "$face" ] || [ ! -f "$face" ]; then
		echo "  FAIL  $metrics does not name a shipped font file"
		status=1
		continue
	fi
	if php tools/compare-metrics.php "$metrics" "$face"; then
		echo "  $(basename "$metrics") matches $(basename "$face")"
	else
		echo "  FAIL  $(basename "$metrics") disagrees with $(basename "$face")"
		status=1
	fi

	# The raster thumbnail is drawn from a bare SFNT of the same face. If that one
	# drifts from the WOFF the browser uses, a shopper's preview and the picture in
	# their email stop being the same plate.
	for sfnt in "${face%.woff}.otf" "${face%.woff}.ttf"; do
		[ -f "$sfnt" ] || continue
		if php tools/compare-metrics.php "$metrics" "$sfnt"; then
			echo "  $(basename "$metrics") matches $(basename "$sfnt")"
		else
			echo "  FAIL  $(basename "$metrics") disagrees with $(basename "$sfnt")"
			status=1
		fi
	done
done

echo
echo "== glyph outlines match the font =="
# The production download is drawn from these paths rather than from text, so a drift
# here is a plate manufactured in the wrong shapes with nothing on screen to show it.
for outlines in data/*-font-outlines.php; do
	[ -e "$outlines" ] || continue
	face=$(sed -n 's~^ \* Glyph outlines for \([^,]*\),.*~\1~p' "$outlines" | head -1)
	face="assets/fonts/$face"
	if [ ! -f "$face" ]; then
		echo "  FAIL  $outlines does not name a shipped font file"
		status=1
		continue
	fi
	if php tools/compare-outlines.php "$outlines" "$face"; then
		echo "  $(basename "$outlines") matches $(basename "$face")"
	else
		echo "  FAIL  $(basename "$outlines") disagrees with $(basename "$face")"
		status=1
	fi
done

echo
echo "== plate dimensions =="
# Measured against the figures Anlage 4 states, not against the profile the renderer
# reads, so a wrong number in the data cannot agree with itself and pass.
if php tools/measure-plates.php; then
	:
else
	echo "  FAIL  rendered plates disagree with Anlage 4 or run off the plate"
	status=1
fi

echo
echo "== house rules =="
if ! php tools/lint.php; then
	status=1
fi

exit "$status"
