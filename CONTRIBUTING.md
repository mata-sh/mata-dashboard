# Contributing

## Before you open a pull request

MATA is maintained by one developer alongside other work, so reviews can take
a while.

- **Bugs and questions:** open an issue with the version (`bin/mata --version`),
  native or Docker mode, and the output of `bin/mata doctor` with secrets
  removed.
- **Typos and documentation fixes:** open a pull request directly.
- **Anything else:** open an issue first and describe the problem before
  writing code. Changes should fit the [scope](README.md#scope).
- **Security issues:** follow [`SECURITY.md`](SECURITY.md), not a public issue.

A pull request may be declined even when the code is good, if it adds more
maintenance than the project can take on. That is a decision about scope, not
about the work.

## Start

```bash
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
make setup
```

Choose native or Docker mode and a `dev`, `demo`, or `prod` environment. Setup
creates the core configuration; create a development user with
`make mata ARGS="user create"` if setup did not create one.

## Checks

For a native development checkout with dev dependencies:

```bash
composer check
make opa-validate
make opa-test
mkdocs build --strict
```

`composer check` runs PHP-CS-Fixer, PHPStan, entrypoint analysis, and PHPUnit.
CI also validates and audits Composer metadata. Docker contributors can run
`composer check` inside the development container; `MODE=docker ENV=dev make
test` runs PHPUnit only.

`make install-hooks` installs a pre-commit hook that invokes host
`composer check`. Install it only when Composer and dev dependencies are
available on the host.

Documentation preview requires MkDocs and the shadcn theme. See
[documentation](docs/development/documentation.md).

## Changes

Use a feature, bugfix, or hotfix branch. Commit messages use a short type and
scope, for example:

```text
fix(router): reject malformed route parameters
```

Common types are `feat`, `fix`, `docs`, `refactor`, `test`, and `chore`.
Update documentation when a command, route, configuration key, or runtime
behavior changes.

Do not commit secrets, generated `site/` output, local `.env` files, or
populated configuration files.

## Changelog

Follow the [changelog guide](docs/development/changelog.md).

## Release

1. Rename `[Unreleased]` to the new version heading.
2. Tag the commit with a plain semantic version, without a `v` prefix (for example `0.7.0`).
3. Push the tag. This runs `.github/workflows/release.yml`.
