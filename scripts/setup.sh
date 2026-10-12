#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT_DIR"

MAKE_CMD=${MAKE_CMD:-make}
MODE=${MODE:-}
ENV=${ENV:-}
if [[ -n "$ENV" ]]; then
    ENV_WAS_PROVIDED=yes
else
    ENV_WAS_PROVIDED=no
fi
START_SERVICES=${START_SERVICES:-}
RUN_MIGRATIONS=${RUN_MIGRATIONS:-}
INSTALL_OPA=${INSTALL_OPA:-}
INSTALL_OPA_SERVICE=${INSTALL_OPA_SERVICE:-}
CREATE_USER=${CREATE_USER:-}
ADMIN_USER=${ADMIN_USER:-}
NONINTERACTIVE=${NONINTERACTIVE:-}
WEBSERVER=${WEBSERVER:-}

is_yes() {
    case "${1,,}" in
        y|yes|1|true|on) return 0 ;;
        *) return 1 ;;
    esac
}

# A pseudo-terminal (some agents, CI runners) looks like a person: NONINTERACTIVE=1 or CI=true says otherwise.
interactive() {
    [[ -t 0 ]] || return 1
    ! is_yes "${NONINTERACTIVE:-}" && ! is_yes "${CI:-}"
}

# Without a person to type a password, sudo fails instead of waiting.
run_sudo() {
    if interactive; then
        sudo "$@"
    else
        sudo -n "$@"
    fi
}

prompt_yes_no() {
    local variable=$1
    local prompt=$2
    local default=$3
    local value=${!variable:-}

    if [[ -z "$value" ]]; then
        if interactive; then
            read -r -p "$prompt" value
        else
            value=$default
        fi
    fi

    if [[ -z "$value" ]]; then
        value=$default
    fi

    if is_yes "$value"; then
        printf -v "$variable" '%s' yes
    else
        printf -v "$variable" '%s' no
    fi
}

