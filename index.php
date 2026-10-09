<?php
/**
 * MATA Dashboard - Configuration Error Handler.
 *
 * This file should never be accessed directly. If you're seeing this message,
 * your web server document root is incorrectly configured.
 */
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MATA Dashboard - Configuration Required</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
        }
        h1 {
            color: #d32f2f;
            border-bottom: 3px solid #d32f2f;
            padding-bottom: 10px;
        }
        h2 {
            color: #1976d2;
            margin-top: 30px;
            font-size: 1.3em;
        }
        pre {
            background: #f5f5f5;
            border-left: 4px solid #1976d2;
            padding: 15px;
            overflow-x: auto;
            border-radius: 4px;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: "Courier New", monospace;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        a {
            color: #1976d2;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h1>⚙️ MATA Dashboard - Configuration Required</h1>

    <div class="warning">
        <strong>⚠️ Configuration Error:</strong> The web server document root must point to the <code>public/</code> directory.
    </div>

    <p>
        For security and proper functionality, MATA Dashboard requires the web server's document root
        to be set to the <code>public/</code> directory. This prevents direct access to sensitive files
        like configuration, vendor dependencies, and source code.
    </p>

    <h2>🔧 Quick Fix</h2>

    <h3>Apache Virtual Host</h3>
    <pre>&lt;VirtualHost *:80&gt;
    ServerName mata.yourdomain.com
    DocumentRoot /path/to/mata-dashboard/public

    &lt;Directory /path/to/mata-dashboard/public&gt;
        AllowOverride All
        Require all granted
    &lt;/Directory&gt;
&lt;/VirtualHost&gt;</pre>

    <h3>Nginx Configuration</h3>
    <pre>server {
    listen 80;
    server_name mata.yourdomain.com;
    root /path/to/mata-dashboard/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}</pre>

    <h3>PHP Built-in Development Server</h3>
    <pre>cd /path/to/mata-dashboard
php -S 0.0.0.0:8001 -t public/</pre>

    <div class="info">
        <strong>💡 Why is this required?</strong><br>
        Setting the document root to <code>public/</code> prevents unauthorized access to:
        <ul>
            <li><code>config/</code> - Contains sensitive configuration files and API keys</li>
            <li><code>vendor/</code> - Third-party dependencies that shouldn't be web-accessible</li>
            <li><code>src/</code> - Application source code</li>
            <li><code>.env</code> files - Environment secrets and credentials</li>
            <li><code>composer.json</code> - Dependency information</li>
        </ul>
    </div>

    <h2>📚 Additional Resources</h2>
    <ul>
        <li><a href="https://github.com/xarcdotdev/mata-dashboard">GitHub Repository</a></li>
        <li><a href="https://github.com/xarcdotdev/mata-dashboard/blob/main/README.md">Installation Documentation</a></li>
    </ul>

    <hr style="margin: 40px 0; border: 0; border-top: 1px solid #ddd;">

    <p style="color: #666; font-size: 0.9em;">
        <strong>Current Request:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?><br>
        <strong>Server Software:</strong> <?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?>
    </p>
</body>
</html>
<?php
exit;
