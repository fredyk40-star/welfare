<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isMember()) {
    redirectTo('/member/login.php');
}

require_once __DIR__ . '/../includes/header.php';

$database = new Database();
$db = $database->getConnection();
$member_id = $_SESSION['user_id'];

$success = '';
$error = '';
$csrf_token = generateCsrfToken();

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $phone = cleanInput($_POST['phone'] ?? '');
        $address = cleanInput($_POST['address'] ?? '');
        $occupation = isset($_POST['occupation']) ? cleanInput($_POST['occupation']) : null;
        $emergency_name = cleanInput($_POST['emergency_contact_name'] ?? '');
        $emergency_relationship = cleanInput($_POST['emergency_contact_relationship'] ?? '');
        $emergency_phone = cleanInput($_POST['emergency_contact_phone'] ?? '');

        if (empty($phone) || empty($address) || empty($emergency_name) || empty($emergency_phone)) {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $query = "UPDATE members SET phone = :phone, address = :address, occupation = :occupation,
                          emergency_contact_name = :en, emergency_contact_relationship = :er, emergency_contact_phone = :ep
                          WHERE member_id = :member_id";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':phone' => $phone,
                    ':address' => $address,
                    ':occupation' => $occupation,
                    ':en' => $emergency_name,
                    ':er' => $emergency_relationship,
                    ':ep' => $emergency_phone,
                    ':member_id' => $member_id
                ]);
                logAudit($member_id, 'Profile updated');
                $success = 'Profile updated successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to update profile.';
                error_log("Profile Update Error: " . $e->getMessage());
            }
        }
    }
}

// Update photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_photo') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['passport_photo']['size'] > 0) {
        $upload = uploadPhoto($_FILES['passport_photo']);
        if ($upload['success']) {
            $stmt = $db->prepare("UPDATE members SET passport_photo = :photo WHERE member_id = :member_id");
            $stmt->execute([':photo' => $upload['filename'], ':member_id' => $member_id]);
            $_SESSION['photo'] = $upload['filename'];
            $success = 'Profile photo updated successfully.';
        } else {
            $error = $upload['message'];
        }
    } else {
        $error = 'Please select an image to upload.';
    }
}
}

// Load current member data
$stmt = $db->prepare("SELECT * FROM members WHERE member_id = :member_id");
$stmt->execute([':member_id' => $member_id]);
$member = $stmt->fetch();
?>
<div class="row">
    <div class="col-md-8 mx-auto">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Profile Photo -->
        <div class="card mb-4 text-center">
            <div class="card-body">
                <?php if ($member['passport_photo']): ?>
                    <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>"
                         class="app-logo-large mb-3" alt="Profile">
                <?php endif; ?>
                <h4 class="mb-1"><?php echo htmlspecialchars($member['full_name']); ?></h4>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($member['member_id']); ?></p>

                <form method="POST" action="" enctype="multipart/form-data" class="mt-3 d-inline-block">
                    <input type="hidden" name="action" value="update_photo">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="mb-2">
                        <input type="file" class="form-control" name="passport_photo" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Update Photo</button>
                </form>
            </div>
        </div>

        <!-- Profile Details -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Profile Details</h5></div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['full_name']); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($member['email']); ?>" disabled>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number *</label>
                        <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($member['phone']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Residential Address *</label>
                        <textarea class="form-control" id="address" name="address" rows="2" required><?php echo htmlspecialchars($member['address']); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="occupation" class="form-label">Occupation</label>
                        <input type="text" class="form-control" id="occupation" name="occupation" value="<?php echo htmlspecialchars($member['occupation'] ?? ''); ?>">
                    </div>

                    <h5 class="mb-3 mt-4">Emergency Contact</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="emergency_contact_name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name"
                                   value="<?php echo htmlspecialchars($member['emergency_contact_name']); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="emergency_contact_relationship" class="form-label">Relationship *</label>
                            <input type="text" class="form-control" id="emergency_contact_relationship" name="emergency_contact_relationship"
                                   value="<?php echo htmlspecialchars($member['emergency_contact_relationship']); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="emergency_contact_phone" class="form-label">Phone *</label>
                            <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone"
                                   value="<?php echo htmlspecialchars($member['emergency_contact_phone']); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

