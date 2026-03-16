{**
 * Envia Shipping – Admin Dashboard Template
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-truck"></i>
        {l s='Envia Shipping – Dashboard' mod='envia_shipping'}
        <span class="badge badge-info pull-right">v{$envia_module_version}</span>
    </div>

    <div class="panel-body">

        {* Status Cards *}
        <div class="row">
            {* API Status *}
            <div class="col-lg-3 col-md-6">
                <div class="panel {if $envia_api_configured}panel-success{else}panel-danger{/if}">
                    <div class="panel-heading">
                        <i class="icon-key"></i> {l s='API Key' mod='envia_shipping'}
                    </div>
                    <div class="panel-body text-center">
                        {if $envia_api_configured}
                            <span class="label label-success">{l s='Configured' mod='envia_shipping'}</span>
                        {else}
                            <span class="label label-danger">{l s='Not configured' mod='envia_shipping'}</span>
                        {/if}
                    </div>
                </div>
            </div>

            {* Environment *}
            <div class="col-lg-3 col-md-6">
                <div class="panel {if $envia_environment === 'production'}panel-success{else}panel-warning{/if}">
                    <div class="panel-heading">
                        <i class="icon-globe"></i> {l s='Environment' mod='envia_shipping'}
                    </div>
                    <div class="panel-body text-center">
                        {if $envia_environment === 'production'}
                            <span class="label label-success">{l s='Production' mod='envia_shipping'}</span>
                        {else}
                            <span class="label label-warning">{l s='Sandbox' mod='envia_shipping'}</span>
                        {/if}
                    </div>
                </div>
            </div>

            {* Carrier *}
            <div class="col-lg-3 col-md-6">
                <div class="panel {if $envia_carrier_active}panel-success{else}panel-warning{/if}">
                    <div class="panel-heading">
                        <i class="icon-truck"></i> {l s='Carrier' mod='envia_shipping'}
                    </div>
                    <div class="panel-body text-center">
                        {if $envia_carrier_active}
                            <span class="label label-success">{l s='Active' mod='envia_shipping'} (#{$envia_carrier_id})</span>
                        {else}
                            <span class="label label-warning">{l s='Inactive or not found' mod='envia_shipping'}</span>
                        {/if}
                    </div>
                </div>
            </div>

            {* Cache *}
            <div class="col-lg-3 col-md-6">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="icon-time"></i> {l s='Cache' mod='envia_shipping'}
                    </div>
                    <div class="panel-body text-center">
                        <strong>{$envia_cache_count}</strong> {l s='entries' mod='envia_shipping'}
                        <br><small>TTL: {$envia_cache_ttl} min</small>
                    </div>
                </div>
            </div>
        </div>

        {* Actions *}
        <div class="row">
            <div class="col-lg-12">
                <div class="panel">
                    <div class="panel-heading">
                        <i class="icon-cog"></i> {l s='Quick Actions' mod='envia_shipping'}
                    </div>
                    <div class="panel-body">
                        <a href="{$envia_config_url|escape:'html':'UTF-8'}" class="btn btn-primary">
                            <i class="icon-cog"></i> {l s='Configuration' mod='envia_shipping'}
                        </a>
                        &nbsp;
                        <a href="{$envia_flush_cache_url|escape:'html':'UTF-8'}"
                           class="btn btn-warning"
                           onclick="return confirm('{l s='Are you sure you want to flush all cached quotes?' mod='envia_shipping' js=1}');">
                            <i class="icon-trash"></i> {l s='Flush Quote Cache' mod='envia_shipping'}
                        </a>
                        &nbsp;
                        {if $envia_debug_mode}
                            <span class="label label-info">
                                <i class="icon-bug"></i> {l s='Debug Mode ON' mod='envia_shipping'}
                            </span>
                        {/if}
                    </div>
                </div>
            </div>
        </div>

        {* Configuration warnings *}
        {if !$envia_api_configured}
            <div class="alert alert-warning">
                <i class="icon-warning-sign"></i>
                {l s='The Envia API key is not configured. Please' mod='envia_shipping'}
                <a href="{$envia_config_url|escape:'html':'UTF-8'}">{l s='configure it here' mod='envia_shipping'}</a>.
            </div>
        {/if}

        {if !$envia_origin_configured}
            <div class="alert alert-warning">
                <i class="icon-warning-sign"></i>
                {l s='The origin address (postal code) is not configured.' mod='envia_shipping'}
            </div>
        {/if}

    </div>
</div>
