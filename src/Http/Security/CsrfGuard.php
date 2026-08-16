<?php

declare(strict_types=1);

namespace Mata\Http\Security;

/**
 * Provides CSRF token generation and request validation.
 */
final class CsrfGuard
{
    public const SESSION_KEY = '_csrf_token';
    public const FIELD_NAME = '_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';

    /**
     * Get the current CSRF token, creating it lazily when missing.
     */
    public function getToken(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * Validate the submitted CSRF token against the existing session token.
     */
    public function validateRequest(): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        $submitted = $_POST[self::FIELD_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!is_string($sessionToken) || $sessionToken === '' || !is_string($submitted)) {
            return false;
        }

        return hash_equals($sessionToken, $submitted);
    }

    /**
     * Force-generate and store a new CSRF token.
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }
}
