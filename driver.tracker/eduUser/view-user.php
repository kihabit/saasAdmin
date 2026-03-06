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

// Get logged-in user info
$logged_user_id = $_SESSION['user_id'];
$logged_username = $_SESSION['username'];

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'User ID not provided.');
    redirect('users.php');
}

$view_user_id = intval($_GET['id']);

// Fetch user details
$user = null;
try {
   $stmt = $conn->prepare("SELECT user_id, driverId, username, firstName, lastName, email, created_at, last_login, school_id, school_name FROM user_login WHERE user_id = ?");
    $stmt->bind_param("i", $view_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        setFlashMessage('error', 'User not found.');
        redirect('users.php');
    }
} catch (Exception $e) {
    logAppError("View user error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading user details.');
    redirect('users.php');
}

// Fetch orders if user has driverId
$orders = [];
$ordersError = '';
if (!empty($user['driverId'])) {
    try {
        // Orders API endpoint se data fetch karna
        $ordersApiUrl = "http://yourdomain.com/api/orders.php?driverId=" . urlencode($user['driverId']);
        
        // Alternative: Direct database query (recommended for same server)
        $stmt = $conn->prepare("SELECT * FROM Orders WHERE driverId = ? ORDER BY orderDate DESC, orderId DESC");
        $stmt->bind_param("s", $user['driverId']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        
        $stmt->close();
    } catch (Exception $e) {
        logAppError("Fetch orders error: " . $e->getMessage());
        $ordersError = 'Unable to load orders data.';
    }
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
    <title>View User - <?php echo htmlspecialchars($user['username']); ?></title>
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

        /* User Profile Card */
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

        .profile-username {
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

        .driver-badge {
            display: inline-block;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Orders Section */
        .orders-section {
            margin-top: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .orders-count {
            background: #0000FF;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .order-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .order-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .order-id {
            font-weight: 700;
            color: #0000FF;
            font-size: 1.1rem;
        }

        .order-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-received {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .order-detail {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: start;
            gap: 8px;
        }

        .order-detail i {
            color: #0000FF;
            margin-top: 4px;
        }

        .order-detail-text {
            flex: 1;
            color: #4a5568;
            font-size: 0.9rem;
        }

        .order-detail-label {
            font-weight: 600;
            color: #1a202c;
        }

        .order-signature {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .signature-img {
            width: 100%;
            max-width: 200px;
            height: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
            background: #f8fafc;
        }

        .no-orders {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            color: #718096;
        }

        .no-orders i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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

            .orders-grid {
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
       <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <!-- Main Wrapper -->
        <div class="main-wrapper">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="breadcrumb">
                        <a href="<?php echo BASE_URL;?>dashboard.php">Home</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="<?php echo BASE_URL;?>users.php">Users</a>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                    <div class="header-actions">
                        <a href="<?php echo BASE_URL;?>users.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i>
                            Back to Users
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
                <!-- User Profile Card -->
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php 
                            $initials = '';
                            if (!empty($user['firstName']) && !empty($user['lastName'])) {
                                $initials = strtoupper(substr($user['firstName'], 0, 1) . substr($user['lastName'], 0, 1));
                            } else {
                                $initials = strtoupper(substr($user['username'], 0, 2));
                            }
                            echo $initials;
                            ?>
                        </div>
                        <h1 class="profile-name">
                            <?php 
                            if (!empty($user['firstName']) && !empty($user['lastName'])) {
                                echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']);
                            } else {
                                echo htmlspecialchars($user['username']);
                            }
                            ?>
                        </h1>
                        <p class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></p>
                    </div>

                    <div class="profile-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-envelope"></i>
                                    Email Address
                                </div>
                                <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-car"></i>
                                    Driver ID
                                </div>
                                <div class="info-value">
                                    <?php if (!empty($user['driverId'])): ?>
                                        <span class="driver-badge"><?php echo htmlspecialchars($user['driverId']); ?></span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">Not a driver</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-calendar-plus"></i>
                                    Joined Date
                                </div>
                                <div class="info-value"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-clock"></i>
                                    Last Login
                                </div>
                                <div class="info-value">
                                    <?php if (!empty($user['last_login'])): ?>
                                        <?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">Never logged in</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-circle"></i>
                                    Status
                                </div>
                                <div class="info-value">
                                    <?php if (!empty($user['last_login'])): ?>
                                        <span class="status-badge status-active">Active</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
<div class="info-item">
    <div class="info-label">
        <i class="fas fa-id-card"></i>
        User ID
    </div>
    <div class="info-value">#<?php echo htmlspecialchars($user['user_id']); ?></div>
</div>

<?php if (!empty($user['school_id'])): ?>
<div class="info-item">
    <div class="info-label">
        <i class="fas fa-organization"></i>
        Organization ID
    </div>
    <div class="info-value"><?php echo htmlspecialchars($user['school_id']); ?></div>
</div>
<?php endif; ?>

<?php if (!empty($user['school_name'])): ?>
<div class="info-item">
    <div class="info-label">
        <i class="fas fa-organization"></i>
        Organization Name
    </div>
    <div class="info-value"><?php echo htmlspecialchars($user['school_name']); ?></div>
</div>
<?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Orders Section (Only if driver) -->
                <?php if (!empty($user['driverId'])): ?>
                <div class="orders-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-box"></i>
                            Orders
                            <span class="orders-count"><?php echo count($orders); ?></span>
                        </h2>
                    </div>

                    <?php if (!empty($orders)): ?>
                        <div class="orders-grid">
                            <?php foreach ($orders as $order): ?>
                            <div class="order-card">
                                <div class="order-header">
                                    <div class="order-id">Order #<?php echo htmlspecialchars($order['orderId']); ?></div>
                                    <span class="order-status <?php echo !empty($order['recived']) ? 'status-received' : 'status-pending'; ?>">
                                        <?php echo !empty($order['recived']) ? 'Received' : 'Pending'; ?>
                                    </span>
                                </div>

                                <div class="order-detail">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <div class="order-detail-text">
                                        <div class="order-detail-label">Address:</div>
                                       <?php
                                        $street  = $order['Street']  ?? '';
                                        $city    = $order['City']    ?? '';
                                        $state   = $order['State']   ?? '';
                                        $zipcode = $order['Zipcode'] ?? '';
                                        $country = $order['Country'] ?? '';
                                        
                                        // Combine all non-empty parts
                                        $addressParts = array_filter([$street, $city, $state, $zipcode, $country]);
                                        
                                        // Join with comma
                                        $fullAddress = implode(', ', $addressParts);
                                        
                                        // Print safely
                                        echo htmlspecialchars($fullAddress);
                                        ?>

                                    </div>
                                </div>

                                <div class="order-detail">
                                    <i class="fas fa-user"></i>
                                    <div class="order-detail-text">
                                        <div class="order-detail-label">Customer Name:</div>
                                        <?php echo htmlspecialchars($order['CustomerName']); ?>
                                    </div>
                                </div>

                               <div class="order-detail">
    <i class="fas fa-calendar"></i>
    <div class="order-detail-text">
        <div class="order-detail-label">Order Date:</div>
        <?php echo !empty($order['orderDate']) ? date('M j, Y', strtotime($order['orderDate'])) : 'N/A'; ?>
    </div>
</div>

<?php if (!empty($order['updatedDate']) && $order['updatedDate'] != '0000-00-00 00:00:00'): ?>
<div class="order-detail">
    <i class="fas fa-sync-alt"></i>
    <div class="order-detail-text">
        <div class="order-detail-label">Updated:</div>
        <?php echo date('M j, Y g:i A', strtotime($order['updatedDate'])); ?>
    </div>
</div>
<?php endif; ?>

                                <?php if (!empty($order['CustomerSignPath'])): ?>
                                <div class="order-signature">
                                    <div class="order-detail-label" style="margin-bottom: 8px;">
                                        <i class="fas fa-signature"></i> Customer Signature:
                                    </div>
                                    <img src="<?php echo htmlspecialchars($order['CustomerSignPath']); ?>" alt="Signature" class="signature-img">
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-orders">
                            <i class="fas fa-box-open"></i>
                            <h3>No Orders Found</h3>
                            <p>This driver hasn't received any orders yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>