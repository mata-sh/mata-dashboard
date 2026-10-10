# Project Structure

This guide explains the organization of the MATA Dashboard codebase.

## Directory Overview

```
mata-dashboard/
├── config/             # Configuration files
├── cron/              # Automated data collection scripts
├── docs/              # Documentation (MkDocs)
├── public/            # Web-accessible files (document root)
├── scripts/           # Utility scripts
├── src/               # Application source code
├── temp/              # Cache and temporary files
├── templates/         # Twig templates
├── vendor/            # Composer dependencies
├── node_modules/      # NPM dependencies
├── composer.json      # PHP dependencies
├── package.json       # Node.js dependencies
└── mkdocs.yml        # Documentation configuration
```

## Configuration Directory (`config/`)

Contains all application configuration files. Examples live under `config/example/`, not as `.example` siblings.

### Configuration Files

```
config/
├── config.env                     # Main application settings
├── db.config.env                  # Database config
├── mail.config.env                # Email/SMTP settings
├── servers.json                   # Monitored servers definition
├── users.json                     # User authentication data
├── thresholds.json                # Alarm thresholds
├── ttl.json                       # Cache TTL settings
├── intervals.json                 # Collection/aggregation intervals
├── limits.json                    # Alarm limits
├── menu.json                      # Dashboard menu visibility
├── notification_rules.json        # Notification routing rules
├── probes.json                    # Reachability probe definitions
├── data_retention.json            # Metrics retention policy
├── phinx.php                      # Phinx migration config
├── demo/                          # Demo/mock data (e.g. events.json)
└── example/                       # *.example counterparts of the files above
```

**Security Note:** Never commit actual configuration files (only `config/example/*.example` and `config/demo/`) to version control.

## Source Code Directory (`src/`)

The main application code organized by responsibility.

### Controllers (`src/Controllers/`)

HTTP request handlers that coordinate application flow.

```
src/Controllers/
├── BaseController.php           # Base controller with common functionality
├── DashboardController.php      # Main dashboard and data refresh
├── LoginController.php          # Authentication handling
├── ModalController.php          # Dynamic modal content
├── MetricsController.php        # Metrics API endpoints
└── AuditController.php          # Audit log endpoints
```

**Responsibilities:**

- Handle HTTP requests
- Validate input
- Coordinate services
- Return responses (HTML or JSON)

### Services (`src/Services/`)

Business logic organized by domain.

```
src/Services/
├── Alarm/             # Creation, dispatch, rate limiting, decision policies (Decision/, Dto/)
├── Audit/             # Audit log aggregation and row enrichment
├── Cache/              # Cache store, TTL/type resolution (MetricsCacheService, ModalCacheService)
├── Collect/           # Node data collection & HMAC auth (ServerDataCollector, NodeApiClient, HmacAuthTokenGenerator, Demo/)
├── Cron/              # Cron run orchestration (ServerDataFetchRunner)
├── DataTransformers/  # API/log/chart payload transformation
├── Event/             # Event recording and aggregation (EventManager, EventRecorderService)
├── Identity/          # Server/application id resolution (ServerApplicationIdResolver)
├── Monitor/           # Server/app health monitoring — reachability, errors, node health
├── Navigation/        # Menu building, alarm notification center (AlarmNotificationCenter)
├── Notification/      # System notices such as database outage banners (SystemNotificationManager)
├── Retention/         # Metrics retention cleanup
├── Runtime/           # Dashboard environment assembly (DashboardEnvironmentFactory)
├── ServerMetrics/     # Metrics aggregation/query — see server-metrics-pipeline.md
├── Sync/              # Config-to-DB sync (servers, settings)
├── Validation/        # Request validation
└── View/              # View-model builders for templates
```

**Responsibilities:**

- Implement business logic
- Interact with external APIs
- Process and transform data
- Coordinate between layers

### Models (`src/Models/`)

Data access and database interactions.

```
src/Models/
├── AlarmDeliveryRepository.php    # Alarm delivery data access
├── AlarmRepository.php            # Alarm data access
├── AppFatalState.php              # App fatal-state row model
├── AppFatalStateRepository.php    # App fatal-state data access
├── ConfigSyncRepository.php       # Config sync data access
├── EventRepository.php            # Event data access
└── ServerMetrics/
    ├── ServerMetricsRepository.php    # Metrics data access
    ├── ServerMetricRawUpserter.php    # Upserts server_metrics_raw rows
    ├── ServerMetricHourlyUpserter.php # Upserts server_metrics_hourly rows
    ├── ServerMetricDailyUpserter.php  # Upserts server_metrics_daily rows
    └── Dto/
        ├── ServerMetricRawSnapshot.php
        ├── ServerMetricHourlyAggregate.php
        └── ServerMetricDailyAggregate.php
```

