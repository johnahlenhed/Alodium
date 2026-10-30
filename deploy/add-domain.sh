#!/bin/bash
# README.md "Deployment", sections 4 (Astro public site), 5 (DNS, Nginx and
# HTTPS), 6 (GeoIP database), 7 (queue worker) and 8 (scheduler), and the
# domain values of section 3, as a script: astro/.env.production and the Astro
# dependencies, APP_URL, PUBLIC_SITE_URL and MAIL_FROM_ADDRESS in Laravel's
# .env, the plain-HTTP Nginx config, the Supervisor worker, the cron entry, the
# Let's Encrypt certificate, and the client's own MaxMind licence key with the
# GeoIP download. (robots.txt needs nothing: the Astro build writes it, with
# the Sitemap line from SITE_URL.)
#
# Only for a NEW Alodium deployment, on a fresh server for a new client, after
# README sections 1-3 (packages, swap, the app cloned to /var/www/alodium, its
# .env). It is NOT for the site already running in this repository's
# production: do not run it there. On a configured server it refuses to replace
# astro/.env.production, the Nginx config, its sites-enabled link, the
# Supervisor config or a different schedule:run cron line unless --force is
# given, and it keeps APP_URL, PUBLIC_SITE_URL and MAIL_FROM_ADDRESS values of
# their own (only empty ones and .env.example's placeholders are filled in).
# Running it again changes nothing that is already right.
#
# Usage, as root:
#   add-domain.sh setup   --admin-domain admin.client.example --site-domain client.example
#   add-domain.sh certbot --admin-domain admin.client.example --site-domain client.example --email you@client.example
#   add-domain.sh geoip   --maxmind-key -
#   add-domain.sh all     (the options of certbot, and --maxmind-key)
#
#   setup    Sections 4, 5 up to HTTPS, 7 and 8, and section 3's domain values:
#            writes astro/.env.production, creates astro-public/, writes the
#            Nginx config, links it, removes the default site, runs nginx -t and
#            reloads only if the test passes; sets APP_URL, PUBLIC_SITE_URL and
#            MAIL_FROM_ADDRESS in .env; writes the Supervisor worker config,
#            creates its log and runs supervisorctl reread and update; adds the
#            scheduler line to www-data's crontab (once); runs npm install in
#            astro/. Ends with php artisan config:cache if the config is cached.
#            Needs no DNS.
#   certbot  Section 5 "HTTPS": checks that SITE, www.SITE and ADMIN resolve to
#            this server, then runs Certbot. Run it (again) once DNS has propagated.
#   geoip    Section 6: writes this client's MaxMind licence key into .env as
#            MAXMIND_LICENSE_KEY, refreshes the config cache and downloads the
#            GeoIP database as www-data. Needs no domains or DNS. Without a key
#            it explains where to get one and changes nothing.
#   all      setup, then geoip, then certbot.
#
# Options:
#   --email ADDRESS    for Let's Encrypt (expiry notices); needed by certbot and
#                      all. Running Certbot accepts the Let's Encrypt terms.
#   --force            replace an existing astro/.env.production, Nginx config,
#                      sites-enabled link, Supervisor config, a different
#                      schedule:run line in www-data's crontab, and APP_URL,
#                      PUBLIC_SITE_URL or MAIL_FROM_ADDRESS values of their own;
#                      old files are kept as *.bak-TIMESTAMP
#   --dry-run          print what would be written and run, and change nothing
#                      (the DNS lookups are still made: they only read)
#   --skip-dns-check   run Certbot without checking DNS first
#   --server-ip IP     this server's public IP, if it isn't on a network interface
#                      (behind NAT); may be given more than once
#   --maxmind-key KEY  the client's own MaxMind licence key (each deployment uses
#                      the client's own free account, never a shared key). Give
#                      "-" to type or paste it instead (not shown, and not kept
#                      in the shell history). --force replaces a different key.
#
# Exit codes: 0 done, 1 error or refused, 2 a step can't be finished yet: DNS
# isn't ready (certbot) or no MaxMind key was given (geoip). Everything else is
# done and kept; run that step again later.
#
# ALODIUM_APP_DIR, ALODIUM_NGINX_DIR and ALODIUM_SUPERVISOR_DIR replace
# /var/www/alodium, /etc/nginx and /etc/supervisor/conf.d, and ALODIUM_WEB_USER
# replaces www-data (whose crontab and log file these are), so the script can be
# tested against a scratch directory (tests/Unit/AddDomainSetupTest.php). The
# files it writes keep the README's paths and user whatever these are.

set -euo pipefail

APP_DIR=${ALODIUM_APP_DIR:-/var/www/alodium}
NGINX_DIR=${ALODIUM_NGINX_DIR:-/etc/nginx}
SUPERVISOR_DIR=${ALODIUM_SUPERVISOR_DIR:-/etc/supervisor/conf.d}
WEB_USER=${ALODIUM_WEB_USER:-www-data}
ENV_FILE="$APP_DIR/astro/.env.production"
LARAVEL_ENV="$APP_DIR/.env"
SITE_CONF="$NGINX_DIR/sites-available/alodium"
SITE_LINK="$NGINX_DIR/sites-enabled/alodium"
DEFAULT_LINK="$NGINX_DIR/sites-enabled/default"
WORKER_CONF="$SUPERVISOR_DIR/alodium-worker.conf"
WORKER_LOG="$APP_DIR/storage/logs/worker.log"
STAMP=$(date +%Y%m%d-%H%M%S)

usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; }
die() { echo "add-domain: $*" >&2; exit 1; }

# Prints a command exactly as it would be run (shell-quoted), then runs it.
run() {
    if $DRY_RUN; then printf '  would run:'; else printf '+'; fi
    printf ' %q' "$@"
    echo
    $DRY_RUN || "$@"
}

# ---------------------------------------------------------------- generated files

# What this script writes, as functions of the two domains (no trailing newline).
# deploy/check-add-domain.sh sources this file and compares them with README.md
# sections 4 and 5, so keep them exactly as the README shows them.

# README section 4: astro/.env.production. $1 admin domain, $2 site domain.
env_content() {
    printf 'API_URL=https://%s/api/public\nSITE_URL=https://%s\nTRACKER_URL=https://%s/tracker.js' "$1" "$2" "$1"
}

# README section 5, the plain-HTTP version (Certbot adds HTTPS to it). $1 admin
# domain, $2 site domain. Quoted heredoc: $uri and friends stay as they are; only
# the two names are replaced.
nginx_content() {
    local template
    read -r -d '' template <<'NGINX' || true
# Laravel: admin panel, public content API, tracker
server {
    listen 80;
    server_name ADMIN_DOMAIN;
    root /var/www/alodium/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    # Nginx's default is 1M. Media uploads (10 MB) and WordPress XML imports
    # (20 MB) need more. Keep this at least as high as PHP's post_max_size.
    client_max_body_size 30M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    # Only Laravel's front controller is executed as PHP. Any other .php file
    # (for example one that ended up in public/storage) gets a 404 instead.
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

# Astro: static public site
server {
    listen 80;
    server_name SITE_DOMAIN www.SITE_DOMAIN;
    root /var/www/alodium/astro-public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.html;

    location / {
        try_files $uri $uri/ $uri.html =404;
    }

    # Serve Astro's 404 page with a real 404 status. "try_files ... /404.html"
    # would return it as 200, so missing URLs look like real pages to search engines.
    error_page 404 /404.html;
}
NGINX
    template=${template//ADMIN_DOMAIN/"$1"}
    printf '%s' "${template//SITE_DOMAIN/"$2"}"
}

# README section 3: the values in Laravel's .env that follow from the domains.
# $1 admin domain, $2 site domain. Comments in the README's block are ignored.
laravel_env_lines() {
    printf 'APP_URL=https://%s\nPUBLIC_SITE_URL=https://%s\nMAIL_FROM_ADDRESS=hello@%s' "$1" "$2" "$2"
}

# README section 4: installs the Astro site's dependencies, run in astro/.
astro_install_command() { printf '%s' 'npm install --legacy-peer-deps'; }

# README section 6: the .env line for this client's MaxMind key ($1), and the
# download commands, run from the app directory (after "cd /var/www/alodium").
maxmind_env_line() { printf 'MAXMIND_LICENSE_KEY=%s' "$1"; }
geoip_commands() { printf '%s\n' 'sudo -u www-data mkdir -p storage/app/geoip' 'sudo -u www-data php artisan location:update'; }

# README section 7: the queue worker's Supervisor program.
supervisor_content() {
    local conf
    read -r -d '' conf <<'CONF' || true
[program:alodium-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/alodium/artisan queue:work --sleep=3 --tries=3 --timeout=660
user=www-data
numprocs=1
autostart=true
autorestart=true
; Longer than --timeout, so a running Astro build finishes when the worker restarts
stopwaitsecs=670
redirect_stderr=true
stdout_logfile=/var/www/alodium/storage/logs/worker.log
CONF
    printf '%s' "$conf"
}

# README section 8: the scheduler's line in www-data's crontab.
cron_line() { printf '%s' '* * * * * cd /var/www/alodium && php artisan schedule:run >> /dev/null 2>&1'; }

# Sourced (by the check): stop here, with only the functions above defined.
[[ ${BASH_SOURCE[0]} == "$0" ]] || return 0

# ---------------------------------------------------------------- arguments

COMMAND=
ADMIN=
SITE=
EMAIL=
SERVER_IPS=()
FORCE=false
DRY_RUN=false
SKIP_DNS=false
MAXMIND_KEY=
GEOIP_DEFERRED=false
# Set when this run changed Laravel's .env, so a cached config is refreshed (once).
CONFIG_STALE=false

case ${1:-} in
    setup | certbot | geoip | all) COMMAND=$1; shift ;;
    -h | --help) usage; exit 0 ;;
    '') usage >&2; exit 1 ;;
    *) die "unknown command \"$1\": use setup, certbot, geoip or all (see --help)" ;;
