<?php

declare(strict_types=1);

namespace Mata\Services\Navigation;

use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;

/**
 * Builds navigation menu configuration for the dashboard.
 *
 * Responsible for constructing menu items with their display properties,
 * routing endpoints, and visibility states. Provides a centralized location
 * for menu configuration separate from controller logic.
 */
class MenuBuilder
{
    /**
     * Canonical list of menu item keys the dashboard knows about.
     *
     * Shared with MenuConfigLoader so menu.json can be validated against the
     * same set of keys that buildDashboardMenu() emits. A drift guard test
     * keeps this list in sync with the items built below.
     *
     * @var list<string>
     */
    public const MENU_KEYS = [
        'events',
        'alarms',
        'libraries',
        'metrics',
        'databases',
        'force_refresh',
        'settings',
    ];

    private Endpoints $endpoints;
    private Tools $tools;

    /** @var array<string,bool> */
    private array $enabledOverrides;

    /**
     * @param Endpoints          $endpoints        Route endpoints for menu item references
     * @param Tools              $tools            Icon/utility helpers
     * @param array<string,bool> $enabledOverrides Per-item visibility overrides from menu.json,
     *                                             keyed by menu item key. Missing keys keep the
     *                                             built-in default.
     */
    public function __construct(Endpoints $endpoints, Tools $tools, array $enabledOverrides = [])
    {
        $this->endpoints = $endpoints;
        $this->tools = $tools;
        $this->enabledOverrides = $enabledOverrides;
    }

    /**
     * Build main dashboard navigation menu.
     *
     * Returns array of menu items with their display properties and routing endpoints.
     * Each menu item contains key identifier, display label, icon reference, endpoint key,
     * resolved URL, and enabled flag for visibility control.
     *
     * The endpoint URLs are resolved from the Endpoints registry to maintain a single
     * source of truth for route definitions (RouterSetup).
     *
     * @return array<int,array{key:string,label:string,icon:string,endpoint:string,url:null|string,enabled:bool}>
     */
    public function buildDashboardMenu(): array
    {

        $menuItems = [
            [
                'key' => 'events',
                'label' => 'Events',
                'icon' => $this->tools->icon('radio', 12),
                'endpoint_key' => 'events_render',
                'enabled' => true,
            ],
            [
                'key' => 'alarms',
                'label' => 'Alarms',
                'icon' => $this->tools->icon('bell', 12),
                'endpoint_key' => 'alarms_render',
                'enabled' => true,
            ],
            [
                'key' => 'libraries',
                'label' => 'Libraries',
                'icon' => $this->tools->icon('package', 12),
                'endpoint_key' => 'libraries_render',
                'enabled' => true,
            ],
            [
                'key' => 'metrics',
                'label' => 'Metrics',
                'icon' => $this->tools->icon('activity', 12),
                'endpoint_key' => 'metrics_render',
                'enabled' => false,
            ],
            [
                'key' => 'databases',
                'label' => 'Databases',
                'icon' => $this->tools->icon('database', 12),
                'endpoint_key' => 'databases_render',
                'enabled' => true,
            ],
            [
                'key' => 'force_refresh',
                'label' => 'Force Refresh',
                'icon' => $this->tools->icon('refresh-cw', 12),
                'endpoint_key' => 'refresh_servers',
                'enabled' => true,
            ],
            [
                'key' => 'settings',
                'label' => 'Settings',
                'icon' => $this->tools->icon('settings', 12),
                'endpoint_key' => 'settings_render',
                'enabled' => true,
            ],
        ];

        // Resolve endpoint URLs from registry and apply menu.json visibility overrides
        return array_map(function ($item) {
            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'endpoint' => $item['endpoint_key'],
                'url' => $this->endpoints->url($item['endpoint_key']),
                'enabled' => $this->enabledOverrides[$item['key']] ?? $item['enabled'],
            ];
        }, $menuItems);
    }

    /**
     * Get only enabled menu items.
     *
     * @return array<int,array{key:string,label:string,icon:string,endpoint:string,url:null|string,enabled:bool}>
     */
    public function getEnabledMenuItems(): array
    {
        return array_values(array_filter(
            $this->buildDashboardMenu(),
            fn ($item) => $item['enabled'] === true
        ));
    }

    /**
     * Get menu item by key.
     *
     * @param string $key Menu item key identifier
     *
     * @return null|array{key:string,label:string,icon:string,endpoint:string,url:null|string,enabled:bool}
     */
    public function getMenuItem(string $key): ?array
    {
        $menu = $this->buildDashboardMenu();
        foreach ($menu as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }
}
