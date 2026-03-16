<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// PSR-4 autoloader via Composer (loaded after install)
$autoloadFile = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
}

use EnviaShipping\Application\Service\CarrierMapper;
use EnviaShipping\Application\Service\ShippingQuoteService;
use EnviaShipping\Domain\Model\Address as EnviaAddress;
use EnviaShipping\Infrastructure\Api\EnviaApiClient;
use EnviaShipping\Infrastructure\Cache\QuoteCache;
use EnviaShipping\Infrastructure\Exception\EnviaConfigException;
use EnviaShipping\Infrastructure\Exception\EnviaException;
use EnviaShipping\Infrastructure\Logger\EnviaLogger;

/**
 * Envia Shipping – PrestaShop 9 carrier module.
 *
 * Dynamically retrieves shipping quotes from the Envia.com API
 * and presents them as a selectable carrier during checkout.
 *
 * @author  Sebastian Bolivar
 * @license AFL-3.0
 */
class EnviaShipping extends CarrierModule
{
    // ── Configuration keys ────────────────────────────────────────────────────
    public const CFG_API_KEY = 'ENVIA_API_KEY';
    public const CFG_ENVIRONMENT = 'ENVIA_ENVIRONMENT';
    public const CFG_ORIGIN_POSTAL = 'ENVIA_ORIGIN_POSTAL_CODE';
    public const CFG_ORIGIN_COUNTRY = 'ENVIA_ORIGIN_COUNTRY';
    public const CFG_ORIGIN_CITY = 'ENVIA_ORIGIN_CITY';
    public const CFG_ORIGIN_STATE = 'ENVIA_ORIGIN_STATE';
    public const CFG_DEFAULT_LENGTH = 'ENVIA_DEFAULT_LENGTH';
    public const CFG_DEFAULT_WIDTH = 'ENVIA_DEFAULT_WIDTH';
    public const CFG_DEFAULT_HEIGHT = 'ENVIA_DEFAULT_HEIGHT';
    public const CFG_VALUE_MULTIPLIER = 'ENVIA_VALUE_MULTIPLIER';
    public const CFG_PRICE_MARGIN = 'ENVIA_PRICE_MARGIN';
    public const CFG_DEBUG_LOGGING = 'ENVIA_DEBUG_LOGGING';
    public const CFG_API_TIMEOUT = 'ENVIA_API_TIMEOUT';
    public const CFG_CACHE_TTL = 'ENVIA_CACHE_TTL';
    public const CFG_FALLBACK_PRICE = 'ENVIA_FALLBACK_PRICE';
    public const CFG_CARRIER_ID = 'ENVIA_CARRIER_ID';

    /** Default values applied on install. */
    private const DEFAULT_CONFIG = [
        self::CFG_ENVIRONMENT => 'sandbox',
        self::CFG_DEFAULT_LENGTH => 20,
        self::CFG_DEFAULT_WIDTH => 15,
        self::CFG_DEFAULT_HEIGHT => 10,
        self::CFG_VALUE_MULTIPLIER => 1.0,
        self::CFG_PRICE_MARGIN => 0,
        self::CFG_API_TIMEOUT => 10,
        self::CFG_CACHE_TTL => 10,
        self::CFG_FALLBACK_PRICE => 0,
        self::CFG_DEBUG_LOGGING => 0,
    ];

    public function __construct()
    {
        $this->name = 'envia_shipping';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Sebastian Bolivar';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Envia Shipping');
        $this->description = $this->l('Real-time shipping quotes via the Envia.com API.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Envia Shipping? Carrier data will be removed.');
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function install(): bool
    {
        return parent::install()
            && $this->registerHooks()
            && $this->setDefaultConfiguration()
            && $this->installCarrier()
            && $this->installTabs();
    }

    public function uninstall(): bool
    {
        return $this->uninstallCarrier()
            && $this->uninstallTabs()
            && $this->deleteConfiguration()
            && parent::uninstall();
    }

