<?php

declare(strict_types=1);

namespace Mata\Services\Notification;

use Exception;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Utils\FileStateStore;
use Mata\Utils\PathResolver;
use Psr\Log\LoggerInterface;

/**
 * Notification management system for application alarms and warnings.
 *
 * Provides comprehensive notification handling with:
 * - Database-backed notification storage
 * - Read/unread status tracking
 * - Display interval management
 * - Notification types (info, warning, error, alarm)
 * - Auto-cleanup of old notifications
 * - Duplicate notification prevention
 */
final class NotificationManager
{
    private DatabaseInterface $db;
    private LoggerInterface $logger;
    private FileStateStore $offlineNoticeStore;

    private const OFFLINE_NOTICE_SUBDIR = 'runtime';
    private const OFFLINE_NOTICE_FILENAME = 'db_connection_notice.json';

    /**
     * Initialize notification manager with database connection.
     */
    public function __construct(DatabaseInterface $db, LoggerInterface $logger, ?PathResolver $paths = null)
    {
        $this->db = $db;
        $this->logger = $logger;

        $baseDirectory = $paths !== null ? $paths->tempPath : sys_get_temp_dir();
        $offlineDirectory = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::OFFLINE_NOTICE_SUBDIR;
        $this->offlineNoticeStore = new FileStateStore($offlineDirectory, self::OFFLINE_NOTICE_FILENAME, $logger);
    }

