<?php
session_start();
require_once '../config.php';
if (!isLoggedIn()) { redirect(LOGIN_PAGE); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset(); session_destroy();
    setFlashMessage('error', 'Session expired.'); redirect(LOGIN_PAGE);
}
$_SESSION['last_activity'] = time();
$db = Database::getInstance();
$conn = $db->getConnection();
$logged_user_id = $_SESSION['user_id'] ?? 0;

if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'User ID not provided.'); redirect('users.php');
}
$view_user_id = intval($_GET['id']);

$user = null;
try {
    $stmt = $conn->prepare("SELECT u.fnc_user_id as fnc_user_id, u.driverId, u.username, u.firstName, u.lastName, u.email, 
                             u.phone_number, u.address, u.city, u.state, u.country, u.zipcode,
                             u.organization_id, u.organization_name, u.userType, u.status, u.created_at, u.last_login, u.latitude, u.longitude,
                             o.org_id as org_custom_id
                             FROM fin_user u
                             LEFT JOIN organization o ON o.id = u.organization_id
                             WHERE u.fnc_user_id = ?");
    $stmt->bind_param("i", $view_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    if (!$user) { setFlashMessage('error', 'User not found.'); redirect('users.php'); }
} catch (Exception $e) {
    logAppError("View user error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading user details.'); redirect('users.php');
}

if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        try {
            $stmt = $conn->prepare("UPDATE fin_user SET token = NULL WHERE fnc_user_id = ?");
            $stmt->bind_param("i", $logged_user_id); $stmt->execute(); $stmt->close();
        } catch (Exception $e) { logAppError("Logout error: " . $e->getMessage()); }
    }
    session_unset(); session_destroy(); redirect(LOGIN_PAGE);
}
$db->close();

$roleNames = [1=>'Super Admin',2=>'Organization Admin',3=>'Branch Manager',4=>'Driver',5=>'Teacher',6=>'Parent',7=>'Employee'];
$ut = intval($user['userType'] ?? 0);
$roleName = $roleNames[$ut] ?? 'Unknown';
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
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1a202c;line-height:1.6;}
        .main-wrapper{font-size:13px;}
        .app-container{display:flex;min-height:100vh}
        .sidebar{width:280px;background:white;border-right:1px solid #e2e8f0;position:fixed;height:100vh;left:0;top:0;z-index:1000;overflow-y:auto}
        .main-wrapper{flex:1;margin-left:280px}
        .header{background:white;border-bottom:1px solid #e2e8f0;padding:.75rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.1)}
        .header-content{display:flex;justify-content:space-between;align-items:center}
        .breadcrumb{display:flex;align-items:center;gap:6px;color:#718096;font-size:.78rem}
        .breadcrumb a{color:#0000FF;text-decoration:none}
        .header-actions{display:flex;align-items:center;gap:.75rem}
        .btn-back{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;padding:6px 13px;border-radius:7px;text-decoration:none;display:flex;align-items:center;gap:6px;font-weight:500;font-size:.78rem;transition:all .3s}
        .btn-back:hover{background:#e2e8f0}
        .btn-edit-page{background:#0000FF;color:white;border:none;padding:6px 13px;border-radius:7px;text-decoration:none;display:flex;align-items:center;gap:6px;font-weight:500;font-size:.78rem;transition:all .3s}
        .btn-edit-page:hover{background:#0000CC}
        .logout-btn{background:#dc3545;color:white;border:none;padding:6px 13px;border-radius:7px;font-weight:500;font-size:.78rem;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:6px}
        .logout-btn:hover{background:#c82333}
        .main-content{padding:1.5rem}
        .profile-card{background:white;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.5rem}
        .profile-header{background:linear-gradient(135deg,#0000FF,#4169E1);color:white;padding:1.5rem;text-align:center}
        .profile-avatar{width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:1.6rem;margin:0 auto .75rem;border:3px solid rgba(255,255,255,.3)}
        .profile-name{font-size:1.3rem;font-weight:700;margin-bottom:.3rem}
        .profile-username{font-size:.82rem;opacity:.9}
        .profile-role{display:inline-block;background:rgba(255,255,255,.2);padding:3px 13px;border-radius:20px;font-size:.78rem;margin-top:.4rem;font-weight:500}
        .profile-body{padding:1.5rem}
        .section-title{font-size:.88rem;font-weight:600;color:#4a5568;margin-bottom:.75rem;padding-bottom:.4rem;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:7px}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;margin-bottom:1.5rem}
        .info-item{padding:.7rem .9rem;background:#f8fafc;border-radius:10px;border:1px solid #e8edf3;transition:box-shadow .2s,border-color .2s}
        .info-item:hover{box-shadow:0 2px 8px rgba(0,0,128,.07);border-color:#c7d2e8}
        .info-label{font-size:.68rem;color:#718096;font-weight:500;margin-bottom:.3rem;display:flex;align-items:center;gap:5px;text-transform:uppercase;letter-spacing:.5px}
        .info-value{font-size:.88rem;font-weight:600;color:#1a202c;word-break:break-word}
        .driver-badge{display:inline-block;background:linear-gradient(135deg,#0000FF,#4169E1);color:white;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600}
        .status-active{color:#065f46;background:#d1fae5;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600;display:inline-block}
        .status-inactive{color:#991b1b;background:#fee2e2;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600;display:inline-block}
        .text-muted{color:#9ca3af;font-weight:400}
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.main-wrapper{margin-left:0}}
        @media(max-width:768px){.main-content{padding:.75rem}.profile-body{padding:.75rem}}
    </style>
</head>
<body>
<div class="app-container">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main-wrapper">
        <header class="header">
            <div class="header-content">
                <div class="breadcrumb">
                    <a href="<?php echo BASE_URL; ?>dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="users.php">Users</a>
                    <i class="fas fa-chevron-right"></i>
                    <span><?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <div class="header-actions">
                    <a href="users.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Logout?')"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </header>
        <main class="main-content">
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php
                        if (!empty($user['firstName']) && !empty($user['lastName']))
                            echo strtoupper(substr($user['firstName'],0,1).substr($user['lastName'],0,1));
                        else echo strtoupper(substr($user['username'],0,2));
                        ?>
                    </div>
                    <h1 class="profile-name">
                        <?php
                        if (!empty($user['firstName']) && !empty($user['lastName']))
                            echo htmlspecialchars($user['firstName'].' '.$user['lastName']);
                        else echo htmlspecialchars($user['username']);
                        ?>
                    </h1>
                    <p class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></p>
                    <span class="profile-role"><i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($roleName); ?></span>
                </div>
                <div class="profile-body">

                    <div class="section-title"><i class="fas fa-user"></i> Basic Information</div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-id-card"></i> User ID</div>
                            <div class="info-value">#<?php echo $user['fnc_user_id']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-car"></i> Driver ID</div>
                            <div class="info-value">
                                <?php if (!empty($user['driverId'])): ?>
                                    <span class="driver-badge"><?php echo htmlspecialchars($user['driverId']); ?></span>
                                <?php else: ?><span class="text-muted">Not assigned</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email'] ?: '—'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['phone_number'] ?: '—'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-circle"></i> Status</div>
                            <div class="info-value">
                                <?php if (intval($user['status']) == 1): ?>
                                    <span class="status-active"><i class="fas fa-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-user-tag"></i> Role</div>
                            <div class="info-value"><?php echo htmlspecialchars($roleName); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($user['organization_id']) || !empty($user['organization_name'])): ?>
                    <div class="section-title"><i class="fas fa-building"></i> Organization</div>
                    <div class="info-grid">
                        <?php if (!empty($user['org_custom_id'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-hashtag"></i> Org ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['org_custom_id']); ?></div>
                        </div>
                        <?php elseif (!empty($user['organization_id'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-hashtag"></i> Org ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['organization_id']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['organization_name'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-school"></i> Org Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['organization_name']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($user['address']) || !empty($user['city']) || !empty($user['country'])): ?>
                    <div class="section-title"><i class="fas fa-map-marker-alt"></i> Address</div>
                    <div class="info-grid">
                        <?php if (!empty($user['address'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-home"></i> Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['address']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['city'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-city"></i> City</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['city']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['state'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-map"></i> State</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['state']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['country'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-globe"></i> Country</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['country']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['zipcode'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-mail-bulk"></i> Zipcode</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['zipcode']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['latitude']) && !empty($user['longitude'])): ?>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-location-arrow"></i> Coordinates</div>
                            <div class="info-value"><?php echo $user['latitude']; ?>, <?php echo $user['longitude']; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="section-title"><i class="fas fa-clock"></i> Activity</div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-calendar-plus"></i> Joined</div>
                            <div class="info-value"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-sign-in-alt"></i> Last Login</div>
                            <div class="info-value">
                                <?php if (!empty($user['last_login'])): ?>
                                    <?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?>
                                <?php else: ?><span class="text-muted">Never logged in</span><?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>