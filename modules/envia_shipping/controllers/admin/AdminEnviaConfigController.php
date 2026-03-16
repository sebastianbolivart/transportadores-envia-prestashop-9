<?php

declare(strict_types=1);

/**
 * Admin configuration controller for the Envia Shipping module.
 *
 * Accessible from: Admin → Shipping → Envia Shipping → Configuration
 */
class AdminEnviaConfigController extends ModuleAdminController
{
    /** Configuration keys */
    private const CONFIG_FIELDS = [
        'ENVIA_API_KEY',
        'ENVIA_ENVIRONMENT',
        'ENVIA_ORIGIN_POSTAL_CODE',
        'ENVIA_ORIGIN_COUNTRY',
        'ENVIA_ORIGIN_CITY',
        'ENVIA_ORIGIN_STATE',
        'ENVIA_DEFAULT_LENGTH',
        'ENVIA_DEFAULT_WIDTH',
        'ENVIA_DEFAULT_HEIGHT',
        'ENVIA_VALUE_MULTIPLIER',
        'ENVIA_PRICE_MARGIN',
        'ENVIA_DEBUG_LOGGING',
        'ENVIA_API_TIMEOUT',
        'ENVIA_CACHE_TTL',
        'ENVIA_FALLBACK_PRICE',
    ];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        parent::__construct();

