<?php

declare(strict_types=1);

namespace Watcher\Http\Responses;

use JsonException;

/**
 * JSON Response.
 *
 * Automatically sets Content-Type header and encodes data as JSON
 */
class JsonResponse extends Response
{
    /** @var mixed */
    private $data;

    /**
     * @param mixed                $data
     * @param array<string,string> $headers
     */
    public function __construct($data, int $statusCode = 200, array $headers = [])
    {
        $this->data = $data;
        $headers['Content-Type'] = 'application/json; charset=UTF-8';

        try {
            $jsonContent = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $fallbackJson = json_encode([
                'error' => true,
                'message' => 'JSON encoding failed: ' . $e->getMessage(),
                'timestamp' => date('c'),
            ]);
            $jsonContent = $fallbackJson !== false ? $fallbackJson : '{"error":true,"message":"Critical JSON encoding failure"}';
            $statusCode = 500;
        }

        parent::__construct($jsonContent, $statusCode, $headers);
    }

    /**
     * Get the original data before JSON encoding.
     */
    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Set new data and re-encode as JSON.
     *
     * @param mixed $data
     */
    /**
     * @param mixed $data
     */
    public function setData($data): self
    {
        $this->data = $data;

        try {
            $this->content = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $fallbackJson = json_encode([
                'error' => true,
                'message' => 'JSON encoding failed: ' . $e->getMessage(),
                'timestamp' => date('c'),
            ]);
            $this->content = $fallbackJson !== false ? $fallbackJson : '{"error":true,"message":"Critical JSON encoding failure"}';
            $this->statusCode = 500;
        }

        return $this;
    }
}