Query building for server metrics lives in `src/Services/ServerMetrics/ServerMetricsQuery.php` (behavior), not here — see [Server Metrics Pipeline](../../architecture/server-metrics-pipeline.md).

**Responsibilities:**

- Database queries
- Data persistence
- Data retrieval
- Transaction management

### View Renderers (`src/Views/`)

HTML rendering components for templates.

```
src/Views/
├── BaseRenderer.php                 # Shared renderer base
├── ServerRenderer.php               # Server cards and containers
├── DatabaseRenderer.php             # Database cards
├── LibrariesRenderer.php            # Library information
├── LogRenderer.php                  # Log display
├── ModalRenderer.php                # Modal content
├── SettingsRenderer.php             # Settings UI
├── MetricsRenderer.php              # Metrics visualization
├── AlarmsRenderer.php               # Alarms table
├── AuditRenderer.php                # Audit log view
├── EventsRenderer.php               # Events table
├── NotificationCenterRenderer.php   # Notification center panel
└── Support/                         # Presentation helpers
    ├── Html.php                     # HTML escaping helpers
    ├── Markdown.php                 # Markdown processing
    └── NumberFormatter.php          # Number formatting
```

**Responsibilities:**

- Prepare data for templates
- Render HTML fragments
- Format output
- Handle presentation logic

### Core (`src/Core/`)

Core application services and infrastructure.

```
src/Core/
├── App.php                  # Application container
├── Auth.php                 # Authentication service
├── Session.php              # Session handling
├── Database.php             # Database wrapper
├── DatabaseInterface.php    # Database contract
├── NullDatabase.php         # Null object pattern
└── ExceptionHandler.php     # Exception handling
```

**Responsibilities:**

- Application bootstrapping
- Dependency injection
- Core services
- Error handling

### Configuration (`src/Config/`)

Configuration loading and management.

```
src/Config/
├── Env.php                       # Environment access
├── EnvConfigLoader.php           # Load .env files to DTOs
├── EnvConfigManager.php          # Env config lifecycle
├── EnvConfigCreator.php          # Scaffold env config
├── EnvConfigFatalException.php   # Fatal config-load error
├── LogConfigurator.php           # Logging setup
├── Dto/                          # Config DTOs (DatabaseConfig, MailConfig, DashboardConfig, ...)
└── Monitoring/                   # .json config loaders (ServersConfigLoader, ThresholdsConfigLoader, ...)
```

**Responsibilities:**

- Load configuration files
- Validate configuration
- Provide configuration DTOs/arrays
- Database schema management

### HTTP Layer (`src/Http/`)

HTTP-related functionality.

```
src/Http/
├── Routing/
│   ├── RouterSetup.php   # Route definitions
│   └── Endpoints.php     # Endpoint constants
├── Security/
│   ├── SecurityHeaders.php            # HTTP security headers
│   ├── CsrfGuard.php                  # CSRF protection
│   ├── RateLimiting/
│   │   ├── RequestRateLimiter.php      # Per-request rate limiting
│   │   └── RequestRateLimiterFactory.php
│   └── RouteParameter.php
└── Responses/
    ├── Response.php          # Response contract
    ├── ResponseFactory.php   # Response construction
    ├── JsonResponse.php      # JSON responses
    ├── HtmlResponse.php      # HTML responses
    └── RedirectResponse.php  # Redirect responses
```

**Responsibilities:**

- Route configuration
- HTTP security
- Response formatting
- Endpoint definitions

### Utilities (`src/Utils/`)

Helper classes and utilities.

```
src/Utils/
├── PathResolver.php            # Path management
├── UsersFilePathResolver.php   # users.json path resolution
├── Tools.php                   # General utilities
├── TypeConverter.php           # Type conversion helpers
└── FileStateStore.php          # File state management
```

**Responsibilities:**

- Common utilities
- Path resolution
- Type conversion
- File operations

Classes with a clear owning domain live with that domain instead of here:
`CronJob` under `Services\Cron`, `AlarmDispatchSpacingCalculator` under
`Services\Alarm\Delivery`, and the presentation helpers under `Views\Support`.

### Other `src/` Directories

