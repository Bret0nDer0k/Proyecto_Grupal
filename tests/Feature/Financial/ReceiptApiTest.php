<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialReceipt;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\ReceiptService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Middleware\ValidateServiceToken;
use Illuminate\Support\Str;

afterEach(function () {
    $transactions = FinancialTransaction::whereIn(
        'idempotency_key',
        [
            'test-receipt-api-credit',
            'test-receipt-api-pending',
        ]
    )->get();

    FinancialReceipt::whereIn(
        'transaction_id',
        $transactions->pluck('public_id')
    )->delete();

    foreach ($transactions as $transaction) {
        LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )->delete();

        $transaction->delete();
    }

    Wallet::where(
        'owner_id',
        'test-receipt-api-user'
    )->delete();
});

test('returns complete receipt evidence through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-receipt-api-user',
        WalletType::USUARIO
    );

    $transaction = app(LedgerService::class)->credit(
        $wallet,
        9500,
        MovementType::RECARGA,
        'test-receipt-api-credit',
        'TEST',
        'receipt-api-001'
    );

    $response = $this->withHeaders([
        'X-Correlation-Id' => 'receipt-correlation-test',
    ])->getJson(
        '/api/v1/financial/transactions/'
            . $transaction->public_id
            . '/receipt'
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.transaction_id',
            $transaction->public_id
        )
        ->assertJsonPath(
            'data.status',
            'COMPLETADA'
        )
        ->assertJsonPath(
            'data.total_amount_cents',
            9500
        )
        ->assertJsonPath(
            'data.currency',
            'MXN'
        )
        ->assertJsonPath(
            'data.entries.0.movement_type',
            'RECARGA'
        )
        ->assertJsonPath(
            'meta.correlation_id',
            'receipt-correlation-test'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
});

test('returns conflict when transaction is not final evidence', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $transaction = FinancialTransaction::create([
        'public_id' => (string) Str::uuid(),
        'idempotency_key' => 'test-receipt-api-pending',
        'status' => TransactionStatus::PENDIENTE,
        'reference_type' => 'TEST',
        'reference_id' => 'pending',
    ]);

    $this->getJson(
        '/api/v1/financial/transactions/'
            . $transaction->public_id
            . '/receipt'
    )->assertStatus(409);
});

test('public verification returns only minimum safe evidence', function () {
    $wallet = app(WalletService::class)->create(
        'USER',
        'test-receipt-api-user',
        WalletType::USUARIO
    );

    $transaction = app(LedgerService::class)->credit(
        $wallet,
        9500,
        MovementType::RECARGA,
        'test-receipt-api-credit',
        'TEST',
        'receipt-api-001'
    );

    $receipt = app(ReceiptService::class)->forTransaction(
        $transaction->public_id
    );

    $response = $this->getJson(
        '/api/v1/financial/receipts/'
            . $receipt['verification']['id']
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.status', 'COMPLETADA')
        ->assertJsonPath('data.currency', 'MXN')
        ->assertJsonPath('data.total_amount_cents', 9500)
        ->assertJsonMissingPath('data.transaction_id')
        ->assertJsonMissingPath('data.entries')
        ->assertJsonMissingPath('data.wallet_id')
        ->assertJsonMissingPath('data.available_balance_cents');
});

test('invalid verification id returns a safe negative result', function () {
    $response = $this->getJson(
        '/api/v1/financial/receipts/'
            . Str::random(64)
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('meta.api_version', 'v1')
        ->assertJsonMissingPath('data.transaction_id')
        ->assertJsonMissingPath('data.wallet_id')
        ->assertJsonMissingPath('data.total_amount_cents');
});
