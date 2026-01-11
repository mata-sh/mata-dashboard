<?php

declare(strict_types=1);

namespace Mata\Http\Responses;

use Exception;

/**
 * Response factory for creating standardized HTTP responses.
 *
 * Provides methods for creating error responses with HTMX awareness
 * and context-appropriate formatting
 */
class ResponseFactory
{
    /**
     * Create a JSON error response.
     */
    /**
     * @param null|array<string,mixed> $details
     */
    public static function jsonError(
        string $message,
        int $statusCode = 500,
        ?string $errorCode = null,
        ?array $details = null
    ): JsonResponse {
        $data = [
            'error' => true,
            'message' => $message,
            'timestamp' => date('c'),
        ];

        if ($errorCode !== null) {
            $data['code'] = $errorCode;
        }

        if ($details !== null) {
            $data['details'] = $details;
        }

        return new JsonResponse($data, $statusCode);
    }

    /**
     * Create an HTML error response.
     */
    public static function htmlError(
        string $message,
        int $statusCode = 500,
        ?string $template = null
    ): HtmlResponse {
        // Could render error template here
        $html = $template
            ? $template
            : "<html><body><h1>Error {$statusCode}</h1><p>{$message}</p></body></html>";

        return new HtmlResponse($html, $statusCode);
    }

    /**
     * Create validation error response (422).
     */
    /**
     * @param array<string,mixed> $errors
     */
    public static function validationError(array $errors): JsonResponse
    {
        return new JsonResponse([
            'error' => true,
            'message' => 'Validation failed',
            'errors' => $errors,
            'timestamp' => date('c'),
        ], 422);
    }

    /**
     * Create "not found" response (404).
     */
    public static function notFound(string $resource = 'Resource'): JsonResponse
    {
        return new JsonResponse([
            'error' => true,
            'message' => "{$resource} not found",
            'timestamp' => date('c'),
        ], 404);
    }

    /**
     * Detect if the current request is an HTMX request.
     */
    public static function isHtmxRequest(): bool
    {
        return isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
    }

    /**
     * Detect if the current request expects JSON response.
     */
    public static function expectsJson(): bool
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';

        return self::isHtmxRequest()
               || str_contains($acceptHeader, 'application/json')
               || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    }

    /**
     * Create context-aware error response based on request type.
     */
    public static function error(
        string $userMessage,
        int $statusCode = 500,
        ?string $errorCode = null,
        ?Exception $exception = null
    ): Response {
        if (self::expectsJson()) {
            $data = [
                'error' => true,
                'message' => $userMessage,
                'timestamp' => date('c'),
            ];

            if ($errorCode !== null) {
                $data['code'] = $errorCode;
            }

            // Add debug info in development mode
            if ($exception !== null && ($_ENV['DEBUG'] ?? false)) {
                $data['debug'] = [
                    'exception' => get_class($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ];
            }

            return new JsonResponse($data, $statusCode);
        }

        // Return HTML error response
        return self::htmlError($userMessage, $statusCode);
    }

    /**
     * Create enhanced JSON error response with exception context.
     */
    public static function fromException(
        Exception $exception,
        string $userMessage,
        int $statusCode = 500,
        ?string $errorCode = null
    ): JsonResponse {
        $data = [
            'error' => true,
            'message' => $userMessage,
            'timestamp' => date('c'),
        ];

        if ($errorCode !== null) {
            $data['code'] = $errorCode;
        }

        // Add debug info in development mode
        if ($_ENV['DEBUG'] ?? false) {
            $data['debug'] = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        return new JsonResponse($data, $statusCode);
    }

    /**
     * Create server error response (500).
     */
    public static function serverError(
        string $message = 'Internal server error',
        ?string $errorCode = null
    ): Response {
        return self::error($message, 500, $errorCode);
    }

    /**
     * Create bad request response (400).
     */
    public static function badRequest(
        string $message = 'Bad request',
        ?string $errorCode = null
    ): Response {
        return self::error($message, 400, $errorCode);
    }

    /**
     * Create unauthorized response (401).
     */
    public static function unauthorized(
        string $message = 'Unauthorized'
    ): Response {
        return self::error($message, 401, 'UNAUTHORIZED');
    }

    /**
     * Create forbidden response (403).
     */
    public static function forbidden(
        string $message = 'Forbidden'
    ): Response {
        return self::error($message, 403, 'FORBIDDEN');
    }
}