```
src/Services/Cache/CacheType.php
src/Services/Monitor/NodeHealthState.php
src/Services/Audit/Severity.php
src/Views/AppAvailabilityStatus.php
src/Tests/        # PHPUnit tests (Unit/, Integration/, Fixtures/, Helpers/) — see composer autoload-dev
```

## Public Directory (`public/`)

Web-accessible files (document root).

```
public/
├── assets/
│   ├── css/       # Compiled Bulma CSS, main.css, fonts.css, tabulator themes
│   ├── js/        # htmx, tabulator, chart.js, hyperscript, tippy/popper, main.js, page scripts
│   ├── icons/     # Feather-style SVG icon set (large; not itemized here)
│   ├── fonts/     # Raleway, Nunito webfonts
│   ├── images/    # favicon.png
│   └── branding/  # Default logos (seed source, do not edit)
├── branding/      # Instance logos (gitignored, seeded from assets/branding/ on first run)
└── index.php      # Application entry point
```

**Important:** The web server document root **must** point to this directory for security.

## Templates Directory (`templates/`)

Twig template files.

```
templates/
├── layouts/
│   └── main.html.twig       # Main layout
├── pages/
│   ├── start.html.twig      # Dashboard/entry page
│   ├── login.html.twig      # Login page
│   └── error.html.twig      # Error page
├── emails/
│   ├── alarm.html.twig      # Alarm email (HTML)
│   └── alarm.txt.twig       # Alarm email (plain text)
└── partials/
    ├── cards/        # server-card, database-card, app-card, server-stats
    ├── containers/   # servers/databases/alarms/events/errors/... section wrappers
    ├── modals/       # server-info, server-metrics, log-files, database-processes, ...
    ├── components/   # logs-table, notification, progress-bar
    ├── structure/    # head, footer, topbar, notification-bell
    ├── audit/        # audit run/summary chips
    ├── errors/       # auth-error, config-error
    └── backup/       # legacy .tpl.php/.html.twig pairs, not in active render path
```

### Template Organization

