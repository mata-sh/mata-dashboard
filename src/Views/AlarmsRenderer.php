<?php

declare(strict_types=1);

namespace Mata\Views;

use Mata\Http\Routing\Endpoints;

/**
 * Alarms rendering service.
 *
 * Handles alarms-related view rendering:
 * - Alarms container with Tabulator table
 * - Endpoint injection for AJAX data loading
 */
class AlarmsRenderer extends BaseRenderer
{
    /**
     * Render complete alarms container with Tabulator table.
     *
     * @param Endpoints $endpoints Route endpoints for API access
     *
     * @return string Generated HTML for alarms container
     */
    public function renderContainer(Endpoints $endpoints): string
    {
        // Raw registered path (keeps the {alarmId} placeholder for JS substitution);
        // relative like every other endpoint URL in the templates.
        $alarmsAckUrl = $endpoints->all()['alarms_ack'] ?? '';

        return $this->twig->render('partials/containers/alarms-container.html.twig', [
            'endpoints' => [
                'alarms_json' => $endpoints->url('alarms_json'),
                'alarms_ack' => $alarmsAckUrl,
                'ack_all' => $endpoints->url('notification_bell_ack_all'),
            ],
            'icons' => [
                'help' => $this->tools->icon('help-circle', 14),
            ],
        ]);
    }
}