set_env_value() {
    local file=$1
    local key=$2
    local value=$3
    local temporary

    if [[ "$value" == *[[:space:]]* ]]; then
        value=${value//\\/\\\\}
        value=${value//\"/\\\"}
        value="\"$value\""
    fi

    temporary=$(mktemp)
    if [[ -f "$file" && ! -r "$file" ]]; then
        local owner
        owner=$(stat -c '%U' "$file" 2>/dev/null || stat -f '%Su' "$file" 2>/dev/null || true)
        if [[ -z "$owner" ]] || ! command -v sudo >/dev/null 2>&1; then
            rm -f "$temporary"
            printf 'ERROR: Cannot read %s. Run setup as its owner.\n' "$file" >&2
            exit 1
        fi
        printf 'Reading %s as %s (sudo required).\n' "$file" "$owner"
        run_sudo -u "$owner" awk -v key="$key" -v value="$value" '
            BEGIN { updated = 0 }
            $0 ~ "^" key "=" {
                if (!updated) print key "=" value
                updated = 1
                next
            }
            { print }
            END {
                if (!updated) print key "=" value
            }
        ' "$file" > "$temporary"
    elif [[ -f "$file" ]]; then
        awk -v key="$key" -v value="$value" '
            BEGIN { updated = 0 }
            $0 ~ "^" key "=" {
                if (!updated) print key "=" value
                updated = 1
                next
            }
            { print }
            END {
                if (!updated) print key "=" value
            }
        ' "$file" > "$temporary"
    else
        printf '%s=%s\n' "$key" "$value" > "$temporary"
    fi
    if [[ -w "$file" ]] || { [[ ! -e "$file" ]] && [[ -w "$(dirname "$file")" ]]; }; then
        cat "$temporary" > "$file"
    else
        local owner
        owner=$(stat -c '%U' "$file" 2>/dev/null || stat -f '%Su' "$file" 2>/dev/null || true)
        if [[ -z "$owner" ]] || ! command -v sudo >/dev/null 2>&1; then
            rm -f "$temporary"
            printf 'ERROR: Cannot update %s. Run setup as its owner.\n' "$file" >&2
            exit 1
        fi
        printf 'Updating %s as %s (sudo required).\n' "$file" "$owner"
        run_sudo -u "$owner" tee "$file" < "$temporary" >/dev/null
    fi
    rm -f "$temporary"
}

get_env_value() {
    local file=$1
    local key=$2

    awk -v key="$key" '
        $0 ~ "^" key "=" {
            value = substr($0, length(key) + 2)
            sub(/^[[:space:]]+/, "", value)
            sub(/[[:space:]]+$/, "", value)
            if (value ~ /^".*"$/) {
                sub(/^"/, "", value)
                sub(/"$/, "", value)
            }
        }
        END { print value }
    ' "$file" 2>/dev/null
}

# Replaces a template placeholder with a random hex secret; a value someone set is kept.
generate_placeholder_secret() {
    local file=$1
    local key=$2
    local placeholder=$3

    if [[ "$(get_env_value "$file" "$key")" != "$placeholder" ]]; then
        return
    fi
    set_env_value "$file" "$key" "$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')"
    printf 'Generated %s in %s.\n' "$key" "$file"
}

configure_users_file() {
    local users_file=${MATA_USERS_FILE:-}
    local users_file_given=${MATA_USERS_FILE:+yes}
    local users_directory
    local php_fpm_user=${PHP_FPM_USER:-}
    local php_fpm_group
    local create_directory=${CREATE_USERS_DIR:-}

    if [[ -z "$users_file" ]]; then
        users_file=$(get_env_value config/config.env MATA_USERS_FILE)
    fi

    if [[ -z "$users_file_given" ]] && interactive; then
        printf '\nAuthentication data path\n'
        printf '%s\n' 'Production requires an absolute path outside the deployment and web root.'
        printf '%s\n' 'Leave empty only for the in-project development location.'
        read -r -p "Users file [${users_file:-empty}]: " entered_users_file
        if [[ -n "$entered_users_file" ]]; then
            users_file=$entered_users_file
        fi
    fi

    if [[ -n "$users_file" && "$users_file" != /* ]]; then
        printf 'ERROR: MATA_USERS_FILE must be an absolute path.\n' >&2
        exit 1
    fi

    set_env_value config/config.env MATA_USERS_FILE "$users_file"

    if [[ -z "$users_file" ]]; then
        return
    fi

    users_directory=$(dirname "$users_file")
    if [[ -d "$users_directory" ]]; then
        return
    fi

    printf 'Authentication directory does not exist: %s\n' "$users_directory"
    prompt_yes_no create_directory "Create it for the PHP-FPM user? [Y/n]: " yes
    if [[ "$create_directory" != yes ]]; then
        printf 'Create it before using user management:\n'
        printf '  install -d -o <php-fpm-user> -g <php-fpm-group> -m 700 %q\n' "$users_directory"

        return
    fi

    if [[ -z "$php_fpm_user" ]]; then
        if interactive; then
            read -r -p 'PHP-FPM user [www-data]: ' php_fpm_user
        fi
        php_fpm_user=${php_fpm_user:-www-data}
    fi
    php_fpm_group=${PHP_FPM_GROUP:-$(id -gn "$php_fpm_user" 2>/dev/null || printf '%s' "$php_fpm_user")}

    if command -v sudo >/dev/null 2>&1 \
        && run_sudo install -d -o "$php_fpm_user" -g "$php_fpm_group" -m 700 "$users_directory"; then
        printf 'Created private authentication directory: %s\n' "$users_directory"
    else
        printf 'Could not create the directory with PHP-FPM ownership. Run:\n' >&2
        printf '  sudo install -d -o %q -g %q -m 700 %q\n' "$php_fpm_user" "$php_fpm_group" "$users_directory" >&2
    fi
}

printf '\nMATA Dashboard Setup\n'
printf '====================\n\n'
if interactive; then
    printf 'Prompts follow. To run without them, e.g. from a script or agent:\n'
    printf '  NONINTERACTIVE=1 make setup MODE=docker ENV=prod START_SERVICES=yes ADMIN_USER=admin\n'
    printf 'Variables and defaults: https://docs.mata.sh/guides/quickstart-docker/#unattended-setup\n\n'
fi

if [[ -z "$MODE" ]]; then
    if interactive; then
        read -r -p "Is this a Docker deployment? [y/N]: " docker_answer
    else
        docker_answer=no
    fi
    if is_yes "${docker_answer:-no}"; then
        MODE=docker
    else
        MODE=local
    fi
fi

if [[ -n "$ADMIN_USER" && ! "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,64}$ ]]; then
    printf 'ERROR: ADMIN_USER may only use letters, digits, ".", "_" and "-" (max 64).\n' >&2
    exit 1
fi
if [[ -z "$ADMIN_USER" ]] && ! interactive && is_yes "${CREATE_USER:-no}"; then
    printf 'ERROR: CREATE_USER=yes without a terminal needs ADMIN_USER=<name>.\n' >&2
    exit 1
fi

case "$MODE" in
    local|docker) ;;
    *)
        printf 'ERROR: MODE must be local or docker (received: %s)\n' "$MODE" >&2
        exit 1
        ;;
esac

if [[ -z "$ENV" ]]; then
    if interactive; then
        printf '\nApplication environment:\n\n'
        printf '  1) Development\n'
        printf '  2) Demo\n'
        printf '  3) Production\n\n'
        read -r -p "Select [1]: " environment_answer
    else
        environment_answer=1
    fi
    case "${environment_answer:-1}" in
        1|dev) ENV=dev ;;
        2|demo) ENV=demo ;;
        3|prod) ENV=prod ;;
        *)
            printf 'ERROR: Select 1, 2, or 3.\n' >&2
            exit 1
            ;;
    esac
fi

case "$ENV" in
    dev|demo|prod) ;;
    *)
        printf 'ERROR: ENV must be dev, demo, or prod (received: %s)\n' "$ENV" >&2
        exit 1
        ;;
esac

if [[ "$ENV" == prod && "$ENV_WAS_PROVIDED" == no ]] && interactive; then
    read -r -p 'Type "prod" to configure a production environment: ' production_confirmation
    if [[ "$production_confirmation" != prod ]]; then
        printf 'Production setup cancelled.\n'
        exit 1
    fi
fi

printf '\nSetup summary\n'
printf '%s\n' '-------------'
printf '  Mode:         %s\n' "$MODE"
printf '  Environment:  %s\n\n' "$ENV"

if [[ "$MODE" == local ]]; then
    "$MAKE_CMD" setup-local MODE=local ENV="$ENV" WEBSERVER="$WEBSERVER"
else
    "$MAKE_CMD" docker-init MODE=docker ENV="$ENV"
    if [[ ! -f .env ]]; then
        cp .env.example .env
        chmod 600 .env
        printf 'Created .env from .env.example.\n'
    fi
fi

set_env_value config/config.env APP_ENV "$ENV"
if [[ "$MODE" == local ]]; then
    configure_users_file
fi
if [[ "$MODE" == docker ]]; then
    set_env_value .env APP_ENV "$ENV"
    # The stack creates its database with these, so no one has to choose them.
    generate_placeholder_secret config/db.config.env DB_PASSWORD change_me
    generate_placeholder_secret .env MYSQL_ROOT_PASSWORD change_me_in_production
    if [[ "$ENV" == dev || "$ENV" == demo ]]; then
        "$MAKE_CMD" dev-setup MODE=docker ENV="$ENV"
    fi
fi

cat > .mata.mk <<EOF
# Generated by make setup. Command-line MODE/ENV values override these defaults.
MODE := $MODE
ENV := $ENV
EOF

printf '\nOptional startup tasks\n'
printf '%s\n\n' '----------------------'

migrations_ran=no
opa_state='not started'
user_state='not created'

if [[ "$MODE" == local ]]; then
    if [[ -x bin/opa ]]; then
        printf 'OPA binary is already installed at bin/opa.\n'
        INSTALL_OPA=no
    else
        prompt_yes_no INSTALL_OPA "Install the OPA binary now? [Y/n]: " yes
        if [[ "$INSTALL_OPA" == yes ]]; then
            "$MAKE_CMD" setup-opa
        fi
    fi

    if [[ -x bin/opa ]]; then
        prompt_yes_no INSTALL_OPA_SERVICE "Install and start the OPA systemd service (requires sudo)? [y/N]: " no
        if [[ "$INSTALL_OPA_SERVICE" == yes ]]; then
            "$MAKE_CMD" opa-install-service
            opa_state=running
        else
            opa_state=installed
        fi
    fi
else
    if [[ "$ENV" == prod ]]; then
        printf 'Review .env (DATA_PATH, WEB_BIND) before production startup.\n'
        prompt_yes_no START_SERVICES "Start Docker services (dashboard, database, and OPA) now? [y/N]: " no
    else
        prompt_yes_no START_SERVICES "Start Docker services (dashboard, database, and OPA) now? [Y/n]: " yes
    fi
    if [[ "$START_SERVICES" == yes ]]; then
        prompt_yes_no RUN_MIGRATIONS "Run database migrations after startup? [Y/n]: " yes
        if [[ "$ENV" == prod ]]; then
            "$MAKE_CMD" prod-fix-perms MODE=docker ENV=prod
            "$MAKE_CMD" prod-start MODE=docker ENV=prod RUN_MIGRATIONS="$RUN_MIGRATIONS"
        else
            "$MAKE_CMD" dev-start MODE=docker ENV="$ENV" RUN_MIGRATIONS="$RUN_MIGRATIONS"
        fi
        opa_state=running
        migrations_ran=$RUN_MIGRATIONS
    else
        RUN_MIGRATIONS=no
    fi
fi

if [[ "$MODE" == docker && "$START_SERVICES" != yes ]]; then
    CREATE_USER=no
elif [[ -n "$ADMIN_USER" ]]; then
    is_yes "${CREATE_USER:-yes}" && CREATE_USER=yes || CREATE_USER=no
elif interactive; then
    prompt_yes_no CREATE_USER "Create an initial user now? [Y/n]: " yes
else
    CREATE_USER=no
fi
# A rerun keeps the account and its password instead of failing on "User already exists".
if [[ "$CREATE_USER" == yes && -n "$ADMIN_USER" ]] \
    && "$MAKE_CMD" -s mata MODE="$MODE" ENV="$ENV" ARGS="user list --json" 2>/dev/null | grep -q "\"username\": \"$ADMIN_USER\""; then
    CREATE_USER=no
    user_state="$ADMIN_USER exists, unchanged"
fi
if [[ "$CREATE_USER" == yes ]]; then
    if [[ -n "$ADMIN_USER" ]] && interactive; then
        "$MAKE_CMD" mata MODE="$MODE" ENV="$ENV" ARGS="user create $ADMIN_USER admin"
    elif [[ -n "$ADMIN_USER" ]]; then
        # Without a terminal there is no password prompt: generate one and print it once.
        "$MAKE_CMD" mata MODE="$MODE" ENV="$ENV" ARGS="user create $ADMIN_USER admin --generate-password --yes"
    else
        "$MAKE_CMD" mata MODE="$MODE" ENV="$ENV" ARGS="user create"
    fi
    user_state=created
fi

printf '\nSetup complete\n'
printf '==============\n\n'
printf '  Mode:          %s\n' "$MODE"
printf '  Environment:   %s\n' "$ENV"
printf '  OPA:           %s\n' "$opa_state"
if [[ "$MODE" == docker ]]; then
    printf '  Migrations:    %s\n' "$migrations_ran"
fi
printf '  Initial user:  %s\n\n' "$user_state"
printf 'Useful commands:\n\n'
printf '  make doctor\n'
printf '  make mata ARGS="user create"\n'
printf '  make opa-status\n'
printf '  make help-all\n'