- **layouts/**: Base page structures
- **pages/**: Full page templates
- **emails/**: Notification email templates
- **partials/**: Reusable components, grouped by kind

## Cron Directory (`cron/`)

Automated data collection scripts.

```
cron/
├── bootstrap.php                        # Cron initialization
├── bootstrap_events.php                 # Event-driven cron initialization
├── servers_data_fetch.php               # Fetch server/app data
├── servers_metrics_collect.php          # Collect raw metrics
├── servers_metrics_aggregate.php        # Aggregate metrics
├── alarms_evaluate_critical.php         # Evaluate critical alarms
├── alarms_evaluate_non_critical.php     # Evaluate non-critical alarms
├── alarms_dispatch.php                  # Dispatch queued alarm notifications
├── retention_cleanup.php                # Purge expired metrics
├── crontab.shared-hosting.template      # Sample crontab for shared hosting
└── logrotate.shared-hosting.conf.template  # Sample logrotate config
```

### Cron Script Naming Convention

- `<domain>_<action>`: e.g. `servers_metrics_collect`, `alarms_evaluate_critical`
- `bootstrap*`: Shared cron initialization, not run directly

## Temporary Directory (`temp/`)

Cache and runtime files (not in version control). Subdirectories are created on demand — exact contents vary by environment. Application logs live in the top-level `logs/` directory, not under `temp/`.

```
temp/
├── api-cache/          # Cached node/API responses
├── cache/              # General application cache
├── templates-cache/    # Twig compiled templates
├── sessions/           # PHP session files
└── runtime/            # Other runtime state
```

## Scripts Directory (`scripts/`)

Utility and maintenance scripts.

```
scripts/
├── bootstrap.php                   # Script bootstrap
├── cron_database_probe.php         # DB reachability probe for cron
├── server_metrics_diagnostic.php   # Ad-hoc metrics diagnostics
├── setup.sh                        # Environment setup
├── database/
│   ├── migrate.php              # Run Phinx migrations
│   ├── database-integrity.php   # Integrity check
│   ├── hash-password.php        # Generate password hashes
│   └── mysql-operation.php      # DB maintenance ops
├── user-management/
│   └── manage-users.php         # Create/update/remove users
├── systemd/                     # Unit/timer files (critical-alarm eval, OPA)
└── docs/
```

## Documentation Directory (`docs/`)

MkDocs documentation source.

```
docs/
├── index.md
├── guides/        # User guides
├── architecture/  # Architecture docs
├── development/   # Development guides
├── examples/      # Worked examples
├── drafts/        # Work-in-progress docs (this file lives here)
├── plans/         # Planning docs
├── styles/        # Doc site styling
└── images/        # Documentation images
```

## File Naming Conventions

### PHP Files

- **Classes**: PascalCase matching class name
  - `ServerDataCollector.php`
  - `DatabaseIntegrityChecker.php`

- **Cron scripts** (`cron/`): snake_case, domain-first
  - `servers_metrics_collect.php`
  - `alarms_evaluate_critical.php`

- **Utility scripts** (`scripts/`): kebab-case
  - `hash-password.php`
  - `manage-users.php`

### Configuration Files

- **Environment files**: `.env` extension
  - `config.env`
  - `mail.config.env`

- **JSON files**: `.json` extension
  - `servers.json`
  - `thresholds.json`

### Templates

- **Twig templates**: `.html.twig` extension
  - `start.html.twig`
  - `server-card.html.twig`

## Autoloading

MATA Dashboard uses PSR-4 autoloading via Composer.

### Namespace Mapping

```json
{
  "autoload": {
    "psr-4": {
      "Mata\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Mata\\Tests\\": "src/tests/"
    }
  }
}
```

**Example usage:**

```php
use Mata\Controllers\DashboardController;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Models\EventRepository;
```

## Import Paths

### Absolute Imports (Recommended)

```php
use Mata\Services\Collect\ServerDataCollector;
use Mata\Database\PdoDatabase;
```

### Relative Imports (Avoid)

Not used in this project. Always use absolute namespaced imports.

## Dependencies

### Composer Dependencies (`composer.json`)

```json
{
  "require": {
    "symfony/mailer": "^7.2",
    "guzzlehttp/guzzle": "^7.0",
    "monolog/monolog": "^3.0",
    "bramus/router": "^1.6",
    "league/commonmark": "^2.8",
    "vlucas/phpdotenv": "^5.6",
    "twig/twig": "^3.0",
    "robmorgan/phinx": "^0.16",
    "mata-sh/rate-limiter": "^0.1"
  },
  "require-dev": {
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^12.4",
    "friendsofphp/php-cs-fixer": "^3.89",
    "cyclonedx/cyclonedx-php-composer": "^6.0"
  }
}
```

### NPM Dependencies (`package.json`)

```json
{
  "dependencies": {
    "bulma": "^1.0.0",
    "htmx.org": "^1.9.12",
    "sass": "^1.72.0"
  }
}
```

## Environment Files

Two tiers: root `.env` for Docker/infra settings, `config/config.env` for application settings.

### Development

```bash
.env                 # Docker Compose infra settings (gitignored; see .env.example)
config/config.env    # Application settings (gitignored; see config/example/config.env.example)
```

### Production

```bash
config/config.env         # Application settings
config/mail.config.env    # SMTP configuration
config/db.config.env      # Database configuration
config/servers.json       # Monitored servers
```

## Build Artifacts

Generated dependencies and runtime files are not in version control.
The compiled Bulma CSS is tracked:

```
vendor/                                 # Composer packages
node_modules/                           # NPM packages
temp/                                   # Cache and runtime files
public/assets/css/bulma.css             # Compiled from src/main.scss (npm run build-bulma)
```

## Version Control

### .gitignore Structure

```
# Dependencies
vendor/
node_modules/

# Runtime
temp/
logs/*
!logs/.gitkeep

# Config (JSON is instance data; examples live in config/example/)
config/*.json
config/demo/*.json

# Build artifacts / local files
*.env
*.log
package-lock.json
```

## Best Practices

### Adding New Files

1. **Choose correct directory** based on responsibility
2. **Follow naming conventions** for the file type
3. **Use proper namespace** matching directory structure
4. **Add to autoloader** if needed (usually automatic)
5. **Document in CLAUDE.md** if it's a significant component

### Organizing Code

- **Single Responsibility**: One class = one responsibility
- **Separation of Concerns**: Controllers vs. Services vs. Models
- **Dependency Injection**: Inject dependencies via constructor
- **Interface-based**: Program to interfaces, not implementations

### Template Organization

- **Reusable partials**: Extract common components
- **Consistent naming**: Use descriptive, kebab-case names
- **Logical grouping**: Group related templates in subdirectories

## Next Steps

- [Architecture Overview](../../architecture/overview.md) - Detailed component documentation
- [Code Quality](code-quality.md) - Code standards and tools
- [Contributing](contributing.md) - Contribution guidelines
