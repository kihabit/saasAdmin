<?php
session_start();
require_once '../config.php';

if (!isLoggedIn()) { redirect(LOGIN_PAGE); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset(); session_destroy();
    setFlashMessage('error', 'Your session has expired. Please login again.');
    redirect(LOGIN_PAGE);
}
$_SESSION['last_activity'] = time();

$db   = Database::getInstance();
$conn = $db->getConnection();

$logged_user_id  = $_SESSION['user_id'];
$logged_username = $_SESSION['username'];

if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'User ID not provided.');
    redirect('users.php');
}
$view_user_id = intval($_GET['id']);

// Handle user update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $firstName       = trim($_POST['firstName']       ?? '');
    $lastName        = trim($_POST['lastName']        ?? '');
    $email           = trim($_POST['email']           ?? '');
    $username        = trim($_POST['username']        ?? '');
    $driverId        = trim($_POST['driverId']        ?? '');
    $newPassword     = $_POST['new_password']    ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $phone_number = trim($_POST['phone_number'] ?? '');
    $address      = trim($_POST['address_field'] ?? '');
    $city         = trim($_POST['city']         ?? '');
    $state        = trim($_POST['state']        ?? '');
    $country      = trim($_POST['country']      ?? '');
    $zipcode      = trim($_POST['zipcode']      ?? '');
    $userType     = intval($_POST['userType']    ?? 0);
    $status       = trim($_POST['status']       ?? '');

    $organization_id   = !empty($_POST['organization_id'])   ? intval($_POST['organization_id']) : null;
    $organization_name = !empty($_POST['organization_name']) ? trim($_POST['organization_name']) : null;

    $userErrors = [];
    if (empty($username)) $userErrors[] = 'Username is required.';
    if (empty($email))    $userErrors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $userErrors[] = 'Invalid email format.';

    if (empty($userErrors)) {
        $stmt = $conn->prepare("SELECT user_id FROM edu_user WHERE username=? AND user_id!=?");
        $stmt->bind_param("si",$username,$view_user_id); $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $userErrors[] = 'Username already exists.';
        $stmt->close();
    }
    if (empty($userErrors)) {
        $stmt = $conn->prepare("SELECT user_id FROM edu_user WHERE email=? AND user_id!=?");
        $stmt->bind_param("si",$email,$view_user_id); $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $userErrors[] = 'Email already exists.';
        $stmt->close();
    }
    if (!empty($newPassword) || !empty($confirmPassword)) {
        if (empty($newPassword))     $userErrors[] = 'New password is required.';
        if (empty($confirmPassword)) $userErrors[] = 'Confirm password is required.';
        if (!empty($newPassword) && !empty($confirmPassword)) {
            if ($newPassword !== $confirmPassword) $userErrors[] = 'Passwords do not match.';
            if (strlen($newPassword) < 6)          $userErrors[] = 'Password must be at least 6 characters.';
        }
    }

    if (empty($userErrors)) {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE edu_user SET username=?,firstName=?,lastName=?,email=?,driverId=?,phone_number=?,address=?,city=?,state=?,country=?,zipcode=?,userType=?,status=?,organization_id=?,organization_name=? WHERE user_id=?");
            $stmt->bind_param("sssssssssssisisi",$username,$firstName,$lastName,$email,$driverId,$phone_number,$address,$city,$state,$country,$zipcode,$userType,$status,$organization_id,$organization_name,$view_user_id);
            $stmt->execute(); $stmt->close();

            if (!empty($newPassword)) {
                $hashed = md5(trim($newPassword));
                $stmt = $conn->prepare("UPDATE edu_user SET password_hash=? WHERE user_id=?");
                $stmt->bind_param("si",$hashed,$view_user_id); $stmt->execute(); $stmt->close();
            }
            $conn->commit();
            $_SESSION['flash_message'] = 'User updated successfully!'.(!empty($newPassword)?' Password changed.':'');
            $_SESSION['flash_type']    = 'success';
            redirect('users.php');
        } catch (Exception $e) {
            $conn->rollback(); logAppError("Update user: ".$e->getMessage());
            $userErrors[] = 'Error updating user: '.$e->getMessage();
        }
    }
    if (!empty($userErrors)) { $_SESSION['flash_message']=implode(' ',$userErrors); $_SESSION['flash_type']='error'; }
}

