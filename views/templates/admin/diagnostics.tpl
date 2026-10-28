{*
 * (c) 2026 Oxygène Technologies <tarek.fellah@oxygene-tech.com>
 *
 * Based on the ClicToPay module (c) 2017 ExtrumWeb International <info@extrumweb.com>,
 * distributed under the BSD license.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *}
<div class="panel">
    <div class="panel-heading"><i class="icon-wrench"></i> {l s='ClicToPay integration tests' mod='clictopay'}</div>
    <p>
        {l s='ClicToPay only accepts validation results produced by the merchant site. These tests run from this server with your credentials and show the raw JSON to paste into the validation grid.' mod='clictopay'}
        <strong>{if $clictopay_sandbox}{l s='Mode: sandbox (test.clictopay.com)' mod='clictopay'}{else}{l s='Mode: production (ipay.clictopay.com)' mod='clictopay'}{/if}</strong>
    </p>
    <form method="post" action="{$clictopay_action|escape:'html':'UTF-8'}" class="form-inline">
        <input type="hidden" name="submitClictopayTest" value="1">
        <button type="submit" name="clictopay_test" value="register_valid" class="btn btn-default">{l s='Valid registration' mod='clictopay'}</button>
        <button type="submit" name="clictopay_test" value="register_missing_amount" class="btn btn-default">{l s='Missing amount' mod='clictopay'}</button>
        <button type="submit" name="clictopay_test" value="register_duplicate" class="btn btn-default">{l s='Duplicate order number' mod='clictopay'}</button>
        <button type="submit" name="clictopay_test" value="status_unknown_order" class="btn btn-default">{l s='Unknown orderId' mod='clictopay'}</button>
    </form>
    <hr>
    <form method="post" action="{$clictopay_action|escape:'html':'UTF-8'}" class="form-inline">
        <input type="hidden" name="submitClictopayTest" value="1">
        <input type="text" name="clictopay_order_id" class="form-control" placeholder="{l s='ClicToPay orderId' mod='clictopay'}" style="width:360px">
        <button type="submit" name="clictopay_test" value="status" class="btn btn-default">{l s='Order status lookup' mod='clictopay'}</button>
    </form>
    {foreach $clictopay_calls as $call}
        <h4>{$call.label|escape:'html':'UTF-8'}</h4>
        <pre>{$call.request|json_encode|escape:'html':'UTF-8'}</pre>
        <pre>{$call.raw|escape:'html':'UTF-8'}</pre>
    {/foreach}
</div>
