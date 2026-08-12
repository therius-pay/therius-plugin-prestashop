<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TheriusFinalizeModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $submitted_code = $input['paymentCode'] ?? '';
        $expected_order_code = $this->context->cookie->therius_order_code;
        
        if (empty($submitted_code) || empty($expected_order_code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Payment verification failed: missing context.']);
            exit;
        }

        $isTest = Configuration::get('THERIUS_TEST_MODE');
        $priv = $isTest ? Configuration::get('THERIUS_TEST_PRIV_KEY') : Configuration::get('THERIUS_LIVE_PRIV_KEY');
        $apiBase = $isTest ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';
        
        $url = $apiBase . '/v1/payment/inquiry/' . urlencode($submitted_code);
        $maxAttempts = 3;
        $inquiryData = null;
        
        for ($i = 0; $i < $maxAttempts; $i++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $priv
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            
            $inquiryData = json_decode($resp, true);
            
            if (!isset($inquiryData['actionRequired'])) {
                break;
            }
            usleep(700000); // 0.7s
        }
        
        if (!isset($inquiryData['orderCode']) || $inquiryData['orderCode'] !== $expected_order_code) {
            http_response_code(400);
            echo json_encode(['error' => 'Payment verification failed: order mismatch.']);
            exit;
        }

        $accepted = ['captured', 'authorized', 'pending', 'approved', 'succeeded'];
        if (!in_array($inquiryData['status'], $accepted)) {
            http_response_code(400);
            echo json_encode(['error' => 'Payment declined.']);
            exit;
        }
        
        $this->context->cookie->therius_payment_code = $inquiryData['paymentCode'];
        $this->context->cookie->write();
        
        echo json_encode(['success' => true]);
        exit;
    }
}
