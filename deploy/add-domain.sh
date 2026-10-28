#!/bin/bash
# README.md "Deployment", sections 4 (Astro public site) and 5 (DNS, Nginx and
# HTTPS), as a script: astro/.env.production, the plain-HTTP Nginx config, and
# the Let's Encrypt certificate. (robots.txt needs nothing: the Astro build
# writes it, with the Sitemap line from SITE_URL.)
#
# Only for a NEW Alodium deployment, on a fresh server for a new client, after
# README sections 1-3 (packages, swap, the app cloned to /var/www/alodium).
# It is NOT for the site already running in this repository's production: do
# not run it there. On a configured server it refuses to replace
# astro/.env.production, the Nginx config or its sites-enabled link unless
# --force is given.
#
# Usage, as root:
#   add-domain.sh setup   --admin-domain admin.client.example --site-domain client.example
#   add-domain.sh certbot --admin-domain admin.client.example --site-domain client.example --email you@client.example
#   add-domain.sh all     (the options of certbot)
#
#   setup    Section 4 and section 5 up to HTTPS: writes astro/.env.production,
#            creates astro-public/, writes the Nginx config, links it, removes
#            the default site, runs nginx -t and reloads only if the test passes.
#            Needs no DNS.
#   certbot  Section 5 "HTTPS": checks that SITE, www.SITE and ADMIN resolve to
#            this server, then runs Certbot. Run it (again) once DNS has propagated.
#   all      setup, then certbot.
#
# Options:
#   --email ADDRESS    for Let's Encrypt (expiry notices); needed by certbot and
#                      all. Running Certbot accepts the Let's Encrypt terms.
#   --force            replace an existing astro/.env.production, Nginx config or
#                      sites-enabled link; old files are kept as *.bak-TIMESTAMP
#   --dry-run          print what would be written and run, and change nothing
#                      (the DNS lookups are still made: they only read)
#   --skip-dns-check   run Certbot without checking DNS first
#   --server-ip IP     this server's public IP, if it isn't on a network interface
#                      (behind NAT); may be given more than once
#
# Exit codes: 0 done, 1 error or refused, 2 DNS not ready yet (anything setup
# wrote is kept; run the certbot step again later).
#
# ALODIUM_APP_DIR and ALODIUM_NGINX_DIR replace /var/www/alodium and /etc/nginx,
# so the script can be tested against a scratch directory.

set -euo pipefail

APP_DIR=${ALODIUM_APP_DIR:-/var/www/alodium}
NGINX_DIR=${ALODIUM_NGINX_DIR:-/etc/nginx}
ENV_FILE="$APP_DIR/astro/.env.production"
SITE_CONF="$NGINX_DIR/sites-available/alodium"
SITE_LINK="$NGINX_DIR/sites-enabled/alodium"
DEFAULT_LINK="$NGINX_DIR/sites-enabled/default"
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

case ${1:-} in
    setup | certbot | all) COMMAND=$1; shift ;;
    -h | --help) usage; exit 0 ;;
    '') usage >&2; exit 1 ;;
    *) die "unknown command \"$1\": use setup, certbot or all (see --help)" ;;
esac
while [[ $# -gt 0 ]]; do
    case $1 in
        --admin-domain | --site-domain | --email | --server-ip)
            [[ $# -ge 2 ]] || die "$1 needs a value"
            case $1 in
                --admin-domain) ADMIN=${2,,} ;;
                --site-domain) SITE=${2,,} ;;
                --email) EMAIL=$2 ;;
                --server-ip) SERVER_IPS+=("$2") ;;
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
check_domain --admin-domain "$ADMIN" admin.client.example
check_domain --site-domain "$SITE" client.example
# www.SITE is added for Certbot and Nginx, so "www.client.example" would become www.www.client.example.
[[ $SITE != www.* ]] || die "give --site-domain without www. (${SITE#www.}): www.${SITE#www.} is added automatically."
[[ $ADMIN != "$SITE" && $ADMIN != "www.$SITE" ]] || die "--admin-domain must be a different name from the site (for example admin.$SITE)."
if [[ $COMMAND != setup ]]; then
    [[ -n $EMAIL ]] || die "--email is missing: Let's Encrypt needs an address for expiry notices."
    [[ $EMAIL =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "--email \"$EMAIL\" doesn't look like an email address."
fi
for ip in ${SERVER_IPS[@]+"${SERVER_IPS[@]}"}; do
    [[ $ip =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ || $ip =~ ^[0-9a-fA-F:]+:[0-9a-fA-F:]*$ ]] || die "--server-ip \"$ip\" is not an IP address."
done

ENV_CONTENT=$(env_content "$ADMIN" "$SITE")
NGINX_CONTENT=$(nginx_content "$ADMIN" "$SITE")

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

setup_step() {
    echo "== Setup: README section 4 and section 5 (Nginx) for $ADMIN and $SITE"
    [[ -f $APP_DIR/astro/package.json ]] || die "$APP_DIR/astro/package.json not found. Clone the app to $APP_DIR first (README section 3)."
    [[ -d $NGINX_DIR/sites-available && -d $NGINX_DIR/sites-enabled ]] || die "$NGINX_DIR/sites-available or sites-enabled not found. Install Nginx first (README section 1)."
    command -v nginx > /dev/null || die "nginx is not installed (README section 1)."

    # Check everything before changing anything, so a refusal leaves the server as it was.
    local env_state refused=()
    env_state=$(file_state "$ENV_FILE" "$ENV_CONTENT")
    SITE_STATE=$(file_state "$SITE_CONF" "$NGINX_CONTENT")
    if [[ ! -e $SITE_LINK && ! -L $SITE_LINK ]]; then LINK_STATE=new
    elif [[ -L $SITE_LINK && "$(readlink -f -- "$SITE_LINK")" == "$(readlink -f -- "$SITE_CONF")" ]]; then LINK_STATE=same
    else LINK_STATE=differs
    fi

    if ! $FORCE; then
        local path
        for path in "$ENV_FILE" "$SITE_CONF"; do
            local state=$env_state content=$ENV_CONTENT
            [[ $path == "$SITE_CONF" ]] && state=$SITE_STATE content=$NGINX_CONTENT
            if [[ $state == differs ]]; then
                refused+=("$path exists with other content. Differences (- now, + this script):")
                refused+=("$(diff -u --label "$path (now)" --label "$path (new)" -- "$path" <(printf '%s\n' "$content") || true)")
            fi
        done
        if [[ $LINK_STATE == differs ]]; then
            refused+=("$SITE_LINK exists and is not a link to $SITE_CONF ($(ls -ld -- "$SITE_LINK")).")
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

    echo
    if $DRY_RUN; then echo "Dry run of setup done: nothing was changed."; else echo "Setup done."; fi
    echo "Still to do by hand (README section 4 and 5):"
    echo "  cd $APP_DIR/astro && npm install --legacy-peer-deps"
    echo "  If a firewall is active, open ports 22, 80 and 443 (README section 11)."
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

# ---------------------------------------------------------------- main

if $DRY_RUN; then
    echo "DRY RUN: nothing is written, Nginx is not tested or reloaded, and Certbot is not run."
fi
case $COMMAND in
    setup) setup_step ;;
    certbot) certbot_step ;;
    all) setup_step; echo; certbot_step ;;
esac
