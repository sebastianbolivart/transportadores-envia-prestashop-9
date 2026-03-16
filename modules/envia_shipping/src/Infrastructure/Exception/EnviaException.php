<?php

declare(strict_types=1);

namespace EnviaShipping\Infrastructure\Exception;

/**
 * Base exception for all Envia Shipping module errors.
 */
class EnviaException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
