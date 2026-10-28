#!/bin/bash
# Checks that deploy/add-domain.sh writes exactly what README.md sections 4 and 5
# say to write by hand: the astro/.env.production block and the plain-HTTP Nginx
# config. If one of them changes, change the other too. (robots.txt is not
# written by hand any more: astro/src/pages/robots.txt.ts builds it, and
# astro/scripts/check-build.mjs checks it.)
#
#   deploy/check-add-domain.sh [README.md] [add-domain.sh]
#
# Exit 0 when both match; 1 with a diff for each one that doesn't, or if a
# block can't be found in the README. Runs with `php artisan test`
# (tests/Unit/AddDomainScriptTest.php). Needs no root and changes nothing: it
# sources add-domain.sh, which then only defines the functions that generate
# the files.

set -euo pipefail

dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
README=${1:-$dir/../README.md}
SCRIPT=${2:-$dir/add-domain.sh}
[[ -f $README ]] || { echo "check-add-domain: $README not found" >&2; exit 1; }
[[ -f $SCRIPT ]] || { echo "check-add-domain: $SCRIPT not found" >&2; exit 1; }

# The same example domains on both sides. Real names, not the README's
# placeholders, so a placeholder the script forgot to replace shows up as a difference.
ADMIN=admin.client.example
SITE=client.example

# The README's blocks, found by the text around them.
readme_env() { awk '/^### 4\. / { s = 1 } /^### 5\. / { exit } s && /^```ini$/ { g = 1; next } g && /^```$/ { exit } g' "$README"; }
readme_nginx() { awk '/^### 5\. / { s = 1 } /^### 6\. / { exit } s && /^`\/etc\/nginx\/sites-available\/alodium`:$/ { f = 1; next } f && /^```nginx$/ { g = 1; next } g && /^```$/ { exit } g' "$README"; }

set -- # the sourced script would otherwise see this script's arguments
# shellcheck source=SCRIPTDIR/add-domain.sh
source "$SCRIPT"
for fn in env_content nginx_content; do
    declare -F "$fn" > /dev/null || { echo "check-add-domain: $SCRIPT no longer defines $fn()" >&2; exit 1; }
done

failed=0
# $1 what, $2 the README's text (with placeholders), $3 what the script generates.
compare() {
    local readme
    if [[ -z $2 ]]; then
        echo "FAIL  $1: not found in $README (did the section's heading or code block change?)"
        failed=1
        return
    fi
    readme=${2//ADMIN_DOMAIN/$ADMIN}
    readme=${readme//SITE_DOMAIN/$SITE}
    if [[ $readme == "$3" ]]; then
        echo "ok    $1"
    else
        echo "FAIL  $1 differs (- README.md, + add-domain.sh):"
        diff -u --label README.md --label add-domain.sh <(printf '%s\n' "$readme") <(printf '%s\n' "$3") || true
        failed=1
    fi
}

compare "astro/.env.production (section 4)" "$(readme_env)" "$(env_content "$ADMIN" "$SITE")"
compare "Nginx config, plain HTTP (section 5)" "$(readme_nginx)" "$(nginx_content "$ADMIN" "$SITE")"

exit $failed
