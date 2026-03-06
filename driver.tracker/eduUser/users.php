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

// Add these new variables:
$totalActiveUsers = 0;
$totalActiveDrivers = 0;
$totalRecentLogins = 0;

// Handle status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $toggleUserId = intval($_POST['user_id']);
    $newStatusInt = intval($_POST['new_status']);
    
    // Debug: Log incoming values
    error_log("Status Toggle - User ID: $toggleUserId, New Status: $newStatusInt");
    
    // Validate status value (0 or 1)
    if (!in_array($newStatusInt, [0, 1])) {
        $_SESSION['message'] = 'Invalid status value.';
        $_SESSION['messageType'] = 'error';
    } else {
        try {
            // First check if user exists and get current status
            $checkStmt = $conn->prepare("SELECT status FROM user_login WHERE user_id = ?");
            $checkStmt->bind_param("i", $toggleUserId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows == 0) {
                $_SESSION['message'] = 'User does not exist.';
                $_SESSION['messageType'] = 'error';
                $checkStmt->close();
            } else {
                $currentRow = $checkResult->fetch_assoc();
                $currentStatus = intval($currentRow['status']);
                $checkStmt->close();
                
                error_log("Current Status: $currentStatus, New Status: $newStatusInt");
                
                // Check if status is actually different
                if ($currentStatus == $newStatusInt) {
                    $_SESSION['message'] = 'Status is already ' . ($newStatusInt == 1 ? 'Active' : 'Inactive');
                    $_SESSION['messageType'] = 'error';
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    // Update with INTEGER value
                    $stmt = $conn->prepare("UPDATE user_login SET status = ? WHERE user_id = ?");
                    
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $stmt->bind_param("ii", $newStatusInt, $toggleUserId);
                    
                    if ($stmt->execute()) {
                        error_log("Execute success. Affected rows: " . $stmt->affected_rows);
                        
                        if ($stmt->affected_rows > 0) {
                            $conn->commit();
                            
                            // Log the change
                            $statusText = $newStatusInt == 1 ? 'Active' : 'Inactive';
                            logAppError("User status changed: User ID $toggleUserId changed to $statusText by: $username");
                            
                            $_SESSION['message'] = "User status successfully updated to $statusText";
                            $_SESSION['messageType'] = 'success';
                        } else {
                            $conn->rollback();
                            $_SESSION['message'] = 'No changes made. Query executed but no rows affected.';
                            $_SESSION['messageType'] = 'error';
                            error_log("No rows affected. User ID: $toggleUserId");
                        }
                    } else {
                        $conn->rollback();
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                    
                    $stmt->close();
                }
            }
            
        } catch (Exception $e) {
            if ($conn->errno) {
                $conn->rollback();
            }
            logAppError("Status update error: " . $e->getMessage());
            $_SESSION['message'] = 'Error updating status: ' . $e->getMessage();
            $_SESSION['messageType'] = 'error';
        }
    }
    
    // Redirect to prevent form resubmission
    $redirect_url = 'users.php';
    if (isset($_GET['page'])) {
        $redirect_url .= '?page=' . intval($_GET['page']);
    }
    if (isset($_GET['search'])) {
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'search=' . urlencode($_GET['search']);
    }
    
    header("Location: $redirect_url");
    exit();
}