    // ── Hooks ─────────────────────────────────────────────────────────────────

    /**
     * Core hook: returns dynamic shipping cost for the carrier created by this module.
     *
     * @param array<string, mixed> $params
     */
    public function getOrderShippingCost(mixed $params, float $shipping_cost): float|false
    {
        /** @var Cart $cart */
        $cart = $params['cart'] ?? null;
        if (!($cart instanceof Cart)) {
            return false;
        }

        try {
            $quotes = $this->fetchQuotesForCart($cart);
            if (empty($quotes)) {
                $fallback = (float) Configuration::get(self::CFG_FALLBACK_PRICE);
                return $fallback > 0 ? $fallback : false;
            }

            return $this->getQuoteService()->getCheapestPrice($quotes);
        } catch (EnviaException $e) {
            $this->getLogger()->logException($e, 'getOrderShippingCost');
            $fallback = (float) Configuration::get(self::CFG_FALLBACK_PRICE);
            return $fallback > 0 ? $fallback : false;
        }
    }

    /**
     * Core hook: external shipping cost calculation (required by CarrierModule).
     *
     * @param array<string, mixed> $params
     */
    public function getOrderShippingCostExternal(mixed $params): float|false
    {
        return $this->getOrderShippingCost($params, 0.0);
    }

    /**
     * Hook: called when a carrier is selected or when carrier list is refreshed.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionCarrierProcess(array $params): void
    {
        // Nothing extra needed – shipping cost is calculated via getOrderShippingCost
    }

    /**
     * Hook: invalidate the quote cache when the cart changes.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionCartSave(array $params): void
    {
        $cart = $params['cart'] ?? null;
        if (!($cart instanceof Cart) || !$cart->id) {
            return;
        }

        // Flush any cached quotes that include this cart ID
        $this->getQuoteCache()->flush();
        $this->getLogger()->debug('Quote cache flushed on cart save.', ['cart_id' => $cart->id]);
    }

    /**
     * Hook: display extra information (delivery time, service name) on carrier selection.
     *
     * @param array<string, mixed> $params
     *
     * @return string HTML output
     */
    public function hookDisplayCarrierExtraContent(array $params): string
    {
        /** @var Carrier|null $carrier */
        $carrier = $params['carrier'] ?? null;
        if (!($carrier instanceof Carrier)) {
            return '';
        }

        // Only enrich our own carrier
        $myCarrierId = (int) Configuration::get(self::CFG_CARRIER_ID);
        if ($carrier->id !== $myCarrierId) {
            return '';
        }

        /** @var Cart|null $cart */
        $cart = $params['cart'] ?? $this->context->cart ?? null;
        if (!($cart instanceof Cart)) {
            return '';
        }

        try {
            $quotes = $this->fetchQuotesForCart($cart);
            if (empty($quotes)) {
                return '';
            }

            $cheapest = $quotes[0] ?? [];
            $serviceName = htmlspecialchars((string)($cheapest['service_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $deliveryTime = htmlspecialchars((string)($cheapest['delivery_time'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html = '';
            if ($serviceName) {
                $html .= '<span class="envia-service-name">' . $serviceName . '</span>';
            }
            if ($deliveryTime) {
                $html .= ' <span class="envia-delivery-time">(' . $this->l('Estimated:') . ' ' . $deliveryTime . ')</span>';
            }

            return $html;
        } catch (EnviaException) {
            return '';
        }
    }

    /**
     * Hook: log shipping data when an order is validated.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        /** @var Order|null $order */
        $order = $params['order'] ?? null;
        if (!($order instanceof Order)) {
            return;
        }

        $this->getLogger()->info(
            'Order validated with Envia Shipping.',
            [
                'order_id' => $order->id,
                'carrier_id' => $order->id_carrier,
                'total_shipping' => $order->total_shipping,
            ]
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Fetch shipping quotes for the given cart, using cache where possible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchQuotesForCart(Cart $cart): array
    {
        $origin = $this->buildOriginAddress();

        $address = new \Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            $this->getLogger()->warning('Delivery address not found for cart.', ['cart_id' => $cart->id]);
            return [];
        }

        $country = Country::getIsoById((int) $address->id_country);
        $destination = new EnviaAddress(
            postalCode: (string)($address->postcode ?: ''),
            country: is_string($country) ? $country : 'MX',
            city: (string)($address->city ?: ''),
            state: '',
        );

        $cartWeight = (float) $cart->getTotalWeight();
        $cartTotal = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);

        $serviceInstance = $this->getQuoteService();

        $package = $serviceInstance->buildPackageFromCart(
            ['weight' => $cartWeight, 'declared_value' => $cartTotal],
            (float)(Configuration::get(self::CFG_DEFAULT_LENGTH) ?: 20),
            (float)(Configuration::get(self::CFG_DEFAULT_WIDTH) ?: 15),
            (float)(Configuration::get(self::CFG_DEFAULT_HEIGHT) ?: 10),
            (float)(Configuration::get(self::CFG_VALUE_MULTIPLIER) ?: 1.0),
        );

        return $serviceInstance->getQuotes($origin, $destination, $package, (int) $cart->id);
    }

    /**
     * Build the origin Address from module configuration.
     *
     * @throws EnviaConfigException
     */
    private function buildOriginAddress(): EnviaAddress
    {
        $postalCode = (string)(Configuration::get(self::CFG_ORIGIN_POSTAL) ?: '');
        $country = (string)(Configuration::get(self::CFG_ORIGIN_COUNTRY) ?: '');

        if (empty($postalCode) || empty($country)) {
            throw new EnviaConfigException('Origin address (postal code / country) is not configured.');
        }

        return new EnviaAddress(
            postalCode: $postalCode,
            country: $country,
            city: (string)(Configuration::get(self::CFG_ORIGIN_CITY) ?: ''),
            state: (string)(Configuration::get(self::CFG_ORIGIN_STATE) ?: ''),
        );
    }

    // ── Service factory helpers ───────────────────────────────────────────────

    private function getLogger(): EnviaLogger
    {
        static $logger = null;
        if ($logger === null) {
            $logger = new EnviaLogger((bool) Configuration::get(self::CFG_DEBUG_LOGGING));
        }
        return $logger;
    }

    private function getQuoteCache(): QuoteCache
    {
        static $cache = null;
        if ($cache === null) {
            $ttlMinutes = (int)(Configuration::get(self::CFG_CACHE_TTL) ?: 10);
            $cache = new QuoteCache($ttlMinutes * 60);
        }
        return $cache;
    }

    private function getQuoteService(): ShippingQuoteService
    {
        static $service = null;
        if ($service === null) {
            $apiKey = (string)(Configuration::get(self::CFG_API_KEY) ?: '');
            $sandbox = Configuration::get(self::CFG_ENVIRONMENT) !== 'production';
            $timeout = (int)(Configuration::get(self::CFG_API_TIMEOUT) ?: 10);
            $margin = (float)(Configuration::get(self::CFG_PRICE_MARGIN) ?: 0);
            $fallback = (float)(Configuration::get(self::CFG_FALLBACK_PRICE) ?: 0);
            $logger = $this->getLogger();
            $cache = $this->getQuoteCache();

            $apiClient = new EnviaApiClient($apiKey, $sandbox, $timeout, $logger);
            $mapper = new CarrierMapper();

            $service = new ShippingQuoteService($apiClient, $mapper, $cache, $logger, $fallback, $margin);
        }
        return $service;
    }

    // ── Installation helpers ──────────────────────────────────────────────────

    private function registerHooks(): bool
    {
        return $this->registerHook('actionCarrierProcess')
            && $this->registerHook('actionCartSave')
            && $this->registerHook('displayCarrierExtraContent')
            && $this->registerHook('actionValidateOrder');
    }

    private function setDefaultConfiguration(): bool
    {
        foreach (self::DEFAULT_CONFIG as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        return true;
    }

    private function installCarrier(): bool
    {
        $carrier = new Carrier();
        $carrier->name = 'Envia Shipping';
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        $carrier->is_module = true;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;

        foreach (Language::getLanguages() as $language) {
            $carrier->delay[(int)$language['id_lang']] = $this->l('Delivery time varies by service');
        }

        if (!$carrier->add()) {
            return false;
        }

        // Copy module logo to carrier image directory if logo exists
        $logoSrc = __DIR__ . '/logo.png';
        if (file_exists($logoSrc) && defined('_PS_SHIP_IMG_DIR_')) {
            @copy($logoSrc, _PS_SHIP_IMG_DIR_ . $carrier->id . '.jpg');
        }

        // Enable carrier for all zones
        $zones = Zone::getZones();
        foreach ($zones as $zone) {
            $carrier->addZone((int)$zone['id_zone']);
        }

        // Add a default weight range (0 to 9999 kg)
        $rangeWeight = new RangeWeight();
        $rangeWeight->id_carrier = (int)$carrier->id;
        $rangeWeight->delimiter1 = '0';
        $rangeWeight->delimiter2 = '9999';
        $rangeWeight->add();

        // Associate with all groups
        $groups = Group::getGroups(Context::getContext()->language->id);
        foreach ($groups as $group) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int)$carrier->id,
                'id_group' => (int)$group['id_group'],
            ]);
        }

