<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {
    return response()->json([
        'status' => 'OK',
        'php_version' => phpversion(),
        'laravel_version' => app()->version(),
        'memory_usage' => memory_get_usage(true),
        'time' => now()->toISOString()
    ]);
});

Route::get('/debug', function () {
    try {
        $user = \App\Models\User::first();
        return response()->json([
            'database' => 'OK',
            'users_count' => \App\Models\User::count(),
            'first_user' => $user ? $user->email : 'No users'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

Route::get('/simple-login', function () {
    return view('simple-login');
});

Route::post('/simple-login', function () {
    $credentials = request()->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (Auth::attempt($credentials)) {
        return redirect('/simple-login')->with('success', 'Logged in successfully!');
    }

    return back()->with('error', 'Invalid credentials');
});

Route::get('/simple-logout', function () {
    Auth::logout();
    return redirect('/simple-login');
});

Route::get('/js-debug', function () {
    return view('js-debug');
});

Route::get('/test-login', function () {
    $user = \App\Models\User::where('email', 'admin@seodashboard.local')->first();
    if ($user) {
        Auth::login($user);
        return response()->json([
            'status' => 'logged_in',
            'user' => $user->email,
            'admin_url' => url('/admin')
        ]);
    }
    return response()->json(['error' => 'User not found'], 404);
});