esac
while [[ $# -gt 0 ]]; do
    case $1 in
        --admin-domain | --site-domain | --email | --server-ip | --maxmind-key)
            [[ $# -ge 2 ]] || die "$1 needs a value"
            case $1 in
                --admin-domain) ADMIN=${2,,} ;;
                --site-domain) SITE=${2,,} ;;
                --email) EMAIL=$2 ;;
                --server-ip) SERVER_IPS+=("$2") ;;
                --maxmind-key) MAXMIND_KEY=$2 ;;
            esac
            shift 2
            ;;
        --force) FORCE=true; shift ;;
        --dry-run) DRY_RUN=true; shift ;;
        --skip-dns-check) SKIP_DNS=true; shift ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown option \"$1\" (see --help)" ;;
    esac
done

[[ $EUID -eq 0 ]] || die "run this as root (sudo $0 ...): it writes to $NGINX_DIR and reloads Nginx."

# Lowercase letters, digits and hyphens per label, at least two labels, a
# letter-only or punycode (xn--) top-level domain. No scheme, port or path.
is_domain() {
    local re='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,63}|xn--[a-z0-9-]{1,59})$'
    [[ ${#1} -le 253 && $1 =~ $re ]]
}
check_domain() {
    local option=$1 value=$2 example=$3
    [[ -n $value ]] || die "$option is missing (for example $option $example)"
    is_domain "$value" || die "$option \"$value\" doesn't look like a domain name. Give only the name, like $example: no https://, port, path or trailing dot."
}
# The geoip step alone needs no domains.
if [[ $COMMAND != geoip ]]; then
    check_domain --admin-domain "$ADMIN" admin.client.example
    check_domain --site-domain "$SITE" client.example
    # www.SITE is added for Certbot and Nginx, so "www.client.example" would become www.www.client.example.
    [[ $SITE != www.* ]] || die "give --site-domain without www. (${SITE#www.}): www.${SITE#www.} is added automatically."
    [[ $ADMIN != "$SITE" && $ADMIN != "www.$SITE" ]] || die "--admin-domain must be a different name from the site (for example admin.$SITE)."
fi
if [[ $COMMAND == certbot || $COMMAND == all ]]; then
    [[ -n $EMAIL ]] || die "--email is missing: Let's Encrypt needs an address for expiry notices."
    [[ $EMAIL =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "--email \"$EMAIL\" doesn't look like an email address."
fi
for ip in ${SERVER_IPS[@]+"${SERVER_IPS[@]}"}; do
    [[ $ip =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ || $ip =~ ^[0-9a-fA-F:]+:[0-9a-fA-F:]*$ ]] || die "--server-ip \"$ip\" is not an IP address."
done
# "-": read the key from standard input, so it isn't in the shell history or the
# process list. Typed at a terminal, it isn't shown.
if [[ $MAXMIND_KEY == - ]]; then
    if [[ -t 0 ]]; then
        printf 'MaxMind licence key for this client (not shown): ' >&2
        IFS= read -rs MAXMIND_KEY || true
        echo >&2
    else
        IFS= read -r MAXMIND_KEY || true
    fi
    MAXMIND_KEY=${MAXMIND_KEY//[[:space:]]/}
fi
# Letters, digits and underscores (MaxMind's format). Anything else could break
# the .env line ("$" is expanded there, quotes and spaces end the value).
if [[ -n $MAXMIND_KEY && ! $MAXMIND_KEY =~ ^[A-Za-z0-9_]{6,128}$ ]]; then
    die "--maxmind-key doesn't look like a MaxMind licence key (letters, digits and _ only). Copy it again from the MaxMind account."
fi

ENV_CONTENT=$(env_content "$ADMIN" "$SITE")
NGINX_CONTENT=$(nginx_content "$ADMIN" "$SITE")
WORKER_CONTENT=$(supervisor_content)
CRON_LINE=$(cron_line)

# ---------------------------------------------------------------- setup

# new, same or differs. $(cat) drops trailing newlines, as $content has none.
file_state() {
    if [[ ! -e $1 && ! -L $1 ]]; then echo new
    elif [[ -f $1 && "$(cat -- "$1")" == "$2" ]]; then echo same
    else echo differs
    fi
}

# Writes in place (">"), so an existing file keeps its owner and mode.
write_file() {
    local path=$1 content=$2 state=$3
    if [[ $state == same ]]; then
        echo "Unchanged: $path"
        return
    fi
    if $DRY_RUN; then
        echo "  would write $path:"
        echo "----- $path -----"
        printf '%s\n' "$content"
        echo "----- end of $path -----"
        return
    fi
    if [[ $state == differs ]]; then
        cp -p -- "$path" "$path.bak-$STAMP"
        BACKUPS[$path]="$path.bak-$STAMP"
        echo "Kept the old $path as $path.bak-$STAMP"
    fi
    printf '%s\n' "$content" > "$path"
    echo "Wrote $path"
}

declare -A BACKUPS=()
LINK_STATE=
OLD_LINK_TARGET=
DEFAULT_MOVED=
DEFAULT_TARGET=

# Puts the Nginx files back as they were before this run (nginx -t failed).
rollback_nginx() {
    if [[ -n ${BACKUPS[$SITE_CONF]:-} ]]; then cp -p -- "${BACKUPS[$SITE_CONF]}" "$SITE_CONF"
    elif [[ $SITE_STATE == new ]]; then rm -f -- "$SITE_CONF"
    fi
    case $LINK_STATE in
        new) rm -f -- "$SITE_LINK" ;;
        differs) rm -f -- "$SITE_LINK"; ln -s -- "$OLD_LINK_TARGET" "$SITE_LINK" ;;
        moved) rm -f -- "$SITE_LINK"; mv -- "$NGINX_DIR/sites-available/alodium-enabled.bak-$STAMP" "$SITE_LINK" ;;
    esac
    if [[ -n $DEFAULT_TARGET ]]; then ln -s -- "$DEFAULT_TARGET" "$DEFAULT_LINK"
    elif [[ -n $DEFAULT_MOVED ]]; then mv -- "$DEFAULT_MOVED" "$DEFAULT_LINK"
    fi
    echo "Put the previous Nginx configuration back. Nginx was not reloaded." >&2
}

# ---------------------------------------------------------------- Laravel .env, worker, cron, npm

# The value of $2 in the env file $1: its first line, without surrounding quotes. Empty if none.
env_value() {
    [[ -f $1 ]] || return 0
    awk -v key="$2" 'index($0, key "=") == 1 { v = substr($0, length(key) + 2); gsub(/^["\047]|["\047]$/, "", v); print v; exit }' "$1"
}

# Section 3's domain values: which to write (empty, or still .env.example's
# placeholder, such as APP_URL=http://localhost:8000 or MAIL_FROM_ADDRESS=hello@alodium.dev),
# and which are kept because they are values of their own (--force replaces them).
declare -A LARAVEL_SET=()
LARAVEL_NAMES=()
LARAVEL_NOTES=()
LARAVEL_REPLACES_OWN=false
plan_laravel_env() {
    local line name want have placeholder
    while IFS= read -r line; do
        [[ -n $line ]] || continue
        name=${line%%=*}
        want=${line#*=}
        have=$(env_value "$LARAVEL_ENV" "$name")
        placeholder=$(env_value "$APP_DIR/.env.example" "$name")
        if [[ $have == "$want" ]]; then
            LARAVEL_NOTES+=("Unchanged: $name in $LARAVEL_ENV")
            continue
        fi
        if [[ -n $have && $have != "$placeholder" ]]; then
            if ! $FORCE; then
                LARAVEL_NOTES+=("Kept $name=$have in $LARAVEL_ENV: a value of its own. These domains would give $name=$want; run again with --force to replace it.")
                continue
            fi
            LARAVEL_REPLACES_OWN=true
        fi
        LARAVEL_SET[$name]=$want
        LARAVEL_NAMES+=("$name")
    done < <(laravel_env_lines "$ADMIN" "$SITE"; echo)
}

write_laravel_env() {
    local note name lines=() content
    for note in ${LARAVEL_NOTES[@]+"${LARAVEL_NOTES[@]}"}; do echo "$note"; done
    [[ ${#LARAVEL_NAMES[@]} -gt 0 ]] || return 0
    CONFIG_STALE=true
    if $DRY_RUN; then
        for name in "${LARAVEL_NAMES[@]}"; do echo "  would set $name=${LARAVEL_SET[$name]} in $LARAVEL_ENV"; done
        return 0
    fi
    if $LARAVEL_REPLACES_OWN; then
        cp -p -- "$LARAVEL_ENV" "$LARAVEL_ENV.bak-$STAMP"
        echo "Kept the old $LARAVEL_ENV as $LARAVEL_ENV.bak-$STAMP. It holds the app's secrets: delete it once the new values work."
    fi
    for name in "${LARAVEL_NAMES[@]}"; do lines+=("$name=${LARAVEL_SET[$name]}"); done
    # One pass: each name's first line is replaced, or the line is added at the end.
    # In place (">"), so .env keeps its owner and mode.
    content=$(SET_LINES="$(printf '%s\n' "${lines[@]}")" awk '
        BEGIN { n = split(ENVIRON["SET_LINES"], l, "\n"); for (i = 1; i <= n; i++) if (l[i] != "") { k = substr(l[i], 1, index(l[i], "=") - 1); want[k] = l[i]; order[++m] = k } }
        { for (k in want) if (!(k in done) && index($0, k "=") == 1) { print want[k]; done[k] = 1; next } print }
        END { for (i = 1; i <= m; i++) if (!(order[i] in done)) print want[order[i]] }' "$LARAVEL_ENV")
    printf '%s\n' "$content" > "$LARAVEL_ENV"
    for name in "${LARAVEL_NAMES[@]}"; do echo "Set $name=${LARAVEL_SET[$name]} in $LARAVEL_ENV"; done
}

# Section 7: the Supervisor program, its log file, and Supervisor told about it.
# reread and update are safe to repeat: they change nothing when nothing changed.
write_worker() {
    write_file "$WORKER_CONF" "$WORKER_CONTENT" "$WORKER_STATE"
    # Supervisor won't start the program if it can't open its log.
    [[ -e $WORKER_LOG ]] || run touch "$WORKER_LOG"
    run chown "$WEB_USER:$WEB_USER" "$WORKER_LOG"
    run supervisorctl reread
    run supervisorctl update
}

# Section 8: www-data's crontab now (empty if it has none), and what to do with it:
# same (the line is there, once), new (no schedule:run line), or differs (a
# schedule:run line of another form, or more than one).
CRON_NOW=
CRON_STATE=
plan_cron() {
    CRON_NOW=$(crontab -u "$WEB_USER" -l 2> /dev/null || true)
    local count
    count=$(grep -c 'artisan schedule:run' <<< "$CRON_NOW" || true)
    if [[ $count -eq 1 ]] && grep -qxF -- "$CRON_LINE" <<< "$CRON_NOW"; then CRON_STATE=same
    elif [[ $count -eq 0 ]]; then CRON_STATE=new
    else CRON_STATE=differs
    fi
}

write_cron() {
    if [[ $CRON_STATE == same ]]; then
        echo "Unchanged: the schedule:run line in $WEB_USER's crontab"
    else
        # Every other line stays. With --force, schedule:run lines of another form go.
        local kept
        kept=$(grep -v 'artisan schedule:run' <<< "$CRON_NOW" || true)
        if [[ $CRON_STATE == differs ]]; then echo "Replacing in $WEB_USER's crontab: $(grep 'artisan schedule:run' <<< "$CRON_NOW" | paste -sd '|')"; fi
        if $DRY_RUN; then
            echo "  would add to $WEB_USER's crontab: $CRON_LINE"
        else
            { [[ -z $kept ]] || printf '%s\n' "$kept"; printf '%s\n' "$CRON_LINE"; } | crontab -u "$WEB_USER" -
            echo "Added to $WEB_USER's crontab: $CRON_LINE"
        fi
    fi
    # README section 12: in root's crontab as well, every scheduled job would run twice.
    if [[ $WEB_USER != root ]]; then
        local root_cron
        root_cron=$(crontab -l 2> /dev/null || true)
        if grep -q 'artisan schedule:run' <<< "$root_cron"; then
            echo "Warning: root's crontab runs schedule:run too, so scheduled jobs (publishing, backups) run twice. Remove it there: crontab -e" >&2
        fi
    fi
}

# Section 4: the Astro dependencies. As root, npm leaves root-owned files the
# rebuild (as www-data) can't replace, so astro/ gets its owner back afterwards.
# Only astro/: the admin panel's assets are built elsewhere (README section 3).
install_astro() {
    local words
    read -ra words <<< "$(astro_install_command)"
    run_in "$APP_DIR/astro" "${words[@]}" || die "\"$(astro_install_command)\" in $APP_DIR/astro failed (output above). Fix it and run setup again."
    run chown -R --reference="$APP_DIR/astro" "$APP_DIR/astro"
}

# Once per run: a cached config (php artisan config:cache) doesn't see .env changes.
refresh_config_cache() {
    $CONFIG_STALE || return 0
    CONFIG_STALE=false
    [[ -f $APP_DIR/bootstrap/cache/config.php ]] || return 0
    run_in "$APP_DIR" php artisan config:cache
}

setup_step() {
    echo "== Setup: README sections 3 (domain values), 4, 5 (Nginx), 7 and 8 for $ADMIN and $SITE"
    [[ -f $APP_DIR/astro/package.json ]] || die "$APP_DIR/astro/package.json not found. Clone the app to $APP_DIR first (README section 3)."
    [[ -f $LARAVEL_ENV ]] || die "$LARAVEL_ENV not found. Create it first (README section 3: cp .env.example .env)."
    [[ -d $APP_DIR/storage/logs ]] || die "$APP_DIR/storage/logs not found. Is $APP_DIR a complete clone (README section 3)?"
    [[ -d $NGINX_DIR/sites-available && -d $NGINX_DIR/sites-enabled ]] || die "$NGINX_DIR/sites-available or sites-enabled not found. Install Nginx first (README section 1)."
    [[ -d $SUPERVISOR_DIR ]] || die "$SUPERVISOR_DIR not found. Install Supervisor first (README section 1)."
    local tool
    for tool in nginx npm supervisorctl crontab; do
        command -v "$tool" > /dev/null || die "$tool is not installed (README section 1)."
    done
    id "$WEB_USER" > /dev/null 2>&1 || die "user $WEB_USER not found. Installing PHP-FPM and Nginx creates it (README section 1)."

    # Check everything before changing anything, so a refusal leaves the server as it was.
    local env_state refused=()
    env_state=$(file_state "$ENV_FILE" "$ENV_CONTENT")
    SITE_STATE=$(file_state "$SITE_CONF" "$NGINX_CONTENT")
    WORKER_STATE=$(file_state "$WORKER_CONF" "$WORKER_CONTENT")
    if [[ ! -e $SITE_LINK && ! -L $SITE_LINK ]]; then LINK_STATE=new
    elif [[ -L $SITE_LINK && "$(readlink -f -- "$SITE_LINK")" == "$(readlink -f -- "$SITE_CONF")" ]]; then LINK_STATE=same
    else LINK_STATE=differs
    fi
    plan_laravel_env
    plan_cron

    if ! $FORCE; then
        local path
        for path in "$ENV_FILE" "$SITE_CONF" "$WORKER_CONF"; do
            local state=$env_state content=$ENV_CONTENT
            [[ $path == "$SITE_CONF" ]] && state=$SITE_STATE content=$NGINX_CONTENT
            [[ $path == "$WORKER_CONF" ]] && state=$WORKER_STATE content=$WORKER_CONTENT
            if [[ $state == differs ]]; then
                refused+=("$path exists with other content. Differences (- now, + this script):")
                refused+=("$(diff -u --label "$path (now)" --label "$path (new)" -- "$path" <(printf '%s\n' "$content") || true)")
            fi
        done
        if [[ $LINK_STATE == differs ]]; then
            refused+=("$SITE_LINK exists and is not a link to $SITE_CONF ($(ls -ld -- "$SITE_LINK")).")
        fi
        if [[ $CRON_STATE == differs ]]; then
            refused+=("$WEB_USER's crontab runs schedule:run, but not exactly once as README section 8 has it:")
            refused+=("$(grep 'artisan schedule:run' <<< "$CRON_NOW")")
        fi
        if [[ ${#refused[@]} -gt 0 ]]; then
            printf '%s\n' "${refused[@]}" >&2
            die "nothing was changed. This server already has an Alodium configuration. If it is the one to replace, run again with --force (old files are kept as *.bak-$STAMP)."
        fi
    elif [[ $SITE_STATE == differs ]] && grep -q 'managed by Certbot' -- "$SITE_CONF"; then
        echo "Warning: --force replaces the HTTPS settings Certbot added to $SITE_CONF. Run the certbot step again afterwards." >&2
    fi

    # Section 4.
    write_file "$ENV_FILE" "$ENV_CONTENT" "$env_state"
    if [[ $env_state == new ]] && ! $DRY_RUN; then
        chown --reference="$APP_DIR/astro" -- "$ENV_FILE" # same owner as the rest of the app
    fi

    [[ -d $APP_DIR/astro-public ]] || run mkdir -p "$APP_DIR/astro-public"

    # Section 5, Nginx.
    write_file "$SITE_CONF" "$NGINX_CONTENT" "$SITE_STATE"
    case $LINK_STATE in
        same) echo "Unchanged: $SITE_LINK" ;;
        new) run ln -s "$SITE_CONF" "$SITE_LINK" ;;
        differs)
            if [[ -L $SITE_LINK ]]; then
                OLD_LINK_TARGET=$(readlink -- "$SITE_LINK")
                echo "Replacing $SITE_LINK (was a link to $OLD_LINK_TARGET)"
                run ln -sfn "$SITE_CONF" "$SITE_LINK"
            else
                # A real file: keep it outside sites-enabled, where Nginx would load a copy too.
                run mv "$SITE_LINK" "$NGINX_DIR/sites-available/alodium-enabled.bak-$STAMP"
                run ln -s "$SITE_CONF" "$SITE_LINK"
                LINK_STATE=moved
            fi
            ;;
    esac
    if [[ -L $DEFAULT_LINK ]]; then
        DEFAULT_TARGET=$(readlink -- "$DEFAULT_LINK")
        run rm -f "$DEFAULT_LINK"
    elif [[ -e $DEFAULT_LINK ]]; then
        # Not the stock link: keep the file, outside sites-enabled.
        DEFAULT_MOVED="$NGINX_DIR/sites-available/default-enabled.bak-$STAMP"
        run mv "$DEFAULT_LINK" "$DEFAULT_MOVED"
    fi

    if $DRY_RUN; then
        echo "  would run: nginx -t"
        echo "  would run, only if nginx -t passes: systemctl reload nginx"
    elif nginx -t; then
        run systemctl reload nginx
    else
        rollback_nginx
        die "nginx -t failed (output above). See README section 5."
    fi

    # Section 3: the domain values in Laravel's .env.
    write_laravel_env
    # Section 7: the queue worker.
    write_worker
    # Section 8: the scheduler.
    write_cron
    # Section 4: the Astro dependencies. Last, as it takes longest and nothing above needs it.
    install_astro
    # On its own, setup refreshes a cached config here. In "all" that waits for
    # the geoip step, so config:cache runs once.
    [[ $COMMAND == all ]] || refresh_config_cache

    echo
    if $DRY_RUN; then echo "Dry run of setup done: nothing was changed."; else echo "Setup done."; fi
    $DRY_RUN || echo "Check the worker with: supervisorctl status (alodium-worker_00 should be RUNNING)"
    echo "Still to do by hand:"
    echo "  If a firewall is active, open ports 22, 80 and 443 (README section 12)."
    [[ $COMMAND == setup ]] && echo "  HTTPS, once DNS points here: $0 certbot --admin-domain $ADMIN --site-domain $SITE --email ADDRESS (README section 5)."
    [[ $COMMAND == setup ]] && echo "  Visitor countries: $0 geoip --maxmind-key - (README section 6)."
    echo "  Automatic rebuilds of the public site: README section 9."
    return 0
}

# ---------------------------------------------------------------- certbot

# The addresses DNS gives for a name, one per line (A or AAAA). dig asks DNS
# itself; getent (if dig isn't installed) also reads /etc/hosts.
lookup() {
    local type=$1 name=$2
    if command -v dig > /dev/null; then
        dig +short "$type" "$name" 2> /dev/null | grep -E "$([[ $type == A ]] && echo '^[0-9.]+$' || echo '^[0-9a-fA-F]*:[0-9a-fA-F:]*$')" || true
    elif [[ $type == A ]]; then
        getent ahostsv4 "$name" | awk '{ print $1 }' || true
    else
        # getent maps IPv4-only names to ::ffff:a.b.c.d; those are not AAAA records.
        getent ahostsv6 "$name" | awk '{ print $1 }' | grep -v '^::ffff:' || true
    fi | sort -u
}

# This server's addresses: --server-ip, plus every global address on an interface.
server_ips() {
    [[ ${#SERVER_IPS[@]} -eq 0 ]] || printf '%s\n' "${SERVER_IPS[@]}"
    ip -o addr show scope global 2> /dev/null | awk '{ sub(/\/.*/, "", $4); print $4 }'
}

# Prints a line per name and returns 1 if any name doesn't point (only) here.
dns_ready() {
    local ips name type addrs addr ok=0
    ips=$(server_ips | sort -u)
    echo "This server's addresses: ${ips//$'\n'/ }"
    command -v dig > /dev/null || echo "(dig is not installed, so names are looked up with getent, which also reads /etc/hosts)"
    for name in "$SITE" "www.$SITE" "$ADMIN"; do
        for type in A AAAA; do
            addrs=$(lookup "$type" "$name")
            if [[ -z $addrs ]]; then
                [[ $type == A ]] && { echo "  NOT READY  $name: no A record yet"; ok=1; }
                continue
            fi
            for addr in $addrs; do
                if ! grep -qxF -- "$addr" <<< "$ips"; then
                    if [[ $type == A ]]; then
                        echo "  NOT READY  $name: A record $addr is not this server"
                    else
                        echo "  NOT READY  $name: AAAA (IPv6) record $addr is not this server. Let's Encrypt tries IPv6 first: remove the record or point it here."
                    fi
                    ok=1
                    continue 2
                fi
            done
            echo "  ok         $name: $type ${addrs//$'\n'/ }"
        done
    done
    return $ok
}

certbot_step() {
    echo "== Certbot: README section 5 (HTTPS) for $SITE, www.$SITE and $ADMIN"
    # After setup in "all"; on its own, setup must have written the config for these names.
    if [[ $COMMAND == certbot ]]; then
        [[ -f $SITE_CONF ]] || die "$SITE_CONF not found. Run the setup step first."
        local name
        for name in "$ADMIN" "$SITE" "www.$SITE"; do
            awk '$1 == "server_name"' "$SITE_CONF" | tr -d ';' | tr -s ' \t' '\n' | grep -qxF -- "$name" \
                || die "$SITE_CONF has no server_name $name, so Certbot couldn't install the certificate. Run setup with the same domains first."
        done
    fi

    if $SKIP_DNS; then
        echo "DNS check skipped (--skip-dns-check)."
    elif ! dns_ready; then
        local again=("$0" certbot --admin-domain "$ADMIN" --site-domain "$SITE" --email "$EMAIL") ip
        for ip in ${SERVER_IPS[@]+"${SERVER_IPS[@]}"}; do again+=(--server-ip "$ip"); done
        cat >&2 <<EOF

DNS is not ready for Certbot yet, so it was not run. Nothing else is wrong:
Let's Encrypt can only verify names that point to this server.
- Create A records for $SITE, www.$SITE and $ADMIN with this server's IP
  address (README section 5, DNS). With Cloudflare, choose "DNS only" (grey cloud).
- Check a name with:  dig +short A $SITE
  Changes can take from minutes to a few hours, depending on the record's TTL.
- Then run the certbot step again:
 $(printf ' %q' "${again[@]}")
If this server's public IP is not on a network interface (NAT), add
--server-ip PUBLIC_IP. To run Certbot without this check, add --skip-dns-check.
EOF
        exit 2
    fi

    if ! command -v certbot > /dev/null || ! dpkg -s python3-certbot-nginx > /dev/null 2>&1; then
        run apt install -y certbot python3-certbot-nginx
    fi
    # The README's command, made non-interactive. --redirect makes the HTTP-to-HTTPS
    # redirect the README describes explicit, instead of relying on Certbot's default.
    run certbot --nginx -d "$SITE" -d "www.$SITE" -d "$ADMIN" \
        --non-interactive --agree-tos --email "$EMAIL" --redirect

    echo
    if $DRY_RUN; then
        echo "Dry run of the certbot step done: Certbot was not run."
    else
        echo "Certbot done. Test https://$SITE and https://$ADMIN in a browser (README section 5)."
    fi
}

# ---------------------------------------------------------------- geoip

# Like run(), from directory $1, as the README's commands run after "cd /var/www/alodium".
run_in() {
    local dir=$1
    shift
    if $DRY_RUN; then printf '  would run (in %s):' "$dir"; else printf '+ (in %s)' "$dir"; fi
    printf ' %q' "$@"
    echo
    $DRY_RUN || (cd "$dir" && "$@")
}

# The last 4 characters, to tell keys apart without showing them.
key_hint() { printf '...%s' "${1: -4}"; }

geoip_step() {
    echo "== GeoIP: README section 6 (visitor countries)"
    local env_file="$APP_DIR/.env"
    [[ -f $APP_DIR/artisan ]] || die "$APP_DIR/artisan not found. Clone the app to $APP_DIR first (README section 3)."
    [[ -f $env_file ]] || die "$env_file not found. Create it first (README section 3)."

    if [[ -z $MAXMIND_KEY ]]; then
        GEOIP_DEFERRED=true
        cat <<EOF
No MaxMind licence key given, so the GeoIP database isn't installed yet and nothing
was changed. Until it is, visits are recorded without a country (the dashboard's
Site status says so). Each client uses a MaxMind account of their own, not a
shared key, so each install is that client's own use of MaxMind's data:
  1. Sign up for a free GeoLite account at https://www.maxmind.com/en/geolite2/signup
  2. Log in, and in the account's GeoIP / GeoLite menu generate a license key.
  3. Run: $0 geoip --maxmind-key -
     (type or paste the key; it isn't shown)
EOF
        return 0
    fi

    # The key in .env now: no line, an empty value (as in .env.example), this key, or another one.
    local current state
    current=$(awk '/^MAXMIND_LICENSE_KEY=/ { sub(/^[^=]*=/, ""); gsub(/^["\047]|["\047]$/, ""); print; exit }' "$env_file")
    if ! grep -q '^MAXMIND_LICENSE_KEY=' "$env_file"; then state=new
    elif [[ -z $current ]]; then state=empty
    elif [[ $current == "$MAXMIND_KEY" ]]; then state=same
    else state=differs
    fi
    if [[ $state == differs ]] && ! $FORCE; then
        die "$env_file already has a different MAXMIND_LICENSE_KEY ($(key_hint "$current")). Nothing was changed. If this client's key has changed, run again with --force."
    fi

    if [[ $state == same ]]; then
        echo "Unchanged: MAXMIND_LICENSE_KEY in $env_file ($(key_hint "$MAXMIND_KEY"))"
    elif $DRY_RUN; then
        echo "  would set MAXMIND_LICENSE_KEY in $env_file to the key given ($(key_hint "$MAXMIND_KEY"))"
    else
        if [[ $state == differs ]]; then
            cp -p -- "$env_file" "$env_file.bak-$STAMP"
            echo "Kept the old $env_file as $env_file.bak-$STAMP. It holds the old key and the other secrets: delete it once the new key works."
        fi
        # Replaces the line (or adds it). In place (">"), so .env keeps its owner and mode.
        local content
        content=$(KEY_LINE="$(maxmind_env_line "$MAXMIND_KEY")" awk '
            /^MAXMIND_LICENSE_KEY=/ && !done { print ENVIRON["KEY_LINE"]; done = 1; next }
            { print }
            END { if (!done) print ENVIRON["KEY_LINE"] }' "$env_file")
        printf '%s\n' "$content" > "$env_file"
        echo "Set MAXMIND_LICENSE_KEY in $env_file ($(key_hint "$MAXMIND_KEY"))"
    fi

    # The download reads the key from the config: a cached one is refreshed first
    # (together with setup's .env changes in "all", so config:cache runs once).
    [[ $state == same ]] || CONFIG_STALE=true
    refresh_config_cache

    # The README's commands, word for word (fixed strings, so splitting them is safe).
    local command words
    while IFS= read -r command; do
        read -ra words <<< "$command"
        run_in "$APP_DIR" "${words[@]}" || die "\"$command\" failed (output above). Check the key in the MaxMind account; a new key can take a few minutes to work."
    done < <(geoip_commands)

    if ! $DRY_RUN; then
        local database="$APP_DIR/storage/app/geoip/GeoLite2-Country.mmdb"
        [[ -s $database ]] || die "$database is missing after the download."
        echo "Installed $database ($(du -h "$database" | cut -f1)). The scheduler updates it monthly (README section 8)."
    fi
}

# ---------------------------------------------------------------- main

if $DRY_RUN; then
    echo "DRY RUN: nothing is written, Nginx is not tested or reloaded, Supervisor, npm and php artisan are not run, crontabs are only read, and neither Certbot nor the GeoIP download is run."
fi
case $COMMAND in
    setup) setup_step ;;
    certbot) certbot_step ;;
    geoip) geoip_step ;;
    # geoip before certbot: certbot stops the script while DNS isn't ready. The
    # config cache is refreshed before it too, for the same reason.
    all) setup_step; echo; geoip_step; refresh_config_cache; echo; certbot_step ;;
esac
# No key yet: everything else is done; the geoip step can run again later.
if $GEOIP_DEFERRED; then exit 2; fi
