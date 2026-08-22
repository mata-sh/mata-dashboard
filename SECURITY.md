# Security policy

## Reporting a vulnerability

Do not report security issues in a public GitHub issue. Email **mata@xarc.dev**
with:

- a short description and impact;
- affected version, configuration, or deployment mode;
- reproduction steps or a proof of concept;
- any suggested mitigation.

Please avoid including real credentials or personal data. We will acknowledge
reports and coordinate disclosure with the reporter.

## Supported versions

Security reports should target the current `main` branch or latest tagged
release. Fixes land on `main`; affected releases receive a new patch release.
Older releases and unmaintained branches are not supported.

## Deployment guidance

See the [secure installation guide](docs/guides/secure-application.md) for the
production baseline and [security architecture](docs/architecture/security.md)
for the threat model.
