<?php
session_start();
require_once 'config.php';

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

// Handle order update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $order_id   = intval($_POST['order_id']);
    $address    = trim($_POST['address']);
    $driverName = trim($_POST['driverName']);
    $orderDate  = !empty($_POST['orderDate']) ? trim($_POST['orderDate']) : null;
    $recived    = isset($_POST['recived']) ? 1 : 0;
    try {
        $stmt = $conn->prepare("SELECT address FROM Orders WHERE id = ?");
        $stmt->bind_param("i", $order_id); $stmt->execute();
        $oldOrder = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($oldOrder && $oldOrder['address'] !== $address) $recived = 0;
    } catch (Exception $e) { logAppError("Check address: ".$e->getMessage()); }
    $updateErrors = [];
    if (empty($address))    $updateErrors[] = 'Address is required.';
    if (empty($driverName)) $updateErrors[] = 'Driver name is required.';
    if (empty($updateErrors)) {
        try {
            $stmt = $conn->prepare("UPDATE Orders SET address=?,driverName=?,orderDate=?,recived=?,updatedDate=NOW() WHERE id=?");
            $stmt->bind_param("sssii",$address,$driverName,$orderDate,$recived,$order_id);
            $_SESSION['flash_message'] = $stmt->execute() ? 'Order #'.$order_id.' updated!' : 'Failed to update.';
            $_SESSION['flash_type']    = $stmt->execute() ? 'success' : 'error';
            $stmt->close();
        } catch (Exception $e) { $_SESSION['flash_message']='Error updating order.'; $_SESSION['flash_type']='error'; }
    } else { $_SESSION['flash_message']=implode(' ',$updateErrors); $_SESSION['flash_type']='error'; }
    redirect('edit_user.php?id='.$view_user_id);
}

// Handle order delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    $order_id = intval($_POST['order_id']);
    try {
        $stmt = $conn->prepare("DELETE FROM Orders WHERE id=?");
        $stmt->bind_param("i",$order_id);
        $_SESSION['flash_message'] = $stmt->execute() ? 'Order deleted!' : 'Failed to delete.';
        $_SESSION['flash_type']    = 'success';
        $stmt->close();
    } catch (Exception $e) { $_SESSION['flash_message']='Error deleting order.'; $_SESSION['flash_type']='error'; }
    redirect('edit_user.php?id='.$view_user_id);
}

