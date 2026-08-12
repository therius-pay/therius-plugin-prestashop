<form action="{$action}" id="therius-embedded-form" method="POST"
      data-client-token-url="{$theriusClientTokenUrl|escape:'html':'UTF-8'}"
      data-pre-order-url="{$theriusPreOrderUrl|escape:'html':'UTF-8'}"
      data-finalize-url="{$theriusFinalizeUrl|escape:'html':'UTF-8'}"
      data-config-id="{$theriusConfigId|escape:'html':'UTF-8'}"
      data-amount="{$theriusAmount|escape:'html':'UTF-8'}">
    <div id="therius-payment-element" style="min-height: 200px;">
        <!-- Widget injected here -->
    </div>
    <div id="therius-error-message" style="color: red; display: none; margin-bottom: 10px;"></div>
    <div id="therius-status-message" class="therius-status" style="display: none; margin-bottom: 10px;"></div>
    <input type="hidden" name="therius_method" id="therius-method" value="card">
</form>
