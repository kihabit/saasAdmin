<?php
session_start();

require_once  '../config.php';

if (!isLoggedIn()) {
    redirect(LOGIN_PAGE);
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please login again.');
    redirect(LOGIN_PAGE);
}

$_SESSION['last_activity'] = time();

$db = Database::getInstance();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$driver_id = $_SESSION['driver_id'];

$message = '';
$messageType = '';

// Handle child deletion
if (isset($_POST['delete_child']) && isset($_POST['child_id'])) {
    $deleteChildId = intval($_POST['child_id']);

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("SELECT name, roll_number FROM students WHERE id = ?");
        $stmt->bind_param("i", $deleteChildId);
        $stmt->execute();
        $result = $stmt->get_result();
        $deletedChild = $result->fetch_assoc();
        $stmt->close();

        if ($deletedChild) {
            $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
            $stmt->bind_param("i", $deleteChildId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            logAppError("Child deleted: {$deletedChild['name']} (ID: $deleteChildId) by user: $username");

            $_SESSION['message'] = "Child '{$deletedChild['name']}' has been successfully deleted.";
            $_SESSION['messageType'] = 'success';
        } else {
            $_SESSION['message'] = 'Child record not found.';
            $_SESSION['messageType'] = 'error';
        }
    } catch (Exception $e) {
        $conn->rollback();
        logAppError("Child deletion error: " . $e->getMessage());
        $_SESSION['message'] = 'Error deleting child record. Please try again.';
        $_SESSION['messageType'] = 'error';
    }

    $redirect_url = 'students.php';
    if (isset($_GET['page'])) $redirect_url .= '?page=' . intval($_GET['page']);
    if (isset($_GET['search'])) $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'search=' . urlencode($_GET['search']);
    header("Location: $redirect_url");
    exit();
}

// Display session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}

$students = [];
$totalChildren = 0;
$totalActive = 0;

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $whereClause = '';
    $params = [];
    $types = '';

    if (!empty($search)) {
        $whereClause = "WHERE c.name LIKE ? OR c.roll_number LIKE ? OR c.class LIKE ? OR c.section LIKE ? OR ul.firstName LIKE ? OR ul.lastName LIKE ?";
        $searchTerm = '%' . $search . '%';
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
        $types = 'ssssss';
    }

    // Total count
    $countSql = "SELECT COUNT(*) as total FROM students c LEFT JOIN user_login ul ON c.parent_id = ul.user_id $whereClause";
    if ($whereClause) {
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalChildren = $result->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $result = $conn->query($countSql);
        $totalChildren = $result->fetch_assoc()['total'];
    }

    // Active count
    $activeResult = $conn->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
    if ($activeResult) $totalActive = $activeResult->fetch_assoc()['total'];

    // Main query - join with user_login to get parent name
    $sql = "SELECT c.id, c.school_id, c.parent_id, c.driver_id, c.name, c.class, c.section,
                   c.roll_number, c.gender, c.dob, c.photo, c.pickup_address, c.drop_address,
                   c.pickup_lat, c.pickup_lng, c.status, c.created_at, c.updated_at,
                   ul.firstName as parent_firstName, ul.lastName as parent_lastName,
                   ul.username as parent_username, ul.email as parent_email, ul.phone_number as parent_phone
            FROM students c
            LEFT JOIN user_login ul ON c.parent_id = ul.user_id
            $whereClause
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?";

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
        $students[] = $row;
    }
    $stmt->close();

    // Count distinct parents
    $pr = $conn->query("SELECT COUNT(DISTINCT parent_id) as cnt FROM students");
    $parentCount = $pr ? $pr->fetch_assoc()['cnt'] : 0;

} catch (Exception $e) {
    logAppError("Student page error: " . $e->getMessage());
    $error = 'Unable to load students data';
}

