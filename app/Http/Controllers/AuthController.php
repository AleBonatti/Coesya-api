<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where(['email' => $request->email])->with('families')->first();

        if ($user && Hash::check($request->password, $user->password)) {
            $token = $user->createToken($user->email);
            return response()->json(['success' => 'ok', 'token' => $token->plainTextToken, 'user' => $user, 'wizard_completed' => $user->has_completed_wizard]);
        }

        /* $response = [
            "password" => [
                "Invalid data"
            ]
        ]; */
        $response = [
            "success" => "ko",
            "message" => "Invalid credential"
        ];

        return response()->json($response, 401);
    }


    public function register(Request $request)
    {
        $request->validate([
            'firstname' => 'required',
            'lastname' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed',
            'privacy' => 'accepted',
        ]);

        $user = new User();
        $user->firstname = $request->firstname;
        $user->lastname = $request->lastname;
        $user->email = $request->email;
        $user->password = $request->password;
        $user->save();

        // TODO invio mail conferma

        $token = $user->createToken($user->email);
        return response()->json(['success' => 'ok', 'user' => $user, 'token' => $token->plainTextToken]);
    }


    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['success' => 'ok', 'message' => 'Loogout successful. Bye!']);
    }
}
