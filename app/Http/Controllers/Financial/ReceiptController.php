<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\ReceiptService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ReceiptController extends Controller
{
    public function show(
        string $transactionId,
        ReceiptService $receiptService
    ): JsonResponse {
        try {
            $receipt = $receiptService->forTransaction(
                $transactionId
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'meta' => $this->meta(),
            ], 409);
        }

        return response()->json([
            'data' => $receipt,
            'meta' => $this->meta(),
        ]);
    }

    public function verify(
        string $verificationId,
        ReceiptService $receiptService
    ): JsonResponse {
        $result = $receiptService->verify(
            $verificationId
        );

        return response()->json([
            'data' => $result ?? [
                'valid' => false,
            ],
            'meta' => $this->meta(),
        ]);
    }

    private function meta(): array
    {
        $requestId = request()->header(
            'X-Request-Id',
            (string) str()->uuid()
        );

        return [
            'request_id' => $requestId,
            'correlation_id' => request()->header(
                'X-Correlation-Id',
                $requestId
            ),
            'api_version' => 'v1',
        ];
    }
}
