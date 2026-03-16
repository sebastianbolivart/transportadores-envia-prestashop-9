{**
 * Envia Shipping – Admin Logs Viewer Template
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-list-alt"></i>
        {l s='Envia Shipping – Logs' mod='envia_shipping'}
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            <i class="icon-info-sign"></i>
            {l s='Envia Shipping logs are stored in the PrestaShop advanced logs.' mod='envia_shipping'}
            {l s='Enable debug mode in configuration to see detailed API request/response logs.' mod='envia_shipping'}
        </div>

        <p>
            <a href="{$logs_url|escape:'html':'UTF-8'}" class="btn btn-default" target="_blank">
                <i class="icon-list-alt"></i>
                {l s='View PrestaShop Logs (filter by "EnviaShipping" object)' mod='envia_shipping'}
            </a>
        </p>

        {if $debug_enabled}
            <div class="alert alert-warning">
                <i class="icon-warning-sign"></i>
                {l s='Debug mode is currently ENABLED. Disable it in production to avoid performance overhead.' mod='envia_shipping'}
            </div>
        {/if}

        <hr>

        <h4>{l s='Log Object Type' mod='envia_shipping'}</h4>
        <p>
            {l s='All Envia Shipping logs use the object type:' mod='envia_shipping'}
            <code>EnviaShipping</code>
        </p>

        <h4>{l s='Severity Levels' mod='envia_shipping'}</h4>
        <ul>
            <li><span class="label label-info">{l s='1 – Info' mod='envia_shipping'}</span> {l s='General information and debug messages' mod='envia_shipping'}</li>
            <li><span class="label label-warning">{l s='2 – Warning' mod='envia_shipping'}</span> {l s='Fallback activation, stale cache usage' mod='envia_shipping'}</li>
            <li><span class="label label-danger">{l s='3 – Error' mod='envia_shipping'}</span> {l s='API errors, exceptions' mod='envia_shipping'}</li>
        </ul>
    </div>
</div>