        $this->meta_title = $this->l('Envia Shipping – Configuration');
    }

    public function initContent(): void
    {
        parent::initContent();

        $helper = $this->buildHelperForm();
        $form = $this->buildFormDefinition();

        $this->context->smarty->assign([
            'configuration_form' => $helper->generateForm([$form]),
            'back_url' => $this->context->link->getAdminLink('AdminEnviaDashboard'),
        ]);

        $this->setTemplate('module:envia_shipping/views/templates/admin/configure.tpl');
    }

    public function postProcess(): void
    {
        if (Tools::isSubmit('submitEnviaConfig')) {
            $this->saveConfiguration();
        }

        parent::postProcess();
    }

    private function saveConfiguration(): void
    {
        // API Key – trim but do not sanitise (it's a token, not HTML)
        $apiKey = trim((string) Tools::getValue('ENVIA_API_KEY'));
        if (!empty($apiKey)) {
            Configuration::updateValue('ENVIA_API_KEY', $apiKey);
        }

        $environment = Tools::getValue('ENVIA_ENVIRONMENT') === 'production' ? 'production' : 'sandbox';
        Configuration::updateValue('ENVIA_ENVIRONMENT', $environment);

        // Origin address
        $postalCode = pSQL(trim((string) Tools::getValue('ENVIA_ORIGIN_POSTAL_CODE')));
        $country = strtoupper(pSQL(trim((string) Tools::getValue('ENVIA_ORIGIN_COUNTRY'))));
        $city = pSQL(trim((string) Tools::getValue('ENVIA_ORIGIN_CITY')));
        $state = pSQL(trim((string) Tools::getValue('ENVIA_ORIGIN_STATE')));

        if (!preg_match('/^[A-Z]{2}$/i', $country)) {
            $this->errors[] = $this->l('Invalid origin country code. Must be a 2-letter ISO code (e.g. MX, CO).');
            return;
        }

        Configuration::updateValue('ENVIA_ORIGIN_POSTAL_CODE', $postalCode);
        Configuration::updateValue('ENVIA_ORIGIN_COUNTRY', $country);
        Configuration::updateValue('ENVIA_ORIGIN_CITY', $city);
        Configuration::updateValue('ENVIA_ORIGIN_STATE', $state);

        // Package dimensions
        $length = max(1.0, (float) Tools::getValue('ENVIA_DEFAULT_LENGTH'));
        $width = max(1.0, (float) Tools::getValue('ENVIA_DEFAULT_WIDTH'));
        $height = max(1.0, (float) Tools::getValue('ENVIA_DEFAULT_HEIGHT'));
        Configuration::updateValue('ENVIA_DEFAULT_LENGTH', $length);
        Configuration::updateValue('ENVIA_DEFAULT_WIDTH', $width);
        Configuration::updateValue('ENVIA_DEFAULT_HEIGHT', $height);

        // Numeric settings
        $valueMultiplier = max(1.0, (float) Tools::getValue('ENVIA_VALUE_MULTIPLIER'));
        $priceMargin = max(0.0, min(100.0, (float) Tools::getValue('ENVIA_PRICE_MARGIN')));
        $apiTimeout = max(5, min(60, (int) Tools::getValue('ENVIA_API_TIMEOUT')));
        $cacheTtl = max(1, min(1440, (int) Tools::getValue('ENVIA_CACHE_TTL')));
        $fallbackPrice = max(0.0, (float) Tools::getValue('ENVIA_FALLBACK_PRICE'));

        Configuration::updateValue('ENVIA_VALUE_MULTIPLIER', $valueMultiplier);
        Configuration::updateValue('ENVIA_PRICE_MARGIN', $priceMargin);
        Configuration::updateValue('ENVIA_API_TIMEOUT', $apiTimeout);
        Configuration::updateValue('ENVIA_CACHE_TTL', $cacheTtl);
        Configuration::updateValue('ENVIA_FALLBACK_PRICE', $fallbackPrice);

        // Boolean settings
        $debugLogging = (bool) Tools::getValue('ENVIA_DEBUG_LOGGING');
        Configuration::updateValue('ENVIA_DEBUG_LOGGING', (int) $debugLogging);

        $this->confirmations[] = $this->l('Configuration saved successfully.');
    }

    private function buildHelperForm(): HelperForm
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = 'configuration';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?: 0;
        $helper->identifier = 'id_configuration';
        $helper->submit_action = 'submitEnviaConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminEnviaConfig');
        $helper->token = $this->token;

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper;
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigValues(): array
    {
        $values = [];
        foreach (self::CONFIG_FIELDS as $key) {
            $values[$key] = Configuration::get($key);
        }
        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFormDefinition(): array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Envia Shipping Configuration'),
                    'icon' => 'icon-truck',
                ],
                'input' => [
                    // API
                    [
                        'type' => 'text',
                        'label' => $this->l('Envia API Key'),
                        'name' => 'ENVIA_API_KEY',
                        'required' => true,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->l('Bearer token from your Envia.com account.'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Environment'),
                        'name' => 'ENVIA_ENVIRONMENT',
                        'options' => [
                            'query' => [
                                ['id' => 'sandbox', 'name' => $this->l('Sandbox (Testing)')],
                                ['id' => 'production', 'name' => $this->l('Production (Live)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Use Sandbox for testing. Switch to Production when ready.'),
                    ],
                    // Origin
                    [
                        'type' => 'text',
                        'label' => $this->l('Origin Postal Code'),
                        'name' => 'ENVIA_ORIGIN_POSTAL_CODE',
                        'required' => true,
                        'class' => 'fixed-width-md',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Origin Country (ISO 2)'),
                        'name' => 'ENVIA_ORIGIN_COUNTRY',
                        'required' => true,
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('e.g. MX, CO, US'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Origin City'),
                        'name' => 'ENVIA_ORIGIN_CITY',
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Origin State / Province'),
                        'name' => 'ENVIA_ORIGIN_STATE',
                        'class' => 'fixed-width-lg',
                    ],
                    // Package defaults
                    [
                        'type' => 'text',
                        'label' => $this->l('Default Package Length (cm)'),
                        'name' => 'ENVIA_DEFAULT_LENGTH',
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Default Package Width (cm)'),
                        'name' => 'ENVIA_DEFAULT_WIDTH',
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Default Package Height (cm)'),
                        'name' => 'ENVIA_DEFAULT_HEIGHT',
                        'class' => 'fixed-width-sm',
                    ],
                    // Pricing
                    [
                        'type' => 'text',
                        'label' => $this->l('Declared Value Multiplier'),
                        'name' => 'ENVIA_VALUE_MULTIPLIER',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Multiplier applied to cart total for declared value (default 1.0).'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping Price Margin (%)'),
                        'name' => 'ENVIA_PRICE_MARGIN',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Percentage markup on API-quoted prices (e.g. 10 = +10%).'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Fallback Flat Rate Price'),
                        'name' => 'ENVIA_FALLBACK_PRICE',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Price charged when the API is unavailable. Set 0 to disable carrier if API fails.'),
                    ],
                    // Technical
                    [
                        'type' => 'text',
                        'label' => $this->l('API Timeout (seconds)'),
                        'name' => 'ENVIA_API_TIMEOUT',
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Cache TTL (minutes)'),
                        'name' => 'ENVIA_CACHE_TTL',
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable Debug Logging'),
                        'name' => 'ENVIA_DEBUG_LOGGING',
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Writes detailed API request/response logs to PrestaShop logger.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];
    }
}
