<?php

declare(strict_types=1);

namespace Mata\Views;

use Mata\Services\Navigation\NotificationCenter;

/**
 * Topbar notification bell rendering service.
 *
 * Renders the bell fragment with per-severity unacknowledged alarm count pills.
 * The fragment polls itself via htmx and is swapped in place, so it must
 * stay self-contained (own hx-get/hx-trigger attributes on the wrapper).
 */
class NotificationCenterRenderer extends BaseRenderer
{
    /**
     * Render the notification bell fragment.
     *
     * @param array<string,int> $counts Unacknowledged alarm counts per severity level
     *
     * @return string Generated HTML for the bell fragment
     */
    public function renderBell(array $counts): string
    {
        $pills = [];
        foreach (NotificationCenter::PILL_LEVELS as $level) {
            $pills[] = [
                'level' => $level,
                'count' => $counts[$level] ?? 0,
            ];
        }

        return $this->twig->render('partials/structure/notification-bell.html.twig', [
            'pills' => $pills,
            'total' => array_sum($counts),
            'icons' => [
                'bell' => $this->tools->icon('bell', 16),
            ],
            'endpoints' => [
                'ack_all' => $this->endpoints->url('notification_bell_ack_all'),
                'alarms_render' => $this->endpoints->url('alarms_render'),
            ],
        ]);
    }
}
