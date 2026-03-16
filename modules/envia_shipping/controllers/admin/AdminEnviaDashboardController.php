<?php

declare(strict_types=1);

use EnviaShipping\Infrastructure\Cache\QuoteCache;

/**
 * Admin dashboard controller for the Envia Shipping module.
 *
 * Accessible from: Admin → Shipping → Envia Shipping → Dashboard
 */
class AdminEnviaDashboardController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        parent::__construct();

        $this->meta_title = $this->l('Envia Shipping – Dashboard');
    }

    /**
     * @return void
     */
    public function initContent(): void
    {
        parent::initContent();

        $cache = $this->getCache();

        $this->context->smarty->assign([
            'envia_module_version' => $this->module->version ?? '1.0.0',
            'envia_environment' => Configuration::get('ENVIA_ENVIRONMENT') ?: 'sandbox',
            'envia_api_configured' => !empty(Configuration::get('ENVIA_API_KEY')),
            'envia_carrier_id' => (int) Configuration::get('ENVIA_CARRIER_ID'),
            'envia_carrier_active' => $this->isCarrierActive(),
            'envia_cache_count' => $cache->count(),
            'envia_cache_ttl' => (int)(Configuration::get('ENVIA_CACHE_TTL') ?: 10),
            'envia_debug_mode' => (bool) Configuration::get('ENVIA_DEBUG_LOGGING'),
            'envia_origin_configured' => !empty(Configuration::get('ENVIA_ORIGIN_POSTAL_CODE')),
            'envia_logs_url' => $this->getAdminLink('AdminEnviaLogs'),
            'envia_config_url' => $this->getAdminLink('AdminEnviaConfig'),
            'envia_flush_cache_url' => $this->context->link->getAdminLink('AdminEnviaDashboard') . '&action=flushCache&token=' . $this->token,
        ]);

        $this->setTemplate('module:envia_shipping/views/templates/admin/dashboard.tpl');
    }

    /**
     * Handle the flushCache action.
     */
    public function postProcess(): void
    {
        if (Tools::isSubmit('action') && Tools::getValue('action') === 'flushCache') {
            $this->validateCsrf();
            $flushed = $this->getCache()->flush();
            $this->confirmations[] = sprintf($this->l('%d cache entries cleared.'), $flushed);
        }

        parent::postProcess();
    }

    /**
     * Validate CSRF token.
     */
    private function validateCsrf(): void
    {
        if (!$this->checkToken()) {
            $this->errors[] = $this->l('Invalid CSRF token.');
        }
    }

    private function isCarrierActive(): bool
    {
        $carrierId = (int) Configuration::get('ENVIA_CARRIER_ID');
        if ($carrierId <= 0) {
            return false;
        }
        $carrier = new Carrier($carrierId);
        return Validate::isLoadedObject($carrier) && $carrier->active;
    }

    private function getCache(): QuoteCache
    {
        $ttlMinutes = (int)(Configuration::get('ENVIA_CACHE_TTL') ?: 10);
        return new QuoteCache($ttlMinutes * 60);
    }

    private function getAdminLink(string $controller): string
    {
        return $this->context->link->getAdminLink($controller);
    }
}
