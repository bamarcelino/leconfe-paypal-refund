<?php

declare(strict_types=1);

namespace PaypalRefund;

use App\Classes\Plugin;
use App\Facades\Hook;
use App\Facades\Plugin as PluginFacade;
use App\Models\Payment;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use PaypalRefund\Services\PaypalRefundService;

final class PaypalRefundPlugin extends Plugin
{
    public function boot()
    {
        if (! app()->getCurrentScheduledConference()) {
            return;
        }

        if (! PluginFacade::getPlugin('PaypalPayment')) {
            return;
        }

        Hook::add('PaymentManager::getPaymentMethodInfolist', function ($hookName, &$schemas) {
            $schemas[] = $this->refundStatusSection();
            $schemas[] = $this->refundActionSection();

            return false;
        });
    }

    private function canRefund(Payment $record): bool
    {
        return $record->payment_method === 'paypal'
            && $record->paid_at !== null
            && filled($record->getMeta('paypal_payment_id'))
            && blank($record->getMeta('paypal_refund_id'))
            && auth()->user()?->can('update', app()->getCurrentScheduledConference());
    }

    private function isRefunded(Payment $record): bool
    {
        return filled($record->getMeta('paypal_refund_id'));
    }

    private function refundStatusSection(): Section
    {
        return Section::make('PayPal Refund')
            ->visible(fn ($record) => $record instanceof Payment && $this->isRefunded($record))
            ->schema([
                TextEntry::make('paypal_refund_id')
                    ->label('Refund ID')
                    ->getStateUsing(fn ($record) => $record->getMeta('paypal_refund_id')),
                TextEntry::make('paypal_refund_amount')
                    ->label('Refunded Amount')
                    ->getStateUsing(fn ($record) => trim($record->getMeta('paypal_refund_amount').' '.$record->getMeta('paypal_refund_currency'))),
                TextEntry::make('paypal_refunded_at')
                    ->label('Refunded At')
                    ->getStateUsing(fn ($record) => $record->getMeta('paypal_refunded_at')),
                TextEntry::make('paypal_refund_state')
                    ->label('Refund State')
                    ->badge()
                    ->color(fn ($record) => $record->getMeta('paypal_refund_state') === 'completed' ? 'success' : 'warning')
                    ->getStateUsing(fn ($record) => $record->getMeta('paypal_refund_state')),
            ]);
    }

    private function refundActionSection(): Section
    {
        return Section::make('PayPal Refund')
            ->description('Issue a full or partial refund directly through PayPal.')
            ->visible(fn ($record) => $record instanceof Payment && $this->canRefund($record))
            ->schema([
                InfolistActions::make([
                    InfolistAction::make('paypal_refund')
                        ->label('Refund via PayPal')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Refund this payment via PayPal')
                        ->modalDescription('This will send a refund request to PayPal. This action cannot be undone from Leconfe.')
                        ->fillForm(fn ($record) => [
                            'amount' => number_format((float) $record->amount, 2, '.', ''),
                            'reopen_payment' => false,
                        ])
                        ->form([
                            Placeholder::make('paid_total')
                                ->label('Paid Total')
                                ->content(fn ($record) => $record->getFormattedFee()),
                            TextInput::make('amount')
                                ->label('Refund Amount')
                                ->helperText('Leave the full amount for a full refund, or lower it for a partial refund.')
                                ->numeric()
                                ->required()
                                ->minValue(0.01)
                                ->maxValue(fn ($record) => (float) $record->amount),
                            Toggle::make('reopen_payment')
                                ->label('Mark payment as unpaid again')
                                ->helperText('Reverts the payment to pending so the participant can pay again. Leave off if the registration is being cancelled.'),
                        ])
                        ->action(function (InfolistAction $action, array $data, $record) {
                            /** @var Payment $record */
                            try {
                                $service = new PaypalRefundService;

                                $requested = round((float) $data['amount'], 2);
                                $isFull = $requested >= round((float) $record->amount, 2);

                                $result = $service->refundPayment(
                                    paymentId: (string) $record->getMeta('paypal_payment_id'),
                                    amount: $isFull ? null : $requested,
                                    currency: (string) $record->currency,
                                );

                                $record->setManyMeta([
                                    'paypal_refund_id' => $result['refund_id'],
                                    'paypal_refund_state' => $result['state'],
                                    'paypal_refund_amount' => $result['amount'],
                                    'paypal_refund_currency' => $result['currency'],
                                    'paypal_refund_sale_id' => $result['sale_id'],
                                    'paypal_refunded_at' => now()->toDateTimeString(),
                                    'paypal_refunded_by' => auth()->id(),
                                ]);

                                if (! empty($data['reopen_payment'])) {
                                    $updates = ['paid_at' => null];

                                    // Keep the reopened queue from being purged as an
                                    // expired unpaid payment by the core Lottery cleanup.
                                    if ($record->expired_at && $record->expired_at->isPast()) {
                                        $updates['expired_at'] = now()->addDays(14);
                                    }

                                    $record->update($updates);
                                }

                                Log::info('[PaypalRefund] Refund issued', [
                                    'payment_id' => $record->getKey(),
                                    'paypal_payment_id' => $record->getMeta('paypal_payment_id'),
                                    'refund_id' => $result['refund_id'],
                                    'amount' => $result['amount'],
                                    'currency' => $result['currency'],
                                    'user_id' => auth()->id(),
                                ]);

                                Notification::make()
                                    ->title('Refund issued successfully')
                                    ->body("PayPal refund {$result['refund_id']} ({$result['amount']} {$result['currency']}) — state: {$result['state']}.")
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Log::error('[PaypalRefund] Refund failed', [
                                    'payment_id' => $record->getKey(),
                                    'error' => $e->getMessage(),
                                ]);

                                Notification::make()
                                    ->title('Refund failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }
}
