# Security advisories

MATA polls security advisory sources and notifies you once about each new advisory that matters
to you. Supported sources:

- **RSS/Atom feeds** such as CERT alerts and vendor feeds (`feed`)
- **GitHub** repository security advisories (`github`)
- **IMAP mailboxes** subscribed to security mailing lists (`imap`)
- **[OSV.dev](https://osv.dev)** vulnerabilities of watched packages (`osv`)
- **[CSAF](https://www.csaf.io)** documents from publishing providers (`csaf`)

The **Advisories** page lists the sources with their poll state, every stored advisory with its
raw payload, and downloads a summary report as PDF, Markdown, HTML or JSON. `bin/mata` fetches
and reports from the command line, and a digest mails the report on a schedule.

!!! info "Prerequisites"
    - `cron/advisories_poll.php` and `cron/advisories_digest.php` run. The Docker image installs
      both; on shared hosting, copy them from `cron/crontab.shared-hosting.template`.
    - Alarms and digests are mailed through `config/mail.config.env`.

## Set up

1. Copy the example and adjust the sources:

    ```bash
    cp config.example/advisories.json.example config/advisories.json
    make mata ARGS="config validate advisories.json"
    ```

2. Try a source and its filters without storing anything:

    ```bash
    make mata ARGS="advisory:fetch cert-bund --preview"
    ```

3. Take the baseline. The first successful poll of each source stores what it already lists
   **without notifying**, so setup does not mail the backlog:

    ```bash
    make mata ARGS="advisory:fetch"
    make mata ARGS="advisory:sources"
    ```

From then on the poll cron fetches each source once its `poll_interval_minutes` passed, and every
new relevant advisory is notified exactly once, also across restarts.

## advisories.json

```json
{
    "poll_interval_minutes": 60,
    "failure_warning_after": 3,
    "stale_after_minutes": 360,
    "digest": { "enabled": true, "every_days": 7, "recipients": [], "relevant_only": true, "attach_pdf": true },
    "sources": {
        "cert-bund": {
            "name": "CERT-Bund WID",
            "description": "BSI security advisories",
            "type": "feed",
            "url": "https://wid.cert-bund.de/content/public/securityAdvisory/rss",
            "filters": { "keywords": ["PHP", "Apache", "MariaDB"], "min_severity": "medium" }
        },
        "php-src": { "name": "PHP", "type": "github", "repository": "php/php-src" }
    }
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `poll_interval_minutes` | `60` | Default fetch interval per source, 5 to 10080. |
| `failure_warning_after` | `3` | Failed polls in a row before a 🟡 `advisories.source_failing` warning. |
| `stale_after_minutes` | `360` | A poll cron silent this long raises a 🟡 `advisories.poll_stalled` warning. A source not polled for its interval plus this long shows as **Stale**, and `advisory:sources` exits with a warning. |
| `digest` | off | The periodic report mail, see [Digest](#digest). |
| `sources` | — | Sources keyed by a stable ID (lowercase letters, digits, `-`, `_`). Renaming a key starts a new baseline. |

Every source takes `name`, `description`, `type`, `enabled` (default `true`),
`poll_interval_minutes`, `filters` and `notify`:

| `type` | Required keys | Notes |
| --- | --- | --- |
| `feed` | `url` (https) | RSS 2.0, RSS 1.0 and Atom. Severity comes from the item's categories, then its title; English and German words (`kritisch`, `hoch`, `mittel`, `niedrig`) are understood. |
| `github` | `repository` (`owner/name`) | Published repository security advisories with severity, CVSS, CVEs and affected version ranges. Optional `token_env` names a variable with a GitHub token. |
| `imap` | `host`, `username_env`, `password_env` | One advisory per message in `folder` (default `INBOX`). `encryption` is `ssl` (port 993, default) or `starttls` (143). `addresses` keeps only messages whose `From`, `Sender`, `To`, `List-Id` or `List-Post` contains one of them. |
| `osv` | `ecosystem`, `package` | Vulnerabilities of one package from OSV.dev, which merges GitHub's reviewed advisories, PyPI, Go, RustSec, Debian, Ubuntu and more. `ecosystem` is spelled as OSV spells it (`npm`, `Packagist`, `PyPI`, `Debian:12`); a typo fails the poll with `invalid ecosystem`. A vulnerability that two databases list (PYSEC and GHSA) is kept once. OSV carries no CVSS scores, so the CVSS column stays empty. |
| `csaf` | `url` (https) | CSAF 2.0 documents of one provider, with CVSS scores, CVEs and affected product versions. `url` is the provider's `changes.csv` or one of its ROLIE feeds, both listed in its `/.well-known/csaf/provider-metadata.json`. Prefer `changes.csv`: BSI's ROLIE feed is 47 MB, its `changes.csv` under 1 MB. |

!!! warning "Credentials stay in the environment"
    `token_env`, `username_env` and `password_env` name variables in `config/config.env`; the JSON
    file never holds secrets, and messages only name the variable. IMAP never connects without
    TLS, verifies the server certificate, and only reads: messages are fetched with
    `BODY.PEEK` and not flagged, moved or deleted.

!!! info "Rate limits"
    GitHub allows 60 unauthenticated API requests per hour and IP. One request per GitHub source
    and poll stays well below that; set `token_env` when many repositories share an IP.

### Filters and notifications

`filters.keywords` match whole words, case-insensitively, in the title, summary and affected
products: `PHP` matches "PHP 8.4", not "PHPMailer". An empty list matches everything.
`filters.min_severity` is `low` (default), `medium`, `high` or `critical`. Advisories without a
rating, such as most mailing-list posts, always pass the severity filter.

Every advisory is stored. The filters and the source's `notify` decide what else happens:

| Advisory | Page | Reports and digest | Event |
| --- | --- | --- | --- |
| Fails the filters | With **relevant only** off | Only with scope **all**, `--all` or digest `relevant_only: false` | — |
| Relevant, `notify: true` (default) | ✓ | ✓, with the matched keywords | `advisories.matched` through the alarm pipeline |
| Relevant, `notify: false` | ✓ | ✓, with the matched keywords | — |

Both decision drivers (OPA & PHP native) allow every level to become events, since the
source filters already chose. `notification_rules.json` can turn the alarm off or pick other
channels under `event_types` → `advisories.matched`.

!!! info "Alarming for some sources only"
    Narrow a source with `filters`, or set `"notify": false` to only list it. To keep events but
    alarm per source, add an OPA rule matching `input.event.context.additional_data.source_name`;
    the built-in PHP-driver has no per-source setting.

Event levels follow the advisory severity: critical → 🔴 `critical`, high → 🟡 `warning`,
medium, low and unrated → 🔵 `info`.

!!! warning "Silence is not the same as no news"
    A source that fails a certain amount of times (`failure_warning_after`) in a row records a warning, and the first
    success after that records `advisories.source_recovered`. `cron/alarms_evaluate_non_critical.php`
    checks the poll cron's heartbeat, so a stopped cron also warns. Both warnings alarm like
    other monitored warnings.

## Review advisories

Click an advisory on the **Advisories** page to see its fields, the raw feed item, API object or
mail, and to mark it **Reviewed** or **Not relevant** with a short note. Reports leave out
advisories reviewed as not relevant unless asked.

To review many at once, filter the table, pick a status under **Set review…** and click
**Apply to N shown**. This applies to every advisory the filters show, on all pages,
and takes an optional shared note. **New** resets reviews and clears their notes.

!!! info "Existing reviews are kept"
    Advisories that already have the chosen status keep their reviewer, time and note.

## Reports

The page downloads the report for the last 7, 30 or 90 days. On the command line:

```bash
make mata ARGS="advisory:report --days 30 --format pdf,md,json -o reports/"
make mata ARGS="advisory:report --source cert-bund --min-severity high"
make mata ARGS="advisory:report --mail --to security@example.com"
```

A report counts advisories by severity and source, lists the most affected products across
versions, highlights the critical and high ones, and groups every advisory by severity. PDF and
HTML use the dashboard and alarm-mail styling, Markdown suits tickets and wikis, and JSON carries
`schema_version` for scripts. Without `--output`, a single Markdown, HTML or JSON report is printed.

## Digest

With `digest.enabled`, `cron/advisories_digest.php` runs daily and mails the report of the last
`every_days` once that many days passed since the previous digest: HTML body, Markdown text part
and, with `attach_pdf`, the PDF attached. `recipients` defaults to `MAIL_ALARM_RECIPIENTS`;
`relevant_only: false` includes filtered-out advisories.

## Notes

- Stored advisories are the memory that prevents repeated notifications, so retention never
  deletes them.
- The first poll stores up to the 100 newest GitHub advisories and CSAF documents, all current
  feed items and OSV vulnerabilities, and mail from the last 14 days. Later CSAF polls download
  only documents changed since, up to 100 per poll; a larger backlog continues on the next one.
  Documents a provider lists but will not serve are skipped with an
  `advisories.csaf_document_skipped` warning in `logs/app.log`.
- The `demo` environment polls the real sources like any other. Only the cron fetches, so
  dashboard visitors cannot trigger requests to the sources.
