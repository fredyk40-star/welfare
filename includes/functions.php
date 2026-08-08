<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Security Functions
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function generateMemberID() {
    return 'GYF-' . strtoupper(substr(uniqid(), -6));
}

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

function validatePassword($password) {
    $min_length = PASSWORD_MIN_LENGTH;
    $max_length = PASSWORD_MAX_LENGTH;
    
    if (strlen($password) < $min_length || strlen($password) > $max_length) {
        return "Password must be between {$min_length} and {$max_length} characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return "Password must contain at least one special character";
    }
    
    return true;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function isTreasurer() {
    return isLoggedIn() && $_SESSION['user_type'] === 'treasurer';
}

function isMember() {
    return isLoggedIn() && $_SESSION['user_type'] === 'member';
}

function checkOnlineStatus() {
    return true; // Online-only enforcement
}

function logAudit($user_id, $action) {
    $database = new Database();
    $db = $database->getConnection();
    
    $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    $ip_address = explode(',', $ip_address)[0];
    $ip_address = trim($ip_address);
    
    $query = "INSERT INTO audit_logs (user_id, action, ip_address) VALUES (:user_id, :action, :ip_address)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $user_id,
        ':action' => $action,
        ':ip_address' => $ip_address
    ]);
}

function sendEmail($to, $subject, $message) {
    $headers = "From: " . APP_NAME . " <noreply@gyf.org>\r\n";
    $headers .= "Reply-To: noreply@gyf.org\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

function sendReceiptEmail($member_email, $receipt_data, $member_photo = null) {
    $photo_html = '';
    if ($member_photo && file_exists(UPLOAD_DIR . 'photos/' . $member_photo)) {
        $photo_url = APP_URL . '/uploads/photos/' . $member_photo;
        $photo_html = '<img src="' . $photo_url . '" alt="Member Photo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #1976d2; margin-bottom: 15px;">';
    }
    
    $subject = 'Payment Receipt - ' . APP_NAME;
    $message = '
    <html>
    <head>
        <title>Payment Receipt</title>
        <style>
            body { font-family: Arial, sans-serif; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { text-align: center; padding: 20px; background: #1976d2; color: white; border-radius: 10px 10px 0 0; }
            .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 10px 10px; }
            .receipt-details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
            .total { font-size: 1.2em; font-weight: bold; color: #1976d2; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 0.9em; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>GYF Welfare Management System</h2>
                <p>Payment Receipt Confirmation</p>
            </div>
            <div class="content">
                <div style="text-align: center;">
                    ' . $photo_html . '
                    <h3>Thank you for your payment!</h3>
                </div>
                
                <div class="receipt-details">
                    <div class="row">
                        <span>Receipt No:</span>
                        <strong>' . htmlspecialchars($receipt_data['receipt_no']) . '</strong>
                    </div>
                    <div class="row">
                        <span>Member Name:</span>
                        <strong>' . htmlspecialchars($receipt_data['member_name']) . '</strong>
                    </div>
                    <div class="row">
                        <span>Member ID:</span>
                        <strong>' . htmlspecialchars($receipt_data['member_id']) . '</strong>
                    </div>
                    <div class="row">
                        <span>Amount:</span>
                        <strong class="total">GH₵ ' . number_format($receipt_data['amount'], 2) . '</strong>
                    </div>
                    <div class="row">
                        <span>Payment Method:</span>
                        <strong>' . htmlspecialchars($receipt_data['payment_method']) . '</strong>
                    </div>
                    <div class="row">
                        <span>Billing Period:</span>
                        <strong>' . htmlspecialchars($receipt_data['billing_period']) . '</strong>
                    </div>
                    <div class="row">
                        <span>Date:</span>
                        <strong>' . date('F j, Y g:i A', strtotime($receipt_data['date'])) . '</strong>
                    </div>
                </div>
                
                <div class="footer">
                    <p>This is an automated receipt. Please keep it for your records.</p>
                    <p>&copy; ' . date('Y') . ' GYF Ministry & Prayer Camp. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ';
    
    return sendEmail($member_email, $subject, $message);
}

function redirectTo($url) {
    header("Location: " . APP_URL . $url);
    exit();
}

function uploadPhoto($file) {
    $target_dir = UPLOAD_DIR . 'photos/';
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $new_filename = uniqid() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    // Verify MIME type from file content, not just extension
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file["tmp_name"]);
    finfo_close($finfo);
    
    $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
    
    if (!isset($allowed_mimes[$mime_type])) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, JPEG, PNG & GIF files are allowed.'];
    }
    
    $file_extension = $allowed_mimes[$mime_type];
    $new_filename = uniqid() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    // Check file size
    if ($file["size"] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'message' => 'File is too large. Maximum size is 5MB.'];
    }
    
    // Allow certain file formats
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
    if(!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'message' => 'Only JPG, JPEG, PNG & GIF files are allowed.'];
    }
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return ['success' => true, 'filename' => $new_filename];
    } else {
        return ['success' => false, 'message' => 'Error uploading file.'];
    }
}
?>