$totalPages = ceil($totalChildren / $limit);

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
    <title>Student - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #f8fafc; color: #1a202c; line-height: 1.6; }
        .app-container { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: 280px; background: white; border-right: 1px solid #e2e8f0; position: fixed; height: 100vh; left: 0; top: 0; z-index: 1000; overflow-y: auto; transition: transform 0.3s ease; }
        .sidebar.collapsed { transform: translateX(-100%); }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #0000FF, #4169E1); color: white; }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
        .sidebar-logo h2 { font-size: 1.3rem; font-weight: 700; }
        .sidebar-user { margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 12px; }
        .sidebar-user .user-avatar { width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 16px; margin-bottom: 0.5rem; }
        .sidebar-user h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem; }
        .sidebar-user p { font-size: 0.85rem; opacity: 0.8; }
        .sidebar-nav { padding: 1rem 0; }
        .nav-section { margin-bottom: 2rem; }
        .nav-item { display: flex; padding: 0.75rem 1.5rem; color: #4a5568; text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent; align-items: center; gap: 12px; }
        .nav-item:hover, .nav-item.active { background: #f7fafc; color: #0000FF; border-left-color: #0000FF; }
        .nav-item i { width: 20px; text-align: center; font-size: 1.1rem; }
        .nav-item .nav-text { flex: 1; }

        /* Main */
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }
        .header { background: white; border-bottom: 1px solid #e2e8f0; padding: 1rem 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .menu-toggle { background: none; border: none; font-size: 1.2rem; color: #4a5568; cursor: pointer; padding: 8px; border-radius: 8px; transition: all 0.3s ease; display: none; }
        .menu-toggle:hover { background: #f7fafc; color: #0000FF; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 0.9rem; }
        .breadcrumb a { color: #0000FF; text-decoration: none; }
        .header-actions { display: flex; align-items: center; gap: 1rem; }
        .logout-btn { background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: #c82333; transform: translateY(-1px); }

        /* Content */
        .main-content { padding: 2rem; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .page-title h1 { font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.5rem; }
        .page-title p { color: #718096; font-size: 1rem; }
        .page-actions { display: flex; gap: 1rem; align-items: center; }
        .search-box { position: relative; min-width: 300px; }
        .search-input { width: 100%; padding: 12px 16px 12px 44px; border: 1px solid #e2e8f0; border-radius: 12px; background: white; font-size: 0.95rem; transition: all 0.3s ease; }
        .search-input:focus { outline: none; border-color: #0000FF; box-shadow: 0 0 0 3px rgba(0,0,255,0.1); }
        .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .btn-primary { background: #0000FF; color: white; border: none; padding: 12px 20px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background: #0000CC; transform: translateY(-1px); }

        /* Message */
        .message-container { margin-bottom: 2rem; }
        .message { padding: 1rem 1.5rem; border-radius: 12px; display: flex; align-items: center; gap: 12px; font-weight: 500; }
        .message.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .message.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* Table Card */
        .students-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 2rem; }
        .card-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.25rem; font-weight: 600; color: #1a202c; display: flex; align-items: center; gap: 8px; }
        .count-badge { background: #0000FF; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }

        .students-table { width: 100%; border-collapse: collapse; }
        .students-table th, .students-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .students-table th { background: #f8fafc; font-weight: 600; color: #4a5568; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .students-table td { font-size: 0.92rem; }
        .students-table tbody tr:hover { background: #f8fafc; }

        .child-info { display: flex; align-items: center; gap: 12px; }
        .child-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #0000FF, #4169E1); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px; flex-shrink: 0; overflow: hidden; }
        .child-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .child-details h4 { font-weight: 600; color: #1a202c; margin-bottom: 2px; }
        .child-details p { font-size: 0.82rem; color: #718096; }

        .parent-info { font-size: 0.88rem; }
        .parent-info strong { color: #1a202c; display: block; }
        .parent-info span { color: #718096; font-size: 0.8rem; }

        .gender-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .gender-male { background: #dbeafe; color: #1d4ed8; }
        .gender-female { background: #fce7f3; color: #9d174d; }

        .status-badge-child { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }

        .date-text { color: #4a5568; font-size: 0.83rem; white-space: nowrap; }
        .text-muted { color: #9ca3af; }

        .actions { display: flex; gap: 6px; align-items: center; }
        .btn-sm { padding: 6px 11px; border-radius: 8px; font-size: 0.78rem; border: none; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; }
        .btn-view { background: #f0f9ff; color: #0369a1; }
        .btn-view:hover { background: #0369a1; color: white; }
        .btn-edit { background: #fef3c7; color: #92400e; }
        .btn-edit:hover { background: #92400e; color: white; }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }

        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-overlay.active { display: flex; }
        .modal { background: white; border-radius: 16px; padding: 2rem; max-width: 480px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: scaleIn 0.3s ease; }
        .modal-header { display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem; }
        .modal-icon { width: 48px; height: 48px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        .modal-header h3 { font-size: 1.4rem; font-weight: 600; }
        .modal-body { margin-bottom: 1.5rem; color: #4a5568; line-height: 1.6; }
        .child-highlight { background: #f3f4f6; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #dc2626; }
        .modal-actions { display: flex; gap: 1rem; justify-content: flex-end; }
        .btn-cancel { background: #f3f4f6; color: #4a5568; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm-delete { background: #dc2626; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; }
        .btn-confirm-delete:hover { background: #b91c1c; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 2rem; }
        .pagination a, .pagination span { padding: 8px 12px; border-radius: 8px; text-decoration: none; color: #4a5568; font-weight: 500; transition: all 0.3s ease; }
        .pagination a:hover { background: #f7fafc; color: #0000FF; }
        .pagination .current { background: #0000FF; color: white; }
        .pagination .disabled { opacity: 0.5; }

        /* Stats */
        .stats-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
        .stat-item { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 1rem; transition: all 0.3s ease; }
        .stat-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .stat-item i { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; background: linear-gradient(135deg, #0000FF, #4169E1); color: white; }
        .stat-item h4 { font-size: 1.5rem; font-weight: 700; color: #1a202c; margin-bottom: 0.2rem; }
        .stat-item p { color: #718096; font-size: 0.88rem; margin: 0; }

        /* Empty State */
        .empty-state { text-align: center; padding: 4rem 2rem; color: #718096; }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.4; }
        .empty-state h3 { font-size: 1.2rem; margin-bottom: 0.5rem; color: #4a5568; }

        /* Sidebar Overlay */
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; display: none; }
        .sidebar-overlay.active { display: block; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .menu-toggle { display: block; }
            .page-header { flex-direction: column; }
            .page-actions { flex-direction: column; }
            .search-box { min-width: auto; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 1rem; }
            .students-table th, .students-table td { padding: 10px 8px; }
            .actions { flex-direction: column; gap: 4px; }
        }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
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
                <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                <h3><?php echo htmlspecialchars($username); ?></h3>
                <p>ID: <?php echo htmlspecialchars($driver_id ?? 'N/A'); ?></p>
            </div>
        </div>
        <div class="sidebar-nav">
            <div class="nav-section">
                <a href="dashboard.php" class="nav-item <?php echo isActivePage('dashboard.php'); ?>">
                    <i class="fas fa-tachometer-alt"></i><span class="nav-text">Dashboard</span>
                </a>
                <a href="users.php" class="nav-item <?php echo isActivePage('users.php'); ?>">
                    <i class="fas fa-users"></i><span class="nav-text">Users</span>
                </a>
                <a href="students.php" class="nav-item active">
                    <i class="fas fa-child"></i><span class="nav-text">Student</span>
                </a>
                <a href="profile.php" class="nav-item <?php echo isActivePage('profile.php'); ?>">
                    <i class="fas fa-user-circle"></i><span class="nav-text">Profile</span>
                </a>
                <a href="organization.php" class="nav-item <?php echo isActivePage('organization.php'); ?>">
                    <i class="fas fa-organization"></i><span class="nav-text">Organization</span>
                </a>
                
                 <a href="alert.php" class="nav-item <?php echo isActivePage('alert.php'); ?>">
                    <i class="fas fa-organization"></i><span class="nav-text">Alert</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Wrapper -->
    <div class="main-wrapper" id="mainWrapper">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Home</a>
                        <i class="fas fa-chevron-right"></i>
                        <span>Student</span>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">

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
                    <h1>Student Management</h1>
                    <p>View and manage all registered students and their parent details</p>
                </div>
                <div class="page-actions">
                    <form method="GET" class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input"
                               placeholder="Search by name, class, roll no, parent..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </form>
                    <a href="<? echo BASE_URL;?>students/add_students.php" class="btn-primary">
                        <i class="fas fa-plus"></i> Add Student 
                    </a>
                </div>
            </div>

            <!-- Table Card -->
            <div class="students-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-child"></i> All Students
                    </h3>
                    <span class="count-badge"><?php echo number_format($totalChildren); ?> students</span>
                </div>

                <?php if (!empty($students)): ?>
                <div style="overflow-x:auto;">
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Child</th>
                            <th>Class / Section</th>
                            <th>Roll No.</th>
                            <th>Pickup Address</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $child): ?>
                        <tr>
                            <!-- Child Name + Photo -->
                            <td>
                                <div class="child-info">
                                    <div class="child-avatar">
                                        <?php if (!empty($child['photo'])): ?>
                                            <img src="uploads/students/<?php echo htmlspecialchars($child['photo']); ?>" alt="Photo">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($child['name'], 0, 2)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="child-details">
                                        <h4><?php echo htmlspecialchars($child['name']); ?></h4>
                                        <p>ID: <?php echo $child['id']; ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- Class / Section -->
                            <td>
                                <strong><?php echo htmlspecialchars($child['class']); ?></strong>
                                <?php if (!empty($child['section'])): ?>
                                    &nbsp;- <?php echo htmlspecialchars($child['section']); ?>
                                <?php endif; ?>
                            </td>

                            <!-- Roll Number -->
                            <td><?php echo htmlspecialchars($child['roll_number'] ?? '-'); ?></td>

                            <!-- Gender -->
                            

                            <!-- Pickup Address -->
                            <td style="max-width:180px; font-size:0.82rem; color:#4a5568;">
                                <?php echo !empty($child['pickup_address']) ? htmlspecialchars($child['pickup_address']) : '<span class="text-muted">-</span>'; ?>
                            </td>

            

                            <!-- Status -->
                            <td>
                                <?php $isActive = ($child['status'] === 'active'); ?>
                                <span class="status-badge-child <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                                    <i class="fas <?php echo $isActive ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                    <?php echo ucfirst($child['status'] ?? 'inactive'); ?>
                                </span>
                            </td>

                            <!-- Registered Date -->
                            <td class="date-text">
                                <?php echo !empty($child['created_at']) ? date('M j, Y', strtotime($child['created_at'])) : '-'; ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div class="actions">
                                    <a href="view_students.php?id=<?php echo $child['id']; ?>" class="btn-sm btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="edit_students.php?id=<?php echo $child['id']; ?>" class="btn-sm btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button class="btn-sm btn-delete"
                                        onclick="showDeleteModal(<?php echo $child['id']; ?>, '<?php echo addslashes(htmlspecialchars($child['name'])); ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-child"></i>
                    <h3>No students found</h3>
                    <?php if (!empty($search)): ?>
                        <p>No students match your search "<?php echo htmlspecialchars($search); ?>"</p>
                        <a href="students.php" class="btn-primary" style="margin-top:1rem; display:inline-flex;">
                            <i class="fas fa-arrow-left"></i> Show All Student
                        </a>
                    <?php else: ?>
                        <p>No students have been registered yet.</p>
                        <a href="<? echo BASE_URL;?>students/add_students.php" class="btn-primary" style="margin-top:1rem; display:inline-flex;">
                            <i class="fas fa-plus"></i> Add First Child
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
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
                    <a href="?page=<?php echo $page+1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
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
                    <i class="fas fa-child"></i>
                    <div>
                        <h4><?php echo number_format($totalChildren); ?></h4>
                        <p>Total Student</p>
                    </div>
                </div>
                <div class="stat-item">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <h4><?php echo number_format($totalActive); ?></h4>
                        <p>Active Student</p>
                    </div>
                </div>
                <div class="stat-item">
                    <i class="fas fa-user-friends"></i>
                    <div>
                        <?php
                        // Already calculated above
                        ?>
                        <h4><?php echo number_format($parentCount); ?></h4>
                        <p>Parents with Student</p>
                    </div>
                </div>
                <div class="stat-item">
                    <i class="fas fa-database"></i>
                    <div>
                        <h4><?php echo number_format($totalChildren - $totalActive); ?></h4>
                        <p>Inactive Student</p>
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
            <h3>Delete Child Record</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this child record? This action cannot be undone.</p>
            <div class="child-highlight">
                <strong>Child Name:</strong> <span id="deleteChildName"></span>
            </div>
            <p><strong>Warning:</strong> All associated pickup/drop records and location data will be permanently removed.</p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Cancel</button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="child_id" id="deleteChildId">
                <button type="submit" name="delete_child" class="btn-confirm-delete">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
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

    // Search auto-submit
    const searchInput = document.querySelector('.search-input');
    let searchTimeout;
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => this.form.submit(), 500);
    });

    // Delete Modal
    function showDeleteModal(childId, childName) {
        document.getElementById('deleteChildId').value = childId;
        document.getElementById('deleteChildName').textContent = childName;
        document.getElementById('deleteModal').classList.add('active');
    }
    function hideDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }
    document.getElementById('deleteModal').addEventListener('click', function (e) {
        if (e.target === this) hideDeleteModal();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hideDeleteModal(); });

    // Auto-hide message
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