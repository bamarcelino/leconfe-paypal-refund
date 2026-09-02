<?php

declare(strict_types=1);

namespace PaypalRefund\Services;

use App\Facades\Plugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Talks directly to the PayPal REST v1 Payments API (the same API used by the
 * official Leconfe PaypalPayment plugin, which creates PAY-* payment ids).
 *
 * Credentials are reused from the official PaypalPayment plugin settings, so
 * there is nothing extra to configure.
 */
final class PaypalRefundService
{
    private string $baseUrl;

    private string $clientId;

    private string $clientSecret;

    public function __construct(?object $paypalPlugin = null)
    {
        $paypalPlugin ??= Plugin::getPlugin('PaypalPayment', true);

        if (! $paypalPlugin) {
            throw new RuntimeException('The official PaypalPayment plugin is not installed or enabled.');
        }

        if (
            ! method_exists($paypalPlugin, 'isTestMode')
            || ! method_exists($paypalPlugin, 'getClientId')
            || ! method_exists($paypalPlugin, 'getClientSecret')
        ) {
            throw new RuntimeException('The installed PaypalPayment plugin is not compatible with PaypalRefund.');
        }

        $clientId = $paypalPlugin->getClientId();
        $clientSecret = $paypalPlugin->getClientSecret();

        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException('PayPal credentials are not configured in the PaypalPayment plugin.');
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->baseUrl = $paypalPlugin->isTestMode()
            ? 'https://api.sandbox.paypal.com'
            : 'https://api.paypal.com';
    }

    /**
     * Full or partial refund for a payment created by the PaypalPayment plugin.
     *
     * @param  string      $paymentId  The PayPal payment id (PAY-*) stored in the
     *                                 payment meta as `paypal_payment_id`.
     * @param  float|null  $amount     Null for a full refund; a positive value
     *                                 (<= captured total) for a partial refund.
     * @param  string      $currency   ISO currency code (e.g. USD).
     * @return array{refund_id: string, state: string, amount: string, currency: string, sale_id: string}
     */
    public function refundPayment(string $paymentId, ?float $amount, string $currency): array
    {
        if ($amount !== null && (! is_finite($amount) || $amount <= 0)) {
            throw new RuntimeException('The refund amount must be greater than zero.');
        }

        $currency = Str::upper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('The payment currency must be a three-letter ISO code.');
        }

        $token = $this->accessToken();

        $saleId = $this->findSaleId($token, $paymentId);

        $body = [];

        if ($amount !== null) {
            $body['amount'] = [
                'total' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
            ];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post("{$this->baseUrl}/v1/payments/sale/{$saleId}/refund", $body === [] ? (object) [] : $body);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response->json(), 'Refund request failed'));
        }

        $data = $response->json();

        if (($data['state'] ?? null) !== 'completed' && ($data['state'] ?? null) !== 'pending') {
            throw new RuntimeException('Unexpected refund state: '.($data['state'] ?? 'unknown'));
        }

        return [
            'refund_id' => (string) $data['id'],
            'state' => (string) $data['state'],
            'amount' => (string) ($data['amount']['total'] ?? ($amount !== null ? number_format($amount, 2, '.', '') : '')),
            'currency' => (string) ($data['amount']['currency'] ?? $currency),
            'sale_id' => $saleId,
        ];
    }

    private function accessToken(): string
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->acceptJson()
            ->post("{$this->baseUrl}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('Could not authenticate with PayPal. Check the credentials in the PaypalPayment plugin.');
        }

        return (string) $response->json('access_token');
    }

    /**
     * A REST v1 payment (PAY-*) contains one transaction whose related
     * resources include the sale. Refunds are issued against the sale id.
     */
    private function findSaleId(string $token, string $paymentId): string
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->get("{$this->baseUrl}/v1/payments/payment/{$paymentId}");

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response->json(), 'Could not fetch the PayPal payment'));
        }

        foreach ($response->json('transactions', []) as $transaction) {
            foreach ($transaction['related_resources'] ?? [] as $resource) {
                if (isset($resource['sale']['id'])) {
                    return (string) $resource['sale']['id'];
                }
            }
        }

        throw new RuntimeException('No sale was found for this PayPal payment.');
    }

    private function apiError(?array $body, string $fallback): string
    {
        if (! $body) {
            return $fallback.'.';
        }

        $name = $body['name'] ?? null;
        $message = $body['message'] ?? null;

        return trim(($name ? "[{$name}] " : '').($message ?: $fallback.'.'));
    }
}
