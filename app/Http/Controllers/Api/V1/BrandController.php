<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

class BrandController extends Controller
{
    /**
     * Get all brands/manufacturers.
     *
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 15).
     * @queryParam sort string Sort by: name, popularity (default: name).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50',
            'sort' => 'string|in:name,popularity'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $sort = $request->get('sort', 'name');

        try {
            $cacheKey = 'brands_all:' . $page . ':' . $limit . ':' . $sort;
            $cacheDuration = now()->addHours(1);

            $brands = Cache::remember($cacheKey, $cacheDuration, function () use ($sort, $limit) {
                $query = DB::table('oc_manufacturer')
                    ->select(
                        'manufacturer_id as id',
                        'name',
                        'image',
                        'sort_order'
                    );

                switch ($sort) {
                    case 'popularity':
                        $query->leftJoin('oc_product', 'oc_manufacturer.manufacturer_id', '=', 'oc_product.manufacturer_id')
                            ->groupBy('oc_manufacturer.manufacturer_id')
                            ->orderByRaw('COUNT(oc_product.product_id) DESC');
                        break;
                    default: // name
                        $query->orderBy('name');
                }

                return $query->paginate($limit);
            });

            return response()->json([
                'data' => $brands->items(),
                'meta' => [
                    'current_page' => $brands->currentPage(),
                    'per_page' => $brands->perPage(),
                    'total' => $brands->total(),
                    'total_pages' => $brands->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve brands', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve brands',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get brand details and products.
     *
     * @urlParam id required Brand ID.
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 15).
     * @queryParam sort string Sort by: newest, price_asc, price_desc, rating (default: newest).
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50',
            'sort' => 'string|in:newest,price_asc,price_desc,rating'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $sort = $request->get('sort', 'newest');

        try {
            // Get brand details
            $brand = DB::table('oc_manufacturer')
                ->where('manufacturer_id', $id)
                ->select(
                    'manufacturer_id as id',
                    'name',
                    'image',
                    'sort_order'
                )
                ->first();

            if (!$brand) {
                return response()->json([
                    'message' => 'Brand not found'
                ], 404);
            }

            // Get brand products
            $products = $this->getBrandProducts($id, $page, $limit, $sort);

            return response()->json([
                'data' => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'image' => $brand->image ? env("IMAGE_BASE_PATH") . $brand->image : null,
                    'sort_order' => $brand->sort_order,
                    'products' => $products['data'],
                    'product_count' => $products['meta']['total']
                ]
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve brand details', [
                'brand_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve brand details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get featured brands.
     *
     * @queryParam limit int Limit the number of brands returned (default: 8).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function featured(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'integer|min:1|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $limit = $request->get('limit', 8);

        try {
            $cacheKey = 'brands_featured:' . $limit;
            $cacheDuration = now()->addHours(2);

            $brands = Cache::remember($cacheKey, $cacheDuration, function () use ($limit) {
                return DB::table('oc_manufacturer')
                    ->whereNotNull('image')
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get()
                    ->map(function ($brand) {
                        return [
                            'id' => $brand->manufacturer_id,
                            'name' => $brand->name,
                            'image' => $brand->image ? env("IMAGE_BASE_PATH") . $brand->image : null,
                            'sort_order' => $brand->sort_order
                        ];
                    });
            });

            return response()->json([
                'data' => $brands
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve featured brands', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve featured brands',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get products for a brand.
     *
     * @param int $brandId
     * @param int $page
     * @param int $limit
     * @param string $sort
     * @return array
     */
    private function getBrandProducts($brandId, $page, $limit, $sort)
    {
        $query = DB::table('oc_product as p')
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
            ->leftJoin('oc_manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->where('p.manufacturer_id', $brandId)
            ->select(
                'p.product_id as id',
                'pd.name',
                'p.price',
                DB::raw('COALESCE(ps.price, p.price) as final_price'),
                'p.image',
                DB::raw('(SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1) as average_rating'),
                DB::raw('(SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1) as review_count')
            );

        // Apply sorting
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('final_price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('final_price', 'desc');
                break;
            case 'rating':
                $query->orderBy('average_rating', 'desc');
                break;
            default: // newest
                $query->orderBy('p.date_added', 'desc');
        }

        $totalCount = $query->count();
        $products = $query
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $formattedProducts = $products->map(function ($product) {
            $finalPrice = $product->final_price ?? $product->price;
            $isOnSale = $finalPrice < $product->price;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float)$product->price,
                'final_price' => (float)$finalPrice,
                'is_on_sale' => $isOnSale,
                'discount_percentage' => $isOnSale ? round((($product->price - $finalPrice) / $product->price) * 100) : 0,
                'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                'average_rating' => $product->average_rating ? round($product->average_rating, 1) : 0,
                'review_count' => (int)$product->review_count
            ];
        });

        return [
            'data' => $formattedProducts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ];
    }

    /**
     * Get brands by first letter.
     *
     * @urlParam letter required The first letter to filter brands (A-Z).
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 15).
     * @queryParam sort string Sort by: name, popularity (default: name).
     *
     * @param string $letter
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function byLetter($letter, Request $request)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['letter' => $letter]),
            [
                'letter' => 'required|string|size:1|regex:/^[A-Za-z]$/',
                'page' => 'integer|min:1',
                'limit' => 'integer|min:1|max:50',
                'sort' => 'string|in:name,popularity'
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $sort = $request->get('sort', 'name');
        $letter = strtoupper($letter);

        try {
            $cacheKey = "brands_letter:{$letter}:{$page}:{$limit}:{$sort}";
            $cacheDuration = now()->addHours(1);

            $brands = Cache::remember($cacheKey, $cacheDuration, function () use ($letter, $sort, $limit) {
                $query = DB::table('oc_manufacturer')
                    ->select(
                        'manufacturer_id as id',
                        'name',
                        'image',
                        'sort_order'
                    )
                    ->where('name', 'LIKE', $letter . '%');

                switch ($sort) {
                    case 'popularity':
                        $query->leftJoin('oc_product', 'oc_manufacturer.manufacturer_id', '=', 'oc_product.manufacturer_id')
                            ->groupBy('oc_manufacturer.manufacturer_id')
                            ->orderByRaw('COUNT(oc_product.product_id) DESC');
                        break;
                    default:
                        $query->orderBy('name');
                }

                return $query->paginate($limit);
            });

            return response()->json([
                'data' => $brands->items(),
                'meta' => [
                    'current_page' => $brands->currentPage(),
                    'per_page' => $brands->perPage(),
                    'total' => $brands->total(),
                    'total_pages' => $brands->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve brands by letter', [
                'letter' => $letter,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve brands by letter',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
