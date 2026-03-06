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

// Display session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}

// Handle alert deletion
if (isset($_POST['delete_alert']) && isset($_POST['alert_id'])) {
    $deleteAlertId = intval($_POST['alert_id']);

    try {
        $stmt = $conn->prepare("DELETE FROM alerts WHERE id = ?");
        $stmt->bind_param("i", $deleteAlertId);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = 'Alert deleted successfully.';
            $_SESSION['messageType'] = 'success';
        } else {
            $_SESSION['message'] = 'Alert not found.';
            $_SESSION['messageType'] = 'error';
        }
        $stmt->close();
    } catch (Exception $e) {
        logAppError("Alert deletion error: " . $e->getMessage());
        $_SESSION['message'] = 'Error deleting alert. Please try again.';
        $_SESSION['messageType'] = 'error';
    }

    $redirect_url = 'alert.php';
    if (isset($_GET['page'])) {
        $redirect_url .= '?page=' . intval($_GET['page']);
    }
    if (isset($_GET['search'])) {
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'search=' . urlencode($_GET['search']);
    }
    header("Location: $redirect_url");
    exit();
}

// Initialize
$alerts = [];
$totalAlerts = 0;

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $whereClause = '';
    $params = [];
    $types = '';

    if (!empty($search)) {
        $whereClause = "WHERE type LIKE ? OR message LIKE ? OR latitude LIKE ? OR longitude LIKE ?";
        $searchTerm = '%' . $search . '%';
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
        $types = 'ssss';
    }

    // Total count
    $countSql = "SELECT COUNT(*) as total FROM alerts $whereClause";
    if ($whereClause) {
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalAlerts = $result->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $result = $conn->query($countSql);
        $totalAlerts = $result->fetch_assoc()['total'];
    }

    // Stats
    $statsResult = $conn->query("SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN type='geo-fence' THEN 1 END) as geo_fence,
        COUNT(CASE WHEN type='sos' THEN 1 END) as sos,
        COUNT(CASE WHEN type='delay' THEN 1 END) as delay,
        COUNT(CASE WHEN type='other' THEN 1 END) as other
        FROM alerts");
    $statsData = $statsResult ? $statsResult->fetch_assoc() : [];

    // Fetch alerts
    $sql = "SELECT id, latitude, longitude, type, message, created_at FROM alerts $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";

    if ($whereClause) {
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $alerts[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    logAppError("Alerts page error: " . $e->getMessage());
    $error = 'Unable to load alerts data';
}

$totalPages = ceil($totalAlerts / $limit);

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        try {
            $stmt = $conn->prepare("UPDATE user_login SET token = NULL WHERE user_id = ?");
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
    <title>Alerts - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            line-height: 1.6;
        }

        .app-container { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            height: 100vh;
            left: 0; top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar.collapsed { transform: translateX(-100%); }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
        }

        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
        .sidebar-logo h2 { font-size: 1.3rem; font-weight: 700; }

        .sidebar-user {
            margin-top: 1rem; padding: 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: 12px; backdrop-filter: blur(10px);
        }

        .sidebar-user .user-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 16px; margin-bottom: 0.5rem;
        }

        .sidebar-user h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem; }
        .sidebar-user p { font-size: 0.85rem; opacity: 0.8; }

        .sidebar-nav { padding: 1rem 0; }
        .nav-section { margin-bottom: 2rem; }

        .nav-item {
            display: flex; padding: 0.75rem 1.5rem;
            color: #4a5568; text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            align-items: center; gap: 12px;
        }

        .nav-item:hover, .nav-item.active {
            background: #f7fafc; color: #0000FF; border-left-color: #0000FF;
        }

        .nav-item i { width: 20px; text-align: center; font-size: 1.1rem; }
        .nav-item .nav-text { flex: 1; }

        /* Main Wrapper */
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }
        .main-wrapper.expanded { margin-left: 0; }

        /* Header */
        .header {
            background: white; border-bottom: 1px solid #e2e8f0;
            padding: 1rem 2rem; position: sticky; top: 0; z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .header-left { display: flex; align-items: center; gap: 1rem; }

        .menu-toggle {
            background: none; border: none; font-size: 1.2rem;
            color: #4a5568; cursor: pointer; padding: 8px; border-radius: 8px;
            transition: all 0.3s ease; display: none;
        }

        .menu-toggle:hover { background: #f7fafc; color: #0000FF; }

        .breadcrumb { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 0.9rem; }
        .breadcrumb a { color: #0000FF; text-decoration: none; }

        .header-actions { display: flex; align-items: center; gap: 1rem; }

        .logout-btn {
            background: #dc3545; color: white; border: none;
            padding: 8px 16px; border-radius: 8px; font-weight: 500;
            cursor: pointer; transition: all 0.3s ease; text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }

        .logout-btn:hover { background: #c82333; transform: translateY(-1px); }

        /* Main Content */
        .main-content { padding: 2rem; }

        .page-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;
        }

        .page-title h1 { font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.5rem; }
        .page-title p { color: #718096; font-size: 1rem; }

        .page-actions { display: flex; gap: 1rem; align-items: center; }

        .search-box { position: relative; min-width: 300px; }

        .search-input {
            width: 100%; padding: 12px 16px 12px 44px;
            border: 1px solid #e2e8f0; border-radius: 12px;
            background: white; font-size: 0.95rem; transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none; border-color: #0000FF;
            box-shadow: 0 0 0 3px rgba(0,0,255,0.1);
        }

        .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9ca3af; }

        /* Message */
        .message-container { margin-bottom: 2rem; }

        .message {
            padding: 1rem 1.5rem; border-radius: 12px;
            display: flex; align-items: center; gap: 12px;
            font-weight: 500; animation: slideIn 0.3s ease;
        }

        .message.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .message.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* Alerts Card */
        .alerts-card {
            background: white; border-radius: 16px;
            border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem; border-bottom: 1px solid #e2e8f0;
            background: #f8fafc; display: flex;
            justify-content: space-between; align-items: center;
        }

        .card-title {
            font-size: 1.25rem; font-weight: 600; color: #1a202c;
            display: flex; align-items: center; gap: 8px;
        }

        .alerts-count {
            background: #0000FF; color: white;
            padding: 4px 12px; border-radius: 20px;
            font-size: 0.85rem; font-weight: 600;
        }

        .alerts-table { width: 100%; border-collapse: collapse; }

        .alerts-table th, .alerts-table td {
            padding: 16px; text-align: left; border-bottom: 1px solid #e2e8f0;
        }

        .alerts-table th {
            background: #f8fafc; font-weight: 600; color: #4a5568;
            font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;
        }

        .alerts-table td { font-size: 0.95rem; }
        .alerts-table tbody tr:hover { background: #f8fafc; }

        /* Alert Type Badge */
        .type-badge {
            padding: 5px 12px; border-radius: 20px;
            font-size: 0.75rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
        }

        .type-geo-fence { background: #dbeafe; color: #1e40af; }
        .type-sos { background: #fee2e2; color: #991b1b; }
        .type-delay { background: #fef3c7; color: #92400e; }
        .type-other { background: #f3f4f6; color: #374151; }

        .date-text { color: #4a5568; font-size: 0.85rem; white-space: nowrap; }
        .text-muted { color: #9ca3af; }

        .coord-text { font-size: 0.82rem; color: #4a5568; font-family: monospace; }

        .message-text {
            max-width: 250px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
            font-size: 0.9rem; color: #374151;
        }

        /* Actions */
        .actions { display: flex; gap: 8px; align-items: center; }

        .btn-sm {
            padding: 6px 12px; border-radius: 8px; font-size: 0.8rem;
            border: none; cursor: pointer; transition: all 0.3s ease;
            text-decoration: none; display: flex; align-items: center; gap: 4px;
        }

        .btn-view { background: #f0f9ff; color: #0369a1; }
        .btn-view:hover { background: #0369a1; color: white; }

        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }

        /* Delete Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: none; align-items: center; justify-content: center;
            z-index: 2000;
        }

        .modal-overlay.active { display: flex; }

        .modal {
            background: white; border-radius: 16px; padding: 2rem;
            max-width: 450px; width: 90%;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            animation: scaleIn 0.3s ease;
        }

        .modal-header { display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem; }

        .modal-header .modal-icon {
            width: 48px; height: 48px; border-radius: 50%;
            background: #fee2e2; color: #dc2626;
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
        }

        .modal-header h3 { font-size: 1.4rem; font-weight: 600; color: #1a202c; }

        .modal-body { margin-bottom: 2rem; color: #4a5568; line-height: 1.6; }

        .alert-highlight {
            background: #f3f4f6; padding: 1rem; border-radius: 8px;
            margin: 1rem 0; border-left: 4px solid #dc2626;
        }

        .modal-actions { display: flex; gap: 1rem; justify-content: flex-end; }

        .btn-cancel {
            background: #f3f4f6; color: #4a5568; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 500;
            cursor: pointer; transition: all 0.3s ease;
        }

        .btn-cancel:hover { background: #e5e7eb; }

        .btn-confirm-delete {
            background: #dc2626; color: white; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 500;
            cursor: pointer; transition: all 0.3s ease;
        }

        .btn-confirm-delete:hover { background: #b91c1c; }

        /* Pagination */
        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 8px; margin-top: 2rem;
        }

        .pagination a, .pagination span {
            padding: 8px 12px; border-radius: 8px; text-decoration: none;
            color: #4a5568; font-weight: 500; transition: all 0.3s ease;
        }

        .pagination a:hover { background: #f7fafc; color: #0000FF; }
        .pagination .current { background: #0000FF; color: white; }
        .pagination .disabled { opacity: 0.5; cursor: not-allowed; }

        /* Empty State */
        .empty-state { text-align: center; padding: 4rem 2rem; color: #718096; }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.5; }
        .empty-state h3 { font-size: 1.25rem; margin-bottom: 0.5rem; color: #4a5568; }

        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 999; display: none;
        }

        .sidebar-overlay.active { display: block; }

        /* Stats Summary */
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem; margin-top: 3rem;
        }

        .stat-item {
            background: white; padding: 1.5rem; border-radius: 12px;
            border: 1px solid #e2e8f0; display: flex;
            align-items: center; gap: 1rem; transition: all 0.3s ease;
        }

        .stat-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        .stat-item .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; color: white;
        }

        .stat-item h4 { font-size: 1.5rem; font-weight: 700; color: #1a202c; margin-bottom: 0.2rem; }
        .stat-item p { color: #718096; font-size: 0.85rem; margin: 0; }

        /* View Modal */
        .view-modal .modal { max-width: 550px; }
        .view-detail { display: flex; flex-direction: column; gap: 0.8rem; }
        .view-row { display: flex; gap: 1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.8rem; }
        .view-label { font-weight: 600; color: #4a5568; min-width: 110px; font-size: 0.9rem; }
        .view-value { color: #1a202c; font-size: 0.9rem; word-break: break-all; }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .menu-toggle { display: block; }
            .page-actions { flex-direction: column; }
            .search-box { min-width: auto; }
        }

        @media (max-width: 768px) {
            .header, .main-content { padding: 1rem; }
            .alerts-table th, .alerts-table td { padding: 10px 8px; }
            .stats-summary { grid-template-columns: 1fr 1fr; margin-top: 1.5rem; }
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
    </style>
</head>
<body>
<div class="app-container">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
    <!-- Sidebar -->
    <!-- <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="/schoolAdmin/driver.tracker/icon/schooladmin.jpg" alt="Logo">
                <h2>Organization Admin</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($username, 0, 2)); ?>
                </div>
                <h3><?php echo htmlspecialchars($username); ?></h3>
                <p>ID: <?php echo htmlspecialchars($driver_id ?? 'N/A'); ?></p>
            </div>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section">
                <a href="dashboard.php" class="nav-item <?php echo isActivePage('dashboard.php'); ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="users.php" class="nav-item <?php echo isActivePage('users.php'); ?>">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Users</span>
                </a>
                <a href="profile.php" class="nav-item <?php echo isActivePage('profile.php'); ?>">
                    <i class="fas fa-user-circle"></i>
                    <span class="nav-text">Profile</span>
                </a>
                <a href="organization.php" class="nav-item <?php echo isActivePage('organization.php'); ?>">
                    <i class="fas fa-organization"></i>
                    <span class="nav-text">Organization</span>
                </a>
                <a href="children.php" class="nav-item <?php echo isActivePage('children.php'); ?>">
                    <i class="fas fa-child"></i>
                    <span class="nav-text">Children</span>
                </a>
                <a href="alert.php" class="nav-item <?php echo isActivePage('alert.php'); ?>">
                    <i class="fas fa-bell"></i>
                    <span class="nav-text">Alerts</span>
                </a>
            </div>
        </div>
    </nav> -->

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
                        <span>Alerts</span>
                    </div>
                </div>
                <div class="header-actions">
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
            <div class="page-header">
                <div class="page-title">
                    <h1>Alerts Management</h1>
                    <p>View and manage all system alerts — geo-fence, SOS, delay, and other notifications</p>
                </div>
                <div class="page-actions">
                    <form method="GET" class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input
                            type="text"
                            name="search"
                            class="search-input"
                            placeholder="Search by type, message, location..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                        <?php if(isset($_GET['page'])): ?>
                            <input type="hidden" name="page" value="<?php echo $_GET['page']; ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Alerts Table -->
            <div class="alerts-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-bell"></i>
                        All Alerts
                    </h3>
                    <span class="alerts-count"><?php echo number_format($totalAlerts); ?> alerts</span>
                </div>

                <?php if (!empty($alerts)): ?>
                <table class="alerts-table">
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Latitude</th>
                            <th>Longitude</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $alert): ?>
                        <tr>
                            <td><strong>#<?php echo $alert['id']; ?></strong></td>
                            <td>
                                <?php
                                $typeMap = [
                                    'geo-fence' => ['class' => 'type-geo-fence', 'icon' => 'fa-map-marker-alt', 'label' => 'Geo-Fence'],
                                    'sos'       => ['class' => 'type-sos',       'icon' => 'fa-exclamation-triangle', 'label' => 'SOS'],
                                    'delay'     => ['class' => 'type-delay',     'icon' => 'fa-clock', 'label' => 'Delay'],
                                    'other'     => ['class' => 'type-other',     'icon' => 'fa-info-circle', 'label' => 'Other'],
                                ];
                                $t = strtolower($alert['type'] ?? 'other');
                                $td = $typeMap[$t] ?? $typeMap['other'];
                                ?>
                                <span class="type-badge <?php echo $td['class']; ?>">
                                    <i class="fas <?php echo $td['icon']; ?>"></i>
                                    <?php echo $td['label']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="message-text" title="<?php echo htmlspecialchars($alert['message'] ?? ''); ?>">
                                    <?php echo !empty($alert['message']) ? htmlspecialchars($alert['message']) : '<span class="text-muted">—</span>'; ?>
                                </div>
                            </td>
                            <td class="coord-text">
                                <?php echo !empty($alert['latitude']) ? htmlspecialchars($alert['latitude']) : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="coord-text">
                                <?php echo !empty($alert['longitude']) ? htmlspecialchars($alert['longitude']) : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="date-text">
                                <?php echo !empty($alert['created_at']) ? date('M j, Y g:i A', strtotime($alert['created_at'])) : '—'; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <button
                                        class="btn-sm btn-view"
                                        onclick="showViewModal(
                                            <?php echo $alert['id']; ?>,
                                            '<?php echo addslashes(htmlspecialchars($alert['type'] ?? '')); ?>',
                                            '<?php echo addslashes(htmlspecialchars($alert['message'] ?? '')); ?>',
                                            '<?php echo addslashes($alert['latitude'] ?? ''); ?>',
                                            '<?php echo addslashes($alert['longitude'] ?? ''); ?>',
                                            '<?php echo addslashes(!empty($alert['created_at']) ? date('M j, Y g:i A', strtotime($alert['created_at'])) : '—'); ?>'
                                        )"
                                    >
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button
                                        class="btn-sm btn-delete"
                                        onclick="showDeleteModal(<?php echo $alert['id']; ?>, '<?php echo addslashes(htmlspecialchars($alert['type'] ?? 'Alert')); ?>')"
                                    >
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No alerts found</h3>
                    <?php if (!empty($search)): ?>
                        <p>No alerts match your search "<?php echo htmlspecialchars($search); ?>"</p>
                        <a href="alert.php" style="margin-top:1rem; display:inline-flex; align-items:center; gap:8px; background:#0000FF; color:white; padding:10px 18px; border-radius:10px; text-decoration:none; font-weight:600;">
                            <i class="fas fa-arrow-left"></i> Show All Alerts
                        </a>
                    <?php else: ?>
                        <p>No alerts have been recorded yet.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo ($page-1); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1): ?>
                    <a href="?page=1<?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">1</a>
                    <?php if ($start > 2): ?><span>...</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?>
                    <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo ($page+1); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Stats Summary -->
            <div class="stats-summary">
                <div class="stat-item">
                    <div class="stat-icon" style="background: linear-gradient(135deg,#0000FF,#4169E1);">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <h4><?php echo number_format($statsData['total'] ?? 0); ?></h4>
                        <p>Total Alerts</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon" style="background: linear-gradient(135deg,#1e40af,#3b82f6);">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <h4><?php echo number_format($statsData['geo_fence'] ?? 0); ?></h4>
                        <p>Geo-Fence</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon" style="background: linear-gradient(135deg,#dc2626,#ef4444);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h4><?php echo number_format($statsData['sos'] ?? 0); ?></h4>
                        <p>SOS Alerts</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon" style="background: linear-gradient(135deg,#b45309,#f59e0b);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <h4><?php echo number_format($statsData['delay'] ?? 0); ?></h4>
                        <p>Delay Alerts</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon" style="background: linear-gradient(135deg,#374151,#6b7280);">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div>
                        <h4><?php echo number_format($statsData['other'] ?? 0); ?></h4>
                        <p>Other</p>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <h3>Delete Alert</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this alert? This action cannot be undone.</p>
            <div class="alert-highlight">
                <strong>Alert ID:</strong> #<span id="deleteAlertId"></span><br>
                <strong>Type:</strong> <span id="deleteAlertType"></span>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Cancel</button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="alert_id" id="deleteAlertIdInput">
                <button type="submit" name="delete_alert" class="btn-confirm-delete">Delete Alert</button>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal-overlay view-modal" id="viewModal">
    <div class="modal" style="max-width:550px;">
        <div class="modal-header">
            <div class="modal-icon" style="background:#dbeafe; color:#1e40af;">
                <i class="fas fa-bell"></i>
            </div>
            <h3>Alert Details</h3>
        </div>
        <div class="modal-body">
            <div class="view-detail">
                <div class="view-row">
                    <span class="view-label">Alert ID</span>
                    <span class="view-value" id="viewId"></span>
                </div>
                <div class="view-row">
                    <span class="view-label">Type</span>
                    <span class="view-value" id="viewType"></span>
                </div>
                <div class="view-row">
                    <span class="view-label">Message</span>
                    <span class="view-value" id="viewMessage"></span>
                </div>
                <div class="view-row">
                    <span class="view-label">Latitude</span>
                    <span class="view-value" id="viewLatitude"></span>
                </div>
                <div class="view-row">
                    <span class="view-label">Longitude</span>
                    <span class="view-value" id="viewLongitude"></span>
                </div>
                <div class="view-row" style="border:none; padding:0;">
                    <span class="view-label">Created At</span>
                    <span class="view-value" id="viewCreatedAt"></span>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="hideViewModal()">Close</button>
        </div>
    </div>
</div>

<script>
    // Sidebar
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    menuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
    });

    sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        }
    });

    // Search auto-submit
    const searchInput = document.querySelector('.search-input');
    let searchTimeout;
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => this.form.submit(), 500);
    });

    // Delete Modal
    function showDeleteModal(id, type) {
        document.getElementById('deleteAlertId').textContent = id;
        document.getElementById('deleteAlertIdInput').value = id;
        document.getElementById('deleteAlertType').textContent = type;
        document.getElementById('deleteModal').classList.add('active');
    }

    function hideDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    // View Modal
    function showViewModal(id, type, message, lat, lng, createdAt) {
        document.getElementById('viewId').textContent = '#' + id;
        document.getElementById('viewType').textContent = type;
        document.getElementById('viewMessage').textContent = message || '—';
        document.getElementById('viewLatitude').textContent = lat || '—';
        document.getElementById('viewLongitude').textContent = lng || '—';
        document.getElementById('viewCreatedAt').textContent = createdAt;
        document.getElementById('viewModal').classList.add('active');
    }

    function hideViewModal() {
        document.getElementById('viewModal').classList.remove('active');
    }

    // Close modals on outside click or ESC
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hideDeleteModal();
            hideViewModal();
        }
    });

    // Auto-hide messages
    const msgContainer = document.querySelector('.message-container');
    if (msgContainer) {
        setTimeout(() => {
            msgContainer.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => msgContainer.remove(), 300);
        }, 5000);
    }
</script>
</body>
</html>