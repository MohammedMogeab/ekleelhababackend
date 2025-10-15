<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class AnalyticsController extends Controller
{
    /**
     * Get sales analytics over time.
     *
     * @queryParam range string Time range (today, week, month, year, custom).
     * @queryParam start_date string Start date for custom range (Y-m-d).
     * @queryParam end_date string End date for custom range (Y-m-d).
     * @queryParam group_by string Grouping (day, week, month).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sales(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'range' => 'nullable|string|in:today,week,month,year,custom',
            'start_date' => 'required_if:range,custom|date',
            'end_date' => 'required_if:range,custom|date|after_or_equal:start_date',
            'group_by' => 'nullable|string|in:day,week,month',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $range = $request->get('range', 'month');
        $groupBy = $request->get('group_by', 'day');
        
        $dateRange = $this->getDateRange($range, $request->start_date, $request->end_date);
        
        $cacheKey = 'analytics_sales_' . $range . '_' . $groupBy . '_' . $dateRange['start'] . '_' . $dateRange['end'];
        
        $salesData = Cache::remember($cacheKey, 300, function () use ($dateRange, $groupBy) {
            $query = DB::table('oc_order')
                ->selectRaw($this->getGroupBySelect($groupBy) . ' as date')
                ->selectRaw('COUNT(*) as orders_count')
                ->selectRaw('SUM(total) as total_revenue')
                ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
                ->where('order_status_id', '!=', 0) // Exclude cancelled orders
                ->groupBy($this->getGroupByField($groupBy))
                ->orderBy($this->getGroupByField($groupBy), 'asc');

            $results = $query->get();

            $formatted = $results->map(function ($item) {
                return [
                    'date' => $item->date,
                    'orders_count' => (int) $item->orders_count,
                    'total_revenue' => (float) $item->total_revenue,
                    'average_order_value' => $item->orders_count > 0 ? (float) ($item->total_revenue / $item->orders_count) : 0,
                ];
            });

            // Get summary
            $summary = DB::table('oc_order')
                ->selectRaw('COUNT(*) as total_orders')
                ->selectRaw('SUM(total) as total_revenue')
                ->selectRaw('AVG(total) as average_order_value')
                ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
                ->where('order_status_id', '!=', 0)
                ->first();

            return [
                'data' => $formatted->values(),
                'summary' => [
                    'total_orders' => (int) $summary->total_orders,
                    'total_revenue' => (float) $summary->total_revenue,
                    'average_order_value' => (float) $summary->average_order_value,
                ],
                'date_range' => [
                    'start' => $dateRange['start'],
                    'end' => $dateRange['end'],
                    'group_by' => $groupBy,
                ],
            ];
        });

        return response()->json($salesData);
    }

    /**
     * Get top selling products analytics.
     *
     * @queryParam limit int Number of products to return (default: 10).
     * @queryParam range string Time range (today, week, month, year, all).
     * @queryParam category_id int Filter by category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function products(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
            'range' => 'nullable|string|in:today,week,month,year,all',
            'category_id' => 'nullable|integer|exists:oc_category,category_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = $request->get('limit', 10);
        $range = $request->get('range', 'month');
        $categoryId = $request->get('category_id');

        $dateRange = $this->getDateRange($range);
        
        $cacheKey = 'analytics_products_' . $range . '_' . $categoryId . '_' . $limit;
        
        $productsData = Cache::remember($cacheKey, 300, function () use ($dateRange, $limit, $categoryId ,$range) {
            $query = DB::table('oc_order_product as op')
                ->join('oc_order as o', 'op.order_id', '=', 'o.order_id')
                ->join('oc_product as p', 'op.product_id', '=', 'p.product_id')
                ->join('oc_product_description as pd', function ($join) {
                    $join->on('p.product_id', '=', 'pd.product_id')
                         ->where('pd.language_id', 1);
                })
                ->select('p.product_id')
                ->select('pd.name as product_name')
                ->select('p.model')
                ->select('p.image')
                ->selectRaw('SUM(op.quantity) as total_sold')
                ->selectRaw('SUM(op.total) as total_revenue')
                ->whereBetween('o.date_added', [$dateRange['start'], $dateRange['end']])
                ->where('o.order_status_id', '!=', 0);

            if ($categoryId) {
                $query->join('oc_product_to_category as pc', 'p.product_id', '=', 'pc.product_id')
                      ->where('pc.category_id', $categoryId);
            }

            $results = $query->groupBy('p.product_id', 'pd.name', 'p.model', 'p.image')
                ->orderBy('total_sold', 'desc')
                ->limit($limit)
                ->get();

            $formatted = $results->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'model' => $item->model,
                    'image' => $item->image ? url('image/' . $item->image) : null,
                    'total_sold' => (int) $item->total_sold,
                    'total_revenue' => (float) $item->total_revenue,
                    'average_price' => $item->total_sold > 0 ? (float) ($item->total_revenue / $item->total_sold) : 0,
                ];
            });

            // Get category name if filtered
            $categoryName = null;
            if ($categoryId) {
                $category = DB::table('oc_category_description')
                    ->where('category_id', $categoryId)
                    ->where('language_id', 1)
                    ->first();
                $categoryName = $category ? $category->name : null;
            }

            return [
                'data' => $formatted->values(),
                'summary' => [
                    'total_products' => $formatted->count(),
                    'total_items_sold' => $formatted->sum('total_sold'),
                    'total_revenue' => $formatted->sum('total_revenue'),
                ],
                'filters' => [
                    'range' => $range,
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'limit' => $limit,
                ],
            ];
        });

        return response()->json($productsData);
    }

    /**
     * Get customer analytics.
     *
     * @queryParam limit int Number of customers to return (default: 10).
     * @queryParam range string Time range (today, week, month, year, all).
     * @queryParam sort string Sort by (orders, revenue, registration).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function customers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
            'range' => 'nullable|string|in:today,week,month,year,all',
            'sort' => 'nullable|string|in:orders,revenue,registration',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = $request->get('limit', 10);
        $range = $request->get('range', 'month');
        $sort = $request->get('sort', 'orders');

        $dateRange = $this->getDateRange($range);
        
        $cacheKey = 'analytics_customers_' . $range . '_' . $sort . '_' . $limit;
        
        $customersData = Cache::remember($cacheKey, 300, function () use ($dateRange, $limit, $sort ,$range) {
            $query = DB::table('oc_customer as c')
                ->select('c.customer_id')
                ->select('c.firstname')
                ->select('c.lastname')
                ->select('c.email')
                ->select('c.date_added as registration_date')
                ->selectRaw('COUNT(o.order_id) as total_orders')
                ->selectRaw('SUM(o.total) as total_spent')
                ->leftJoin('oc_order as o', function ($join) use ($dateRange) {
                    $join->on('c.customer_id', '=', 'o.customer_id')
                         ->whereBetween('o.date_added', [$dateRange['start'], $dateRange['end']])
                         ->where('o.order_status_id', '!=', 0);
                })
                ->groupBy('c.customer_id', 'c.firstname', 'c.lastname', 'c.email', 'c.date_added');

            // Apply sorting
            switch ($sort) {
                case 'revenue':
                    $query->orderBy('total_spent', 'desc');
                    break;
                case 'registration':
                    $query->orderBy('c.date_added', 'desc');
                    break;
                case 'orders':
                default:
                    $query->orderBy('total_orders', 'desc');
                    break;
            }

            $results = $query->limit($limit)->get();

            $formatted = $results->map(function ($item) {
                return [
                    'customer_id' => $item->customer_id,
                    'name' => trim($item->firstname . ' ' . $item->lastname),
                    'email' => $item->email,
                    'registration_date' => $item->registration_date,
                    'total_orders' => (int) $item->total_orders,
                    'total_spent' => (float) $item->total_spent,
                    'average_order_value' => $item->total_orders > 0 ? (float) ($item->total_spent / $item->total_orders) : 0,
                ];
            });

            // Get summary
            $summary = DB::table('oc_customer as c')
                ->selectRaw('COUNT(*) as total_customers')
                ->selectRaw('COUNT(DISTINCT o.customer_id) as active_customers')
                ->leftJoin('oc_order as o', function ($join) use ($dateRange) {
                    $join->on('c.customer_id', '=', 'o.customer_id')
                         ->whereBetween('o.date_added', [$dateRange['start'], $dateRange['end']])
                         ->where('o.order_status_id', '!=', 0);
                })
                ->first();

            return [
                'data' => $formatted->values(),
                'summary' => [
                    'total_customers' => (int) $summary->total_customers,
                    'active_customers' => (int) $summary->active_customers,
                    'inactive_customers' => (int) ($summary->total_customers - $summary->active_customers),
                ],
                'filters' => [
                    'range' => $range,
                    'sort' => $sort,
                    'limit' => $limit,
                ],
            ];
        });

        return response()->json($customersData);
    }

    /**
     * Get traffic and search analytics.
     *
     * @queryParam limit int Number of records to return (default: 10).
     * @queryParam range string Time range (today, week, month, year, all).
     * @queryParam type string Type of analytics (searches, traffic, conversions).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function traffic(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
            'range' => 'nullable|string|in:today,week,month,year,all',
            'type' => 'nullable|string|in:searches,traffic,conversions',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = $request->get('limit', 10);
        $range = $request->get('range', 'month');
        $type = $request->get('type', 'searches');

        $dateRange = $this->getDateRange($range);
        
        $cacheKey = 'analytics_traffic_' . $range . '_' . $type . '_' . $limit;
        
        $trafficData = Cache::remember($cacheKey, 300, function () use ($dateRange, $limit, $type) {
            switch ($type) {
                case 'traffic':
                    return $this->getTrafficAnalytics($dateRange, $limit);
                case 'conversions':
                    return $this->getConversionAnalytics($dateRange, $limit);
                case 'searches':
                default:
                    return $this->getSearchAnalytics($dateRange, $limit);
            }
        });

        return response()->json($trafficData);
    }

    /**
     * Get revenue analytics by category, region, etc.
     *
     * @queryParam limit int Number of records to return (default: 10).
     * @queryParam range string Time range (today, week, month, year, all).
     * @queryParam group_by string Group by (category, country, payment_method).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenue(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
            'range' => 'nullable|string|in:today,week,month,year,all',
            'group_by' => 'nullable|string|in:category,country,payment_method',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = $request->get('limit', 10);
        $range = $request->get('range', 'month');
        $groupBy = $request->get('group_by', 'category');

        $dateRange = $this->getDateRange($range);
        
        $cacheKey = 'analytics_revenue_' . $range . '_' . $groupBy . '_' . $limit;
        
        $revenueData = Cache::remember($cacheKey, 300, function () use ($dateRange, $limit, $groupBy) {
            switch ($groupBy) {
                case 'country':
                    return $this->getRevenueByCountry($dateRange, $limit);
                case 'payment_method':
                    return $this->getRevenueByPaymentMethod($dateRange, $limit);
                case 'category':
                default:
                    return $this->getRevenueByCategory($dateRange, $limit);
            }
        });

        return response()->json($revenueData);
    }

    /**
     * Helper: Get date range based on range parameter.
     *
     * @param  string  $range
     * @param  string|null  $startDate
     * @param  string|null  $endDate
     * @return array
     */
    protected function getDateRange($range, $startDate = null, $endDate = null)
    {
        $now = now();
        
        switch ($range) {
            case 'today':
                return [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay(),
                ];
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek(),
                ];
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth(),
                ];
            case 'year':
                return [
                    'start' => $now->copy()->startOfYear(),
                    'end' => $now->copy()->endOfYear(),
                ];
            case 'custom':
                return [
                    'start' => $startDate,
                    'end' => $endDate,
                ];
            case 'all':
            default:
                return [
                    'start' => '2000-01-01',
                    'end' => $now->copy()->endOfDay(),
                ];
        }
    }

    /**
     * Helper: Get GROUP BY select clause.
     *
     * @param  string  $groupBy
     * @return string
     */
    protected function getGroupBySelect($groupBy)
    {
        switch ($groupBy) {
            case 'week':
                return 'YEARWEEK(date_added, 1)';
            case 'month':
                return 'DATE_FORMAT(date_added, "%Y-%m")';
            case 'day':
            default:
                return 'DATE(date_added)';
        }
    }

    /**
     * Helper: Get GROUP BY field.
     *
     * @param  string  $groupBy
     * @return string
     */
    protected function getGroupByField($groupBy)
    {
        switch ($groupBy) {
            case 'week':
                return DB::raw('YEARWEEK(date_added, 1)');
            case 'month':
                return DB::raw('DATE_FORMAT(date_added, "%Y-%m")');
            case 'day':
            default:
                return DB::raw('DATE(date_added)');
        }
    }

    /**
     * Helper: Get search analytics.
     *
     * @param  array  $dateRange
     * @param  int  $limit
     * @return array
     */
    protected function getSearchAnalytics($dateRange, $limit)
    {
        $searches = DB::table('oc_customer_search')
            ->select('keyword')
            ->selectRaw('COUNT(*) as search_count')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->groupBy('keyword')
            ->orderBy('search_count', 'desc')
            ->limit($limit)
            ->get();

        $formatted = $searches->map(function ($search) {
            return [
                'keyword' => $search->keyword,
                'search_count' => (int) $search->search_count,
            ];
        });

        $totalSearches = DB::table('oc_customer_search')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->count();

        $uniqueKeywords = DB::table('oc_customer_search')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->distinct('keyword')
            ->count('keyword');

        return [
            'data' => $formatted->values(),
            'summary' => [
                'total_searches' => $totalSearches,
                'unique_keywords' => $uniqueKeywords,
                'average_searches_per_keyword' => $uniqueKeywords > 0 ? round($totalSearches / $uniqueKeywords, 2) : 0,
            ],
            'type' => 'searches',
        ];
    }

    /**
     * Helper: Get traffic analytics.
     *
     * @param  array  $dateRange
     * @param  int  $limit
     * @return array
     */
    protected function getTrafficAnalytics($dateRange, $limit)
    {
        // Get visitor count from oc_customer_online (if available)
        $visitors = DB::table('oc_customer_online')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->count();

        // Get page views (if you have tracking)
        $pageViews = DB::table('oc_customer_activity')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->count();

        // Get popular pages
        $popularPages = DB::table('oc_customer_activity')
            ->select('key')
            ->selectRaw('COUNT(*) as visit_count')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->groupBy('key')
            ->orderBy('visit_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($page) {
                return [
                    'page' => $page->key,
                    'visit_count' => (int) $page->visit_count,
                ];
            });

        return [
            'data' => $popularPages->values(),
            'summary' => [
                'total_visitors' => $visitors,
                'total_page_views' => $pageViews,
                'pages_per_visitor' => $visitors > 0 ? round($pageViews / $visitors, 2) : 0,
            ],
            'type' => 'traffic',
        ];
    }

    /**
     * Helper: Get conversion analytics.
     *
     * @param  array  $dateRange
     * @param  int  $limit
     * @return array
     */
    protected function getConversionAnalytics($dateRange, $limit)
    {
        $totalVisitors = DB::table('oc_customer_online')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->count();

        $totalOrders = DB::table('oc_order')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->where('order_status_id', '!=', 0)
            ->count();

        $conversionRate = $totalVisitors > 0 ? round(($totalOrders / $totalVisitors) * 100, 2) : 0;

        // Get conversion by source (if available)
        $conversionsBySource = DB::table('oc_order')
            ->select('order_from')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(total) as revenue')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->where('order_status_id', '!=', 0)
            ->groupBy('order_from')
            ->orderBy('order_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($source) {
                return [
                    'source' => $source->order_from,
                    'order_count' => (int) $source->order_count,
                    'revenue' => (float) $source->revenue,
                    'average_order_value' => $source->order_count > 0 ? (float) ($source->revenue / $source->order_count) : 0,
                ];
            });

        return [
            'data' => $conversionsBySource->values(),
            'summary' => [
                'total_visitors' => $totalVisitors,
                'total_orders' => $totalOrders,
                'conversion_rate' => $conversionRate,
                'average_order_value' => $totalOrders > 0 ? (float) (DB::table('oc_order')
                    ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
                    ->where('order_status_id', '!=', 0)
                    ->avg('total')) : 0,
            ],
            'type' => 'conversions',
        ];
    }

    /**
     * Helper: Get revenue by category.
     *
     * @param  array  $dateRange
     * @param  int  $limit
     * @return array
     */
    protected function getRevenueByCategory($dateRange, $limit)
    {
        $revenue = DB::table('oc_order_product as op')
            ->join('oc_order as o', 'op.order_id', '=', 'o.order_id')
            ->join('oc_product_to_category as pc', 'op.product_id', '=', 'pc.product_id')
            ->join('oc_category_description as cd', function ($join) {
                $join->on('pc.category_id', '=', 'cd.category_id')
                     ->where('cd.language_id', 1);
            })
            ->select('cd.category_id')
            ->select('cd.name as category_name')
            ->selectRaw('SUM(op.total) as revenue')
            ->selectRaw('COUNT(op.order_product_id) as items_sold')
            ->selectRaw('COUNT(DISTINCT o.order_id) as orders_count')
            ->whereBetween('o.date_added', [$dateRange['start'], $dateRange['end']])
            ->where('o.order_status_id', '!=', 0)
            ->groupBy('cd.category_id', 'cd.name')
            ->orderBy('revenue', 'desc')
            ->limit($limit)
            ->get();

        $formatted = $revenue->map(function ($item) {
            return [
                'category_id' => $item->category_id,
                'category_name' => $item->category_name,
                'revenue' => (float) $item->revenue,
                'items_sold' => (int) $item->items_sold,
                'orders_count' => (int) $item->orders_count,
                'average_order_value' => $item->orders_count > 0 ? (float) ($item->revenue / $item->orders_count) : 0,
            ];
        });

        $totalRevenue = DB::table('oc_order')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->where('order_status_id', '!=', 0)
            ->sum('total');

        return [
            'data' => $formatted->values(),
            'summary' => [
                'total_revenue' => (float) $totalRevenue,
                'categories_count' => $formatted->count(),
            ],
            'group_by' => 'category',
        ];
    }

    /**
     * Helper: Get revenue by country.
     *
     * @param  array  $dateRange
     * @param  int  $limit
     * @return array
     */
    protected function getRevenueByCountry($dateRange, $limit)
    {
        $revenue = DB::table('oc_order')
            ->join('oc_country as c', 'oc_order.payment_country_id', '=', 'c.country_id')
            ->select('c.country_id')
            ->select('c.name as country_name')
            ->selectRaw('SUM(total) as revenue')
            ->selectRaw('COUNT(*) as orders_count')
            ->whereBetween('oc_order.date_added', [$dateRange['start'], $dateRange['end']])
            ->where('oc_order.order_status_id', '!=', 0)
            ->groupBy('c.country_id', 'c.name')
            ->orderBy('revenue', 'desc')
            ->limit($limit)
            ->get();

        $formatted = $revenue->map(function ($item) {
            return [
                'country_id' => $item->country_id,
                'country_name' => $item->country_name,
                'revenue' => (float) $item->revenue,
                'orders_count' => (int) $item->orders_count,
                'average_order_value' => $item->orders_count > 0 ? (float) ($item->revenue / $item->orders_count) : 0,
            ];
        });

        $totalRevenue = DB::table('oc_order')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->where('order_status_id', '!=', 0)
            ->sum('total');

        return [
            'data' => $formatted->values(),
            'summary' => [
                'total_revenue' => (float) $totalRevenue,
                'countries_count' => $formatted->count(),
            ],
            'group_by' => 'country',
        ];
    }

    /**
     * Helper: Get revenue by payment method.
     *
     * @param  array  $dateRange
     * @param  int  $limit
     * @return array
     */
    protected function getRevenueByPaymentMethod($dateRange, $limit)
    {
        $revenue = DB::table('oc_order')
            ->select('payment_method')
            ->selectRaw('SUM(total) as revenue')
            ->selectRaw('COUNT(*) as orders_count')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->where('order_status_id', '!=', 0)
            ->groupBy('payment_method')
            ->orderBy('revenue', 'desc')
            ->limit($limit)
            ->get();

        $formatted = $revenue->map(function ($item) {
            return [
                'payment_method' => $item->payment_method,
                'revenue' => (float) $item->revenue,
                'orders_count' => (int) $item->orders_count,
                'average_order_value' => $item->orders_count > 0 ? (float) ($item->revenue / $item->orders_count) : 0,
            ];
        });

        $totalRevenue = DB::table('oc_order')
            ->whereBetween('date_added', [$dateRange['start'], $dateRange['end']])
            ->where('order_status_id', '!=', 0)
            ->sum('total');

        return [
            'data' => $formatted->values(),
            'summary' => [
                'total_revenue' => (float) $totalRevenue,
                'payment_methods_count' => $formatted->count(),
            ],
            'group_by' => 'payment_method',
        ];
    }
}