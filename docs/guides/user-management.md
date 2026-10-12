# User Management CLI

Manage dashboard users with `bin/mata user`. This is the only supported way to create, update, and delete users.

```bash
php bin/mata user list                                     # --json for scripts
php bin/mata user create                                   # prompts for name, role, password
php bin/mata user create admin admin --generate-password   # prints the password once
echo "$PASSWORD" | php bin/mata user passwd admin --password-stdin
php bin/mata user unlock admin
php bin/mata user delete admin
```

`php bin/mata user <command> --help` lists the options. Production changes ask for a typed
confirmation; without a terminal they need `--yes` instead.

## Which account runs it

Run it as the account that owns the users file, which is the web server user:

```bash
sudo -u <php-fpm-user> php bin/mata user list
```

`bin/mata` refuses root: root-created `users.json` and `.lock` files can be unreadable or unwritable to PHP-FPM.

`make mata` picks the account for the installation selected by `make setup`: in Docker it runs
`bin/mata` in the web container as `www-data`, natively as the owner of `logs/`:

```shell
make mata ARGS="user create"
make mata MODE=docker ENV=prod ARGS="user list"
```

On Plesk, invoke it through the physical vhost path if the deploy checkout is not readable by PHP-FPM:

```bash
sudo -u <php-fpm-user> php /var/www/vhosts/<domain>/httpdocs/<app>/bin/mata user create admin admin
```

## Rules

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

`bin/mata user` uses the same `MATA_USERS_FILE` as the web application. Without it, it uses the shared `config/users.json`; `APP_ENV` does not select a separate user file.

Production writes require explicit confirmation.

## Files and Logs

- Users: `MATA_USERS_FILE`, or the development fallback `config/users.json`
- Audit log: `logs/user-management-audit.log`
