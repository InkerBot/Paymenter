<?php

namespace App\Extensions\Gateways\AliPay;

use Alipay\EasySDK\Kernel\Config;
use Alipay\EasySDK\Kernel\Factory;
use Alipay\EasySDK\Kernel\Util\ResponseChecker;
use App\Classes\Extensions\Gateway;
use App\Helpers\ExtensionHelper;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class AliPay extends Gateway
{
    public function getMetadata()
    {
        return [
            'display_name' => 'AliPay',
            'version' => '1.0',
            'author' => 'InkerBot',
            'website' => 'https://inker.bot',
        ];
    }

    private function getApiInstance() {
        $options = new Config();
        $options->protocol = 'https';
        if (ExtensionHelper::getConfig('AliPay', 'live')) {
            $options->gatewayHost = 'openapi.alipay.com';
        } else {
            $options->gatewayHost = 'openapi-sandbox.dl.alipaydev.com';
        }
        $options->signType = 'RSA2';

        $options->appId = ExtensionHelper::getConfig('AliPay', 'app_id');

        // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中
        $options->merchantPrivateKey = ExtensionHelper::getConfig('AliPay', 'private_key');

        if (ExtensionHelper::getConfig('AliPay', 'is_key_mode')) {
            $options->alipayPublicKey = ExtensionHelper::getConfig('AliPay', 'alipay_public_key');
        } else {
            $options->alipayCertPath = base_path(ExtensionHelper::getConfig('AliPay', 'alipay_cert_public_key'));
            $options->alipayRootCertPath = base_path(ExtensionHelper::getConfig('AliPay', 'alipay_root_cert'));
            $options->merchantCertPath = base_path(ExtensionHelper::getConfig('AliPay', 'app_cert_public_key'));
        }

        $options->notifyUrl = url('/extensions/alipay/webhook');
        Factory::setOptions($options);
    }

    private function isPaid($orderId)
    {
        $this->getApiInstance();

        $queryResult = Factory::payment()
            ->common()
            ->query($orderId);
        $responseChecker = new ResponseChecker();
        if ($responseChecker->success($queryResult)) {
            $trade_result = $queryResult->tradeStatus === "TRADE_SUCCESS" || $queryResult->tradeStatus === "TRADE_FINISHED";
            if ($trade_result) {
                ExtensionHelper::paymentDone($orderId, 'AliPay');
                return true;
            }
        }
        return false;
    }

    public function pay($total, $products, $orderId)
    {
        $this->getApiInstance();

        if ($this->isPaid($orderId)) {
            return route('clients.invoice.show', $orderId);
        }

        $description = '';
        foreach ($products as $product) {
            $description .= $product->name;
            if ($product->quantity > 1) {
                $description .= ' x' . $product->quantity . ', ';
            }
        }
        $description = rtrim($description, ', ');

        $payResult = Factory::payment()
            ->page()
            ->pay(
                $description,
                $orderId,
                number_format($total, 2, '.', ''),
                url('/extensions/alipay/redirect')
            );

        $responseChecker = new ResponseChecker();
        //3. 处理响应或异常
        if (!$responseChecker->success($payResult)) {
            throw new Exception($payResult->msg . ', ' . $payResult->subMsg);
        }
        return new Response($payResult->body);
    }

    public function webhook(Request $request)
    {
        $this->getApiInstance();

        $verifyResult = Factory::payment()
            ->common()
            ->verifyNotify($request->all());
        if (!$verifyResult) {
            return response('failure');
        }

        $appId = $request->get('app_id');
        if (str($appId) != ExtensionHelper::getConfig('AliPay', 'app_id')) {
            return response('failure');
        }
        $order_id = $request->get('out_trade_no');
        $trade_status = str($request->get('trade_status'));
        if ($trade_status === 'TRADE_SUCCESS' || $trade_status === 'TRADE_FINISHED') {
            ExtensionHelper::paymentDone($order_id, 'AliPay');
        }
        return response('success');
    }

    public function redirect(Request $request)
    {
        $this->getApiInstance();

        $verifyResult = Factory::payment()
            ->common()
            ->verifyNotify($request->all());
        if (!$verifyResult) {
            throw new Exception('Invalid request');
        }

        $appId = $request->get('app_id');
        if (str($appId) != ExtensionHelper::getConfig('AliPay', 'app_id')) {
            throw new Exception('Invalid request');
        }
        $order_id = $request->get('out_trade_no');
        $this->isPaid($order_id);
        return redirect()->route('clients.invoice.show', $order_id);
    }

    public function getConfig()
    {
        return [
            [
                'name' => 'app_id',
                'friendlyName' => 'APP ID',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'live',
                'type' => 'boolean',
                'friendlyName' => 'Live mode',
                'required' => false,
            ],
            [
                'name' => 'is_key_mode',
                'type' => 'boolean',
                'friendlyName' => 'Key mode',
                'required' => false,
            ],
            // key mode
            [
                'name' => 'private_key',
                'friendlyName' => 'Private Key',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'alipay_public_key',
                'friendlyName' => 'Public key (for not cert mode)',
                'type' => 'text',
                'required' => false,
            ],
            // cert mode
            [
                'name' => 'app_cert_public_key',
                'friendlyName' => 'App cert public key path (for cert mode)',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'alipay_cert_public_key',
                'friendlyName' => 'Alipay cert public path (for cert mode)',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'alipay_root_cert',
                'friendlyName' => 'Alipay root cert path (for cert mode)',
                'type' => 'text',
                'required' => false,
            ]
        ];
    }
}
