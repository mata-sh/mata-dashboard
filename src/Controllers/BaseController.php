<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Exception;
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
        if (!empty($_SERVER['REQUEST_URI'])) {
            $logContext['request_uri'] = $_SERVER['REQUEST_URI'];
        }

        if (!empty($_SERVER['REQUEST_METHOD'])) {
            $logContext['request_method'] = $_SERVER['REQUEST_METHOD'];
        }

        // Log the error
        $logMessage = !empty($context) ? $context : 'Controller error occurred';
        $this->logger->error($logMessage, $logContext);

        // Return appropriate response
        return ResponseFactory::error($userMessage, $statusCode, $errorCode, $exception);
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
        $this->logger->warning('Validation failed', [
            'errors' => $errors,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        return ResponseFactory::validationError($errors);
    }

    /**
     * Handle resource not found errors.
     *
     * @param string $resource Resource name or identifier
     * @param string $context  Additional context for logging
     *
     * @return Response 404 response
     */
    protected function handleNotFound(string $resource, string $context = ''): Response
    {
        $logContext = [
            'resource' => $resource,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ];

        if (!empty($context)) {
            $logContext['context'] = $context;
        }

        $this->logger->warning('Resource not found', $logContext);

        return ResponseFactory::notFound($resource);
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

    /**
     * Check if request is authorized.
     *
     * @return bool True if authorized
     */
    protected function isAuthorized(): bool
    {
        // This can be overridden by child controllers for specific auth logic
        return true;
    }

    /**
     * Handle unauthorized access.
     *
     * @param string $context Additional context for logging
     *
     * @return Response Unauthorized response
     */
    protected function handleUnauthorized(string $context = ''): Response
    {
        $logContext = [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        if (!empty($context)) {
            $logContext['context'] = $context;
        }

        $this->logger->warning('Unauthorized access attempt', $logContext);

        return ResponseFactory::unauthorized();
    }
}
