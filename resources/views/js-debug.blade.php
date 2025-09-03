<!DOCTYPE html>
<html>
<head>
    <title>JavaScript Debug</title>
    <script>
        // Capture all JavaScript errors
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            document.getElementById('js-errors').innerHTML += '<div style="color: red; border: 1px solid red; padding: 10px; margin: 5px;">' +
                '<strong>Error:</strong> ' + e.message + '<br>' +
                '<strong>File:</strong> ' + e.filename + '<br>' +
                '<strong>Line:</strong> ' + e.lineno + '<br>' +
                '<strong>Stack:</strong> ' + (e.error ? e.error.stack : 'No stack trace') +
                '</div>';
        });
        
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled Promise Rejection:', e.reason);
            document.getElementById('js-errors').innerHTML += '<div style="color: red; border: 1px solid red; padding: 10px; margin: 5px;">' +
                '<strong>Promise Rejection:</strong> ' + e.reason +
                '</div>';
        });
    </script>
</head>
<body>
    <h1>JavaScript Debug Page</h1>
    
    <div id="js-errors">
        <h2>JavaScript Errors will appear here:</h2>
    </div>
    
    <hr>
    
    <h2>Test Links:</h2>
    <ul>
        <li><a href="/admin/login" target="_blank">Admin Login (New Tab)</a></li>
        <li><a href="/simple-login" target="_blank">Simple Login (New Tab)</a></li>
        <li><a href="/test" target="_blank">Test API (New Tab)</a></li>
    </ul>
    
    <hr>
    
    <h2>Direct Filament Load Test:</h2>
    <div id="filament-test">
        <p>Loading Filament JavaScript...</p>
    </div>
    
    <script>
        // Test loading Filament scripts directly
        const scripts = [
            '/js/filament/support/support.js?v=4.0.4.0',
            '/js/filament/actions/actions.js?v=4.0.4.0',
            '/livewire/livewire.js'
        ];
        
        scripts.forEach(function(src) {
            const script = document.createElement('script');
            script.src = src;
            script.onload = function() {
                document.getElementById('filament-test').innerHTML += '<div style="color: green;">✅ Loaded: ' + src + '</div>';
            };
            script.onerror = function() {
                document.getElementById('filament-test').innerHTML += '<div style="color: red;">❌ Failed: ' + src + '</div>';
            };
            document.head.appendChild(script);
        });
    </script>
</body>
</html>