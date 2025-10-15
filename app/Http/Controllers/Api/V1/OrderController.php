<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Exception;

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
            // Get cart items
            $cartItems = Cart::where('customer_id', $user->customer_id)
                ->with('product')
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'message' => 'Cart is empty',
                ], 400);
            }

            // Validate stock
            foreach ($cartItems as $item) {
                if (!$item->product || $item->product->quantity < $item->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Product out of stock: ' . ($item->product?->model ?? 'Unknown'),
                    ], 400);
                }
            }

            // Calculate totals with tax (15%) and shipping (23, free if subtotal + tax >= 250)
            $subtotal = 0;
            $taxRate = 0.15;
            $shippingCost = 23;

            foreach ($cartItems as $item) {
                $finalPrice = $this->getProductFinalPrice($item->product);
                $itemTotal = $finalPrice * $item->quantity;
                $subtotal += $itemTotal;
            }

            $tax = $subtotal * $taxRate;
            $shipping = ($subtotal + $tax) >= 250 ? 0 : $shippingCost;
            $total = $subtotal + $tax + $shipping;

            // Apply coupon if provided
            $couponDiscount = 0;
            if ($request->filled('coupon_code')) {
                $couponDiscount = $this->applyCoupon($request->coupon_code, $subtotal);
                $total = max(0, $total - $couponDiscount); // Ensure total doesn't go negative
            }

            // Get shipping address
            $shippingAddress = DB::table('oc_address')->where('address_id', $request->shipping_address_id)->first();
            if (!$shippingAddress) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Invalid shipping address',
                ], 400);
            }

            // Generate invoice number
            $invoiceNo = DB::table('oc_order')->max('invoice_no') + 1;
            $invoicePrefix = 'INV-' . date('Ym');

            // Create order
            $order = Order::create([
                'invoice_prefix' => $invoicePrefix,
                'invoice_no' => $invoiceNo,
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
                'payment_country' => $this->getCountryName($shippingAddress->country_id),
                'payment_country_id' => $shippingAddress->country_id,
                'payment_zone' => $this->getZoneName($shippingAddress->zone_id),
                'payment_zone_id' => $shippingAddress->zone_id,
                'payment_method' => $request->payment_method,
                'payment_code' => $request->payment_method,
                'shipping_firstname' => $user->firstname,
                'shipping_lastname' => $user->lastname,
                'shipping_address_1' => $shippingAddress->address_1,
                'shipping_address_2' => $shippingAddress->address_2,
                'shipping_city' => $shippingAddress->city,
                'shipping_postcode' => $shippingAddress->postcode,
                'shipping_country' => $this->getCountryName($shippingAddress->country_id),
                'shipping_country_id' => $shippingAddress->country_id,
                'shipping_zone' => $this->getZoneName($shippingAddress->zone_id),
                'shipping_zone_id' => $shippingAddress->zone_id,
                'shipping_method' => $shipping > 0 ? 'Standard Shipping' : 'Free Shipping',
                'shipping_code' => $shipping > 0 ? 'standard' : 'free',
                'comment' => $request->comment ?? '',
                'total' => $total,
                'order_status_id' => 1, // Pending
                'affiliate_id' => 0,
                'commission' => 0,
                'marketing_id' => 0,
                'tracking' => '',
                'language_id' => 1,
                'currency_id' => $this->getCurrencyId(),
                'currency_code' => $this->getCurrencyCode(),
                'currency_value' => $this->getCurrencyValue(),
                'ip' => $request->ip(),
                'forwarded_ip' => $request->header('X-Forwarded-For') ?? '',
                'user_agent' => $request->userAgent(),
                'accept_language' => $request->header('Accept-Language') ?? '',
                'date_added' => now(),
                'date_modified' => now(),
                'order_from' => 'mobile_app',
                'fax' => '',
                'custom_field' => '',
                'payment_company' => '',
                'payment_address_format' => '',
                'payment_custom_field' => '',
                'shipping_company' => '',
                'shipping_address_format' => '',
                'shipping_custom_field' => '',
                'order_from' => '',
            ]);

            // Add order products
            foreach ($cartItems as $item) {
                $product = $item->product;
                $finalPrice = $this->getProductFinalPrice($product);

                OrderProduct::create([
                    'order_id' => $order->order_id,
                    'product_id' => $product->product_id,
                    'name' => $product->descriptions->first()?->name ?? $product->model,
                    'model' => $product->model,
                    'quantity' => $item->quantity,
                    'price' => $finalPrice,
                    'total' => $finalPrice * $item->quantity,
                    'tax' => $finalPrice * $item->quantity * $taxRate,
                    'reward' => 0,
                ]);

                // Reduce product quantity
                $product->quantity -= $item->quantity;
                $product->save();
            }

            // Add order totals
            $this->addOrderTotal($order->order_id, 'sub_total', 'Sub-Total', $subtotal, 1);
            $this->addOrderTotal($order->order_id, 'tax', 'Tax (15%)', $tax, 2);
            $this->addOrderTotal($order->order_id, 'shipping', $shipping > 0 ? 'Shipping' : 'Free Shipping', $shipping, 3);

            if ($couponDiscount > 0) {
                $this->addOrderTotal($order->order_id, 'coupon', 'Coupon', -$couponDiscount, 4);
                $this->addOrderTotal($order->order_id, 'total', 'Total', $total, 5);
            } else {
                $this->addOrderTotal($order->order_id, 'total', 'Total', $total, 4);
            }

            // Clear cart
            Cart::where('customer_id', $user->customer_id)->delete();

            // Add order history
            $this->addOrderHistory($order->order_id, 1, 'Order created', false);

            DB::commit();

            return response()->json([
                'message' => 'Order created successfully',
                'order' => [
                    'id' => $order->order_id,
                    'invoice_no' => $invoicePrefix . $invoiceNo,
                    'total' => $total,
                    'status' => 'pending',
                    'date_added' => $order->date_added,
                    'shipping' => [
                        'cost' => $shipping,
                        'free_threshold' => 250,
                        'is_free' => $shipping === 0
                    ],
                    'tax' => [
                        'rate' => '15%',
                        'amount' => $tax
                    ]
                ],
            ], 201);
        } catch (Exception $e) {
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
        $orders = Order::where('customer_id', $user->customer_id)
            ->orderBy('date_added', 'desc')
            ->paginate($limit);

        $formatted = $orders->getCollection()->map(function ($order) {
            return [
                'id' => $order->order_id,
                'invoice_no' => $order->invoice_prefix . $order->invoice_no,
                'total' => (float) $order->total,
                'status' => $this->getOrderStatus($order->order_status_id),
                'status_id' => $order->order_status_id,
                'date_added' => $order->date_added,
                'shipping_method' => $order->shipping_method,
                'payment_method' => $order->payment_method,
                'shipping' => [
                    'cost' => $this->getOrderShippingCost($order->order_id),
                    'is_free' => $this->isOrderShippingFree($order->order_id)
                ],
                'tax' => [
                    'rate' => '15%',
                    'amount' => $this->getOrderTaxAmount($order->order_id)
                ]
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

        try {
            $order = Order::where('customer_id', $user->customer_id)
                ->where('order_id', $id)
                ->with($this->getIncludeRelations($request))
                ->firstOrFail();

            return response()->json([
                'data' => $this->formatOrderResponse($order, $request)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve order details', [
                'user_id' => $user->customer_id,
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve order details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
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

        // Validate cancellation reason
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::transaction(function () use ($user, $id, $request) {
                $order = Order::where('customer_id', $user->customer_id)
                    ->where('order_id', $id)
                    ->whereIn('order_status_id', [1, 2, 3]) // Pending, Processing, Shipped
                    ->lockForUpdate()
                    ->firstOrFail();

                // Get canceled status ID (should be 7 in most OpenCart setups)
                $canceledStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%canceled%')
                    ->value('order_status_id') ?? 7;

                // Update order status
                $order->order_status_id = $canceledStatusId;
                $order->save();

                // Add order history
                $this->addOrderHistory(
                    $order->order_id,
                    $canceledStatusId,
                    'Order canceled by customer: ' . $request->reason,
                    true
                );
            });

            return response()->json([
                'message' => 'Order canceled successfully',
                'data' => ['status' => 'canceled']
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Order not found or cannot be canceled'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Order cancellation failed', [
                'user_id' => $user->customer_id,
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to cancel order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Request return/refund.
     *
     * @urlParam id required Order ID.
     * @bodyParam reason string required Reason for return.
     * @bodyParam product_ids array List of product IDs to return.
     * @bodyParam quantities array Quantities to return for each product.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestReturn($id, Request $request)
    {
        $user = $request->user();

        // Validate return request
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => [
                'required',
                'integer',
                Rule::exists('oc_order_product', 'product_id')->where(function ($query) use ($id) {
                    $query->where('order_id', $id);
                })
            ],
            'quantities' => 'required|array',
            'quantities.*' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
            'images' => 'nullable|array',
            'images.*' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::transaction(function () use ($user, $id, $request) {
                $order = Order::where('customer_id', $user->customer_id)
                    ->where('order_id', $id)
                    ->whereIn('order_status_id', [4, 5, 6]) // Delivered, Complete, Processing Return
                    ->lockForUpdate()
                    ->firstOrFail();

                $orderProducts = OrderProduct::whereIn('product_id', $request->product_ids)
                    ->where('order_id', $id)
                    ->get()
                    ->keyBy('product_id');

                // Validate quantities
                foreach ($request->product_ids as $index => $productId) {
                    $quantity = $request->quantities[$index];
                    $orderProduct = $orderProducts->get($productId);

                    if (!$orderProduct || $quantity > $orderProduct->quantity) {
                        throw new \Exception("Invalid quantity for product ID: {$productId}");
                    }
                }

                // Create return request
                $returnId = DB::table('oc_return')->insertGetId([
                    'order_id' => $id,
                    'customer_id' => $user->customer_id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'telephone' => $user->telephone ?? '',
                    'product' => 'Multiple Products',
                    'model' => '',
                    'quantity' => array_sum($request->quantities),
                    'opened' => 0,
                    'return_reason_id' => $this->getReturnReasonId($request->reason),
                    'return_action_id' => 1, // Default action
                    'return_status_id' => 1, // Pending
                    'comment' => $request->reason,
                    'date_ordered' => $order->date_added,
                    'date_added' => now(),
                    'date_modified' => now(),
                ]);

                // Add individual return products
                foreach ($request->product_ids as $index => $productId) {
                    $quantity = $request->quantities[$index];
                    $orderProduct = $orderProducts[$productId];

                    DB::table('oc_return_product')->insert([
                        'return_id' => $returnId,
                        'product_id' => $productId,
                        'name' => $orderProduct->name,
                        'model' => $orderProduct->model,
                        'quantity' => $quantity,
                        'price' => $orderProduct->price,
                        'total' => $quantity * $orderProduct->price,
                        'reason' => $request->reason,
                        'opened' => 0,
                        'return_reason_id' => $this->getReturnReasonId($request->reason),
                        'return_action_id' => 1,
                        'return_status_id' => 1,
                        'date_added' => now(),
                    ]);
                }

                // Update order status to indicate return processing
                $returnProcessingStatusId = DB::table('oc_order_status')
                    ->where('name', 'like', '%return%')
                    ->value('order_status_id') ?? 12;

                $order->order_status_id = $returnProcessingStatusId;
                $order->save();

                $this->addOrderHistory(
                    $id,
                    $returnProcessingStatusId,
                    'Return request submitted for ' . count($request->product_ids) . ' products',
                    true
                );
            });

            return response()->json([
                'message' => 'Return request submitted successfully',
                'data' => ['return_id' => $orderProducts->product_id ?? null]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Return request failed', [
                'user_id' => $user->customer_id,
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to submit return request',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Helper: Format order response with requested relations.
     *
     * @param Order $order
     * @param Request $request
     * @return array
     */
    private function formatOrderResponse(Order $order, Request $request)
    {
        $include = $this->getIncludeRelations($request);
        $response = [
            'id' => $order->order_id,
            'order_number' => $order->order_number ?? 'ORD-' . $order->order_id,
            'date_added' => $order->date_added,
            'date_modified' => $order->date_modified,
            'status' => $this->getOrderStatus($order->order_status_id),
            'status_id' => $order->order_status_id,
            'total' => (float)$order->total,
            'currency_code' => $order->currency_code,
            'currency_value' => (float)$order->currency_value,
            'payment_method' => $order->payment_method,
            'shipping_method' => $order->shipping_method,
            'shipping_address' => json_decode($order->shipping_address, true) ?? [],
            'payment_address' => json_decode($order->payment_address, true) ?? [],
            'products' => [],
            'totals' => [],
            'history' => []
        ];

        if (in_array('products', $include) && $order->products) {
            $response['products'] = $order->products->map(function ($product) {
                return [
                    'id' => $product->product_id,
                    'name' => $product->name,
                    'model' => $product->model,
                    'quantity' => $product->quantity,
                    'price' => (float)$product->price,
                    'total' => (float)$product->total,
                    'tax' => (float)$product->tax,
                    'reward' => $product->reward,
                    'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null
                ];
            });
        }

        if (in_array('totals', $include) && $order->totals) {
            $response['totals'] = $order->totals->map(function ($total) {
                return [
                    'code' => $total->code,
                    'title' => $total->title,
                    'value' => (float)$total->value,
                    'sort_order' => $total->sort_order
                ];
            });
        }

        if (in_array('history', $include) && $order->history) {
            $response['history'] = $order->history->map(function ($history) {
                return [
                    'status' => $this->getOrderStatus($history->order_status_id),
                    'status_id' => $history->order_status_id,
                    'comment' => $history->comment,
                    'date_added' => $history->date_added,
                    'notify' => (bool)$history->notify
                ];
            });
        }

        return $response;
    }

    /**
     * Helper: Get product final price.
     *
     * @param  \App\Models\Product  $product
     * @return float
     */
    protected function getProductFinalPrice(Product $product)
    {
        $special = $product->specials()
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

        // Check if coupon applies to this subtotal
        if ($coupon->total > $subtotal) {
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
            'value' => round($value, 2),
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
     * Helper: Get include relations from request.
     *
     * @param Request $request
     * @return array
     */
    private function getIncludeRelations(Request $request)
    {
        $validRelations = ['products', 'totals', 'history'];
        $includes = explode(',', $request->get('include', ''));

        return array_values(array_intersect($includes, $validRelations));
    }



    /**
     * Helper: Get order status name.
     *
     * @param  int  $statusId
     * @return string
     */
    private function getOrderStatus($statusId)
    {
        static $statuses = [];

        if (empty($statuses)) {
            $statuses = DB::table('oc_order_status')
                ->pluck('name', 'order_status_id')
                ->all();
        }

        return $statuses[$statusId] ?? 'Unknown Status';
    }
    /**
     * Helper: Get order status ID by name.
     *
     * @param  string  $statusName
     * @return int|null
     */
    protected function getOrderStatusId($statusName)
    {
        $status = DB::table('oc_order_status')
            ->where('name', 'like', '%' . $statusName . '%')
            ->where('language_id', 1)
            ->first();

        return $status ? $status->order_status_id : null;
    }

    /**
     * Helper: Get return reason ID.
     *
     * @param  string  $reason
     * @return int
     */
    private function getReturnReasonId($reason)
    {
        static $reasons = [];

        if (empty($reasons)) {
            $reasons = DB::table('oc_return_reason')
                ->pluck('return_reason_id', 'name')
                ->all();
        }

        // Try to find exact match first
        if (isset($reasons[$reason])) {
            return $reasons[$reason];
        }

        // Try partial match
        foreach ($reasons as $name => $id) {
            if (stripos($name, $reason) !== false) {
                return $id;
            }
        }

        // Default to 'Other' reason (ID 7 in most setups)
        return 7;
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

    /**
     * Helper: Get country name.
     *
     * @param  int  $countryId
     * @return string
     */
    protected function getCountryName($countryId)
    {
        $country = DB::table('oc_country')->where('country_id', $countryId)->first();
        return $country ? $country->name : 'Unknown Country';
    }

    /**
     * Helper: Get zone name.
     *
     * @param  int  $zoneId
     * @return string
     */
    protected function getZoneName($zoneId)
    {
        $zone = DB::table('oc_zone')->where('zone_id', $zoneId)->first();
        return $zone ? $zone->name : 'Unknown Zone';
    }

    /**
     * Helper: Get currency ID.
     *
     * @return int
     */
    protected function getCurrencyId()
    {
        $currency = DB::table('oc_currency')->where('code', 'SAR')->first();
        return $currency ? $currency->currency_id : 1;
    }

    /**
     * Helper: Get currency code.
     *
     * @return string
     */
    protected function getCurrencyCode()
    {
        return 'SAR'; // Saudi Riyal
    }

    /**
     * Helper: Get currency value.
     *
     * @return float
     */
    protected function getCurrencyValue()
    {
        $currency = DB::table('oc_currency')->where('code', 'SAR')->first();
        return $currency ? $currency->value : 1.00000000;
    }

    /**
     * Helper: Get order shipping cost.
     *
     * @param  int  $orderId
     * @return float
     */
    protected function getOrderShippingCost($orderId)
    {
        $shipping = DB::table('oc_order_total')
            ->where('order_id', $orderId)
            ->where('code', 'shipping')
            ->first();

        return $shipping ? (float) $shipping->value : 0;
    }

    /**
     * Helper: Check if order has free shipping.
     *
     * @param  int  $orderId
     * @return bool
     */
    protected function isOrderShippingFree($orderId)
    {
        return $this->getOrderShippingCost($orderId) == 0;
    }

    /**
     * Helper: Get order tax amount.
     *
     * @param  int  $orderId
     * @return float
     */
    protected function getOrderTaxAmount($orderId)
    {
        $tax = DB::table('oc_order_total')
            ->where('order_id', $orderId)
            ->where('code', 'tax')
            ->first();

        return $tax ? (float) $tax->value : 0;
    }
}
