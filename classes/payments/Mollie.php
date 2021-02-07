<?php


namespace OFFLINE\Mall\Classes\Payments;

use OFFLINE\Mall\Models\Order;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use Omnipay\Mollie\Gateway;
use Omnipay\Omnipay;
use Request;
use Session;
use Throwable;
use Validator;

class Mollie extends PaymentProvider
{
    /**
     * The order that is being paid.
     *
     * @var Order
     */
    public $order;
    /**
     * Data that is needed for the payment.
     * Card numbers, tokens, etc.
     *
     * @var array
     */
    public $data;

    /**
     * Return the display name of your payment provider.
     *
     * @return string
     */
    public function name(): string
    {
        return 'Mollie';
    }

    /**
     * Return a unique identifier for this payment provider.
     *
     * @return string
     */
    public function identifier(): string
    {
        return 'mollie';
    }

    /**
     * Process the payment.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */

    public function process(PaymentResult $result): PaymentResult
    {
        $response = $this->getGateway()->purchase(
            [
                'amount' => $this->order->total_in_currency,
                'currency' => $this->order->currency['code'],
                'description' => '#' . $this->order->id,
                'billingEmail' => $this->order->customer->user->email,
                'metadata' => [
                    'order_id' => $this->order->id,
                    'payment_hash' => $this->order->payment_hash,
                ],
                'returnUrl' => $this->returnUrl(),
                'cancelUrl' => $this->cancelUrl(),
            ]
        )->send();

        // This example assumes that if no redirect response is returned, something went wrong.
        // Maybe there is a case, where a payment can succeed without a redirect.
        if (!$response->isRedirect()) {
            return $result->fail((array)$response->getData(), $response);
        }

        $data = $response->getData();
        $id = array_get($data, 'id');
        if (!$id) {
            return $result->fail(array($data), 'missing payment id');
        }

        Session::put('mall.payment.callback', self::class);
        Session::put('mall.payment.mollie.id', $id);

        return $result->redirect($response->getRedirectResponse()->getTargetUrl());
    }

    public function complete(PaymentResult $result): PaymentResult
    {
        $this->setOrder($result->order);

        $id = Session::pull('mall.payment.mollie.id');
        if (!$id) {
            return $result->fail([], 'missing payment id');
        }

        try {
            $response = $this->getGateway()->completePurchase([
                'transactionReference' => $id,
            ])->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        $data = (array)$response->getData();

        if (!$data) {
            return $result->fail([], $response);
        }

        $status = array_get($data, 'status');

        if ($status === 'canceled') {
            return $result->fail([], trans('offline.mall::lang.payment_status.cancelled'));
        }

        if ($status === 'paid') {
            $this->order->card_type = array_get($data, 'details.cardLabel');
            $this->order->card_holder_name = array_get($data, 'details.cardHolder');
            $this->order->credit_card_last4_digits = array_get($data, 'details.cardNumber');

            return $result->success($data, null);
        }

        if ($status === 'open' || $status === 'pending') {
            return $result->pending($data, $response);
        }

        return $result->fail([], null);
    }

    /**
     * Validate the given input data for this payment.
     *
     * @return bool
     */
    public function validate(): bool
    {
        return true;
    }

    /**
     * Setting keys returned from this method are stored encrypted.
     *
     * Use this to store API tokens and other secret data
     * that is needed for this PaymentProvider to work.
     *
     * @return array
     */
    public function encryptedSettings(): array
    {
        return ['mollie_api_key'];
    }


    /**
     * Build and return the Omnipay gateway.
     */
    protected function getGateway(): Gateway
    {
        $gateway = Omnipay::create('Mollie');
        $gateway->setApiKey(decrypt(PaymentGatewaySettings::get('mollie_api_key')));

        return $gateway;
    }

    public function settings(): array
    {
        return [
            'mollie_api_key' => [
                'label' => 'offline.mall::lang.payment_gateway_settings.mollie.api_key',
                'span' => 'left',
                'type' => 'text',
            ],
        ];
    }
}

