<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class LogController extends Controller
{
    /**
     * List system logs with filtering and pagination.
     *
     * @queryParam page int Page number.
     * @queryParam limit int Items per page (default: 20).
     * @queryParam level string Filter by log level (error, warning, info, all).
     * @queryParam search string Search in log content.
     * @queryParam start_date string Filter by start date (Y-m-d H:i:s).
     * @queryParam end_date string Filter by end date (Y-m-d H:i:s).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'level' => 'nullable|string|in:error,warning,info,all',
            'search' => 'nullable|string',
            'start_date' => 'nullable|date_format:Y-m-d H:i:s',
            'end_date' => 'nullable|date_format:Y-m-d H:i:s|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Get logs from Laravel log files (storage/logs)
        $logs = $this->getLogsFromFile($request);

        // Get database logs (customer activity, order history, etc.)
        $dbLogs = $this->getDatabaseLogs($request);

        // Merge and sort logs
        $allLogs = array_merge($logs, $dbLogs);
        
        // Sort by date descending
        usort($allLogs, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Paginate manually since we're combining different sources
        $limit = $request->get('limit', 20);
        $page = $request->get('page', 1);
        $offset = ($page - 1) * $limit;
        
        $paginatedLogs = array_slice($allLogs, $offset, $limit);
        $total = count($allLogs);

        return response()->json([
            'data' => $paginatedLogs,
            'meta' => [
                'current_page' => $page,
                'last_page' => ceil($total / $limit),
                'per_page' => $limit,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Helper: Get logs from Laravel log files.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function getLogsFromFile($request)
    {
        $logs = [];
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            return $logs;
        }

        $content = file_get_contents($logFile);
        $lines = explode(PHP_EOL, $content);
        
        $currentLog = [];
        $inStack = false;
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            // Check if line starts with date (new log entry)
            if (preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $line)) {
                // Process previous log if exists
                if (!empty($currentLog)) {
                    $logs[] = $currentLog;
                    $currentLog = [];
                    $inStack = false;
                }
                
                // Parse new log entry
                $parts = explode('] ', $line, 3);
                if (count($parts) >= 3) {
                    $dateTime = substr($parts[0], 1); // Remove [
                    $level = trim($parts[1]);
                    $message = $parts[2];
                    
                    $currentLog = [
                        'id' => md5($line),
                        'level' => strtolower($level),
                        'message' => $message,
                        'context' => [],
                        'created_at' => $dateTime,
                        'source' => 'laravel_log',
                    ];
                    
                    // Check if this is a stack trace line
                    if (strpos($message, 'Stack trace:') !== false) {
                        $inStack = true;
                    }
                }
            } elseif ($inStack && !empty($currentLog)) {
                // Add to stack trace
                $currentLog['message'] .= PHP_EOL . $line;
            } elseif (!empty($currentLog)) {
                // Add additional context
                $currentLog['message'] .= PHP_EOL . $line;
            }
        }
        
        // Add last log entry
        if (!empty($currentLog)) {
            $logs[] = $currentLog;
        }
        
        // Apply filters
        $filteredLogs = array_filter($logs, function ($log) use ($request) {
            // Filter by level
            if ($request->filled('level') && $request->level !== 'all') {
                if ($log['level'] !== $request->level) {
                    return false;
                }
            }
            
            // Filter by search term
            if ($request->filled('search')) {
                if (stripos($log['message'], $request->search) === false) {
                    return false;
                }
            }
            
            // Filter by date range
            if ($request->filled('start_date')) {
                if (strtotime($log['created_at']) < strtotime($request->start_date)) {
                    return false;
                }
            }
            
            if ($request->filled('end_date')) {
                if (strtotime($log['created_at']) > strtotime($request->end_date)) {
                    return false;
                }
            }
            
            return true;
        });
        
        return array_values($filteredLogs);
    }

    /**
     * Helper: Get logs from database tables.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function getDatabaseLogs($request)
    {
        $logs = [];
        
        // Get customer activity logs
        $customerActivities = DB::table('oc_customer_activity')
            ->select(
                'customer_activity_id as id',
                DB::raw('"info" as level'),
                'key as message',
                'data as context',
                'date_added as created_at'
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('key', 'like', '%' . $request->search . '%')
                      ->orWhere('data', 'like', '%' . $request->search . '%');
            })
            ->when($request->filled('start_date'), function ($query) use ($request) {
                $query->where('date_added', '>=', $request->start_date);
            })
            ->when($request->filled('end_date'), function ($query) use ($request) {
                $query->where('date_added', '<=', $request->end_date);
            })
            ->orderBy('date_added', 'desc')
            ->limit(1000)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'level' => $item->level,
                    'message' => $item->message . ' - Customer Activity',
                    'context' => json_decode($item->context, true) ?: [],
                    'created_at' => $item->created_at,
                    'source' => 'customer_activity',
                ];
            });
            
        $logs = array_merge($logs, $customerActivities->toArray());
        
        // Get order history logs
        $orderHistories = DB::table('oc_order_history as oh')
            ->join('oc_order_status as os', 'oh.order_status_id', '=', 'os.order_status_id')
            ->join('oc_order as o', 'oh.order_id', '=', 'o.order_id')
            ->select(
                'oh.order_history_id as id',
                DB::raw('CASE WHEN oh.order_status_id IN (10,11) THEN "error" ELSE "info" END as level'),
                DB::raw('CONCAT("Order #", o.order_id, " - ", os.name) as message'),
                'oh.comment as context',
                'oh.date_added as created_at'
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('oh.comment', 'like', '%' . $request->search . '%')
                      ->orWhere('o.order_id', 'like', '%' . $request->search . '%');
            })
            ->when($request->filled('start_date'), function ($query) use ($request) {
                $query->where('oh.date_added', '>=', $request->start_date);
            })
            ->when($request->filled('end_date'), function ($query) use ($request) {
                $query->where('oh.date_added', '<=', $request->end_date);
            })
            ->orderBy('oh.date_added', 'desc')
            ->limit(1000)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'level' => $item->level,
                    'message' => $item->message,
                    'context' => $item->context,
                    'created_at' => $item->created_at,
                    'source' => 'order_history',
                ];
            });
            
        $logs = array_merge($logs, $orderHistories->toArray());
        
        // Get admin user logs (if oc_user table has activity tracking)
        if (DB::getSchemaBuilder()->hasTable('oc_user')) {
            $userLogs = DB::table('oc_user')
                ->select(
                    'user_id as id',
                    DB::raw('"info" as level'),
                    DB::raw('CONCAT(firstname, " ", lastname, " - User Activity") as message'),
                    'ip as context',
                    'date_added as created_at'
                )
                ->when($request->filled('search'), function ($query) use ($request) {
                    $query->where('firstname', 'like', '%' . $request->search . '%')
                          ->orWhere('lastname', 'like', '%' . $request->search . '%')
                          ->orWhere('email', 'like', '%' . $request->search . '%');
                })
                ->when($request->filled('start_date'), function ($query) use ($request) {
                    $query->where('date_added', '>=', $request->start_date);
                })
                ->when($request->filled('end_date'), function ($query) use ($request) {
                    $query->where('date_added', '<=', $request->end_date);
                })
                ->orderBy('date_added', 'desc')
                ->limit(500)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'level' => $item->level,
                        'message' => $item->message,
                        'context' => $item->context,
                        'created_at' => $item->created_at,
                        'source' => 'admin_user',
                    ];
                });
                
            $logs = array_merge($logs, $userLogs->toArray());
        }
        
        // Get API logs (if you have API logging)
        if (DB::getSchemaBuilder()->hasTable('oc_api_session')) {
            $apiLogs = DB::table('oc_api_session')
                ->select(
                    'api_session_id as id',
                    DB::raw('"info" as level'),
                    DB::raw('CONCAT("API Session - ", session_id) as message'),
                    'ip as context',
                    'date_added as created_at'
                )
                ->when($request->filled('search'), function ($query) use ($request) {
                    $query->where('session_id', 'like', '%' . $request->search . '%')
                          ->orWhere('ip', 'like', '%' . $request->search . '%');
                })
                ->when($request->filled('start_date'), function ($query) use ($request) {
                    $query->where('date_added', '>=', $request->start_date);
                })
                ->when($request->filled('end_date'), function ($query) use ($request) {
                    $query->where('date_added', '<=', $request->end_date);
                })
                ->orderBy('date_added', 'desc')
                ->limit(500)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'level' => $item->level,
                        'message' => $item->message,
                        'context' => $item->context,
                        'created_at' => $item->created_at,
                        'source' => 'api_session',
                    ];
                });
                
            $logs = array_merge($logs, $apiLogs->toArray());
        }
        
        // Get webhook logs (Tabby, Tamara, etc.)
        if (DB::getSchemaBuilder()->hasTable('oc_tabby_transaction')) {
            $tabbyLogs = DB::table('oc_tabby_transaction')
                ->select(
                    'id',
                    DB::raw('CASE WHEN status = "failed" THEN "error" WHEN status = "success" THEN "info" ELSE "warning" END as level'),
                    DB::raw('CONCAT("Tabby Payment - ", status) as message'),
                    'body as context',
                    'create_date as created_at'
                )
                ->when($request->filled('search'), function ($query) use ($request) {
                    $query->where('status', 'like', '%' . $request->search . '%')
                          ->orWhere('transaction_id', 'like', '%' . $request->search . '%');
                })
                ->when($request->filled('start_date'), function ($query) use ($request) {
                    $query->where('create_date', '>=', $request->start_date);
                })
                ->when($request->filled('end_date'), function ($query) use ($request) {
                    $query->where('create_date', '<=', $request->end_date);
                })
                ->orderBy('create_date', 'desc')
                ->limit(500)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'level' => $item->level,
                        'message' => $item->message,
                        'context' => json_decode($item->context, true) ?: [],
                        'created_at' => $item->created_at,
                        'source' => 'tabby_webhook',
                    ];
                });
                
            $logs = array_merge($logs, $tabbyLogs->toArray());
        }
        
        if (DB::getSchemaBuilder()->hasTable('oc_tamara_orders')) {
            $tamaraLogs = DB::table('oc_tamara_orders')
                ->select(
                    'tamara_id as id',
                    DB::raw('CASE WHEN is_authorised = 0 THEN "warning" ELSE "info" END as level'),
                    DB::raw('CONCAT("Tamara Order - ", tamara_order_id) as message'),
                    'redirect_url as context',
                    'created_at'
                )
                ->when($request->filled('search'), function ($query) use ($request) {
                    $query->where('tamara_order_id', 'like', '%' . $request->search . '%')
                          ->orWhere('reference_id', 'like', '%' . $request->search . '%');
                })
                ->when($request->filled('start_date'), function ($query) use ($request) {
                    $query->where('created_at', '>=', $request->start_date);
                })
                ->when($request->filled('end_date'), function ($query) use ($request) {
                    $query->where('created_at', '<=', $request->end_date);
                })
                ->orderBy('created_at', 'desc')
                ->limit(500)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'level' => $item->level,
                        'message' => $item->message,
                        'context' => $item->context,
                        'created_at' => $item->created_at,
                        'source' => 'tamara_webhook',
                    ];
                });
                
            $logs = array_merge($logs, $tamaraLogs->toArray());
        }
        
        // Apply level filter
        if ($request->filled('level') && $request->level !== 'all') {
            $logs = array_filter($logs, function ($log) use ($request) {
                return $log['level'] === $request->level;
            });
        }
        
        return array_values($logs);
    }
}