# User Management CLI

Manage dashboard users with the PHP CLI script. This is the only supported way to create, update, and delete users.

## User Management with Make

Run `make setup` once to select a native or Docker installation and the `dev`, `demo`, or `prod` environment. User commands then use that selection:

```shell
make users-list     # List all users
make users-create   # Create a user interactively
make users-unlock   # Unlock a user account
make users-delete   # Delete a user
make users-passwd   # Change a user password
```

Override the saved selection for one command when needed:

```shell
make users-list MODE=local ENV=prod
make users-create MODE=docker ENV=dev
```

Production Docker commands use a one-off administrative container. The normal `mata-web` container keeps `config/` read-only.

## User Management (PHP)

Without Docker you can also use the PHP CLI directly:

```bash
php scripts/user-management/manage-users.php help
```

**Available commands:**

- `list` - List all users
- `create` - Create a new user
- `passwd` - Change a user password
- `unlock` - Unlock a user
- `delete` - Delete a user

## Initial User

`make setup` offers to create the initial user. Setup does not install static default credentials.

## Persistent authentication data

Set `MATA_USERS_FILE` to an absolute path outside the deployed application and web root:

```dotenv
MATA_USERS_FILE=/var/lib/mata-dashboard/users.json
```

Provision its directory once for the PHP-FPM user. The application creates the lock file and atomically replaces `users.json` there.

```bash
install -d -o <php-fpm-user> -g <php-fpm-group> -m 700 /var/lib/mata-dashboard
```

On Plesk/shared hosting, `/var/lib` is usually unavailable. Use a sibling of the web root instead:

```dotenv
MATA_USERS_FILE=/var/www/vhosts/<domain>/mata-private/users.json
```

The file and lock file are owned by PHP-FPM and use mode `0600`. Deployments must not copy, delete, or change this directory.

Docker always uses `/var/lib/mata-dashboard/users.json` on its `mata_users` named volume, regardless of `MATA_USERS_FILE` in `config.env`.

If `MATA_USERS_FILE` is empty, MATA keeps the legacy location `config/<env>/users.json`. This is intended for development only.

## Environments

The script uses the same `MATA_USERS_FILE` as the web application. Without it, it targets `config/dev/`, `config/demo/`, or `config/prod/` based on `APP_ENV`. You can override with `--env=ENV`.

```bash
make users-list MODE=docker ENV=prod
```

Production writes require explicit confirmation.

## Files and Logs

- Users: `MATA_USERS_FILE`, or the legacy `config/<env>/users.json`
- Audit log: `logs/user-management-audit.log`
