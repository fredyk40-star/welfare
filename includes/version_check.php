<?php
// PHP Version Check - Ensures minimum required PHP version
$required_php_version = '7.4.0';

if (version_compare(PHP_VERSION, $required_php_version, '<')) {
    die("
    <!DOCTYPE html>
    <html>
    <head>
        <title>Server Error - PHP Version Too Old</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .error-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 600px; margin: 50px auto; }
            h1 { color: #d32f2f; }
            .version { background: #ffebee; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .solution { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='error-box'>
            <h1>⚠️ Server Configuration Error</h1>
            <p><strong>This application requires PHP {$required_php_version} or higher.</strong></p>
            <div class='version'>
                <strong>Current Version:</strong> " . PHP_VERSION . "<br>
                <strong>Required Version:</strong> {$required_php_version}+
            </div>
            <div class='solution'>
                <strong>Solution:</strong>
                <ul>
                    <li>Contact your hosting provider to upgrade PHP</li>
                    <li>Or update PHP through your server control panel (cPanel, Plesk, etc.)</li>
                    <li>Recommended: PHP 8.0 or higher for best performance and security</li>
                </ul>
            </div>
            <p><em>GYF Welfare Management System</em></p>
        </div>
    </body>
    </html>
    ");
}

// Check for required extensions
$required_extensions = ['pdo', 'pdo_mysql', 'openssl', 'mbstring', 'json'];
$missing_extensions = [];

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

if (!empty($missing_extensions)) {
    die("
    <!DOCTYPE html>
    <html>
    <head>
        <title>Server Error - Missing PHP Extensions</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .error-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 600px; margin: 50px auto; }
            h1 { color: #d32f2f; }
            .missing { background: #ffebee; padding: 15px; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='error-box'>
            <h1>⚠️ Server Configuration Error</h1>
            <p><strong>Missing required PHP extensions:</strong></p>
            <div class='missing'>
                " . implode(', ', $missing_extensions) . "
            </div>
            <p>Please contact your hosting provider to enable these extensions.</p>
        </div>
    </body>
    </html>
    ");
}
?>