<!DOCTYPE html>
<html>
<head>
    <title>Simple Login Test</title>
</head>
<body>
    <h1>Simple Login Test</h1>
    
    @if(session('error'))
        <div style="color: red;">{{ session('error') }}</div>
    @endif

    @if(auth()->check())
        <div style="color: green;">
            <h2>Logged in as: {{ auth()->user()->email }}</h2>
            <a href="/simple-logout">Logout</a>
        </div>
    @else
        <form method="POST" action="/simple-login">
            @csrf
            <div>
                <label>Email:</label>
                <input type="email" name="email" value="admin@seodashboard.local" required>
            </div>
            <div>
                <label>Password:</label>
                <input type="password" name="password" value="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
    @endif

    <hr>
    <div>
        <h3>System Info:</h3>
        <ul>
            <li>PHP Version: {{ phpversion() }}</li>
            <li>Laravel Version: {{ app()->version() }}</li>
            <li>Database Users: {{ \App\Models\User::count() }}</li>
            <li>Auth Status: {{ auth()->check() ? 'Logged in' : 'Not logged in' }}</li>
        </ul>
    </div>
</body>
</html>