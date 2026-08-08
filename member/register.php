<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

if (isLoggedIn()) {
    redirectTo('/member/dashboard.php');
}

$error = '';
$success = '';
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $full_name = sanitizeInput($_POST['full_name']);
    $dob = sanitizeInput($_POST['dob']);
    $gender = sanitizeInput($_POST['gender']);
    $email = sanitizeInput($_POST['email']);
    $country_code = sanitizeInput($_POST['country_code']);
    $phone = sanitizeInput($_POST['phone']);
    $address = sanitizeInput($_POST['address']);
    $occupation = isset($_POST['occupation']) ? sanitizeInput($_POST['occupation']) : null;
    $emergency_name = sanitizeInput($_POST['emergency_contact_name']);
    $emergency_relationship = sanitizeInput($_POST['emergency_contact_relationship']);
    $emergency_phone = sanitizeInput($_POST['emergency_contact_phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $consent = isset($_POST['consent']);
    
    $email = strtolower($email);
    $phone = $country_code . ' ' . $phone;
    
    // Validate inputs
    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (($password_validation = validatePassword($password)) !== true) {
        $error = $password_validation;
    } elseif (!$consent) {
        $error = 'You must agree to the terms and conditions.';
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if email already exists
        $check_query = "SELECT id FROM members WHERE LOWER(email) = LOWER(:email)";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([':email' => $email]);
        
        if ($check_stmt->rowCount() > 0) {
            $error = 'Email already registered.';
        } else {
            // Handle photo upload
            $photo_filename = null;
            if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
                $upload_result = uploadPhoto($_FILES['passport_photo']);
                if ($upload_result['success']) {
                    $photo_filename = $upload_result['filename'];
                } else {
                    $error = $upload_result['message'];
                }
            }
            
            if (!$error) {
                // Generate member ID
                $member_id = generateMemberID();
                
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                // Insert member
                $query = "INSERT INTO members (member_id, full_name, dob, gender, email, phone, address, 
                         occupation, emergency_contact_name, emergency_contact_relationship, 
                         emergency_contact_phone, passport_photo, password) 
                         VALUES (:member_id, :full_name, :dob, :gender, :email, :phone, :address, 
                         :occupation, :emergency_name, :emergency_relationship, :emergency_phone, 
                         :photo, :password)";
                
                $stmt = $db->prepare($query);
                
                try {
                    $stmt->execute([
                        ':member_id' => $member_id,
                        ':full_name' => $full_name,
                        ':dob' => $dob,
                        ':gender' => $gender,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':address' => $address,
                        ':occupation' => $occupation,
                        ':emergency_name' => $emergency_name,
                        ':emergency_relationship' => $emergency_relationship,
                        ':emergency_phone' => $emergency_phone,
                        ':photo' => $photo_filename,
                        ':password' => $hashed_password
                    ]);
                    
                    logAudit($member_id, 'New member registered');
                    $success = "Registration successful! Your Member ID is: <strong>$member_id</strong>";
                    
                } catch (PDOException $e) {
                    $error = 'Registration failed. Please try again.';
                    error_log("Registration Error: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Registration - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Member Registration</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <?php echo $success; ?>
                                <br>
                                <a href="login.php" class="btn btn-primary mt-3">Proceed to Login</a>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" enctype="multipart/form-data" id="registrationForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <h5 class="mb-3">Personal Information</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="full_name" class="form-label">Full Name *</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="dob" class="form-label">Date of Birth *</label>
                                        <input type="date" class="form-control" id="dob" name="dob" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="gender" class="form-label">Gender *</label>
                                        <select class="form-control" id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email Address *</label>
                                        <input type="email" class="form-control" id="email" name="email" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label for="country_code" class="form-label">Country Code *</label>
                                        <select class="form-control" id="country_code" name="country_code" required>
                                            <option value="+233">Ghana (+233)</option>
                                            <option value="+1">USA (+1)</option>
                                            <option value="+44">UK (+44)</option>
                                            <option value="+27">South Africa (+27)</option>
                                            <option value="+234">Nigeria (+234)</option>
                                            <option value="+254">Kenya (+254)</option>
                                            <option value="+255">Tanzania (+255)</option>
                                            <option value="+256">Uganda (+256)</option>
                                            <option value="+265">Malawi (+265)</option>
                                            <option value="+260">Zambia (+260)</option>
                                            <option value="+263">Zimbabwe (+263)</option>
                                            <option value="+258">Mozambique (+258)</option>
                                            <option value="+257">Burundi (+257)</option>
                                            <option value="+250">Rwanda (+250)</option>
                                            <option value="+221">Senegal (+221)</option>
                                            <option value="+223">Mali (+223)</option>
                                            <option value="+226">Burkina Faso (+226)</option>
                                            <option value="+229">Benin (+229)</option>
                                            <option value="+224">Guinea (+224)</option>
                                            <option value="+225">Ivory Coast (+225)</option>
                                            <option value="+220">Gambia (+220)</option>
                                            <option value="+232">Sierra Leone (+232)</option>
                                            <option value="+231">Liberia (+231)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-9 mb-3">
                                        <label for="phone" class="form-label">Phone Number *</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               placeholder="XX XXX XXXX" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="address" class="form-label">Residential Address *</label>
                                    <textarea class="form-control" id="address" name="address" rows="2" required></textarea>
                                </div>
                                
                                <h5 class="mb-3">Emergency Contact</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="emergency_contact_name" class="form-label">Contact Name *</label>
                                        <input type="text" class="form-control" id="emergency_contact_name" 
                                               name="emergency_contact_name" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="emergency_contact_relationship" class="form-label">Relationship *</label>
                                        <input type="text" class="form-control" id="emergency_contact_relationship" 
                                               name="emergency_contact_relationship" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="emergency_contact_phone" class="form-label">Phone *</label>
                                        <input type="tel" class="form-control" id="emergency_contact_phone" 
                                               name="emergency_contact_phone" required>
                                    </div>
                                </div>
                                
                                <h5 class="mb-3">Security</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">Password *</label>
                                        <input type="password" class="form-control" id="password" name="password" autocomplete="new-password"
                                               placeholder="8-255 characters, include uppercase, lowercase, number, and special character" required>
                                        <small class="text-muted">Password must be 8-255 characters with uppercase, lowercase, number, and special character</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="passport_photo" class="form-label">Passport Photo</label>
                                    <input type="file" class="form-control" id="passport_photo" name="passport_photo" 
                                           accept="image/*">
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="consent" name="consent" required>
                                    <label class="form-check-label" for="consent">
                                        I consent to the collection and processing of my personal data for welfare management purposes *
                                    </label>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">Register</button>
                            </form>
                            
                            <div class="mt-3 text-center">
                                <a href="login.php" class="text-decoration-none">Already have an account? Login here</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/validation.js"></script>
</body>
</html>