// Fetch user
$user = null;
try {
    $stmt = $conn->prepare("SELECT u.user_id as user_id,u.driverId,u.username,u.firstName,u.lastName,u.email,u.phone_number,u.address,u.city,u.state,u.country,u.zipcode,u.latitude,u.longitude,u.userType,u.status,u.created_at,u.last_login,u.organization_id,u.organization_name, o.org_id as org_custom_id FROM edu_user u LEFT JOIN organization o ON o.id = u.organization_id WHERE u.user_id=?");
    $stmt->bind_param("i",$view_user_id); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user) { setFlashMessage('error','User not found.'); redirect('users.php'); }
} catch (Exception $e) { setFlashMessage('error','Error loading user.'); redirect('users.php'); }

// Logout
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login','',time()-3600,COOKIE_PATH,COOKIE_DOMAIN,COOKIE_SECURE,COOKIE_HTTPONLY);
        try { $stmt=$conn->prepare("DELETE FROM edu_remember_tokens WHERE user_id=?"); $stmt->bind_param("i",$logged_user_id); $stmt->execute(); $stmt->close(); } catch(Exception $e){}
    }
    session_unset(); session_destroy(); redirect(LOGIN_PAGE);
}

$flashMessage = $flashType = '';
if (isset($_SESSION['flash_message'])) {
    $flashMessage = is_array($_SESSION['flash_message']) ? implode(' ',$_SESSION['flash_message']) : $_SESSION['flash_message'];
    $flashType    = $_SESSION['flash_type'] ?? 'error';
    unset($_SESSION['flash_message'],$_SESSION['flash_type']);
}

// ✅ Roles dynamically fetch karo (prt=0 wale - super_admin nahi dikhega)
$roles = [];
$rStmt = $conn->prepare("SELECT id, role_name FROM roles WHERE prt = 0 ORDER BY id ASC");
$rStmt->execute();
$rResult = $rStmt->get_result();
while ($rRow = $rResult->fetch_assoc()) { $roles[] = $rRow; }
$rStmt->close();

