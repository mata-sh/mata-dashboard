<?php

declare(strict_types=1);

namespace Mata\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Monolog\Logger;
use Twig\Environment;

/**
 * The renderers behind the dashboard page and its sections, built once per request.
 */
final readonly class DashboardRenderers
{
    public function __construct(
        public ServerRenderer $server,
        public DatabaseRenderer $database,
        public LibrariesRenderer $libraries,
        public SettingsRenderer $settings,
        public EventsRenderer $events,
        public AlarmsRenderer $alarms,
        public NotificationCenterRenderer $notificationCenter,
    ) {}

    public static function create(
        Environment $twig,
        Logger $logger,
        Tools $tools,
        App $app,
        Endpoints $endpoints,
    ): self {
        $logRenderer = new LogRenderer($twig, $logger, $tools, $app, $endpoints);

        return new self(
            server: new ServerRenderer($twig, $logger, $tools, $app, $endpoints, $logRenderer),
            database: new DatabaseRenderer($twig, $logger, $tools, $app, $endpoints),
            libraries: new LibrariesRenderer($twig, $logger, $tools, $app, $endpoints),
            settings: new SettingsRenderer($twig, $logger, $tools, $app, $endpoints),
            events: new EventsRenderer($twig, $logger, $tools, $app, $endpoints),
            alarms: new AlarmsRenderer($twig, $logger, $tools, $app, $endpoints),
            notificationCenter: new NotificationCenterRenderer($twig, $logger, $tools, $app, $endpoints),
        );
    }
}
