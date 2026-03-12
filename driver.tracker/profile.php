<?php
session_start();

// Include configuration file
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect(LOGIN_PAGE);
}

// Check session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please login again.');
    redirect(LOGIN_PAGE);
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Get database connection
$db = Database::getInstance();
$conn = $db->getConnection();

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$driver_id = $_SESSION['driver_id'];
$email = $_SESSION['email'];

// Initialize variables
$message = '';
$messageType = '';
$userData = [];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = trim($_POST['firstName']);
    $lastName = trim($_POST['lastName']);
    $updateEmail = trim($_POST['email']);
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    try {
        // Validate email
        if (!filter_var($updateEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }
        
        // Check if email already exists for other users
        $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE email = ? AND user_id != ?");
        $stmt->bind_param("si", $updateEmail, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception('Email already exists.');
        }
        $stmt->close();
        
        $conn->begin_transaction();
        
        // Update basic info
        $stmt = $conn->prepare("UPDATE user_login SET firstName = ?, lastName = ?, email = ? WHERE user_id = ?");
        $stmt->bind_param("sssi", $firstName, $lastName, $updateEmail, $user_id);
        $stmt->execute();
        $stmt->close();
        
        // Update password if provided
        if (!empty($newPassword)) {
            // Verify current password
            $stmt = $conn->prepare("SELECT password_hash FROM user_login WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
      if (md5(trim($currentPassword)) !== $row['password_hash']) {
    throw new Exception('Current password is incorrect.');
     }
            
            if ($newPassword !== $confirmPassword) {
                throw new Exception('New passwords do not match.');
            }
            
            if (strlen($newPassword) < 6) {
                throw new Exception('New password must be at least 6 characters.');
            }
            
$hashedPassword = md5(trim($newPassword));
$stmt = $conn->prepare("UPDATE user_login SET password_hash = ? WHERE user_id = ?");
$stmt->bind_param("si", $hashedPassword, $user_id);
$stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        
        // Update session
        $_SESSION['email'] = $updateEmail;
        
        logAppError("Profile updated: User ID $user_id by: $username");
        
        $_SESSION['message'] = 'Profile updated successfully!';
        $_SESSION['messageType'] = 'success';
        
        header("Location: profile.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        logAppError("Profile update error: " . $e->getMessage());
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['messageType'] = 'error';
        header("Location: profile.php");
        exit();
    }
}

// Display session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}

// Get user data
try {
    $stmt = $conn->prepare("SELECT user_id, driverId, username, firstName, lastName, email, status, created_at, last_login FROM user_login WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    logAppError("Profile page error: " . $e->getMessage());
    $error = 'Unable to load profile data';
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        
        try {
            $stmt = $conn->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            logAppError("Logout error: " . $e->getMessage());
        }
    }
    
    session_unset();
    session_destroy();
    redirect(LOGIN_PAGE);
}

$db->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            line-height: 1.6;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        /* .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
        } */

        /* .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo img {
            width: 36px;
            height: 36px;
            border-radius: 8px;
        }

        .sidebar-logo h2 {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .sidebar-user {
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .sidebar-user .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 0.5rem;
        }

        .sidebar-user h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .sidebar-user p {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-item:hover,
        .nav-item.active {
            background: #f7fafc;
            color: #0000FF;
            border-left-color: #0000FF;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        } */

        /* Main Wrapper */
        /* .main-wrapper {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        } */

        .main-wrapper.expanded {
            margin-left: 0;
        }

        /* Header */
        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #4a5568;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: none;
        }

        .menu-toggle:hover {
            background: #f7fafc;
            color: #0000FF;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #718096;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: #0000FF;
            text-decoration: none;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-btn {
            position: relative;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            padding: 8px;
            border-radius: 8px;
            color: #4a5568;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .notification-btn:hover {
            background: #0000FF;
            color: white;
        }

        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }

        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        /* Main Content */
        .main-content {
            padding: 1rem;
            max-width: 1000px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 1rem;
        }

        .page-header h1 {
            font-size: 1rem;
            color: #1a202c;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #718096;
            font-size: 1rem;
        }

        /* Message */
        .message-container {
            margin-bottom: 2rem;
        }

        .message {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        .message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Profile Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 1rem;
        }

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            height: fit-content;
        }

        .profile-header {
            background: linear-gradient(135deg, #0000FF, #4169E1);
           padding: 5px;
            text-align: center;
            color: white;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 3rem;
            font-weight: 700;
            border: 4px solid rgba(255, 255, 255, 0.3);
        }

        .profile-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .profile-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .profile-info {
            padding: 2rem;
        }

        .info-item {
            padding: .25rem 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item i {
            width: 40px;
            height: 40px;
            background: #f0f9ff;
            color: #0000FF;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 1.1rem;
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.85rem;
            color: #718096;
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 600;
            color: #1a202c;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        /* Edit Form */
        .edit-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 15px;
        }

        .card-header {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-header i {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .form-section {
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: .8rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: .5rem;
        }

        .form-group {
            margin-bottom: .8rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.8rem;
        }

        .form-group input {
            width: 100%;
            padding: 4px 9px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:focus {
            outline: none;
            border-color: #0000FF;
            box-shadow: 0 0 0 3px rgba(0, 0, 255, 0.1);
        }

        .form-group input:disabled {
            background: #f3f4f6;
            cursor: not-allowed;
        }

        .password-input {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #718096;
            cursor: pointer;
            padding: 4px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: #0000FF;
            color: white;
        }

        .btn-primary:hover {
            background: #0000CC;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .alert-box {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 4px 4px;
            margin-bottom: .8rem;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
            font-size: 10px;
        }

        .alert-box i {
            font-size: 1.5rem;
        }

        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .header {
                padding: 1rem;
            }
        }

        /* Animations */
        @keyframes slideIn {
            from { 
                transform: translateX(100%); 
                opacity: 0; 
            }
            to { 
                transform: translateX(0); 
                opacity: 1; 
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Wrapper -->
        <div class="main-wrapper" id="mainWrapper">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="header-left">
                        <button class="menu-toggle" id="menuToggle">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="breadcrumb">
                            <a href="<?php echo BASE_URL;?>dashboard.php">Home</a>
                            <i class="fas fa-chevron-right"></i>
                            <span>Profile</span>
                        </div>
                    </div>
                    <div class="header-actions">
                        <!--<button class="notification-btn">-->
                        <!--    <i class="fas fa-bell"></i>-->
                        <!--    <span class="notification-badge">3</span>-->
                        <!--</button>-->
                        <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Message -->
                <?php if (!empty($message)): ?>
                <div class="message-container">
                    <div class="message <?php echo $messageType; ?>">
                        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Page Header -->
                <!-- <div class="page-header">
                    <h4>My Profile</h4>
                    <p>Manage your account information and settings</p>
                </div> -->

                <!-- Profile Grid -->
                <div class="profile-grid">
                    <!-- Profile Card -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <!-- <div class="profile-avatar">
                                <?php 
                                if (!empty($userData['firstName']) && !empty($userData['lastName'])) {
                                    echo strtoupper(substr($userData['firstName'], 0, 1) . substr($userData['lastName'], 0, 1));
                                } else {
                                    echo strtoupper(substr($username, 0, 2));
                                }
                                ?>
                            </div> -->
                            <h4>
                                <?php 
                                if (!empty($userData['firstName']) && !empty($userData['lastName'])) {
                                    echo htmlspecialchars($userData['firstName'] . ' ' . $userData['lastName']);
                                } else {
                                    echo htmlspecialchars($username);
                                }
                                ?>
                            </h4>
                            <p>@<?php echo htmlspecialchars($username); ?></p>
                        </div>

                        <div class="profile-info">
                            <div class="info-item">
                                <i class="fas fa-envelope"></i>
                                <div class="info-content">
                                    <div class="info-label">Email Address</div>
                                    <div class="info-value"><?php echo htmlspecialchars($userData['email']); ?></div>
                                </div>
                            </div>

                            <div class="info-item">
                                <i class="fas fa-car"></i>
                                <div class="info-content">
                                    <div class="info-label">Admin ID</div>
                                    <div class="info-value">
                                        <?php echo !empty($userData['driverId']) ? htmlspecialchars($userData['driverId']) : 'Not Assigned'; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="info-item">
                                <i class="fas fa-check-circle"></i>
                                <div class="info-content">
                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">
                                        <span class="status-badge status-active">
                                            <?php echo $userData['status'] == 1 ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="info-item">
                                <i class="fas fa-calendar-alt"></i>
                                <div class="info-content">
                                    <div class="info-label">Member Since</div>
                                    <div class="info-value"><?php echo date('F j, Y', strtotime($userData['created_at'])); ?></div>
                                </div>
                            </div>

                            <div class="info-item">
                                <i class="fas fa-clock"></i>
                                <div class="info-content">
                                    <div class="info-label">Last Login</div>
                                    <div class="info-value">
                                        <?php 
                                        if (!empty($userData['last_login'])) {
                                            echo date('M j, Y g:i A', strtotime($userData['last_login']));
                                        } else {
                                            echo 'Never';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Form -->
                    <div class="edit-card">
                        <div class="card-header">
                            <h3>
                                <i class="fas fa-edit"></i>
                                Edit Profile
                            </h3>
                        </div>

                        <form method="POST" action="profile.php">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <!-- <div class="section-title">Basic Information</div> -->
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="firstName">First Name</label>
                                        <input 
                                            type="text" 
                                            id="firstName" 
                                            name="firstName" 
                                            value="<?php echo htmlspecialchars($userData['firstName'] ?? ''); ?>"
                                            required
                                        >
                                    </div>
                                    <div class="form-group">
                                        <label for="lastName">Last Name</label>
                                        <input 
                                            type="text" 
                                            id="lastName" 
                                            name="lastName" 
                                            value="<?php echo htmlspecialchars($userData['lastName'] ?? ''); ?>"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input 
                                        type="text" 
                                        id="username" 
                                        value="<?php echo htmlspecialchars($userData['username']); ?>"
                                        disabled
                                    >
                                </div>

                                <div class="form-group">
                                    <label for="email">Email Address</label>
                                    <input 
                                        type="email" 
                                        id="email" 
                                        name="email" 
                                        value="<?php echo htmlspecialchars($userData['email']); ?>"
                                        required
                                    >
                                </div>

                                <div class="form-group">
                                    <label for="driverId">Driver ID</label>
                                    <input 
                                        type="text" 
                                        id="driverId" 
                                        value="<?php echo htmlspecialchars($userData['driverId'] ?? 'Not Assigned'); ?>"
                                        disabled
                                    >
                                </div>
                            </div>

                            <!-- Change Password -->
                            <div class="form-section">
                                <div class="section-title">Change Password</div>
                                
                                <div class="alert-box">
                                    <i class="fas fa-info-circle"></i>
                                    <div>
                                        <strong>Note:</strong> Leave password fields empty if you don't want to change your password.
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <div class="password-input">
                                        <input 
                                            type="password" 
                                            id="current_password" 
                                            name="current_password"
                                            placeholder="Enter current password"
                                        >
                                        <button type="button" class="toggle-password" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="new_password">New Password</label>
                                        <div class="password-input">
                                            <input 
                                                type="password" 
                                                id="new_password" 
                                                name="new_password"
                                                placeholder="Enter new password"
                                            >
                                            <button type="button" class="toggle-password" onclick="togglePassword('new_password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="confirm_password">Confirm New Password</label>
                                        <div class="password-input">
                                            <input 
                                                type="password" 
                                                id="confirm_password" 
                                                name="confirm_password"
                                                placeholder="Confirm new password"
                                            >
                                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo"></i>
                                    Reset
                                </button>
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });

        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 1024) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                }
            });
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            }
        });

        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset the form?')) {
                location.reload();
            }
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const currentPassword = document.getElementById('current_password').value;

            // If any password field is filled
            if (newPassword || confirmPassword || currentPassword) {
                // Check if all password fields are filled
                if (!currentPassword) {
                    e.preventDefault();
                    alert('Please enter your current password to change password.');
                    return;
                }
                
                if (!newPassword) {
                    e.preventDefault();
                    alert('Please enter a new password.');
                    return;
                }
                
                if (!confirmPassword) {
                    e.preventDefault();
                    alert('Please confirm your new password.');
                    return;
                }

                // Check if passwords match
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New passwords do not match!');
                    return;
                }

                // Check password length
                if (newPassword.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters long.');
                    return;
                }
            }
        });

        // Auto-hide messages after 5 seconds
        const messageContainer = document.querySelector('.message-container');
        if (messageContainer) {
            setTimeout(() => {
                messageContainer.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => {
                    messageContainer.remove();
                }, 300);
            }, 5000);
        }

        // Add animation for slideOut
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideOut {
                from { 
                    transform: translateX(0); 
                    opacity: 1; 
                }
                to { 
                    transform: translateX(100%); 
                    opacity: 0; 
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>