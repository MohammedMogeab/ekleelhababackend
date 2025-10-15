<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * List all users with filters.
     *
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     * @queryParam status string Filter by status (active, inactive, all).
     * @queryParam search string Search by name or email.
     * @queryParam group_id int Filter by customer group.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|in:active,inactive,all',
            'search' => 'nullable|string',
            'group_id' => 'nullable|integer|exists:oc_customer_group,customer_group_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = DB::table('oc_customer as c')
            ->select(
                'c.customer_id',
                'c.firstname',
                'c.lastname',
                'c.email',
                'c.telephone',
                'c.status',
                'c.date_added',
                'c.customer_group_id',
                'cg.name as group_name'
            )
            ->leftJoin('oc_customer_group_description as cg', function ($join) {
                $join->on('c.customer_group_id', '=', 'cg.customer_group_id')
                     ->where('cg.language_id', 1);
            });

        $status = $request->get('status', 'all');
        if ($status === 'active') {
            $query->where('c.status', 1);
        } elseif ($status === 'inactive') {
            $query->where('c.status', 0);
        }

        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('c.firstname', 'like', $searchTerm)
                  ->orWhere('c.lastname', 'like', $searchTerm)
                  ->orWhere('c.email', 'like', $searchTerm);
            });
        }

        if ($request->filled('group_id')) {
            $query->where('c.customer_group_id', $request->group_id);
        }

        $limit = $request->get('limit', 20);
        $users = $query->orderBy('c.customer_id', 'desc')->paginate($limit);

        $formatted = $users->getCollection()->map(function ($user) {
            return [
                'id' => $user->customer_id,
                'name' => trim($user->firstname . ' ' . $user->lastname),
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'phone' => $user->telephone,
                'status' => $user->status ? 'active' : 'inactive',
                'customer_group' => [
                    'id' => $user->customer_group_id,
                    'name' => $user->group_name ?? 'Unknown',
                ],
                'date_registered' => $user->date_added,
                'last_login' => $this->getLastLogin($user->customer_id),
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Update user role/group.
     *
     * @urlParam id required User ID.
     * @bodyParam customer_group_id integer required Customer group ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateRole(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'customer_group_id' => 'required|integer|exists:oc_customer_group,customer_group_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = DB::table('oc_customer')->where('customer_id', $id)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        DB::table('oc_customer')
            ->where('customer_id', $id)
            ->update([
                'customer_group_id' => $request->customer_group_id,
                'date_modified' => now(),
            ]);

        return response()->json([
            'message' => 'User role updated successfully',
            'user' => [
                'id' => $id,
                'customer_group_id' => $request->customer_group_id,
            ],
        ]);
    }

    /**
     * Delete user.
     *
     * @urlParam id required User ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = DB::table('oc_customer')->where('customer_id', $id)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        // Check if user has orders
        $orderCount = DB::table('oc_order')->where('customer_id', $id)->count();
        if ($orderCount > 0) {
            return response()->json([
                'message' => 'Cannot delete user with orders. Archive instead.',
            ], 400);
        }

        // Delete related records
        DB::table('oc_customer_wishlist')->where('customer_id', $id)->delete();
        DB::table('oc_address')->where('customer_id', $id)->delete();
        DB::table('oc_customer_activity')->where('customer_id', $id)->delete();
        DB::table('oc_customer_ip')->where('customer_id', $id)->delete();
        DB::table('oc_customer_login')->where('email', $user->email)->delete();
        DB::table('oc_fcm_details')->where('email_id', 'like', '%' . $user->email . '%')->delete();

        // Delete user
        DB::table('oc_customer')->where('customer_id', $id)->delete();

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Helper: Get last login date for user.
     *
     * @param  int  $customerId
     * @return string|null
     */
    protected function getLastLogin($customerId)
    {
        $lastLogin = DB::table('oc_customer_login')
            ->where('email', function ($query) use ($customerId) {
                $query->select('email')->from('oc_customer')->where('customer_id', $customerId);
            })
            ->orderBy('date_modified', 'desc')
            ->value('date_modified');

        return $lastLogin;
    }
}