<?php
session_start();
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

// Get drivers directly from user_login table (most accurate)
$driversQuery = "SELECT DISTINCT u.driverId, u.username as driverName
                 FROM user_login u
                 WHERE EXISTS (
                     SELECT 1 FROM Orders o 
                     WHERE o.driverId = u.driverId 
                       AND o.CustomerSignPath IS NOT NULL 
                       AND o.CustomerSignPath != ''
                 )
                 ORDER BY u.username ASC";

$driversResult = $conn->query($driversQuery);
$drivers = [];
if ($driversResult) {
    while ($row = $driversResult->fetch_assoc()) {
        $drivers[] = $row;
    }
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
  <title>In-Progress Orders - <?php echo APP_NAME; ?></title>
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

        /* Layout Structure */
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

        .sidebar.collapsed {
            transform: translateX(-100%);
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

        .nav-section {
            margin-bottom: 2rem;
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
        }

        .nav-item .nav-text {
            flex: 1;
        }

        /* Main Content Area */
        .main-wrapper {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        }

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
            padding: 2rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            flex: 1;
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.5rem;
        }

        .page-title p {
            color: #718096;
            font-size: 1rem;
        }

        /* Filters Section */
        .filters-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filters-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 1.5rem;
        }

        .filters-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a202c;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #0000FF;
            box-shadow: 0 0 0 3px rgba(0, 0, 255, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
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
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-item i {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
        }

        .stat-item h4 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.25rem;
        }

        .stat-item p {
            color: #718096;
            font-size: 0.9rem;
            margin: 0;
        }

        /* Orders Table */
        .orders-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loading {
            text-align: center;
            padding: 3rem;
            color: #718096;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #0000FF;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

.table-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

.orders-table th,
.orders-table td {
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
    white-space: nowrap;
}

.orders-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #4a5568;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 10;
}

.orders-table td {
    font-size: 0.95rem;
}

.orders-table tbody tr {
    transition: all 0.2s ease;
}

