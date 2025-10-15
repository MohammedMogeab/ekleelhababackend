<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Get user notifications.
     *
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 15).
     * @queryParam type string Filter by notification type (order, product, general).
     * @queryParam read boolean Filter by read status.
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
            'type' => 'string|in:order,product,general',
            'read' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $type = $request->get('type');
        $read = $request->has('read') ? $request->boolean('read') : null;
        
        try {
            $query = DB::table('oc_notification')
                ->where('customer_id', $user->customer_id)
                ->orderBy('date_added', 'desc');
                
            if ($type) {
                $query->where('type', $type);
            }
            
            if ($read !== null) {
                $query->where('read', $read ? 1 : 0);
            }
            
            $total = $query->count();
            $notifications = $query
                ->offset(($page - 1) * $limit)
                ->limit($limit)
                ->get();
                
            $formattedNotifications = $notifications->map(function ($notification) {
                return $this->formatNotification($notification);
            });
            
            return response()->json([
                'data' => $formattedNotifications,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve notifications', [
                'user_id' => $user->customer_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve notifications',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Mark notification as read.
     *
     * @urlParam id required Notification ID.
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead($id, Request $request)
    {
        $user = $request->user();
        
        try {
            $notification = DB::table('oc_notification')
                ->where('notification_id', $id)
                ->where('customer_id', $user->customer_id)
                ->first();
                
            if (!$notification) {
                return response()->json([
                    'message' => 'Notification not found'
                ], 404);
            }
            
            if ($notification->read) {
                return response()->json([
                    'message' => 'Notification is already marked as read'
                ]);
            }
            
            DB::table('oc_notification')
                ->where('notification_id', $id)
                ->update([
                    'read' => 1,
                    'date_read' => now()
                ]);
                
            return response()->json([
                'message' => 'Notification marked as read',
                'data' => $this->formatNotification([
                    'notification_id' => $id,
                    'read' => 1,
                    'date_read' => now()
                ])
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark notification as read', [
                'user_id' => $user->customer_id,
                'notification_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to mark notification as read',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Delete notification.
     *
     * @urlParam id required Notification ID.
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id, Request $request)
    {
        $user = $request->user();
        
        try {
            $notification = DB::table('oc_notification')
                ->where('notification_id', $id)
                ->where('customer_id', $user->customer_id)
                ->first();
                
            if (!$notification) {
                return response()->json([
                    'message' => 'Notification not found'
                ], 404);
            }
            
            DB::table('oc_notification')
                ->where('notification_id', $id)
                ->delete();
                
            return response()->json([
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete notification', [
                'user_id' => $user->customer_id,
                'notification_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to delete notification',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Mark all notifications as read.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllRead(Request $request)
    {
        $user = $request->user();
        
        try {
            DB::table('oc_notification')
                ->where('customer_id', $user->customer_id)
                ->where('read', 0)
                ->update([
                    'read' => 1,
                    'date_read' => now()
                ]);
                
            return response()->json([
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read', [
                'user_id' => $user->customer_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to mark all notifications as read',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Format notification for API response.
     *
     * @param \stdClass|array $notification
     * @return array
     */
    private function formatNotification($notification)
    {
        // If it's an array (from update response), convert to object
        if (is_array($notification)) {
            $notification = (object)$notification;
        }
        
        return [
            'id' => $notification->notification_id,
            'title' => $this->getNotificationTitle($notification),
            'message' => $this->getNotificationMessage($notification),
            'type' => $notification->type,
            'data' => json_decode($notification->data, true) ?? [],
            'read' => (bool)$notification->read,
            'date_added' => $notification->date_added,
            'date_read' => $notification->date_read ?? null
        ];
    }
    
    /**
     * Get notification title based on type.
     *
     * @param \stdClass $notification
     * @return string
     */
    private function getNotificationTitle($notification)
    {
        switch ($notification->type) {
            case 'order':
                return 'Order Update';
            case 'product':
                return 'Product Update';
            case 'promotion':
                return 'Special Offer';
            default:
                return 'Notification';
        }
    }
    
    /**
     * Get notification message based on type and data.
     *
     * @param \stdClass $notification
     * @return string
     */
    private function getNotificationMessage($notification)
    {
        $data = json_decode($notification->data, true) ?? [];
        
        switch ($notification->type) {
            case 'order':
                $orderId = $data['order_id'] ?? 'an order';
                $status = $data['status'] ?? 'updated';
                
                return "Your order #{$orderId} has been {$status}.";
                
            case 'product':
                $productName = $data['product_name'] ?? 'a product';
                $action = $data['action'] ?? 'updated';
                
                return "The product {$productName} has been {$action}.";
                
            case 'promotion':
                return $data['message'] ?? 'Special offer available!';
                
            default:
                return $notification->message ?? 'You have a new notification.';
        }
    }
}