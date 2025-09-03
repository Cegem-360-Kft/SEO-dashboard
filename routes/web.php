<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): View|Factory {
    return view('welcome');
});

Route::get('/test', function () {
    return response()->json([
        'status' => 'OK',
        'php_version' => phpversion(),
        'laravel_version' => app()->version(),
        'memory_usage' => memory_get_usage(true),
        'time' => now()->toISOString(),
    ]);
});

Route::get('/debug', function () {
    try {
        $user = User::query()->first();

        return response()->json([
            'database' => 'OK',
            'users_count' => User::query()->count(),
            'first_user' => $user ? $user->email : 'No users',
        ]);
    } catch (Exception $exception) {
        return response()->json([
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ], 500);
    }
});

Route::get('/simple-login', function (): View|Factory {
    return view('simple-login');
});

Route::post('/simple-login', function () {
    $credentials = request()->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (Auth::attempt($credentials)) {
        return redirect('/simple-login')->with('success', 'Logged in successfully!');
    }

    return back()->with('error', 'Invalid credentials');
});

Route::get('/simple-logout', function (): Redirector|RedirectResponse {
    Auth::logout();

    return redirect('/simple-login');
});

Route::get('/js-debug', function (): View|Factory {
    return view('js-debug');
});

Route::get('/test-login', function () {
    $user = User::query()->where('email', 'admin@seodashboard.local')->first();
    if ($user) {
        Auth::login($user);

        return response()->json([
            'status' => 'logged_in',
            'user' => $user->email,
            'admin_url' => url('/admin'),
        ]);
    }

    return response()->json(['error' => 'User not found'], 404);
});
