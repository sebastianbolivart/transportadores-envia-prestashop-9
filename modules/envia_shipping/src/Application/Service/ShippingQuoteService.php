<?php

declare(strict_types=1);

namespace EnviaShipping\Application\Service;

use EnviaShipping\Domain\Model\Address;
use EnviaShipping\Domain\Model\Package;
use EnviaShipping\Infrastructure\Api\EnviaApiClient;
use EnviaShipping\Infrastructure\Cache\QuoteCache;
use EnviaShipping\Infrastructure\Exception\EnviaApiException;
use EnviaShipping\Infrastructure\Exception\EnviaConfigException;
use EnviaShipping\Infrastructure\Logger\EnviaLogger;

/**
 * Orchestrates shipping quote retrieval from the Envia API.
 *
 * Responsibilities:
 * - Build request payload from cart + configuration data
 * - Check / populate quote cache
 * - Invoke EnviaApiClient
 * - Apply margin via CarrierMapper
 * - Provide fallback on API failure
 */
class ShippingQuoteService
{
    public function __construct(
        private readonly EnviaApiClient $apiClient,
        private readonly CarrierMapper $carrierMapper,
        private readonly QuoteCache $quoteCache,
        private readonly EnviaLogger $logger,
        private readonly float $fallbackPrice = 0.0,
        private readonly float $marginPercent = 0.0,
    ) {
    }

    /**
     * Retrieve shipping quotes for a given cart, origin and package spec.
     *
     * Returns an array of normalised carrier objects (see CarrierMapper::mapRatesToCarriers).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQuotes(
        Address $origin,
        Address $destination,
        Package $package,
        int $cartId = 0
    ): array {
        $cacheKey = QuoteCache::buildKey([
            'cart_id' => $cartId,
            'dest_postal' => $destination->getPostalCode(),
            'weight' => round($package->getWeight(), 2),
            'value' => round($package->getDeclaredValue(), 0),
            'origin' => $origin->getPostalCode(),
        ]);

        // 1. Try fresh cache
        $cached = $this->quoteCache->get($cacheKey);
        if ($cached !== null) {
            $this->logger->logCache('hit', $cacheKey);
            return $cached;
        }

        $this->logger->logCache('miss', $cacheKey);

        // 2. Call API
        $payload = $this->carrierMapper->buildRequestPayload($origin, $destination, $package);

        try {
            $rawRates = $this->apiClient->getShippingRates($payload);
            $rates = $this->carrierMapper->mapRatesToCarriers($rawRates, $this->marginPercent);

            $this->quoteCache->set($cacheKey, $rates);
            return $rates;
        } catch (EnviaApiException $e) {
            $this->logger->logException($e, 'ShippingQuoteService::getQuotes');

            // 3. Stale cache as backup
            $stale = $this->quoteCache->getStale($cacheKey);
            if ($stale !== null) {
                $this->logger->warning('Using stale cache due to API error.', ['cache_key' => $cacheKey]);
                return $stale;
            }

            // 4. Flat-rate fallback
            if ($this->fallbackPrice > 0) {
                $this->logger->logFallback($e->getMessage(), $this->fallbackPrice);
                return $this->buildFallbackResponse();
            }

            return [];
        }
    }

    /**
     * Build a package value object from PrestaShop cart data and module configuration.
     *
     * @param array{
     *     weight: float,
     *     length?: float,
     *     width?: float,
     *     height?: float,
     *     declared_value?: float,
     * } $cartData
     */
    public function buildPackageFromCart(
        array $cartData,
        float $defaultLength,
        float $defaultWidth,
        float $defaultHeight,
        float $valueMultiplier = 1.0
    ): Package {
        $weight = max(0.1, (float)($cartData['weight'] ?? 0.1));
        $length = (float)($cartData['length'] ?? $defaultLength);
        $width = (float)($cartData['width'] ?? $defaultWidth);
        $height = (float)($cartData['height'] ?? $defaultHeight);
        $declaredValue = (float)($cartData['declared_value'] ?? 0.0) * max(1.0, $valueMultiplier);

        return new Package(
            weight: max(0.1, $weight),
            length: max(1.0, $length),
            width: max(1.0, $width),
            height: max(1.0, $height),
            declaredValue: max(0.0, $declaredValue),
        );
    }

    /**
     * Return the cheapest price from a set of quotes, or the fallback price.
     *
     * @param array<int, array<string, mixed>> $quotes
     */
    public function getCheapestPrice(array $quotes): float
    {
        if (empty($quotes)) {
            return $this->fallbackPrice > 0 ? $this->fallbackPrice : 0.0;
        }

        $prices = array_column($quotes, 'price');
        $prices = array_filter($prices, fn($p) => is_numeric($p) && $p > 0);

        return empty($prices) ? $this->fallbackPrice : (float) min($prices);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFallbackResponse(): array
    {
        return [
            [
                'carrier_id' => 'fallback',
                'carrier_name' => 'Standard Shipping',
                'service_name' => 'Flat Rate',
                'price' => $this->fallbackPrice,
                'currency' => 'MXN',
                'delivery_time' => '',
                'logo_url' => '',
            ],
        ];
    }
}
