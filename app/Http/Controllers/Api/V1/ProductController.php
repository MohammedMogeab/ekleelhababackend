<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * Display a listing of products with pagination and filtering
     */
    public function index(Request $request)
    {
        // Validate request parameters
        $validated = $request->validate([
            'category'   => 'nullable|integer|exists:oc_category,category_id',
            'min_price'  => 'nullable|numeric|min:0',
            'max_price'  => 'nullable|numeric|min:0',
            'sort'       => ['nullable', 'string', Rule::in(['newest', 'price_asc', 'price_desc', 'rating', 'popularity'])],
            'per_page'   => 'nullable|integer|min:1|max:100',
            'brand'      => 'nullable|string'
        ]);

        $perPage = $request->get('per_page', 12);
        $sort = $request->get('sort', 'newest');

        $query = DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->join('oc_product_to_store as p2s', 'p.product_id', '=', 'p2s.product_id')
            ->leftJoin('oc_manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoin('oc_product_special as ps', function ($join) {
                $join->on('p.product_id', '=', 'ps.product_id')
                    ->where(function ($query) {
                        $query->whereNull('ps.date_start')
                            ->orWhere('ps.date_start', '<=', DB::raw('NOW()'));
                    })
                    ->where(function ($query) {
                        $query->whereNull('ps.date_end')
                            ->orWhere('ps.date_end', '>=', DB::raw('NOW()'));
                    });
            })
            ->where('pd.language_id', 1)
            ->where('p2s.store_id', 0)
            ->where('p.status', 1);

        // Apply category filter
        if ($request->filled('category')) {
            $query->join('oc_product_to_category as p2c', 'p.product_id', '=', 'p2c.product_id')
                ->where('p2c.category_id', $request->category);
        }

        // Apply brand filter
        if ($request->filled('brand')) {
            $query->where('m.name', 'like', '%' . $request->brand . '%');
        }

        // Select fields - wrap ps.price in ANY_VALUE()
        $query->groupBy('p.product_id')
            ->select([
                'p.product_id as id',
                DB::raw('ANY_VALUE(pd.name) as name'),
                DB::raw('ANY_VALUE(pd.description) as description'),
                DB::raw('ANY_VALUE(p.price) as price'),
                DB::raw('COALESCE(ANY_VALUE(ps.price), ANY_VALUE(p.price)) as final_price'),
                DB::raw('CASE WHEN ANY_VALUE(ps.price) IS NOT NULL AND ANY_VALUE(ps.price) < ANY_VALUE(p.price) THEN TRUE ELSE FALSE END as is_on_sale'),
                DB::raw('CASE WHEN ANY_VALUE(ps.price) IS NOT NULL AND ANY_VALUE(ps.price) < ANY_VALUE(p.price) THEN ROUND(((ANY_VALUE(p.price) - ANY_VALUE(ps.price)) / ANY_VALUE(p.price)) * 100, 2) ELSE 0 END as discount_percentage'),
                DB::raw("CONCAT('https://ekleelabha.shop/image/', ANY_VALUE(p.image)) as image"),
                DB::raw('ANY_VALUE(m.name) as manufacturer'),
                DB::raw('(ANY_VALUE(p.quantity) > 0) as in_stock'),
                DB::raw('ANY_VALUE(p.viewed) as viewed'),
                DB::raw('ANY_VALUE((SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1)) as average_rating'),
                DB::raw('ANY_VALUE((SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1)) as review_count')
            ]);

        // Apply price filters on final_price using HAVING
        if ($request->filled('min_price')) {
            $query->having('final_price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->having('final_price', '<=', $request->max_price);
        }

        // Apply sorting
        $this->applySorting($query, $sort);

        // Paginate results
        $products = $query->paginate($perPage);

        return response()->json([
            'data'       => $products->items(),
            'pagination' => [
                'total'         => $products->total(),
                'per_page'      => $products->perPage(),
                'current_page'  => $products->currentPage(),
                'last_page'     => $products->lastPage(),
            ],
            'filters'    => $this->getActiveFilters($request)
        ]);
    }

    
    /**
     * Process a product to add offer information
     */
    private function processProductWithOffers($product)
    {
        // Initialize default values
        $regularPrice = $product->price;
        $finalPrice = $regularPrice;
        $isOnSale = false;
        $discountPercentage = 0;
        $offerType = null;
        $offerDetails = null;

        // Check for special price (direct product special)
        $special = DB::table('oc_product_special as ps')
            ->where('ps.product_id', '=', $product->product_id)
            ->where('ps.customer_group_id', '=', 1)
            ->where('ps.date_start', '<=', now())
            ->where(function ($query) {
                $query->where('ps.date_end', '>=', now())
                    ->orWhereNull('ps.date_end');
            })
            ->orderBy('ps.priority', 'asc')
            ->first();

        // Check for discount (quantity-based discount)
        $discounts = DB::table('oc_product_discount as pd')
            ->where('pd.product_id', '=', $product->product_id)
            ->where('pd.customer_group_id', '=', 1)
            ->where('pd.quantity', '<=', 1)
            ->where('pd.date_start', '<=', now())
            ->where(function ($query) {
                $query->where('pd.date_end', '>=', now())
                    ->orWhereNull('pd.date_end');
            })
            ->orderBy('pd.priority', 'asc')
            ->first();

        // Check for active coupons applicable to this product
        $coupon = null;
        $productCategories = DB::table('oc_product_to_category')
            ->where('product_id', $product->product_id)
            ->pluck('category_id');

        if ($productCategories->count() > 0) {
            $coupon = DB::table('oc_coupon as c')
                ->join('oc_coupon_history as ch', 'c.coupon_id', '=', 'ch.coupon_id')
                ->leftJoin('oc_coupon_product as cp', 'c.coupon_id', '=', 'cp.coupon_id')
                ->leftJoin('oc_coupon_category as cc', 'c.coupon_id', '=', 'cc.coupon_id')
                ->where('c.status', 1)
                ->where('c.date_start', '<=', now())
                ->where(function ($query) {
                    $query->where('c.date_end', '>=', now())
                        ->orWhereNull('c.date_end');
                })
                ->where(function ($query) use ($product, $productCategories) {
                    $productId = $product->product_id;
                    $query->whereNull('cp.product_id')
                        ->orWhere('cp.product_id', $productId)
                        ->orWhereIn('cc.category_id', $productCategories);
                })
                ->select('c.*', 'ch.order_id', 'ch.amount')
                ->first();
        }

        // Determine the best offer (lowest price)
        $offerPrices = [];

        if ($special) {
            $offerPrices['special'] = [
                'price' => $special->price,
                'type' => 'special',
                'details' => [
                    'priority' => $special->priority,
                    'date_start' => $special->date_start,
                    'date_end' => $special->date_end
                ]
            ];
        }

        if ($discounts) {
            $offerPrices['discount'] = [
                'price' => $discounts->price,
                'type' => 'discount',
                'details' => [
                    'quantity' => $discounts->quantity,
                    'priority' => $discounts->priority,
                    'date_start' => $discounts->date_start,
                    'date_end' => $discounts->date_end
                ]
            ];
        }

        if ($coupon) {
            $discountAmount = 0;
            if ($coupon->type == 'P') { // Percentage
                $discountAmount = $regularPrice * ($coupon->discount / 100);
            } else { // Fixed amount
                $discountAmount = $coupon->discount;
            }

            $offerPrice = max(0, $regularPrice - $discountAmount);
            $offerPrices['coupon'] = [
                'price' => $offerPrice,
                'type' => 'coupon',
                'details' => [
                    'code' => $coupon->code,
                    'type' => $coupon->type,
                    'discount' => $coupon->discount,
                    'name' => $coupon->name,
                    'date_start' => $coupon->date_start,
                    'date_end' => $coupon->date_end
                ]
            ];
        }

        // Find the best offer (lowest price)
        if (!empty($offerPrices)) {
            usort($offerPrices, function ($a, $b) {
                return $a['price'] <=> $b['price'];
            });

            $bestOffer = $offerPrices[0];
            $finalPrice = $bestOffer['price'];
            $isOnSale = true;
            $offerType = $bestOffer['type'];
            $offerDetails = $bestOffer['details'];

            if ($regularPrice > 0) {
                $discountPercentage = round((($regularPrice - $finalPrice) / $regularPrice) * 100);
            }
        }

        // Get categories for the product
        $categories = DB::table('oc_product_to_category as p2c')
            ->join('oc_category as c', 'p2c.category_id', '=', 'c.category_id')
            ->join('oc_category_description as cd', 'c.category_id', '=', 'cd.category_id')
            ->where('p2c.product_id', $product->product_id)
            ->where('cd.language_id', 1)
            ->select('c.category_id', 'cd.name')
            ->get();

        return [
            'id' => $product->product_id,
            'name' => $product->name,
            'description' => $product->description,
            'model' => $product->model,
            'sku' => $product->sku,
            'upc' => $product->upc,
            'ean' => $product->ean,
            'quantity' => $product->quantity,
            'price' => (float)$regularPrice,
            'final_price' => (float)$finalPrice,
            'is_on_sale' => $isOnSale,
            'discount_percentage' => $discountPercentage,
            'offer_type' => $offerType,
            'offer_details' => $offerDetails,
            'image' => $product->image ? env('IMAGE_BASE_PATH')  . $product->image : null,
            'manufacturer' => $product->manufacturer_name,
            'viewed' => $product->viewed,
            'average_rating' => $product->average_rating ? (float)$product->average_rating : 0,
            'review_count' => (int)$product->review_count,
            'in_stock' => $product->quantity > 0,
            'categories' => $categories,
            'in_wishlist' => false // Will be set by middleware for authenticated users
        ];
    }

    /**
     * Apply sorting to the query
     */
    private function applySorting($query, $sort)
    {
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('p.price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('p.price', 'desc');
                break;
            case 'best_selling':
                $query->leftJoin('oc_order_product as op', 'p.product_id', '=', 'op.product_id')
                    ->select(
                        'p.product_id',
                        DB::raw('ANY_VALUE(p.model) as model'),
                        DB::raw('ANY_VALUE(p.sku) as sku'),
                        DB::raw('ANY_VALUE(p.upc) as upc'),
                        DB::raw('ANY_VALUE(p.ean) as ean'),
                        DB::raw('ANY_VALUE(p.quantity) as quantity'),
                        DB::raw('ANY_VALUE(p.price) as price'),
                        DB::raw('ANY_VALUE(p.image) as image'),
                        DB::raw('ANY_VALUE(pd.name) as name'),
                        DB::raw('ANY_VALUE(m.name) as manufacturer_name'),
                        DB::raw('ANY_VALUE(p.viewed) as viewed'),
                        DB::raw('ANY_VALUE((SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1)) as average_rating'),
                        DB::raw('SUM(op.quantity) as sales_count')
                    )
                    ->groupBy('p.product_id')
                    ->orderBy('sales_count', 'desc');
                break;
            case 'rating':
                $query->orderByRaw('COALESCE((SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1), 0) DESC');
                break;
            case 'newest':
            default:
                $query->orderBy('p.date_added', 'desc');
                break;
        }
    }


    /**
     * Display the specified product
     */
    public function show($id)
    {
        // Get the product with all related data
        $product = DB::table('oc_product as p')
            ->select(
                'p.*',
                'pd.name',
                'pd.description',
                'pd.meta_title',
                'pd.meta_description',
                'pd.meta_keyword',
                'm.name as manufacturer_name',
                DB::raw('(SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1) as average_rating'),
                DB::raw('(SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1) as review_count')
            )
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->leftJoin('oc_manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', '=', $id)
            ->where('pd.language_id', '=', 1)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Format the product with deal information
        $formattedProduct = $this->formatProduct($product);

        // Get categories
        $categories = DB::table('oc_product_to_category as ptc')
            ->join('oc_category_description as cd', 'ptc.category_id', '=', 'cd.category_id')
            ->where('ptc.product_id', '=', $id)
            ->where('cd.language_id', '=', 1)
            ->select('cd.category_id as id', 'cd.name')
            ->get();

        // Get reviews
        $reviews = DB::table('oc_review as r')
            ->join('oc_customer as c', 'r.customer_id', '=', 'c.customer_id')
            ->where('r.product_id', '=', $id)
            ->where('r.status', '=', 1)
            ->select('r.review_id as id', 'r.rating', 'r.text', 'r.date_added as created_at', 'c.firstname as name')
            ->orderBy('r.date_added', 'desc')
            ->get();

        $formattedProduct['categories'] = $categories;
        $formattedProduct['reviews'] = $reviews;

        return response()->json($formattedProduct);
    }

    /**
     * Get related products.
     *
     * @urlParam id required Product ID.
     * @queryParam limit int Items per page (default: 5).
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function related($id, Request $request)
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

        $limit = $request->get('limit', 5);

        try {
            $cacheKey = 'related_products:' . $id . ':' . $limit;
            $cacheDuration = now()->addHours(1);

            $products = Cache::remember($cacheKey, $cacheDuration, function () use ($id, $limit) {
                // First try to get related products from oc_product_related
                $relatedIds = DB::table('oc_product_related')
                    ->where('product_id', $id)
                    ->pluck('related_id');

                if ($relatedIds->isNotEmpty()) {
                    return $this->buildProductQuery()
                        ->whereIn('p.product_id', $relatedIds)
                        ->limit($limit)
                        ->get();
                }

                // If no related products found, get from same category
                $categoryIds = DB::table('oc_product_to_category')
                    ->where('product_id', $id)
                    ->pluck('category_id');

                if ($categoryIds->isNotEmpty()) {
                    return $this->buildProductQuery()
                        ->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                        ->whereIn('ptc.category_id', $categoryIds)
                        ->where('p.product_id', '!=', $id)
                        ->orderByRaw('RAND()')
                        ->limit($limit)
                        ->get();
                }

                // If still no products, get random products
                return $this->buildProductQuery()
                    ->where('p.product_id', '!=', $id)
                    ->orderByRaw('RAND()')
                    ->limit($limit)
                    ->get();
            });

            return response()->json([
                'data' => $this->formatProducts($products)
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve related products', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve related products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get similar products.
     *
     * @urlParam id required Product ID.
     * @queryParam limit int Items per page (default: 5).
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function similar($id, Request $request)
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

        $limit = $request->get('limit', 5);

        try {
            $cacheKey = 'similar_products:' . $id . ':' . $limit;
            $cacheDuration = now()->addHours(1);

            $products = Cache::remember($cacheKey, $cacheDuration, function () use ($id, $limit) {
                // Get product attributes
                $attributeIds = DB::table('oc_product_attribute')
                    ->where('product_id', $id)
                    ->pluck('attribute_id');

                if ($attributeIds->isNotEmpty()) {
                    // Get products with similar attributes
                    $similarProductIds = DB::table('oc_product_attribute')
                        ->whereIn('attribute_id', $attributeIds)
                        ->where('product_id', '!=', $id)
                        ->groupBy('product_id')
                        ->orderByRaw('COUNT(*) DESC')
                        ->limit($limit * 2)
                        ->pluck('product_id');

                    if ($similarProductIds->isNotEmpty()) {
                        return $this->buildProductQuery()
                            ->whereIn('p.product_id', $similarProductIds)
                            ->orderByRaw('FIELD(p.product_id, ' . $similarProductIds->implode(',') . ')')
                            ->limit($limit)
                            ->get();
                    }
                }

                // If no similar products found by attributes, get from same category
                $categoryIds = DB::table('oc_product_to_category')
                    ->where('product_id', $id)
                    ->pluck('category_id');

                if ($categoryIds->isNotEmpty()) {
                    return $this->buildProductQuery()
                        ->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                        ->whereIn('ptc.category_id', $categoryIds)
                        ->where('p.product_id', '!=', $id)
                        ->orderByRaw('RAND()')
                        ->limit($limit)
                        ->get();
                }

                // If still no products, get random products
                return $this->buildProductQuery()
                    ->where('p.product_id', '!=', $id)
                    ->orderByRaw('RAND()')
                    ->limit($limit)
                    ->get();
            });

            return response()->json([
                'data' => $this->formatProducts($products)
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve similar products', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve similar products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get top selling products.
     *
     * @queryParam page int Page number (default: 1)
     * @queryParam limit int Items per page (default: 10)
     * @queryParam period string Time period (week, month, quarter, year) (default: month)
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function top(Request $request)
    {
        $perPage = $request->get('per_page', 20); // default pagination size

        $products = DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->leftJoin('oc_manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoin('oc_product_special as ps', function ($join) {
                $join->on('p.product_id', '=', 'ps.product_id')
                    ->where(function ($query) {
                        $query->whereNull('ps.date_start')
                            ->orWhere('ps.date_start', '<=', DB::raw('NOW()'));
                    })
                    ->where(function ($query) {
                        $query->whereNull('ps.date_end')
                            ->orWhere('ps.date_end', '>=', DB::raw('NOW()'));
                    });
            })
            ->leftJoin('oc_order_product as op', 'p.product_id', '=', 'op.product_id')
            ->leftJoin('oc_order as o', 'op.order_id', '=', 'o.order_id')
            ->where('pd.language_id', 1)
            ->where(function ($query) {
                $query->where('o.order_status_id', '>', 0)
                    ->orWhereNull('o.order_status_id');
            })
            ->where('p.status', 1)
            ->groupBy(
                'p.product_id',
                'pd.name',
                'pd.description',
                'p.price',
                'ps.price',
                'p.image',
                'm.name',
                'p.quantity'
            )
            ->select([
                'p.product_id as id',
                'pd.name as name',
                'pd.description as description',
                'p.price as price',
                DB::raw('COALESCE(ps.price, p.price) as final_price'),
                DB::raw('CASE WHEN ps.price IS NOT NULL AND ps.price < p.price THEN TRUE ELSE FALSE END as is_on_sale'),
                DB::raw('CASE WHEN ps.price IS NOT NULL AND ps.price < p.price THEN ROUND(((p.price - ps.price) / p.price) * 100, 2) ELSE 0 END as discount_percentage'),
                DB::raw("CONCAT('https://ekleelabha.shop/image/', p.image) as image"),
                'm.name as manufacturer',
                DB::raw('(p.quantity > 0) as in_stock'),
                DB::raw('IFNULL(SUM(op.quantity), 0) as total_sold')
            ])
            ->orderByDesc('total_sold')
            ->paginate($perPage);

        return response()->json($products);
    }


    /**
     * Get new arrival products.
     *
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 10).
     * @queryParam days int Number of days to consider as new (default: 30).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function newArrivals(Request $request)
    {
        $perPage = $request->get('per_page', 20); // optional pagination size

        $products = DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->leftJoin('oc_manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoin('oc_product_special as ps', function ($join) {
                $join->on('p.product_id', '=', 'ps.product_id')
                    ->where(function ($query) {
                        $query->whereNull('ps.date_start')
                            ->orWhere('ps.date_start', '<=', DB::raw('NOW()'));
                    })
                    ->where(function ($query) {
                        $query->whereNull('ps.date_end')
                            ->orWhere('ps.date_end', '>=', DB::raw('NOW()'));
                    });
            })
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->orderByDesc('p.date_added')
            ->select([
                'p.product_id as id',
                'pd.name as name',
                'pd.description as description',
                'p.price as price',
                DB::raw('COALESCE(ps.price, p.price) as final_price'),
                DB::raw('CASE WHEN ps.price IS NOT NULL AND ps.price < p.price THEN TRUE ELSE FALSE END as is_on_sale'),
                DB::raw('CASE WHEN ps.price IS NOT NULL AND ps.price < p.price THEN ROUND(((p.price - ps.price) / p.price) * 100, 2) ELSE 0 END as discount_percentage'),
                DB::raw('p.image'),
                'm.name as manufacturer',
                DB::raw('(p.quantity > 0) as in_stock'),
                'p.date_added',
            ])
            ->paginate($perPage);

        return response()->json($products);
    }





    /**
     * Get products on deal
     * 
     * @queryParam page int Page number (default: 1)
     * @queryParam limit int Items per page (default: 10)
     * @queryParam category int Category ID to filter by
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deals(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50',
            'category' => 'integer|exists:oc_category,category_id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $categoryId = $request->get('category');

        try {
            $cacheKey = 'deals_products:' . $page . ':' . $limit . ':' . ($categoryId ?: 'all');
            $cacheDuration = now()->addMinutes(15);

            $products = Cache::remember($cacheKey, $cacheDuration, function () use ($categoryId, $limit) {
                // Build a complete query for deals products
                $query = DB::table('oc_product as p')
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
                    ->leftJoin('oc_manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
                    ->where('p.status', 1)
                    ->where('p.quantity', '>', 0)
                    ->whereNotNull('ps.price')  // Only products with special prices
                    ->whereColumn('ps.price', '<', 'p.price');  // Compare special price to regular price

                // Add category filter if specified
                if ($categoryId) {
                    $query->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                        ->where('ptc.category_id', $categoryId);
                }

                // Select all necessary fields with proper aggregation
                $query->select(
                    'p.product_id',
                    DB::raw('ANY_VALUE(p.model) as model'),
                    DB::raw('ANY_VALUE(p.sku) as sku'),
                    DB::raw('ANY_VALUE(p.upc) as upc'),
                    DB::raw('ANY_VALUE(p.ean) as ean'),
                    DB::raw('ANY_VALUE(p.quantity) as quantity'),
                    DB::raw('COALESCE(ANY_VALUE(ps.price), ANY_VALUE(p.price)) as final_price'),
                    DB::raw('ANY_VALUE(p.price) as price'),
                    DB::raw('ANY_VALUE(p.image) as image'),
                    DB::raw('ANY_VALUE(COALESCE(pd.name, p.model)) as name'),
                    DB::raw('ANY_VALUE(COALESCE(pd.description, "")) as description'),
                    DB::raw('ANY_VALUE(COALESCE(pd.meta_title, "")) as meta_title'),
                    DB::raw('ANY_VALUE(COALESCE(pd.meta_description, "")) as meta_description'),
                    DB::raw('ANY_VALUE(COALESCE(pd.meta_keyword, "")) as meta_keyword'),
                    DB::raw('ANY_VALUE(m.name) as manufacturer_name'),
                    DB::raw('(SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1) as average_rating'),
                    DB::raw('(SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1) as review_count'),
                    DB::raw('MAX(ps.date_start) as latest_special_date') // Critical fix: add this for ordering
                )
                    ->groupBy('p.product_id')
                    ->orderBy('latest_special_date', 'desc') // Order by the aggregated column
                    ->limit($limit);

                return $query->get();
            });

            return response()->json([
                'data' => $this->formatProducts($products),
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $products->count(),
                    'total_pages' => ceil($products->count() / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve deals products', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve deals products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    /**
     * Format products for API response.
     *
     * @param \Illuminate\Support\Collection $products
     * @return array
     */
    private function formatProducts($products)
    {
        return $products->map(function ($product) {
            $finalPrice = $product->final_price ?? $product->price;
            $isOnSale = $finalPrice < $product->price;

            return [
                'id' => $product->product_id,
                'name' => $product->name,
                'description' => $product->description,
                'model' => $product->model,
                'price' => (float)$product->price,
                'final_price' => (float)$finalPrice,
                'is_on_sale' => $isOnSale,
                'discount_percentage' => $isOnSale ? round((($product->price - $finalPrice) / $product->price) * 100) : 0,
                'quantity' => $product->quantity,
                'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                'manufacturer' => $product->manufacturer_name,
                'in_stock' => $product->quantity > 0,
                'average_rating' => $product->average_rating ? round($product->average_rating, 1) : 0,
                'review_count' => (int)$product->review_count,
                'date_added' => $product->date_added ?? now()->toDateTimeString(),
                'meta' => [
                    'title' => $product->meta_title,
                    'description' => $product->meta_description,
                    'keywords' => $product->meta_keyword
                ]
            ];
        })->toArray();
    }


    /**
     * Build base product query with all necessary joins.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function buildProductQuery()
    {
        return DB::table('oc_product as p')
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
            ->select(
                'p.product_id',
                'p.model',
                'p.sku',
                'p.upc',
                'p.ean',
                'p.quantity',
                DB::raw('COALESCE(ps.price, p.price) as final_price'),
                'p.price',
                'p.image',
                'pd.name',
                'pd.description',
                'pd.meta_title',
                'pd.meta_description',
                'pd.meta_keyword',
                'm.name as manufacturer_name',
                DB::raw('(SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1) as average_rating'),
                DB::raw('(SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1) as review_count')
            );
    }


    /**
     * Format a product with deal information
     */
    private function formatProduct($product)
    {
        // Check for special prices
        $special = DB::table('oc_product_special')
            ->where('product_id', $product->product_id)
            ->where('customer_group_id', 1)
            ->where('date_start', '<=', now())
            ->where(function ($query) {
                $query->where('date_end', '>=', now())
                    ->orWhereNull('date_end');
            })
            ->orderBy('priority')
            ->first();

        // Check for quantity-based discounts
        $discounts = DB::table('oc_product_discount')
            ->where('product_id', $product->product_id)
            ->where('customer_group_id', 1)
            ->where('quantity', '<=', 1)
            ->where('date_start', '<=', now())
            ->where(function ($query) {
                $query->where('date_end', '>=', now())
                    ->orWhereNull('date_end');
            })
            ->orderBy('priority')
            ->first();

        $isOnSale = false;
        $discountPercentage = 0;
        $regularPrice = $product->price;
        $finalPrice = $product->price;

        // Special price has higher priority than quantity discounts
        if ($special) {
            $finalPrice = $special->price;
            $isOnSale = true;
            if ($product->price > 0) {
                $discountPercentage = round((($product->price - $special->price) / $product->price) * 100);
            }
        } else if ($discounts && $discounts->price < $product->price) {
            $finalPrice = $discounts->price;
            $isOnSale = true;
            if ($product->price > 0) {
                $discountPercentage = round((($product->price - $discounts->price) / $product->price) * 100);
            }
        }

        return [
            'id' => $product->product_id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (float)$regularPrice,
            'final_price' => (float)$finalPrice,
            'discount_percentage' => $discountPercentage,
            'is_on_sale' => $isOnSale,
            'image' => env('IMAGE_BASE_PATH') . $product->image,
            'in_stock' => $product->quantity > 0,
            'average_rating' => (float)($product->average_rating ?? 0),
            'review_count' => (int)($product->review_count ?? 0),
            'model' => $product->model ?? null,
            'sku' => $product->sku ?? null,
            'upc' => $product->upc ?? null,
            'ean' => $product->ean ?? null
        ];
    }

    /**
     * Format product detail data for response
     */
    private function formatProductDetail($product, $images, $categories, $attributes, $options, $discounts, $special)
    {
        // Add safety checks for all required properties
        $product->name = property_exists($product, 'name') ? $product->name : 'Unnamed Product';
        $product->model = property_exists($product, 'model') ? $product->model : '';
        $product->sku = property_exists($product, 'sku') ? $product->sku : '';
        $product->upc = property_exists($product, 'upc') ? $product->upc : '';
        $product->ean = property_exists($product, 'ean') ? $product->ean : '';
        $product->quantity = property_exists($product, 'quantity') ? $product->quantity : 0;
        $product->description = property_exists($product, 'description') ? $product->description : '';

        // Calculate final price considering discounts and specials
        $finalPrice = $product->price;
        $isOnSale = false;
        $discountPercentage = 0;
        $regularPrice = $product->price;

        if ($special) {
            $finalPrice = $special->price;
            $isOnSale = true;
            if ($product->price > 0) {
                $discountPercentage = round((($product->price - $special->price) / $product->price) * 100);
            }
        } else if ($discounts && $discounts->price < $product->price) {
            $finalPrice = $discounts->price;
            $isOnSale = true;
            if ($product->price > 0) {
                $discountPercentage = round((($product->price - $discounts->price) / $product->price) * 100);
            }
        }

        return [
            'id' => $product->product_id,
            'name' => $product->name,
            'description' => $product->description,
            'meta_title' => $product->meta_title ?? '',
            'meta_description' => $product->meta_description ?? '',
            'meta_keyword' => $product->meta_keyword ?? '',
            'model' => $product->model,
            'sku' => $product->sku,
            'upc' => $product->upc,
            'ean' => $product->ean,
            'jan' => $product->jan ?? '',
            'isbn' => $product->isbn ?? '',
            'mpn' => $product->mpn ?? '',
            'location' => $product->location ?? '',
            'quantity' => $product->quantity,
            'minimum' => $product->minimum ?? 1,
            'subtract' => $product->subtract ?? true,
            'stock_status_id' => $product->stock_status_id ?? 0,
            'date_available' => $product->date_available ?? now(),
            'manufacturer_id' => $product->manufacturer_id ?? 0,
            'manufacturer' => $product->manufacturer_name ?? '',
            'shipping' => (bool)($product->shipping ?? true),
            'price' => (float)$product->price,
            'final_price' => (float)$finalPrice,
            'is_on_sale' => $isOnSale,
            'discount_percentage' => $discountPercentage,
            'regular_price' => (float)$regularPrice,
            'points' => $product->points ?? 0,
            'tax_class_id' => $product->tax_class_id ?? 0,
            'date_added' => $product->date_added ?? now(),
            'date_modified' => $product->date_modified ?? now(),
            'status' => (bool)($product->status ?? true),
            'weight' => $product->weight ?? 0.0,
            'weight_class_id' => $product->weight_class_id ?? 0,
            'length' => $product->length ?? 0.0,
            'width' => $product->width ?? 0.0,
            'height' => $product->height ?? 0.0,
            'length_class_id' => $product->length_class_id ?? 0,
            'sort_order' => $product->sort_order ?? 0,
            'viewed' => $product->viewed ?? 0,
            'average_rating' => $product->average_rating ? (float)$product->average_rating : 0,
            'review_count' => $product->review_count ? (int)$product->review_count : 0,
            'images' => $images,
            'categories' => $categories->map(function ($category) {
                return [
                    'id' => $category->category_id,
                    'name' => $category->name
                ];
            }),
            'attributes' => $attributes->map(function ($attribute) {
                return [
                    'group' => $attribute->group_name,
                    'value' => $attribute->value
                ];
            }),
            // Corrected options format
            'options' => array_map(function ($option) {
                return [
                    'id' => $option['id'],
                    'option_id' => $option['option_id'],
                    'name' => $option['name'],
                    'type' => $option['type'],
                    'required' => $option['required'],
                    'values' => $option['values']
                ];
            }, $options)
        ];
    }

    /**
     * Get currently applied filters
     */
    private function getActiveFilters(Request $request)
    {
        $filters = [];

        if ($request->has('category')) {
            $category = DB::table('oc_category_description')
                ->where('category_id', $request->category)
                ->where('language_id', 1)
                ->first();

            if ($category) {
                $filters['category'] = [
                    'id' => $request->category,
                    'name' => $category->name
                ];
            }
        }

        if ($request->has('min_price') || $request->has('max_price')) {
            $filters['price_range'] = [
                'min' => $request->min_price,
                'max' => $request->max_price
            ];
        }

        return $filters;
    }
}
