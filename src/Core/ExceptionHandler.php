<?php

declare(strict_types=1);

namespace Mata\Core;

use ErrorException;
use Mata\Http\Request;
use Mata\Http\Responses\ResponseFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class ExceptionHandler
{
    private LoggerInterface $logger;
    private bool $debugMode;
    private Request $request;

    public function __construct(LoggerInterface $logger, Request $request, bool $debugMode = false)
    {
        $this->logger = $logger;
        $this->request = $request;
        $this->debugMode = $debugMode;

        // Apply PHP internal settings accordingly
        ini_set('display_errors', $debugMode ? '1' : '0');
        error_reporting(E_ALL);
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

        exit(1);
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        if ($this->debugMode) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }

        return true;
    }

    private function logException(Throwable $exception): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'request_uri' => $this->request->uri(),
            'request_method' => $this->request->method(),
            'user_agent' => $this->request->header('User-Agent') ?? '',
        ];

        if ($this->debugMode) {
            $context['trace'] = $exception->getTraceAsString();
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
            $response = ResponseFactory::error($this->request, $userMessage, 500, $errorCode);
        }

        $response->send();
    }
}