        Configuration::updateValue(self::CFG_CARRIER_ID, (int)$carrier->id);

        return true;
    }

    private function uninstallCarrier(): bool
    {
        $carrierId = (int) Configuration::get(self::CFG_CARRIER_ID);
        if ($carrierId > 0) {
            $carrier = new Carrier($carrierId);
            if (Validate::isLoadedObject($carrier)) {
                $carrier->active = false;
                $carrier->deleted = true;
                $carrier->update();
            }
        }
        return true;
    }

    private function installTabs(): bool
    {
        $tabParentId = (int) Tab::getIdFromClassName('AdminParentShipping');

        // Main menu entry
        $tab = new Tab();
        $tab->active = true;
        $tab->class_name = 'AdminEnviaDashboard';
        $tab->id_parent = $tabParentId;
        $tab->module = $this->name;
        $tab->icon = 'local_shipping';

        foreach (Language::getLanguages() as $lang) {
            $tab->name[(int)$lang['id_lang']] = $this->l('Envia Shipping');
        }

        if (!$tab->add()) {
            return false;
        }

        // Configuration sub-tab
        $subTab = new Tab();
        $subTab->active = true;
        $subTab->class_name = 'AdminEnviaConfig';
        $subTab->id_parent = (int) $tab->id;
        $subTab->module = $this->name;

        foreach (Language::getLanguages() as $lang) {
            $subTab->name[(int)$lang['id_lang']] = $this->l('Configuration');
        }

        return $subTab->add();
    }

    private function uninstallTabs(): bool
    {
        foreach (['AdminEnviaDashboard', 'AdminEnviaConfig'] as $className) {
            $id = (int) Tab::getIdFromClassName($className);
            if ($id > 0) {
                $tab = new Tab($id);
                $tab->delete();
            }
        }
        return true;
    }

    private function deleteConfiguration(): bool
    {
        $keys = array_merge(
            array_keys(self::DEFAULT_CONFIG),
            [
                self::CFG_API_KEY,
                self::CFG_ORIGIN_POSTAL,
                self::CFG_ORIGIN_COUNTRY,
                self::CFG_ORIGIN_CITY,
                self::CFG_ORIGIN_STATE,
                self::CFG_CARRIER_ID,
            ]
        );

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    // ── Module configuration page (redirect to dedicated controller) ──────────

    /**
     * Render the module configuration page called from Modules list.
     */
    public function getContent(): string
    {
        $configUrl = $this->context->link->getAdminLink('AdminEnviaConfig');
        Tools::redirectAdmin($configUrl);
        return '';
    }
}
