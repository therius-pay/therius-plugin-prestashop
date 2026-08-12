<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TheriusWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $rawPayload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_THERIUS_SIGNATURE']) ? $_SERVER['HTTP_X_THERIUS_SIGNATURE'] : '';
        
        $json = json_decode($rawPayload, true);
        if (!$json) {
            http_response_code(400);
            exit;
        }

        $env = isset($json['environment']) ? $json['environment'] : 'sandbox';
        
        $secret = '';
        if ($env === 'sandbox') {
            $secret = Configuration::get('THERIUS_TEST_WEBHOOK_SECRET');
        } else {
            $secret = Configuration::get('THERIUS_LIVE_WEBHOOK_SECRET');
        }

        if (empty($secret)) {
            http_response_code(400);
            exit;
        }

        $expectedSig = 'sha256=' . hash_hmac('sha256', $rawPayload, $secret);
        if (!hash_equals($expectedSig, $signature)) {
            http_response_code(401);
            exit;
        }

        $event = isset($json['event']) ? $json['event'] : '';
        $paymentCode = isset($json['data']['payment_code']) ? $json['data']['payment_code'] : '';
        $orderCode = isset($json['data']['order_code']) ? $json['data']['order_code'] : '';

        if (empty($paymentCode)) {
            http_response_code(200);
            exit;
        }

        $order = $this->findOrderByPaymentCode($paymentCode);

        // Recovery path: no PrestaShop order exists yet for this payment.
        // Unlike WooCommerce/Magento, this plugin's checkout charges the card
        // BEFORE the PrestaShop order is created - the browser's own POST
        // (views/js/therius-checkout.js -> purchase.php action=submit) is
        // what actually calls validateOrder(). If that POST never lands
        // (tab closed, network dropped, some other script on the page
        // blocked the submit), money moves with no local order to show for
        // it. This webhook is a server-to-server call that doesn't depend
        // on the customer's browser at all, so on a captured/authorized
        // event with no matching order, build the order here instead of
        // just acking and losing the link.
        $justRecovered = false;
        if (!$order && in_array($event, ['payment.captured', 'payment.authorized'], true)) {
            $order = $this->recoverOrderFromCart($orderCode, $paymentCode, $event);
            $justRecovered = (bool)$order;
        }

        if (!$order) {
            http_response_code(200); // Nothing to reconcile against, or recovery wasn't possible; ack so Therius doesn't retry forever.
            exit;
        }

        // Idempotency: webhook deliveries retry on failure and can arrive more
        // than once for the same event - skip re-applying one we've already seen.
        $eventKey = $event . ':' . (isset($json['created_at']) ? $json['created_at'] : '');
        $dedupeKey = 'THERIUS_LAST_EVT_' . $order->id;
        if ($eventKey !== '' && Configuration::get($dedupeKey) === $eventKey) {
            http_response_code(200);
            exit;
        }

        $applied = true;
        if ($justRecovered) {
            // recoverOrderFromCart() already created the order in the state
            // this exact event implies - applying the switch below would
            // just re-set the same state and write a duplicate order_history
            // row for nothing.
        } else {
            switch ($event) {
                case 'payment.authorized':
                    $order->setCurrentState((int)Configuration::get('THERIUS_OS_AUTHORIZED'));
                    break;
                case 'payment.captured':
                    $order->setCurrentState((int)Configuration::get('PS_OS_PAYMENT'));
                    break;
                case 'payment.refused':
                case 'payment.cancelled':
                case 'payment.capture_failed':
                    $order->setCurrentState((int)Configuration::get('PS_OS_ERROR'));
                    break;
                case 'payment.refunded':
                    $order->setCurrentState((int)Configuration::get('PS_OS_REFUND'));
                    break;
                case 'payment.refund_failed':
                    // Don't move the order off its current status - the customer's
                    // payment is unaffected, only the refund attempt failed. Log it
                    // so the merchant notices and retries (PrestaShop core has no
                    // generic per-order note field to surface this in the BO).
                    PrestaShopLogger::addLog(
                        'Therius: refund attempt failed on order #' . $order->id . ', needs manual review and retry.',
                        3,
                        null,
                        'Order',
                        $order->id
                    );
                    break;
                default:
                    $applied = false;
            }
        }

        if ($applied && $eventKey !== '') {
            Configuration::updateValue($dedupeKey, $eventKey);
        }

        http_response_code(200);
        exit;
    }

    /**
     * Look up an existing PrestaShop order by the Therius payment code
     * stamped on its order_payment row (set by purchase.php action=submit
     * or by recoverOrderFromCart() below).
     */
    private function findOrderByPaymentCode($paymentCode)
    {
        $sql = new DbQuery();
        $sql->select('id_order');
        $sql->from('order_invoice_payment', 'oip');
        $sql->leftJoin('order_payment', 'op', 'op.id_order_payment = oip.id_order_payment');
        $sql->where('op.transaction_id = "' . pSQL($paymentCode) . '"');
        $row = Db::getInstance()->getRow($sql);

        return $row ? new Order((int)$row['id_order']) : null;
    }

    /**
     * Build the PrestaShop order for a payment that already succeeded but
     * never got turned into an order by the customer's browser. The cart id
     * is recovered from the pseudo order code minted at charge time
     * (purchase.php: 'CART_' . $cart->id . '_' . time()) - the only
     * identifier available before a real PrestaShop order exists.
     *
     * Known limitation: this does not re-check the charged amount against
     * the cart's current total, so a cart modified between charge time and
     * webhook delivery (rare - webhooks normally arrive within seconds)
     * would recover an order priced at its now-current total rather than
     * what was actually charged. Flagged for manual reconciliation via the
     * log line below, not solved here.
     */
    private function recoverOrderFromCart($orderCode, $paymentCode, $event)
    {
        if (!preg_match('/^CART_(\d+)_\d+$/', (string)$orderCode, $matches)) {
            return null;
        }

        $cart = new Cart((int)$matches[1]);
        if (!Validate::isLoadedObject($cart)) {
            return null;
        }

        // Another delivery of this same event (or the client, racing us)
        // may have already turned this cart into an order between the
        // findOrderByPaymentCode() lookup above and here.
        if ($cart->orderExists()) {
            $orders = Order::getByCartId($cart->id);
            return $orders ? new Order((int)$orders[0]['id_order']) : null;
        }

        $customer = new Customer((int)$cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return null;
        }

        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
        $stateKey = ($event === 'payment.authorized') ? 'THERIUS_OS_AUTHORIZED' : 'PS_OS_PAYMENT';

        try {
            $this->module->validateOrder(
                $cart->id,
                (int)Configuration::get($stateKey),
                $total,
                $this->module->displayName,
                null,
                ['transaction_id' => $paymentCode],
                (int)$cart->id_currency,
                false,
                $customer->secure_key
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Therius: order recovery failed for cart #' . $cart->id . ', payment ' . $paymentCode . ': ' . $e->getMessage(),
                3
            );
            return null;
        }

        $orderId = (int)$this->module->currentOrder;
        if (!$orderId) {
            return null;
        }

        PrestaShopLogger::addLog(
            'Therius: order #' . $orderId . ' recovered via webhook for cart #' . $cart->id . ' (payment ' . $paymentCode . ') - '
            . 'the customer\'s browser never completed order creation after a successful charge.',
            2,
            null,
            'Order',
            $orderId
        );

        return new Order($orderId);
    }
}
