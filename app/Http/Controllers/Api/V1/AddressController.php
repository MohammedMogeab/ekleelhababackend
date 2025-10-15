<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class AddressController extends Controller
{
    /**
     * List all user addresses.
     *
     * @queryParam include string Comma-separated list of relations to include (country, zone).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        try {
            $include = $this->getIncludeRelations($request);
            
            $addresses = DB::table('oc_address as a')
                ->where('a.customer_id', $user->customer_id)
                ->select(
                    'a.address_id',
                    'a.firstname',
                    'a.lastname',
                    'a.company',
                    'a.address_1',
                    'a.address_2',
                    'a.city',
                    'a.postcode',
                    'a.country_id',
                    'a.zone_id',
                    'a.custom_field',
                    'a.basc_address as default' // Rename the column to 'default' for the API response
                )
                ->orderBy('a.basc_address', 'desc') // Use the correct column name for ordering
                ->get();
                
            $formattedAddresses = $addresses->map(function ($address) use ($include) {
                $addressData = [
                    'id' => $address->address_id,
                    'firstname' => $address->firstname,
                    'lastname' => $address->lastname,
                    'company' => $address->company,
                    'address_1' => $address->address_1,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'postcode' => $address->postcode,
                    'default' => (bool)$address->default // This now works because we aliased it
                ];
                
                // Include country if requested
                if (in_array('country', $include)) {
                    $country = DB::table('oc_country')
                        ->where('country_id', $address->country_id)
                        ->first();
                        
                    $addressData['country'] = $country ? [
                        'id' => $country->country_id,
                        'name' => $country->name,
                        'iso_code_2' => $country->iso_code_2,
                        'iso_code_3' => $country->iso_code_3
                    ] : null;
                }
                
                // Include zone if requested
                if (in_array('zone', $include)) {
                    $zone = DB::table('oc_zone')
                        ->where('zone_id', $address->zone_id)
                        ->first();
                        
                    $addressData['zone'] = $zone ? [
                        'id' => $zone->zone_id,
                        'name' => $zone->name,
                        'code' => $zone->code
                    ] : null;
                }
                
                return $addressData;
            });
            
            return response()->json([
                'data' => $formattedAddresses
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve addresses', [
                'user_id' => $user->customer_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve addresses',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Get address details.
     *
     * @urlParam id required Address ID.
     * @queryParam include string Comma-separated list of relations to include (country, zone).
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id, Request $request)
    {
        $user = $request->user();
        
        try {
            $include = $this->getIncludeRelations($request);
            
            $address = DB::table('oc_address as a')
                ->where('a.address_id', $id)
                ->where('a.customer_id', $user->customer_id)
                ->first();
                
            if (!$address) {
                return response()->json([
                    'message' => 'Address not found'
                ], 404);
            }
            
            $addressData = [
                'id' => $address->address_id,
                'firstname' => $address->firstname,
                'lastname' => $address->lastname,
                'company' => $address->company,
                'address_1' => $address->address_1,
                'address_2' => $address->address_2,
                'city' => $address->city,
                'postcode' => $address->postcode,
                'default' => (bool)$address->default
            ];
            
            // Include country if requested
            if (in_array('country', $include)) {
                $country = DB::table('oc_country')
                    ->where('country_id', $address->country_id)
                    ->first();
                    
                $addressData['country'] = $country ? [
                    'id' => $country->country_id,
                    'name' => $country->name,
                    'iso_code_2' => $country->iso_code_2,
                    'iso_code_3' => $country->iso_code_3
                ] : null;
            }
            
            // Include zone if requested
            if (in_array('zone', $include)) {
                $zone = DB::table('oc_zone')
                    ->where('zone_id', $address->zone_id)
                    ->first();
                    
                $addressData['zone'] = $zone ? [
                    'id' => $zone->zone_id,
                    'name' => $zone->name,
                    'code' => $zone->code
                ] : null;
            }
            
            return response()->json([
                'data' => $addressData
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve address', [
                'user_id' => $user->customer_id,
                'address_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve address',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Create a new address.
     *
     * @bodyParam firstname string required First name.
     * @bodyParam lastname string required Last name.
     * @bodyParam company string Company name.
     * @bodyParam address_1 string required Address line 1.
     * @bodyParam address_2 string Address line 2.
     * @bodyParam city string required City.
     * @bodyParam postcode string required Postcode.
     * @bodyParam country_id integer required Country ID.
     * @bodyParam zone_id integer required Zone ID.
     * @bodyParam default boolean Set as default address (default: false).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:32',
            'lastname' => 'required|string|max:32',
            'company' => 'nullable|string|max:40',
            'address_1' => 'required|string|max:128',
            'address_2' => 'nullable|string|max:128',  // Changed from max=128 to max:128
            'city' => 'required|string|max:128',      // Changed from max=128 to max:128
            'postcode' => 'required|string|max:10',   // Changed from max=10 to max:10
            'country_id' => 'required|integer|exists:oc_country,country_id',
            'zone_id' => [
                'required',
                'integer',
                Rule::exists('oc_zone', 'zone_id')->where(function ($query) use ($request) {
                    $query->where('country_id', $request->country_id);
                })
            ],
            'default' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            DB::transaction(function () use ($request, $user) {
                $addressData = [
                    'customer_id' => $user->customer_id,
                    'firstname' => $request->firstname,
                    'lastname' => $request->lastname,
                    'company' => $request->company,
                    'address_1' => $request->address_1,
                    'address_2' => $request->address_2,
                    'city' => $request->city,
                    'postcode' => $request->postcode,
                    'country_id' => $request->country_id,
                    'zone_id' => $request->zone_id,
                    'custom_field' => json_encode([]), // Default empty custom fields
                    'basc_address' => $request->boolean('default', false) ? 1 : 0  // Changed from 'default' to 'basc_address'
                ];
                
                $addressId = DB::table('oc_address')->insertGetId($addressData);
                
                // If this is the default address, unset other defaults
                if ($request->boolean('default', false)) {
                    DB::table('oc_address')
                        ->where('customer_id', $user->customer_id)
                        ->where('address_id', '!=', $addressId)
                        ->update(['basc_address' => 0]);  // Changed from 'default' to 'basc_address'
                }
            });
            
            return response()->json([
                'message' => 'Address created successfully',
                'data' => $this->formatAddress($request->all())
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create address', [
                'user_id' => $user->customer_id,
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'message' => 'Failed to create address',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Update an existing address.
     *
     * @urlParam id required Address ID.
     * @bodyParam firstname string First name.
     * @bodyParam lastname string Last name.
     * @bodyParam company string Company name.
     * @bodyParam address_1 string Address line 1.
     * @bodyParam address_2 string Address line 2.
     * @bodyParam city string City.
     * @bodyParam postcode string Postcode.
     * @bodyParam country_id integer Country ID.
     * @bodyParam zone_id integer Zone ID.
     * @bodyParam default boolean Set as default address.
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, Request $request)
    {
        $user = $request->user();
        
        // Validate address ownership
        $address = DB::table('oc_address')
            ->where('address_id', $id)
            ->where('customer_id', $user->customer_id)
            ->first();
            
        if (!$address) {
            return response()->json([
                'message' => 'Address not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'firstname' => 'string|max:32',
            'lastname' => 'string|max:32',
            'company' => 'nullable|string|max:40',
            'address_1' => 'string|max:128',
            'address_2' => 'nullable|string|max:128',  // Changed from max=128 to max:128
            'city' => 'string|max:128',              // Changed from max=128 to max:128
            'postcode' => 'string|max:10',           // Changed from max=10 to max:10
            'country_id' => 'integer|exists:oc_country,country_id',
            'zone_id' => [
                'integer',
                Rule::exists('oc_zone', 'zone_id')->where(function ($query) use ($request, $address) {
                    $query->where('country_id', $request->country_id ?? $address->country_id);
                })
            ],
            'default' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            DB::transaction(function () use ($id, $request, $user) {
                $updateData = [];
                
                if ($request->has('firstname')) {
                    $updateData['firstname'] = $request->firstname;
                }
                
                if ($request->has('lastname')) {
                    $updateData['lastname'] = $request->lastname;
                }
                
                if ($request->has('company')) {
                    $updateData['company'] = $request->company;
                }
                
                if ($request->has('address_1')) {
                    $updateData['address_1'] = $request->address_1;
                }
                
                if ($request->has('address_2')) {
                    $updateData['address_2'] = $request->address_2;
                }
                
                if ($request->has('city')) {
                    $updateData['city'] = $request->city;
                }
                
                if ($request->has('postcode')) {
                    $updateData['postcode'] = $request->postcode;
                }
                
                if ($request->has('country_id')) {
                    $updateData['country_id'] = $request->country_id;
                }
                
                if ($request->has('zone_id')) {
                    $updateData['zone_id'] = $request->zone_id;
                }
                
                if ($request->has('default')) {
                    $isDefault = $request->boolean('default');
                    $updateData['basc_address'] = $isDefault ? 1 : 0;  // Changed from 'default' to 'basc_address'
                    
                    // If setting as default, unset other defaults
                    if ($isDefault) {
                        DB::table('oc_address')
                            ->where('customer_id', $user->customer_id)
                            ->where('address_id', '!=', $id)
                            ->update(['basc_address' => 0]);  // Changed from 'default' to 'basc_address'
                    }
                }
                
                DB::table('oc_address')
                    ->where('address_id', $id)
                    ->update($updateData);
            });
            
            // Get updated address
            $updatedAddress = DB::table('oc_address')
                ->where('address_id', $id)
                ->where('customer_id', $user->customer_id)
                ->first();
                
            return response()->json([
                'message' => 'Address updated successfully',
                'data' => $this->formatAddress((array)$updatedAddress)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update address', [
                'user_id' => $user->customer_id,
                'address_id' => $id,
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'message' => 'Failed to update address',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Delete an address.
     *
     * @urlParam id required Address ID.
     *
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id, Request $request)
    {
        $user = $request->user();
        
        // Validate address ownership
        $address = DB::table('oc_address')
            ->where('address_id', $id)
            ->where('customer_id', $user->customer_id)
            ->first();
            
        if (!$address) {
            return response()->json([
                'message' => 'Address not found'
            ], 404);
        }
        
        try {
            DB::table('oc_address')
                ->where('address_id', $id)
                ->delete();
                
            return response()->json([
                'message' => 'Address deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete address', [
                'user_id' => $user->customer_id,
                'address_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to delete address',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Helper: Format address for API response.
     *
     * @param array $address
     * @return array
     */
    private function formatAddress(array $address)
    {
        return [
            'id' => $address['address_id'] ?? null,
            'firstname' => $address['firstname'] ?? '',
            'lastname' => $address['lastname'] ?? '',
            'company' => $address['company'] ?? '',
            'address_1' => $address['address_1'] ?? '',
            'address_2' => $address['address_2'] ?? '',
            'city' => $address['city'] ?? '',
            'postcode' => $address['postcode'] ?? '',
            'default' => (bool)($address['default'] ?? false)
        ];
    }
    
    /**
     * Helper: Get include relations from request.
     *
     * @param Request $request
     * @return array
     */
    private function getIncludeRelations(Request $request)
    {
        $validRelations = ['country', 'zone'];
        $includes = explode(',', $request->get('include', ''));
        
        return array_values(array_intersect($includes, $validRelations));
    }
}