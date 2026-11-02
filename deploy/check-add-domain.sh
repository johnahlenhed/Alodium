#!/bin/bash
# Checks that deploy/add-domain.sh writes and runs exactly what README.md sections
# 3 to 8 say to do by hand: the domain values for Laravel's .env (section 3), the
# astro/.env.production block and the npm install command (4), the plain-HTTP
# Nginx config (5), the MAXMIND_LICENSE_KEY line and the GeoIP download commands
# (6), the Supervisor worker config (7) and the cron line (8). If one of them
# changes, change the other too. (robots.txt is not written by hand any more:
# astro/src/pages/robots.txt.ts builds it, and astro/scripts/check-build.mjs
# checks it.)
#
#   deploy/check-add-domain.sh [README.md] [add-domain.sh]
#
# Exit 0 when all of them match; 1 with a diff for each one that doesn't, or if a
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
# Section 3: the block after "Without the script, set them here:", without its comments.
readme_laravel_env() { awk '/^### 3\. / { s = 1 } /^### 4\. / { exit } s && /Without the script, set them here:$/ { f = 1; next } f && /^```ini$/ { g = 1; next } g && /^```$/ { exit } g && !/^#/' "$README"; }
readme_astro_install() { awk '/^### 4\. / { s = 1 } /^### 5\. / { exit } s && /^```bash$/ { g = 1; next } g && /^```$/ { exit } g && /^npm install/' "$README"; }
readme_supervisor() { awk '/^### 7\. / { s = 1 } /^### 8\. / { exit } s && /^`\/etc\/supervisor\/conf\.d\/alodium-worker\.conf`:$/ { f = 1; next } f && /^```ini$/ { g = 1; next } g && /^```$/ { exit } g' "$README"; }
readme_cron() { awk '/^### 8\. / { s = 1 } /^### 9\. / { exit } s && /^\* \* \* \* \* /' "$README"; }
readme_env() { awk '/^### 4\. / { s = 1 } /^### 5\. / { exit } s && /^```ini$/ { g = 1; next } g && /^```$/ { exit } g' "$README"; }
readme_nginx() { awk '/^### 5\. / { s = 1 } /^### 6\. / { exit } s && /^`\/etc\/nginx\/sites-available\/alodium`:$/ { f = 1; next } f && /^```nginx$/ { g = 1; next } g && /^```$/ { exit } g' "$README"; }
# Section 6's blocks are indented (they are in a numbered list).
readme_geoip_env() { awk '/^### 6\. / { s = 1 } /^### 7\. / { exit } s && /^ *```ini$/ { g = 1; next } g && /^ *```$/ { exit } g { sub(/^ +/, ""); print }' "$README"; }
readme_geoip_commands() { awk '/^### 6\. / { s = 1 } /^### 7\. / { exit } s && /^ *sudo -u www-data / { sub(/^ +/, ""); sub(/ +#.*$/, ""); print }' "$README"; }

set -- # the sourced script would otherwise see this script's arguments
# shellcheck source=SCRIPTDIR/add-domain.sh
source "$SCRIPT"
for fn in laravel_env_lines env_content astro_install_command nginx_content maxmind_env_line geoip_commands supervisor_content cron_line; do
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

compare "Laravel .env domain values (section 3)" "$(readme_laravel_env)" "$(laravel_env_lines "$ADMIN" "$SITE")"
compare "astro/.env.production (section 4)" "$(readme_env)" "$(env_content "$ADMIN" "$SITE")"
compare "Astro npm install (section 4)" "$(readme_astro_install)" "$(astro_install_command)"
compare "Nginx config, plain HTTP (section 5)" "$(readme_nginx)" "$(nginx_content "$ADMIN" "$SITE")"
# The README's placeholder key on both sides.
compare "MAXMIND_LICENSE_KEY line (section 6)" "$(readme_geoip_env)" "$(maxmind_env_line your-key)"
compare "GeoIP download commands (section 6)" "$(readme_geoip_commands)" "$(geoip_commands)"
compare "Supervisor worker config (section 7)" "$(readme_supervisor)" "$(supervisor_content)"
compare "cron line (section 8)" "$(readme_cron)" "$(cron_line)"

exit $failed
