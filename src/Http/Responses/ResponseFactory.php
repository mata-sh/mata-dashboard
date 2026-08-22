<?php

declare(strict_types=1);

namespace Mata\Http\Responses;

use Exception;
use Mata\Http\Request;

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

    public static function htmlNotFound(string $resource = 'Resource'): HtmlResponse
    {
        $message = htmlspecialchars("{$resource} not found", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new HtmlResponse('<div class="notification is-warning" role="alert">' . $message . '</div>', 404);
    }

    public static function jsonNotFound(string $resource = 'Resource'): JsonResponse
    {
        return new JsonResponse([
            'error' => true,
            'message' => "{$resource} not found",
            'timestamp' => date('c'),
        ], 404);
    }

    /**
     * Create a response that makes HTMX navigate the browser to a new page.
     */
    public static function htmxRedirect(string $url): Response
    {
        return new Response('', 200, ['HX-Redirect' => $url]);
    }

    /**
     * Detect if the current request is an HTMX request.
     */
    public static function isHtmxRequest(Request $request): bool
    {
        return $request->header('HX-Request') === 'true';
    }

    /**
     * Detect if the current request expects JSON response.
     */
    public static function expectsJson(Request $request): bool
    {
        return self::isHtmxRequest($request)
               || str_contains($request->header('Accept') ?? '', 'application/json')
               || str_contains($request->uri(), '/api/');
    }

    /**
     * Create context-aware error response based on request type.
     */
    public static function error(
        Request $request,
        string $userMessage,
        int $statusCode = 500,
        ?string $errorCode = null,
        ?Exception $exception = null
    ): Response {
        if (self::expectsJson($request)) {
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
        Request $request,
        string $message = 'Internal server error',
        ?string $errorCode = null
    ): Response {
        return self::error($request, $message, 500, $errorCode);
    }

    /**
     * Create bad request response (400).
     */
    public static function badRequest(
        Request $request,
        string $message = 'Bad request',
        ?string $errorCode = null
    ): Response {
        return self::error($request, $message, 400, $errorCode);
    }

    /**
     * Create unauthorized response (401).
     */
    public static function unauthorized(
        Request $request,
        string $message = 'Unauthorized'
    ): Response {
        return self::error($request, $message, 401, 'UNAUTHORIZED');
    }

    /**
     * Create forbidden response (403).
     */
    public static function forbidden(
        Request $request,
        string $message = 'Forbidden'
    ): Response {
        return self::error($request, $message, 403, 'FORBIDDEN');
    }
}
