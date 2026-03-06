<?php
session_start();

// Include configuration file
require_once '../config.php';

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

// Get logged-in user info
$logged_user_id = $_SESSION['user_id'];
$logged_username = $_SESSION['username'];

// Check if organization ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'Organization ID not provided.');
    redirect('organization.php');
}

$view_school_id = intval($_GET['id']);

// Fetch organization details
$organization = null;
try {
    $stmt = $conn->prepare("SELECT id, name, address, city, state, postal_code, phone, email, latitude, longitude, created_at FROM organization WHERE id = ?");
    $stmt->bind_param("i", $view_school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $organization = $result->fetch_assoc();
    $stmt->close();

    if (!$organization) {
        setFlashMessage('error', 'Organization not found.');
        redirect('organization.php');
    }
} catch (Exception $e) {
    logAppError("View organization error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading organization details.');
    redirect('organization.php');
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        try {
            $stmt = $conn->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            $stmt->bind_param("i", $logged_user_id);
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
    <title>View Organization - <?php echo htmlspecialchars($organization['name']); ?></title>
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

        /* Sidebar */
        .sidebar {
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

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
        }

        .sidebar-logo {
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

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            display: flex;
            padding: 0.75rem 1.5rem;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
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
        }

        /* Main Wrapper */
        .main-wrapper {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
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

        .btn-back {
            background: #f7fafc;
            color: #4a5568;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            background: #e2e8f0;
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
        }

        /* Main Content */
        .main-content {
            padding: 2rem;
        }

        /* Organization Profile Card */
        .profile-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .profile-header {
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 2.5rem;
            margin: 0 auto 1rem;
            border: 4px solid rgba(255, 255, 255, 0.3);
        }

        .profile-name {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .profile-subtitle {
            font-size: 1rem;
            opacity: 0.9;
        }

        .profile-body {
            padding: 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .info-label {
            font-size: 0.85rem;
            color: #718096;
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-wrapper {
                margin-left: 0;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .profile-header {
                padding: 1.5rem;
            }

            .profile-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="/schoolAdmin/driver.tracker/icon/schooladmin.jpg" alt="Logo">
                    <h2>Organization Admin</h2>
                </div>
                <div class="sidebar-user">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($logged_username, 0, 2)); ?>
                    </div>
                    <h3><?php echo htmlspecialchars($logged_username); ?></h3>
                </div>
            </div>

            <div class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
                <a href="users.php" class="nav-item">
                    <i class="fas fa-users"></i>
                    Users
                </a>
                <a href="profile.php" class="nav-item">
                    <i class="fas fa-user-circle"></i>
                    Profile
                </a>
                <a href="organization.php" class="nav-item active">
                    <i class="fas fa-organization"></i>
                    Organization
                </a>
                <a href="children.php" class="nav-item">
                    <i class="fas fa-child"></i>
                    Children
                </a>
                
                <a href="alert.php" class="nav-item">
                    <i class="fas fa-child"></i>
                   alert
                </a>
            </div>
        </nav>

        <!-- Main Wrapper -->
        <div class="main-wrapper">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="breadcrumb">
                        <a href="dashboard.php">Home</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="organization.php">Organizations</a>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo htmlspecialchars($organization['name']); ?></span>
                    </div>
                    <div class="header-actions">
                        <a href="organization.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i>
                            Back to Organizations
                        </a>
                        <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Organization Profile Card -->
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($organization['name'], 0, 2)); ?>
                        </div>
                        <h1 class="profile-name"><?php echo htmlspecialchars($organization['name']); ?></h1>
                        <p class="profile-subtitle">
                            <?php
                            $parts = array_filter([$organization['city'] ?? '', $organization['state'] ?? '']);
                            echo $parts ? htmlspecialchars(implode(', ', $parts)) : 'Organization Details';
                            ?>
                        </p>
                    </div>

                    <div class="profile-body">
                        <div class="info-grid">

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-id-badge"></i>
                                    Organization ID
                                </div>
                                <div class="info-value">#<?php echo htmlspecialchars($organization['id']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Address
                                </div>
                                <div class="info-value">
                                    <?php echo !empty($organization['address']) ? htmlspecialchars($organization['address']) : '<span style="color:#9ca3af;">Not provided</span>'; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-city"></i>
                                    City
                                </div>
                                <div class="info-value">
                                    <?php echo !empty($organization['city']) ? htmlspecialchars($organization['city']) : '<span style="color:#9ca3af;">Not provided</span>'; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-map"></i>
                                    State
                                </div>
                                <div class="info-value">
                                    <?php echo !empty($organization['state']) ? htmlspecialchars($organization['state']) : '<span style="color:#9ca3af;">Not provided</span>'; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-mail-bulk"></i>
                                    Postal Code
                                </div>
                                <div class="info-value">
                                    <?php echo !empty($organization['postal_code']) ? htmlspecialchars($organization['postal_code']) : '<span style="color:#9ca3af;">Not provided</span>'; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-phone"></i>
                                    Phone
                                </div>
                                <div class="info-value">
                                    <?php echo !empty($organization['phone']) ? htmlspecialchars($organization['phone']) : '<span style="color:#9ca3af;">Not provided</span>'; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-envelope"></i>
                                    Email
                                </div>
                                <div class="info-value">
                                    <?php echo !empty($organization['email']) ? htmlspecialchars($organization['email']) : '<span style="color:#9ca3af;">Not provided</span>'; ?>
                                </div>
                            </div>

                            <?php if (!empty($organization['latitude']) && !empty($organization['longitude'])): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-map-pin"></i>
                                    Coordinates
                                </div>
                                <div class="info-value">
                                    <?php echo number_format((float)$organization['latitude'], 6); ?>, <?php echo number_format((float)$organization['longitude'], 6); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-calendar-plus"></i>
                                    Registered Date
                                </div>
                                <div class="info-value"><?php echo date('M j, Y', strtotime($organization['created_at'])); ?></div>
                            </div>

                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>