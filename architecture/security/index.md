# Security architecture

MATA is designed for small, private monitoring installations. The dashboard pulls data from read-only nodes. Nodes do not push data, send mail, execute commands, or change monitored systems.

## Node authentication

Each node has one shared secret. The dashboard stores it as `api_key` in `config/servers.secrets.json`, separate from the versionable `config/servers.json`; the node stores it as `DASHBOARD_API_KEY`.

Every authenticated request carries one `X-Mata-Auth` header:

```
X-Mata-Auth: <server_id>|<unix_timestamp>|<nonce>|<hex_hmac_sha256>
```

The signature is:

```
HMAC-SHA256(server_id|timestamp|nonce, api_key)
```

The node checks the server ID, timestamp window, nonce, and signature. The secret itself never crosses the network. The server entry key must match `DASHBOARD_SERVER_ID`, so renaming that key is a breaking configuration change.

Use a different secret for every node. To rotate one, replace the value on the node and dashboard during a short monitoring interruption.

## Transport

Use HTTPS for node URLs and dashboard access. The dashboard rejects plain HTTP node URLs unless the server entry explicitly enables `allow_http`; reserve that switch for local development.

The node API supports an optional IP allowlist. The dashboard also supports an IP allowlist for its own routes. Set `TRUSTED_PROXIES` to exact proxy IPs or CIDR ranges before relying on `X-Forwarded-Proto`; the application ignores that header from other clients. `HSTS_ENABLED` is opt-in and should be enabled only when HTTPS is permanent for the domain and all subdomains.

## Dashboard protections

- `/watch/*` and `/api/*` require an authenticated dashboard session.
- `POST`, `PUT`, `PATCH`, and `DELETE` requests require a CSRF token.
- Login, dashboard, and API routes have separate rate limits.
- `/health` is public for service checks.
- Security headers can be enabled with `SECURITY_HEADERS_ENABLED=ON`.
- Node responses are treated as untrusted input before rendering.

Rate limiting uses file storage by default and can use APCu or Redis. If enabled configuration cannot create a rate limiter, the application fails closed during startup rather than silently running without the configured protection.

## User accounts

Dashboard users are stored as password hashes in `MATA_USERS_FILE`, falling back to `config/users.json` when the variable is unset. The only supported management interface is `bin/mata user …` (or `make mata ARGS="user …"`). User-management actions are written to the CLI-only audit log.

For production, put the users file outside the deployment and web root. Use a private directory owned by the PHP-FPM user with mode `0700`; the application creates the file and lock file with mode `0600`. Docker stores its users file on the `mata_users` named volume.

Each user has a random authentication revision stored in the users file and matched against the session on every request; there is no central session registry. Password or role changes rotate the revision and remove remember-me tokens, revoking existing sessions and tokens on their next request. Deleting an account removes its access on its next request.

See the [user management guide](https://docs.mata.sh/guides/user-management/index.md) for setup commands and the [secure application guide](https://docs.mata.sh/guides/secure-application/index.md) for deployment checks.

## What a compromised node can do

A stolen node secret lets an attacker impersonate that node's monitoring data until the secret is rotated. It does not grant dashboard access or a way to send mail directly. A compromised dashboard remains more serious because it can read monitoring data and create deliveries, so protect dashboard accounts, configuration files, and the database.

## Release integrity

Every release attaches `install.sh`, `sbom.cdx.json`, and `checksums.txt`, built by `.github/workflows/release.yml` on a GitHub-hosted runner from the pushed tag. Two independent, keyless proofs tie each file to that workflow run:

- **Sigstore signatures.** Each file has a `<file>.sigstore.json` bundle from `cosign sign-blob`. The job's OpenID Connect token obtains a short-lived certificate from Fulcio, and the signature is recorded in the Rekor transparency log. There is no signing key to store, leak, or rotate.
- **SLSA provenance.** `actions/attest-build-provenance` records the repository, commit, workflow, and runner that produced the files (SLSA v1.0 Build Level 2). GitHub stores the attestation; `provenance.sigstore.json` on the release carries a copy.

The expected signer identity is the release workflow at the release tag, issued by `https://token.actions.githubusercontent.com`:

```
https://github.com/mata-sh/mata-dashboard/.github/workflows/release.yml@refs/tags/<version>
```

Verify before `curl | sh`

`https://mata.sh/install.sh` redirects to the newest release's `install.sh`. Download the file and its bundle from the release instead, then check both proofs with [cosign](https://docs.sigstore.dev/cosign/system_config/installation/) 3 or newer and the [GitHub CLI](https://cli.github.com/):

```
VERSION=0.11.0
BASE=https://github.com/mata-sh/mata-dashboard/releases/download/$VERSION
curl -fsSLO "$BASE/install.sh" -O "$BASE/install.sh.sigstore.json"

cosign verify-blob install.sh \
    --bundle install.sh.sigstore.json \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com \
    --certificate-identity "https://github.com/mata-sh/mata-dashboard/.github/workflows/release.yml@refs/tags/$VERSION"

gh attestation verify install.sh \
    --repo mata-sh/mata-dashboard \
    --source-ref "refs/tags/$VERSION"

sh install.sh
```

The same commands verify `sbom.cdx.json` and `checksums.txt`. `sha256sum -c checksums.txt` still works on its own, but a checksum only proves a file matches the release page; the signature and provenance prove the release page's files came from this repository's workflow.

The provenance names the commit the workflow built from. The installer clones that same tag, so after an install `git -C mata-dashboard rev-parse HEAD` should equal the commit in the attestation. Tags themselves are not signed; the provenance and the signature are the chain of custody for the published files.
