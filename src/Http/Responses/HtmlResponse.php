<?php

declare(strict_types=1);

namespace Watcher\Http\Responses;

/**
 * HTML Response.
 *
 * Sets appropriate Content-Type for HTML content
 */
class HtmlResponse extends Response
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $html, int $statusCode = 200, array $headers = [])
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        parent::__construct($html, $statusCode, $headers);
    }
}
