# Architecture Overview

## Component Layers

### 1. Presentation Layer

**Web Interface**

- **Bulma CSS**: Modern, responsive CSS framework (pre-compiled)
- **HTMX**: Dynamic interactions without JavaScript frameworks
- **Twig Templates**: Server-side rendering with caching

**Responsibilities:**

- Display server status and metrics
- Interactive dashboard updates
- User authentication interface
- Alert configuration UI

### 2. Application Layer

**Controllers** (`src/Controllers/`)

Handle HTTP requests and coordinate responses:

- `DashboardController`: Main dashboard, data refresh
- `LoginController`: Authentication handling
- `ModalController`: Dynamic modal content

**Services** (`src/Services/`)

Business logic and core functionality:

- `ServerDataCollector`: API communication with nodes
- `EventManager`: Alert generation and processing
- `NotificationManager`: Email notification handling
- `CacheManager`: Cache management with TTL
- `ServerMonitor`: Metric evaluation and thresholds
- `LogAnalyzer`: Log file analysis

**View Renderers** (`src/View/`)

Specialized HTML rendering:

- `ServerRenderer`: Server card rendering
- `DatabaseRenderer`: Database card rendering
- `LibrariesRenderer`: Library rendering
- `CronjobRenderer`: Cron job status rendering

### 3. Core Layer

**Core Services** (`src/Core/`)

- `App`: Application container, dependency injection
- `Auth`: Session-based authentication
- `Database`: MySQL connection wrapper

**Configuration** (`src/Config/`)

- `EnvConfigLoader`: Environment configuration (DTOs from .env)
- `MonitoringSettingsLoader`: Monitoring settings (arrays from .json)
- `DatabaseSetup`: Database initialization

**HTTP** (`src/Http/`)

- `Endpoints`: API endpoint definitions
- `RouterSetup`: Bramus Router configuration
- `SecurityHeaders`: HTTP security headers

### 4. Data Layer

**Models** (`src/Models/`)

- `EventRecorder`: Event logging to database
- `Settings`: Application settings management
- `Users`: User data management

**Database**

- MySQL/MariaDB for persistent storage
- Custom Database wrapper (not ORM)
- Prepared statements for security

### 5. Integration Layer

**Nodes (External)**

- Lightweight API endpoints on monitored servers
- Read-only operations
- Encrypted communication with dashboard

## Data Flow

### Typical Request Flow

1. **User Request** → Web browser sends HTTP request
2. **Router** → Bramus Router matches route to controller
3. **Controller** → Handles request, coordinates services
4. **Service Layer** → Business logic execution
5. **Cache Check** → Check if data is cached
6. **API Call** (if needed) → Fetch from remote node
7. **Data Processing** → Evaluate thresholds, analyze logs
8. **Database** (if needed) → Store events, retrieve history
9. **View Rendering** → Twig template with data
10. **HTTP Response** → HTML sent to browser

### Monitoring Data Collection

```
Cron Job (every 5 min)
    ↓
servers_metrics_collect.php
    ↓
ServerDataCollector Service
    ↓
API Requests to Nodes
    ↓
Store Raw Metrics → MySQL
    ↓
Evaluate Thresholds
    ↓
Generate Events (if needed)
    ↓
Trigger Alerts (if threshold exceeded)
    ↓
Send Email Notifications
```

### Data Aggregation Flow

```
Cron Job (hourly)
    ↓
servers_metrics_aggregate.php
    ↓
Query Raw Metrics (5-min data)
    ↓
Calculate Hourly Averages
    ↓
Store Aggregated Data → MySQL
    ↓
Cleanup Old Raw Data (> 7 days)
```

## Communication Patterns

### Dashboard ↔ Node Communication

**Request:**

```http
GET /mata-node/api/server-data HTTP/1.1
Host: monitored-server.com
X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex
Accept: application/json
```

**Response:**

```json
{
  "cpu_usage": 45.2,
  "memory_used": 2147483648,
  "memory_total": 8589934592,
  "disk_usage": 128849018880,
  "disk_total": 536870912000,
  "process_count": 156,
  "file_count": 485291
}
```

### API Endpoints

**Dashboard provides:**

- `/` - Main dashboard
- `/login` - Authentication
- `/logout` - Session termination
- `/api/refresh` - Force data refresh
- `/api/server/{id}` - Server details
- `/api/events` - Event log

**Nodes provide:**

- `/api/ping` - Health check
- `/api/server-data` - Server metrics
- `/api/apps` - Application list
- `/api/app-data/{id}` - Application metrics
- `/api/processes` - Process information


## Caching Strategy

### Multi-Level Cache

1. **File-based Cache** (`temp/api-cache/`)
   - Structured directory hierarchy
   - JSON files with metadata
   - TTL-based expiration

2. **Template Cache** (`temp/templates-cache/`)
   - Twig compiled templates
   - Automatic invalidation on changes

### Cache TTL Configuration

Different data types have different cache lifetimes:

- **Server Data**: 10 seconds (near real-time)
- **Application List**: 60 seconds (relatively static)
- **Error Logs**: 60 seconds (important changes)
- **Process Info**: 30 seconds (moderate changes)

### Cache Invalidation

- **Time-based**: TTL expiration
- **Manual**: Force refresh button
- **Graceful degradation**: Use stale cache on API failure

## Database Schema

### Events Table

Stores all monitoring events:

```sql
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(50),
    event_type VARCHAR(50),
    severity VARCHAR(20),
    message TEXT,
    timestamp DATETIME,
    INDEX idx_server_timestamp (server_id, timestamp)
);
```

### Alarms Table

Tracks sent notifications:

```sql
CREATE TABLE alarms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT,
    sent_at DATETIME,
    recipient VARCHAR(255),
    status VARCHAR(20),
    INDEX idx_sent_at (sent_at)
);
```

### Server Metrics Tables

```sql
-- Raw metrics (5-minute intervals)
CREATE TABLE server_metrics_raw (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(50),
    timestamp DATETIME,
    cpu_usage DECIMAL(5,2),
    memory_used BIGINT,
    disk_usage BIGINT,
    -- ... other metrics
    INDEX idx_server_timestamp (server_id, timestamp)
);

-- Hourly aggregates
CREATE TABLE server_metrics_hourly (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(50),
    hour_timestamp DATETIME,
    avg_cpu_usage DECIMAL(5,2),
    -- ... other aggregated metrics
    INDEX idx_server_hour (server_id, hour_timestamp)
);

-- Daily aggregates
CREATE TABLE server_metrics_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(50),
    date DATE,
    avg_cpu_usage DECIMAL(5,2),
    -- ... other aggregated metrics
    INDEX idx_server_date (server_id, date)
);
```

