<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\Product;
use App\Models\Marketer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SellerController extends Controller
{
    /**
     * List all sellers.
     *
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     * @queryParam status string Filter by status (active, pending, all).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
            'status' => 'nullable|in:active,pending,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = Marketer::query();

        $status = $request->get('status', 'active');
        if ($status === 'active') {
            $query->where('status', 1);
        } elseif ($status === 'pending') {
            $query->where('status', 0);
        }

        $limit = $request->get('limit', 20);
        $sellers = $query->orderBy('created_at', 'desc')->paginate($limit);

        $formatted = $sellers->getCollection()->map(function ($seller) {
            return [
                'id' => $seller->id,
                'name' => $seller->name,
                'phone' => $seller->phone,
                'phone2' => $seller->phone2,
                'address' => $seller->address,
                'facebook_link' => $seller->facebook_link,
                'instagram_link' => $seller->instagram_link,
                'status' => $seller->status ? 'active' : 'pending',
                'total_products' => $this->getSellerProductCount($seller->customer_id),
                'created_at' => $seller->created_at,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $sellers->currentPage(),
                'last_page' => $sellers->lastPage(),
                'per_page' => $sellers->perPage(),
                'total' => $sellers->total(),
            ],
        ]);
    }

    /**
     * Get seller by ID.
     *
     * @urlParam id required Seller ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $seller = Marketer::findOrFail($id);

        return response()->json([
            'id' => $seller->id,
            'name' => $seller->name,
            'phone' => $seller->phone,
            'phone2' => $seller->phone2,
            'address' => $seller->address,
            'facebook_link' => $seller->facebook_link,
            'telegram_link' => $seller->telegram_link,
            'twitter_link' => $seller->twitter_link,
            'tiktok_link' => $seller->tiktok_link,
            'instagram_link' => $seller->instagram_link,
            'snap_link' => $seller->snap_link,
            'comment' => $seller->comment,
            'status' => $seller->status ? 'active' : 'pending',
            'total_products' => $this->getSellerProductCount($seller->customer_id),
            'created_at' => $seller->created_at,
            'updated_at' => $seller->updated_at,
        ]);
    }

    /**
     * Get products by seller.
     *
     * @urlParam id required Seller ID.
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function products($id, Request $request)
    {
        $seller = Marketer::findOrFail($id);

        if (!$seller->customer_id) {
            return response()->json([
                'message' => 'This seller has no associated products',
                'data' => [],
            ]);
        }

        $limit = $request->get('limit', 20);
        $products = Product::active()
            ->where('customer_id', $seller->customer_id) // Assuming products are linked to customer_id
            ->with([
                'descriptions' => fn($q) => $q->where('language_id', 1),
                'images'
            ])
            ->orderBy('date_added', 'desc')
            ->paginate($limit);

            $formatted = $products->getCollection()->map(function ($product) {
                $desc = $product->descriptions->first();
                $finalPrice = $this->getProductFinalPrice($product);
                $averageRating = $this->getProductRating($product->product_id);
                $reviewCount = DB::table('oc_review')
                    ->where('product_id', $product->product_id)
                    ->where('status', 1)
                    ->count();
                    
                return [
                    'id' => $product->product_id,
                    'name' => $desc?->name ?? '',
                    'model' => $product->model,
                    'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                    'price' => (float)$product->price,
                    'final_price' => $finalPrice,
                    'is_on_sale' => $finalPrice < $product->price,
                    'in_stock' => $product->quantity > 0,
                    'average_rating' => $averageRating,
                    'review_count' => $reviewCount,
                ];
        });

        return response()->json([
            'seller' => [
                'id' => $seller->id,
                'name' => $seller->name,
            ],
            'products' => [
                'data' => $formatted,
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
        ]);
    }

    /**
     * Apply to become a seller.
     *
     * @bodyParam name string required Business name.
     * @bodyParam phone string required Phone number.
     * @bodyParam address string Business address.
     * @bodyParam facebook_link string Facebook page URL.
     * @bodyParam instagram_link string Instagram profile URL.
     * @bodyParam comment string Additional information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function apply(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:200',
            'phone' => 'required|string|max:15',
            'address' => 'nullable|string|max:200',
            'facebook_link' => 'nullable|url|max:500',
            'instagram_link' => 'nullable|url|max:500',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer_id = $request->user()?->customer_id;

        $seller = Marketer::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'phone2' => $request->phone2 ?? null,
            'address' => $request->address,
            'facebook_link' => $request->facebook_link,
            'telegram_link' => $request->telegram_link ?? null,
            'twitter_link' => $request->twitter_link ?? null,
            'tiktok_link' => $request->tiktok_link ?? null,
            'instagram_link' => $request->instagram_link,
            'snap_link' => $request->snap_link ?? null,
            'comment' => $request->comment,
            'status' => 0, // Pending approval
            'customer_id' => $customer_id,
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Seller application submitted successfully. We will review your application shortly.',
            'seller' => [
                'id' => $seller->id,
                'name' => $seller->name,
                'status' => 'pending',
                'application_date' => $seller->created_at,
            ],
        ], 201);
    }

    /**
     * Helper: Get seller product count.
     *
     * @param  int|null  $customerId
     * @return int
     */
    protected function getSellerProductCount($customerId)
    {
        if (!$customerId) {
            return 0;
        }

        return Product::where('customer_id', $customerId)->count();
    }

    /**
     * Helper: Get product final price.
     *
     * @param  \App\Models\Product  $product
     * @return float
     */
    private function getProductFinalPrice($product)
    {
        // Get special price if available and valid
        $specialPrice = DB::table('oc_product_special')
            ->where('product_id', $product->product_id)
            ->where(function ($query) {
                $query->whereNull('date_start')
                    ->orWhere('date_start', '<=', now())
                    ->whereNull('date_end')
                    ->orWhere('date_end', '>=', now());
            })
            ->orderBy('priority', 'asc')
            ->first();

        if ($specialPrice && $specialPrice->price < $product->price) {
            return (float)$specialPrice->price;
        }

        return (float)$product->price;
    }


    private function getProductRating($productId)
    {
        // Calculate average rating from reviews
        $averageRating = DB::table('oc_review')
            ->where('product_id', $productId)
            ->where('status', 1)
            ->avg('rating');

        return $averageRating ? round($averageRating, 1) : 0;
    }
}
