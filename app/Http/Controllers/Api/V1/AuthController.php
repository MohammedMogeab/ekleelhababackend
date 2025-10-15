<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{

    
    /**
     * Register a new user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'firstname' => ['required', 'string', 'max:32'],
            'lastname' => ['required', 'string', 'max:32'],
            'email' => ['required', 'string', 'email', 'max:96', 'unique:oc_customer'],
            'telephone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $passwordWithSalt = [
            'password' => $request->password,
            'salt' => substr(md5(uniqid(rand(), true)), 0, 9)
        ];
        $user = Customer::create([
            'customer_group_id' => 1, // default group
            'store_id' => 0, // adjust if multi-store
            'language_id' => 1, // if needed
            'firstname' => $request->firstname,
            'lastname' => $request->lastname,
            'email' => $request->email,
            'telephone' => $request->telephone,
            'fax' => '',
            'password' => $passwordWithSalt,
            'salt' => $passwordWithSalt['salt'],
            'cart' => '',
            'wishlist' => '',
            'newsletter' => 0,
            'address_id' => 0,
            'custom_field' => '[]',
            'ip' => $request->ip(),
            'status' => 1,
            'safe' => 0,
            'token' => '',
            'code' => '',
            'verify_code' => '',
            'status_code' => 0,
            'delete_status' => 0,
            'from_come' => 'api', // or something else
            'is_marketer' => 0,
            'date_added' => now(),
        ]);


       

        // Create token with 'user' scope
        $user->token = $user->createToken('auth-token', ['user'])->plainTextToken;
        $user->save();
        //return response()->json(["user"=>$user]);
        return response()->json([
            'message' => 'User registered successfully',
            'access_token' => $user->token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->customer_id,
                'name' => $user->full_name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ],
        ], 201);
    }

    /**
     * Log in existing user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {   
        //return response()->json(['message' => 'Login disabled for now'], 403);
        //$array_request = $request->all();
        //return response()->json($request->all());
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Customer::active()->where('email', $request->email)->first();
        // $is = $user->validatePassword($request->password);
        // return response()->json([ "is" => $is , "hash" => $user->password , "req" => sha1($user->salt . sha1($user->salt . $request->password)) , "salt" => $user->salt ]);


        if (!$user || !$user->validatePassword($request->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Assign scopes: 'user' for all, 'admin' if admin
        $scopes = ['user'];
        if ($user->is_admin) {
            $scopes[] = 'admin';
        }
        
        $user->token =$user->createToken('auth-token', $scopes)->plainTextToken;
      
        $user->save();
        


        return response()->json([
            'message' => 'Logged in successfully',
            'access_token' => $user->token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->customer_id,
                'name' => $user->full_name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ],
        ]);
    }

    /**
     * Log out — revoke current token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        //return response()->json(['message' => 'Disabled for now'], 403);
        $user = $request->user();

        return response()->json([
            'id' => $user->customer_id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'full_name' => $user->full_name,
            'is_admin' => $user->is_admin,
            'date_added' => $user->date_added,
        ]);
    }

    /**
     * Update user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstname' => ['sometimes', 'string', 'max:32'],
            'lastname' => ['sometimes', 'string', 'max:32'],
            'telephone' => ['sometimes', 'string', 'max:32'],
            'email' => ['sometimes', 'email', 'max:96', 'unique:oc_customer,email,' . $request->user()->customer_id . ',customer_id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $user->update($request->only(['firstname', 'lastname', 'telephone', 'email']));

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->customer_id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'full_name' => $user->full_name,
            ],
        ]);
    }

    /**
     * Change user password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!$user->validatePassword($request->current_password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 400);
        }
        $passwordWithSalt = [
            'password' => $request->password,
            'salt' => $user->salt 
        ];
        $user->password =  $passwordWithSalt ;
        $user->save();

        // Revoke all tokens except current (force re-login on other devices)
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => 'Password changed successfully. Other sessions have been logged out.',
        ]);
    }

    /**
     * Forgot password — send reset link.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:oc_customer,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // // Laravel’s built-in password broker
        // $status = Password::broker('customers')->sendResetLink(
        //     $request->only('email')
        // );

        // if ($status === Password::RESET_LINK_SENT) {
        //     return response()->json([
        //         'message' => 'Password reset link sent to your email.',
        //     ]);
        // }

        return response()->json([
            'message' => 'Unable to send reset link.',
        ], 500);
    }

    /**
     * Reset password using token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = $password;
                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successfully.',
            ]);
        }

        return response()->json([
            'message' => 'Invalid token or email.',
        ], 400);
    }
}