<?php

declare(strict_types=1);

namespace EnviaShipping\Infrastructure\Api;

use EnviaShipping\Infrastructure\Exception\EnviaApiException;
use EnviaShipping\Infrastructure\Exception\EnviaConfigException;
use EnviaShipping\Infrastructure\Logger\EnviaLogger;

/**
 * HTTP client for the Envia.com Shipping API.
 *
 * Handles authentication, request sending, response validation and retry logic.
 */
class EnviaApiClient
{
    private const SANDBOX_BASE_URL = 'https://api-test.envia.com/';
    private const PRODUCTION_BASE_URL = 'https://api.envia.com/';
    private const QUOTE_ENDPOINT = 'ship/rate/';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_US = 500_000; // 0.5 s

    /** @var array<string, string> */
    private array $defaultHeaders;

    public function __construct(
        private readonly string $apiKey,
        private readonly bool $sandbox = true,
        private readonly int $timeoutSeconds = 10,
        private readonly EnviaLogger $logger = new EnviaLogger()
    ) {
        if (empty(trim($this->apiKey))) {
            throw new EnviaConfigException('Envia API key is not configured.');
        }

        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Request shipping quotes from the Envia API.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<int, array<string, mixed>> List of carrier quote objects
     *
     * @throws EnviaApiException
     */
    public function getShippingRates(array $payload): array
    {
        $endpoint = $this->getBaseUrl() . self::QUOTE_ENDPOINT;

        $this->logger->logApiRequest($endpoint, $payload);

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; ++$attempt) {
            try {
                $response = $this->sendRequest('POST', $endpoint, $payload);
                $this->logger->logApiResponse($endpoint, $response, 200);
                return $this->parseRatesResponse($response);
            } catch (EnviaApiException $e) {
                $lastException = $e;

                // Do not retry on client errors (4xx)
                if ($e->getHttpStatusCode() >= 400 && $e->getHttpStatusCode() < 500) {
                    break;
                }

                if ($attempt < self::MAX_RETRIES) {
                    $this->logger->warning(
                        sprintf('API attempt %d/%d failed, retrying... Error: %s', $attempt, self::MAX_RETRIES, $e->getMessage())
                    );
                    usleep(self::RETRY_DELAY_US * $attempt);
                }
            }
        }

        throw $lastException ?? new EnviaApiException('Unknown API error after retries.');
    }

    /**
     * Send an HTTP request using PHP's built-in stream context (no external dependency).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws EnviaApiException
     */
    private function sendRequest(string $method, string $url, array $payload): array
    {
        $jsonBody = json_encode($payload);
        if ($jsonBody === false) {
            throw new EnviaApiException('Failed to encode request payload as JSON.');
        }

        $headers = $this->defaultHeaders;
        $headers['Content-Length'] = (string) strlen($jsonBody);

        $headerLines = array_map(
            fn(string $key, string $value): string => "{$key}: {$value}",
            array_keys($headers),
            array_values($headers)
        );

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $jsonBody,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $rawResponse = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        $statusCode = $this->extractStatusCode($responseHeaders);

        if ($rawResponse === false) {
            throw new EnviaApiException(
                sprintf('Failed to connect to Envia API at %s', $url),
                0
            );
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new EnviaApiException(
                'Envia API returned invalid JSON response.',
                $statusCode,
                []
            );
        }

        if ($statusCode >= 400) {
            $errorMessage = (string)($decoded['message'] ?? $decoded['error'] ?? "HTTP error {$statusCode}");
            throw new EnviaApiException(
                sprintf('Envia API error (HTTP %d): %s', $statusCode, $errorMessage),
                $statusCode,
                $decoded
            );
        }

        return $decoded;
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param string[] $headers
     */
    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/\d+\.?\d*\s+(\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }

    /**
     * Parse and validate the rates response from the API.
     *
     * @param array<string, mixed> $response
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws EnviaApiException
     */
    private function parseRatesResponse(array $response): array
    {
        // Envia API wraps results in a 'data' key
        $data = $response['data'] ?? $response;

        if (!is_array($data)) {
            throw new EnviaApiException('Unexpected API response structure: "data" key is missing or not an array.');
        }

        // Ensure each item has the minimum required fields
        $validated = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!isset($item['totalPrice']) && !isset($item['total_price'])) {
                continue; // Skip malformed entries
            }

            $validated[] = $item;
        }

        return $validated;
    }

    private function getBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }
}
