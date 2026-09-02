<?php

declare(strict_types=1);

namespace PaypalRefund\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PaypalRefund\Services\PaypalRefundService;
use RuntimeException;
use Tests\TestCase;

final class PaypalRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_partial_refund_with_official_plugin_credentials(): void
    {
        $http = $this->fakeHttp([
            'https://api.sandbox.paypal.com/v1/oauth2/token' => Factory::response([
                'access_token' => 'access-token',
            ]),
            'https://api.sandbox.paypal.com/v1/payments/payment/PAY-TEST' => Factory::response([
                'transactions' => [[
                    'related_resources' => [[
                        'sale' => ['id' => 'SALE-TEST'],
                    ]],
                ]],
            ]),
            'https://api.sandbox.paypal.com/v1/payments/sale/SALE-TEST/refund' => Factory::response([
                'id' => 'REFUND-TEST',
                'state' => 'completed',
                'amount' => [
                    'total' => '25.50',
                    'currency' => 'EUR',
                ],
            ]),
        ]);

        $service = new PaypalRefundService(new FakePaypalPaymentPlugin);
        $result = $service->refundPayment('PAY-TEST', 25.50, 'eur');

        $this->assertSame([
            'refund_id' => 'REFUND-TEST',
            'state' => 'completed',
            'amount' => '25.50',
            'currency' => 'EUR',
            'sale_id' => 'SALE-TEST',
        ], $result);

        $http->assertSent(fn (Request $request) =>
            $request->url() === 'https://api.sandbox.paypal.com/v1/payments/sale/SALE-TEST/refund'
            && $request['amount']['total'] === '25.50'
            && $request['amount']['currency'] === 'EUR'
        );
        $http->assertSentCount(3);
    }

    public function test_it_surfaces_paypal_refund_errors(): void
    {
        $this->fakeHttp([
            'https://api.sandbox.paypal.com/v1/oauth2/token' => Factory::response([
                'access_token' => 'access-token',
            ]),
            'https://api.sandbox.paypal.com/v1/payments/payment/PAY-TEST' => Factory::response([
                'transactions' => [[
                    'related_resources' => [[
                        'sale' => ['id' => 'SALE-TEST'],
                    ]],
                ]],
            ]),
            'https://api.sandbox.paypal.com/v1/payments/sale/SALE-TEST/refund' => Factory::response([
                'name' => 'TRANSACTION_REFUSED',
                'message' => 'The refund was refused.',
            ], 422),
        ]);

        $service = new PaypalRefundService(new FakePaypalPaymentPlugin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[TRANSACTION_REFUSED] The refund was refused.');

        $service->refundPayment('PAY-TEST', null, 'EUR');
    }

    public function test_it_rejects_invalid_refund_input_before_contacting_paypal(): void
    {
        $http = $this->fakeHttp([]);
        $service = new PaypalRefundService(new FakePaypalPaymentPlugin);

        try {
            $service->refundPayment('PAY-TEST', 0, 'EUR');
            $this->fail('A zero refund amount should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The refund amount must be greater than zero.', $exception->getMessage());
        }

        $http->assertNothingSent();
    }

    private function fakeHttp(array $responses): Factory
    {
        $factory = new Factory(app('events'));
        $factory->fake($responses);
        Http::swap($factory);

        return $factory;
    }
}

final class FakePaypalPaymentPlugin
{
    public function isTestMode(): bool
    {
        return true;
    }

    public function getClientId(): string
    {
        return 'client-id';
    }

    public function getClientSecret(): string
    {
        return 'client-secret';
    }
}
