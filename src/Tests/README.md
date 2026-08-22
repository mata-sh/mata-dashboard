# Tests

The test suite uses PHPUnit 12. Tests live under `src/Tests/`:

- `Unit/` tests isolated classes and services.
- `Integration/` tests database, routing, configuration, and multi-component
  behavior.
- `Fixtures/` and `Helpers/` contain shared test data and helpers.

Run tests through Composer or the mode-aware Make target:

```bash
composer test
MODE=local ENV=dev make test
```

`make test` requires `make setup` first. Docker runs the suite inside the
`mata-web` container. Native runs use the local `vendor/bin/phpunit`.

Run one class or filter one test with PHPUnit:

```bash
vendor/bin/phpunit src/Tests/Unit/Http/Routing/RouterSetupTest.php
vendor/bin/phpunit --filter test_name
```

The full quality check is:

```bash
composer check
```

It runs PHP-CS-Fixer in dry-run mode, PHPStan at the configured level, the
entrypoint analysis, and PHPUnit. Tests should cover failure and security paths,
not only successful requests. Mock external boundaries such as HTTP and mail;
keep the behavior under test real.
