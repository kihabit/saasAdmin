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

$db = Database::getInstance();
$conn = $db->getConnection();

$user_id   = $_SESSION['user_id'];
$username  = $_SESSION['username'];
$driver_id = $_SESSION['driver_id'] ?? null;

if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'Child ID not provided.');
    redirect('students.php');
}

$child_id = intval($_GET['id']);
$child = null;

try {
    $stmt = $conn->prepare("
        SELECT c.*, 
               ul.firstName  AS parent_firstName,
               ul.lastName   AS parent_lastName,
               ul.username   AS parent_username,
               ul.email      AS parent_email,
               ul.phone_number AS parent_phone,
               s.name        AS school_name,
               s.address     AS school_address,
               s.city        AS school_city,
               s.state       AS school_state
        FROM students c
        LEFT JOIN user_login ul ON c.parent_id = ul.user_id
        LEFT JOIN organization     s  ON c.school_id = s.id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $child  = $result->fetch_assoc();
    $stmt->close();

    if (!$child) {
        setFlashMessage('error', 'Child not found.');
        redirect('students.php');
    }
} catch (Exception $e) {
    logAppError("View child error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading child details.');
    redirect('students.php');
}

if (isset($_GET['logout'])) {
    session_unset(); session_destroy(); redirect(LOGIN_PAGE);
}

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Child - <?php echo htmlspecialchars($child['name']); ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1a202c;line-height:1.6}
.app-container{display:flex;min-height:100vh}

/* ── Sidebar ── */
.sidebar{width:280px;background:white;border-right:1px solid #e2e8f0;position:fixed;height:100vh;left:0;top:0;z-index:1000;overflow-y:auto;transition:transform .3s ease}
.sidebar-header{padding:1.5rem;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#0000FF,#4169E1);color:white}
.sidebar-logo{display:flex;align-items:center;gap:12px}
.sidebar-logo img{width:36px;height:36px;border-radius:8px}
.sidebar-logo h2{font-size:1.3rem;font-weight:700}
.sidebar-user{margin-top:1rem;padding:1rem;background:rgba(255,255,255,.1);border-radius:12px}
.sidebar-user .user-avatar{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:600;font-size:16px;margin-bottom:.5rem}
.sidebar-user h3{font-size:1rem;font-weight:600;margin-bottom:.25rem}
.sidebar-user p{font-size:.85rem;opacity:.8}
.sidebar-nav{padding:1rem 0}
.nav-item{display:flex;padding:.75rem 1.5rem;color:#4a5568;text-decoration:none;transition:all .3s;border-left:3px solid transparent;align-items:center;gap:12px}
.nav-item:hover,.nav-item.active{background:#f7fafc;color:#0000FF;border-left-color:#0000FF}
.nav-item i{width:20px;text-align:center;font-size:1.1rem}

/* ── Main Wrapper ── */
.main-wrapper{flex:1;margin-left:280px;transition:margin-left .3s}
.header{background:white;border-bottom:1px solid #e2e8f0;padding:1rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.header-content{display:flex;justify-content:space-between;align-items:center}
.header-left{display:flex;align-items:center;gap:1rem}
.menu-toggle{background:none;border:none;font-size:1.2rem;color:#4a5568;cursor:pointer;padding:8px;border-radius:8px;display:none}
.menu-toggle:hover{background:#f7fafc;color:#0000FF}
.breadcrumb{display:flex;align-items:center;gap:8px;color:#718096;font-size:.9rem}
.breadcrumb a{color:#0000FF;text-decoration:none}
.header-actions{display:flex;align-items:center;gap:1rem}
.btn-back{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;padding:8px 16px;border-radius:8px;text-decoration:none;display:flex;align-items:center;gap:8px;font-weight:500;transition:all .3s}
.btn-back:hover{background:#e2e8f0}
.logout-btn{background:#dc3545;color:white;border:none;padding:8px 16px;border-radius:8px;font-weight:500;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:8px;transition:all .3s}
.logout-btn:hover{background:#c82333;transform:translateY(-1px)}

/* ── Content ── */
.main-content{padding:2rem}

/* Profile Header Card */
.profile-card{background:white;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:2rem}
.profile-header{background:linear-gradient(135deg,#0000FF,#4169E1);color:white;padding:2.5rem 2rem;text-align:center;position:relative}
.profile-avatar{width:110px;height:110px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:2.5rem;margin:0 auto 1rem;border:4px solid rgba(255,255,255,.35);overflow:hidden}
.profile-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.profile-name{font-size:1.8rem;font-weight:700;margin-bottom:.25rem}
.profile-sub{font-size:.95rem;opacity:.85;margin-bottom:.5rem}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 16px;border-radius:20px;font-size:.8rem;font-weight:600;margin-top:.5rem}
.status-active{background:rgba(209,250,229,.3);color:#d1fae5;border:1px solid rgba(209,250,229,.5)}
.status-inactive{background:rgba(254,226,226,.3);color:#fee2e2;border:1px solid rgba(254,226,226,.5)}

/* Action buttons below header */
.profile-actions{display:flex;gap:1rem;padding:1.25rem 2rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;flex-wrap:wrap}
.btn-action{padding:9px 18px;border-radius:10px;font-size:.88rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:all .3s;border:none;cursor:pointer}
.btn-edit-action{background:#fef3c7;color:#92400e}.btn-edit-action:hover{background:#92400e;color:white}
.btn-delete-action{background:#fee2e2;color:#dc2626}.btn-delete-action:hover{background:#dc2626;color:white}
.btn-list-action{background:#eff6ff;color:#1d4ed8}.btn-list-action:hover{background:#1d4ed8;color:white}

/* Info Grid */
.section-card{background:white;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.5rem}
.section-card-header{padding:1.1rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;gap:10px}
.section-card-header h3{font-size:1.05rem;font-weight:600;color:#1a202c}
.section-card-header i{color:#0000FF;font-size:1rem}
.section-card-body{padding:1.5rem}

.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.2rem}
.info-item{padding:1rem 1.1rem;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0}
.info-label{font-size:.78rem;color:#718096;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:.4rem;display:flex;align-items:center;gap:6px}
.info-label i{color:#0000FF;font-size:.75rem}
.info-value{font-size:.97rem;font-weight:600;color:#1a202c;word-break:break-word}
.info-value.muted{color:#9ca3af;font-weight:400}

/* Badges */
.gender-badge{padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:600}
.gender-male{background:#dbeafe;color:#1d4ed8}
.gender-female{background:#fce7f3;color:#9d174d}
.status-badge-in{padding:5px 13px;border-radius:20px;font-size:.78rem;font-weight:600;display:inline-flex;align-items:center;gap:5px}
.badge-active{background:#d1fae5;color:#065f46}
.badge-inactive{background:#fee2e2;color:#991b1b}

/* Map Preview */
.map-box{border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;margin-top:1rem}
.map-box iframe{width:100%;height:280px;border:none;display:block}
.map-link{display:inline-flex;align-items:center;gap:6px;color:#0000FF;font-size:.85rem;font-weight:500;text-decoration:none;margin-top:.6rem}
.map-link:hover{text-decoration:underline}

/* Photo full */
.photo-wrap{display:flex;flex-direction:column;align-items:center;gap:1rem}
.photo-large{width:160px;height:160px;border-radius:50%;object-fit:cover;border:4px solid #e2e8f0}
.photo-placeholder{width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,#0000FF,#4169E1);display:flex;align-items:center;justify-content:center;color:white;font-size:3rem;font-weight:700;border:4px solid #e2e8f0}

/* Delete Modal */
.modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:2000}
.modal-overlay.active{display:flex}
.modal{background:white;border-radius:16px;padding:2rem;max-width:460px;width:90%;box-shadow:0 20px 25px -5px rgba(0,0,0,.15);animation:scaleIn .25s ease}
.modal-icon{width:50px;height:50px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:1rem}
.modal-body{color:#4a5568;line-height:1.6;margin-bottom:1.5rem}
.child-highlight{background:#f3f4f6;padding:.9rem 1rem;border-radius:8px;margin:.75rem 0;border-left:4px solid #dc2626;font-weight:600}
.modal-actions{display:flex;gap:1rem;justify-content:flex-end}
.btn-cancel{background:#f3f4f6;color:#4a5568;border:none;padding:10px 20px;border-radius:8px;font-weight:500;cursor:pointer}
.btn-cancel:hover{background:#e5e7eb}
.btn-confirm-delete{background:#dc2626;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:500;cursor:pointer}
.btn-confirm-delete:hover{background:#b91c1c}

/* Sidebar overlay */
.sidebar-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;display:none}
.sidebar-overlay.active{display:block}

@keyframes scaleIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}

@media(max-width:1024px){
    .sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}
    .main-wrapper{margin-left:0}.menu-toggle{display:block}
    .info-grid{grid-template-columns:1fr}
}
@media(max-width:768px){
    .main-content,.header{padding:1rem}
    .profile-actions{padding:1rem}
    .header-actions .btn-back span{display:none}
}
</style>
</head>
<body>
<div class="app-container">

<!-- ═══ SIDEBAR ═══ -->
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
        <a href="dashboard.php" class="nav-item <?php echo isActivePage('dashboard.php'); ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
        <a href="users.php"     class="nav-item <?php echo isActivePage('users.php'); ?>"><i class="fas fa-users"></i><span>Users</span></a>
        <a href="students.php"  class="nav-item active"><i class="fas fa-child"></i><span>Children</span></a>
        <a href="organization.php"    class="nav-item <?php echo isActivePage('organization.php'); ?>"><i class="fas fa-organization"></i><span>Organization</span></a>
        <a href="profile.php"   class="nav-item <?php echo isActivePage('profile.php'); ?>"><i class="fas fa-user-circle"></i><span>Profile</span></a>
        <a href="alert.php"     class="nav-item <?php echo isActivePage('alert.php'); ?>"><i class="fas fa-bell"></i><span>Alert</span></a>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ═══ MAIN WRAPPER ═══ -->
<div class="main-wrapper" id="mainWrapper">

    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="students.php">Children</a>
                    <i class="fas fa-chevron-right"></i>
                    <span><?php echo htmlspecialchars($child['name']); ?></span>
                </div>
            </div>
            <div class="header-actions">
                <a href="students.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                </a>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">

        <!-- ── PROFILE HEADER CARD ── -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($child['photo'])): ?>
                        <img src="uploads/students/<?php echo htmlspecialchars($child['photo']); ?>" alt="Photo">
                    <?php else: ?>
                        <?php echo strtoupper(substr($child['name'],0,2)); ?>
                    <?php endif; ?>
                </div>
                <h1 class="profile-name"><?php echo htmlspecialchars($child['name']); ?></h1>
                <p class="profile-sub">
                    Class <?php echo htmlspecialchars($child['class']); ?>
                    <?php if (!empty($child['section'])): ?> – Section <?php echo htmlspecialchars($child['section']); ?><?php endif; ?>
                    &nbsp;|&nbsp; Roll No: <?php echo htmlspecialchars($child['roll_number'] ?? 'N/A'); ?>
                </p>
                <?php $isActive = ($child['status'] === 'active'); ?>
                <span class="status-pill <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                    <i class="fas <?php echo $isActive ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                    <?php echo ucfirst($child['status'] ?? 'inactive'); ?>
                </span>
            </div>

            <!-- Quick action buttons -->
        <!--    <div class="profile-actions">-->
        <!--        <a href="edit_students.php?id=<?php echo $child['id']; ?>" class="btn-action btn-edit-action">-->
        <!--            <i class="fas fa-edit"></i> Edit Child-->
        <!--        </a>-->
        <!--        <button class="btn-action btn-delete-action"-->
        <!--            onclick="openDeleteModal(<?php echo $child['id']; ?>, '<?php echo addslashes(htmlspecialchars($child['name'])); ?>')">-->
        <!--            <i class="fas fa-trash"></i> Delete-->
        <!--        </button>-->
        <!--        <a href="students.php" class="btn-action btn-list-action">-->
        <!--            <i class="fas fa-list"></i> All Children-->
        <!--        </a>-->
        <!--    </div>-->
        <!--</div>-->

        <!-- ── BASIC INFO ── -->
        <div class="section-card">
            <div class="section-card-header">
                <i class="fas fa-id-card"></i>
                <h3>Basic Information</h3>
            </div>
            <div class="section-card-body">
                <div class="info-grid">

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-hashtag"></i> Child ID</div>
                        <div class="info-value">#<?php echo $child['id']; ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-venus-mars"></i> Gender</div>
                        <div class="info-value">
                            <?php if (!empty($child['gender'])): ?>
                                <span class="gender-badge <?php echo $child['gender']==='male' ? 'gender-male' : 'gender-female'; ?>">
                                    <i class="fas <?php echo $child['gender']==='male' ? 'fa-mars' : 'fa-venus'; ?>"></i>
                                    <?php echo ucfirst($child['gender']); ?>
                                </span>
                            <?php else: ?><span class="muted">Not specified</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-birthday-cake"></i> Date of Birth</div>
                        <div class="info-value">
                            <?php if (!empty($child['dob'])): ?>
                                <?php echo date('d M Y', strtotime($child['dob'])); ?>
                                <span style="color:#718096;font-size:.82rem;font-weight:400;">
                                    (<?php
                                        $dob = new DateTime($child['dob']);
                                        $now = new DateTime();
                                        echo $dob->diff($now)->y . ' yrs';
                                    ?>)
                                </span>
                            <?php else: ?><span class="muted">—</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-organization"></i> Class / Section</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($child['class']); ?>
                            <?php if (!empty($child['section'])): ?> – <?php echo htmlspecialchars($child['section']); ?><?php endif; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-list-ol"></i> Roll Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['roll_number'] ?? '—'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-circle"></i> Status</div>
                        <div class="info-value">
                            <span class="status-badge-in <?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
                                <i class="fas <?php echo $isActive ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                <?php echo ucfirst($child['status'] ?? 'inactive'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-plus"></i> Registered On</div>
                        <div class="info-value"><?php echo !empty($child['created_at']) ? date('d M Y', strtotime($child['created_at'])) : '—'; ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-check"></i> Last Updated</div>
                        <div class="info-value"><?php echo !empty($child['updated_at']) ? date('d M Y, g:i A', strtotime($child['updated_at'])) : '—'; ?></div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── PARENT INFO ── -->
        <div class="section-card">
            <div class="section-card-header">
                <i class="fas fa-user-friends"></i>
                <h3>Parent / Guardian Details</h3>
            </div>
            <div class="section-card-body">
                <?php if (!empty($child['parent_firstName'])): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-user"></i> Full Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['parent_firstName'].' '.$child['parent_lastName']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-at"></i> Username</div>
                        <div class="info-value">@<?php echo htmlspecialchars($child['parent_username']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['parent_email'] ?? '—'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['parent_phone'] ?? '—'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-hashtag"></i> Parent ID</div>
                        <div class="info-value" style="color:#0000FF;">#<?php echo $child['parent_id']; ?></div>
                    </div>
                </div>
                <?php else: ?>
                    <p style="color:#9ca3af;text-align:center;padding:1rem;">No parent linked to this child.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── SCHOOL INFO ── -->
        <?php if (!empty($child['school_name'])): ?>
        <div class="section-card">
            <div class="section-card-header">
                <i class="fas fa-organization"></i>
                <h3>Organization Information</h3>
            </div>
            <div class="section-card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-building"></i> Organization Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['school_name']); ?></div>
                    </div>
                    <?php if (!empty($child['school_address'])): ?>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['school_address']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($child['school_city'])): ?>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-city"></i> City / State</div>
                        <div class="info-value"><?php echo htmlspecialchars($child['school_city'].', '.$child['school_state']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-hashtag"></i> Organization ID</div>
                        <div class="info-value">#<?php echo $child['school_id']; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── PICKUP / DROP LOCATION ── -->
        <div class="section-card">
            <div class="section-card-header">
                <i class="fas fa-map-marked-alt"></i>
                <h3>Pickup & Drop Location</h3>
            </div>
            <div class="section-card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-map-marker-alt"></i> Pickup Address</div>
                        <div class="info-value"><?php echo !empty($child['pickup_address']) ? htmlspecialchars($child['pickup_address']) : '<span class="muted">Not set</span>'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-flag-checkered"></i> Drop Address</div>
                        <div class="info-value"><?php echo !empty($child['drop_address']) ? htmlspecialchars($child['drop_address']) : '<span class="muted">Not set</span>'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-crosshairs"></i> Pickup Latitude</div>
                        <div class="info-value"><?php echo !empty($child['pickup_lat']) ? htmlspecialchars($child['pickup_lat']) : '<span class="muted">—</span>'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-crosshairs"></i> Pickup Longitude</div>
                        <div class="info-value"><?php echo !empty($child['pickup_lng']) ? htmlspecialchars($child['pickup_lng']) : '<span class="muted">—</span>'; ?></div>
                    </div>
                </div>

                <!-- Map Preview -->
                <?php if (!empty($child['pickup_lat']) && !empty($child['pickup_lng'])): ?>
                <div class="map-box" style="margin-top:1.2rem;">
                    <iframe
                        src="https://maps.google.com/maps?q=<?php echo urlencode($child['pickup_lat']); ?>,<?php echo urlencode($child['pickup_lng']); ?>&z=15&output=embed"
                        allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
                <a class="map-link"
                   href="https://www.google.com/maps?q=<?php echo urlencode($child['pickup_lat']); ?>,<?php echo urlencode($child['pickup_lng']); ?>"
                   target="_blank">
                    <i class="fas fa-external-link-alt"></i> Google Maps mein kholein
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── PHOTO ── -->
        <?php if (!empty($child['photo'])): ?>
        <div class="section-card">
            <div class="section-card-header">
                <i class="fas fa-image"></i>
                <h3>Child Photo</h3>
            </div>
            <div class="section-card-body">
                <div class="photo-wrap">
                    <img src="uploads/students/<?php echo htmlspecialchars($child['photo']); ?>"
                         alt="Photo" class="photo-large">
                    <span style="font-size:.82rem;color:#718096;"><?php echo htmlspecialchars($child['photo']); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div><!-- end main-wrapper -->
</div><!-- end app-container -->

<!-- ── DELETE MODAL ── -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 style="font-size:1.3rem;font-weight:700;margin-bottom:.5rem;">Delete Child Record</h3>
        <div class="modal-body">
            <p>Kya aap sach mein is child ka record delete karna chahte hain? Yeh action undo nahi hogi.</p>
            <div class="child-highlight" id="deleteChildName"></div>
            <p><strong>Warning:</strong> Is bachche se judi saari pickup/drop aur location information bhi permanently delete ho jayegi.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <form method="POST" action="students.php" style="display:inline;">
                <input type="hidden" name="child_id" id="deleteChildId">
                <button type="submit" name="delete_child" class="btn-confirm-delete">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Sidebar
const menuToggle = document.getElementById('menuToggle');
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('sidebarOverlay');
menuToggle.addEventListener('click', () => { sidebar.classList.toggle('active'); overlay.classList.toggle('active'); });
overlay.addEventListener('click', () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
window.addEventListener('resize', () => { if (window.innerWidth > 1024) { sidebar.classList.remove('active'); overlay.classList.remove('active'); } });

// Delete modal
function openDeleteModal(id, name) {
    document.getElementById('deleteChildId').value = id;
    document.getElementById('deleteChildName').textContent = name;
    document.getElementById('deleteModal').classList.add('active');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDeleteModal(); });
</script>
</body>
</html>