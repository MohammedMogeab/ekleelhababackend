<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    private function logHyperPayPayment(array $data): int
    {
        return DB::table('oc_hyperpay_payment')->insertGetId([
            'order_id' => $data['order_id'] ?? null,
            'checkout_id' => $data['checkout_id'] ?? null,
            'payment_id' => $data['payment_id'] ?? null,
            'payment_type' => $data['payment_type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'result_code' => $data['result_code'] ?? null,
            'result_description' => $data['result_description'] ?? null,
            'brand' => $data['brand'] ?? null,
            'card_bin' => $data['card_bin'] ?? null,
            'card_last4' => $data['card_last4'] ?? null,
            'status' => $data['status'],
            'raw_response' => json_encode($data['raw_response'] ?? null, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Hosted Checkout – requires order_id
     */
    public function requestPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:oc_order,order_id',
            'amount'   => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:SAR,USD,EUR,AED',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $url = env('HYPERPAY_BASE_URL') . "/v1/checkouts";

        $payload = [
            'entityId'    => env('HYPERPAY_ENTITY_ID', '8a8294174d0595bb014d05d829cb01cd'),
            'amount'      => $request->amount,
            'currency'    => $request->currency,
            'paymentType' => 'DB',
            'integrity'   => true,
        ];

        $response = Http::withHeaders([
            'Authorization' => env('HYPERPAY_AUTH_TOKEN', 'Bearer OGE4Mjk0MTc0ZDA1OTViYjAxNGQwNWQ4MjllNzAxZDF8OVRuSlBjMm45aA=='),
        ])
        ->asForm()
        ->withoutVerifying()
        ->post($url, $payload);

        $responseData = $response->json();

        // Log to oc_hyperpay_payment
        $this->logHyperPayPayment([
            'order_id' => $request->order_id,
            'checkout_id' => $responseData['id'] ?? null,
            'payment_type' => 'DB',
            'amount' => $request->amount,
            'currency' => $request->currency,
            'result_code' => $responseData['result']['code'] ?? null,
            'result_description' => $responseData['result']['description'] ?? null,
            'status' => $response->successful() && isset($responseData['id']) ? 'success' : 'failed',
            'raw_response' => $responseData,
        ]);

        // Also update oc_order.transaction_id
        if (isset($responseData['id'])) {
            DB::table('oc_order')
                ->where('order_id', $request->order_id)
                ->update(['transaction_id' => $responseData['id']]);
        }

        return response()->json([
            'success' => $response->successful(),
            'data' => $responseData,
            'status' => $response->status(),
        ]);
    }

    /**
     * Rebill (RB) – capture from pre-auth
     */
    public function rebillPayment(Request $request, int $order_id)
    {
        return $this->processPostAction($request, $order_id, 'RB');
    }

    /**
     * Refund (RF)
     */
    public function refundPayment(Request $request, int $order_id)
    {
        return $this->processPostAction($request, $order_id, 'RF');
    }

    /**
     * Reverse (RV)
     */
    public function reversePayment(Request $request, int $order_id)
    {
        return $this->processPostAction($request, $order_id, 'RV');
    }

    private function processPostAction(Request $request, int $order_id, string $paymentType)
    {
        $validator = Validator::make($request->all(), [
            'amount'   => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:SAR,USD,EUR,AED',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Get original payment_id from oc_hyperpay_payment (latest successful PA/DB)
        $original = DB::table('oc_hyperpay_payment')
            ->where('order_id', $order_id)
            ->whereIn('payment_type', ['PA', 'DB'])
            ->where('status', 'success')
            ->orderBy('id', 'desc')
            ->first();

        if (!$original || empty($original->payment_id)) {
            return response()->json(['success' => false, 'message' => 'No valid payment found for this order'], 404);
        }

        $url = rtrim(env('HYPERPAY_BASE_URL'), '/') . "/v1/payments/{$original->payment_id}";

        $payload = [
            'entityId'    => env('HYPERPAY_ENTITY_ID'),
            'amount'      => $request->amount,
            'currency'    => $request->currency,
            'paymentType' => $paymentType,
        ];

        $response = Http::withHeaders([
            'Authorization' => env('HYPERPAY_AUTH_TOKEN'),
        ])
        ->asForm()
        ->withoutVerifying()
        ->post($url, $payload);

        $responseData = $response->json();

        // Determine status
        $statusMap = ['RB' => 'captured', 'RF' => 'refunded', 'RV' => 'reversed'];
        $status = $response->successful() ? ($statusMap[$paymentType] ?? 'processed') : 'failed';

        // Log the action
        $this->logHyperPayPayment([
            'order_id' => $order_id,
            'payment_id' => $responseData['id'] ?? null,
            'payment_type' => $paymentType,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'result_code' => $responseData['result']['code'] ?? null,
            'result_description' => $responseData['result']['description'] ?? null,
            'status' => $status,
            'raw_response' => $responseData,
        ]);

        return response()->json([
            'success' => $response->successful(),
            'data' => $responseData,
            'status' => $response->status(),
        ]);
    }

    /**
     * Check payment status by order_id
     */
    public function paymentStatusByOrder(int $order_id)
    {
        $order = DB::table('oc_order')->where('order_id', $order_id)->first();
        if (!$order || empty($order->transaction_id)) {
            return response()->json(['success' => false, 'message' => 'No transaction ID'], 404);
        }

        $url = env('HYPERPAY_BASE_URL') . "/v1/checkouts/{$order->transaction_id}/payment?entityId=" . env('HYPERPAY_ENTITY_ID');

        $response = Http::withHeaders([
            'Authorization' => env('HYPERPAY_AUTH_TOKEN'),
        ])
        ->withoutVerifying()
        ->get($url);

        return response()->json([
            'success' => $response->successful(),
            'data' => $response->json(),
            'status' => $response->status(),
        ]);
    }

    /**
     * Callback from HyperPay
     */
    public function callback(Request $request)
    {
        $checkoutId = $request->query('id');
        if (!$checkoutId) {
            return response()->json(['error' => 'Missing checkout ID'], 400);
        }

        $url = env('HYPERPAY_BASE_URL') . "/v1/checkouts/{$checkoutId}/payment?entityId=" . env('HYPERPAY_ENTITY_ID');
        $response = Http::withHeaders([
            'Authorization' => env('HYPERPAY_AUTH_TOKEN'),
        ])->withoutVerifying()->get($url);

        $data = $response->json();

        // Find order
        $order = DB::table('oc_order')->where('transaction_id', $checkoutId)->first();

        if ($order) {
            $status = str_starts_with($data['result']['code'] ?? '', '000.') ? 'success' : 'failed';
            $this->logHyperPayPayment([
                'order_id' => $order->order_id,
                'payment_id' => $data['id'] ?? null,
                'payment_type' => 'DB',
                'amount' => $data['amount'] ?? 0,
                'currency' => $data['currency'] ?? 'SAR',
                'result_code' => $data['result']['code'] ?? null,
                'result_description' => $data['result']['description'] ?? null,
                'brand' => $data['paymentBrand'] ?? null,
                'card_bin' => $data['card']['bin'] ?? null,
                'card_last4' => $data['card']['last4Digits'] ?? null,
                'status' => $status,
                'raw_response' => $data,
            ]);

            // Update order status if success
            if ($status === 'success') {
                DB::table('oc_order')->where('order_id', $order->order_id)->update([
                    'order_status_id' => 2, // Processing
                    'date_modified' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Callback processed',
            'order_id' => $order->order_id ?? null,
            'result' => $data,
        ]);
    }
}