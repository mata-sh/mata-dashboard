<?php

declare(strict_types=1);

namespace Mata\Services\Collect\Demo;

/**
 * Static demo data for application generation (templates, server presets,
 * session/log tuning tables, library and log-error fixtures). Values moved
 * verbatim from MockServerDataCollector; commonLibraries() embeds mt_rand()
 * and intentionally recomputes per call.
 */
final class DemoAppCatalog
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function appTemplates(): array
    {
        return [
            'ecommerce' => [
                'title' => 'E-Commerce Platform',
                'description' => 'Customer-facing commerce and checkout platform.',
                'path' => 'ecommerce',
                'environment' => 'prod',
                'category' => 'commerce',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/payment.log', 'logs/queue.log'],
            ],
            'admin-panel' => [
                'title' => 'Admin Dashboard',
                'description' => 'Internal dashboard for administration and operations.',
                'path' => 'admin',
                'environment' => 'prod',
                'category' => 'internal',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/audit.log'],
            ],
            'api-gateway' => [
                'title' => 'API Gateway',
                'description' => 'Routes and authenticates requests for backend services.',
                'path' => 'api',
                'environment' => 'prod',
                'category' => 'api',
                'suitable_environments' => ['prod', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/request.log'],
            ],
            'user-portal' => [
                'title' => 'Customer Portal',
                'description' => 'Self-service portal for customers and account management.',
                'path' => 'portal',
                'environment' => 'test',
                'category' => 'customer',
                'suitable_environments' => ['prod', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/ui.log'],
            ],
            'inventory-system' => [
                'title' => 'Inventory Management',
                'description' => 'Tracks inventory, stock levels, and fulfillment operations.',
                'path' => 'inventory',
                'environment' => 'prod',
                'category' => 'operations',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/sync.log', 'logs/report.log'],
            ],
            'analytics-dashboard' => [
                'title' => 'Analytics Dashboard',
                'description' => 'Explores product and operational analytics.',
                'path' => 'analytics',
                'environment' => 'dev',
                'category' => 'analytics',
                'suitable_environments' => ['prod', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/query.log', 'logs/etl.log'],
            ],
            'payment-processor' => [
                'title' => 'Payment Gateway',
                'description' => 'Processes payments and settlement workflows.',
                'path' => 'payments',
                'environment' => 'prod',
                'category' => 'commerce',
                'suitable_environments' => ['prod'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/security.log', 'logs/settlement.log'],
            ],
            'notification-service' => [
                'title' => 'Notification Service',
                'description' => 'Delivers email, SMS, and application notifications.',
                'path' => 'notifications',
                'environment' => 'test',
                'category' => 'communications',
                'suitable_environments' => ['prod', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/queue.log', 'logs/delivery.log'],
            ],
            'warehouse-system' => [
                'title' => 'Warenwirtschaft Backend',
                'description' => 'Coordinates warehouse and enterprise resource operations.',
                'path' => 'warenwirtschaft',
                'environment' => 'prod',
                'category' => 'operations',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/integration.log', 'logs/erp.log'],
            ],
            'crm' => [
                'title' => 'CRM Platform',
                'description' => 'Manages customer relationships and account activity.',
                'path' => 'crm',
                'environment' => 'prod',
                'category' => 'customer',
                'suitable_environments' => ['prod', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/integration.log'],
            ],
            'search-service' => [
                'title' => 'Search Service',
                'description' => 'Indexes and serves application search queries.',
                'path' => 'search',
                'environment' => 'prod',
                'category' => 'api',
                'suitable_environments' => ['prod', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/indexer.log', 'logs/query.log'],
            ],
            'load-balancer-node' => [
                'title' => 'Load Balancer Node',
                'description' => 'Balances traffic across application instances.',
                'path' => 'load-balancer',
                'environment' => 'prod',
                'category' => 'infrastructure',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/health.log'],
            ],
            'shared-storage-cluster' => [
                'title' => 'Shared Storage Cluster',
                'description' => 'Provides shared storage for distributed services.',
                'path' => 'shared-storage',
                'environment' => 'prod',
                'category' => 'infrastructure',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/replication.log', 'logs/audit.log'],
            ],
            'ci-runner' => [
                'title' => 'CI Runner',
                'description' => 'Runs continuous integration and delivery pipelines.',
                'path' => 'ci-runner',
                'environment' => 'dev',
                'category' => 'devtools',
                'suitable_environments' => ['dev', 'test'],
                'log_templates' => ['logs/error.log', 'logs/pipeline.log', 'logs/worker.log'],
            ],
            'feature-flags' => [
                'title' => 'Feature Flag Service',
                'description' => 'Controls feature rollout and runtime configuration.',
                'path' => 'feature-flags',
                'environment' => 'dev',
                'category' => 'devtools',
                'suitable_environments' => ['dev', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/audit.log', 'logs/toggle.log'],
            ],
            'email-gateway' => [
                'title' => 'Email Gateway',
                'description' => 'Relays outbound email and delivery events.',
                'path' => 'email',
                'environment' => 'prod',
                'category' => 'communications',
                'suitable_environments' => ['prod', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/delivery.log', 'logs/relay.log'],
            ],
            'reporting-service' => [
                'title' => 'Reporting Service',
                'description' => 'Builds scheduled reports and data exports.',
                'path' => 'reporting',
                'environment' => 'stg',
                'category' => 'analytics',
                'suitable_environments' => ['prod', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/report.log', 'logs/export.log'],
            ],
            'qa-automation' => [
                'title' => 'QA Automation Suite',
                'description' => 'Executes automated quality and regression checks.',
                'path' => 'qa-suite',
                'environment' => 'test',
                'category' => 'testing',
                'suitable_environments' => ['test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/run.log', 'logs/assertion.log'],
            ],
            'beta-portal' => [
                'title' => 'Beta Customer Portal',
                'description' => 'Preview customer portal for upcoming features.',
                'path' => 'beta-portal',
                'environment' => 'test',
                'category' => 'customer',
                'suitable_environments' => ['test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/feature.log', 'logs/access.log'],
            ],
            'catalog-service' => [
                'title' => 'Catalog Service',
                'description' => 'Maintains product catalog data and availability.',
                'path' => 'catalog',
                'environment' => 'prod',
                'category' => 'commerce',
                'suitable_environments' => ['prod', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/ingestion.log', 'logs/search.log'],
            ],
            'message-broker' => [
                'title' => 'Message Broker',
                'description' => 'Moves asynchronous messages between services.',
                'path' => 'broker',
                'environment' => 'prod',
                'category' => 'infrastructure',
                'suitable_environments' => ['prod', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/queue.log', 'logs/cluster.log'],
            ],
            'workflow-engine' => [
                'title' => 'Workflow Engine',
                'description' => 'Orchestrates multi-step business workflows.',
                'path' => 'workflow',
                'environment' => 'stg',
                'category' => 'internal',
                'suitable_environments' => ['dev', 'test', 'stg', 'prod'],
                'log_templates' => ['logs/error.log', 'logs/execution.log', 'logs/audit.log'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function serverPresets(): array
    {
        return [
            'production_prod' => [
                'environment_weights' => ['prod' => 1.0],
                'app_count_range' => [4, 7],
                'preferred_categories' => [
                    'commerce' => 3.4,
                    'customer' => 2.4,
                    'operations' => 2.1,
                    'analytics' => 1.5,
                    'communications' => 1.6,
                    'api' => 1.8,
                    'internal' => 1.2,
                    'infrastructure' => 0.8,
                    'devtools' => 0.4,
                    'testing' => 0.4,
                ],
                'app_weight_overrides' => [
                    'ecommerce' => 6,
                    'payment-processor' => 5,
                    'inventory-system' => 4,
                    'warehouse-system' => 4,
                    'catalog-service' => 4,
                    'admin-panel' => 3,
                    'api-gateway' => 3,
                    'crm' => 2,
                    'analytics-dashboard' => 2,
                    'notification-service' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.9, 'range' => [0, 0]],
                        ['prob' => 0.08, 'range' => [1, 1]],
                        ['prob' => 0.02, 'range' => [2, 4]],
                    ],
                    'warnings' => [
                        ['prob' => 0.82, 'range' => [0, 1]],
                        ['prob' => 0.14, 'range' => [2, 4]],
                        ['prob' => 0.04, 'range' => [5, 10]],
                    ],
                ],
                'status_total_base' => [250, 550],
                'status_total_weight' => ['fatal' => 25, 'warnings' => 6],
                'session_multiplier' => 1.15,
                'log_files' => ['min' => 2, 'max' => 5],
                'log_size_multiplier' => 1.0,
                'prod_environment_bias' => [
                    'clean_probability' => 0.86,
                    'max_fatal' => 2,
                    'max_warnings' => 5,
                    'suppress_fatal_probability' => 0.6,
                    'suppress_warning_probability' => 0.48,
                ],
                'outlier_profile' => [
                    'fatal' => [15, 45],
                    'warnings' => [30, 140],
                    'distribution' => ['warnings' => 0.7, 'fatal' => 0.2, 'both' => 0.1],
                ],
            ],
            'test_only' => [
                'environment_weights' => ['test' => 1.0],
                'app_count_range' => [3, 6],
                'preferred_categories' => [
                    'testing' => 3.6,
                    'devtools' => 2.8,
                    'communications' => 1.4,
                    'api' => 1.5,
                    'customer' => 1.2,
                    'analytics' => 1.1,
                    'infrastructure' => 0.6,
                    'internal' => 0.8,
                ],
                'app_weight_overrides' => [
                    'qa-automation' => 6,
                    'ci-runner' => 5,
                    'feature-flags' => 4,
                    'beta-portal' => 4,
                    'user-portal' => 2,
                    'notification-service' => 2,
                    'workflow-engine' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.45, 'range' => [0, 0]],
                        ['prob' => 0.35, 'range' => [1, 3]],
                        ['prob' => 0.20, 'range' => [4, 10]],
                    ],
                    'warnings' => [
                        ['prob' => 0.25, 'range' => [0, 2]],
                        ['prob' => 0.45, 'range' => [5, 25]],
                        ['prob' => 0.30, 'range' => [30, 90]],
                    ],
                ],
                'status_total_base' => [80, 200],
                'status_total_weight' => ['fatal' => 12, 'warnings' => 5],
                'session_multiplier' => 0.45,
                'log_files' => ['min' => 1, 'max' => 3],
                'log_size_multiplier' => 0.6,
                'outlier_profile' => [
                    'fatal' => [12, 40],
                    'warnings' => [80, 200],
                    'distribution' => ['warnings' => 0.8, 'fatal' => 0.1, 'both' => 0.1],
                ],
            ],
            'dev_test_mix' => [
                'environment_weights' => ['dev' => 0.6, 'test' => 0.4],
                'app_count_range' => [4, 7],
                'preferred_categories' => [
                    'devtools' => 3.2,
                    'testing' => 2.2,
                    'api' => 1.8,
                    'communications' => 1.3,
                    'customer' => 1.1,
                    'analytics' => 1.0,
                    'infrastructure' => 0.9,
                    'internal' => 1.0,
                ],
                'app_weight_overrides' => [
                    'feature-flags' => 4,
                    'ci-runner' => 4,
                    'qa-automation' => 3,
                    'api-gateway' => 3,
                    'user-portal' => 2,
                    'message-broker' => 2,
                    'workflow-engine' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.35, 'range' => [0, 1]],
                        ['prob' => 0.40, 'range' => [2, 6]],
                        ['prob' => 0.25, 'range' => [7, 18]],
                    ],
                    'warnings' => [
                        ['prob' => 0.30, 'range' => [0, 4]],
                        ['prob' => 0.45, 'range' => [5, 25]],
                        ['prob' => 0.25, 'range' => [26, 80]],
                    ],
                ],
                'status_total_base' => [120, 320],
                'status_total_weight' => ['fatal' => 18, 'warnings' => 6],
                'session_multiplier' => 0.5,
                'log_files' => ['min' => 2, 'max' => 4],
                'log_size_multiplier' => 0.7,
                'outlier_profile' => [
                    'fatal' => [25, 70],
                    'warnings' => [90, 220],
                    'distribution' => ['warnings' => 0.5, 'fatal' => 0.3, 'both' => 0.2],
                ],
            ],
            'mixed_all' => [
                'environment_weights' => ['prod' => 0.4, 'stg' => 0.25, 'test' => 0.25, 'dev' => 0.1],
                'app_count_range' => [5, 8],
                'preferred_categories' => [
                    'commerce' => 2.4,
                    'customer' => 2.0,
                    'analytics' => 1.6,
                    'api' => 1.7,
                    'operations' => 1.3,
                    'communications' => 1.2,
                    'internal' => 1.1,
                    'infrastructure' => 1.0,
                    'devtools' => 0.9,
                    'testing' => 0.9,
                ],
                'app_weight_overrides' => [
                    'ecommerce' => 4,
                    'payment-processor' => 4,
                    'inventory-system' => 3,
                    'analytics-dashboard' => 3,
                    'message-broker' => 2,
                    'reporting-service' => 3,
                    'workflow-engine' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.48, 'range' => [0, 1]],
                        ['prob' => 0.32, 'range' => [2, 5]],
                        ['prob' => 0.20, 'range' => [6, 15]],
                    ],
                    'warnings' => [
                        ['prob' => 0.38, 'range' => [0, 4]],
                        ['prob' => 0.40, 'range' => [5, 25]],
                        ['prob' => 0.22, 'range' => [26, 90]],
                    ],
                ],
                'status_total_base' => [150, 420],
                'status_total_weight' => ['fatal' => 20, 'warnings' => 6],
                'session_multiplier' => 0.95,
                'log_files' => ['min' => 2, 'max' => 5],
                'log_size_multiplier' => 0.9,
                'outlier_profile' => [
                    'fatal' => [20, 60],
                    'warnings' => [80, 250],
                    'distribution' => ['warnings' => 0.55, 'fatal' => 0.25, 'both' => 0.2],
                ],
            ],
            'staging_prod' => [
                'environment_weights' => ['stg' => 0.55, 'prod' => 0.45],
                'app_count_range' => [4, 7],
                'preferred_categories' => [
                    'commerce' => 2.2,
                    'customer' => 2.0,
                    'analytics' => 1.6,
                    'internal' => 1.4,
                    'operations' => 1.8,
                    'communications' => 1.2,
                    'api' => 1.3,
                    'infrastructure' => 1.0,
                    'devtools' => 0.6,
                    'testing' => 0.7,
                ],
                'app_weight_overrides' => [
                    'catalog-service' => 4,
                    'inventory-system' => 3,
                    'warehouse-system' => 3,
                    'reporting-service' => 3,
                    'api-gateway' => 2,
                    'workflow-engine' => 2,
                    'shared-storage-cluster' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.58, 'range' => [0, 1]],
                        ['prob' => 0.28, 'range' => [2, 4]],
                        ['prob' => 0.14, 'range' => [5, 12]],
                    ],
                    'warnings' => [
                        ['prob' => 0.40, 'range' => [0, 3]],
                        ['prob' => 0.40, 'range' => [4, 18]],
                        ['prob' => 0.20, 'range' => [19, 70]],
                    ],
                ],
                'status_total_base' => [130, 360],
                'status_total_weight' => ['fatal' => 18, 'warnings' => 6],
                'session_multiplier' => 0.85,
                'log_files' => ['min' => 2, 'max' => 4],
                'log_size_multiplier' => 0.85,
                'outlier_profile' => [
                    'fatal' => [18, 50],
                    'warnings' => [60, 180],
                    'distribution' => ['warnings' => 0.5, 'fatal' => 0.2, 'both' => 0.3],
                ],
            ],
            'infra_mix' => [
                'environment_weights' => ['prod' => 0.4, 'stg' => 0.3, 'test' => 0.2, 'dev' => 0.1],
                'app_count_range' => [3, 6],
                'preferred_categories' => [
                    'infrastructure' => 3.5,
                    'operations' => 2.0,
                    'api' => 1.4,
                    'analytics' => 1.2,
                    'internal' => 1.1,
                    'communications' => 1.0,
                    'commerce' => 0.6,
                    'customer' => 0.6,
                    'devtools' => 1.2,
                ],
                'app_weight_overrides' => [
                    'load-balancer-node' => 6,
                    'shared-storage-cluster' => 4,
                    'message-broker' => 4,
                    'analytics-dashboard' => 2,
                    'workflow-engine' => 2,
                    'notification-service' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.40, 'range' => [0, 1]],
                        ['prob' => 0.35, 'range' => [2, 5]],
                        ['prob' => 0.25, 'range' => [6, 15]],
                    ],
                    'warnings' => [
                        ['prob' => 0.30, 'range' => [0, 3]],
                        ['prob' => 0.40, 'range' => [4, 20]],
                        ['prob' => 0.30, 'range' => [21, 80]],
                    ],
                ],
                'status_total_base' => [90, 260],
                'status_total_weight' => ['fatal' => 16, 'warnings' => 5],
                'session_multiplier' => 0.4,
                'log_files' => ['min' => 1, 'max' => 4],
                'log_size_multiplier' => 0.7,
                'outlier_profile' => [
                    'fatal' => [25, 70],
                    'warnings' => [70, 180],
                    'distribution' => ['warnings' => 0.5, 'fatal' => 0.3, 'both' => 0.2],
                ],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function presetKeyMap(): array
    {
        return [
            'production-web' => 'production_prod',
            'database-server' => 'mixed_all',
            'staging-server' => 'test_only',
            'development-server' => 'dev_test_mix',
            'backup-server' => 'staging_prod',
            'load-balancer' => 'infra_mix',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function environmentSessionProfiles(): array
    {
        return [
            'prod' => ['hour' => [300, 1800], 'day' => [4000, 22000], 'total' => [50000, 280000]],
            'stg' => ['hour' => [60, 200], 'day' => [800, 4000], 'total' => [9000, 60000]],
            'test' => ['hour' => [20, 120], 'day' => [300, 1600], 'total' => [4000, 25000]],
            'dev' => ['hour' => [10, 80], 'day' => [200, 900], 'total' => [2500, 15000]],
        ];
    }

    /**
     * @return array<string,float>
     */
    public function categorySessionMultipliers(): array
    {
        return [
            'commerce' => 1.3,
            'customer' => 1.2,
            'analytics' => 1.1,
            'api' => 1.0,
            'operations' => 0.9,
            'communications' => 0.8,
            'internal' => 0.7,
            'infrastructure' => 0.4,
            'devtools' => 0.5,
            'testing' => 0.6,
        ];
    }

    /**
     * @return array<string,array<int,int>>
     */
    public function logSizeBase(): array
    {
        return [
            'prod' => [600, 50000],
            'stg' => [200, 18000],
            'test' => [120, 12000],
            'dev' => [80, 8000],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function commonLibraries(): array
    {
        return [
            [
                'name' => 'symfony/symfony',
                'version' => 'v6.' . mt_rand(1, 4) . '.' . mt_rand(0, 15),
                'description' => 'PHP framework for web applications and a set of reusable PHP components',
            ],
            [
                'name' => 'doctrine/orm',
                'version' => 'v2.' . mt_rand(10, 17) . '.' . mt_rand(0, 8),
                'description' => 'Object-Relational-Mapper for PHP',
            ],
            [
                'name' => 'twig/twig',
                'version' => 'v3.' . mt_rand(4, 8) . '.' . mt_rand(0, 5),
                'description' => 'Flexible, fast, and secure template engine for PHP',
            ],
            [
                'name' => 'monolog/monolog',
                'version' => 'v3.' . mt_rand(0, 5) . '.' . mt_rand(0, 12),
                'description' => 'Sends your logs to files, sockets, inboxes, databases and various web services',
            ],
            [
                'name' => 'guzzlehttp/guzzle',
                'version' => 'v7.' . mt_rand(4, 8) . '.' . mt_rand(0, 5),
                'description' => 'Guzzle is a PHP HTTP client library',
            ],
            [
                'name' => 'phpunit/phpunit',
                'version' => 'v10.' . mt_rand(0, 5) . '.' . mt_rand(0, 8),
                'description' => 'The PHP Unit Testing framework',
            ],
            [
                'name' => 'league/commonmark',
                'version' => 'v2.' . mt_rand(3, 4) . '.' . mt_rand(0, 8),
                'description' => 'Highly-extensible PHP Markdown parser which fully supports the CommonMark spec',
            ],
            [
                'name' => 'nesbot/carbon',
                'version' => 'v2.' . mt_rand(65, 72) . '.' . mt_rand(0, 8),
                'description' => 'A simple API extension for DateTime',
            ],
            [
                'name' => 'ramsey/uuid',
                'version' => 'v4.' . mt_rand(6, 7) . '.' . mt_rand(0, 5),
                'description' => 'A PHP library for generating and working with UUIDs',
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function logErrorTypes(): array
    {
        return [
            ['message' => 'PHP Fatal error: Uncaught Error: Call to undefined function', 'type' => 'Error'],
            ['message' => 'PHP Warning: Division by zero in', 'type' => 'Warning'],
            ['message' => 'PHP Notice: Undefined variable: data in', 'type' => 'Deprecated'],
            ['message' => 'MySQL server has gone away', 'type' => 'Error'],
            ['message' => 'Connection timed out after 30000ms', 'type' => 'Error'],
            ['message' => 'Failed to connect to database: Access denied', 'type' => 'Error'],
            ['message' => 'HTTP 500 Internal Server Error', 'type' => 'Error'],
            ['message' => 'Class \'App\Model\User\' not found', 'type' => 'Error'],
            ['message' => 'Session started', 'type' => 'Other'],
            ['message' => 'Request completed in 234ms', 'type' => 'Other'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function sessionVariants(): array
    {
        return [
            [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => 42,
                'count_last_day' => 512,
                'count_total' => 8214,
                'path' => '/var/lib/php/sessions',
                'size_kb' => 1843.2,
            ],
            [
                'type' => 'redis',
                'status' => 'ok',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => 8214,
                'path' => null,
                'size_kb' => null,
            ],
            [
                'type' => 'none',
                'status' => 'unconfigured',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
            ],
            [
                'type' => 'memcached',
                'status' => 'unsupported',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
                'error' => 'session handler memcached is not supported',
            ],
        ];
    }
}
