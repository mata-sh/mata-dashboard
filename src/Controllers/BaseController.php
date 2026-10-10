<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Exception;
use Mata\Http\Request;
use Mata\Http\Responses\Response;
use Mata\Http\Responses\ResponseFactory;
use Monolog\Logger;

/**
 * Base controller providing standardized error handling.
 *
 * Provides consistent error handling patterns across all controllers
 * with proper logging, user-friendly messages, and HTMX-aware responses
 */
abstract class BaseController
{
    protected Logger $logger;

    public function __construct(protected readonly Request $request) {}

    /**
     * Handle exceptions with consistent logging and user-friendly responses.
     *
     * @param Exception   $exception   The caught exception
     * @param string      $userMessage User-friendly error message
     * @param string      $context     Additional context for logging
     * @param int         $statusCode  HTTP status code (default: 500)
     * @param null|string $errorCode   Optional error code for API responses
     *
     * @return Response Appropriate response based on request type
     */
    protected function handleError(
        Exception $exception,
        string $userMessage,
        string $context = '',
        int $statusCode = 500,
        ?string $errorCode = null
    ): Response {
        // Build logging context
        $logContext = [
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        if (!empty($context)) {
            $logContext['context'] = $context;
        }

        // Add request context if available
        if ($this->request->uri() !== '') {
            $logContext['request_uri'] = $this->request->uri();
        }

        if ($this->request->method() !== '') {
            $logContext['request_method'] = $this->request->method();
        }

        // Log the error
        $logMessage = !empty($context) ? $context : 'Controller error occurred';
        $this->logger->error($logMessage, $logContext);

        // Return appropriate response
        return ResponseFactory::error($this->request, $userMessage, $statusCode, $errorCode, $exception);
    }

    /**
     * Handle validation errors with structured response.
     *
     * @param array<string,mixed> $errors  Validation errors
     * @param string              $message Optional custom message
     *
     * @return Response Validation error response
     */
    protected function handleValidationError(
        array $errors,
        string $message = 'Validation failed'
    ): Response {
        $this->logger->warning($message, [
            'errors' => $errors,
            'request_uri' => $this->request->uri(),
        ]);

        return ResponseFactory::validationError($errors);
    }

    protected function handleHtmlNotFound(string $resource, string $context = ''): Response
    {
        $this->logNotFound($resource, $context);

        return ResponseFactory::htmlNotFound($resource);
    }

    protected function handleJsonNotFound(string $resource, string $context = ''): Response
    {
        $this->logNotFound($resource, $context);

        return ResponseFactory::jsonNotFound($resource);
    }

    protected function logNotFound(string $resource, string $context): void
    {
        $logContext = [
            'resource' => $resource,
            'request_uri' => $this->request->uri(),
        ];

        if ($context !== '') {
            $logContext['context'] = $context;
        }

        $this->logger->warning('Resource not found', $logContext);
    }

    /**
     * Execute action with standardized error handling.
     *
     * @param callable $action      The action to execute
     * @param string   $userMessage User-friendly error message for failures
     * @param string   $context     Additional context for logging
     *
     * @return Response Response from action or error response
     */
    protected function executeWithErrorHandling(
        callable $action,
        string $userMessage,
        string $context = ''
    ): Response {
        try {
            return $action();
        } catch (Exception $e) {
            return $this->handleError($e, $userMessage, $context);
        }
    }
}
