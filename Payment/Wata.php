<?php

namespace notwonderful\Wata\Payment;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use XF;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Mvc\Reply\AbstractReply;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class Wata extends AbstractProvider
{
    /**
     * Get payment provider display title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Wata.pro';
    }

    /**
     * Get API endpoint URL
     *
     * @return string
     */
    public function getApiEndpoint(): string
    {
        return 'https://api.wata.pro/api/h2h/';
    }

    /**
     * List of supported currency codes
     *
     * @var array
     */
    protected array $supportedCurrencies = ['USD', 'EUR', 'RUB'];

    /**
     * List of allowed IP addresses for webhooks
     *
     * @var array
     */
    protected array $allowedIps = ['62.84.126.140', '51.250.106.150'];

    /**
     * Transaction statuses
     */
    protected const STATUS_PAID = 'Paid';
    protected const STATUS_DECLINED = 'Declined';

    /**
     * Verify payment provider configuration
     *
     * @param array $options Configuration options
     * @param array $errors Array to store error messages
     *
     * @return bool
     */
    public function verifyConfig(array &$options, &$errors = []): bool
    {
        if (empty($options['token']))
        {
            $errors[] = XF::phrase('wata_you_must_provide_token');
            return false;
        }

        return true;
    }

    /**
     * Get payment parameters for API request
     *
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     *
     * @return array
     */
    protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase): array
    {
        return [
            'amount' => number_format($purchaseRequest->cost_amount, 2, '.', ''),
            'currency' => $purchaseRequest->cost_currency,
            'description' => "Order #{$purchaseRequest->request_key}",
            'orderId' => $purchaseRequest->request_key,
        ];
    }

    /**
     * Initiate payment process
     *
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     *
     * @return AbstractReply
     * @throws GuzzleException
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase): AbstractReply
    {
        $payment = $this->getPaymentParams($purchaseRequest, $purchase);
        $profileOptions = $purchase->paymentProfile->options;

        $response = XF::app()->http()->client()->post($this->getApiEndpoint() . 'links', [
            'headers' => [
                'Authorization' => 'Bearer ' . $profileOptions['token'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payment
        ]);

        $responseData = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() === 200 && !empty($responseData['id']) && !empty($responseData['url']))
        {
            $purchaseRequest->fastUpdate('provider_metadata', $responseData['id']);
            return $controller->redirect($responseData['url']);
        }

        return $controller->error($responseData['error'] ?? XF::phrase('something_went_wrong_please_try_again'));
    }

    /**
     * Setup callback state from webhook request
     *
     * @param Request $request
     *
     * @return CallbackState
     */
    public function setupCallback(Request $request): CallbackState
    {
        $state = new CallbackState();

        $state->providerId = $request->filter('_xfProvider', 'str');

        $state->input = $request->filter([
            'transactionType' => 'str',
            'transactionId' => 'str',
            'transactionStatus' => 'str',
            'errorCode' => 'str',
            'errorDescription' => 'str',
            'terminalName' => 'str',
            'amount' => 'float',
            'currency' => 'str',
            'orderId' => 'str',
            'orderDescription' => 'str',
            'paymentTime' => 'str',
            'commission' => 'float',
            'email' => 'str'
        ]);

        $state->transactionId = $state->input['transactionId'] ?? null;
        $state->requestKey = $state->input['orderId'] ?? null;
        $state->status = $state->input['transactionStatus'] ?? 'unknown';

        $state->ip = $request->getIp();
        $state->rawInput = $request->getInputRaw();

        return $state;
    }

    /**
     * Validate callback request
     *
     * @param CallbackState $state
     *
     * @return bool
     * @throws GuzzleException
     */
    public function validateCallback(CallbackState $state): bool
    {
        if (! in_array($state->ip, $this->allowedIps))
        {
            $state->logType = 'error';
            $state->logMessage = 'Invalid IP address';
            return false;
        }

        $signature = XF::app()->request()->getServer('HTTP_X_SIGNATURE');
        if (! $this->validateSignature($state->rawInput, $signature))
        {
            $state->logType = 'error';
            $state->logMessage = 'Invalid signature';
            return false;
        }

        if ($state->transactionId && $state->requestKey && $state->getPaymentProfile())
        {
            if ($state->getPaymentProfile()->provider_id !== $state->providerId)
            {
                $state->logType = 'error';
                $state->logMessage = 'Invalid provider';
                return false;
            }
        }

        if (! $state->transactionId || !$state->requestKey)
        {
            $state->logType = 'error';
            $state->logMessage = 'Missing transaction data';
            return false;
        }

        if (! $this->validateTransaction($state))
        {
            return false;
        }

        return parent::validateCallback($state);
    }

    /**
     * Validate transaction details
     *
     * @param CallbackState $state
     * @return bool
     */
    public function validateTransaction(CallbackState $state): bool
    {
        $purchaseRequest = $state->getPurchaseRequest();

        if (! $purchaseRequest)
        {
            $state->logType = 'error';
            $state->logMessage = 'Invalid purchase request';
            return false;
        }

        if (! $this->validateCost($state))
        {
            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     *
     * @return bool
     */
    public function validateCost(CallbackState $state): bool
    {
        $purchaseRequest = $state->getPurchaseRequest();

        if (number_format($state->input['amount'], 2) != number_format($purchaseRequest->cost_amount, 2))
        {
            $state->logType = 'error';
            $state->logMessage = 'Invalid payment amount';
            return false;
        }

        if ($state->input['currency'] !== $purchaseRequest->cost_currency)
        {
            $state->logType = 'error';
            $state->logMessage = 'Invalid payment currency';
            return false;
        }

        return true;
    }

    /**
     * Validate webhook signature
     *
     * @param string $payload Raw JSON payload
     * @param string|null $signature Base64 encoded signature
     * @return bool
     * @throws GuzzleException
     */
    protected function validateSignature(string $payload, ?string $signature): bool
    {
        if (empty($signature))
        {
            XF::logError('Empty Wata signature');
            return false;
        }

        try
        {
            $publicKeyPem = $this->getPublicKey();
            if (empty($publicKeyPem))
            {
                XF::logError('Failed to get Wata public key');
                return false;
            }

            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if ($publicKey === false)
            {
                XF::logError('Failed to parse Wata public key: ' . openssl_error_string());
                return false;
            }

            $verifyResult = openssl_verify(
                $payload,
                base64_decode($signature),
                $publicKey,
                OPENSSL_ALGO_SHA512
            );

            if ($verifyResult === 1)
            {
                return true;
            }

            XF::logError('Invalid Wata signature');
            return false;
        }
        catch (Exception $e)
        {
            XF::logException($e);
            return false;
        }
    }

    /**
     * Get public key from the API
     *
     * @throws GuzzleException
     */
    protected function getPublicKey(): ?string
    {
        try {
            $response = XF::app()->http()->client()
                ->get($this->getApiEndpoint() . 'public-key', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ]
                ])
                ->getBody()
                ->getContents();

            return json_decode($response, true)['value'] ?? null;
        } catch (Exception $e) {
            XF::logException($e);
            return null;
        }
    }

    /**
     * Process payment result from callback
     *
     * @param CallbackState $state
     */
    public function getPaymentResult(CallbackState $state): void
    {
        switch ($state->status)
        {
            case self::STATUS_PAID:
                $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                break;

            case self::STATUS_DECLINED:
                $state->paymentResult = CallbackState::PAYMENT_REINSTATED;
                break;

            default:
                $state->logType = 'error';
                $state->logMessage = 'Invalid transaction status: ' . $state->status;
                break;
        }
    }

    /**
     * Prepare log data for payment callback
     *
     * @param CallbackState $state
     */
    public function prepareLogData(CallbackState $state): void
    {
        $state->logDetails = [
            'ip' => $state->ip,
            'request_time' => XF::$time,
            'input' => $state->input,
            'raw_input' => $state->rawInput
        ];
    }

    /**
     * Check if recurring payments are supported
     *
     * @param PaymentProfile $paymentProfile
     * @param string $unit
     * @param float $amount
     * @param int $result
     *
     * @return bool
     */
    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING): bool
    {
        return false;
    }

    /**
     * Verify if currency is supported
     *
     * @param PaymentProfile $paymentProfile
     * @param string $currencyCode
     *
     * @return bool
     */
    public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode): bool
    {
        return in_array($currencyCode, $this->supportedCurrencies);
    }
}