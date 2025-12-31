<?php

declare(strict_types=1);

namespace Watcher\Core;

use ErrorException;
use Exception;
use Monolog\Logger;
use Throwable;
use Watcher\Http\Responses\ResponseFactory;

class ExceptionHandler
{
    private Logger $logger;
    private bool $debugMode;

    public function __construct(Logger $logger, bool $debugMode = false)
    {
        $this->logger = $logger;
        $this->debugMode = $debugMode;

        // Apply PHP internal settings accordingly
        ini_set('display_errors', $debugMode ? '1' : '0');
        error_reporting($debugMode ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    }

    public function register(): void
    {
        set_exception_handler([$this, 'handle']);
        set_error_handler([$this, 'handleError']);
    }

    public function handle(Throwable $exception): void
    {
        $this->logException($exception);
        $this->clearOutputBuffer();
        $this->sendErrorResponse($exception);

        exit;
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $this->logger->error('PHP Error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        // Convert errors to exceptions in debug mode for full trace context
        if ($this->debugMode) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }

        return false;
    }

    private function logException(Throwable $exception): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        if ($this->debugMode) {
            $context['trace'] = $exception->getTraceAsString();
            $context['server'] = $_SERVER;
        }

        $this->logger->critical('Uncaught exception', $context);
    }

    private function clearOutputBuffer(): void
    {
        // In debug mode we might want to see any partially rendered output
        if (!$this->debugMode && ob_get_level()) {
            ob_end_clean();
        }
    }

    private function sendErrorResponse(Throwable $exception): void
    {
        $errorCode = 'SERVER_ERROR_' . date('YmdHis');

        if ($this->debugMode) {
            $message = sprintf(
                "<h2>Uncaught Exception: %s</h2>
                 <p><strong>Message:</strong> %s</p>
                 <p><strong>File:</strong> %s:%d</p>
                 <pre style='background:#222;color:#eee;padding:10px;border-radius:6px;overflow-x:auto;'>%s</pre>",
                htmlspecialchars(get_class($exception)),
                htmlspecialchars($exception->getMessage()),
                htmlspecialchars($exception->getFile()),
                $exception->getLine(),
                htmlspecialchars($exception->getTraceAsString())
            );

            $response = ResponseFactory::htmlError($message, 500);
        } else {
            // Use context-aware error response (JSON for API/HTMX, HTML otherwise)
            $userMessage = 'A server error occurred. Please try again later.';
            $response = ResponseFactory::error(
                $userMessage,
                500,
                $errorCode,
                $exception instanceof Exception ? $exception : new Exception($exception->getMessage())
            );
        }

        $response->send();
    }
}
