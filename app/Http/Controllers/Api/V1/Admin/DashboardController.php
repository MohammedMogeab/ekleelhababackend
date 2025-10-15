<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Get admin dashboard summary.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $cacheKey = 'admin_dashboard_summary';
        
        $dashboard = Cache::remember($cacheKey, 300, function () {
            // Get today's stats
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();
            
            // Get this month's stats
            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();
            
            // Get total stats
            $totalStats = $this->getTotalStats();
            $todayStats = $this->getStatsForPeriod($todayStart, $todayEnd);
            $monthStats = $this->getStatsForPeriod($monthStart, $monthEnd);
            
            // Get recent orders
            $recentOrders = DB::table('oc_order')
                ->select('order_id', 'order_status_id', 'total', 'firstname', 'lastname', 'date_added')
                ->orderBy('date_added', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->order_id,
                        'customer' => trim($order->firstname . ' ' . $order->lastname),
                        'total' => (float) $order->total,
                        'status' => $this->getOrderStatus($order->order_status_id),
                        'status_id' => $order->order_status_id,
                        'date_added' => $order->date_added,
                    ];
                });
            
            // Get low stock products
            $lowStockProducts = DB::table('oc_product as p')
                ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
                ->where('p.quantity', '<=', 5)
                ->where('p.status', 1)
                ->where('pd.language_id', 1)
                ->select('p.product_id', 'pd.name', 'p.model', 'p.quantity', 'p.image')
                ->orderBy('p.quantity', 'asc')
                ->limit(5)
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->product_id,
                        'name' => $product->name,
                        'model' => $product->model,
                        'quantity' => (int) $product->quantity,
                        'image' => $product->image ? url('image/' . $product->image) : null,
                    ];
                });
            
            // Get recent customers
            $recentCustomers = DB::table('oc_customer')
                ->select('customer_id', 'firstname', 'lastname', 'email', 'telephone', 'date_added')
                ->orderBy('date_added', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->customer_id,
                        'name' => trim($customer->firstname . ' ' . $customer->lastname),
                        'email' => $customer->email,
                        'phone' => $customer->telephone,
                        'date_registered' => $customer->date_added,
                    ];
                });
            
            // Get system info
            $systemInfo = [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'database_size' => $this->getDatabaseSize(),
                'storage_usage' => $this->getStorageUsage(),
                'last_backup' => now()->subDays(2)->format('Y-m-d H:i:s'), // Implement actual backup tracking
            ];
            
            return [
                'summary' => [
                    'total_sales' => $totalStats['total_sales'],
                    'total_orders' => $totalStats['total_orders'],
                    'total_customers' => $totalStats['total_customers'],
                    'total_products' => $totalStats['total_products'],
                ],
                'today' => [
                    'sales' => $todayStats['total_sales'],
                    'orders' => $todayStats['total_orders'],
                    'customers' => $todayStats['new_customers'],
                ],
                'this_month' => [
                    'sales' => $monthStats['total_sales'],
                    'orders' => $monthStats['total_orders'],
                    'customers' => $monthStats['new_customers'],
                ],
                'charts' => [
                    'sales_last_7_days' => $this->getSalesLast7Days(),
                    'orders_by_status' => $this->getOrdersByStatus(),
                    'top_categories' => $this->getTopCategories(),
                ],
                'recent_orders' => $recentOrders,
                'low_stock_products' => $lowStockProducts,
                'recent_customers' => $recentCustomers,
                'system_info' => $systemInfo,
                'updated_at' => now(),
            ];
        });

        return response()->json($dashboard);
    }

    /**
     * Helper: Get total stats.
     *
     * @return array
     */
    protected function getTotalStats()
    {
        $totalSales = DB::table('oc_order')
            ->where('order_status_id', '!=', 0)
            ->sum('total');
            
        $totalOrders = DB::table('oc_order')
            ->where('order_status_id', '!=', 0)
            ->count();
            
        $totalCustomers = DB::table('oc_customer')->count();
        
        $totalProducts = DB::table('oc_product')
            ->where('status', 1)
            ->count();
            
        return [
            'total_sales' => (float) $totalSales,
            'total_orders' => (int) $totalOrders,
            'total_customers' => (int) $totalCustomers,
            'total_products' => (int) $totalProducts,
        ];
    }

    /**
     * Helper: Get stats for a specific period.
     *
     * @param  string  $startDate
     * @param  string  $endDate
     * @return array
     */
    protected function getStatsForPeriod($startDate, $endDate)
    {
        $totalSales = DB::table('oc_order')
            ->whereBetween('date_added', [$startDate, $endDate])
            ->where('order_status_id', '!=', 0)
            ->sum('total');
            
        $totalOrders = DB::table('oc_order')
            ->whereBetween('date_added', [$startDate, $endDate])
            ->where('order_status_id', '!=', 0)
            ->count();
            
        $newCustomers = DB::table('oc_customer')
            ->whereBetween('date_added', [$startDate, $endDate])
            ->count();
            
        return [
            'total_sales' => (float) $totalSales,
            'total_orders' => (int) $totalOrders,
            'new_customers' => (int) $newCustomers,
        ];
    }

    /**
     * Helper: Get sales for last 7 days.
     *
     * @return array
     */
    protected function getSalesLast7Days()
    {
        $salesData = [];
        $today = now();
        
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $dateStart = $date->copy()->startOfDay();
            $dateEnd = $date->copy()->endOfDay();
            
            $sales = DB::table('oc_order')
                ->whereBetween('date_added', [$dateStart, $dateEnd])
                ->where('order_status_id', '!=', 0)
                ->sum('total');
                
            $salesData[] = [
                'date' => $date->format('Y-m-d'),
                'sales' => (float) $sales,
            ];
        }
        
        return $salesData;
    }

    /**
     * Helper: Get orders by status.
     *
     * @return array
     */
    protected function getOrdersByStatus()
    {
        $ordersByStatus = DB::table('oc_order')
            ->select('order_status_id')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('order_status_id')
            ->get()
            ->map(function ($item) {
                return [
                    'status_id' => $item->order_status_id,
                    'status' => $this->getOrderStatus($item->order_status_id),
                    'count' => (int) $item->count,
                ];
            });
            
        return $ordersByStatus->values();
    }

    /**
     * Helper: Get top categories by sales.
     *
     * @return array
     */
    protected function getTopCategories()
    {
        $topCategories = DB::table('oc_order_product as op')
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
            ->where('o.order_status_id', '!=', 0)
            ->groupBy('cd.category_id', 'cd.name')
            ->orderBy('revenue', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->category_id,
                    'category_name' => $item->category_name,
                    'revenue' => (float) $item->revenue,
                    'items_sold' => (int) $item->items_sold,
                ];
            });
            
        return $topCategories->values();
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
     * Helper: Get database size.
     *
     * @return string
     */
    protected function getDatabaseSize()
    {
        try {
            $result = DB::select("SELECT table_schema 'database', 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) 'size' 
                FROM information_schema.TABLES 
                WHERE table_schema = ? 
                GROUP BY table_schema", [config('database.connections.mysql.database')]);
                
            return $result[0]->size . ' MB';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Helper: Get storage usage.
     *
     * @return string
     */
    protected function getStorageUsage()
    {
        try {
            $totalSpace = disk_total_space(base_path());
            $freeSpace = disk_free_space(base_path());
            $usedSpace = $totalSpace - $freeSpace;
            $percentage = ($usedSpace / $totalSpace) * 100;
            
            return round($usedSpace / 1024 / 1024 / 1024, 2) . ' GB of ' . 
                   round($totalSpace / 1024 / 1024 / 1024, 2) . ' GB (' . 
                   round($percentage, 2) . '%)';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
}