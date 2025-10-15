<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReturnController extends Controller
{
    /**
     * Get user's return requests.
     *
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 10).
     * @queryParam status string Filter by status (pending, approved, rejected, completed).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50',
            'status' => 'string|in:pending,approved,rejected,completed'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $status = $request->get('status');
        
        try {
            $query = DB::table('oc_return as r')
                ->where('r.customer_id', $user->customer_id)
                ->join('oc_order as o', 'r.order_id', '=', 'o.order_id')
                ->join('oc_return_status as rs', 'r.return_status_id', '=', 'rs.return_status_id')
                ->select(
                    'r.return_id',
                    'r.order_id',
                    'r.product_id',
                    'r.name as product_name',
                    'r.model',
                    'r.quantity',
                    'r.opened',
                    'r.comment',
                    'r.date_ordered',
                    'r.date_added',
                    'rs.name as status',
                    'o.order_status_id'
                );
                
            if ($status) {
                $statusId = $this->getReturnStatusId($status);
                if ($statusId) {
                    $query->where('r.return_status_id', $statusId);
                }
            }
            
            $total = $query->count();
            $returns = $query
                ->orderBy('r.date_added', 'desc')
                ->offset(($page - 1) * $limit)
                ->limit($limit)
                ->get();
                
            $formattedReturns = $returns->map(function ($return) {
                return [
                    'id' => $return->return_id,
                    'order_id' => $return->order_id,
                    'product_id' => $return->product_id,
                    'product_name' => $return->product_name,
                    'model' => $return->model,
                    'quantity' => $return->quantity,
                    'opened' => (bool)$return->opened,
                    'comment' => $return->comment,
                    'date_ordered' => $return->date_ordered,
                    'date_added' => $return->date_added,
                    'status' => $return->status,
                    'order_status' => $this->getOrderStatus($return->order_status_id)
                ];
            });
            
            return response()->json([
                'data' => $formattedReturns,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve return requests', [
                'user_id' => $user->customer_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve return requests',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Get return request details.
     *
     * @urlParam id required Return ID.
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id, Request $request)
    {
        $user = $request->user();
        
        try {
            $return = DB::table('oc_return as r')
                ->where('r.return_id', $id)
                ->where('r.customer_id', $user->customer_id)
                ->join('oc_order as o', 'r.order_id', '=', 'o.order_id')
                ->join('oc_return_status as rs', 'r.return_status_id', '=', 'rs.return_status_id')
                ->join('oc_product as p', 'r.product_id', '=', 'p.product_id')
                ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
                ->select(
                    'r.return_id',
                    'r.order_id',
                    'r.product_id',
                    'r.name as product_name',
                    'r.model',
                    'r.quantity',
                    'r.opened',
                    'r.comment',
                    'r.date_ordered',
                    'r.date_added',
                    'rs.name as status',
                    'o.order_status_id',
                    'pd.description as product_description',
                    'p.image as product_image'
                )
                ->first();
                
            if (!$return) {
                return response()->json([
                    'message' => 'Return request not found'
                ], 404);
            }
            
            return response()->json([
                'data' => [
                    'id' => $return->return_id,
                    'order_id' => $return->order_id,
                    'product_id' => $return->product_id,
                    'product_name' => $return->product_name,
                    'product_description' => $return->product_description,
                    'product_image' => $return->product_image ? env("IMAGE_BASE_PATH") . $return->product_image : null,
                    'model' => $return->model,
                    'quantity' => $return->quantity,
                    'opened' => (bool)$return->opened,
                    'comment' => $return->comment,
                    'date_ordered' => $return->date_ordered,
                    'date_added' => $return->date_added,
                    'status' => $return->status,
                    'order_status' => $this->getOrderStatus($return->order_status_id)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve return request details', [
                'user_id' => $user->customer_id,
                'return_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve return request details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Helper: Get return status ID by name.
     *
     * @param string $status
     * @return int|null
     */
    private function getReturnStatusId($status)
    {
        static $statusMap = [
            'pending' => 1,
            'approved' => 2,
            'rejected' => 3,
            'completed' => 4
        ];
        
        return $statusMap[$status] ?? null;
    }
    
    /**
     * Helper: Get order status name.
     *
     * @param int $statusId
     * @return string
     */
    private function getOrderStatus($statusId)
    {
        static $statuses = [];
        
        if (empty($statuses)) {
            $statuses = DB::table('oc_order_status')
                ->pluck('name', 'order_status_id')
                ->all();
        }
        
        return $statuses[$statusId] ?? 'Unknown Status';
    }
}