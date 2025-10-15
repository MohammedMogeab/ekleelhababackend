<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CartController extends Controller
{


    /**
     * Display the user's cart
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        try {
            $user = $request->user();
            $customerId = $user->customer_id;

            // Get cart items with proper joins and selects
            $cartItems = DB::table('oc_cart_mob as c')
                ->join('oc_product as p', 'c.product_id', '=', 'p.product_id')
                ->leftJoin('oc_product_description as pd', function ($join) {
                    $join->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', 1);
                })
                ->leftJoin('oc_product_special as ps', function ($join) {
                    $join->on('p.product_id', '=', 'ps.product_id')
                        ->where('ps.customer_group_id', '=', 1)
                        ->where('ps.date_start', '<=', now())
                        ->where(function ($query) {
                            $query->where('ps.date_end', '>=', now())
                                ->orWhere('ps.date_end', '0000-00-00')
                                ->orWhereNull('ps.date_end');
                        });
                })
                ->where('c.customer_id', $customerId)
                ->select(
                    'c.cart_id',
                    'c.product_id',
                    'c.quantity',
                    'c.option',
                    'p.model',
                    'pd.name',
                    'p.price',
                    DB::raw('COALESCE(ps.price, p.price) as final_price'),
                    'p.image',
                    'p.quantity as stock_quantity'
                )
                ->get();

            // Get product options for all items at once - FIXED HERE
            $productIds = $cartItems->pluck('product_id')->unique()->toArray();
            $options = [];

            if (!empty($productIds)) {
                // CORRECTED: Join with oc_option_description to get option names
                $productOptions = DB::table('oc_product_option as po')
                    ->join('oc_option as o', 'po.option_id', '=', 'o.option_id')
                    ->join('oc_option_description as od', function ($join) {
                        $join->on('o.option_id', '=', 'od.option_id')
                            ->where('od.language_id', '=', 1);
                    })
                    ->whereIn('po.product_id', $productIds)
                    ->select('po.product_id', 'po.option_id', 'od.name as option_name')
                    ->get();

                foreach ($productOptions as $option) {
                    $options[$option->option_id] = $option;
                }
            }

            // Format the cart items
            $formattedItems = $cartItems->map(function ($item) use ($options) {
                // If product no longer exists or is out of stock, skip it
                if ($item->stock_quantity < $item->quantity) {
                    return null;
                }

                $optionsData = [];
                $optionsJson = json_decode($item->option, true) ?? [];

                foreach ($optionsJson as $optionId => $value) {
                    if (isset($options[$optionId])) {
                        $optionsData[] = [
                            'id' => $optionId,
                            'name' => $options[$optionId]->option_name,
                            'value' => $value
                        ];
                    }
                }

                $finalPrice = $item->final_price;
                $total = $finalPrice * $item->quantity;

                return [
                    'id' => $item->cart_id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'model' => $item->model,
                    'quantity' => $item->quantity,
                    'price' => (float)$item->price,
                    'final_price' => (float)$finalPrice,
                    'total' => (float)$total,
                    'image' => $item->image ? env("IMAGE_BASE_PATH") . $item->image : null,
                    'options' => $optionsData
                ];
            })->filter(); // Remove null items (out of stock products)

            // Calculate cart totals
            $subtotal = $formattedItems->sum('total');
            $tax = $subtotal * 0.15; // Assuming 15% tax rate - adjust as needed
            $total = $subtotal + $tax;

            return response()->json([
                'data' => [
                    'items' => $formattedItems,
                    'subtotal' => (float)$subtotal,
                    'tax' => (float)$tax,
                    'total' => (float)$total,
                    'item_count' => $formattedItems->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve cart', [
                'user_id' => $request->user()->customer_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve cart',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    /**
     * Add item to the user's cart
     * 
     * @bodyParam product_id int required The ID of the product to add
     * @bodyParam quantity int required The quantity to add (must be at least 1)
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addItem(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer', Rule::exists('oc_product', 'product_id')->where(function ($query) {
                $query->where('status', 1);
            })],
            'quantity' => 'required|integer|min:1',
            'option' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $productId = $request->product_id;
            $quantity = $request->quantity;
            $options = $request->input('option', []);

            // Get product
            $product = DB::table('oc_product')
                ->where('product_id', $productId)
                ->where('status', 1)
                ->first();

            if (!$product) {
                return response()->json([
                    'message' => 'Product not found or unavailable'
                ], 404);
            }

            // Check stock
            if ($product->quantity < $quantity) {
                return response()->json([
                    'message' => 'Insufficient stock'
                ], 400);
            }

            // Check if item already in cart
            $existingItem = DB::table('oc_cart_mob')
                ->where('customer_id', $user->customer_id)
                ->where('product_id', $productId)
                ->first();

            if ($existingItem) {
                // Update quantity
                DB::table('oc_cart_mob')
                    ->where('cart_id', $existingItem->cart_id)
                    ->update([
                        'quantity' => $existingItem->quantity + $quantity,
                        'date_added' => now()
                    ]);

                $cartId = $existingItem->cart_id;
            } else {
                // Add new item - REMOVED 'model' FIELD AS IT DOESN'T EXIST IN oc_cart_mob TABLE
                $cartId = DB::table('oc_cart_mob')->insertGetId([
                    'product_id' => $productId,
                    'customer_id' => $user->customer_id,
                    'quantity' => $quantity,
                    'api_id' => 0,
                    'session_id' => '',
                    'recurring_id' => 0,
                    'option' => json_encode($options),
                    'date_added' => now()
                ]);
            }

            // Return updated cart
            return response()->json([
                'message' => 'Item added to cart successfully',
                'data' => $this->getCartItems($user->customer_id)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add item to cart', [
                'user_id' => $user->customer_id,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to add item to cart',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Helper method to get all cart items for a user
     * 
     * @param int $customerId
     * @return array
     */
    private function getCartItems($customerId)
    {
        $cartItems = DB::table('oc_cart_mob as c')
            ->join('oc_product as p', 'c.product_id', '=', 'p.product_id')
            ->leftJoin('oc_product_description as pd', function ($join) {
                $join->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', 1);
            })
            ->leftJoin('oc_product_special as ps', function ($join) {
                $join->on('p.product_id', '=', 'ps.product_id')
                    ->where('ps.customer_group_id', '=', 1)
                    ->where('ps.date_start', '<=', now())
                    ->where(function ($query) {
                        $query->where('ps.date_end', '>=', now())
                            ->orWhere('ps.date_end', '0000-00-00')
                            ->orWhereNull('ps.date_end');
                    });
            })
            ->where('c.customer_id', $customerId)
            ->select(
                'c.cart_id',
                'c.product_id',
                'c.quantity',
                'p.model',  // FIXED: Changed from 'c.model' to 'p.model' - model is in product table
                'pd.name',
                'p.price',
                DB::raw('COALESCE(ps.price, p.price) as final_price'),
                'p.image'
            )
            ->get();

        $formattedItems = $cartItems->map(function ($item) {
            return [
                'id' => $item->cart_id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'model' => $item->model,  // Now correctly referencing p.model from the select
                'quantity' => $item->quantity,
                'price' => (float)$item->price,
                'final_price' => (float)$item->final_price,
                'total' => (float)($item->final_price * $item->quantity),
                'image' => $item->image ? env("IMAGE_BASE_PATH") . $item->image : null
            ];
        });

        return $formattedItems;
    }

/**
 * Update cart item.
 *
 * @urlParam id required Cart item ID.
 * @bodyParam quantity integer required Quantity (min: 1).
 *
 * @param int $id
 * @param \Illuminate\Http\Request $request
 * @return \Illuminate\Http\JsonResponse
 */
public function updateItem($id, Request $request)
{
    $user = $request->user();
    $validator = Validator::make($request->all(), [
        'quantity' => 'required|integer|min:1'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Get cart item - FIXED: Changed from 'oc_cart' to 'oc_cart_mob'
        $cartItem = DB::table('oc_cart_mob')->where('cart_id', $id)->where('customer_id', $user->customer_id)->first();
        
        if (!$cartItem) {
            return response()->json([
                'message' => 'Cart item not found'
            ], 404);
        }
        
        // Get product
        $product = DB::table('oc_product')
            ->where('product_id', $cartItem->product_id)
            ->where('status', 1)
            ->first();
            
        if (!$product) {
            return response()->json([
                'message' => 'Product not found or unavailable'
            ], 404);
        }
        
        // Check stock
        if ($product->quantity < $request->quantity) {
            return response()->json([
                'message' => 'Requested quantity exceeds available stock',
                'data' => [
                    'available_quantity' => $product->quantity
                ]
            ], 400);
        }
        
        // Update quantity - FIXED: Changed from 'oc_cart' to 'oc_cart_mob'
        DB::table('oc_cart_mob')->where('cart_id', $id)->update([
            'quantity' => $request->quantity
        ]);
        
        // Return updated cart
        $updatedCart = $this->getCartItems($user->customer_id);
        
        return response()->json([
            'message' => 'Cart item updated successfully',
            'data' => $updatedCart
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to update cart item', [
            'user_id' => $user->customer_id,
            'cart_id' => $id,
            'error' => $e->getMessage(),
            'request' => $request->all()
        ]);
        
        return response()->json([
            'message' => 'Failed to update cart item',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Remove item from cart.
     *
     * @urlParam id required Cart item ID.
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeItem($id, Request $request)
    {
        $user = $request->user();

        try {
            // Verify item ownership
            $item = DB::table('oc_cart')
                ->where('cart_id', $id)
                ->where('customer_id', $user->customer_id)
                ->first();

            if (!$item) {
                return response()->json([
                    'message' => 'Cart item not found'
                ], 404);
            }

            // Remove item
            DB::table('oc_cart')
                ->where('cart_id', $id)
                ->delete();

            // Return updated cart
            $updatedCart = $this->getCartData($user->customer_id);

            return response()->json([
                'message' => 'Item removed from cart successfully',
                'data' => $updatedCart
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove cart item', [
                'user_id' => $user->customer_id,
                'cart_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to remove item from cart',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Clear user cart.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clear(Request $request)
    {
        $user = $request->user();

        try {
            // Clear cart
            DB::table('oc_cart')
                ->where('api_id', 0)
                ->where('customer_id', $user->customer_id)
                ->where('session_id', '')
                ->delete();

            return response()->json([
                'message' => 'Cart cleared successfully',
                'data' => [
                    'items' => [],
                    'summary' => $this->getEmptyCartSummary()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear cart', [
                'user_id' => $user->customer_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to clear cart',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Add item to guest cart.
     *
     * @bodyParam session_id string required Session ID.
     * @bodyParam product_id integer required Product ID.
     * @bodyParam quantity integer required Quantity (min: 1).
     * @bodyParam option array Product options.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addGuestItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string|max:32',
            'product_id' => [
                'required',
                'integer',
                Rule::exists('oc_product', 'product_id')->where(function ($query) {
                    $query->where('status', 1);
                })
            ],
            'quantity' => 'required|integer|min:1',
            'option' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $product = DB::table('oc_product')
                ->where('product_id', $request->product_id)
                ->where('status', 1)
                ->first();

            if (!$product) {
                return response()->json([
                    'message' => 'Product not found or unavailable'
                ], 404);
            }

            if ($product->quantity < $request->quantity) {
                return response()->json([
                    'message' => 'Requested quantity exceeds available stock',
                    'data' => [
                        'available_quantity' => $product->quantity
                    ]
                ], 400);
            }

            // Check if item already exists in cart
            $existingItem = DB::table('oc_cart')
                ->where('api_id', 0)
                ->where('customer_id', 0)
                ->where('session_id', $request->session_id)
                ->where('product_id', $request->product_id)
                ->where('option', json_encode($request->option ?? []))
                ->first();

            if ($existingItem) {
                // Update quantity
                $newQuantity = $existingItem->quantity + $request->quantity;

                if ($product->quantity < $newQuantity) {
                    return response()->json([
                        'message' => 'Total quantity exceeds available stock',
                        'data' => [
                            'available_quantity' => $product->quantity
                        ]
                    ], 400);
                }

                DB::table('oc_cart')
                    ->where('cart_id', $existingItem->cart_id)
                    ->update(['quantity' => $newQuantity]);
            } else {
                // Add new item
                DB::table('oc_cart')->insert([
                    'api_id' => 0,
                    'customer_id' => 0,
                    'session_id' => $request->session_id,
                    'product_id' => $request->product_id,
                    'option' => json_encode($request->option ?? []),
                    'quantity' => $request->quantity,
                    'date_added' => now()
                ]);
            }

            // Return updated cart
            $updatedCart = $this->getGuestCartData($request->session_id);

            return response()->json([
                'message' => 'Item added to cart successfully',
                'data' => $updatedCart
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to add item to guest cart', [
                'session_id' => $request->session_id,
                'product_id' => $request->product_id,
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'message' => 'Failed to add item to guest cart',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get guest cart.
     *
     * @queryParam session_id string required Session ID.
     * @queryParam include string Comma-separated list of relations to include (products).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function showGuestCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string|max:32'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            return response()->json([
                'data' => $this->getGuestCartData($request->session_id)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve guest cart', [
                'session_id' => $request->session_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve guest cart',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Helper: Get cart data for user.
     *
     * @param int $customerId
     * @return array
     */
    private function getCartData($customerId)
    {
        $cartItems = DB::table('oc_cart as c')
            ->where('c.api_id', 0)
            ->where('c.customer_id', $customerId)
            ->where('c.session_id', '')
            ->get();

        if ($cartItems->isEmpty()) {
            return [
                'items' => [],
                'summary' => $this->getEmptyCartSummary()
            ];
        }

        $productIds = $cartItems->pluck('product_id')->toArray();
        $optionIds = [];

        // Extract option IDs from cart items
        foreach ($cartItems as $item) {
            $options = json_decode($item->option, true) ?? [];
            foreach ($options as $optionId => $value) {
                $optionIds[] = $optionId;
            }
        }

        // Get product details
        $products = DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->leftJoin('oc_product_special as ps', function ($join) {
                $join->on('p.product_id', '=', 'ps.product_id')
                    ->where('ps.customer_group_id', '=', 1)
                    ->where('ps.date_start', '<=', now())
                    ->where(function ($query) {
                        $query->where('ps.date_end', '>=', now())
                            ->orWhere('ps.date_end', '0000-00-00');
                    });
            })
            ->whereIn('p.product_id', $productIds)
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->select(
                'p.product_id',
                'pd.name',
                'p.price',
                'p.image',
                'p.quantity as stock_quantity',
                DB::raw('COALESCE(ps.price, p.price) as final_price')
            )
            ->get();

        // Get option details
        $options = [];
        if (!empty($optionIds)) {
            $options = DB::table('oc_option_value_description')
                ->whereIn('option_value_id', $optionIds)
                ->where('language_id', 1)
                ->get()
                ->keyBy('option_value_id');
        }

        $formattedItems = $cartItems->map(function ($item) use ($products, $options) {
            $product = $products->firstWhere('product_id', $item->product_id);

            // If product no longer exists or is out of stock, skip it
            if (!$product || $product->stock_quantity < $item->quantity) {
                return null;
            }

            $optionsData = [];
            $optionsJson = json_decode($item->option, true) ?? [];

            foreach ($optionsJson as $optionId => $value) {
                if (isset($options[$optionId])) {
                    $optionsData[] = [
                        'id' => $optionId,
                        'name' => $options[$optionId]->name,
                        'value' => $value
                    ];
                }
            }

            $finalPrice = $product->final_price;
            $total = $finalPrice * $item->quantity;

            return [
                'id' => $item->cart_id,
                'product_id' => $item->product_id,
                'name' => $product->name,
                'quantity' => $item->quantity,
                'price' => (float)$product->price,
                'final_price' => (float)$finalPrice,
                'is_on_sale' => $finalPrice < $product->price,
                'total' => (float)$total,
                'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                'options' => $optionsData,
                'in_stock' => $product->stock_quantity > 0
            ];
        })->filter();

        $summary = $this->calculateCartSummary($formattedItems);

        return [
            'items' => $formattedItems,
            'summary' => $summary
        ];
    }

    /**
     * Helper: Get guest cart data.
     *
     * @param string $sessionId
     * @return array
     */
    private function getGuestCartData($sessionId)
    {
        $cartItems = DB::table('oc_cart as c')
            ->where('c.api_id', 0)
            ->where('c.customer_id', 0)
            ->where('c.session_id', $sessionId)
            ->get();

        if ($cartItems->isEmpty()) {
            return [
                'items' => [],
                'summary' => $this->getEmptyCartSummary()
            ];
        }

        $productIds = $cartItems->pluck('product_id')->toArray();
        $optionIds = [];

        // Extract option IDs from cart items
        foreach ($cartItems as $item) {
            $options = json_decode($item->option, true) ?? [];
            foreach ($options as $optionId => $value) {
                $optionIds[] = $optionId;
            }
        }

        // Get product details
        $products = DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->leftJoin('oc_product_special as ps', function ($join) {
                $join->on('p.product_id', '=', 'ps.product_id')
                    ->where('ps.customer_group_id', '=', 1)
                    ->where('ps.date_start', '<=', now())
                    ->where(function ($query) {
                        $query->where('ps.date_end', '>=', now())
                            ->orWhere('ps.date_end', '0000-00-00');
                    });
            })
            ->whereIn('p.product_id', $productIds)
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->select(
                'p.product_id',
                'pd.name',
                'p.price',
                'p.image',
                'p.quantity as stock_quantity',
                DB::raw('COALESCE(ps.price, p.price) as final_price')
            )
            ->get();

        // Get option details
        $options = [];
        if (!empty($optionIds)) {
            $options = DB::table('oc_option_value_description')
                ->whereIn('option_value_id', $optionIds)
                ->where('language_id', 1)
                ->get()
                ->keyBy('option_value_id');
        }

        $formattedItems = $cartItems->map(function ($item) use ($products, $options) {
            $product = $products->firstWhere('product_id', $item->product_id);

            // If product no longer exists or is out of stock, skip it
            if (!$product || $product->stock_quantity < $item->quantity) {
                return null;
            }

            $optionsData = [];
            $optionsJson = json_decode($item->option, true) ?? [];

            foreach ($optionsJson as $optionId => $value) {
                if (isset($options[$optionId])) {
                    $optionsData[] = [
                        'id' => $optionId,
                        'name' => $options[$optionId]->name,
                        'value' => $value
                    ];
                }
            }

            $finalPrice = $product->final_price;
            $total = $finalPrice * $item->quantity;

            return [
                'id' => $item->cart_id,
                'product_id' => $item->product_id,
                'name' => $product->name,
                'quantity' => $item->quantity,
                'price' => (float)$product->price,
                'final_price' => (float)$finalPrice,
                'is_on_sale' => $finalPrice < $product->price,
                'total' => (float)$total,
                'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                'options' => $optionsData,
                'in_stock' => $product->stock_quantity > 0
            ];
        })->filter();

        $summary = $this->calculateCartSummary($formattedItems);

        return [
            'items' => $formattedItems,
            'summary' => $summary
        ];
    }

    /**
     * Helper: Calculate cart summary.
     *
     * @param \Illuminate\Support\Collection $items
     * @return array
     */
    private function calculateCartSummary($items)
    {
        $total = 0;
        $count = 0;

        foreach ($items as $item) {
            $total += $item['total'];
            $count += $item['quantity'];
        }

        $taxRate = 0.15; // 15%
        $tax = $total * $taxRate;

        // Free shipping threshold
        $freeShippingThreshold = 250;
        $shippingCost = $total >= $freeShippingThreshold ? 0 : 23;

        // Grand total = subtotal + tax + shipping
        $grandTotal = $total + $tax + $shippingCost;

        return [
            'total_items' => $count,
            'subtotal' => round($total, 2),
            'tax' => round($tax, 2),
            'tax_rate' => '15%',
            'shipping' => round($shippingCost, 2),
            'shipping_cost' => $shippingCost,
            'free_shipping_threshold' => $freeShippingThreshold,
            'grand_total' => round($grandTotal, 2),
        ];
    }

    /**
     * Helper: Get empty cart summary.
     *
     * @return array
     */
    private function getEmptyCartSummary()
    {
        return [
            'total_items' => 0,
            'subtotal' => 0,
            'tax' => 0,
            'tax_rate' => '15%',
            'shipping' => 0,
            'shipping_cost' => 23,
            'free_shipping_threshold' => 250,
            'grand_total' => 0,
        ];
    }

    /**
     * Helper: Get include relations from request.
     *
     * @param Request $request
     * @return array
     */
    private function getIncludeRelations(Request $request)
    {
        $validRelations = ['products'];
        $includes = explode(',', $request->get('include', ''));

        return array_values(array_intersect($includes, $validRelations));
    }
}
