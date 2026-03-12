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

// Initialize dashboard data
$dashboardData = [
    'total_trips' => 0,
    'total_distance' => 0,
    'total_earnings' => 0,
    'avg_rating' => 0,
    'recent_trips' => [],
    'monthly_stats' => []
];

try {
    // You can customize these queries based on your actual database schema
    // For now, I'll create sample queries that you can modify
    
    // Get total trips (assuming you have a trips table)
    // $stmt = $conn->prepare("SELECT COUNT(*) as total_trips FROM trips WHERE driver_id = ?");
    // $stmt->bind_param("s", $driver_id);
    // $stmt->execute();
    // $result = $stmt->get_result();
    // $dashboardData['total_trips'] = $result->fetch_assoc()['total_trips'];
    // $stmt->close();
    
    // For demo purposes, using sample data
    $dashboardData['total_trips'] = 156;
    $dashboardData['total_distance'] = 2847.5;
    $dashboardData['total_earnings'] = 15640.75;
    $dashboardData['avg_rating'] = 4.7;
    
    // Sample recent trips data
    $dashboardData['recent_trips'] = [
        ['id' => 'T001', 'date' => '2025-06-23', 'from' => 'Downtown', 'to' => 'Airport', 'distance' => 25.5, 'fare' => 450.00, 'status' => 'Completed'],
        ['id' => 'T002', 'date' => '2025-06-22', 'from' => 'Mall', 'to' => 'Railway Station', 'distance' => 12.8, 'fare' => 280.00, 'status' => 'Completed'],
        ['id' => 'T003', 'date' => '2025-06-22', 'from' => 'Hotel', 'to' => 'Bus Stand', 'distance' => 8.2, 'fare' => 180.00, 'status' => 'Completed'],
        ['id' => 'T004', 'date' => '2025-06-21', 'from' => 'Hospital', 'to' => 'Market', 'distance' => 6.5, 'fare' => 150.00, 'status' => 'Completed'],
        ['id' => 'T005', 'date' => '2025-06-21', 'from' => 'Organization', 'to' => 'Park', 'distance' => 4.2, 'fare' => 120.00, 'status' => 'Completed']
    ];
    
} catch (Exception $e) {
    logAppError("Dashboard data error: " . $e->getMessage());
    $dashboardData = ['error' => 'Unable to load dashboard data'];
}

// Handle logout
if (isset($_GET['logout'])) {
    // Clear remember me cookie
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        
        // Remove remember token from database
        try {
            $stmt = $conn->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            logAppError("Logout error: " . $e->getMessage());
        }
    }
    
    // Destroy session
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
    <title>Dashboard - <?php echo APP_NAME; ?></title>
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
            max-width: 1200px;
            margin: 0 auto;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo img {
            width: 40px;
            height: 40px;
            border-radius: 8px;
        }

        .logo h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0000FF;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: #f7fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-details h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #2d3748;
        }

        .user-details p {
            font-size: 0.8rem;
            color: #718096;
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .welcome-section {
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="1.5" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="80" r="1" fill="rgba(255,255,255,0.1)"/></svg>');
            opacity: 0.3;
        }

        .welcome-content {
            position: relative;
            z-index: 1;
        }

        .welcome-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .welcome-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #0000FF, #4169E1);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 255, 0.15);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.9rem;
            color: #718096;
            font-weight: 500;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-icon.trips {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .stat-icon.distance {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .stat-icon.earnings {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .stat-icon.rating {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.85rem;
            color: #10b981;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .content-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-content {
            padding: 1.5rem;
        }

        /* Recent Trips Table */
        .trips-table {
            width: 100%;
            border-collapse: collapse;
        }

        .trips-table th,
        .trips-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .trips-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .trips-table td {
            font-size: 0.9rem;
        }

        .trip-id {
            font-weight: 600;
            color: #0000FF;
        }

        .trip-route {
            color: #4a5568;
        }

        .trip-status {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            background: #d1fae5;
            color: #065f46;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 1rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: #0000FF;
            color: white;
            transform: translateX(4px);
        }

        .action-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: #0000FF;
        }

        .action-btn:hover .action-icon {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
            }

            .main-content {
                padding: 0 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .user-menu {
                width: 100%;
                justify-content: space-between;
            }

            .welcome-title {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 640px) {
            .trips-table {
                font-size: 0.8rem;
            }

            .trips-table th,
            .trips-table td {
                padding: 8px 4px;
            }

            .stat-value {
                font-size: 1.5rem;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background: #10b981;
        }

        .notification.error {
            background: #ef4444;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="https://erphub.ai/driver.tracker/icon/ic_logo.png" alt="Logo">
                <h1>Driver Tracker</h1>
            </div>
            <div class="user-menu">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($username, 0, 2)); ?>
                    </div>
                    <div class="user-details">
                        <h3><?php echo htmlspecialchars($username); ?></h3>
                        <p>ID: <?php echo htmlspecialchars($driver_id ?? 'N/A'); ?></p>
                    </div>
                </div>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <div class="welcome-content">
                <h2 class="welcome-title">Welcome back, <?php echo htmlspecialchars($username); ?>!</h2>
                <p class="welcome-subtitle">Here's your driving summary for today. Keep up the great work!</p>
            </div>
        </div>

    </main>

    <script>
        // Auto-refresh dashboard data every 5 minutes
        setInterval(() => {
            location.reload();
        }, 300000);

        // Add click handlers for action buttons
        document.addEventListener('DOMContentLoaded', function() {
            const actionBtns = document.querySelectorAll('.action-btn');
            actionBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const action = this.querySelector('div div').textContent;
                    showNotification(`${action} - Feature coming soon!`, 'info');
                });
            });
        });

        // Notification system
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Session timeout warning
        let sessionTimer;
        function resetSessionTimer() {
            clearTimeout(sessionTimer);
            sessionTimer = setTimeout(() => {
                if (confirm('Your session will expire in 5 minutes. Click OK to extend your session.')) {
                    // Make an AJAX call to extend session
                    fetch('extend_session.php', { method: 'POST' })
                        .then(() => resetSessionTimer())
                        .catch(() => window.location.href = '<?php echo LOGIN_PAGE; ?>');
                } else {
                    window.location.href = '<?php echo LOGIN_PAGE; ?>';
                }
            }, <?php echo (SESSION_LIFETIME - 300) * 1000; ?>); // 5 minutes before expiry
        }

        // Initialize session timer
        resetSessionTimer();

        // Reset timer on user activity
        ['click', 'keypress', 'mousemove'].forEach(event => {
            document.addEventListener(event, resetSessionTimer);
        });
    </script>
</body>
</html>