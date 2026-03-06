<?php
session_start();
require_once 'config.php';

// Authentication check
if (!isLoggedIn()) {
    redirect(LOGIN_PAGE);
}

// Session timeout
if (isset($_SESSION['last_activity']) && 
    (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    redirect(LOGIN_PAGE);
}

$_SESSION['last_activity'] = time();

// Database connection
$db = Database::getInstance();
$conn = $db->getConnection();

$dashboardData = [];

// Your query logic here
$stmt = $conn->prepare("
    SELECT loc.*, user_login.firstName, user_login.lastName
    FROM locations loc
    INNER JOIN (
        SELECT driverId, MAX(updated_at) max_updated
        FROM locations
        GROUP BY driverId
    ) latest 
    ON loc.driverId = latest.driverId
    AND loc.updated_at = latest.max_updated
    LEFT JOIN user_login 
    ON user_login.user_id = loc.driverId
    ORDER BY loc.updated_at DESC
");

$stmt->execute();
$result = $stmt->get_result();
$dashboardData['recent_trips'] = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
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

        /*.notification-btn {*/
        /*    position: relative;*/
        /*    background: #f7fafc;*/
        /*    border: 1px solid #e2e8f0;*/
        /*    padding: 8px;*/
        /*    border-radius: 8px;*/
        /*    color: #4a5568;*/
        /*    cursor: pointer;*/
        /*    transition: all 0.3s ease;*/
        /*}*/

        /*.notification-btn:hover {*/
        /*    background: #0000FF;*/
        /*    color: white;*/
        /*}*/

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

        /* Search Section - Google-style */
        .search-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: .5rem .25rem;
            margin-bottom: 2rem;
        }

        .search-container {
            width: 100%;
            max-width: 600px;
            position: relative;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 50px;
            padding: 12px 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .search-box:focus-within {
            border-color: #0000FF;
            box-shadow: 0 8px 25px rgba(0, 0, 255, 0.15);
            transform: translateY(-2px);
        }

        .search-icon {
            color: #9ca3af;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        .search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 1.1rem;
            padding: 8px 0;
            background: transparent;
            color: #1a202c;
        }

        .search-input::placeholder {
            color: #9ca3af;
            font-weight: 400;
        }

        .go-button {
            background: #0000FF;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .go-button:hover {
            background: #0000CC;
            transform: scale(1.05);
        }

        .go-button:active {
            transform: scale(0.98);
        }

        .go-button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }

        /* Auto-suggestion Dropdown */
        .suggestions-dropdown {
            position: absolute;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            margin-top: 2px;
        }

        .suggestions-dropdown.active {
            display: block;
        }

        .suggestion-item {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .suggestion-item:hover,
        .suggestion-item.highlighted {
            background: #f7fafc;
            color: #0000FF;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }

        .suggestion-details {
            flex: 1;
        }

        .suggestion-name {
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 2px;
            font-size: 0.95rem;
        }

        .suggestion-id {
            font-size: 0.8rem;
            color: #718096;
        }

        .suggestion-item:hover .suggestion-name,
        .suggestion-item.highlighted .suggestion-name {
            color: #0000FF;
        }

        .no-suggestions {
            padding: 20px;
            text-align: center;
            color: #718096;
            font-style: italic;
        }

        .search-suggestions {
            margin-top: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
        }

        .suggestion-btn {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 20px;
            color: #4a5568;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .suggestion-btn:hover {
            background: #f7fafc;
            border-color: #0000FF;
            color: #0000FF;
            transform: translateY(-1px);
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
            float:right;
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
    grid-template-columns: 1fr;
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
            z-index: 10000;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .notification.success {
            background: #10b981;
        }

        .notification.error {
            background: #ef4444;
        }

        .notification.info {
            background: #3b82f6;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
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

            .content-grid {
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

            .search-section {
                padding: 2rem 1rem;
            }

            .search-box {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
                padding: 1rem;
                border-radius: 16px;
            }

            .go-button {
                margin-left: 0;
                justify-content: center;
            }

            .search-suggestions {
                margin-top: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .welcome-title {
                font-size: 1.5rem;
            }

            .header-actions {
                gap: 0.5rem;
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

            .sidebar {
                width: 260px;
            }
        }
    </style>
<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/navbar.php'; ?>

<main class="main-content">

    <h2>Dashboard</h2>

    <table class="trips-table">
        <thead>
            <tr>
                <th>Driver ID</th>
                <th>Name</th>
                <th>Speed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dashboardData['recent_trips'] as $trip): ?>
            <tr>
                <td><?= htmlspecialchars($trip['driverId']) ?></td>
                <td>
                    <?= htmlspecialchars($trip['firstName'] ?? '') ?>
                    <?= htmlspecialchars($trip['lastName'] ?? '') ?>
                </td>
                <td><?= htmlspecialchars($trip['speed']) ?> km/h</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</main>

<?php include 'includes/footer.php'; ?>