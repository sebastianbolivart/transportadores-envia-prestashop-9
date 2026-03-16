<?php

declare(strict_types=1);

namespace EnviaShipping\Application\Service;

use EnviaShipping\Domain\Model\Address;
use EnviaShipping\Domain\Model\Package;

/**
 * Maps Envia API response objects to PrestaShop-compatible carrier data.
 */
class CarrierMapper
{
    /**
     * Transform a list of Envia rate objects into a normalised array
     * ready to be consumed by the shipping module.
     *
     * @param array<int, array<string, mixed>> $apiRates
     * @param float $marginPercent    Percentage markup to apply (e.g. 10 = +10 %)
     *
     * @return array<int, array{
     *     carrier_id: string,
     *     carrier_name: string,
     *     service_name: string,
     *     price: float,
     *     currency: string,
     *     delivery_time: string,
     *     logo_url: string,
     * }>
     */
    public function mapRatesToCarriers(array $apiRates, float $marginPercent = 0.0): array
    {
        $carriers = [];

        foreach ($apiRates as $rate) {
            $price = $this->extractPrice($rate);
            if ($price <= 0) {
                continue;
            }

            $price = $this->applyMargin($price, $marginPercent);

            $carriers[] = [
                'carrier_id' => $this->extractString($rate, ['carrierId', 'carrier_id', 'id'], ''),
                'carrier_name' => $this->extractString($rate, ['carrier', 'carrierName', 'carrier_name'], 'Envia'),
                'service_name' => $this->extractString($rate, ['service', 'serviceName', 'service_name', 'name'], 'Standard'),
                'price' => round($price, 2),
                'currency' => $this->extractString($rate, ['currency', 'currencyCode'], 'MXN'),
                'delivery_time' => $this->extractDeliveryTime($rate),
                'logo_url' => $this->extractString($rate, ['logoUrl', 'logo_url', 'logo'], ''),
            ];
        }

        // Sort by price ascending
        usort($carriers, fn(array $a, array $b) => $a['price'] <=> $b['price']);

        return $carriers;
    }

    /**
     * Build the Envia API request payload from domain objects.
     *
     * @return array<string, mixed>
     */
    public function buildRequestPayload(Address $origin, Address $destination, Package $package): array
    {
        return [
            'origin' => $origin->toArray(),
            'destination' => $destination->toArray(),
            'packages' => [$package->toArray()],
        ];
    }

    /**
     * Extract the total price from an Envia rate object.
     *
     * @param array<string, mixed> $rate
     */
    private function extractPrice(array $rate): float
    {
        foreach (['totalPrice', 'total_price', 'price', 'amount'] as $key) {
            if (isset($rate[$key]) && (is_int($rate[$key]) || is_float($rate[$key]))) {
                return (float) $rate[$key];
            }
        }
        return 0.0;
    }

    /**
     * Extract a human-readable delivery time estimate.
     *
     * @param array<string, mixed> $rate
     */
    private function extractDeliveryTime(array $rate): string
    {
        foreach (['deliveryTime', 'delivery_time', 'days', 'estimatedDays'] as $key) {
            if (isset($rate[$key])) {
                $val = $rate[$key];
                if (is_int($val) || is_float($val)) {
                    return (int)$val . ' day(s)';
                }
                if (is_string($val)) {
                    return $val;
                }
            }
        }
        return '';
    }

    /**
     * Extract a string value trying multiple possible key names.
     *
     * @param array<string, mixed> $data
     * @param string[] $keys
     */
    private function extractString(array $data, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    /**
     * Apply a percentage margin to a price.
     */
    private function applyMargin(float $price, float $marginPercent): float
    {
        if ($marginPercent <= 0) {
            return $price;
        }
        return $price * (1 + $marginPercent / 100);
    }
}
