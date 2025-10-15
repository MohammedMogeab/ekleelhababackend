<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    /**
     * Handle product search requests
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:255',
            'category' => 'nullable|integer|exists:oc_category,category_id',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'sort' => ['nullable', 'string', 'in:newest,price_asc,price_desc,rating'],
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50'
        ]);
    
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 20);
        $limit = min($limit, 50); // Cap at 50
    
        $query = $request->get('q', '');
        $categoryId = $request->get('category');
        $minPrice = $request->get('min_price', 0);
        $maxPrice = $request->get('max_price');
        $sort = $request->get('sort', 'relevance');
    
        // Build the base query
        $productsQuery = DB::table('oc_product as p')
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
            ->select(
                'p.product_id',
                'p.model',
                'p.sku',
                'p.upc',
                'p.ean',
                'p.quantity',
                'p.price',
                'ps.price as special_price', // CRITICAL: Alias special price correctly
                'p.image',
                'pd.name',
                'pd.description',
                'm.name as manufacturer_name',
                DB::raw('(SELECT AVG(rating) FROM oc_review WHERE product_id = p.product_id AND status = 1) as average_rating'),
                DB::raw('(SELECT COUNT(*) FROM oc_review WHERE product_id = p.product_id AND status = 1) as review_count')
            )
            ->where('p.status', '=', 1)
            ->where('pd.language_id', '=', 1);
    
        // Apply search query if provided
        if ($query) {
            $searchTerms = explode(' ', $query);
            foreach ($searchTerms as $term) {
                $productsQuery->where(function ($q) use ($term) {
                    $q->where('pd.name', 'LIKE', '%' . $term . '%')
                        ->orWhere('pd.description', 'LIKE', '%' . $term . '%')
                        ->orWhere('p.model', 'LIKE', '%' . $term . '%')
                        ->orWhere('p.sku', 'LIKE', '%' . $term . '%')
                        ->orWhere('p.upc', 'LIKE', '%' . $term . '%')
                        ->orWhere('p.ean', 'LIKE', '%' . $term . '%');
                });
            }
        }
    
        // Apply category filter if provided
        if ($categoryId) {
            $productsQuery->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->where('ptc.category_id', '=', $categoryId);
        }
    
        // Apply price filters
        $productsQuery->where('p.price', '>=', $minPrice);
        if ($maxPrice !== null) {
            $productsQuery->where('p.price', '<=', $maxPrice);
        }
    
        // Apply sorting
        switch ($sort) {
            case 'price_asc':
                $productsQuery->orderBy('p.price', 'asc');
                break;
            case 'price_desc':
                $productsQuery->orderBy('p.price', 'desc');
                break;
            case 'rating':
                $productsQuery->orderBy('average_rating', 'desc');
                break;
            case 'newest':
                $productsQuery->orderBy('p.date_added', 'desc');
                break;
            default: // relevance or default
                if ($query) {
                    $productsQuery->orderByRaw('CASE 
                        WHEN pd.name LIKE ? THEN 1 
                        WHEN pd.description LIKE ? THEN 2 
                        ELSE 3 
                    END', ["%$query%", "%$query%"]);
                } else {
                    $productsQuery->orderBy('p.date_added', 'desc');
                }
        }
    
        $total = $productsQuery->count();
        $products = $productsQuery
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();
    
        return response()->json([
            'data' => $this->formatProducts($products),
            'pagination' => [
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Apply sorting to the query
     */
    private function applySorting($query, $sort, $searchQuery)
    {
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('p.price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('p.price', 'desc');
                break;
            case 'newest':
                $query->orderBy('p.date_added', 'desc');
                break;
            case 'best_selling':
                $query->leftJoin('oc_order_product as op', 'p.product_id', '=', 'op.product_id')
                      ->selectRaw('p.*, COUNT(op.order_product_id) as sales_count')
                      ->groupBy('p.product_id')
                      ->orderBy('sales_count', 'desc');
                break;
            case 'relevance':
            default:
                // For relevance, if query is provided, order by name matching
                if ($searchQuery) {
                    $query->orderByRaw('CASE 
                        WHEN pd.name LIKE ? THEN 1 
                        WHEN pd.description LIKE ? THEN 2 
                        ELSE 3 
                    END', ["%{$searchQuery}%", "%{$searchQuery}%"])
                    ->orderBy('p.date_added', 'desc');
                } else {
                    $query->orderBy('p.sort_order', 'asc')
                          ->orderBy('p.date_added', 'desc');
                }
                break;
        }
    }

    /**
     * Format product data for response
     */
    private function formatProducts($products)
    {
        return $products->map(function ($product) {
            $finalPrice = $product->price;
            $isOnSale = false;
            $discountPercentage = 0;
            
            // Check if special_price exists and is valid
            if (isset($product->special_price) && $product->special_price > 0 && $product->special_price < $product->price) {
                $finalPrice = $product->special_price;
                $isOnSale = true;
                $discountPercentage = round((($product->price - $finalPrice) / $product->price) * 100);
            }
            
            return [
                'id' => $product->product_id,
                'name' => $product->name,
                'description' => $product->description,
                'model' => $product->model,
                'price' => (float)$product->price,
                'final_price' => (float)$finalPrice,
                'is_on_sale' => $isOnSale,
                'discount_percentage' => $discountPercentage,
                'quantity' => $product->quantity,
                'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                'manufacturer' => $product->manufacturer_name,
                'in_stock' => $product->quantity > 0,
                'average_rating' => $product->average_rating ? round($product->average_rating, 1) : 0,
                'review_count' => (int)($product->review_count ?? 0)
            ];
        });
    }

    /**
     * Get search suggestions
     */
    public function suggest(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:50',
        ]);

        $query = $request->q;

        // Get search suggestions (product names)
        $suggestions = DB::table('oc_product_description')
            ->select('name')
            ->where('language_id', 1)
            ->where('name', 'LIKE', $query . '%')
            ->limit(10)
            ->pluck('name')
            ->unique()
            ->values();

        return response()->json([
            'suggestions' => $suggestions
        ]);
    }

    /**
     * Get available filters for search
     */
    public function filters(Request $request)
    {
        $request->validate([
            'category' => 'required|integer',
        ]);
    
        $attributesQuery = DB::table('oc_attribute_group_description as agd')
            ->select(
                'agd.name as group_name',
                'a.attribute_id',
                'ad.name as attribute_name',
                'agd.attribute_group_id',
                'ag.sort_order as group_sort_order',  // ADDED: Include sort_order for ordering
                'a.sort_order as attribute_sort_order' // ADDED: Include sort_order for ordering
            )
            ->join('oc_attribute_group as ag', 'agd.attribute_group_id', '=', 'ag.attribute_group_id')
            ->join('oc_attribute as a', 'ag.attribute_group_id', '=', 'a.attribute_group_id')
            ->join('oc_attribute_description as ad', function ($join) {
                $join->on('a.attribute_id', '=', 'ad.attribute_id')
                    ->where('ad.language_id', '=', 1);
            })
            ->join('oc_product_attribute as pa', 'a.attribute_id', '=', 'pa.attribute_id')
            ->join('oc_product as p', 'pa.product_id', '=', 'p.product_id')
            ->where('agd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->distinct();
    
        if ($request->has('category')) {
            $attributesQuery->join('oc_product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->where('ptc.category_id', $request->category);
        }
    
        $results = $attributesQuery->orderBy('group_sort_order')->orderBy('attribute_sort_order')->get();
    
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
    
        return response()->json($groupedResults);
    }

    /**
     * Calculate dynamic price ranges
     */
    private function getPriceRanges()
    {
        // Calculate dynamic price ranges based on products
        $minPrice = DB::table('oc_product')
            ->where('status', 1)
            ->min('price');
            
        $maxPrice = DB::table('oc_product')
            ->where('status', 1)
            ->max('price');
        
        if ($minPrice === null || $maxPrice === null || $minPrice == $maxPrice) {
            return [
                ['label' => 'All Prices', 'min' => 0, 'max' => null],
            ];
        }
        
        // Create 5 price ranges
        $rangeSize = ($maxPrice - $minPrice) / 5;
        $ranges = [];
        
        for ($i = 0; $i < 5; $i++) {
            $start = $minPrice + ($i * $rangeSize);
            $end = $minPrice + (($i + 1) * $rangeSize);
            
            if ($i === 0) {
                $start = 0; // Start from 0 for the first range
            }
            
            $ranges[] = [
                'label' => ($i === 4) ? number_format($start, 2) . '+' : 
                          number_format($start, 2) . ' - ' . number_format($end, 2),
                'min' => $start,
                'max' => ($i === 4) ? null : $end,
            ];
        }
        
        return $ranges;
    }

    /**
     * Get category hierarchy
     */
    private function getCategories($parentId = null)
    {
        $categoriesQuery = DB::table('oc_category as c')
            ->select(
                'c.category_id as id', 
                'cd.name',
                'c.parent_id'
            )
            ->join('oc_category_description as cd', 'c.category_id', '=', 'cd.category_id')
            ->where('cd.language_id', 1)
            ->where('c.status', 1);

        if ($parentId !== null) {
            $categoriesQuery->where('c.parent_id', $parentId);
        } else {
            $categoriesQuery->where('c.parent_id', 0); // Top-level categories
        }

        $categories = $categoriesQuery->orderBy('cd.name')
            ->get();

        // For each category, get child categories if needed
        if ($parentId === null) {
            foreach ($categories as $category) {
                $category->children = $this->getCategories($category->id);
            }
        }

        return $categories;
    }

    /**
     * Get filter attributes
    */
    private function getAttributes(Request $request)
    {
        $attributesQuery = DB::table('oc_filter as f')
            ->select(
                'f.filter_id as id',
                'fd.name',
                'fgd.name as group_name',
                'fg.filter_group_id as group_id'
            )
            // Add sort_order columns to SELECT for proper ordering
            ->selectRaw('fg.sort_order as group_sort_order, f.sort_order as filter_sort_order')
            ->join('oc_filter_description as fd', function($join) {
                $join->on('f.filter_id', '=', 'fd.filter_id')
                     ->where('fd.language_id', '=', 1);
            })
            ->join('oc_filter_group as fg', 'f.filter_group_id', '=', 'fg.filter_group_id')
            ->join('oc_filter_group_description as fgd', function($join) {
                $join->on('fg.filter_group_id', '=', 'fgd.filter_group_id')
                     ->where('fgd.language_id', '=', 1);
            });

        // If category is provided in request, filter attributes for that category
        if ($request->has('category')) {
            $attributesQuery->join('oc_product_filter as pf', 'f.filter_id', '=', 'pf.filter_id')
                           ->join('oc_product_to_category as ptc', 'pf.product_id', '=', 'ptc.product_id')
                           ->where('ptc.category_id', $request->category)
                           ->distinct();
        }

        $results = $attributesQuery->orderBy('group_sort_order')
                                  ->orderBy('filter_sort_order')
                                  ->get();

        // Remove the sort_order columns from the final result
        return $results->map(function ($item) {
            unset($item->group_sort_order);
            unset($item->filter_sort_order);
            return $item;
        });
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