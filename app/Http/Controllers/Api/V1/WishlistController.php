<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    /**
     * Get user's wishlist.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $perPage = $request->get('limit', 15);
        $page = $request->get('page', 1);
        
        $wishlistQuery = Wishlist::where('customer_id', $user->customer_id)
            ->with(['product' => function ($query) {
                $query->with([
                    'descriptions' => fn($q) => $q->where('language_id', 1),
                    'images'
                ]);
            }]);
            
        $total = $wishlistQuery->count();
        $wishlistItems = $wishlistQuery->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();
            
        $formatted = $wishlistItems->map(function ($item) {
            $product = $item->product;
            $desc = $product->descriptions->first();
            $finalPrice = $this->getProductFinalPrice($product);
            
            return [
                'id' => $product->product_id,
                'name' => $desc?->name ?? '',
                'model' => $product->model,
                'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                'price' => (float)$product->price,
                'final_price' => $finalPrice,
                'is_on_sale' => $finalPrice < $product->price,
                'in_stock' => $product->quantity > 0,
                'added_at' => $item->date_added,
            ];
        });
        
        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ]
        ]);
    }

    /**
     * Add product to wishlist.
     *
     * @bodyParam product_id integer required Product ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:oc_product,product_id',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        $user = $request->user();
        $productId = $request->product_id;
        
        // Check if already in wishlist
        $exists = Wishlist::where('customer_id', $user->customer_id)
            ->where('product_id', $productId)
            ->exists();
            
        if ($exists) {
            return response()->json([
                'message' => 'Product already in wishlist',
            ], 409);
        }
        
        $wishlistItem = Wishlist::create([
            'customer_id' => $user->customer_id,
            'product_id' => $productId,
            'date_added' => now(),
        ]);
        
        // Return the added product details
        $product = DB::table('oc_product as p')
            ->select(
                'p.product_id',
                'p.model',
                'p.price',
                'p.quantity',
                'p.image',
                'pd.name',
                'pd.description'
            )
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->where('p.product_id', $productId)
            ->where('pd.language_id', 1)
            ->first();
            
        if ($product) {
            $finalPrice = $this->getProductFinalPrice((object)$product);
            
            return response()->json([
                'message' => 'Product added to wishlist',
                'data' => [
                    'id' => $product->product_id,
                    'name' => $product->name,
                    'model' => $product->model,
                    'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                    'price' => (float)$product->price,
                    'final_price' => $finalPrice,
                    'is_on_sale' => $finalPrice < $product->price,
                    'in_stock' => $product->quantity > 0,
                    'added_at' => $wishlistItem->date_added,
                ]
            ], 201);
        }
        
        return response()->json([
            'message' => 'Product added to wishlist',
            'product_id' => $productId
        ], 201);
    }

    /**
     * Remove product from wishlist.
     *
     * @urlParam productId required Product ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $productId)
    {
        $user = $request->user();

        $wishlistItem = Wishlist::where('customer_id', $user->customer_id)
            ->where('product_id', $productId)
            ->firstOrFail();

        $wishlistItem->delete();

        return response()->json([
            'message' => 'Product removed from wishlist',
        ]);
    }

    /**
     * Helper: Get final price of product (considering specials).
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
}