<?php

declare(strict_types=1);

namespace EnviaShipping\Infrastructure\Logger;

use EnviaShipping\Infrastructure\Exception\EnviaException;

/**
 * Logger service wrapping PrestaShopLogger for Envia Shipping module.
 */
class EnviaLogger
{
    private const LOG_OBJECT_TYPE = 'EnviaShipping';
    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(private readonly bool $debugEnabled = false)
    {
    }

    /**
     * Log an informational message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(1, $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(2, $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(3, $message, $context);
    }

    /**
     * Log a debug message (only when debug mode is enabled).
     */
    public function debug(string $message, array $context = []): void
    {
        if (!$this->debugEnabled) {
            return;
        }
        $this->log(1, '[DEBUG] ' . $message, $context);
    }

    /**
     * Log an API request (sanitized – strips API key from headers).
     *
     * @param array<string, mixed> $payload
     */
    public function logApiRequest(string $endpoint, array $payload): void
    {
        $this->debug(
            sprintf('API Request → %s', $endpoint),
            ['payload' => $payload]
        );
    }

    /**
     * Log an API response.
     *
     * @param array<string, mixed> $response
     */
    public function logApiResponse(string $endpoint, array $response, int $statusCode): void
    {
        $this->debug(
            sprintf('API Response ← %s (HTTP %d)', $endpoint, $statusCode),
            ['response' => $response]
        );
    }

    /**
     * Log an exception with full stack trace.
     */
    public function logException(EnviaException $exception, string $context = ''): void
    {
        $message = sprintf(
            '[%s] %s: %s (code %d)',
            $context ?: 'Exception',
            get_class($exception),
            $exception->getMessage(),
            $exception->getCode()
        );

        $this->error($message, ['trace' => $exception->getTraceAsString()]);
    }

    /**
     * Log a cache event (hit or miss).
     */
    public function logCache(string $event, string $cacheKey): void
    {
        $this->debug(
            sprintf('Cache %s for key: %s', strtoupper($event), $cacheKey)
        );
    }

    /**
     * Log fallback activation.
     */
    public function logFallback(string $reason, float $fallbackPrice): void
    {
        $this->warning(
            sprintf(
                'Fallback rate activated (%.2f). Reason: %s',
                $fallbackPrice,
                $reason
            )
        );
    }

    /**
     * Send the log entry to PrestaShopLogger.
     *
     * @param array<string, mixed> $context
     */
    private function log(int $severity, string $message, array $context = []): void
    {
        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($contextStr !== false) {
                $message .= ' | Context: ' . $contextStr;
            }
        }

        $message = substr($message, 0, self::MAX_MESSAGE_LENGTH);

        try {
            \PrestaShopLogger::addLog(
                $message,
                $severity,
                null,
                self::LOG_OBJECT_TYPE,
                0,
                true
            );
        } catch (\Throwable) {
            // Logging must never break the checkout process
        }
    }
}
