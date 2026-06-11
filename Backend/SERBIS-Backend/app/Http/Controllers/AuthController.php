<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'role' => 'required|string|max:50',
            'email_address' => 'required|string|email|max:255|unique:tbl_user,email_address',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'role' => $validated['role'],
            'email_address' => $validated['email_address'],
            'password' => $validated['password'],
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email_address' => 'required|email',
            'password' => 'required',
        ]);

        // Check Admin table first
        $admin = User::where('email_address', $request->email_address)->first();
        if ($admin && Hash::check($request->password, $admin->password)) {
            return response()->json([
                'token' => $admin->createToken('admin-token')->plainTextToken,
                'role' => 'admin',
                'user' => $admin
            ]);
        }

        // Check Resident table second
        $resident = Resident::where('email_address', $request->email_address)->first();
        if ($resident && Hash::check($request->password, $resident->password)) {
            return response()->json([
                'token' => $resident->createToken('resident-token')->plainTextToken,
                'role' => 'resident',
                'user' => $resident
            ]);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
}