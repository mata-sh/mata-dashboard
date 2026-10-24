#!/bin/sh
# MATA Dashboard installer: curl -fsSL https://mata.sh/install.sh | sh
#
# Clones a release into ./mata-dashboard and runs the unattended setup. Docker and production
# by default; --native prepares a checkout for a web server you configure yourself.
# Never runs sudo: whatever needs root is printed for you to run. Safe to run again.
#
# Everything runs from main() on the last line, so a download cut short does nothing.
set -eu

# The release workflow writes the tag here; empty means the newest release.
MATA_VERSION_DEFAULT=
REPO_URL=${MATA_REPO_URL:-https://github.com/mata-sh/mata-dashboard.git}
DOCS_URL=https://docs.mata.sh

usage() {
    cat <<'EOF'
Usage: curl -fsSL https://mata.sh/install.sh | sh -s -- [options]

  --docker          Docker install (the default)
  --native          Native install (PHP, MySQL, web server, crontab are yours to set up)
  --dir DIR         Checkout directory (default: ./mata-dashboard)
  --version TAG     Release to install (default: the newest)
  --admin NAME      Admin account to create (default: admin; Docker only)
  --dry-run         Print the commands instead of running them
  -h, --help        Show this help

Environment: MATA_REPO_URL, MATA_DIR, MATA_VERSION, MATA_ADMIN_USER, and for --native MATA_USERS_FILE
(default /var/lib/mata-dashboard/users.json).
EOF
}

say() { printf '%s\n' "$*"; }
fail() {
    printf 'Error: %s\n' "$1" >&2
    shift
    for line in "$@"; do printf '%s\n' "$line" >&2; done
    exit 1
}

# Children get /dev/null as stdin: under curl | sh, stdin is the rest of this script.
run() {
    say "+ $*"
    [ "$dry_run" = yes ] || "$@" </dev/null
}

parse_args() {
    mode=docker
    dir=${MATA_DIR:-mata-dashboard}
    version=${MATA_VERSION:-$MATA_VERSION_DEFAULT}
    admin=${MATA_ADMIN_USER:-admin}
    users_file=${MATA_USERS_FILE:-/var/lib/mata-dashboard/users.json}
    dry_run=no
    while [ $# -gt 0 ]; do
        case $1 in
            --docker) mode=docker ;;
            --native) mode=native ;;
            --dir) [ $# -ge 2 ] || fail "--dir needs a value"; dir=$2; shift ;;
            --version) [ $# -ge 2 ] || fail "--version needs a value"; version=$2; shift ;;
            --admin) [ $# -ge 2 ] || fail "--admin needs a value"; admin=$2; shift ;;
            --dry-run) dry_run=yes ;;
            -h|--help) usage; exit 0 ;;
            *) usage >&2; fail "unknown option: $1" ;;
        esac
        shift
    done
}

native_hint() {
    say "  or install natively (PHP 8.3, Composer, MySQL, and a web server):"
    say "     curl -fsSL https://mata.sh/install.sh | sh -s -- --native"
}

check_tools() {
    tools="git make"
    [ "$mode" = docker ] || tools="$tools php composer"
    missing=
    for tool in $tools; do
        command -v "$tool" >/dev/null 2>&1 || missing="$missing $tool"
    done
    [ -z "$missing" ] || fail "missing:$missing. Install them with your package manager, then run this again."
}

check_docker() {
    if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        fail "Docker with the Compose plugin is not installed. Either" \
            "  install it (https://docs.docker.com/engine/install/), then run this again," \
            "$(native_hint)"
    fi
    if ! docker_error=$(docker info 2>&1 >/dev/null); then
        case $docker_error in
            *"permission denied"*)
                fail "$(id -un) may not use Docker. Either" \
                    "  add the account to the docker group (root-equivalent), log in again, and rerun:" \
                    "     sudo usermod -aG docker $(id -un)" \
                    "  or run this as root," \
                    "$(native_hint)" ;;
            *) fail "the Docker daemon is not running. Start it (e.g. sudo systemctl start docker), then run this again." ;;
        esac
    fi
}

