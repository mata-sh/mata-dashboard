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

Contains all application configuration files.

### Configuration Files

```
config/
├── config.env                 # Main application settings
├── config.env.example         # Example configuration
├── mail.config.env           # Email/SMTP settings
├── mail.config.env.example   # Example email config
├── servers.json              # Monitored servers definition
├── servers.json.example      # Example servers config
├── users.json                # User authentication data
├── users.json.example        # Example users
├── thresholds.json           # Alert thresholds (optional)
├── ttl.json                  # Cache TTL settings (optional)
├── data_retention.json       # Metrics retention (optional)
└── mysql.mata.env           # Database config (optional)
```

**Security Note:** Never commit actual configuration files (without `.example` suffix) to version control.

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
└── MetricsController.php        # Metrics API endpoints
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
├── Collection/
│   ├── ServerDataCollector.php      # Real node communication
│   └── MockServerDataCollector.php  # Mock for testing
├── Monitoring/
│   ├── ServerMonitor.php            # Server metrics evaluation
│   ├── AppMonitor.php               # Application monitoring
│   └── ServerMetricsHealthMonitor.php  # Metrics health checks
├── Foundation/
│   ├── CacheManager.php             # Cache management
│   ├── EventManager.php             # Alert/event management
│   ├── NotificationManager.php      # Email notifications
│   ├── LogAnalyzer.php              # Log file analysis
│   ├── ApiClient.php                # HTTP API client
│   ├── ConfigDatabaseSync.php       # Config to DB sync
│   └── IdResolver.php               # ID resolution
├── Cache/
│   ├── MetricsCacheService.php      # Metrics caching
│   ├── ModalCacheService.php        # Modal content caching
│   └── CacheTypeResolver.php        # Cache type resolution
├── View/
│   ├── ServerViewBuilder.php        # Server view data
│   ├── MetricsViewBuilder.php       # Metrics view data
│   └── ChartViewBuilder.php         # Chart data preparation
└── Validation/
    └── MetricsRequestValidator.php  # Request validation
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
├── EventRecorder.php            # Event logging
├── Settings.php                 # Application settings
├── Users.php                    # User management
├── ConfigSyncRepository.php     # Config sync data access
└── ServerMetrics/
    ├── ServerMetricsRepository.php   # Metrics data access
    ├── ServerMetricsQuery.php        # Query builder
    ├── RawMetric.php                 # Raw metric DTO
    ├── HourlyMetric.php             # Hourly aggregate DTO
    └── DailyMetric.php              # Daily aggregate DTO
```

**Responsibilities:**

- Database queries
- Data persistence
- Data retrieval
- Transaction management

### View Renderers (`src/View/`)

HTML rendering components for templates.

```
src/View/
├── ServerRenderer.php       # Server cards and containers
├── DatabaseRenderer.php     # Database cards
├── LibrariesRenderer.php    # Library information
├── CronjobRenderer.php      # Cron job status
├── LogRenderer.php          # Log display
├── ModalRenderer.php        # Modal content
├── SettingsRenderer.php     # Settings UI
└── MetricsRenderer.php      # Metrics visualization
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
├── EnvConfigLoader.php           # Load .env files to DTOs
├── MonitoringSettingsLoader.php  # Load .json files to arrays
├── DatabaseSetup.php             # Database initialization
└── DatabaseIntegrityChecker.php  # Database health checks
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
├── RouterSetup.php      # Route definitions
├── Endpoints.php        # Endpoint constants
├── SecurityHeaders.php  # HTTP security headers
└── Response/
    ├── JsonResponse.php     # JSON responses
    └── HtmlResponse.php     # HTML responses
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
├── PathResolver.php      # Path management
├── Cronjob.php          # Base class for cron scripts
├── LogHandler.php       # Logging utilities
├── Markdown.php         # Markdown processing
├── Tools.php            # General utilities
├── TypeConverter.php    # Type conversion helpers
└── FileStateStore.php   # File state management
```

**Responsibilities:**

- Common utilities
- Path resolution
- Logging
- Type conversion
- File operations

## Public Directory (`public/`)

Web-accessible files (document root).

```
public/
├── assets/
│   ├── css/
│   │   ├── bulma.css           # Compiled Bulma CSS
│   │   └── bulma.purged.min.css  # Optimized CSS
│   ├── js/
│   │   └── htmx.min.js         # HTMX library
│   └── images/
│       └── logo.png
├── index.php              # Application entry point
└── .htaccess             # Apache configuration
```

**Important:** The web server document root **must** point to this directory for security.

## Templates Directory (`templates/`)

Twig template files.

```
templates/
├── layouts/
│   ├── main.html.twig          # Main layout
│   └── auth.html.twig          # Authentication layout
├── pages/
│   ├── dashboard.html.twig     # Dashboard page
│   ├── login.html.twig         # Login page
│   ├── servers.html.twig       # Servers page
│   ├── events.html.twig        # Events page
│   └── metrics.html.twig       # Metrics page
└── partials/
    ├── server-card.html.twig   # Server card component
    ├── database-card.html.twig # Database card
    ├── navigation.html.twig    # Navigation menu
    ├── modal.html.twig         # Modal template
    └── charts/
        ├── line-chart.html.twig
        └── bar-chart.html.twig