// ── Handle user update ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $firstName       = trim($_POST['firstName']       ?? '');
    $lastName        = trim($_POST['lastName']        ?? '');
    $email           = trim($_POST['email']           ?? '');
    $username        = trim($_POST['username']        ?? '');
    $driverId        = trim($_POST['driverId']        ?? '');
    $newPassword     = $_POST['new_password']    ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // ✅ Organization fields
    $school_id   = !empty($_POST['school_id'])   ? intval($_POST['school_id']) : null;
    $school_name = !empty($_POST['school_name']) ? trim($_POST['school_name']) : null;

    $userErrors = [];
    if (empty($username)) $userErrors[] = 'Username is required.';
    if (empty($email))    $userErrors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $userErrors[] = 'Invalid email format.';

    if (empty($userErrors)) {
        $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE username=? AND user_id!=?");
        $stmt->bind_param("si",$username,$view_user_id); $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $userErrors[] = 'Username already exists.';
        $stmt->close();
    }
    if (empty($userErrors)) {
        $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE email=? AND user_id!=?");
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
            // ✅ school_id aur school_name bhi update
            $stmt = $conn->prepare("UPDATE user_login SET username=?,firstName=?,lastName=?,email=?,driverId=?,school_id=?,school_name=? WHERE user_id=?");
            $stmt->bind_param("sssssisi",$username,$firstName,$lastName,$email,$driverId,$school_id,$school_name,$view_user_id);
            $stmt->execute(); $stmt->close();

            if (!empty($newPassword)) {
                $hashed = md5(trim($newPassword));
                $stmt = $conn->prepare("UPDATE user_login SET password_hash=? WHERE user_id=?");
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

// ── Fetch user ────────────────────────────────────────────────────────────
$user = null;
try {
    // ✅ school_id aur school_name bhi fetch
    $stmt = $conn->prepare("SELECT user_id,driverId,username,firstName,lastName,email,created_at,last_login,school_id,school_name FROM user_login WHERE user_id=?");
    $stmt->bind_param("i",$view_user_id); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user) { setFlashMessage('error','User not found.'); redirect('users.php'); }
} catch (Exception $e) { setFlashMessage('error','Error loading user.'); redirect('users.php'); }

// ── Fetch orders ──────────────────────────────────────────────────────────
$orders = [];
if (!empty($user['driverId'])) {
    try {
        $stmt = $conn->prepare("SELECT * FROM Orders WHERE driverId=? ORDER BY orderDate DESC, orderId DESC");
        $stmt->bind_param("s",$user['driverId']); $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $orders[] = $row;
        $stmt->close();
    } catch (Exception $e) { logAppError("Fetch orders: ".$e->getMessage()); }
}

// ── Logout ────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login','',time()-3600,COOKIE_PATH,COOKIE_DOMAIN,COOKIE_SECURE,COOKIE_HTTPONLY);
        try { $stmt=$conn->prepare("DELETE FROM user_remember_tokens WHERE user_id=?"); $stmt->bind_param("i",$logged_user_id); $stmt->execute(); $stmt->close(); } catch(Exception $e){}
    }
    session_unset(); session_destroy(); redirect(LOGIN_PAGE);
}

