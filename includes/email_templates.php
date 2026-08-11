<?php
// Improved Email Templates with better formatting

function getPasswordResetEmailTemplate($name, $reset_link, $app_name, $app_url) {
    $safe_name = sanitizeEmailValue($name);
    $safe_app_name = sanitizeEmailValue($app_name);
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset - {$safe_app_name}</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%); color: white; padding: 30px 20px; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; }
            .content { padding: 30px 20px; }
            .button { display: inline-block; background: #1976d2; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
            .button:hover { background: #1565c0; }
            .info-box { background: #e3f2fd; border-left: 4px solid #1976d2; padding: 15px; margin: 20px 0; border-radius: 5px; }
            .warning-box { background: #fff3e0; border-left: 4px solid #ff9800; padding: 15px; margin: 20px 0; border-radius: 5px; }
            .footer { background: #f5f5f5; padding: 20px; text-align: center; color: #666; font-size: 12px; border-top: 1px solid #e0e0e0; }
            .logo { max-width: 150px; height: auto; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>{$safe_app_name}</h1>
                <p style='margin: 10px 0 0 0; opacity: 0.9;'>Password Reset Request</p>
            </div>
            
            <div class='content'>
                <p>Hello {$safe_name},</p>
                
                <p>We received a request to reset your password for your {$safe_app_name} account. Click the button below to reset your password:</p>
                
                <div style='text-align: center;'>
                    <a href='{$reset_link}' class='button'>Reset Password</a>
                </div>
                
                <p>Or copy and paste this link into your browser:</p>
                <p style='word-break: break-all; color: #1976d2;'>{$reset_link}</p>
                
                <div class='info-box'>
                    <strong>⏰ Important:</strong> This link will expire in <strong>1 hour</strong> for security reasons.
                </div>
                
                <div class='warning-box'>
                    <strong>⚠️ Security Notice:</strong> If you did not request a password reset, please ignore this email. Your password will remain unchanged.
                </div>
                
                <p>If you're having trouble clicking the button, you can also visit the link above directly.</p>
            </div>
            
            <div class='footer'>
                <p><strong>{$safe_app_name}</strong></p>
                <p>© " . date('Y') . " GYF Ministry & Prayer Camp. All rights reserved.</p>
                <p style='margin-top: 10px; font-size: 11px;'>This is an automated email. Please do not reply to this message.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function getAccountLockoutEmailTemplate($name, $app_name, $unlock_time) {
    $safe_name = sanitizeEmailValue($name);
    $safe_app_name = sanitizeEmailValue($app_name);
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Account Locked - {$safe_app_name}</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%); color: white; padding: 30px 20px; text-align: center; }
            .content { padding: 30px 20px; }
            .alert { background: #ffebee; border-left: 4px solid #d32f2f; padding: 15px; margin: 20px 0; border-radius: 5px; }
            .info { background: #e3f2fd; border-left: 4px solid #1976d2; padding: 15px; margin: 20px 0; border-radius: 5px; }
            .footer { background: #f5f5f5; padding: 20px; text-align: center; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔒 Account Temporarily Locked</h1>
            </div>
            
            <div class='content'>
                <p>Hello {$safe_name},</p>
                
                <div class='alert'>
                    <strong>Security Alert:</strong> Your account has been temporarily locked due to multiple failed login attempts.
                </div>
                
                <p><strong>Unlock Time:</strong> {$unlock_time}</p>
                
                <div class='info'>
                    <strong>💡 What happened?</strong><br>
                    For your security, your account is automatically locked after 5 failed login attempts within a 15-minute period.
                </div>
                
                <p><strong>What to do next:</strong></p>
                <ul>
                    <li>Wait for the lockout period to end</li>
                    <li>Try logging in again after {$unlock_time}</li>
                    <li>Make sure you're using the correct credentials</li>
                </ul>
                
                <p>If you forgot your password, you can reset it using the <a href='" . APP_URL . "/member/forgot-password.php'>password reset page</a>.</p>
            </div>
            
            <div class='footer'>
                <p>© " . date('Y') . " {$safe_app_name}. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function getWelcomeEmailTemplate($name, $app_name) {
    $safe_name = sanitizeEmailValue($name);
    $safe_app_name = sanitizeEmailValue($app_name);
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Welcome - {$safe_app_name}</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); color: white; padding: 30px 20px; text-align: center; }
            .content { padding: 30px 20px; }
            .tip { background: #e8f5e9; border-left: 4px solid #4caf50; padding: 15px; margin: 15px 0; border-radius: 5px; }
            .footer { background: #f5f5f5; padding: 20px; text-align: center; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎉 Welcome to {$safe_app_name}!</h1>
            </div>
            
            <div class='content'>
                <p>Hello {$safe_name},</p>
                
                <p>Thank you for joining {$safe_app_name}. We're excited to have you on board!</p>
                
                <h3>Getting Started:</h3>
                <ol>
                    <li><strong>Complete Your Profile:</strong> Add your contact information and emergency contacts</li>
                    <li><strong>Enable 2FA:</strong> Add an extra layer of security to your account</li>
                    <li><strong>Make Your First Contribution:</strong> Start tracking your welfare contributions</li>
                </ol>
                
                <div class='tip'>
                    <strong>🔒 Security Tip:</strong> Enable two-factor authentication in your settings for better account security.
                </div>
                
                <p>If you have any questions or need assistance, please don't hesitate to contact us.</p>
            </div>
            
            <div class='footer'>
                <p>© " . date('Y') . " {$safe_app_name}. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}
?>