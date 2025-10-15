<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Create order from cart (checkout).
     *
     * @bodyParam shipping_address_id integer required Shipping address ID.
     * @bodyParam payment_method string required Payment method code.
     * @bodyParam comment string Order comment.
     * @bodyParam coupon_code string Coupon code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkout(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'shipping_address_id' => 'required|integer|exists:oc_address,address_id',
            'payment_method' => 'required|string',
            'comment' => 'nullable|string',
            'coupon_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Get cart items from oc_cart_mob (mobile cart table)
            $cartItems = DB::table('oc_cart_mob')
                ->where('customer_id', $user->customer_id)
                ->where('session_id', '0') // Mobile app uses '0' for session_id
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'message' => 'Cart is empty',
                ], 400);
            }

            // Validate stock and calculate totals
            $subtotal = 0;
            $total = 0;
            $tax = 0;
            $shipping = 15.00; // Implement dynamic shipping later

            foreach ($cartItems as $item) {
                $product = DB::table('oc_product')->where('product_id', $item->product_id)->first();
                if (!$product || $product->quantity < $item->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Product out of stock: ' . ($product->model ?? 'Unknown'),
                    ], 400);
                }

                $finalPrice = $this->getProductFinalPrice($product);
                $itemTotal = $finalPrice * $item->quantity;
                $subtotal += $itemTotal;
            }

            $total = $subtotal + $tax + $shipping;

            // Apply coupon if provided
            if ($request->filled('coupon_code')) {
                $discount = $this->applyCoupon($request->coupon_code, $subtotal);
                $total -= $discount;
            }

            // Get shipping address
            $shippingAddress = DB::table('oc_address')->where('address_id', $request->shipping_address_id)->first();
            if (!$shippingAddress) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Invalid shipping address',
                ], 400);
            }

            // Get country and zone names
            $countryName = DB::table('oc_country')->where('country_id', $shippingAddress->country_id)->value('name') ?? '';
            $zoneName = DB::table('oc_zone')->where('zone_id', $shippingAddress->zone_id)->value('name') ?? '';

            // Create order
            $orderData = [
                'invoice_prefix' => 'INV-',
                'store_id' => 0,
                'store_name' => config('app.name'),
                'store_url' => config('app.url'),
                'customer_id' => $user->customer_id,
                'customer_group_id' => $user->customer_group_id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'payment_firstname' => $user->firstname,
                'payment_lastname' => $user->lastname,
                'payment_address_1' => $shippingAddress->address_1,
                'payment_address_2' => $shippingAddress->address_2,
                'payment_city' => $shippingAddress->city,
                'payment_postcode' => $shippingAddress->postcode,
                'payment_country' => $countryName,
                'payment_country_id' => $shippingAddress->country_id,
                'payment_zone' => $zoneName,
                'payment_zone_id' => $shippingAddress->zone_id,
                'payment_method' => $request->payment_method,
                'payment_code' => $request->payment_method,
                'shipping_firstname' => $user->firstname,
                'shipping_lastname' => $user->lastname,
                'shipping_address_1' => $shippingAddress->address_1,
                'shipping_address_2' => $shippingAddress->address_2,
                'shipping_city' => $shippingAddress->city,
                'shipping_postcode' => $shippingAddress->postcode,
                'shipping_country' => $countryName,
                'shipping_country_id' => $shippingAddress->country_id,
                'shipping_zone' => $zoneName,
                'shipping_zone_id' => $shippingAddress->zone_id,
                'shipping_method' => 'Standard Shipping',
                'shipping_code' => 'standard',
                'comment' => $request->comment ?? '',
                'total' => $total,
                'order_status_id' => 1, // Pending
                'affiliate_id' => 0,
                'commission' => 0,
                'marketing_id' => 0,
                'tracking' => '',
                'language_id' => 1,
                'currency_id' => 1,
                'currency_code' => 'SAR',
                'currency_value' => 1.00000000,
                'ip' => $request->ip(),
                'forwarded_ip' => $request->header('X-Forwarded-For') ?? '',
                'user_agent' => $request->userAgent(),
                'accept_language' => $request->header('Accept-Language') ?? '',
                'date_added' => now(),
                'date_modified' => now(),
                'order_from' => 'mobile_app',
            ];

            $orderId = DB::table('oc_order')->insertGetId($orderData);

            // Add order products
            foreach ($cartItems as $item) {
                $product = DB::table('oc_product')->where('product_id', $item->product_id)->first();
                $productDescription = DB::table('oc_product_description')
                    ->where('product_id', $item->product_id)
                    ->where('language_id', 1)
                    ->first();

                $finalPrice = $this->getProductFinalPrice($product);

                DB::table('oc_order_product')->insert([
                    'order_id' => $orderId,
                    'product_id' => $product->product_id,
                    'name' => $productDescription->name ?? $product->model,
                    'model' => $product->model,
                    'quantity' => $item->quantity,
                    'price' => $finalPrice,
                    'total' => $finalPrice * $item->quantity,
                    'tax' => 0,
                    'reward' => 0,
                ]);

                // Reduce product quantity
                DB::table('oc_product')
                    ->where('product_id', $product->product_id)
                    ->decrement('quantity', $item->quantity);
            }

            // Add order totals
            $this->addOrderTotal($orderId, 'sub_total', 'Sub-Total', $subtotal, 1);
            $this->addOrderTotal($orderId, 'shipping', 'Shipping', $shipping, 2);
            $this->addOrderTotal($orderId, 'tax', 'Tax', $tax, 3);
            $this->addOrderTotal($orderId, 'total', 'Total', $total, 4);

            // Add order history
            $this->addOrderHistory($orderId, 1, 'Order created', false);

            // Clear cart
            DB::table('oc_cart_mob')
                ->where('customer_id', $user->customer_id)
                ->where('session_id', '0')
                ->delete();

            DB::commit();

            return response()->json([
                'message' => 'Order created successfully',
                'order' => [
                    'id' => $orderId,
                    'invoice_no' => 0, // OpenCart generates this on invoice generation
                    'total' => $total,
                    'status' => 'pending',
                    'date_added' => now(),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order creation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List user's orders.
     *
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $limit = $request->get('limit', 10);
        $orders = DB::table('oc_order')
            ->where('customer_id', $user->customer_id)
            ->orderBy('date_added', 'desc')
            ->paginate($limit);

        $formatted = $orders->getCollection()->map(function ($order) {
            return [
                'id' => $order->order_id,
                'invoice_no' => $order->invoice_no,
                'total' => (float) $order->total,
                'status' => $this->getOrderStatus($order->order_status_id),
                'status_id' => $order->order_status_id,
                'date_added' => $order->date_added,
                'shipping_method' => $order->shipping_method,
                'payment_method' => $order->payment_method,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get specific order details.
     *
     * @urlParam id required Order ID.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id, Request $request)
    {
        $user = $request->user();

        $order = DB::table('oc_order')
            ->where('customer_id', $user->customer_id)
            ->where('order_id', $id)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found',
            ], 404);
        }

        $orderProducts = DB::table('oc_order_product')
            ->where('order_id', $id)
            ->get()
            ->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'model' => $item->model,
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'total' => (float) $item->total,
                ];
            });

        $orderTotals = DB::table('oc_order_total')
            ->where('order_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($total) {
                return [
                    'code' => $total->code,
                    'title' => $total->title,
                    'value' => (float) $total->value,
                ];
            });

        return response()->json([
            'order' => [
                'id' => $order->order_id,
                'invoice_no' => $order->invoice_no,
                'status' => $this->getOrderStatus($order->order_status_id),
                'status_id' => $order->order_status_id,
                'date_added' => $order->date_added,
                'date_modified' => $order->date_modified,
                'shipping_address' => [
                    'firstname' => $order->shipping_firstname,
                    'lastname' => $order->shipping_lastname,
                    'address_1' => $order->shipping_address_1,
                    'address_2' => $order->shipping_address_2,
                    'city' => $order->shipping_city,
                    'postcode' => $order->shipping_postcode,
                    'country' => $order->shipping_country,
                    'zone' => $order->shipping_zone,
                ],
                'payment_address' => [
                    'firstname' => $order->payment_firstname,
                    'lastname' => $order->payment_lastname,
                    'address_1' => $order->payment_address_1,
                    'address_2' => $order->payment_address_2,
                    'city' => $order->payment_city,
                    'postcode' => $order->payment_postcode,
                    'country' => $order->payment_country,
                    'zone' => $order->payment_zone,
                ],
                'payment_method' => $order->payment_method,
                'shipping_method' => $order->shipping_method,
                'comment' => $order->comment,
                'total' => (float) $order->total,
            ],
            'products' => $orderProducts,
            'totals' => $orderTotals,
            'history' => $this->getOrderHistory($id),
        ]);
    }

    /**
     * Request to cancel order.
     *
     * @urlParam id required Order ID.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel($id, Request $request)
    {
        $user = $request->user();

        $order = DB::table('oc_order')
            ->where('customer_id', $user->customer_id)
            ->where('order_id', $id)
            ->whereIn('order_status_id', [1, 2]) // Only pending or processing orders
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found or cannot be cancelled',
            ], 404);
        }

        // Update order status to "Canceled"
        DB::table('oc_order')
            ->where('order_id', $id)
            ->update([
                'order_status_id' => 7, // Assuming 7 is canceled status
                'date_modified' => now(),
            ]);

        // Add order history
        $this->addOrderHistory($id, 7, 'Customer requested cancellation', true);

        return response()->json([
            'message' => 'Order cancellation requested successfully',
            'order' => [
                'id' => $id,
                'status' => $this->getOrderStatus(7),
            ],
        ]);
    }

    /**
     * Request return/refund.
     *
     * @urlParam id required Order ID.
     * @bodyParam reason string required Reason for return.
     * @bodyParam product_ids array List of product IDs to return.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestReturn($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:oc_order_product,product_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $order = DB::table('oc_order')
            ->where('customer_id', $user->customer_id)
            ->where('order_id', $id)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found',
            ], 404);
        }

        // Create return record
        $returnData = [
            'order_id' => $order->order_id,
            'product_id' => $request->product_ids[0], // First product for simplicity
            'customer_id' => $user->customer_id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'product' => 'Multiple Products',
            'model' => '',
            'quantity' => count($request->product_ids),
            'opened' => 0,
            'return_reason_id' => 1, // Other reason
            'return_action_id' => 1,
            'return_status_id' => 1, // Pending
            'comment' => $request->reason,
            'date_ordered' => $order->date_added,
            'date_added' => now(),
            'date_modified' => now(),
        ];

        $returnId = DB::table('oc_return')->insertGetId($returnData);

        return response()->json([
            'message' => 'Return request submitted successfully',
            'return' => [
                'id' => $returnId,
                'order_id' => $order->order_id,
                'status' => 'pending',
            ],
        ]);
    }

    /**
     * Helper: Get product final price.
     *
     * @param  object  $product
     * @return float
     */
    protected function getProductFinalPrice($product)
    {
        $special = DB::table('oc_product_special')
            ->where('product_id', $product->product_id)
            ->where('date_start', '<=', now())
            ->where(function ($q) {
                $q->where('date_end', '>=', now())
                  ->orWhere('date_end', '0000-00-00');
            })
            ->orderBy('priority', 'ASC')
            ->first();

        return $special ? (float) $special->price : (float) $product->price;
    }

    /**
     * Helper: Apply coupon discount.
     *
     * @param  string  $code
     * @param  float  $subtotal
     * @return float
     */
    protected function applyCoupon($code, $subtotal)
    {
        $coupon = DB::table('oc_coupon')
            ->where('code', $code)
            ->where('status', 1)
            ->where('date_start', '<=', now())
            ->where(function ($q) {
                $q->where('date_end', '>=', now())
                  ->orWhere('date_end', '0000-00-00');
            })
            ->first();

        if (!$coupon) {
            return 0;
        }

        if ($coupon->type === 'P') {
            return ($coupon->discount / 100) * $subtotal;
        } else {
            return min($coupon->discount, $subtotal);
        }
    }

    /**
     * Helper: Add order total.
     *
     * @param  int  $orderId
     * @param  string  $code
     * @param  string  $title
     * @param  float  $value
     * @param  int  $sortOrder
     */
    protected function addOrderTotal($orderId, $code, $title, $value, $sortOrder)
    {
        DB::table('oc_order_total')->insert([
            'order_id' => $orderId,
            'code' => $code,
            'title' => $title,
            'value' => $value,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Helper: Add order history.
     *
     * @param  int  $orderId
     * @param  int  $statusId
     * @param  string  $comment
     * @param  bool  $notify
     */
    protected function addOrderHistory($orderId, $statusId, $comment, $notify = false)
    {
        DB::table('oc_order_history')->insert([
            'order_id' => $orderId,
            'order_status_id' => $statusId,
            'notify' => $notify ? 1 : 0,
            'comment' => $comment,
            'date_added' => now(),
        ]);
    }

    /**
     * Helper: Get order status name.
     *
     * @param  int  $statusId
     * @return string
     */
    protected function getOrderStatus($statusId)
    {
        $status = DB::table('oc_order_status')
            ->where('order_status_id', $statusId)
            ->where('language_id', 1)
            ->first();

        return $status ? $status->name : 'Unknown';
    }

    /**
     * Helper: Get order history.
     *
     * @param  int  $orderId
     * @return array
     */
    protected function getOrderHistory($orderId)
    {
        return DB::table('oc_order_history')
            ->join('oc_order_status', function ($join) {
                $join->on('oc_order_history.order_status_id', '=', 'oc_order_status.order_status_id')
                     ->where('oc_order_status.language_id', 1);
            })
            ->where('oc_order_history.order_id', $orderId)
            ->orderBy('oc_order_history.date_added', 'desc')
            ->get()
            ->map(function ($history) {
                return [
                    'status' => $history->name,
                    'comment' => $history->comment,
                    'date_added' => $history->date_added,
                    'notify' => (bool) $history->notify,
                ];
            });
    }
}