// Handle user deletion - NEWLY ADDED
if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
    $deleteUserId = intval($_POST['user_id']);
    
    // Prevent user from deleting themselves
    if ($deleteUserId == $user_id) {
        $_SESSION['message'] = 'You cannot delete your own account.';
        $_SESSION['messageType'] = 'error';
    } else {
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Get user info before deletion for logging
            $stmt = $conn->prepare("SELECT username, email, driverId FROM user_login WHERE user_id = ?");
            $stmt->bind_param("i", $deleteUserId);
            $stmt->execute();
            $result = $stmt->get_result();
            $deletedUser = $result->fetch_assoc();
            $stmt->close();
            
           if ($deletedUser) {
    // Clear token from user_login table
    $stmt = $conn->prepare("UPDATE user_login SET token = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $deleteUserId);
    $stmt->execute();
    $stmt->close();
                
                // Delete from any other related tables if needed
                if (!empty($deletedUser['driverId'])) {
                    // Add driver-related data deletion here if you have driver tables
                    // Example:
                    // $stmt = $conn->prepare("DELETE FROM driver_locations WHERE driver_id = ?");
                    // $stmt->bind_param("s", $deletedUser['driverId']);
                    // $stmt->execute();
                    // $stmt->close();
                }
                
                // Finally delete the user
                $stmt = $conn->prepare("DELETE FROM user_login WHERE user_id = ?");
                $stmt->bind_param("i", $deleteUserId);
                $stmt->execute();
                $stmt->close();
                
                // Commit transaction
                $conn->commit();
                
                // Log the deletion
                logAppError("User deleted: {$deletedUser['username']} (ID: $deleteUserId) by user: $username");
                
                $_SESSION['message'] = "User '{$deletedUser['username']}' has been successfully deleted.";
                $_SESSION['messageType'] = 'success';
            } else {
                $_SESSION['message'] = 'User not found.';
                $_SESSION['messageType'] = 'error';
            }
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            logAppError("User deletion error: " . $e->getMessage());
            $_SESSION['message'] = 'Error deleting user. Please try again.';
            $_SESSION['messageType'] = 'error';
        }
    }
    
    // Redirect to prevent form resubmission
    $redirect_url = 'users.php';
    if (isset($_GET['page'])) {
        $redirect_url .= '?page=' . intval($_GET['page']);
    }
    if (isset($_GET['search'])) {
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'search=' . urlencode($_GET['search']);
    }
    
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

// Initialize users data
$users = [];
$totalUsers = 0;

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10; // Users per page
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    // Build search query
    $whereClause = '';
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $whereClause = "WHERE firstName LIKE ? OR lastName LIKE ? OR username LIKE ? OR email LIKE ? OR driverId LIKE ?";
        $searchTerm = '%' . $search . '%';
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
        $types = 'sssss';
    }
    
    // Get total count for pagination (all users matching search)
    $countSql = "SELECT COUNT(*) as total FROM user_login $whereClause";
    if ($whereClause) {
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalUsers = $result->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $result = $conn->query($countSql);
        $totalUsers = $result->fetch_assoc()['total'];
    }
    
    // Get stats for active users only (status = 1) - YE NAYA HAI
    $statsSql = "SELECT 
                    COUNT(*) as total_active,
                    COUNT(CASE WHEN driverId IS NOT NULL AND driverId != '' THEN 1 END) as active_drivers,
                    COUNT(CASE WHEN last_login IS NOT NULL AND last_login != '' THEN 1 END) as recent_logins
                 FROM user_login 
                 WHERE status = 1";
    
    $statsResult = $conn->query($statsSql);
    if ($statsResult) {
        $statsData = $statsResult->fetch_assoc();
        $totalActiveUsers = $statsData['total_active'];
        $totalActiveDrivers = $statsData['active_drivers'];
        $totalRecentLogins = $statsData['recent_logins'];
    }
    
   $sql = "SELECT user_id, driverId, username, firstName, lastName, email, status, created_at, last_login, userType, latitude, longitude
        FROM user_login 
        $whereClause 
        ORDER BY created_at DESC 
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
        $users[] = $row;
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    logAppError("Users page error: " . $e->getMessage());
    $error = 'Unable to load users data';
}

// Calculate pagination
$totalPages = ceil($totalUsers / $limit);

