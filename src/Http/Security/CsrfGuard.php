<?php

declare(strict_types=1);

namespace Mata\Http\Security;

use Mata\Core\Session;
use Mata\Http\Request;

/**
 * Provides CSRF token generation and request validation.
 */
final class CsrfGuard
{
    public const SESSION_KEY = '_csrf_token';
    public const FIELD_NAME = '_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';

    public function __construct(private readonly Request $request) {}

    /**
     * Get the current CSRF token, creating it lazily when missing.
     */
    public function getToken(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Validate the submitted CSRF token against the existing session token.
     */
    public function validateRequest(): bool
    {
        $sessionToken = Session::get(self::SESSION_KEY);
        $submitted = $this->request->postString(self::FIELD_NAME) ?? $this->request->header(self::HEADER_NAME);

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
        Session::set(self::SESSION_KEY, $token);

        return $token;
    }
}