resolve_version() {
    [ -z "$version" ] || return 0
    version=$(git ls-remote --tags --refs --sort=-v:refname "$REPO_URL" '[0-9]*' | head -n1 | sed 's|.*refs/tags/||')
    [ -n "$version" ] || fail "could not find a release at $REPO_URL"
}

checkout() {
    if [ -e "$dir" ]; then
        [ -f "$dir/scripts/setup.sh" ] && [ -d "$dir/.git" ] \
            || fail "$dir exists and is not a MATA checkout. Pass --dir to install elsewhere."
        current=$(git -C "$dir" describe --tags --exact-match 2>/dev/null || git -C "$dir" rev-parse --short HEAD)
        say "Using the existing checkout in $dir ($current); this installer does not upgrade it."
        return 0
    fi
    resolve_version
    say "Installing MATA Dashboard $version into $dir"
    run git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$version" "$REPO_URL" "$dir"
}

setup() {
    if [ "$mode" = docker ]; then
        run make -C "$dir" setup NONINTERACTIVE=1 MODE=docker ENV=prod START_SERVICES=yes ADMIN_USER="$admin"
    else
        # CREATE_USERS_DIR=no: setup prints the root command for the directory instead of trying sudo.
        run make -C "$dir" setup NONINTERACTIVE=1 MODE=native ENV=prod WEBSERVER=none \
            MATA_USERS_FILE="$users_file" CREATE_USERS_DIR=no
    fi
}

# Sets port and url for summary_docker. The web container answers a few seconds after setup returns.
verify_docker() {
    port=$(sed -n 's/^WEB_PORT=//p' "$dir/.env" 2>/dev/null | tr -d '"[:space:]')
    port=${port:-3300}
    url=http://127.0.0.1:$port
    command -v curl >/dev/null 2>&1 || return 0
    tries=0
    until curl -fsS "$url/health" >/dev/null 2>&1; do
        tries=$((tries + 1))
        [ "$tries" -lt 30 ] || fail "the dashboard does not answer at $url/health. See: make -C $dir logs"
        sleep 2
    done
}

summary_docker() {
    cat <<EOF

MATA Dashboard is running at $url (this host only).

  Sign in:   $admin. A new account's password is printed above as "Password (shown once)";
             a rerun keeps the existing one. It is not stored anywhere; to set a new one:
             make -C $dir mata ARGS="user passwd $admin"
  From your machine:
             ssh -L $port:127.0.0.1:$port $(id -un)@$(hostname)   then open http://localhost:$port
  On a domain: put an HTTPS reverse proxy in front of port $port.

Next steps, from $dir:
  1. Install MATA Node on each server and add it:
       make mata ARGS="server add web-01 --api-url https://node.example.com/"
  2. Check the installation (cron's first run is within five minutes):
       make doctor
       make mata ARGS="status"

Docs:      $DOCS_URL/guides/quickstart-docker/
Uninstall: make -C $dir uninstall, then delete $dir
EOF
}

summary_native() {
    cat <<EOF

The checkout in $dir is prepared. Finish the install yourself:

  1. Create the MySQL database and user; set them in $dir/config/db.config.env.
  2. As root, create the users file directory with the install command printed above
     ($(dirname "$users_file"), owned by the PHP-FPM user, mode 700).
  3. Serve $dir/public with your web server (examples: $dir/docs/examples/webserver-configs/).
  4. Install the crontab from $dir/cron/crontab.shared-hosting.template.
  5. From $dir:
       make db-migrate ALLOW_PRODUCTION=CONFIRM_PRODUCTION
       make mata ARGS="user create $admin admin --generate-password --yes"
       make doctor

Docs: $DOCS_URL/guides/quickstart/
EOF
}

main() {
    parse_args "$@"
    check_tools
    if [ "$mode" = docker ]; then check_docker; fi
    checkout
    setup
    if [ "$dry_run" = yes ]; then exit 0; fi
    if [ "$mode" = docker ]; then
        verify_docker
        summary_docker
    else
        summary_native
    fi
}

main "$@"
