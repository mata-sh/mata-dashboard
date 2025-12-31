<?php

declare(strict_types=1);

namespace Watcher\Views;

use Watcher\Http\Routing\Endpoints;

/**
 * Events rendering service.
 *
 * Handles events-related view rendering:
 * - Events container with Tabulator table
 * - Endpoint injection for AJAX data loading
 */
class EventsRenderer extends BaseRenderer
{
    /**
     * Render complete events container with Tabulator table.
     *
     * @param Endpoints $endpoints Route endpoints for API access
     *
     * @return string Generated HTML for events container
     */
    public function renderContainer(Endpoints $endpoints): string
    {
        return $this->twig->render('partials/containers/events-container.html.twig', [
            'endpoints' => [
                'events_json' => $endpoints->get('events_json'),
            ],
        ]);
    }
}
