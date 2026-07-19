<?php

declare(strict_types=1);

namespace Mata\Views;

use Mata\Http\Routing\Endpoints;

/**
 * Libraries container rendering service.
 */
class LibrariesRenderer extends BaseRenderer
{
    /**
     * Render complete libraries container with wrapper template.
     *
     * @param array<string,mixed> $serversConfig Server configurations
     *
     * @return string Generated HTML for complete libraries container
     */
    public function renderContainer(Endpoints $endpoints, array $serversConfig): string
    {
        return $this->twig->render('partials/containers/libraries-container.html.twig', [
            'endpoints' => [
                'libraries_json' => $endpoints->url('libraries_json'),
                'libraries_render' => $endpoints->url('libraries_render'),
                'audit_section' => $endpoints->url('audit_section_render'),
            ],
            'filters' => [
                'servers' => $this->buildServerDropdownOptions($serversConfig),
            ],
        ]);
    }
}