```

### Template Organization

- **layouts/**: Base page structures
- **pages/**: Full page templates
- **partials/**: Reusable components

## Cron Directory (`cron/`)

Automated data collection scripts.

```
cron/
├── bootstrap.php                  # Cron initialization
├── servers_metrics_collect.php     # Collect raw metrics (every 5 min)
├── servers_metrics_aggregate.php   # Aggregate metrics (hourly)
└── README.md                      # Cron documentation
```

### Cron Script Naming Convention

- `collect_*`: Gather raw data from external sources
- `aggregate_*`: Process/summarize existing data
- `monitor_*`: Health checks and status validation

## Temporary Directory (`temp/`)

Cache and temporary files (not in version control).

```
temp/
├── api_cache/            # API response cache
│   └── [host]/
│       └── [method]/
│           └── [path]/
│               └── cache.json
├── templates_cache/      # Twig compiled templates
├── logs/                 # Application logs
│   ├── application.log
│   ├── cron-collect.log
│   └── cron-aggregate.log
└── uploads/             # Temporary uploads (if any)
```

## Scripts Directory (`scripts/`)

Utility and maintenance scripts.

```
scripts/
├── hash-password.php              # Generate password hashes
├── setup-database.php             # Database initialization
└── ai-docs/                      # AI documentation scripts
```

## Documentation Directory (`docs/`)

MkDocs documentation source.

```
docs/
├── index.md
├── guides/              # User guides
├── architecture/        # Architecture docs
├── reference/          # Reference documentation
├── api/                # API documentation
├── development/        # Development guides
├── tutorials/          # Step-by-step tutorials
└── images/             # Documentation images
```

## File Naming Conventions

### PHP Files

- **Classes**: PascalCase matching class name
  - `ServerDataCollector.php`
  - `DatabaseSetup.php`

- **Scripts**: snake_case with action-first naming
  - `servers_metrics_collect.php`
  - `hash_password.php`

### Configuration Files

- **Environment files**: `.env` extension
  - `config.env`
  - `mail.config.env`

- **JSON files**: `.json` extension
  - `servers.json`
  - `thresholds.json`

### Templates

- **Twig templates**: `.html.twig` extension
  - `dashboard.html.twig`
  - `server-card.html.twig`

## Autoloading

MATA Dashboard uses PSR-4 autoloading via Composer.

### Namespace Mapping

```json
{
  "autoload": {
    "psr-4": {
      "IDS\\": "src/"
    }
  }
}
```

**Example usage:**

```php
use IDS\Controllers\DashboardController;
use IDS\Services\Foundation\CacheManager;
use IDS\Models\EventRecorder;
```

## Import Paths

### Absolute Imports (Recommended)

```php
use IDS\Services\Collection\ServerDataCollector;
use IDS\Core\Database;
```

### Relative Imports (Avoid)

Not used in this project. Always use absolute namespaced imports.

## Dependencies

### Composer Dependencies (`composer.json`)

```json
{
  "require": {
    "bramus/router": "dev-master",
    "twig/twig": "^3.0",
    "ids/helper": "dev-main",
    "ids/security": "dev-main",
    "guzzlehttp/guzzle": "^7.0",
    "monolog/monolog": "^3.0",
    "symfony/mailer": "^6.0"
  },
  "require-dev": {
    "phpstan/phpstan": "^1.10",
    "squizlabs/php_codesniffer": "^3.7"
  }
}
```

### NPM Dependencies (`package.json`)

```json
{
  "dependencies": {
    "bulma": "^0.9.4",
    "htmx.org": "^1.9.0"
  },
  "devDependencies": {
    "postcss": "^8.4.0",
    "postcss-cli": "^10.0.0",
    "sass": "^1.60.0"
  }
}
```

## Environment Files

### Development

```bash
.env.local          # Local development overrides
config.env          # Main config (gitignored)
```

### Production

```bash
config.env          # Production settings
mail.config.env     # SMTP configuration
servers.json        # Monitored servers
```

## Build Artifacts

Generated files not in version control:

```
vendor/             # Composer packages
node_modules/       # NPM packages
temp/               # Cache and logs
public/assets/css/bulma.css     # Compiled CSS
public/assets/js/htmx.min.js    # Copied from node_modules
```

## Version Control

### .gitignore Structure

```
# Configuration
config/*.env
config/*.json
!config/*.example

# Dependencies
vendor/
node_modules/

# Temporary files
temp/

# Build artifacts
public/assets/css/bulma.css
public/assets/css/bulma.purged.min.css
public/assets/js/htmx.min.js
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

- [Components](../architecture/components.md) - Detailed component documentation
- [Code Quality](code-quality.md) - Code standards and tools
- [Contributing](contributing.md) - Contribution guidelines
