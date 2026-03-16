<?php

declare(strict_types=1);

namespace EnviaShipping\Domain\Model;

/**
 * Value object representing a package to be shipped.
 */
final class Package
{
    public function __construct(
        private readonly float $weight,
        private readonly float $length,
        private readonly float $width,
        private readonly float $height,
        private readonly float $declaredValue = 0.0,
    ) {
        if ($this->weight <= 0) {
            throw new \InvalidArgumentException('Package weight must be greater than zero.');
        }
        if ($this->length <= 0 || $this->width <= 0 || $this->height <= 0) {
            throw new \InvalidArgumentException('Package dimensions must be greater than zero.');
        }
        if ($this->declaredValue < 0) {
            throw new \InvalidArgumentException('Declared value cannot be negative.');
        }
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getLength(): float
    {
        return $this->length;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getDeclaredValue(): float
    {
        return $this->declaredValue;
    }

    /**
     * Calculate volumetric weight (length × width × height / divisor).
     */
    public function getVolumetricWeight(float $divisor = 5000.0): float
    {
        return ($this->length * $this->width * $this->height) / $divisor;
    }

    /**
     * @return array{weight: float, length: float, width: float, height: float, declaredValue: float}
     */
    public function toArray(): array
    {
        return [
            'weight' => $this->weight,
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'declaredValue' => $this->declaredValue,
        ];
    }
}