$flashMessage = $flashType = '';
if (isset($_SESSION['flash_message'])) {
    $flashMessage = is_array($_SESSION['flash_message']) ? implode(' ',$_SESSION['flash_message']) : $_SESSION['flash_message'];
    $flashType    = $_SESSION['flash_type'] ?? 'error';
    unset($_SESSION['flash_message'],$_SESSION['flash_type']);
}
$db->close();
?>
<!DOCTYPE html>
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
        .app-container{display:flex;min-height:100vh;}
        .sidebar{width:280px;background:white;border-right:1px solid #e2e8f0;position:fixed;height:100vh;left:0;top:0;z-index:1000;overflow-y:auto;transition:transform 0.3s ease;}
        .sidebar-header{padding:1.5rem;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#0000FF,#4169E1);color:white;}
        .sidebar-logo{display:flex;align-items:center;gap:12px;}
        .sidebar-logo img{width:36px;height:36px;border-radius:8px;}
        .sidebar-logo h2{font-size:1.3rem;font-weight:700;}
        .sidebar-user{margin-top:1rem;padding:1rem;background:rgba(255,255,255,0.1);border-radius:12px;backdrop-filter:blur(10px);}
        .sidebar-user .user-avatar{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:600;font-size:16px;margin-bottom:0.5rem;}
        .sidebar-nav{padding:1rem 0;}
        .nav-item{display:flex;padding:0.75rem 1.5rem;color:#4a5568;text-decoration:none;transition:all 0.3s;border-left:3px solid transparent;align-items:center;gap:12px;}
        .nav-item:hover,.nav-item.active{background:#f7fafc;color:#0000FF;border-left-color:#0000FF;}
        .nav-item i{width:20px;text-align:center;}
        .main-wrapper{flex:1;margin-left:280px;}
        .header{background:white;border-bottom:1px solid #e2e8f0;padding:1rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
        .header-content{display:flex;justify-content:space-between;align-items:center;}
        .breadcrumb{display:flex;align-items:center;gap:8px;color:#718096;font-size:0.9rem;}
        .breadcrumb a{color:#0000FF;text-decoration:none;}
        .header-actions{display:flex;align-items:center;gap:1rem;}
        .btn-back{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;padding:8px 16px;border-radius:8px;text-decoration:none;display:flex;align-items:center;gap:8px;font-weight:500;transition:all 0.3s;}
        .btn-back:hover{background:#e2e8f0;}
        .logout-btn{background:#dc3545;color:white;border:none;padding:8px 16px;border-radius:8px;font-weight:500;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:8px;transition:all 0.3s;}
        .logout-btn:hover{background:#c82333;}
        .main-content{padding:2rem;max-width:1400px;margin:0 auto;}
        .alert{padding:1rem 1.5rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:12px;font-weight:500;animation:slideIn 0.3s ease;}
        .alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
        .alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
        .alert-warning{background:#fef3c7;border:1px solid #fbbf24;color:#92400e;}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0;}to{transform:translateX(0);opacity:1;}}
        .edit-card{background:white;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:2rem;}
        .card-header{background:linear-gradient(135deg,#0000FF,#4169E1);color:white;padding:1.5rem 2rem;display:flex;align-items:center;gap:12px;}
        .card-header h2{font-size:1.5rem;font-weight:700;}
        .card-body{padding:2rem;}
        .form-section{margin-bottom:2rem;padding-bottom:2rem;border-bottom:1px solid #e2e8f0;}
        .form-section:last-of-type{border-bottom:none;margin-bottom:0;padding-bottom:0;}
        .section-title{font-size:1.1rem;font-weight:600;color:#1a202c;margin-bottom:1.5rem;padding-bottom:0.5rem;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:8px;}
        .section-title i{color:#0000FF;}
        .form-group{margin-bottom:1.5rem;}
        .form-label{display:flex;margin-bottom:0.5rem;font-weight:600;color:#1a202c;align-items:center;gap:8px;}
        .form-label i{color:#0000FF;}
        .required{color:#dc3545;}
        .form-input,.form-textarea{width:100%;padding:0.75rem 1rem;border:1px solid #e2e8f0;border-radius:8px;font-size:1rem;transition:all 0.3s;font-family:inherit;}
        .form-textarea{resize:vertical;min-height:100px;}
        .form-input:focus,.form-textarea:focus{outline:none;border-color:#0000FF;box-shadow:0 0 0 3px rgba(0,0,255,0.1);}
        .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.5rem;}
        .password-input{position:relative;}
        .toggle-password{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#718096;cursor:pointer;padding:4px;font-size:1.1rem;}
        .toggle-password:hover{color:#0000FF;}
        .form-actions{display:flex;gap:1rem;justify-content:flex-end;padding-top:1.5rem;border-top:1px solid #e2e8f0;margin-top:1.5rem;}
        .btn{padding:0.75rem 1.5rem;border-radius:8px;font-weight:600;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;gap:8px;border:none;font-size:1rem;text-decoration:none;}
        .btn-primary{background:#0000FF;color:white;}
        .btn-primary:hover{background:#0000CC;transform:translateY(-2px);}
        .btn-secondary{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;}
        .btn-secondary:hover{background:#e2e8f0;}
        .btn-danger{background:#dc2626;color:white;}
        .btn-danger:hover{background:#b91c1c;}
        .btn-warning{background:#f59e0b;color:white;}
        .btn-warning:hover{background:#d97706;}
        .btn-sm{padding:6px 12px;font-size:0.85rem;}
        .info-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;margin-bottom:2rem;}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;}
        .info-item{display:flex;flex-direction:column;gap:4px;}
        .info-label{font-size:0.85rem;color:#718096;font-weight:500;}
        .info-value{font-size:0.95rem;font-weight:600;color:#1a202c;}
        .orders-section{margin-top:2rem;}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .orders-count{background:#0000FF;color:white;padding:4px 12px;border-radius:20px;font-size:0.85rem;font-weight:600;}
        .orders-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(350px,1fr));gap:1.5rem;}
        .order-card{background:white;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;transition:all 0.3s;}
        .order-card:hover{transform:translateY(-4px);box-shadow:0 8px 16px rgba(0,0,0,0.1);}
        .order-header{display:flex;justify-content:space-between;align-items:start;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #e2e8f0;}
        .order-id{font-weight:700;color:#0000FF;font-size:1.1rem;}
        .order-status{padding:4px 12px;border-radius:20px;font-size:0.75rem;font-weight:600;}
        .status-received{background:#d1fae5;color:#065f46;}
        .status-pending{background:#fef3c7;color:#92400e;}
        .order-detail{margin-bottom:0.75rem;}
        .order-detail-label{font-weight:600;color:#4a5568;font-size:0.85rem;margin-bottom:4px;display:flex;align-items:center;gap:6px;}
        .order-detail-label i{color:#0000FF;}
        .order-detail-text{color:#1a202c;font-size:0.95rem;padding-left:22px;}
        .order-actions{display:flex;gap:8px;margin-top:1rem;padding-top:1rem;border-top:1px solid #e2e8f0;}
        .order-signature{margin-top:1rem;padding-top:1rem;border-top:1px solid #e2e8f0;}
        .signature-img{width:100%;max-width:200px;height:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px;background:#f8fafc;}
        .no-orders{background:white;border:1px solid #e2e8f0;border-radius:12px;padding:3rem;text-align:center;color:#718096;}
        .no-orders i{font-size:3rem;margin-bottom:1rem;opacity:0.5;}
        .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:2000;}
        .modal-overlay.active{display:flex;}
        .modal{background:white;border-radius:16px;padding:2rem;max-width:600px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);}
        .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .modal-title{font-size:1.5rem;font-weight:700;color:#1a202c;display:flex;align-items:center;gap:12px;}
        .modal-title i{color:#0000FF;}
        .modal-close{background:none;border:none;font-size:1.5rem;color:#9ca3af;cursor:pointer;padding:4px;line-height:1;transition:color 0.3s;}
        .modal-close:hover{color:#1a202c;}
        .form-checkbox{display:flex;align-items:center;gap:8px;margin-top:0.5rem;}
        .form-checkbox input{width:20px;height:20px;cursor:pointer;}

        /* ══ Organization Search ══ */
        .organization-search-wrapper{position:relative;}
        .organization-dropdown{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:white;border:1.5px solid #0000FF;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,255,0.12);z-index:500;max-height:250px;overflow-y:auto;}
        .organization-dropdown.open{display:block;}
        .organization-dd-header{padding:6px 12px;font-size:0.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;border-bottom:1px solid #f1f5f9;background:#f8fafc;border-radius:10px 10px 0 0;}
        .organization-option{padding:10px 14px;cursor:pointer;transition:background 0.15s;border-bottom:1px solid #f1f5f9;display:flex;flex-direction:column;gap:2px;}
        .organization-option:last-child{border-bottom:none;border-radius:0 0 10px 10px;}
        .organization-option:hover,.organization-option.highlighted{background:#eff6ff;}
        .organization-option-name{font-size:0.88rem;font-weight:600;color:#1a202c;}
        .organization-option-meta{font-size:0.74rem;color:#94a3b8;display:flex;gap:8px;}
        .organization-option-id{background:#dbeafe;color:#1d4ed8;padding:1px 6px;border-radius:4px;font-size:0.68rem;font-weight:700;}
        .organization-no-result{padding:14px;text-align:center;color:#94a3b8;font-size:0.84rem;}
        .organization-selected-badge{display:none;align-items:center;gap:8px;margin-top:6px;padding:6px 10px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;border-radius:8px;font-size:0.76rem;color:#15803d;font-weight:500;}
        .organization-selected-badge.show{display:flex;}
        .badge-clear{margin-left:auto;cursor:pointer;color:#15803d;font-size:12px;opacity:0.7;background:none;border:none;}
        .badge-clear:hover{opacity:1;}
        .match-highlight{color:#0000FF;font-weight:700;}
        .organization-readonly{background:#f0fdf4!important;cursor:not-allowed;color:#15803d;font-weight:600;}
        @keyframes spin{to{transform:rotate(360deg);}}

        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.main-wrapper{margin-left:0}.form-grid{grid-template-columns:1fr}.orders-grid{grid-template-columns:1fr}}
        @media(max-width:768px){.main-content{padding:1rem}.card-body{padding:1.5rem}.form-actions,.order-actions{flex-direction:column}}
    </style>
</head>
<body>
<div class="app-container">
    <!-- <nav class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="/schoolAdmin/driver.tracker/icon/schooladmin.jpg" alt="Logo">
                <h2>Organization Admin</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar"><?php echo strtoupper(substr($logged_username,0,2)); ?></div>
                <h3><?php echo htmlspecialchars($logged_username); ?></h3>
            </div>
        </div>
        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="users.php" class="nav-item active"><i class="fas fa-users"></i>Users</a>
            <a href="profile.php" class="nav-item"><i class="fas fa-user-circle"></i>Profile</a>
            <a href="organization.php" class="nav-item"><i class="fas fa-organization"></i>Organization</a>
            <a href="children.php" class="nav-item"><i class="fas fa-child"></i>Children</a>
             <a href="alert.php" class="nav-item"><i class="fas fa-child"></i>Alert</a>
        </div>
    </nav> -->
<?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-wrapper">
        <header class="header">
            <div class="header-content">
                <div class="breadcrumb">
                    <a href="dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="users.php">Users</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Edit <?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <div class="header-actions">
                    <a href="users.php" class="btn-back"><i class="fas fa-arrow-left"></i>Back to Users</a>
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
                    <?php if(!empty($user['school_name'])): ?>
                    <div class="info-item">
                        <span class="info-label">Current Organization</span>
                        <span class="info-value" style="color:#0000FF;">🏫 <?php echo htmlspecialchars($user['school_name']); ?> (ID: <?php echo $user['school_id']; ?>)</span>
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

                        <!-- ✅ SCHOOL SEARCH SECTION -->
                        <div class="form-section">
                            <div class="section-title"><i class="fas fa-organization"></i>Organization Info</div>

                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-search"></i>Search & Change Organization</label>
                                <div class="organization-search-wrapper">
                                    <input type="text" id="schoolSearch" class="form-input"
                                        placeholder="Type organization name to search..."
                                        autocomplete="off"
                                        value="<?php echo htmlspecialchars($user['school_name']??''); ?>">
                                    <span id="schoolSpinner" style="display:none;position:absolute;right:14px;top:50%;transform:translateY(-50%);">
                                        <span style="display:inline-block;width:14px;height:14px;border:2px solid #94a3b8;border-top-color:#0000FF;border-radius:50%;animation:spin 0.7s linear infinite;"></span>
                                    </span>
                                    <div class="organization-dropdown" id="schoolDropdown">
                                        <div class="organization-dd-header">🏫 Organizations in database</div>
                                        <div id="schoolDropdownBody"></div>
                                    </div>
                                </div>
                                <div class="organization-selected-badge <?php echo !empty($user['school_name'])?'show':''; ?>" id="schoolSelectedBadge">
                                    <span>✅</span>
                                    <span id="schoolSelectedText"><?php echo !empty($user['school_name'])?htmlspecialchars($user['school_name']).' | ID: '.$user['school_id']:''; ?></span>
                                    <button type="button" class="badge-clear" id="clearSchool">✕ Clear</button>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-id-badge"></i>Organization ID</label>
                                    <input type="text" id="schoolIdDisplay" class="form-input organization-readonly" readonly
                                        value="<?php echo htmlspecialchars($user['school_id']??''); ?>" placeholder="Auto-filled">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-organization"></i>Organization Name</label>
                                    <input type="text" id="schoolNameDisplay" class="form-input organization-readonly" readonly
                                        value="<?php echo htmlspecialchars($user['school_name']??''); ?>" placeholder="Auto-filled">
                                </div>
                            </div>

                            <input type="hidden" name="school_id"   id="school_id_hidden"   value="<?php echo htmlspecialchars($user['school_id']??''); ?>">
                            <input type="hidden" name="school_name" id="school_name_hidden" value="<?php echo htmlspecialchars($user['school_name']??''); ?>">
                        </div>
                        <!-- ✅ END SCHOOL SECTION -->

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

            <!-- Orders -->
            <?php if (!empty($user['driverId'])): ?>
            <div class="orders-section">
                <div class="section-header">
                    <h2 class="section-title" style="font-size:1.5rem;font-weight:700;border:none;"><i class="fas fa-box"></i>Orders <span class="orders-count"><?php echo count($orders); ?></span></h2>
                </div>
                <?php if (!empty($orders)): ?>
                <div class="orders-grid">
                    <?php foreach ($orders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div class="order-id">Order #<?php echo htmlspecialchars($order['orderId']); ?></div>
                            <span class="order-status <?php echo !empty($order['recived'])?'status-received':'status-pending'; ?>">
                                <?php echo !empty($order['recived'])?'Received':'Pending'; ?>
                            </span>
                        </div>
                        <div class="order-detail">
                            <div class="order-detail-label"><i class="fas fa-map-marker-alt"></i>Address</div>
                            <div class="order-detail-text"><?php $ap=array_filter([$order['Street']??'',$order['City']??'',$order['State']??'',$order['Zipcode']??'',$order['Country']??'']); echo htmlspecialchars(implode(', ',$ap)); ?></div>
                        </div>
                        <div class="order-detail">
                            <div class="order-detail-label"><i class="fas fa-user"></i>Customer Name</div>
                            <div class="order-detail-text"><?php echo htmlspecialchars($order['CustomerName']??'N/A'); ?></div>
                        </div>
                        <div class="order-detail">
                            <div class="order-detail-label"><i class="fas fa-calendar"></i>Order Date</div>
                            <div class="order-detail-text"><?php echo !empty($order['orderDate'])?date('M j, Y',strtotime($order['orderDate'])):'N/A'; ?></div>
                        </div>
                        <?php if(!empty($order['CustomerSignPath'])): ?>
                        <div class="order-signature">
                            <div class="order-detail-label" style="margin-bottom:8px;"><i class="fas fa-signature"></i>Customer Signature</div>
                            <img src="<?php echo htmlspecialchars($order['CustomerSignPath']); ?>" class="signature-img" alt="Signature">
                        </div>
                        <?php endif; ?>
                        <div class="order-actions">
                            <?php if(empty($order['recived'])||$order['recived']==0): ?>
                            <button class="btn btn-warning btn-sm" onclick='editOrder(<?php echo json_encode($order,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-edit"></i>Edit Order</button>
                            <?php endif; ?>
                            <button class="btn btn-danger btn-sm" onclick="deleteOrder(<?php echo intval($order['id']); ?>)"><i class="fas fa-trash"></i>Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="no-orders"><i class="fas fa-box-open"></i><h3>No Orders Found</h3><p>This driver hasn't received any orders yet.</p></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Edit Order Modal -->
<div class="modal-overlay" id="editOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-edit"></i>Edit Order</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="order_id" id="edit_order_id">
            <div class="form-group"><label class="form-label"><i class="fas fa-map-marker-alt"></i>Address <span class="required">*</span></label><textarea name="address" id="edit_address" class="form-textarea" required></textarea></div>
            <div class="form-group"><label class="form-label"><i class="fas fa-user"></i>Driver Name <span class="required">*</span></label><input type="text" name="driverName" id="edit_driverName" class="form-input" required></div>
            <div class="form-group"><label class="form-label"><i class="fas fa-calendar"></i>Order Date</label><input type="date" name="orderDate" id="edit_orderDate" class="form-input"></div>
            <div class="form-group"><div class="form-checkbox"><input type="checkbox" name="recived" id="edit_recived" value="1"><label for="edit_recived">Mark as Received</label></div></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()"><i class="fas fa-times"></i>Cancel</button>
                <button type="submit" name="update_order" class="btn btn-primary"><i class="fas fa-save"></i>Update Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Order Modal -->
<div class="modal-overlay" id="deleteOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" style="color:#dc2626;"><i class="fas fa-exclamation-triangle"></i>Delete Order</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div style="margin-bottom:2rem;">
            <p style="color:#4a5568;margin-bottom:1rem;">Are you sure? This cannot be undone.</p>
            <div style="background:#fee2e2;padding:1rem;border-radius:8px;border-left:4px solid #dc2626;"><strong style="color:#991b1b;">Order ID: #<span id="delete_order_display_id"></span></strong></div>
        </div>
        <form method="POST">
            <input type="hidden" name="order_id" id="delete_order_id">
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()"><i class="fas fa-times"></i>Cancel</button>
                <button type="submit" name="delete_order" class="btn btn-danger"><i class="fas fa-trash"></i>Delete Order</button>
            </div>
        </form>
    </div>
</div>

<script>
// ══ SCHOOL SEARCH ═════════════════════════════════════════════════════════
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

// Initialize with existing data
if (schoolIdHidden.value) selectedSchool = { id: schoolIdHidden.value, name: schoolNameHidden.value };

async function searchSchools(q) {
    try { const r = await fetch(`search_school.php?q=${encodeURIComponent(q)}`); const d = await r.json(); return d.schools||[]; }
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
                <div class="organization-option-meta"><span class="organization-option-id">ID: ${organization.id}</span><span>📍 ${organization.city||''} ${organization.state?', '+organization.state:''}</span></div>`;
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
    schoolIdDisplay.value   = organization.id;
    schoolNameDisplay.value = organization.name;
    schoolSearchInput.value = organization.name;
    schoolSelectedText.textContent = `${organization.name} | ID: ${organization.id}${organization.city?' | '+organization.city:''}`;
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
// ══ END SCHOOL SEARCH ═════════════════════════════════════════════════════

// Order modals
function editOrder(order) {
    document.getElementById('edit_order_id').value   = order.id;
    document.getElementById('edit_address').value    = order.address;
    document.getElementById('edit_driverName').value = order.driverName;
    document.getElementById('edit_orderDate').value  = order.orderDate||'';
    document.getElementById('edit_recived').checked  = order.recived==1;
    document.getElementById('editOrderModal').classList.add('active');
}
function closeEditModal()  { document.getElementById('editOrderModal').classList.remove('active'); }
function deleteOrder(id)   { document.getElementById('delete_order_id').value=id; document.getElementById('delete_order_display_id').textContent=id; document.getElementById('deleteOrderModal').classList.add('active'); }
function closeDeleteModal(){ document.getElementById('deleteOrderModal').classList.remove('active'); }

document.getElementById('editOrderModal').addEventListener('click',  function(e){ if(e.target===this) closeEditModal(); });
document.getElementById('deleteOrderModal').addEventListener('click', function(e){ if(e.target===this) closeDeleteModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape'){ closeEditModal(); closeDeleteModal(); }});

function togglePassword(id) {
    const f=document.getElementById(id), i=f.parentElement.querySelector('.toggle-password i');
    f.type = f.type==='password'?'text':'password';
    i.className = f.type==='password'?'fas fa-eye':'fas fa-eye-slash';
}

setTimeout(() => { document.querySelectorAll('.alert').forEach(a => { a.style.transition='opacity 0.5s'; a.style.opacity='0'; setTimeout(()=>a.remove(),500); }); }, 5000);
</script>
</body>
</html>