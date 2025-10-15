<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class WebhookController extends Controller
{
    /**
     * Handle Tap Payments webhooks.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tap(Request $request)
    {
        $signature = $request->header('X-Tap-Signature');
        $secret = config('services.tap.webhook_secret');
        
        // Verify webhook signature
        if (!$this->verifyTapSignature($request->getContent(), $signature, $secret)) {
            Log::error('Invalid Tap webhook signature', [
                'signature' => $signature,
                'payload' => $request->all()
            ]);
            
            return response()->json([
                'error' => 'Invalid signature'
            ], 400);
        }
        
        $payload = json_decode($request->getContent(), true);
        $eventType = $payload['event'] ?? '';
        
        try {
            switch ($eventType) {
                case 'charge.succeeded':
                    return $this->handleTapPaymentSucceeded($payload);
                case 'charge.failed':
                    return $this->handleTapPaymentFailed($payload);
                case 'charge.cancelled':
                    return $this->handleTapPaymentCancelled($payload);
                default:
                    Log::info('Unhandled Tap event', [
                        'event' => $eventType
                    ]);
                    
                    return response()->json([
                        'status' => 'ignored'
                    ]);
            }
        } catch (\Exception $e) {
            Log::error('Tap webhook processing failed', [
                'event' => $eventType,
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Webhook processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tamara webhooks.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tamara(Request $request)
    {
        $signature = $request->header('X-Tamara-Signature');
        $secret = config('services.tamara.webhook_secret');
        
        // Verify webhook signature
        if (!$this->verifyTamaraSignature($request->getContent(), $signature, $secret)) {
            Log::error('Invalid Tamara webhook signature', [
                'signature' => $signature,
                'payload' => $request->all()
            ]);
            
            return response()->json([
                'error' => 'Invalid signature'
            ], 400);
        }
        
        $payload = json_decode($request->getContent(), true);
        $eventType = $payload['event_type'] ?? '';
        
        try {
            switch ($eventType) {
                case 'order.approved':
                    return $this->handleTamaraOrderApproved($payload);
                case 'order.canceled':
                    return $this->handleTamaraOrderCanceled($payload);
                case 'order.shipped':
                    return $this->handleTamaraOrderShipped($payload);
                case 'order.delivered':
                    return $this->handleTamaraOrderDelivered($payload);
                case 'order.refunded':
                    return $this->handleTamaraOrderRefunded($payload);
                default:
                    Log::info('Unhandled Tamara event', [
                        'event' => $eventType
                    ]);
                    
                    return response()->json([
                        'status' => 'ignored'
                    ]);
            }
        } catch (\Exception $e) {
            Log::error('Tamara webhook processing failed', [
                'event' => $eventType,
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Webhook processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify Tap webhook signature.
     *
     * @param string $payload
     * @param string $signature
     * @param string $secret
     * @return bool
     */
    private function verifyTapSignature($payload, $signature, $secret)
    {
        $computedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Verify Tamara webhook signature.
     *
     * @param string $payload
     * @param string $signature
     * @param string $secret
     * @return bool
     */
    private function verifyTamaraSignature($payload, $signature, $secret)
    {
        $computedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Handle Tap payment succeeded.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTapPaymentSucceeded($payload)
    {
        $charge = $payload['data']['object'];
        $orderId = $charge['metadata']['order_id'] ?? null;
        
        if (!$orderId) {
            Log::error('Order ID not found in Tap webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $charge) {
                // Update order status
                $order = DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->lockForUpdate()
                    ->first();
                
                if (!$order) {
                    throw new \Exception("Order not found: {$orderId}");
                }
                
                // Only update if not already processed
                if ($order->order_status_id === 1) { // Pending
                    $processingStatusId = DB::table('oc_order_status')
                        ->where('name', 'like', '%processing%')
                        ->value('order_status_id') ?? 2;
                    
                    DB::table('oc_order')
                        ->where('order_id', $orderId)
                        ->update([
                            'order_status_id' => $processingStatusId,
                            'payment_method' => 'tap',
                            'date_modified' => now()
                        ]);
                    
                    // Add order history
                    DB::table('oc_order_history')->insert([
                        'order_id' => $orderId,
                        'order_status_id' => $processingStatusId,
                        'notify' => 1,
                        'comment' => 'Payment completed via Tap Payments',
                        'date_added' => now()
                    ]);
                }
                
                // Update Tap payment record
                DB::table('oc_tap_payments')
                    ->where('reference_id', $charge['id'])
                    ->update([
                        'status' => 'success',
                        'updated_at' => now()
                    ]);
            });
            
            Log::info('Tap payment processed successfully', [
                'order_id' => $orderId,
                'charge_id' => $charge['id']
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tap payment', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process payment',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tap payment failed.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTapPaymentFailed($payload)
    {
        $charge = $payload['data']['object'];
        $orderId = $charge['metadata']['order_id'] ?? null;
        
        if (!$orderId) {
            Log::error('Order ID not found in Tap webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $charge) {
                // Update order status
                $order = DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->lockForUpdate()
                    ->first();
                
                if (!$order) {
                    throw new \Exception("Order not found: {$orderId}");
                }
                
                $failedStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%failed%')
                    ->value('order_status_id') ?? 10;
                
                DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->update([
                        'order_status_id' => $failedStatusId,
                        'date_modified' => now()
                    ]);
                
                // Add order history
                DB::table('oc_order_history')->insert([
                    'order_id' => $orderId,
                    'order_status_id' => $failedStatusId,
                    'notify' => 1,
                    'comment' => 'Payment failed via Tap Payments: ' . ($charge['failure_reason'] ?? 'Unknown reason'),
                    'date_added' => now()
                ]);
                
                // Update Tap payment record
                DB::table('oc_tap_payments')
                    ->where('reference_id', $charge['id'])
                    ->update([
                        'status' => 'failed',
                        'updated_at' => now()
                    ]);
            });
            
            Log::info('Tap payment failed', [
                'order_id' => $orderId,
                'charge_id' => $charge['id'],
                'reason' => $charge['failure_reason'] ?? 'Unknown reason'
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tap payment failure', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process payment failure',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tap payment cancelled.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTapPaymentCancelled($payload)
    {
        $charge = $payload['data']['object'];
        $orderId = $charge['metadata']['order_id'] ?? null;
        
        if (!$orderId) {
            Log::error('Order ID not found in Tap webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $charge) {
                // Update order status
                $order = DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->lockForUpdate()
                    ->first();
                
                if (!$order) {
                    throw new \Exception("Order not found: {$orderId}");
                }
                
                $cancelledStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%canceled%')
                    ->value('order_status_id') ?? 7;
                
                DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->update([
                        'order_status_id' => $cancelledStatusId,
                        'date_modified' => now()
                    ]);
                
                // Add order history
                DB::table('oc_order_history')->insert([
                    'order_id' => $orderId,
                    'order_status_id' => $cancelledStatusId,
                    'notify' => 1,
                    'comment' => 'Payment cancelled via Tap Payments',
                    'date_added' => now()
                ]);
                
                // Update Tap payment record
                DB::table('oc_tap_payments')
                    ->where('reference_id', $charge['id'])
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => now()
                    ]);
            });
            
            Log::info('Tap payment cancelled', [
                'order_id' => $orderId,
                'charge_id' => $charge['id']
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tap payment cancellation', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process payment cancellation',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tamara order approved.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTamaraOrderApproved($payload)
    {
        $orderId = $payload['data']['order']['reference_id'] ?? null;
        $tamaraOrderId = $payload['data']['order']['id'] ?? null;
        
        if (!$orderId || !$tamaraOrderId) {
            Log::error('Order ID not found in Tamara webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $tamaraOrderId) {
                // Update order status
                $order = DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->lockForUpdate()
                    ->first();
                
                if (!$order) {
                    throw new \Exception("Order not found: {$orderId}");
                }
                
                // Only update if not already processed
                if ($order->order_status_id === 1) { // Pending
                    $processingStatusId = DB::table('oc_order_status')
                        ->where('name', 'like', '%processing%')
                        ->value('order_status_id') ?? 2;
                    
                    DB::table('oc_order')
                        ->where('order_id', $orderId)
                        ->update([
                            'order_status_id' => $processingStatusId,
                            'payment_method' => 'tamara',
                            'date_modified' => now()
                        ]);
                    
                    // Add order history
                    DB::table('oc_order_history')->insert([
                        'order_id' => $orderId,
                        'order_status_id' => $processingStatusId,
                        'notify' => 1,
                        'comment' => 'Payment approved via Tamara',
                        'date_added' => now()
                    ]);
                }
                
                // Update Tamara payment record
                DB::table('oc_tamara_orders')
                    ->where('tamara_order_id', $tamaraOrderId)
                    ->update([
                        'is_authorised' => 1,
                        'updated_at' => now()
                    ]);
            });
            
            Log::info('Tamara payment approved', [
                'order_id' => $orderId,
                'tamara_order_id' => $tamaraOrderId
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tamara payment approval', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process payment approval',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tamara order canceled.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTamaraOrderCanceled($payload)
    {
        $orderId = $payload['data']['order']['reference_id'] ?? null;
        $tamaraOrderId = $payload['data']['order']['id'] ?? null;
        
        if (!$orderId || !$tamaraOrderId) {
            Log::error('Order ID not found in Tamara webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $tamaraOrderId) {
                // Update order status
                $order = DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->lockForUpdate()
                    ->first();
                
                if (!$order) {
                    throw new \Exception("Order not found: {$orderId}");
                }
                
                $cancelledStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%canceled%')
                    ->value('order_status_id') ?? 7;
                
                DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->update([
                        'order_status_id' => $cancelledStatusId,
                        'date_modified' => now()
                    ]);
                
                // Add order history
                DB::table('oc_order_history')->insert([
                    'order_id' => $orderId,
                    'order_status_id' => $cancelledStatusId,
                    'notify' => 1,
                    'comment' => 'Order cancelled via Tamara',
                    'date_added' => now()
                ]);
                
                // Update Tamara payment record
                DB::table('oc_tamara_orders')
                    ->where('tamara_order_id', $tamaraOrderId)
                    ->update([
                        'is_authorised' => 0,
                        'updated_at' => now()
                    ]);
            });
            
            Log::info('Tamara order cancelled', [
                'order_id' => $orderId,
                'tamara_order_id' => $tamaraOrderId
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tamara order cancellation', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process order cancellation',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tamara order shipped.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTamaraOrderShipped($payload)
    {
        $orderId = $payload['data']['order']['reference_id'] ?? null;
        $tamaraOrderId = $payload['data']['order']['id'] ?? null;
        
        if (!$orderId || !$tamaraOrderId) {
            Log::error('Order ID not found in Tamara webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $tamaraOrderId, $payload) {
                // Update order status
                $shippedStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%shipped%')
                    ->value('order_status_id') ?? 3;
                
                DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->update([
                        'order_status_id' => $shippedStatusId,
                        'date_modified' => now()
                    ]);
                
                // Add order history
                DB::table('oc_order_history')->insert([
                    'order_id' => $orderId,
                    'order_status_id' => $shippedStatusId,
                    'notify' => 1,
                    'comment' => 'Order shipped via Tamara',
                    'date_added' => now()
                ]);
                
                // Update shipping info if available
                $shippingInfo = $payload['data']['order']['shipping_info'] ?? null;
                if ($shippingInfo) {
                    DB::table('oc_order')
                        ->where('order_id', $orderId)
                        ->update([
                            'shipping_method' => $shippingInfo['carrier'] ?? $shippingInfo['method'] ?? 'Tamara Shipping',
                            'tracking' => $shippingInfo['tracking_number'] ?? ''
                        ]);
                }
            });
            
            Log::info('Tamara order shipped', [
                'order_id' => $orderId,
                'tamara_order_id' => $tamaraOrderId
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tamara order shipped', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process order shipped',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tamara order delivered.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTamaraOrderDelivered($payload)
    {
        $orderId = $payload['data']['order']['reference_id'] ?? null;
        $tamaraOrderId = $payload['data']['order']['id'] ?? null;
        
        if (!$orderId || !$tamaraOrderId) {
            Log::error('Order ID not found in Tamara webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $tamaraOrderId) {
                // Update order status
                $deliveredStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%delivered%')
                    ->value('order_status_id') ?? 5;
                
                DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->update([
                        'order_status_id' => $deliveredStatusId,
                        'date_modified' => now()
                    ]);
                
                // Add order history
                DB::table('oc_order_history')->insert([
                    'order_id' => $orderId,
                    'order_status_id' => $deliveredStatusId,
                    'notify' => 1,
                    'comment' => 'Order delivered via Tamara',
                    'date_added' => now()
                ]);
            });
            
            Log::info('Tamara order delivered', [
                'order_id' => $orderId,
                'tamara_order_id' => $tamaraOrderId
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tamara order delivered', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process order delivered',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle Tamara order refunded.
     *
     * @param array $payload
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleTamaraOrderRefunded($payload)
    {
        $orderId = $payload['data']['order']['reference_id'] ?? null;
        $tamaraOrderId = $payload['data']['order']['id'] ?? null;
        
        if (!$orderId || !$tamaraOrderId) {
            Log::error('Order ID not found in Tamara webhook', [
                'payload' => $payload
            ]);
            
            return response()->json([
                'error' => 'Order ID missing'
            ], 400);
        }
        
        try {
            DB::transaction(function () use ($orderId, $tamaraOrderId, $payload) {
                // Update order status
                $refundedStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%refunded%')
                    ->value('order_status_id') ?? 11;
                
                DB::table('oc_order')
                    ->where('order_id', $orderId)
                    ->update([
                        'order_status_id' => $refundedStatusId,
                        'date_modified' => now()
                    ]);
                
                // Add order history
                $amount = $payload['data']['refund']['amount'] ?? 'full';
                DB::table('oc_order_history')->insert([
                    'order_id' => $orderId,
                    'order_status_id' => $refundedStatusId,
                    'notify' => 1,
                    'comment' => 'Order refunded via Tamara: ' . $amount,
                    'date_added' => now()
                ]);
            });
            
            Log::info('Tamara order refunded', [
                'order_id' => $orderId,
                'tamara_order_id' => $tamaraOrderId
            ]);
            
            return response()->json([
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process Tamara order refund', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Failed to process order refund',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}