<?php

declare(strict_types=1);

namespace EnviaShipping\Domain\Model;

/**
 * Value object representing a shipping address.
 */
final class Address
{
    public function __construct(
        private readonly string $postalCode,
        private readonly string $country,
        private readonly string $city = '',
        private readonly string $state = '',
        private readonly string $street = '',
    ) {
        if (empty(trim($this->postalCode))) {
            throw new \InvalidArgumentException('Postal code cannot be empty.');
        }

        if (!preg_match('/^[A-Z]{2}$/i', $this->country)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid country code: "%s". Must be a 2-letter ISO code.', $this->country)
            );
        }
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountry(): string
    {
        return strtoupper($this->country);
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    /**
     * @return array{postalCode: string, country: string}
     */
    public function toArray(): array
    {
        return [
            'postalCode' => $this->postalCode,
            'country' => $this->getCountry(),
        ];
    }
}
