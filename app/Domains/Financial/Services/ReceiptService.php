<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialReceipt;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReceiptService
{
    public function forTransaction(string $transactionId): array
    {
        return DB::connection('sqlsrv')->transaction(
            function () use ($transactionId): array {
                $transaction = FinancialTransaction::where(
                    'public_id',
                    $transactionId
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertFinalTransaction($transaction);

                $entries = $this->entriesFor(
                    $transaction->public_id
                );

                if ($entries->isEmpty()) {
                    throw new InvalidArgumentException(
                        'La transacción todavía no tiene evidencia contable para generar un comprobante.'
                    );
                }

                $source = $this->resolveSourceEvidence(
                    $transaction,
                    $entries
                );

                $record = $this->receiptRecord(
                    $transaction,
                    $source['folio']
                );

                return $this->buildReceipt(
                    $record,
                    $transaction,
                    $entries,
                    $source
                );
            }
        );
    }

    public function forWallet(
        Wallet $wallet,
        string $transactionId
    ): array {
        LedgerEntry::where(
            'transaction_id',
            $transactionId
        )
            ->where('wallet_id', $wallet->public_id)
            ->firstOrFail();

        $receipt = $this->forTransaction(
            $transactionId
        );

        $receipt['entries'] = array_values(
            array_filter(
                $receipt['entries'],
                fn (array $entry) =>
                    strtolower($entry['wallet_id'])
                    === strtolower($wallet->public_id)
            )
        );

        return $receipt;
    }

    public function listForWallet(
        Wallet $wallet,
        int $limit = 50
    ): array {
        $transactionIds = LedgerEntry::where(
            'wallet_id',
            $wallet->public_id
        )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('transaction_id')
            ->unique()
            ->values();

        if ($transactionIds->isEmpty()) {
            return [];
        }

        $transactions = FinancialTransaction::whereIn(
            'public_id',
            $transactionIds
        )
            ->whereIn('status', [
                TransactionStatus::COMPLETADA->value,
                TransactionStatus::REVERTIDA->value,
            ])
            ->get()
            ->keyBy(
                fn (FinancialTransaction $transaction) =>
                    strtolower($transaction->public_id)
            );

        $items = [];

        foreach ($transactionIds as $transactionId) {
            if (
                !$transactions->has(
                    strtolower($transactionId)
                )
            ) {
                continue;
            }

            try {
                $receipt = $this->forWallet(
                    $wallet,
                    $transactionId
                );
            } catch (InvalidArgumentException) {
                continue;
            }

            $items[] = [
                'receipt_id' => $receipt['receipt_id'],
                'transaction_id' =>
                    $receipt['transaction_id'],
                'folio' => $receipt['folio'],
                'status' => $receipt['status'],
                'total_amount_cents' =>
                    $receipt['total_amount_cents'],
                'currency' => $receipt['currency'],
                'payment_method' =>
                    $receipt['payment_method'],
                'reference_type' =>
                    $receipt['reference_type'],
                'reference_id' =>
                    $receipt['reference_id'],
                'issued_at' => $receipt['issued_at'],
            ];
        }

        return $items;
    }

    public function verify(
        string $verificationId
    ): ?array {
        $record = FinancialReceipt::where(
            'verification_id',
            $verificationId
        )->first();

        if (!$record) {
            return null;
        }

        try {
            $receipt = $this->forTransaction(
                $record->transaction_id
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'valid' => true,
            'folio' => $receipt['folio'],
            'status' => $receipt['status'],
            'issued_at' => $receipt['issued_at'],
            'currency' => $receipt['currency'],
            'total_amount_cents' =>
                $receipt['total_amount_cents'],
        ];
    }

    private function assertFinalTransaction(
        FinancialTransaction $transaction
    ): void {
        if (
            !in_array(
                $transaction->status,
                [
                    TransactionStatus::COMPLETADA,
                    TransactionStatus::REVERTIDA,
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Solo las transacciones con estado financiero final pueden emitir un comprobante.'
            );
        }
    }

    private function entriesFor(
        string $transactionId
    ): Collection {
        return LedgerEntry::where(
            'transaction_id',
            $transactionId
        )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function receiptRecord(
        FinancialTransaction $transaction,
        ?string $folio
    ): FinancialReceipt {
        $record = FinancialReceipt::where(
            'transaction_id',
            $transaction->public_id
        )->first();

        if ($record) {
            return $record;
        }

        return FinancialReceipt::create([
            'public_id' => (string) Str::uuid(),
            'transaction_id' => $transaction->public_id,
            /*
             * No se genera un folio nuevo porque el formato
             * y el alcance de unicidad siguen pendientes.
             */
            'folio' => $folio,
            'verification_id' => Str::random(64),
        ]);
    }

    private function resolveSourceEvidence(
        FinancialTransaction $transaction,
        Collection $entries
    ): array {
        $metadata = is_array($transaction->metadata)
            ? $transaction->metadata
            : [];

        $currency = $this->resolveCurrency($entries);
        $totalAmountCents = $this->resolveLedgerTotal(
            $entries,
            $metadata
        );

        $result = [
            'folio' => null,
            'currency' => $currency,
            'total_amount_cents' => $totalAmountCents,
            'payment_method' => null,
            'payment_components' => [],
            'external_references' => array_filter([
                'reference_type' =>
                    $transaction->reference_type,
                'reference_id' =>
                    $transaction->reference_id,
                'event_id' => $metadata['event_id'] ?? null,
                'registration_id' =>
                    $metadata['registration_id'] ?? null,
            ], fn ($value) => $value !== null),
        ];

        $referenceType = strtoupper(
            (string) $transaction->reference_type
        );

        if (
            $referenceType === 'TOPUP'
            && $transaction->reference_id
        ) {
            $topUp = TopUp::where(
                'public_id',
                $transaction->reference_id
            )->first();

            if ($topUp) {
                $result['folio'] = $topUp->folio;
                $result['currency'] = $topUp->currency;
                $result['total_amount_cents'] =
                    $topUp->amount_cents;
                $result['payment_method'] =
                    $topUp->method->value;
                $result['payment_components'] = [[
                    'type' => 'TOPUP',
                    'amount_cents' => $topUp->amount_cents,
                ]];

                if ($topUp->external_reference) {
                    $result['external_references']
                        ['external_reference'] =
                            $topUp->external_reference;
                }
            }

            return $result;
        }

        if (
            $referenceType === 'WITHDRAWAL'
            && $transaction->reference_id
        ) {
            $withdrawal = Withdrawal::where(
                'public_id',
                $transaction->reference_id
            )->first();

            if ($withdrawal) {
                $result['folio'] = $withdrawal->folio;
                $result['currency'] =
                    $withdrawal->currency;
                $result['total_amount_cents'] =
                    $withdrawal->amount_cents;
                $result['payment_method'] =
                    $withdrawal->method->value;
                $result['payment_components'] = [[
                    'type' => 'WITHDRAWAL',
                    'amount_cents' =>
                        $withdrawal->amount_cents,
                ]];

                if ($withdrawal->external_reference) {
                    $result['external_references']
                        ['external_reference'] =
                            $withdrawal->external_reference;
                }
            }

            return $result;
        }

        if ($referenceType === 'PURCHASE') {
            /*
             * La implementación actual de pagos no garantiza
             * que reference_id sea el PurchasePayment.
             * Se usa la idempotency_key compartida, que sí es
             * una referencia técnica estable observada.
             */
            $payment = PurchasePayment::where(
                'idempotency_key',
                $transaction->idempotency_key
            )->first();

            if ($payment) {
                $result['currency'] = $payment->currency;
                $result['total_amount_cents'] =
                    $payment->total_amount_cents;

                if ($payment->wallet_amount_cents > 0) {
                    $result['payment_components'][] = [
                        'type' => 'WALLET',
                        'amount_cents' =>
                            $payment->wallet_amount_cents,
                    ];
                }

                $bonusRows = $payment->bonuses()->get();

                if ($bonusRows->isNotEmpty()) {
                    foreach ($bonusRows as $bonusRow) {
                        $result['payment_components'][] = [
                            'type' => 'BONUS',
                            'reference_id' =>
                                $bonusRow->bonus_id,
                            'amount_cents' =>
                                $bonusRow->amount_cents,
                        ];
                    }
                } elseif ($payment->bonus_amount_cents > 0) {
                    $result['payment_components'][] = [
                        'type' => 'BONUS',
                        'reference_id' =>
                            $payment->bonus_id,
                        'amount_cents' =>
                            $payment->bonus_amount_cents,
                    ];
                }

                /*
                 * payment_method queda null para compras.
                 * Los valores aceptados para pagos wallet/bono
                 * están marcados como decisión pendiente.
                 */
                $result['external_references'] = array_filter(
                    array_merge(
                        $result['external_references'],
                        [
                            'purchase_payment_id' =>
                                $payment->public_id,
                            'business_id' =>
                                $payment->business_id,
                            'category_id' =>
                                $payment->category_id,
                        ]
                    ),
                    fn ($value) => $value !== null
                );
            }
        }

        return $result;
    }

    private function resolveCurrency(
        Collection $entries
    ): ?string {
        $walletIds = $entries
            ->pluck('wallet_id')
            ->unique()
            ->values();

        return Wallet::whereIn(
            'public_id',
            $walletIds
        )
            ->value('currency');
    }

    private function resolveLedgerTotal(
        Collection $entries,
        array $metadata
    ): int {
        if (
            isset($metadata['total_amount_cents'])
            && is_numeric(
                $metadata['total_amount_cents']
            )
        ) {
            return abs(
                (int) $metadata['total_amount_cents']
            );
        }

        return (int) $entries
            ->map(
                fn (LedgerEntry $entry) =>
                    abs($entry->amount_cents)
            )
            ->max();
    }

    private function buildReceipt(
        FinancialReceipt $record,
        FinancialTransaction $transaction,
        Collection $entries,
        array $source
    ): array {
        $walletIds = $entries
            ->pluck('wallet_id')
            ->unique()
            ->values();

        $wallets = Wallet::whereIn(
            'public_id',
            $walletIds
        )
            ->get()
            ->keyBy(
                fn (Wallet $wallet) =>
                    strtolower($wallet->public_id)
            );

        return [
            'receipt_id' => $record->public_id,
            'transaction_id' => $transaction->public_id,
            'folio' => $record->folio,
            'status' => $transaction->status->value,
            'issued_at' =>
                $record->created_at?->toISOString(),
            'currency' => $source['currency'],
            'total_amount_cents' =>
                $source['total_amount_cents'],
            'payment_method' =>
                $source['payment_method'],
            'payment_components' =>
                $source['payment_components'],
            'reference_type' =>
                $transaction->reference_type,
            'reference_id' =>
                $transaction->reference_id,
            'original_transaction_id' =>
                $transaction->original_transaction_id,
            'external_references' =>
                $source['external_references'],
            'verification' => [
                'id' => $record->verification_id,
                'url' => url(
                    '/api/v1/financial/receipts/'
                    . $record->verification_id
                ),
            ],
            'entries' => $entries
                ->map(function (LedgerEntry $entry) use ($wallets) {
                    $wallet = $wallets->get(
                        strtolower($entry->wallet_id)
                    );

                    return [
                        'id' => $entry->public_id,
                        'wallet_id' => $entry->wallet_id,
                        'currency' => $wallet?->currency,
                        'movement_type' =>
                            $entry->movement_type->value,
                        'amount_cents' =>
                            $entry->amount_cents,
                        'balance_after_cents' =>
                            $entry->balance_after_cents,
                        'available_balance_after_cents' =>
                            $entry
                                ->available_balance_after_cents,
                        'held_balance_after_cents' =>
                            $entry
                                ->held_balance_after_cents,
                        'created_at' =>
                            $entry->created_at?->toISOString(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
