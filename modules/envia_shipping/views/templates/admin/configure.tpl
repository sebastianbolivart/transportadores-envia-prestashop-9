{**
 * Envia Shipping – Admin Configuration Template
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-cog"></i>
        {l s='Envia Shipping – Configuration' mod='envia_shipping'}
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            <i class="icon-info-sign"></i>
            {l s='Configure your Envia.com API credentials and shipping parameters below.' mod='envia_shipping'}
            {l s='Get your API key at' mod='envia_shipping'}
            <a href="https://envia.com" target="_blank" rel="noopener noreferrer">envia.com</a>.
        </div>

        {$configuration_form nofilter}

        <div class="panel-footer">
            <a href="{$back_url|escape:'html':'UTF-8'}" class="btn btn-default">
                <i class="icon-arrow-left"></i> {l s='Back to Dashboard' mod='envia_shipping'}
            </a>
        </div>
    </div>
</div>