.orders-table tbody tr:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Customer Address column - allow wrapping if needed */
.orders-table td:nth-child(4) {
    white-space: normal;
    max-width: 300px;
}

        .order-id {
            font-weight: 600;
            color: #0000FF;
        }

        .driver-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .driver-name {
            font-weight: 600;
            color: #1a202c;
        }

        .driver-id {
            font-size: 0.85rem;
            color: #718096;
        }

        .badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    background: #dbeafe; /* Blue for in-progress */
    color: #1e40af;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .date-text {
            color: #4a5568;
            font-size: 0.9rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #718096;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: #4a5568;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 2rem;
            padding: 1.5rem;
        }

        .pagination button,
        .pagination span {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
        }

        .pagination button:hover:not(:disabled) {
            background: #0000FF;
            color: white;
            border-color: #0000FF;
        }

        .pagination button:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .pagination .page-info {
            padding: 8px 16px;
            font-weight: 600;
            background: transparent;
            border: none;
        }

        /* Sidebar Overlay for Mobile */
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

        /* Responsive Design */
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

            .page-header {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .stats-bar {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header {
                padding: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .orders-table {
                font-size: 0.85rem;
            }

            .orders-table th,
            .orders-table td {
                padding: 12px 8px;
            }
        }

        @media (max-width: 640px) {
            .sidebar {
                width: 260px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
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
        
        <a href="completed_orders.php" class="nav-item <?php echo isActivePage('completed_orders.php'); ?>">
            <i class="fas fa-check-circle"></i>
            <span class="nav-text">Completed Orders</span>
        </a>
        
        <a href="progress_orders.php" class="nav-item <?php echo isActivePage('progress_orders.php'); ?>">
            <i class="fas fa-hourglass-half"></i>
            <span class="nav-text">In-Progress Orders</span>
        </a>
        
        <a href="profile.php" class="nav-item <?php echo isActivePage('profile.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">Profile</span>
        </a>
    </div>
</div>
        </nav>

        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content Area -->
        <div class="main-wrapper" id="mainWrapper">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="header-left">
                        <button class="menu-toggle" id="menuToggle">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Home</a>
                            <i class="fas fa-chevron-right"></i>
                         <span>In-Progress Orders</span>
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
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title">
                        <h1>In-Progress Orders</h1>
<p>View and manage all in-progress delivery orders</p>
                    </div>
                </div>

                <!-- Filters Card -->
                <div class="filters-card">
                    <div class="filters-header">
                        <i class="fas fa-filter"></i>
                        <h3>Filter Orders</h3>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="fromDate"><i class="fas fa-calendar"></i> From Date</label>
                            <input type="date" id="fromDate">
                        </div>

                        <div class="filter-group">
                            <label for="toDate"><i class="fas fa-calendar"></i> To Date</label>
                            <input type="date" id="toDate">
                        </div>

<div class="filter-group">
    <label for="driverFilter"><i class="fas fa-user"></i> Driver</label>
    <select id="driverFilter">
        <option value="">All Drivers</option>
        <?php foreach ($drivers as $driver): ?>
            <option value="<?php echo htmlspecialchars($driver['driverId']); ?>">
                <?php echo htmlspecialchars($driver['driverName']) . ' - ID: ' . htmlspecialchars($driver['driverId']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
                    </div>

                    <div class="filter-actions">
                        <button class="btn btn-primary" onclick="searchOrders()">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>
                </div>

                <!-- Stats Bar -->
                <div class="stats-bar" id="statsBar" style="display: none;">
                    <div class="stat-card">
                        <div class="stat-item">
                            <i class="fas fa-clipboard-list"></i>
                            <div>
                                <h4 id="totalOrders">0</h4>
                                <p>Total Orders</p>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-item">
                            <i class="fas fa-file-alt"></i>
                            <div>
                                <h4 id="currentPageDisplay">0</h4>
                                <p>Current Page</p>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-item">
                            <i class="fas fa-copy"></i>
                            <div>
                                <h4 id="totalPages">0</h4>
                                <p>Total Pages</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading State -->
                <div class="loading" id="loading" style="display: none;">
                    <div class="spinner"></div>
                    <p>Loading completed orders...</p>
                </div>

                <!-- Empty State -->
                <div class="empty-state" id="noData" style="display: none;">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No in-progress orders found</h3>
                  <!--  <p>Try adjusting your filters or date range</p> -->
                </div>

                <!-- Orders Table -->
                <div class="orders-card" id="tableContainer" style="display: none;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-check-circle"></i>
                           In-Progress Orders
                        </h3>
                    </div>
                    <div class="table-container">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Driver</th>
                                    <th>Customer Name</th>
                                    <th>Customer Address</th>
                                    <th>Order Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTableBody"></tbody>
                        </table>

                        <div class="pagination" id="pagination">
                            <button onclick="previousPage()" id="btnPrevious">
                                <i class="fas fa-chevron-left"></i> Previous
                            </button>
                            <span class="page-info" id="pageInfo">Page 1 of 1</span>
                            <button onclick="nextPage()" id="btnNext">
                                Next <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

  <script>
        let currentPage = 1;
        let totalPages = 1;
        let totalOrders = 0;
        let lastFilters = { fromDate: '', toDate: '', driverId: '' }; // Store last search filters

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

        // Set default dates
        function setDefaultDates() {
            const today = new Date();
            const thirtyDaysAgo = new Date(today);
            thirtyDaysAgo.setDate(today.getDate() - 30);

            document.getElementById('toDate').valueAsDate = today;
            document.getElementById('fromDate').valueAsDate = thirtyDaysAgo;
        }

        async function searchOrders() {
            const fromDate = document.getElementById('fromDate').value;
            const toDate = document.getElementById('toDate').value;
            const driverId = document.getElementById('driverFilter').value;

            if (!fromDate || !toDate) {
                alert('Please select both dates');
                return;
            }

            // Reset to page 1 when new search
            currentPage = 1;
            lastFilters = { fromDate, toDate, driverId };
            await fetchOrders(fromDate, toDate, driverId);
        }

        async function fetchOrders(fromDate, toDate, driverId = '') {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('tableContainer').style.display = 'none';
            document.getElementById('noData').style.display = 'none';

            try {
let url = `https://erphub.ai/api/getProgressOrders.php?fromDate=${fromDate}&toDate=${toDate}&page=${currentPage}&limit=20`;
                if (driverId) url += `&driverId=${driverId}`;

                const response = await fetch(url);
                const data = await response.json();

                if (data.success && data.data.orders.length > 0) {
                    totalPages = data.data.totalPages;
                    totalOrders = data.data.totalOrders;
                    displayOrders(data.data.orders);
                    updatePagination();
                    updateStats();
                } else {
                    showNoData();
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to fetch orders');
            } finally {
                document.getElementById('loading').style.display = 'none';
            }
        }

      function displayOrders(orders) {
    const tbody = document.getElementById('ordersTableBody');
    tbody.innerHTML = '';

    orders.forEach(order => {
        let address = '';
        if (order.Street) address += order.Street + ', ';
        if (order.City) address += order.City + ', ';
        if (order.State) address += order.State + ' ';
        if (order.Zipcode) address += order.Zipcode + ', ';
        if (order.Country) address += order.Country;
        address = address.trim().replace(/,$/, '') || 'No Address';

        const row = tbody.insertRow();
        row.innerHTML = `
            <td><span class="order-id">${order.orderId || 'N/A'}</span></td>
            <td>
                <div class="driver-info">
                    <span class="driver-name">${order.driverName || 'N/A'}</span>
                    <span class="driver-id">ID: ${order.driverId || 'N/A'}</span>
                </div>
            </td>
            <td>${order.CustomerName || 'Unknown'}</td>
            <td>${address}</td>
            <td class="date-text">${formatDate(order.orderDate)}</td>
            <td><span class="badge"><i class="fas fa-hourglass-half"></i> In Progress</span></td>
        `;
    });

    document.getElementById('tableContainer').style.display = 'block';
}

        function showNoData() {
            document.getElementById('noData').style.display = 'block';
            document.getElementById('statsBar').style.display = 'none';
        }

        function updatePagination() {
            document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
            document.getElementById('btnPrevious').disabled = currentPage <= 1;
            document.getElementById('btnNext').disabled = currentPage >= totalPages;
        }

        function updateStats() {
            document.getElementById('totalOrders').textContent = totalOrders;
            document.getElementById('totalPages').textContent = totalPages;
            document.getElementById('currentPageDisplay').textContent = currentPage;
            document.getElementById('statsBar').style.display = 'grid';
        }

        function previousPage() {
            if (currentPage > 1) {
                currentPage--;
                fetchOrders(lastFilters.fromDate, lastFilters.toDate, lastFilters.driverId);
            }
        }

        function nextPage() {
            if (currentPage < totalPages) {
                currentPage++;
                fetchOrders(lastFilters.fromDate, lastFilters.toDate, lastFilters.driverId);
            }
        }

        function resetFilters() {
            setDefaultDates();
            document.getElementById('driverFilter').value = '';
            document.getElementById('tableContainer').style.display = 'none';
            document.getElementById('noData').style.display = 'none';
            document.getElementById('statsBar').style.display = 'none';
            currentPage = 1;
            lastFilters = { fromDate: '', toDate: '', driverId: '' };
        }

        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Initialize on load
        window.onload = function() {
            setDefaultDates();
            searchOrders();
        };
    </script>
</body>
</html>