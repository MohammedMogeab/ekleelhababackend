<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    /**
     * List all coupons.
     *
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     * @queryParam status string Filter by status (active, inactive, all).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
            'status' => 'nullable|in:active,inactive,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = DB::table('oc_coupon');

        $status = $request->get('status', 'all');
        if ($status === 'active') {
            $query->where('status', 1);
        } elseif ($status === 'inactive') {
            $query->where('status', 0);
        }

        $limit = $request->get('limit', 20);
        $coupons = $query->orderBy('date_added', 'desc')->paginate($limit);

        $formatted = $coupons->getCollection()->map(function ($coupon) {
            $totalUses = DB::table('oc_coupon_history')->where('coupon_id', $coupon->coupon_id)->count();
            
            return [
                'id' => $coupon->coupon_id,
                'name' => $coupon->name,
                'code' => $coupon->code,
                'type' => $coupon->type === 'P' ? 'percentage' : 'fixed',
                'discount' => (float) $coupon->discount,
                'minimum_order' => (float) $coupon->total,
                'status' => $coupon->status ? 'active' : 'inactive',
                'valid_from' => $coupon->date_start,
                'valid_until' => $coupon->date_end,
                'uses_total' => $coupon->uses_total,
                'uses_remaining' => $coupon->uses_total > 0 ? max(0, $coupon->uses_total - $totalUses) : 'Unlimited',
                'uses_customer' => $coupon->uses_customer,
                'date_added' => $coupon->date_added,
                'date_modified' => $coupon->date_added, // OpenCart doesn't have date_modified for coupons
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    /**
     * Create a new coupon.
     *
     * @bodyParam name string required Coupon name.
     * @bodyParam code string required Coupon code.
     * @bodyParam type string required Type (P for percentage, F for fixed).
     * @bodyParam discount numeric required Discount amount or percentage.
     * @bodyParam total numeric Minimum order total.
     * @bodyParam date_start string required Start date (Y-m-d).
     * @bodyParam date_end string required End date (Y-m-d).
     * @bodyParam uses_total integer Maximum uses (0 for unlimited).
     * @bodyParam uses_customer integer Maximum uses per customer.
     * @bodyParam status boolean Coupon status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:20|unique:oc_coupon,code',
            'type' => 'required|in:P,F',
            'discount' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'date_start' => 'required|date',
            'date_end' => 'required|date|after_or_equal:date_start',
            'uses_total' => 'required|integer|min:0',
            'uses_customer' => 'required|integer|min:0',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $couponId = DB::table('oc_coupon')->insertGetId([
            'name' => $request->name,
            'code' => $request->code,
            'type' => $request->type,
            'discount' => $request->discount,
            'logged' => 0, // Can be updated to 1 if login required
            'shipping' => 0, // Can be updated to 1 if free shipping included
            'total' => $request->total,
            'date_start' => $request->date_start,
            'date_end' => $request->date_end,
            'uses_total' => $request->uses_total,
            'uses_customer' => $request->uses_customer,
            'status' => $request->status,
            'date_added' => now(),
            'customer_id' => 0, // Can be set to specific customer if needed
        ]);

        return response()->json([
            'message' => 'Coupon created successfully',
            'coupon' => [
                'id' => $couponId,
                'name' => $request->name,
                'code' => $request->code,
                'type' => $request->type === 'P' ? 'percentage' : 'fixed',
                'discount' => (float) $request->discount,
                'status' => $request->status ? 'active' : 'inactive',
            ],
        ], 201);
    }

    /**
     * Update coupon.
     *
     * @urlParam id required Coupon ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:128',
            'code' => 'sometimes|required|string|max:20|unique:oc_coupon,code,' . $id . ',coupon_id',
            'type' => 'sometimes|required|in:P,F',
            'discount' => 'sometimes|required|numeric|min:0',
            'total' => 'sometimes|required|numeric|min:0',
            'date_start' => 'sometimes|required|date',
            'date_end' => 'sometimes|required|date|after_or_equal:date_start',
            'uses_total' => 'sometimes|required|integer|min:0',
            'uses_customer' => 'sometimes|required|integer|min:0',
            'status' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $coupon = DB::table('oc_coupon')->where('coupon_id', $id)->first();
        if (!$coupon) {
            return response()->json([
                'message' => 'Coupon not found',
            ], 404);
        }

        DB::table('oc_coupon')
            ->where('coupon_id', $id)
            ->update([
                'name' => $request->name ?? $coupon->name,
                'code' => $request->code ?? $coupon->code,
                'type' => $request->type ?? $coupon->type,
                'discount' => $request->discount ?? $coupon->discount,
                'total' => $request->total ?? $coupon->total,
                'date_start' => $request->date_start ?? $coupon->date_start,
                'date_end' => $request->date_end ?? $coupon->date_end,
                'uses_total' => $request->uses_total ?? $coupon->uses_total,
                'uses_customer' => $request->uses_customer ?? $coupon->uses_customer,
                'status' => $request->status ?? $coupon->status,
            ]);

        return response()->json([
            'message' => 'Coupon updated successfully',
            'coupon' => [
                'id' => $id,
                'name' => $request->name ?? $coupon->name,
                'code' => $request->code ?? $coupon->code,
                'type' => ($request->type ?? $coupon->type) === 'P' ? 'percentage' : 'fixed',
                'discount' => (float) ($request->discount ?? $coupon->discount),
                'status' => ($request->status ?? $coupon->status) ? 'active' : 'inactive',
            ],
        ]);
    }

    /**
     * Delete coupon.
     *
     * @urlParam id required Coupon ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $coupon = DB::table('oc_coupon')->where('coupon_id', $id)->first();
        if (!$coupon) {
            return response()->json([
                'message' => 'Coupon not found',
            ], 404);
        }

        // Delete coupon history
        DB::table('oc_coupon_history')->where('coupon_id', $id)->delete();

        // Delete coupon
        DB::table('oc_coupon')->where('coupon_id', $id)->delete();

        return response()->json([
            'message' => 'Coupon deleted successfully',
        ]);
    }
}