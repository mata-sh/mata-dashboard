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

## User Management (`bin/mata`)

`bin/mata user list|create|passwd|unlock|delete` runs the same checks as the script below
(`CLI_ALLOWED_USERS`, audit log, password policy) and also works without a terminal:

```bash
php bin/mata user create admin admin --generate-password   # prints the password once
echo "$PASSWORD" | php bin/mata user passwd admin --password-stdin
php bin/mata user list --json
```

Without a terminal, production changes and `delete` need `--yes` instead of the typed
confirmation. Run it as the user that owns `users.json`, as below; `bin/mata` refuses root.

## User Management (PHP script)

Without Docker you can also use the PHP CLI directly:

```bash
php scripts/user-management/manage-users.php help
```

When authentication data is owned by the PHP-FPM user, run the command as that user:

```bash
sudo -u <php-fpm-user> php scripts/user-management/manage-users.php create --env=prod
```

Do not run the command as root with `sudo php ...`. Root-created `users.json` and `.lock` files can be unreadable or unwritable to PHP-FPM.

On Plesk, invoke the script through the physical vhost path if the deploy checkout is not readable by PHP-FPM:

```bash
sudo -u <php-fpm-user> php /var/www/vhosts/<domain>/httpdocs/<app>/scripts/user-management/manage-users.php create --env=prod
```

**Available commands:**

- `list` - List all users
- `create` - Create a new user
- `passwd` - Change a user password
- `unlock` - Unlock a user
- `delete` - Delete a user

`create` and `passwd` require passwords of 12 characters to 72 bytes; bcrypt ignores
anything longer. The `demo` environment is exempt because its credentials are public.
Existing passwords keep working.

`create` accepts usernames of 1 to 64 letters, digits, dots, underscores or hyphens, and the
roles `viewer` and `admin`. Existing accounts with other usernames keep working.

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

If `MATA_USERS_FILE` is empty, MATA uses `config/users.json`. This is intended for development only.

!!! warning
    After upgrading to revision-based sessions, existing users must sign in with their password once. No manual `users.json` migration is required.

## Environments

The script uses the same `MATA_USERS_FILE` as the web application. Without it, it uses the shared `config/users.json`; `APP_ENV` does not select a separate user file.

```bash
make users-list MODE=docker ENV=prod
```

Production writes require explicit confirmation.

## Files and Logs

- Users: `MATA_USERS_FILE`, or the development fallback `config/users.json`
- Audit log: `logs/user-management-audit.log`
