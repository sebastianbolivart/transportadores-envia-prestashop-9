<?php

declare(strict_types=1);

namespace EnviaShipping\Infrastructure\Exception;

/**
 * Exception thrown when the Envia API returns an error or is unreachable.
 */
class EnviaApiException extends EnviaException
{
    private int $httpStatusCode;

    /** @var array<string, mixed> */
    private array $responseBody;

    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message = '',
        int $httpStatusCode = 0,
        array $responseBody = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->httpStatusCode = $httpStatusCode;
        $this->responseBody = $responseBody;
        parent::__construct($message, $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
