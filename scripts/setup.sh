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

is_yes() {
    case "${1,,}" in
        y|yes|1|true|on) return 0 ;;
        *) return 1 ;;
    esac
}

prompt_yes_no() {
    local variable=$1
    local prompt=$2
    local default=$3
    local value=${!variable:-}

    if [[ -z "$value" ]]; then
        if [[ -t 0 ]]; then
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
        sudo -u "$owner" awk -v key="$key" -v value="$value" '
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
        sudo -u "$owner" tee "$file" < "$temporary" >/dev/null
    fi
    rm -f "$temporary"
}

printf '\nMATA Dashboard Setup\n'
printf '====================\n\n'

if [[ -z "$MODE" ]]; then
    if [[ -t 0 ]]; then
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

case "$MODE" in
    local|docker) ;;
    *)
        printf 'ERROR: MODE must be local or docker (received: %s)\n' "$MODE" >&2
        exit 1
        ;;
esac

if [[ -z "$ENV" ]]; then
    if [[ -t 0 ]]; then
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

if [[ "$ENV" == prod && "$ENV_WAS_PROVIDED" == no && -t 0 ]]; then
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
    "$MAKE_CMD" setup-local MODE=local ENV="$ENV"
else
    "$MAKE_CMD" docker-init MODE=docker ENV="$ENV"
    if [[ ! -f .env ]]; then
        cp .env.example .env
        printf 'Created .env from .env.example.\n'
    fi
fi

set_env_value config/config.env APP_ENV "$ENV"
if [[ "$MODE" == docker ]]; then
    set_env_value .env APP_ENV "$ENV"
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
        printf 'Review .env and config/db.config.env before production startup.\n'
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

prompt_yes_no CREATE_USER "Create an initial user now? [Y/n]: " yes
if [[ "$CREATE_USER" == yes ]]; then
    "$MAKE_CMD" users-create MODE="$MODE" ENV="$ENV"
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
printf '  make users-create\n'
printf '  make db-status\n'
printf '  make opa-status\n'
printf '  make help-all\n'
