<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CouponController extends Controller
{
    /**
     * Validate coupon code.
     *
     * @queryParam code required Coupon code.
     * @queryParam subtotal required Subtotal amount to apply coupon to.
     * @queryParam customer_id integer Customer ID (optional for logged in users).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|exists:oc_coupon,code',
            'subtotal' => 'required|numeric|min:0.01',
            'customer_id' => 'integer|exists:oc_customer,customer_id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $code = $request->code;
        $subtotal = $request->subtotal;
        $customerId = $request->customer_id;
        
        try {
            $coupon = DB::table('oc_coupon')
                ->where('code', $code)
                ->where('status', 1)
                ->first();
                
            if (!$coupon) {
                return response()->json([
                    'message' => 'Invalid coupon code'
                ], 404);
            }
            
            // Check if coupon is still valid
            if ($coupon->date_start > now() || ($coupon->date_end && $coupon->date_end < now())) {
                return response()->json([
                    'message' => 'Coupon is not valid at this time'
                ], 400);
            }
            
            // Check usage limit
            if ($coupon->uses_total > 0) {
                $totalUses = DB::table('oc_coupon_history')->where('coupon_id', $coupon->coupon_id)->count();
                if ($totalUses >= $coupon->uses_total) {
                    return response()->json([
                        'message' => 'Coupon usage limit reached'
                    ], 400);
                }
            }
            
            // Check customer usage limit
            if ($customerId && $coupon->uses_customer > 0) {
                $customerUses = DB::table('oc_coupon_history')
                    ->where('coupon_id', $coupon->coupon_id)
                    ->where('customer_id', $customerId)
                    ->count();
                    
                if ($customerUses >= $coupon->uses_customer) {
                    return response()->json([
                        'message' => 'You have reached the usage limit for this coupon'
                    ], 400);
                }
            }
            
            // Check minimum subtotal
            if ($subtotal < $coupon->minimum) {
                return response()->json([
                    'message' => 'Subtotal does not meet minimum requirement for this coupon'
                ], 400);
            }
            
            // Calculate discount
            $discount = $this->calculateCouponDiscount($coupon, $subtotal);
            
            return response()->json([
                'data' => [
                    'id' => $coupon->coupon_id,
                    'code' => $coupon->code,
                    'type' => $coupon->type,
                    'discount' => $discount,
                    'total' => max(0, $subtotal - $discount),
                    'description' => $coupon->description
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Coupon validation failed', [
                'code' => $code,
                'subtotal' => $subtotal,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Coupon validation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Get active promotions.
     *
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 10).
     * @queryParam category int Filter by category ID.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function promotions(Request $request)
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
            $query = DB::table('oc_coupon as c')
                ->where('c.status', 1)
                ->where('c.date_start', '<=', now())
                ->where(function ($query) {
                    $query->whereNull('c.date_end')
                        ->orWhere('c.date_end', '>=', now());
                })
                ->select(
                    'c.coupon_id',
                    'c.name',
                    'c.code',
                    'c.type',
                    'c.discount',
                    'c.shipping',
                    'c.total',
                    'c.description',
                    'c.date_start',
                    'c.date_end'
                );
                
            if ($categoryId) {
                $query->join('oc_coupon_product as cp', 'c.coupon_id', '=', 'cp.coupon_id')
                    ->join('oc_product_to_category as ptc', 'cp.product_id', '=', 'ptc.product_id')
                    ->where('ptc.category_id', $categoryId)
                    ->distinct();
            }
            
            $total = $query->count();
            $coupons = $query
                ->orderBy('c.date_added', 'desc')
                ->offset(($page - 1) * $limit)
                ->limit($limit)
                ->get();
                
            $formattedCoupons = $coupons->map(function ($coupon) {
                return [
                    'id' => $coupon->coupon_id,
                    'name' => $coupon->name,
                    'code' => $coupon->code,
                    'type' => $coupon->type,
                    'discount' => (float)$coupon->discount,
                    'shipping' => (bool)$coupon->shipping,
                    'total' => (float)$coupon->total,
                    'description' => $coupon->description,
                    'date_start' => $coupon->date_start,
                    'date_end' => $coupon->date_end,
                    'formatted_discount' => $this->formatDiscount($coupon)
                ];
            });
            
            return response()->json([
                'data' => $formattedCoupons,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve promotions', [
                'category_id' => $categoryId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve promotions',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Calculate coupon discount.
     *
     * @param \stdClass $coupon
     * @param float $subtotal
     * @return float
     */
    private function calculateCouponDiscount($coupon, $subtotal)
    {
        if ($coupon->type === 'P') { // Percentage
            $discount = ($subtotal * $coupon->discount) / 100;
        } else { // Fixed amount
            $discount = $coupon->discount;
        }
        
        // Apply maximum discount if set
        if ($coupon->maximum > 0 && $discount > $coupon->maximum) {
            $discount = $coupon->maximum;
        }
        
        // Don't discount more than the subtotal
        return min($discount, $subtotal);
    }
    
    /**
     * Format discount for display.
     *
     * @param \stdClass $coupon
     * @return string
     */
    private function formatDiscount($coupon)
    {
        if ($coupon->type === 'P') {
            return $coupon->discount . '% off';
        } else {
            return '$' . number_format($coupon->discount, 2) . ' off';
        }
    }
}