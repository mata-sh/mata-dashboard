<?php

declare(strict_types=1);

namespace Mata\Http\Responses;

/**
 * Redirect Response.
 *
 * For HTTP redirects (301, 302, etc.)
 */
class RedirectResponse extends Response
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $url, int $statusCode = 302, array $headers = [])
    {
        $headers['Location'] = $url;
        parent::__construct('', $statusCode, $headers);
    }
}
