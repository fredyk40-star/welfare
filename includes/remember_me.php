<?php
// Remember Me Token Management
// Provides secure persistent login functionality

function generateRememberMeToken() {
    return bin2hex(random_bytes(32)) . '.' . bin2hex(random_bytes(16));
}

function hashRememberMeToken($token) {
    return password_hash($token, PASSWORD_DEFAULT);
}

function setRememberMeToken($user_id, $user_type) {
    $database = new Database();
    $db = $database->getConnection();
    
    $token = generateRememberMeToken();
    $hashed_token = hashRememberMeToken($token);
    $expires = date('Y-m-d H:i:s', time() + REMEMBER_ME_TIMEOUT);
    
    // Delete old tokens for this user
    $delete_query = "DELETE FROM remember_me_tokens WHERE user_id = :user_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->execute([':user_id' => $user_id]);
    
    // Insert new token
    $insert_query = "INSERT INTO remember_me_tokens (user_id, user_type, token_hash, expires_at) 
                     VALUES (:user_id, :user_type, :token_hash, :expires_at)";
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->execute([
        ':user_id' => $user_id,
        ':user_type' => $user_type,
        ':token_hash' => $hashed_token,
        ':expires_at' => $expires
    ]);
    
    // Set cookie with selector + token
    setcookie(
        'remember_me',
        $user_id . '.' . $token,
        time() + REMEMBER_ME_TIMEOUT,
        '/',
        '',
        isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        true
    );
}

function clearRememberMeToken($user_id) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "DELETE FROM remember_me_tokens WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':user_id' => $user_id]);
    
    // Clear cookie
    setcookie('remember_me', '', time() - 3600, '/');
}

function autoLoginWithRememberMe() {
    if (isset($_COOKIE['remember_me']) && !isLoggedIn()) {
        $cookie_value = $_COOKIE['remember_me'];
        $parts = explode('.', $cookie_value);
        
        if (count($parts) === 2) {
            $user_id = $parts[0];
            $token = $parts[1];
            
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "SELECT user_type, token_hash, expires_at FROM remember_me_tokens 
                      WHERE user_id = :user_id AND expires_at > NOW()";
            $stmt = $db->prepare($query);
            $stmt->execute([':user_id' => $user_id]);
            $result = $stmt->fetch();
            
            if ($result && password_verify($token, $result['token_hash'])) {
                // Valid token - log user in
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_type'] = $result['user_type'];
                
                // Get user details
                $user_query = "SELECT member_id, full_name, email, passport_photo FROM members WHERE member_id = :member_id";
                $user_stmt = $db->prepare($user_query);
                $user_stmt->execute([':member_id' => $user_id]);
                $user = $user_stmt->fetch();
                
                if ($user) {
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['photo'] = $user['passport_photo'];
                    
                    // Rotate token for security
                    setRememberMeToken($user_id, $result['user_type']);
                    
                    logAudit($user_id, 'Auto-login via remember me token');
                    return true;
                }
            }
            
            // Invalid token - clean up
            clearRememberMeToken($user_id);
        }
    }
    return false;
}
?>