// Handle logout
if (isset($_GET['logout'])) {
    // Clear remember me cookie
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        
        // Remove remember token from database
        try {
          $stmt = $conn->prepare("UPDATE user_login SET token = NULL WHERE user_id = ?");
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
    <title>Users - <?php echo APP_NAME; ?></title>
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

        .page-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .search-box {
            position: relative;
            min-width: 300px;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #0000FF;
            box-shadow: 0 0 0 3px rgba(0, 0, 255, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .btn-primary {
            background: #0000FF;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: #0000CC;
            transform: translateY(-1px);
        }

        /* Message Notification */
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

        .message i {
            font-size: 1.2rem;
        }

        /* Users Table */
        .users-card {
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

        .users-count {
            background: #0000FF;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th,
        .users-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .users-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .users-table td {
            font-size: 0.95rem;
        }

        .users-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .users-table tbody tr:hover .actions {
    opacity: 1;
    visibility: visible;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar-small {
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
        }

        .user-details h4 {
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 2px;
        }

        .user-details p {
            font-size: 0.85rem;
            color: #718096;
        }

        .driver-id {
            font-weight: 600;
            color: #0000FF;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .status-badge i {
            font-size: 0.85rem;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-active:hover {
            background: #a7f3d0;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-inactive:hover {
            background: #fca5a5;
        }

.date-text {
    color: #4a5568;
    font-size: 0.85rem;
    white-space: nowrap;
    line-height: 1.4;
}

/* Last Login column को थोड़ा चौड़ा करें */
.users-table th:nth-child(6),
.users-table td:nth-child(6) {
    min-width: 150px;
}

.actions {
    display: flex;
    gap: 8px;
    align-items: center;
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}

        .btn-sm {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-view {
            background: #f0f9ff;
            color: #0369a1;
        }

        .btn-view:hover {
            background: #0369a1;
            color: white;
        }

        .btn-edit {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-edit:hover {
            background: #92400e;
            color: white;
        }

        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }

        .btn-delete:hover {
            background: #dc2626;
            color: white;
        }

        /* Delete Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            animation: scaleIn 0.3s ease;
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
        }

        .modal-header i {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #fee2e2;
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a202c;
        }

        .modal-body {
            margin-bottom: 2rem;
            color: #4a5568;
            line-height: 1.6;
        }

        .user-highlight {
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid #dc2626;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #4a5568;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #e5e7eb;
        }

        .btn-confirm-delete {
            background: #dc2626;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-confirm-delete:hover {
            background: #b91c1c;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: #f7fafc;
            color: #0000FF;
        }

        .pagination .current {
            background: #0000FF;
            color: white;
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            font-size: 1.25rem
            margin-bottom: 0.5rem;
            color: #4a5568;
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

            .page-actions {
                flex-direction: column;
            }

            .search-box {
                min-width: auto;
            }

            .actions {
                flex-direction: column;
                gap: 4px;
            }
        }

        @media (max-width: 768px) {
            .header {
                padding: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .users-table {
                font-size: 0.85rem;
            }

            .users-table th,
            .users-table td {
                padding: 12px 8px;
            }

            .user-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .modal {
                padding: 1.5rem;
            }
        }

        @media (max-width: 640px) {
            .sidebar {
                width: 260px;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes scaleIn {
            from { 
                opacity: 0;
                transform: scale(0.9);
            }
            to { 
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        /* Additional styles for stats summary */
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 3rem;
        }

        .stat-item {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.25rem;
        }

        .stat-item p {
            color: #718096;
            font-size: 0.9rem;
            margin: 0;
        }

        .text-muted {
            color: #9ca3af;
        }

        @media (max-width: 768px) {
            .stats-summary {
                grid-template-columns: 1fr;
                margin-top: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->

<?php include __DIR__ . '/../includes/sidebar.php'; ?>
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
                            <a href="<?php echo BASE_URL;?>dashboard.php">Home</a>
                            <i class="fas fa-chevron-right"></i>
                            <span>Users</span>
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
                <!-- Message Display -->
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
                        <h1>Users Management</h1>
                        <p>Manage and view all registered users in the system</p>
                    </div>
                    <div class="page-actions">
                        <form method="GET" class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input 
                                type="text" 
                                name="search" 
                                class="search-input" 
                                placeholder="Search users by name, email, or driver ID..." 
                                value="<?php echo htmlspecialchars($search); ?>"
                            >
                            <?php if(isset($_GET['page'])): ?>
                                <input type="hidden" name="page" value="<?php echo $_GET['page']; ?>">
                            <?php endif; ?>
                        </form>
                        <a href="addUser.html" class="btn-primary">
                            <i class="fas fa-plus"></i>
                            Add User
                        </a>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="users-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-users"></i>
                            All Users
                        </h3>
                        <span class="users-count"><?php echo number_format($totalUsers); ?> users</span>
                    </div>
                    
                    <?php if (!empty($users)): ?>
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Driver ID</th>
                                  <th>User Type</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar-small">
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
                                            <div class="user-details">
                                                <h4>
                                                    <?php 
                                                    if (!empty($user['firstName']) && !empty($user['lastName'])) {
                                                        echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']);
                                                    } else {
                                                        echo htmlspecialchars($user['username']);
                                                    }
                                                    ?>
                                                </h4>
                                                <p>@<?php echo htmlspecialchars($user['username']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($user['driverId'])): ?>
                                            <span class="driver-id"><?php echo htmlspecialchars($user['driverId']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                               <td style="white-space:nowrap;">
    <?php
    $roleNames = [
        1 => 'Super Admin',
        2 => 'Organization Admin',
        3 => 'Organization Staff',
        4 => 'Driver',
        5 => 'Teacher',
        6 => 'Parent',
    ];
    $ut = intval($user['userType'] ?? 0);
    echo isset($roleNames[$ut]) ? htmlspecialchars($roleNames[$ut]) : '—';
    ?>
</td>
<td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php 
                                        // Status is INTEGER in database (1 = Active, 0 = Inactive)
                                        // Default to 1 (Active) if status is NULL or not set
                                        $currentStatusInt = isset($user['status']) && $user['status'] !== null ? intval($user['status']) : 1;
                                        $isActive = ($currentStatusInt == 1);
                                        
                                        $statusClass = $isActive ? 'status-active' : 'status-inactive';
                                        $statusIcon = $isActive ? 'fa-check-circle' : 'fa-times-circle';
                                        $statusText = $isActive ? 'Active' : 'Inactive';
                                        $nextStatusInt = $isActive ? 0 : 1; // Toggle between 0 and 1
                                        $nextStatusText = $isActive ? 'Inactive' : 'Active';
                                        ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirmStatusChange('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo $nextStatusText; ?>')">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $nextStatusInt; ?>">
                                            <input type="hidden" name="toggle_status" value="1">
                                            <button 
                                                type="submit" 
                                                class="status-badge <?php echo $statusClass; ?>"
                                                title="Click to change to <?php echo $nextStatusText; ?>"
                                            >
                                                <i class="fas <?php echo $statusIcon; ?>"></i>
                                                <?php echo $statusText; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="date-text">
                                        <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                    </td>
                                    <td class="date-text">
                                        <?php if (!empty($user['last_login'])): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="view-user.php?id=<?php echo $user['user_id']; ?>" class="btn-sm btn-view">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                            <a href="edit_user.php?id=<?php echo $user['user_id']; ?>" class="btn-sm btn-edit">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </a>
                                            <?php if ($user['user_id'] != $user_id): ?>
                                                <button 
                                                    class="btn-sm btn-delete" 
                                                    onclick="showDeleteModal(<?php echo $user['user_id']; ?>, '<?php echo addslashes(htmlspecialchars($user['username'])); ?>', '<?php echo addslashes(htmlspecialchars($user['email'])); ?>')"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                    Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No users found</h3>
                            <?php if (!empty($search)): ?>
                                <p>No users match your search criteria "<?php echo htmlspecialchars($search); ?>"</p>
                                <a href="users.php" class="btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-arrow-left"></i>
                                    Show All Users
                                </a>
                            <?php else: ?>
                                <p>No users have been registered yet.</p>
                                <a href="addUser.html" class="btn-primary" style="margin-top: 1rem;">
                                    <i class="fas fa-plus"></i>
                                    Add First User
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo ($page - 1); ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                        <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">1</a>
                        <?php if ($start > 2): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <span>...</span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><?php echo $totalPages; ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo ($page + 1); ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

             <!-- Quick Stats -->
                <div class="stats-summary">
                    <div class="stat-item">
                        <i class="fas fa-users"></i>
                        <div>
                            <h4><?php echo number_format($totalActiveUsers); ?></h4>
                            <p>Active Users</p>
                        </div>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-car"></i>
                        <div>
                            <h4><?php echo number_format($totalActiveDrivers); ?></h4>
                            <p>Active Drivers</p>
                        </div>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <h4><?php echo number_format($totalRecentLogins); ?></h4>
                            <p>Recent Logins</p>
                        </div>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-database"></i>
                        <div>
                            <h4><?php echo number_format($totalUsers); ?></h4>
                            <p>Total Records</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Delete User</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this user? This action cannot be undone.</p>
                <div class="user-highlight">
                    <strong>User:</strong> <span id="deleteUserName"></span><br>
                    <strong>Email:</strong> <span id="deleteUserEmail"></span>
                </div>
                <p><strong>Warning:</strong> All associated data including driver records, location history, and login sessions will be permanently deleted.</p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <button type="submit" name="delete_user" class="btn-confirm-delete">Delete User</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainWrapper = document.getElementById('mainWrapper');

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

        // Search functionality
        const searchInput = document.querySelector('.search-input');
        let searchTimeout;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });

        // Delete Modal Functions
        function showDeleteModal(userId, username, email) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = username;
            document.getElementById('deleteUserEmail').textContent = email;
            document.getElementById('deleteModal').classList.add('active');
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Status Change Confirmation Function
        function confirmStatusChange(username, nextStatus) {
            return confirm(`Are you sure you want to change status of "${username}" to "${nextStatus}"?`);
        }

        // Close modal on outside click
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideDeleteModal();
            }
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
    </script>
</body>
</html>