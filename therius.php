<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Therius extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'therius';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Therius';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->controllers = ['purchase', 'finalize', 'webhook'];

        parent::__construct();

        $this->displayName = $this->l('Therius Payments');
        $this->description = $this->l('Accept payments through Therius Payment Orchestration Platform.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->installOrderState();
    }

    public function uninstall()
    {
        return parent::uninstall() && $this->deleteOrderState();
    }

    private function installOrderState()
    {
        $states = [
            'THERIUS_OS_PENDING' => ['name' => 'Therius: Pending', 'color' => '#4169E1'],
            'THERIUS_OS_AUTHORIZED' => ['name' => 'Therius: Authorized', 'color' => '#32CD32']
        ];
        
        foreach ($states as $key => $config) {
            if (!Configuration::get($key)) {
                $orderState = new OrderState();
                $orderState->name = array_fill_keys(Language::getIDs(false), $config['name']);
                $orderState->color = $config['color'];
                $orderState->hidden = false;
                $orderState->delivery = false;
                $orderState->logable = false;
                $orderState->invoice = false;
                $orderState->unremovable = true;
                $orderState->module_name = $this->name;
                
                if ($orderState->add()) {
                    Configuration::updateGlobalValue($key, (int)$orderState->id);
                }
            }
        }
        return true;
    }

    private function deleteOrderState()
    {
        return true; // Keep them or hide them
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submit' . $this->name)) {
            $testmode = Tools::getValue('THERIUS_TEST_MODE');
            Configuration::updateValue('THERIUS_TEST_MODE', $testmode);
            Configuration::updateValue('THERIUS_TEST_PUB_KEY', Tools::getValue('THERIUS_TEST_PUB_KEY'));
            Configuration::updateValue('THERIUS_TEST_PRIV_KEY', Tools::getValue('THERIUS_TEST_PRIV_KEY'));
            Configuration::updateValue('THERIUS_TEST_WEBHOOK_SECRET', Tools::getValue('THERIUS_TEST_WEBHOOK_SECRET'));
            Configuration::updateValue('THERIUS_TEST_CONFIG_ID', Tools::getValue('THERIUS_TEST_CONFIG_ID'));
            
            Configuration::updateValue('THERIUS_LIVE_PUB_KEY', Tools::getValue('THERIUS_LIVE_PUB_KEY'));
            Configuration::updateValue('THERIUS_LIVE_PRIV_KEY', Tools::getValue('THERIUS_LIVE_PRIV_KEY'));
            Configuration::updateValue('THERIUS_LIVE_WEBHOOK_SECRET', Tools::getValue('THERIUS_LIVE_WEBHOOK_SECRET'));
            Configuration::updateValue('THERIUS_LIVE_CONFIG_ID', Tools::getValue('THERIUS_LIVE_CONFIG_ID'));
            Configuration::updateValue('THERIUS_MERCHANT_CODE', Tools::getValue('THERIUS_MERCHANT_CODE'));
            
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Settings'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Test mode'),
                        'name' => 'THERIUS_TEST_MODE',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Merchant Code'),
                        'name' => 'THERIUS_MERCHANT_CODE',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Test Publishable Key'),
                        'name' => 'THERIUS_TEST_PUB_KEY',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Test Private Key'),
                        'name' => 'THERIUS_TEST_PRIV_KEY',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Test Webhook Secret'),
                        'name' => 'THERIUS_TEST_WEBHOOK_SECRET',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Test Checkout Config ID'),
                        'name' => 'THERIUS_TEST_CONFIG_ID',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Live Publishable Key'),
                        'name' => 'THERIUS_LIVE_PUB_KEY',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Live Private Key'),
                        'name' => 'THERIUS_LIVE_PRIV_KEY',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Live Webhook Secret'),
                        'name' => 'THERIUS_LIVE_WEBHOOK_SECRET',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Live Checkout Config ID'),
                        'name' => 'THERIUS_LIVE_CONFIG_ID',
                    ],
                ],
                'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right']
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->submit_action = 'submit' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        
        $helper->fields_value['THERIUS_TEST_MODE'] = Configuration::get('THERIUS_TEST_MODE');
        $helper->fields_value['THERIUS_MERCHANT_CODE'] = Configuration::get('THERIUS_MERCHANT_CODE');
        $helper->fields_value['THERIUS_TEST_PUB_KEY'] = Configuration::get('THERIUS_TEST_PUB_KEY');
        $helper->fields_value['THERIUS_TEST_PRIV_KEY'] = Configuration::get('THERIUS_TEST_PRIV_KEY');
        $helper->fields_value['THERIUS_TEST_WEBHOOK_SECRET'] = Configuration::get('THERIUS_TEST_WEBHOOK_SECRET');
        $helper->fields_value['THERIUS_TEST_CONFIG_ID'] = Configuration::get('THERIUS_TEST_CONFIG_ID');
        
        $helper->fields_value['THERIUS_LIVE_PUB_KEY'] = Configuration::get('THERIUS_LIVE_PUB_KEY');
        $helper->fields_value['THERIUS_LIVE_PRIV_KEY'] = Configuration::get('THERIUS_LIVE_PRIV_KEY');
        $helper->fields_value['THERIUS_LIVE_WEBHOOK_SECRET'] = Configuration::get('THERIUS_LIVE_WEBHOOK_SECRET');
        $helper->fields_value['THERIUS_LIVE_CONFIG_ID'] = Configuration::get('THERIUS_LIVE_CONFIG_ID');

        return $helper->generateForm([$form]);
    }

    public function hookActionFrontControllerSetMedia($params)
    {
        if ('order' === $this->context->controller->php_self) {
            $isTest = Configuration::get('THERIUS_TEST_MODE');
            $apiBase = $isTest ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';
            
            $this->context->controller->registerJavascript(
                'therius-sdk',
                $apiBase . '/v1/sdk/js',
                ['server' => 'remote', 'position' => 'head', 'priority' => 20]
            );

            $this->context->controller->registerJavascript(
                'therius-checkout',
                'modules/' . $this->name . '/views/js/therius-checkout.js',
                ['position' => 'bottom', 'priority' => 30]
            );

            $this->context->controller->registerStylesheet(
                'therius-css',
                'modules/' . $this->name . '/views/css/therius.css',
                ['media' => 'all', 'priority' => 50]
            );
            
            $config_id = $isTest ? Configuration::get('THERIUS_TEST_CONFIG_ID') : Configuration::get('THERIUS_LIVE_CONFIG_ID');
            
            if (!$config_id) {
                // Fetch default config id via API using backend call and pass it to frontend
                $priv = $isTest ? Configuration::get('THERIUS_TEST_PRIV_KEY') : Configuration::get('THERIUS_LIVE_PRIV_KEY');
                $session_body = json_encode([
                    'country' => $this->context->country->iso_code,
                    'currency' => $this->context->currency->iso_code
                ]);
                
                $ch = curl_init($apiBase . '/v1/sdk/session');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $priv,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $session_body);
                $resp = curl_exec($ch);
                curl_close($ch);
                
                if ($resp) {
                    $json = json_decode($resp, true);
                    if (isset($json['defaultCheckoutConfigId'])) {
                        $config_id = $json['defaultCheckoutConfigId'];
                    }
                }
            }

            Media::addJsDef([
                'theriusConfig' => [
                    'clientTokenUrl' => $this->context->link->getModuleLink($this->name, 'purchase', ['action' => 'session']),
                    'preOrderUrl' => $this->context->link->getModuleLink($this->name, 'purchase', ['action' => 'preorder']),
                    'finalizeUrl' => $this->context->link->getModuleLink($this->name, 'finalize'),
                    'configId' => $config_id
                ]
            ]);
        }
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) return [];

        $isTest = Configuration::get('THERIUS_TEST_MODE');
        $config_id = $isTest ? Configuration::get('THERIUS_TEST_CONFIG_ID') : Configuration::get('THERIUS_LIVE_CONFIG_ID');

        $submitUrl = $this->context->link->getModuleLink($this->name, 'purchase', ['action' => 'submit']);

        // therius-sdk's checkout widget gates the Apple Pay / Google Pay
        // wallet button entirely on a truthy amount (_mountWalletButton in
        // checkout.ts) - without this, wallets silently never render no
        // matter how they're configured on the merchant's checkout config.
        $cart = $this->context->cart;
        $currency = new Currency($cart->id_currency);
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF'];
        $isZero = in_array(strtoupper($currency->iso_code), $zeroDecimalCurrencies);
        $amountMinor = $isZero ? (int)round($total) : (int)round($total * 100);

        $this->context->smarty->assign([
            'action' => $submitUrl,
            'theriusClientTokenUrl' => $this->context->link->getModuleLink($this->name, 'purchase', ['action' => 'session']),
            'theriusPreOrderUrl' => $this->context->link->getModuleLink($this->name, 'purchase', ['action' => 'preorder']),
            'theriusFinalizeUrl' => $this->context->link->getModuleLink($this->name, 'finalize'),
            'theriusConfigId' => $config_id,
            'theriusAmount' => $amountMinor
        ]);

        $option = new PaymentOption();
        $option->setModuleName($this->name)
               ->setCallToActionText($this->l('Pay with Card / APM (Therius)'))
               ->setAction($submitUrl)
               ->setForm($this->context->smarty->fetch('module:therius/views/templates/front/paymentOptionEmbeddedForm.tpl'));

        return [$option];
    }
    
    public function hookDisplayPaymentReturn($params)
    {
        return $this->context->smarty->fetch('module:therius/views/templates/hook/displayPaymentReturn.tpl');
    }

    public function hookActionOrderSlipAdd($params)
    {
        if (empty($params['order'])) {
            return;
        }
        $order = $params['order'];
        if ($order->module !== $this->name) {
            return;
        }

        $orderSlip = $params['order_slip'];
        $amount = (float)$orderSlip->amount;
        
        $isTest = Configuration::get('THERIUS_TEST_MODE');
        $priv = $isTest ? Configuration::get('THERIUS_TEST_PRIV_KEY') : Configuration::get('THERIUS_LIVE_PRIV_KEY');
        $apiBase = $isTest ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';
        $merchantCode = Configuration::get('THERIUS_MERCHANT_CODE');
        
        $currency = new Currency($order->id_currency);
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF'];
        $isZero = in_array(strtoupper($currency->iso_code), $zeroDecimalCurrencies);
        $minorUnit = $isZero ? (int)round($amount) : (int)round($amount * 100);
        $exponent = $isZero ? 0 : 2;
        
        $body = [
            'key' => $priv,
            'amount' => [
                'value' => $minorUnit,
                'currency' => $currency->iso_code,
                'exponent' => $exponent
            ],
            'reference' => 'Refund for Order ' . $order->reference
        ];

        if ($merchantCode) {
            $body['merchantCode'] = $merchantCode;
        }

        // Therius addresses a payment by its server-issued id in the URL —
        // POST /v1/payment/{id}/refund. orderCode / paymentCode are not
        // accepted for this call. The order payment's transaction_id holds the
        // paymentCode; resolve it to the id via the keyless inquiry endpoint
        // (which returns both).
        $paymentCode = '';
        $orderPaymentCollection = $order->getOrderPaymentCollection();
        if ($orderPaymentCollection->count()) {
            $paymentCode = $orderPaymentCollection->getFirst()->transaction_id;
        }
        if (!$paymentCode) {
            PrestaShopLogger::addLog('Therius: refund for order ' . $order->reference . ' has no stored payment code', 3);
            return;
        }

        $paymentId = '';
        $ich = curl_init($apiBase . '/v1/payment/inquiry/' . urlencode($paymentCode));
        curl_setopt($ich, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ich, CURLOPT_HTTPHEADER, $isTest ? ['X-Environment: sandbox'] : []);
        $iresp = curl_exec($ich);
        $icode = curl_getinfo($ich, CURLINFO_HTTP_CODE);
        curl_close($ich);
        if ($icode < 400) {
            $idata = json_decode($iresp, true);
            if (!empty($idata['id'])) {
                $paymentId = $idata['id'];
            }
        }
        if (!$paymentId) {
            PrestaShopLogger::addLog('Therius: could not resolve payment id for order ' . $order->reference . ' (payment ' . $paymentCode . ')', 3);
            return;
        }

        // Keyed on the order slip id (one per distinct refund action) so a
        // retried request for the same slip reuses the key, but two separate
        // refunds of the same amount on the same order don't collide.
        $dedupe = md5($order->id . '|' . $amount . '|' . $orderSlip->id);

        $ch = curl_init($apiBase . '/v1/payment/' . urlencode($paymentId) . '/refund');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $priv,
            'Content-Type: application/json',
            'Idempotency-Key: ps_refund_' . $dedupe
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $resp = curl_exec($ch);
        curl_close($ch);
    }
}
