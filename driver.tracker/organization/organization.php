<?php
session_start();
require_once '../config.php';

if (!isLoggedIn()) { redirect(LOGIN_PAGE); }

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset(); session_destroy();
    setFlashMessage('error', 'Your session has expired. Please login again.');
    redirect(LOGIN_PAGE);
}
$_SESSION['last_activity'] = time();

$db   = Database::getInstance();
$conn = $db->getConnection();

$user_id   = $_SESSION['user_id']   ?? 0;
$username  = $_SESSION['username']  ?? '';
$driver_id = $_SESSION['driver_id'] ?? null;

$message = ''; $messageType = '';
$totalSchools = 0; $totalWithPhone = 0; $totalWithEmail = 0; $filteredTotal = 0;
$schools = [];

/* Delete */
if (isset($_POST['delete_school'], $_POST['school_id'])) {
    $delId = intval($_POST['school_id']);
    try {
        $conn->begin_transaction();
        $st = $conn->prepare("SELECT name FROM organization WHERE id = ?");
        $st->bind_param("i", $delId); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        if ($row) {
            $st = $conn->prepare("DELETE FROM organization WHERE id = ?");
            $st->bind_param("i", $delId); $st->execute(); $st->close();
            $conn->commit();
            logAppError("Organization deleted: {$row['name']} (ID:$delId) by $username");
            $_SESSION['message'] = "Organization '{$row['name']}' deleted successfully.";
            $_SESSION['messageType'] = 'success';
        } else {
            $_SESSION['message'] = 'Organization not found.'; $_SESSION['messageType'] = 'error';
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = 'Error deleting organization.'; $_SESSION['messageType'] = 'error';
    }
    $qs = [];
    if (!empty($_GET['page']))   $qs[] = 'page='   . intval($_GET['page']);
    if (!empty($_GET['search'])) $qs[] = 'search=' . urlencode($_GET['search']);
    header('Location: organization.php' . ($qs ? '?' . implode('&', $qs) : '')); exit();
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message']; $messageType = $_SESSION['messageType'];
    unset($_SESSION['message'], $_SESSION['messageType']);
}

$page   = max(1, intval($_GET['page']   ?? 1));
$limit  = 10; $offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

try {
    $res = $conn->query("SELECT COUNT(*) AS total,
        COUNT(CASE WHEN phone IS NOT NULL AND phone!='' THEN 1 END) AS wp,
        COUNT(CASE WHEN email IS NOT NULL AND email!='' THEN 1 END) AS we FROM organization");
    if ($res) { $s=$res->fetch_assoc(); $totalSchools=(int)$s['total']; $totalWithPhone=(int)$s['wp']; $totalWithEmail=(int)$s['we']; }

    if ($search !== '') {
        $like = '%'.$search.'%';
        $st = $conn->prepare("SELECT COUNT(*) AS total FROM organization WHERE name LIKE ? OR city LIKE ? OR state LIKE ? OR email LIKE ? OR phone LIKE ?");
        $st->bind_param('sssss',$like,$like,$like,$like,$like); $st->execute();
        $filteredTotal = (int)$st->get_result()->fetch_assoc()['total']; $st->close();
    } else { $filteredTotal = $totalSchools; }

    if ($search !== '') {
        $like = '%'.$search.'%';
        $st = $conn->prepare("SELECT id,name,address,city,state,postal_code,phone,email,latitude,longitude,created_at FROM organization WHERE name LIKE ? OR city LIKE ? OR state LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $st->bind_param('sssssii',$like,$like,$like,$like,$like,$limit,$offset);
    } else {
        $st = $conn->prepare("SELECT id,name,address,city,state,postal_code,phone,email,latitude,longitude,created_at FROM organization ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $st->bind_param('ii',$limit,$offset);
    }
    $st->execute(); $res=$st->get_result();
    while ($r=$res->fetch_assoc()) $schools[]=$r;
    $st->close();
} catch (Exception $e) { logAppError("organization.php: ".$e->getMessage()); }

$totalPages = $filteredTotal > 0 ? (int)ceil($filteredTotal/$limit) : 1;

if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login','',time()-3600,COOKIE_PATH,COOKIE_DOMAIN,COOKIE_SECURE,COOKIE_HTTPONLY);
        try { $st=$conn->prepare("DELETE FROM user_remember_tokens WHERE user_id=?"); $st->bind_param("i",$user_id); $st->execute(); $st->close(); } catch(Exception $e){}
    }
    session_unset(); session_destroy(); redirect(LOGIN_PAGE);
}
$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizations - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #f8fafc; color: #1a202c; line-height: 1.6; }
        .app-container { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: white; border-right: 1px solid #e2e8f0; position: fixed; height: 100vh; left: 0; top: 0; z-index: 1000; overflow-y: auto; transition: transform 0.3s ease; }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #0000FF, #4169E1); color: white; }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
        .sidebar-logo h2 { font-size: 1.3rem; font-weight: 700; }
        .sidebar-user { margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 12px; backdrop-filter: blur(10px); }
        .sidebar-user .user-avatar { width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 16px; margin-bottom: 0.5rem; }
        .sidebar-user h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem; }
        .sidebar-user p { font-size: 0.85rem; opacity: 0.8; }
        .sidebar-nav { padding: 1rem 0; }
        .nav-item { display: flex; padding: 0.75rem 1.5rem; color: #4a5568; text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent; align-items: center; gap: 12px; }
        .nav-item:hover, .nav-item.active { background: #f7fafc; color: #0000FF; border-left-color: #0000FF; }
        .nav-item i { width: 20px; text-align: center; font-size: 1.1rem; }
        .nav-item .nav-text { flex: 1; }
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }
        .header { background: white; border-bottom: 1px solid #e2e8f0; padding: 1rem 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .menu-toggle { background: none; border: none; font-size: 1.2rem; color: #4a5568; cursor: pointer; padding: 8px; border-radius: 8px; transition: all 0.3s ease; display: none; }
        .menu-toggle:hover { background: #f7fafc; color: #0000FF; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 0.9rem; }
        .breadcrumb a { color: #0000FF; text-decoration: none; }
        .logout-btn { background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: #c82333; transform: translateY(-1px); }
        .main-content { padding: 2rem; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .page-title h1 { font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.5rem; }
        .page-title p { color: #718096; font-size: 1rem; }
        .page-actions { display: flex; gap: 1rem; align-items: center; }
        .search-box { position: relative; min-width: 300px; }
        .search-input { width: 100%; padding: 12px 16px 12px 44px; border: 1px solid #e2e8f0; border-radius: 12px; background: white; font-size: 0.95rem; transition: all 0.3s ease; font-family: inherit; }
        .search-input:focus { outline: none; border-color: #0000FF; box-shadow: 0 0 0 3px rgba(0,0,255,0.1); }
        .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .btn-primary { background: #0000FF; color: white; border: none; padding: 12px 20px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; align-items: center; gap: 8px; font-family: inherit; white-space: nowrap; }
        .btn-primary:hover { background: #0000CC; transform: translateY(-1px); }
        .message-container { margin-bottom: 2rem; }
        .message { padding: 1rem 1.5rem; border-radius: 12px; display: flex; align-items: center; gap: 12px; font-weight: 500; animation: slideIn 0.3s ease; }
        .message.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .message.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .schools-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 2rem; }
        .card-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.25rem; font-weight: 600; color: #1a202c; display: flex; align-items: center; gap: 8px; }
        .schools-count { background: #0000FF; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .schools-table { width: 100%; border-collapse: collapse; }
        .schools-table th, .schools-table td { padding: 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .schools-table th { background: #f8fafc; font-weight: 600; color: #4a5568; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .schools-table td { font-size: 0.95rem; }
        .schools-table tbody tr:hover { background: #f8fafc; }
        .organization-info { display: flex; align-items: center; gap: 12px; }
        .organization-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #0000FF, #4169E1); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px; flex-shrink: 0; }
        .organization-details h4 { font-weight: 600; color: #1a202c; margin-bottom: 2px; }
        .organization-details p { font-size: 0.85rem; color: #718096; }
        .organization-id { font-weight: 600; color: #0000FF; }
        .date-text { color: #4a5568; font-size: 0.85rem; white-space: nowrap; }
        .text-muted { color: #9ca3af; }
        .actions { display: flex; gap: 8px; align-items: center; }
        .btn-sm { padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; border: none; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; align-items: center; gap: 4px; font-family: inherit; }
        .btn-view   { background: #f0f9ff; color: #0369a1; }
        .btn-view:hover   { background: #0369a1; color: white; }
        .btn-edit   { background: #fef3c7; color: #92400e; }
        .btn-edit:hover   { background: #92400e; color: white; }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-overlay.active { display: flex; }
        .modal { background: white; border-radius: 16px; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: scaleIn 0.3s ease; }
        .modal-header { display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem; }
        .modal-header i { width: 48px; height: 48px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .modal-header h3 { font-size: 1.5rem; font-weight: 600; color: #1a202c; }
        .modal-body { margin-bottom: 2rem; color: #4a5568; line-height: 1.6; }
        .organization-highlight { background: #f3f4f6; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #dc2626; }
        .modal-actions { display: flex; gap: 1rem; justify-content: flex-end; }
        .btn-cancel { background: #f3f4f6; color: #4a5568; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; font-family: inherit; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-confirm-delete { background: #dc2626; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; font-family: inherit; }
        .btn-confirm-delete:hover { background: #b91c1c; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 2rem; }
        .pagination a, .pagination span { padding: 8px 12px; border-radius: 8px; text-decoration: none; color: #4a5568; font-weight: 500; transition: all 0.3s ease; }
        .pagination a:hover { background: #f7fafc; color: #0000FF; }
        .pagination .current { background: #0000FF; color: white; }
        .pagination .disabled { opacity: 0.5; cursor: not-allowed; }
        .empty-state { text-align: center; padding: 4rem 2rem; color: #718096; }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.5; display: block; }
        .empty-state h3 { font-size: 1.25rem; margin-bottom: 0.5rem; color: #4a5568; }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; display: none; }
        .sidebar-overlay.active { display: block; }
        .stats-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 1.5rem; margin-top: 3rem; }
        .stat-item { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 1rem; transition: all 0.3s ease; }
        .stat-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .stat-item i { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: linear-gradient(135deg, #0000FF, #4169E1); color: white; }
        .stat-item h4 { font-size: 1.5rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem; }
        .stat-item p { color: #718096; font-size: 0.9rem; margin: 0; }
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .menu-toggle { display: block; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-actions { flex-direction: column; }
            .search-box { min-width: auto; }
            .actions { flex-direction: column; gap: 4px; }
        }
        @media (max-width: 768px) {
            .header, .main-content { padding: 1rem; }
            .schools-table th, .schools-table td { padding: 12px 8px; }
            .stats-summary { grid-template-columns: 1fr; margin-top: 2rem; }
        }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
    </style>
</head>
<body>
<div class="app-container">
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="/schoolAdmin/driver.tracker/icon/schooladmin.jpg" alt="Logo">
            <h2>Organization Admin</h2>
        </div>
        <div class="sidebar-user">
            <div class="user-avatar"><?php echo strtoupper(substr($username,0,2)); ?></div>
            <h3><?php echo htmlspecialchars($username); ?></h3>
            <p>ID: <?php echo htmlspecialchars($driver_id ?? 'N/A'); ?></p>
        </div>
    </div>
    <div class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?php echo isActivePage('dashboard.php'); ?>">
            <i class="fas fa-tachometer-alt"></i><span class="nav-text">Dashboard</span>
        </a>
        <a href="users.php" class="nav-item <?php echo isActivePage('users.php'); ?>">
            <i class="fas fa-users"></i><span class="nav-text">Users</span>
        </a>
        <a href="<?php echo BASE_URL; ?>organization/organization.php" class="nav-item <?php echo isActivePage('organization.php'); ?>">
            <i class="fas fa-organization"></i><span class="nav-text">Organizations</span>
        </a>
        <a href="children.php" class="nav-item <?php echo isActivePage('children.php'); ?>">
            <i class="fas fa-child"></i><span class="nav-text">Children</span>
        </a>
        <a href="profile.php" class="nav-item <?php echo isActivePage('profile.php'); ?>">
            <i class="fas fa-user-circle"></i><span class="nav-text">Profile</span>
        </a>
        
        <a href="alert.php" class="nav-item <?php echo isActivePage('alert.php'); ?>">
                    <i class="fas fa-organization"></i><span class="nav-text">Alert</span>
                </a>
    </div>
</nav>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="main-wrapper" id="mainWrapper">
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Organizations</span>
                </div>
            </div>
            <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <main class="main-content">
        <?php if ($message): ?>
        <div class="message-container">
            <div class="message <?php echo htmlspecialchars($messageType); ?>">
                <i class="fas <?php echo $messageType==='success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div class="page-title">
                <h1>Organizations Management</h1>
                <p>Manage and view all registered schools in the system</p>
            </div>
            <div class="page-actions">
                <form method="GET" class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="search" class="search-input"
                        placeholder="Search schools by name, city, email..."
                        value="<?php echo htmlspecialchars($search); ?>">
                    <?php if(isset($_GET['page'])): ?>
                        <input type="hidden" name="page" value="<?php echo intval($_GET['page']); ?>">
                    <?php endif; ?>
                </form>
                <a href="<?php echo BASE_URL; ?>organization/addOrganization.php" class="btn-primary">
                    <i class="fas fa-plus"></i> Add Organization
                </a>
            </div>
        </div>

        <div class="schools-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-organization"></i> All Organizations</h3>
                <span class="schools-count"><?php echo number_format($filteredTotal); ?> schools</span>
            </div>

            <?php if ($schools): ?>
            <div style="overflow-x:auto;">
            <table class="schools-table">
                <thead>
                    <tr>
                        <th>Organization</th>

                        <th>Phone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($schools as $sc): ?>
                <tr>
                    <td>
                        <div class="organization-info">
                            <div class="organization-avatar"><?php echo strtoupper(substr($sc['name'],0,2)); ?></div>
                            <div class="organization-details">
                                <h4><?php echo htmlspecialchars($sc['name']); ?></h4>
                                <p><?php echo !empty($sc['address']) ? htmlspecialchars(substr($sc['address'],0,40)).'…' : 'No address'; ?></p>
                            </div>
                        </div>
                    </td>
                    <td><?php echo $sc['phone'] ? htmlspecialchars($sc['phone']) : '<span class="text-muted">—</span>'; ?></td>
                    <td>
                        <div class="actions">
                            <a href="view-organization.php?id=<?php echo (int)$sc['id']; ?>" class="btn-sm btn-view">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="edit_organization.php?id=<?php echo (int)$sc['id']; ?>" class="btn-sm btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <button class="btn-sm btn-delete"
                                onclick="showDeleteModal(<?php echo (int)$sc['id']; ?>,'<?php echo addslashes(htmlspecialchars($sc['name'])); ?>','<?php echo addslashes(htmlspecialchars(implode(', ', array_filter([$sc['city']??'',$sc['state']??''])))); ?>')">
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
                <i class="fas fa-organization"></i>
                <h3>No schools found</h3>
                <?php if ($search): ?>
                    <p>No schools match "<?php echo htmlspecialchars($search); ?>"</p>
                    <a href="organization.php" class="btn-primary" style="margin-top:1rem;display:inline-flex">
                        <i class="fas fa-arrow-left"></i> Show All Organizations
                    </a>
                <?php else: ?>
                    <p>No schools registered yet.</p>
                    <a href="organization/addOrganization.php" class="btn-primary" style="margin-top:1rem;display:inline-flex">
                        <i class="fas fa-plus"></i> Add First Organization
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $qs = $search ? '&search='.urlencode($search) : '';
            echo $page > 1
                ? "<a href='?page=".($page-1)."$qs'><i class='fas fa-chevron-left'></i></a>"
                : "<span class='disabled'><i class='fas fa-chevron-left'></i></span>";
            $s2=max(1,$page-2); $e2=min($totalPages,$page+2);
            if($s2>1){ echo "<a href='?page=1$qs'>1</a>"; if($s2>2) echo "<span>…</span>"; }
            for($i=$s2;$i<=$e2;$i++)
                echo $i==$page ? "<span class='current'>$i</span>" : "<a href='?page=$i$qs'>$i</a>";
            if($e2<$totalPages){ if($e2<$totalPages-1) echo "<span>…</span>"; echo "<a href='?page=$totalPages$qs'>$totalPages</a>"; }
            echo $page < $totalPages
                ? "<a href='?page=".($page+1)."$qs'><i class='fas fa-chevron-right'></i></a>"
                : "<span class='disabled'><i class='fas fa-chevron-right'></i></span>";
            ?>
        </div>
        <?php endif; ?>

        <div class="stats-summary">
            <div class="stat-item">
                <i class="fas fa-organization"></i>
                <div><h4><?php echo number_format($totalSchools); ?></h4><p>Total Organizations</p></div>
            </div>
            <div class="stat-item">
                <i class="fas fa-phone"></i>
                <div><h4><?php echo number_format($totalWithPhone); ?></h4><p>With Phone</p></div>
            </div>
            <div class="stat-item">
                <i class="fas fa-envelope"></i>
                <div><h4><?php echo number_format($totalWithEmail); ?></h4><p>With Email</p></div>
            </div>
            <div class="stat-item">
                <i class="fas fa-database"></i>
                <div><h4><?php echo number_format($filteredTotal); ?></h4><p>Total Records</p></div>
            </div>
        </div>
    </main>
</div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Delete Organization</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this organization? This action cannot be undone.</p>
            <div class="organization-highlight">
                <strong>Organization:</strong> <span id="deleteSchoolName"></span><br>
                <strong>Location:</strong> <span id="deleteSchoolCity"></span>
            </div>
            <p><strong>Warning:</strong> All associated data will be permanently deleted.</p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Cancel</button>
            <form method="POST" style="display:inline">
                <input type="hidden" name="school_id" id="deleteSchoolId">
                <button type="submit" name="delete_school" class="btn-confirm-delete">Delete Organization</button>
            </form>
        </div>
    </div>
</div>

<script>
    const menuToggle     = document.getElementById('menuToggle');
    const sidebar        = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    menuToggle.addEventListener('click', () => { sidebar.classList.toggle('active'); sidebarOverlay.classList.toggle('active'); });
    sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('active'); sidebarOverlay.classList.remove('active'); });
    window.addEventListener('resize', () => { if(window.innerWidth>1024){ sidebar.classList.remove('active'); sidebarOverlay.classList.remove('active'); }});
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', () => { if(window.innerWidth<=1024){ sidebar.classList.remove('active'); sidebarOverlay.classList.remove('active'); }});
    });
    let searchTimeout;
    document.querySelector('.search-input').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => this.form.submit(), 500);
    });
    function showDeleteModal(id, name, city) {
        document.getElementById('deleteSchoolId').value = id;
        document.getElementById('deleteSchoolName').textContent = name;
        document.getElementById('deleteSchoolCity').textContent = city || '—';
        document.getElementById('deleteModal').classList.add('active');
    }
    function hideDeleteModal() { document.getElementById('deleteModal').classList.remove('active'); }
    document.getElementById('deleteModal').addEventListener('click', e => { if(e.target===document.getElementById('deleteModal')) hideDeleteModal(); });
    document.addEventListener('keydown', e => { if(e.key==='Escape') hideDeleteModal(); });
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