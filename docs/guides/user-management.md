# User Management CLI

Manage dashboard users with the PHP CLI script. This is the only supported way to create, update, and delete users.

## User Management (DOCKER)

When running in docker you can manage users with `make` commands:

```shell
make users-list    - List all users
make users-create  - Create new user (interactive)
make users-unlock  - Unlock user account
make users-delete  - Delete user
make users-passwd  - Change user password
```

## User Management (PHP)

Without docker ou can use the php CLI directly

```bash
php scripts/user-management/manage-users.php help
```

**Available commands:**

- `list` - List all users
- `create` - Create a new user
- `passwd` - Change a user password
- `unlock` - Unlock a user
- `delete` - Delete a user

## Default Docker Credentials

`make docker-setup` creates a single admin user in `config/dev/users.json`:

!!! warning "Credentials:" 
    Change the login immediately in any non-local deployment.

- **Username:** `mata`
- **Password:** `mata`


## Environments

The script targets the users.json in `config/dev/`, `config/demo/`, or `config/prod/` based on `APP_ENV`. You can override with `--env=ENV`.

```bash
docker-compose exec -u www-data mata-web php scripts/user-management/manage-users.php list --env=prod
```

Production writes require explicit confirmation.

## Files and Logs

- Users are stored in `config/<env>/users.json`
- Audit log: `logs/user-management-audit.log`

Keep `users.json` restricted:

```bash
chmod 600 config/<env>/users.json
```
