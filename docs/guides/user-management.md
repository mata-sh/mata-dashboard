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

## Environments

The script targets the users.json in `config/dev/`, `config/demo/`, or `config/prod/` based on `APP_ENV`. You can override with `--env=ENV`.

```bash
make users-list MODE=docker ENV=prod
```

Production writes require explicit confirmation.

## Files and Logs

- Users are stored in `config/<env>/users.json`
- Audit log: `logs/user-management-audit.log`

Keep `users.json` restricted:

```bash
chmod 600 config/<env>/users.json
```
