<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TheriusPurchaseModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $action = Tools::getValue('action');
        
        $isTest = Configuration::get('THERIUS_TEST_MODE');
        $priv = $isTest ? Configuration::get('THERIUS_TEST_PRIV_KEY') : Configuration::get('THERIUS_LIVE_PRIV_KEY');
        $apiBase = $isTest ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';
            
        if ($action === 'session') {
            $cart = $this->context->cart;
            $currency = new Currency($cart->id_currency);

            // Use the customer's actual selected billing address, the same
            // way action=preorder below does - NOT $this->context->country,
            // which is PrestaShop's ambient/geolocation browsing context and
            // can easily differ from the address the customer actually
            // entered. This country gates which payment methods the widget
            // is even allowed to show (see buildMethodSpecs in
            // therius-public-api), so getting it wrong can silently hide
            // country-restricted methods like ACH with no error anywhere.
            $country_id = 0;
            if ($cart->id_address_invoice) {
                $address = new Address((int)$cart->id_address_invoice);
                if (Validate::isLoadedObject($address)) {
                    $country_id = (int)$address->id_country;
                }
            }
            if (!$country_id) {
                $country_id = $this->context->country ? $this->context->country->id : (int) Configuration::get('PS_COUNTRY_DEFAULT');
            }
            $country = new Country($country_id);

            $session_body = json_encode([
                'country' => $country->iso_code,
                'currency' => $currency->iso_code,
            ]);
            
            $ch = curl_init($apiBase . '/v1/sdk/session');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $priv,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $session_body);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            
            header('Content-Type: application/json');
            if ($resp === false || empty($resp)) {
                PrestaShopLogger::addLog('Therius session request failed: ' . $err, 3);
                echo json_encode(['error' => 'Unable to reach Therius API.']);
            } else {
                echo $resp;
            }
            exit;
        }

        if ($action === 'preorder') {
            // Read JSON input
            $input = json_decode(file_get_contents('php://input'), true);
            
            $cart = $this->context->cart;
            $customer = new Customer($cart->id_customer);
            $currency = new Currency($cart->id_currency);
            $address = new Address($cart->id_address_invoice);
            $country = new Country($address->id_country);
            $state = new State($address->id_state);
            
            $total = $cart->getOrderTotal(true, Cart::BOTH);
            $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF'];
            $isZero = in_array(strtoupper($currency->iso_code), $zeroDecimalCurrencies);
            
            $minorUnit = $isZero ? (int)round($total) : (int)round($total * 100);
            $exponent = $isZero ? 0 : 2;
            
            $body = [
                'amount' => [
                    'value' => $minorUnit,
                    'currency' => $currency->iso_code,
                    'exponent' => $exponent
                ],
                'shopper' => [
                    'email' => $customer->email,
                    'name' => $address->firstname . ' ' . $address->lastname
                ],
                'browserInfo' => [
                    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'ipAddress' => Tools::getRemoteAddr()
                ],
                'key' => $priv
            ];
            
            if ($customer->id) {
                $body['shopper']['id'] = strval($customer->id);
            }
            
            $merchantCode = Configuration::get('THERIUS_MERCHANT_CODE');
            if ($merchantCode) {
                $body['merchantCode'] = $merchantCode;
            }

            if (!empty($input['payment_code'])) {
                $body['threeDsSetup'] = ['sessionId' => $input['payment_code']];
            }

            $method = $input['method'] ?? 'card';
            
            $cardAddress = [
                'address1' => $address->address1,
                'address2' => $address->address2,
                'city' => $address->city,
                'state' => $state ? $state->name : '',
                'countryCode' => $country->iso_code,
                'postalCode' => $address->postcode
            ];

            if ($method === 'card') {
                $body['card'] = [
                    'nonceData' => [
                        'nonce' => $input['nonce'],
                        'cardholderName' => $address->firstname . ' ' . $address->lastname,
                        'cardAddress' => $cardAddress
                    ]
                ];
                if (!empty($input['vault_consent']) && !empty($body['shopper']['id'])) {
                    $body['card']['nonceData']['tokenize'] = true;
                }
            } elseif ($method === 'saved_method') {
                $body['card'] = [
                    'tokenData' => [
                        'token' => $input['token'],
                        'cardAddress' => $cardAddress
                    ]
                ];
            } elseif ($method === 'apm') {
                $apm = $input['apm_data'];
                if (empty($apm['payerName'])) $apm['payerName'] = $address->firstname . ' ' . $address->lastname;
                if (empty($apm['payerEmail'])) $apm['payerEmail'] = $customer->email;
                $apm['billingAddress'] = $cardAddress;
                $body['apm'] = $apm;
            } elseif ($method === 'wallet') {
                $body['wallet'] = $input['wallet_data'];
            }

            // Pseudo-order code (Cart ID before order placement)
            $orderCode = 'CART_' . $cart->id . '_' . time();
            $payloadHash = md5(json_encode($body));
            
            $body['orderCode'] = $orderCode;
            $body['paymentCode'] = $orderCode . '_' . substr($payloadHash, 0, 8);
            
            $this->context->cookie->therius_order_code = $orderCode;
            $this->context->cookie->write();

            $ch = curl_init($apiBase . '/v1/payment/purchase');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $priv,
                'Content-Type: application/json',
                'Idempotency-Key: ps_purchase_' . $cart->id . '_' . $payloadHash
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $resp = curl_exec($ch);
            curl_close($ch);
            
            $json = json_decode($resp, true);

            if (isset($json['paymentCode'])) {
                $this->context->cookie->therius_payment_code = $json['paymentCode'];
                $this->context->cookie->write();
            }

            header('Content-Type: application/json');
            
            if (!empty($json['actionRequired'])) {
                echo json_encode(['actionRequired' => $json['actionRequired']]);
                exit;
            }
            
            $accepted = ['captured', 'authorized', 'pending', 'approved', 'succeeded'];
            if (isset($json['paymentCode']) && in_array($json['status'], $accepted)) {
                echo json_encode(['success' => true, 'paymentCode' => $json['paymentCode']]);
                exit;
            }
            
            http_response_code(400);
            echo json_encode(['error' => isset($json['error']) ? $json['error'] : (isset($json['refusalCode']['reason']) ? $json['refusalCode']['reason'] : 'Payment declined.')]);
            exit;
        }

        if ($action === 'submit') {
            // Actual place order for synchronous flow
            $cart = $this->context->cart;
            $orderCode = $this->context->cookie->therius_order_code;
            $paymentCode = $this->context->cookie->therius_payment_code;
            
            if (!$orderCode || !$paymentCode) {
                Tools::redirect('index.php?controller=order&step=1');
            }
            
            $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
            $customer = new Customer($cart->id_customer);
            
            $this->module->validateOrder(
                $cart->id,
                Configuration::get('THERIUS_OS_PENDING'),
                $total,
                $this->module->displayName,
                null,
                ['transaction_id' => $paymentCode],
                (int)$cart->id_currency,
                false,
                $customer->secure_key
            );
            
            $order = new Order($this->module->currentOrder);
            
            // Set order code for tracking real order ID instead of CART_
            // but we keep the current one.
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart->id.'&id_module='.$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
        }
    }
}
