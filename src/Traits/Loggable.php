<?php

declare(strict_types=1);

namespace Watcher\Traits;

use Psr\Log\LoggerInterface;

/**
 * Shared logging functionality trait.
 *
 * Provides consistent logging capabilities across classes:
 * - PSR-3 logger interface support
 * - Automatic source detection (Class::method)
 * - Optional backtrace inclusion
 * - Visual message spacing
 * - Fallback to error_log when no logger is set
 * - Context-aware logging with metadata
 *
 * @author MATA Dashboard
 *
 * @version 1.0
 *
 * @since 1.0
 */
trait Loggable
{
    protected ?LoggerInterface $logger = null;

    /**
     * Set PSR-3 logger instance.
     *
     * @param LoggerInterface $logger PSR-3 compliant logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Log message with automatic source detection and context.
     *
     * @param mixed  $message   Log message content
     * @param string $level     Log level (info, debug, warning, error, critical) (default: 'info')
     * @param mixed  $backtrace Whether to include backtrace (true/false/null) (default: null)
     * @param int    $spacing   Visual indentation spaces (default: 0)
     */
    public function log(mixed $message = '', string $level = 'info', $backtrace = null, int $spacing = 0): void
    {
        $level = strtolower($level);
        $context = [];

        // Class::method Kurzform extrahieren
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1] ?? null;
        if ($trace && isset($trace['class'])) {
            $shortClass = strrchr($trace['class'], '\\');
            $shortClass = $shortClass ? substr($shortClass, 1) : $trace['class'];
            $context['source'] = $shortClass . '::' . $trace['function'];
        }

        // Optionaler Backtrace
        if ($backtrace === true) {
            $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        }

        // Spacing (optisch eingerückt)
        if ($spacing > 0) {
            $message = str_repeat(' ', $spacing) . $message;
        }


        // Wenn Logger gesetzt ist, nutze ihn
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->log($level, $message, $context);

            return;
        }

        // Fallback
        $level = strtoupper($level);
        $msg = "[{$level}] {$message}";
        if (isset($context['source'])) {
            $msg .= " [{$context['source']}]";
        }
        error_log($msg);
    }
}