$db->close();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1a202c;line-height:1.6;}
        .main-wrapper{font-size:13px;}
        .app-container{display:flex;min-height:100vh;}
        .sidebar{width:280px;background:white;border-right:1px solid #e2e8f0;position:fixed;height:100vh;left:0;top:0;z-index:1000;overflow-y:auto;transition:transform 0.3s ease;}
        .main-wrapper{flex:1;margin-left:280px;}
        .header{background:white;border-bottom:1px solid #e2e8f0;padding:.75rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
        .header-content{display:flex;justify-content:space-between;align-items:center;}
        .breadcrumb{display:flex;align-items:center;gap:6px;color:#718096;font-size:.78rem;}
        .breadcrumb a{color:#0000FF;text-decoration:none;}
        .header-actions{display:flex;align-items:center;gap:.75rem;}
        .btn-back{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;padding:6px 13px;border-radius:7px;text-decoration:none;display:flex;align-items:center;gap:6px;font-weight:500;font-size:.78rem;transition:all 0.3s;}
        .btn-back:hover{background:#e2e8f0;}
        .logout-btn{background:#dc3545;color:white;border:none;padding:6px 13px;border-radius:7px;font-weight:500;font-size:.78rem;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.3s;}
        .logout-btn:hover{background:#c82333;}
        .main-content{padding:1.5rem;max-width:1400px;margin:0 auto;}
        .alert{padding:.75rem 1.25rem;border-radius:10px;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;font-weight:500;font-size:.82rem;animation:slideIn 0.3s ease;}
        .alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
        .alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
        .alert-warning{background:#fef3c7;border:1px solid #fbbf24;color:#92400e;}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0;}to{transform:translateX(0);opacity:1;}}
        .info-card{background:white;border:1px solid #e2e8f0;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;}
        .info-item{display:flex;flex-direction:column;gap:3px;}
        .info-label{font-size:.7rem;color:#718096;font-weight:500;text-transform:uppercase;letter-spacing:.4px;}
        .info-value{font-size:.82rem;font-weight:600;color:#1a202c;}
        .edit-card{background:white;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.5rem;}
        .card-header{background:linear-gradient(135deg,#0000FF,#4169E1);color:white;padding:1rem 1.5rem;display:flex;align-items:center;gap:10px;}
        .card-header h2{font-size:1.1rem;font-weight:700;}
        .card-body{padding:1.5rem;}
        .form-section{margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid #e2e8f0;}
        .form-section:last-of-type{border-bottom:none;margin-bottom:0;padding-bottom:0;}
        .section-title{font-size:.85rem;font-weight:600;color:#1a202c;margin-bottom:1rem;padding-bottom:.4rem;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:7px;}
        .section-title i{color:#0000FF;}
        .form-group{margin-bottom:1.1rem;}
        .form-label{display:flex;margin-bottom:.4rem;font-weight:600;color:#1a202c;align-items:center;gap:7px;font-size:.8rem;}
        .form-label i{color:#0000FF;}
        .required{color:#dc3545;}
        .form-input,.form-textarea{width:100%;padding:.6rem .9rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;transition:all 0.3s;font-family:inherit;}
        .form-textarea{resize:vertical;min-height:80px;}
        .form-input:focus,.form-textarea:focus{outline:none;border-color:#0000FF;box-shadow:0 0 0 3px rgba(0,0,255,0.1);}
        .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.1rem;}
        .password-input{position:relative;}
        .toggle-password{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#718096;cursor:pointer;padding:4px;font-size:1rem;}
        .toggle-password:hover{color:#0000FF;}
        .form-actions{display:flex;gap:.75rem;justify-content:flex-end;padding-top:1.25rem;border-top:1px solid #e2e8f0;margin-top:1.25rem;}
        .btn{padding:.6rem 1.25rem;border-radius:7px;font-weight:600;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;gap:7px;border:none;font-size:.82rem;text-decoration:none;}
        .btn-primary{background:#0000FF;color:white;}
        .btn-primary:hover{background:#0000CC;transform:translateY(-1px);}
        .btn-secondary{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;}
        .btn-secondary:hover{background:#e2e8f0;}
        .organization-search-wrapper{position:relative;}
        .organization-dropdown{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:white;border:1.5px solid #0000FF;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,255,0.12);z-index:500;max-height:220px;overflow-y:auto;}
        .organization-dropdown.open{display:block;}
        .organization-dd-header{padding:5px 11px;font-size:.66rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;border-bottom:1px solid #f1f5f9;background:#f8fafc;border-radius:10px 10px 0 0;}
        .organization-option{padding:8px 12px;cursor:pointer;transition:background 0.15s;border-bottom:1px solid #f1f5f9;display:flex;flex-direction:column;gap:2px;}
        .organization-option:last-child{border-bottom:none;border-radius:0 0 10px 10px;}
        .organization-option:hover,.organization-option.highlighted{background:#eff6ff;}
        .organization-option-name{font-size:.82rem;font-weight:600;color:#1a202c;}
        .organization-option-meta{font-size:.7rem;color:#94a3b8;display:flex;gap:7px;}
        .organization-option-id{background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:4px;font-size:.65rem;font-weight:700;}
        .organization-no-result{padding:12px;text-align:center;color:#94a3b8;font-size:.78rem;}
        .organization-selected-badge{display:none;align-items:center;gap:7px;margin-top:5px;padding:5px 9px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;border-radius:7px;font-size:.72rem;color:#15803d;font-weight:500;}
        .organization-selected-badge.show{display:flex;}
        .badge-clear{margin-left:auto;cursor:pointer;color:#15803d;font-size:11px;opacity:0.7;background:none;border:none;}
        .badge-clear:hover{opacity:1;}
        .match-highlight{color:#0000FF;font-weight:700;}
        .organization-readonly{background:#f0fdf4!important;cursor:not-allowed;color:#15803d;font-weight:600;}
        @keyframes spin{to{transform:rotate(360deg);}}
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.main-wrapper{margin-left:0}.form-grid{grid-template-columns:1fr}}
        @media(max-width:768px){.main-content{padding:.75rem}.card-body{padding:1rem}.form-actions{flex-direction:column}}
    </style>
</head>
<body>
<div class="app-container">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main-wrapper">
        <header class="header">
            <div class="header-content">
                <div class="breadcrumb">
                    <a href="<?php echo BASE_URL;?>dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="<?php echo BASE_URL;?>users.php">Users</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Edit <?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <div class="header-actions">
                    <a href="<?php echo BASE_URL;?>users.php" class="btn-back"><i class="fas fa-arrow-left"></i>Back to Users</a>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Logout?')"><i class="fas fa-sign-out-alt"></i>Logout</a>
                </div>
            </div>
        </header>

        <main class="main-content">
            <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>">
                <i class="fas <?php echo $flashType==='success'?'fa-check-circle':'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
            <?php endif; ?>

            <div class="info-card">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">User ID</span>
                        <span class="info-value">#<?php echo htmlspecialchars($user['user_id']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Created Date</span>
                        <span class="info-value"><?php echo date('M j, Y',strtotime($user['created_at'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Login</span>
                        <span class="info-value"><?php echo !empty($user['last_login'])?date('M j, Y g:i A',strtotime($user['last_login'])):'Never'; ?></span>
                    </div>
                    <?php if(!empty($user['phone_number'])): ?>
                    <div class="info-item">
                        <span class="info-label">Phone</span>
                        <span class="info-value">📞 <?php echo htmlspecialchars($user['phone_number']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if(!empty($user['userType'])): ?>
                    <?php
                    // ✅ Dynamic typeLabel from roles
                    $typeLabels = [];
                    foreach($roles as $r) { $typeLabels[$r['id']] = ucwords(str_replace('_',' ',$r['role_name'])); }
                    $typeLabel = $typeLabels[$user['userType']] ?? 'Unknown';
                    ?>
                    <div class="info-item">
                        <span class="info-label">User Type</span>
                        <span class="info-value">🏷️ <?php echo htmlspecialchars($typeLabel); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if(!empty($user['status'])): ?>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value" style="color:<?php echo $user['status']==='active'?'#15803d':($user['status']==='banned'?'#dc2626':'#92400e'); ?>;">
                            <?php echo $user['status']==='active'?'✅':'❌'; ?> <?php echo htmlspecialchars(ucfirst($user['status'])); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if(!empty($user['city']) || !empty($user['country'])): ?>
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value">📍 <?php echo htmlspecialchars(implode(', ', array_filter([$user['city']??'', $user['country']??'']))); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if(!empty($user['organization_name'])): ?>
                    <div class="info-item">
                        <span class="info-label">Current Organization</span>
                        <span class="info-value" style="color:#0000FF;">🏫 <?php echo htmlspecialchars($user['organization_name']); ?> (Org ID: <?php echo htmlspecialchars($user['org_custom_id'] ?? $user['organization_id']); ?>)</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="edit-card">
                <div class="card-header">
                    <i class="fas fa-user-edit"></i>
                    <h2>Edit User Information</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="">

                        <!-- Basic Info -->
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-info-circle"></i>Basic Information</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-user"></i>Username <span class="required">*</span></label>
                                    <input type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-envelope"></i>Email <span class="required">*</span></label>
                                    <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-user"></i>First Name</label>
                                    <input type="text" name="firstName" class="form-input" value="<?php echo htmlspecialchars($user['firstName']??''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-user"></i>Last Name</label>
                                    <input type="text" name="lastName" class="form-input" value="<?php echo htmlspecialchars($user['lastName']??''); ?>">
                                </div>
                                <div class="form-group" style="grid-column:1/-1;">
                                    <label class="form-label"><i class="fas fa-car"></i>Driver ID</label>
                                    <input type="text" name="driverId" class="form-input" value="<?php echo htmlspecialchars($user['driverId']??''); ?>" placeholder="Leave empty if not a driver">
                                </div>
                            </div>
                        </div>

                        <!-- Contact & Address -->
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-map-marker-alt"></i>Contact & Address</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-phone"></i>Phone Number</label>
                                    <input type="text" name="phone_number" class="form-input" value="<?php echo htmlspecialchars($user['phone_number']??''); ?>" placeholder="e.g. +1-555-0100">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-city"></i>City</label>
                                    <input type="text" name="city" class="form-input" value="<?php echo htmlspecialchars($user['city']??''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-map"></i>State</label>
                                    <input type="text" name="state" class="form-input" value="<?php echo htmlspecialchars($user['state']??''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-globe"></i>Country</label>
                                    <input type="text" name="country" class="form-input" value="<?php echo htmlspecialchars($user['country']??''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-mail-bulk"></i>Zipcode</label>
                                    <input type="text" name="zipcode" class="form-input" value="<?php echo htmlspecialchars($user['zipcode']??''); ?>">
                                </div>
                                <div class="form-group" style="grid-column:1/-1;">
                                    <label class="form-label"><i class="fas fa-home"></i>Address</label>
                                    <input type="text" name="address_field" class="form-input" value="<?php echo htmlspecialchars($user['address']??''); ?>" placeholder="Street address">
                                </div>
                            </div>
                        </div>

                        <!-- Account Settings -->
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-sliders-h"></i>Account Settings</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-user-tag"></i>User Type</label>
                                    <!-- ✅ Dynamic roles from DB (prt=0 wale) -->
                                    <select name="userType" class="form-input">
                                        <option value="">-- Select Type --</option>
                                        <?php foreach($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" <?php echo ($user['userType']??'')==$role['id']?'selected':''; ?>>
                                            <?php echo htmlspecialchars(ucwords(str_replace('_',' ',$role['role_name']))); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-toggle-on"></i>Status</label>
                                    <select name="status" class="form-input">
                                        <option value="active"   <?php echo ($user['status']??'')==='active'  ?'selected':''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($user['status']??'')==='inactive'?'selected':''; ?>>Inactive</option>
                                        <option value="banned"   <?php echo ($user['status']??'')==='banned'  ?'selected':''; ?>>Banned</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Organization Search -->
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-building"></i>Organization Info</div>
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-search"></i>Search & Change Organization</label>
                                <div class="organization-search-wrapper">
                                    <input type="text" id="schoolSearch" class="form-input"
                                        placeholder="Type organization name to search..."
                                        autocomplete="off"
                                        value="<?php echo htmlspecialchars($user['organization_name']??''); ?>">
                                    <span id="schoolSpinner" style="display:none;position:absolute;right:14px;top:50%;transform:translateY(-50%);">
                                        <span style="display:inline-block;width:13px;height:13px;border:2px solid #94a3b8;border-top-color:#0000FF;border-radius:50%;animation:spin 0.7s linear infinite;"></span>
                                    </span>
                                    <div class="organization-dropdown" id="schoolDropdown">
                                        <div class="organization-dd-header">🏫 Organizations in database</div>
                                        <div id="schoolDropdownBody"></div>
                                    </div>
                                </div>
                                <div class="organization-selected-badge <?php echo !empty($user['organization_name'])?'show':''; ?>" id="schoolSelectedBadge">
                                    <span>✅</span>
                                    <span id="schoolSelectedText"><?php echo !empty($user['organization_name'])?htmlspecialchars($user['organization_name']).' | Org ID: '.htmlspecialchars($user['org_custom_id'] ?? $user['organization_id'] ?? ''):''; ?></span>
                                    <button type="button" class="badge-clear" id="clearSchool">✕ Clear</button>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-id-badge"></i>Organization ID</label>
                                    <input type="text" id="schoolIdDisplay" class="form-input organization-readonly" readonly
                                        value="<?php echo htmlspecialchars($user['org_custom_id']??''); ?>" placeholder="Auto-filled">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-building"></i>Organization Name</label>
                                    <input type="text" id="schoolNameDisplay" class="form-input organization-readonly" readonly
                                        value="<?php echo htmlspecialchars($user['organization_name']??''); ?>" placeholder="Auto-filled">
                                </div>
                            </div>
                            <input type="hidden" name="organization_id"   id="school_id_hidden"   value="<?php echo htmlspecialchars($user['organization_id']??''); ?>">
                            <input type="hidden" name="organization_name" id="school_name_hidden" value="<?php echo htmlspecialchars($user['organization_name']??''); ?>">
                        </div>

                        <!-- Password -->
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-lock"></i>Change Password</div>
                            <div class="alert alert-warning"><i class="fas fa-info-circle"></i><div><strong>Note:</strong> Leave empty to keep current password.</div></div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-key"></i>New Password</label>
                                    <div class="password-input">
                                        <input type="password" name="new_password" id="new_password" class="form-input" placeholder="Enter new password">
                                        <button type="button" class="toggle-password" onclick="togglePassword('new_password')"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-key"></i>Confirm Password</label>
                                    <div class="password-input">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Confirm new password">
                                        <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="users.php" class="btn btn-secondary"><i class="fas fa-times"></i>Cancel</a>
                            <button type="submit" name="update_user" class="btn btn-primary"><i class="fas fa-save"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
const schoolSearchInput   = document.getElementById('schoolSearch');
const schoolDropdown      = document.getElementById('schoolDropdown');
const schoolDropdownBody  = document.getElementById('schoolDropdownBody');
const schoolSpinner       = document.getElementById('schoolSpinner');
const schoolSelectedBadge = document.getElementById('schoolSelectedBadge');
const schoolSelectedText  = document.getElementById('schoolSelectedText');
const clearSchoolBtn      = document.getElementById('clearSchool');
const schoolIdDisplay     = document.getElementById('schoolIdDisplay');
const schoolNameDisplay   = document.getElementById('schoolNameDisplay');
const schoolIdHidden      = document.getElementById('school_id_hidden');
const schoolNameHidden    = document.getElementById('school_name_hidden');

let schoolTimer = null, selectedSchool = null, highlightedIndex = -1, currentResults = [];
if (schoolIdHidden.value) selectedSchool = { id: schoolIdHidden.value, name: schoolNameHidden.value };

async function searchSchools(q) {
    try { const r = await fetch(`../organization/search_organization.php?q=${encodeURIComponent(q)}`); const d = await r.json(); return d.schools||[]; }
    catch(e) { return []; }
}

function highlightText(text, query) {
    if (!query) return text;
    return text.replace(new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'), '<span class="match-highlight">$1</span>');
}

function renderDropdown(results, query) {
    currentResults = results; highlightedIndex = -1;
    schoolDropdownBody.innerHTML = '';
    if (!results.length) {
        schoolDropdownBody.innerHTML = `<div class="organization-no-result">😕 No organization found for "<strong>${query}</strong>"</div>`;
    } else {
        results.forEach((organization, i) => {
            const div = document.createElement('div');
            div.className = 'organization-option';
            div.innerHTML = `<div class="organization-option-name">${highlightText(organization.name, query)}</div>
                <div class="organization-option-meta"><span class="organization-option-id">Org ID: ${organization.org_id || organization.id}</span><span>📍 ${organization.city||''} ${organization.state?', '+organization.state:''}</span></div>`;
            div.addEventListener('mousedown', e => { e.preventDefault(); selectSchool(organization); });
            schoolDropdownBody.appendChild(div);
        });
    }
    schoolDropdown.classList.add('open');
}

function selectSchool(organization) {
    selectedSchool = organization;
    schoolIdHidden.value    = organization.id;
    schoolNameHidden.value  = organization.name;
    schoolIdDisplay.value   = organization.org_id || organization.id;
    schoolNameDisplay.value = organization.name;
    schoolSearchInput.value = organization.name;
    schoolSelectedText.textContent = `${organization.name} | Org ID: ${organization.org_id || organization.id}${organization.city?' | '+organization.city:''}`;
    schoolSelectedBadge.classList.add('show');
    schoolDropdown.classList.remove('open');
}

function clearSchool() {
    selectedSchool = null;
    schoolIdHidden.value = schoolNameHidden.value = '';
    schoolIdDisplay.value = schoolNameDisplay.value = schoolSearchInput.value = '';
    schoolSelectedBadge.classList.remove('show');
    schoolDropdown.classList.remove('open');
}

clearSchoolBtn.addEventListener('click', clearSchool);

schoolSearchInput.addEventListener('input', function() {
    const q = this.value.trim();
    clearTimeout(schoolTimer);
    if (selectedSchool && q !== selectedSchool.name) {
        selectedSchool = null; schoolIdHidden.value = schoolNameHidden.value = '';
        schoolIdDisplay.value = schoolNameDisplay.value = '';
        schoolSelectedBadge.classList.remove('show');
    }
    if (q.length < 2) { schoolDropdown.classList.remove('open'); schoolSpinner.style.display='none'; return; }
    schoolSpinner.style.display = 'block';
    schoolTimer = setTimeout(async () => {
        const results = await searchSchools(q);
        schoolSpinner.style.display = 'none';
        renderDropdown(results, q);
    }, 300);
});

schoolSearchInput.addEventListener('keydown', function(e) {
    const opts = schoolDropdownBody.querySelectorAll('.organization-option');
    if (!opts.length) return;
    if (e.key==='ArrowDown') { e.preventDefault(); highlightedIndex=Math.min(highlightedIndex+1,opts.length-1); }
    else if (e.key==='ArrowUp') { e.preventDefault(); highlightedIndex=Math.max(highlightedIndex-1,0); }
    else if (e.key==='Enter' && highlightedIndex>=0) { e.preventDefault(); selectSchool(currentResults[highlightedIndex]); return; }
    else if (e.key==='Escape') { schoolDropdown.classList.remove('open'); return; }
    opts.forEach((o,i) => o.classList.toggle('highlighted', i===highlightedIndex));
    if (highlightedIndex>=0) opts[highlightedIndex].scrollIntoView({block:'nearest'});
});

document.addEventListener('click', e => { if (!e.target.closest('.organization-search-wrapper')) schoolDropdown.classList.remove('open'); });

function togglePassword(id) {
    const f=document.getElementById(id), i=f.parentElement.querySelector('.toggle-password i');
    f.type = f.type==='password'?'text':'password';
    i.className = f.type==='password'?'fas fa-eye':'fas fa-eye-slash';
}

setTimeout(() => { document.querySelectorAll('.alert').forEach(a => { a.style.transition='opacity 0.5s'; a.style.opacity='0'; setTimeout(()=>a.remove(),500); }); }, 5000);
</script>
</body>
</html>