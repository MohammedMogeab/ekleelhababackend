<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User ;
use Illuminate\Support\Facades\Hash;

class AuthTestController extends Controller
{
    public function register(Request $request)
    {  
        //return response()->json(['message' => 'Disabled for now'], 403);
        
        $fields = $request->validate([
            'name' => 'required|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed|min:6'
        ]);
    
        // Create the user
        $user = User::create([
            'name' => $fields['name'],
            'email' => $fields['email'],
            'password' => bcrypt($fields['password']), // Hash the password
        ]);
    
        // Create a token for the user (automatically stored in the database)
        $token = $user->createToken('name',['server:update'])->plainTextToken;
    
        return response([
            'user' => $user,
            'token' => $token
        ], 201);
    }
    



    public function login (Request $request){
        
        $fields = $request->validate([
            'email' => 'required|email|exists:users',
            'password' => 'required'
        ]);

        $user = User::where('email' , $request->email)->first();
        if(!$user || !Hash::check($request->password, $user->password)){
            return [
                'message' => 'The provided credentials are incorrects'
            ];
        }

        $token = $user->createToken($user->name) ;
        return response([
            'user' => $user,
            'token' => $token->plainTextToken
        ], 201);

    }






    public function logout (Request $request){

        $request->user()->tokens()->delete();
        return response([
            'message' => 'you are loggd out'
        ], 201);
    }

} 
