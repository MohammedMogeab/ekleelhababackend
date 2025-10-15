<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{

    /**
     * Get full hierarchical category tree.
     *
     * @queryParam language int Language ID (default: 1).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {


        $validator = Validator::make($request->all(), [
            'parent_id' => 'integer|min:0',
            'include_products' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $parentId = $request->get('parent_id', 0);
        $includeProducts = $request->boolean('include_products', false);

        try {
            $cacheKey = 'category_tree:' . $parentId . ':' . ($includeProducts ? 'with_products' : 'no_products');
            $cacheDuration = now()->addHours(1);

            $categories = Cache::remember($cacheKey, $cacheDuration, function () use ($parentId, $includeProducts) {
                return $this->buildCategoryTree($parentId, $includeProducts);
            });

            return response()->json([
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve category tree', [
                'parent_id' => $parentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve category tree',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    /**
     * Get category tree for navigation.
     *
     * @queryParam parent_id int Parent category ID (default: 0 for top-level categories).
     * @queryParam include_products bool Include product count (default: false).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tree(Request $request)
    {
        // return response()->json(['message' => 'This endpoint has been deprecated. Please use the index endpoint instead.'], 410);
        $validator = Validator::make($request->all(), [
            'parent_id' => 'integer|min:0',
            'include_products' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $parentId = $request->get('parent_id', 0);
        $includeProducts = $request->boolean('include_products', false);

        try {
            $cacheKey = 'category_tree:' . $parentId . ':' . ($includeProducts ? 'with_products' : 'no_products');
            $cacheDuration = now()->addHours(1);

            $categories = Cache::remember($cacheKey, $cacheDuration, function () use ($parentId, $includeProducts) {
                return $this->buildCategoryTree($parentId, $includeProducts);
            });

            return response()->json([
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve category tree', [
                'parent_id' => $parentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve category tree',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get category details and products.
     *
     * @urlParam id required Category ID.
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 15).
     * @queryParam sort string Sort by: newest, price_asc, price_desc, rating (default: newest).
     * @queryParam min_price numeric Minimum price filter.
     * @queryParam max_price numeric Maximum price filter.
     * @queryParam attributes string Comma-separated attribute IDs for filtering.
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
            'sort' => 'string|in:newest,price_asc,price_desc,rating',
            'min_price' => 'numeric|min:0',
            'max_price' => 'numeric|min:0',
            'attributes' => 'string'
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
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');
        $attributes = $request->get('attributes') ? explode(',', $request->get('attributes')) : [];

        try {
            // Get category details
            $category = $this->getCategoryDetails($id);
            if (!$category) {
                return response()->json([
                    'message' => 'Category not found'
                ], 404);
            }
            // Get filtered products
            $products = $this->getCategoryProducts($id, [
                'page' => $page,
                'limit' => $limit,
                'sort' => $sort,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'attributes' => $attributes
            ]);


            return response()->json([
                'data' => [
                    'id' => $category->category_id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'image' => $category->image ? env("IMAGE_BASE_PATH") . $category->image : null,
                    'meta_title' => $category->meta_title,
                    'meta_description' => $category->meta_description,
                    'meta_keyword' => $category->meta_keyword,
                    'products' => $products['data'],
                    'filters' => $this->getCategoryFilters($id)
                ],
                'meta' => $products['meta']
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve category details', [
                'category_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve category details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get child categories.
     *
     * @urlParam id required Category ID.
     * @queryParam include_products bool Include product count (default: false).
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function children($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'include_products' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $includeProducts = $request->boolean('include_products', false);

        try {
            $cacheKey = 'category_children:' . $id . ':' . ($includeProducts ? 'with_products' : 'no_products');
            $cacheDuration = now()->addHours(1);

            $children = Cache::remember($cacheKey, $cacheDuration, function () use ($id, $includeProducts) {
                return $this->getCategoryChildren($id, $includeProducts);
            });

            return response()->json([
                'data' => $children
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve category children', [
                'category_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve category children',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get category filters (attributes).
     *
     * @urlParam id required Category ID.
     *
     * @param int $id
     * @return array
     */
    private function getCategoryFilters($id)
    {
        $attributesQuery = DB::table('oc_attribute_group_description as agd')
            ->select(
                'agd.name as group_name',
                'a.attribute_id',
                'ad.name as attribute_name',
                'agd.attribute_group_id',
                'ag.sort_order',
                'ag.sort_order as group_sort_order',
                'a.sort_order as attribute_sort_order'
            )
            ->join('oc_attribute_group as ag', 'agd.attribute_group_id', '=', 'ag.attribute_group_id')
            ->join('oc_attribute as a', 'ag.attribute_group_id', '=', 'a.attribute_group_id')
            ->join('oc_attribute_description as ad', function ($join) {
                $join->on('a.attribute_id', '=', 'ad.attribute_id')
                    ->where('ad.language_id', '=', 1);
            })
            ->join('oc_product_attribute as pa', 'a.attribute_id', '=', 'pa.attribute_id')
            ->join('oc_product as p', 'pa.product_id', '=', 'p.product_id')
            ->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
            ->where('agd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->where('ptc.category_id', $id)
            ->distinct()
            ->orderBy('ag.sort_order')
            ->orderBy('a.sort_order');

        $results = $attributesQuery->get();

        // Group results by attribute group
        $groupedResults = $results->groupBy('group_name')->map(function ($group) {
            return [
                'group_name' => $group->first()->group_name,
                'attributes' => $group->map(function ($item) {
                    return [
                        'id' => $item->attribute_id,
                        'name' => $item->attribute_name
                    ];
                })
            ];
        })->values();

        return $groupedResults->toArray();
    }

    /**
     * Build category tree recursively.
     *
     * @param int $parentId
     * @param bool $includeProducts
     * @return array
     */
    private function buildCategoryTree($parentId, $includeProducts)
    {
        $categories = DB::table('oc_category as c')
            ->join('oc_category_description as cd', 'c.category_id', '=', 'cd.category_id')
            ->where('cd.language_id', 1)
            ->where('c.parent_id', $parentId)
            ->where('c.status', 1)
            ->select(
                'c.category_id',
                'cd.name',
                'cd.description',
                'c.image',
                'c.parent_id',
                DB::raw('(SELECT COUNT(*) FROM oc_category WHERE parent_id = c.category_id AND status = 1) as children_count')
            )
            ->orderBy('c.sort_order')
            ->get();

        return $categories->map(function ($category) use ($includeProducts) {
            $categoryData = [
                'id' => $category->category_id,
                'name' => $category->name,
                'description' => $category->description,
                'image' => $category->image ? env("IMAGE_BASE_PATH") . $category->image : null,
                'parent_id' => $category->parent_id,
                'children_count' => $category->children_count
            ];

            if ($includeProducts) {
                $categoryData['product_count'] = $this->getCategoryProductCount($category->category_id);
            }

            // Add children recursively
            if ($category->children_count > 0) {
                $categoryData['children'] = $this->buildCategoryTree($category->category_id, $includeProducts);
            }

            return $categoryData;
        })->toArray();
    }

    /**
     * Get category details.
     *
     * @param int $id
     * @return \stdClass|null
     */
    private function getCategoryDetails($id)
    {
        return DB::table('oc_category as c')
            ->join('oc_category_description as cd', 'c.category_id', '=', 'cd.category_id')
            ->where('c.category_id', $id)
            ->where('cd.language_id', 1)
            ->where('c.status', 1)
            ->select(
                'c.category_id',
                'cd.name',
                'cd.description',
                'cd.meta_title',
                'cd.meta_description',
                'cd.meta_keyword',
                'c.image',
                'c.parent_id'
            )
            ->first();
    }

    /**
     * Get category product count.
     *
     * @param int $categoryId
     * @return int
     */
    private function getCategoryProductCount($categoryId)
    {
        return DB::table('oc_product_to_category as ptc')
            ->join('oc_product as p', 'ptc.product_id', '=', 'p.product_id')
            ->where('ptc.category_id', $categoryId)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->count();
    }

    /**
     * Get category products with filters and pagination.
     *
     * @param int $categoryId
     * @param array $params
     * @return array
     */
    private function getCategoryProducts($categoryId, $params)
    {
        $page = $params['page'];
        $limit = $params['limit'];
        $sort = $params['sort'];
        $minPrice = $params['min_price'];
        $maxPrice = $params['max_price'];
        $attributes = $params['attributes'];

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
            ->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->where('ptc.category_id', $categoryId)
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
                'm.name as manufacturer_name',
                DB::raw('(SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1) as average_rating'),
                DB::raw('(SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1) as review_count')
            );

        // Apply price filters
        if ($minPrice !== null) {
            $query->where(DB::raw('COALESCE(ps.price, p.price)'), '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $query->where(DB::raw('COALESCE(ps.price, p.price)'), '<=', $maxPrice);
        }

        // Apply attribute filters
        if (!empty($attributes)) {
            $query->join('oc_product_attribute as pa', 'p.product_id', '=', 'pa.product_id')
                ->whereIn('pa.attribute_id', $attributes)
                ->groupBy('p.product_id');
        }

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

        // Get total count for pagination
        $totalCount = $query->count();

        // Apply pagination
        $products = $query->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $formattedProducts = $products->map(function ($product) {
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
                'image' => $product->image ?env("IMAGE_BASE_PATH") . $product->image : null,
                'manufacturer' => $product->manufacturer_name,
                'in_stock' => $product->quantity > 0,
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
     * Get category children.
     *
     * @param int $parentId
     * @param bool $includeProducts
     * @return array
     */
    private function getCategoryChildren($parentId, $includeProducts)
    {
        $children = DB::table('oc_category as c')
            ->join('oc_category_description as cd', 'c.category_id', '=', 'cd.category_id')
            ->where('cd.language_id', 1)
            ->where('c.parent_id', $parentId)
            ->where('c.status', 1)
            ->select(
                'c.category_id',
                'cd.name',
                'cd.description',
                'c.image',
                'c.parent_id'
            )
            ->orderBy('c.sort_order')
            ->get();

        return $children->map(function ($category) use ($includeProducts) {
            $categoryData = [
                'id' => $category->category_id,
                'name' => $category->name,
                'description' => $category->description,
                'image' => $category->image ? env("IMAGE_BASE_PATH") . $category->image : null,
                'parent_id' => $category->parent_id
            ];

            if ($includeProducts) {
                $categoryData['product_count'] = $this->getCategoryProductCount($category->category_id);
            }

            return $categoryData;
        })->toArray();
    }
}