    /**
     * Add a new notification to the system.
     *
     * @param string      $type         Notification type (info, warning, error, alarm)
     * @param string      $title        Short notification title
     * @param string      $message      Detailed notification message
     * @param int         $showInterval Interval in hours between showing the notification (0 = show once)
     * @param bool        $isActive     Whether notification should be actively shown
     * @param null|string $identifier   Unique identifier for persistent notifications
     *
     * @return bool True on success, false on failure
     */
    public function addNotification(
        string $type,
        string $title,
        string $message,
        int $showInterval = 24,
        bool $isActive = true,
        ?string $identifier = null
    ): bool {
        // If identifier is provided, use addOrUpdateNotification for smart handling
        if ($identifier !== null) {
            return $this->addOrUpdateNotification($identifier, $type, $title, $message, $showInterval, $isActive);
        }

        if ($this->isDuplicateNotification($type, $title, $message)) {
            return true; // Don't add duplicate, but return success
        }

        $sql = 'INSERT INTO notifications
                (`identifier`, `type`, `title`, `message`, `created_at`, `updated_at`, `last_shown`, `show_interval`, `dismissed_at`, `deactivated_at`)
                VALUES (?, ?, ?, ?, NOW(), NOW(), NULL, ?, NULL, ?)';

        try {
            $this->db->runInsert($sql, [$identifier, $type, $title, $message, $showInterval, $isActive ? null : date('Y-m-d H:i:s')]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to add notification: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Add or update a notification by identifier (prevents duplicates for persistent notifications).
     *
     * @param string $identifier   Unique identifier for the notification
     * @param string $type         Notification type (info, warning, error, alarm)
     * @param string $title        Short notification title
     * @param string $message      Detailed notification message
     * @param int    $showInterval Interval in hours between showing the notification
     * @param bool   $isActive     Whether notification should be actively shown
     *
     * @return bool True on success, false on failure
     */
    public function addOrUpdateNotification(
        string $identifier,
        string $type,
        string $title,
        string $message,
        int $showInterval = 24,
        bool $isActive = true
    ): bool {
        // Check if notification with this identifier already exists and is active
        $existingNotification = $this->getNotificationByIdentifier($identifier);

        if ($existingNotification) {
            // Update existing notification
            return $this->updateNotificationByIdentifier(
                $identifier,
                $type,
                $title,
                $message,
                $showInterval,
                $isActive
            );
        }
        // Create new notification
        $sql = 'INSERT INTO notifications
                    (identifier, type, title, message, created_at, updated_at, last_shown, show_interval, dismissed_at, deactivated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW(), NULL, ?, NULL, ?)';

        try {
            $this->db->runInsert($sql, [$identifier, $type, $title, $message, $showInterval, $isActive ? null : date('Y-m-d H:i:s')]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to add notification: ' . $e->getMessage());

            return false;
        }

    }

    /**
     * Update an existing notification by identifier.
     *
     * @param string $identifier   Unique identifier for the notification
     * @param string $type         Notification type
     * @param string $title        Notification title
     * @param string $message      Notification message
     * @param int    $showInterval Show interval in hours
     * @param bool   $isActive     Whether notification is active
     *
     * @return bool True on success, false on failure
     */
    private function updateNotificationByIdentifier(
        string $identifier,
        string $type,
        string $title,
        string $message,
        int $showInterval,
        bool $isActive
    ): bool {
        $sql = 'UPDATE notifications
                SET type = ?, title = ?, message = ?, updated_at = NOW(),
                    show_interval = ?, deactivated_at = ?, dismissed_at = NULL
                WHERE identifier = ? AND deactivated_at IS NULL';

        try {
            $this->db->runStatement($sql, [$type, $title, $message, $showInterval, $isActive ? null : date('Y-m-d H:i:s'), $identifier]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update notification: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get notification by identifier.
     *
     * @param string $identifier Unique identifier
     *
     * @return null|array<string,mixed> Notification data or null if not found
     */
    private function getNotificationByIdentifier(string $identifier): ?array
    {
        $sql = 'SELECT * FROM notifications WHERE identifier = ? AND deactivated_at IS NULL LIMIT 1';

        try {
            $result = $this->db->runSelect($sql, [$identifier]);
            if ($result !== []) {
                return $result[0];
            }

            return null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get notification by identifier: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Remove notification by identifier (when config issue is resolved).
     *
     * @param string $identifier Unique identifier for the notification
     *
     * @return bool True on success, false on failure
     */
    public function removeNotificationByIdentifier(string $identifier): bool
    {
        $sql = 'UPDATE notifications SET deactivated_at = NOW() WHERE identifier = ? AND deactivated_at IS NULL';

        try {
            $this->db->runStatement($sql, [$identifier]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to remove notification by identifier: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Remove multiple notifications by identifiers (batch operation).
     *
     * @param array<string> $identifiers Array of identifiers to remove
     *
     * @return bool True on success, false on failure
     */
    public function removeNotificationsByIdentifiers(array $identifiers): bool
    {
        if (empty($identifiers)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
        $sql = "UPDATE notifications
                SET deactivated_at = NOW()
                WHERE identifier IN ({$placeholders}) AND deactivated_at IS NULL";

        try {
            // Positional placeholders need a list; callers may pass array_diff() output.
            $this->db->runStatement($sql, array_values($identifiers));

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to remove notifications by identifiers: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get active notifications that should be displayed.
     *
     * @return array<int,array<string,mixed>> Array of notification data
     */
    public function getActiveNotifications(): array
    {
        $notifications = [];

        if (!$this->db instanceof NullDatabase) {
            $sql = "SELECT id, type, title, message, created_at, dismissed_at, show_interval
                    FROM notifications
                    WHERE deactivated_at IS NULL
                    AND (
                        dismissed_at IS NULL
                        OR show_interval = 0
                        OR DATE_ADD(dismissed_at, INTERVAL show_interval HOUR) <= NOW()
                    )
                    ORDER BY
                        CASE type
                            WHEN 'alarm' THEN 1
                            WHEN 'error' THEN 2
                            WHEN 'warning' THEN 3
                            WHEN 'info' THEN 4
                        END,
                        created_at DESC";

            try {
                $notifications = $this->db->runSelect($sql);
            } catch (Exception $e) {
                $this->logger->error('Failed to get active notifications: ' . $e->getMessage());
            }
        }

        return $this->appendOfflineNotice($notifications);
    }

    /**
     * Mark notification as dismissed (user closed it).
     *
     * @param int $notificationId Notification ID
     *
     * @return bool True on success, false on failure
     */
    public function markAsRead(int $notificationId): bool
    {
        $sql = 'UPDATE notifications SET dismissed_at = NOW() WHERE id = ?';

        try {
            $this->db->runStatement($sql, [$notificationId]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to mark notification as dismissed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Mark notification as inactive (hide permanently).
     *
     * @param int $notificationId Notification ID
     *
     * @return bool True on success, false on failure
     */
    public function deactivateNotification(int $notificationId): bool
    {
        $sql = 'UPDATE notifications SET deactivated_at = NOW() WHERE id = ?';

        try {
            $this->db->runStatement($sql, [$notificationId]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to deactivate notification: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Clean up old notifications (older than specified days).
     *
     * @param int $daysOld Number of days after which to clean up notifications
     *
     * @return bool True on success, false on failure
     */
    public function cleanupOldNotifications(int $daysOld = 30): bool
    {
        $sql = 'DELETE FROM notifications
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND deactivated_at IS NOT NULL';

        try {
            $this->db->runStatement($sql, [$daysOld]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup old notifications: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get count of undismissed active notifications by type.
     *
     * @return array<string,int> Count of notifications by type
     */
    public function getUnreadCount(): array
    {
        $sql = 'SELECT type, COUNT(*) as count
                FROM notifications
                WHERE deactivated_at IS NULL AND dismissed_at IS NULL
                GROUP BY type';

        try {
            $results = $this->db->runSelect($sql);
            $counts = [];
            foreach ($results as $row) {
                $counts[$row['type']] = (int) $row['count'];
            }

            return $counts;
        } catch (Exception $e) {
            $this->logger->error('Failed to get unread count: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Check if a notification with similar content already exists.
     *
     * Prevents creating duplicate notifications as long as an active one exists.
     * The existing notification will re-show based on dismissed_at + show_interval logic.
     *
     * @param string $type    Notification type
     * @param string $title   Notification title
     * @param string $message Notification message
     *
     * @return bool True if duplicate exists, false otherwise
     */
    private function isDuplicateNotification(string $type, string $title, string $message): bool
    {
        $sql = 'SELECT id FROM notifications
                WHERE type = ? AND title = ? AND message = ?
                AND deactivated_at IS NULL
                LIMIT 1';

        try {
            $result = $this->db->runSelect($sql, [$type, $title, $message]);

            return !empty($result);
        } catch (Exception $e) {
            $this->logger->error('Failed to check for duplicate notification: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Append the offline database notice (if needed) to the notification list.
     *
     * @param array<int,array<string,mixed>> $notifications
     *
     * @return array<int,array<string,mixed>>
     */
    private function appendOfflineNotice(array $notifications): array
    {
        $offlineNotice = $this->buildOfflineNotice();
        if (!empty($offlineNotice)) {
            array_unshift($notifications, $offlineNotice);
        }

        return $notifications;
    }

    /**
     * Build the offline notice data structure if the database is unavailable.
     *
     * @return array<string,mixed>
     */
    private function buildOfflineNotice(): array
    {
        if (!$this->db instanceof NullDatabase) {
            return [];
        }

        if (!$this->shouldShowOfflineNotice()) {
            return [];
        }

        return [
            'id' => 'db-connection-unavailable',
            'type' => 'warning',
            'title' => 'Database connection unavailable',
            'message' => 'The dashboard is running without a database connection. Historical data, alarms and notifications may be incomplete until the database becomes reachable again.',
            'actions' => [
                [
                    'label' => "Don't show again",
                    'style' => 'is-light',
                    'action' => 'never',
                    'endpoint' => 'database_notice_ack',
                ],
                [
                    'label' => 'Remind me later',
                    'style' => 'is-primary',
                    'action' => 'snooze',
                    'endpoint' => 'database_notice_ack',
                ],
            ],
            'close_action' => 'snooze',
            'close_endpoint' => 'database_notice_ack',
        ];
    }

    /**
     * Determine whether the offline notice should be displayed.
     */
    private function shouldShowOfflineNotice(): bool
    {
        $state = $this->loadOfflineNoticeState();

        if ($state['dismissed_permanently']) {
            return false;
        }

        $snoozedUntil = $state['snoozed_until'];
        if (is_int($snoozedUntil) && $snoozedUntil > time()) {
            return false;
        }

        return true;
    }

    /**
     * Acknowledge the offline notice (snooze, never show, reset, etc.).
     */
    public function acknowledgeOfflineNotice(string $action): bool
    {
        $normalizedAction = strtolower(trim($action));
        $state = $this->loadOfflineNoticeState();
        $updated = true;

        switch ($normalizedAction) {
            case 'never':
                $state['dismissed_permanently'] = true;

                break;

            case 'snooze':
                $state['snoozed_until'] = time() + (24 * 3600);

                break;

            case 'reset':
                $state = $this->defaultOfflineNoticeState();

                break;

            default:
                $updated = false;

                break;
        }

        if ($updated) {
            $this->saveOfflineNoticeState($state);
        }

        return $updated;
    }

    /**
     * @return array{dismissed_permanently:bool,snoozed_until:null|int}
     */
    private function loadOfflineNoticeState(): array
    {
        $data = $this->offlineNoticeStore->load(fn (): array => $this->defaultOfflineNoticeState());

        return [
            'dismissed_permanently' => (bool) ($data['dismissed_permanently'] ?? false),
            'snoozed_until' => isset($data['snoozed_until']) ? (int) $data['snoozed_until'] : null,
        ];
    }

    /**
     * @param array{dismissed_permanently:bool,snoozed_until:null|int} $state
     */
    private function saveOfflineNoticeState(array $state): void
    {
        $this->offlineNoticeStore->save($state);
    }

    /**
     * @return array{dismissed_permanently:bool,snoozed_until:null|int}
     */
    private function defaultOfflineNoticeState(): array
    {
        return [
            'dismissed_permanently' => false,
            'snoozed_until' => null,
        ];
    }

    public function addConfigWarning(string $identifier, string $message): void
    {
        $this->addNotification(
            type: 'warning',
            title: 'Configuration Warning',
            message: $message,
            showInterval: 24,
            isActive: true,
            identifier: $identifier
        );
    }

    /**
     * Get all config warning identifiers currently stored in the database.
     *
     * @return array<string> Array of config warning identifiers (config.*)
     */
    public function getConfigWarningIdentifiers(): array
    {
        $sql = "SELECT DISTINCT identifier
                FROM notifications
                WHERE identifier LIKE 'config.%'
                AND deactivated_at IS NULL";

        try {
            return array_column($this->db->runSelect($sql), 'identifier');
        } catch (Exception $e) {
            $this->logger->error('Failed to get config warning identifiers: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Clean up resolved config warnings by comparing current warnings with database.
     *
     * @param array<array<string,string>> $currentWarnings Current validation warnings with identifier and message
     *
     * @return bool True on success, false on failure
     */
    public function cleanupResolvedConfigWarnings(array $currentWarnings): bool
    {
        try {
            $activeIdentifiers = array_column($currentWarnings, 'identifier');
            $existingConfigWarnings = $this->getConfigWarningIdentifiers();

            $resolvedIssues = array_diff($existingConfigWarnings, $activeIdentifiers);
            if (!empty($resolvedIssues)) {
                return $this->removeNotificationsByIdentifiers($resolvedIssues);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup resolved config warnings: ' . $e->getMessage());

            return false;
        }
    }
}
