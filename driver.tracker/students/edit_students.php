<?php
session_start();
require_once '../config.php';

if (!isLoggedIn()) { redirect(LOGIN_PAGE); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset(); session_destroy();
    setFlashMessage('error', 'Session expired.'); redirect(LOGIN_PAGE);
}
$_SESSION['last_activity'] = time();

$db   = Database::getInstance();
$conn = $db->getConnection();

$user_id   = $_SESSION['user_id'];
$username  = $_SESSION['username'];
$driver_id = $_SESSION['driver_id'] ?? null;

if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'Child ID not provided.');
    redirect('students.php');
}

$child_id = intval($_GET['id']);
$errors   = [];
$success  = '';

// ── Fetch schools for dropdown ──
$schools = [];
try {
    $res = $conn->query("SELECT id, name FROM organization ORDER BY name ASC");
    while ($row = $res->fetch_assoc()) $schools[] = $row;
} catch (Exception $e) { /* optional */ }

// ── Fetch parents for dropdown ──
$parents = [];
try {
    $res = $conn->query("SELECT user_id, firstName, lastName, username FROM user_login ORDER BY firstName ASC");
    while ($row = $res->fetch_assoc()) $parents[] = $row;
} catch (Exception $e) { /* optional */ }

// ── Fetch child ──
$child = null;
try {
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $child = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$child) { setFlashMessage('error', 'Child not found.'); redirect('students.php'); }
} catch (Exception $e) {
    logAppError("Edit child fetch: " . $e->getMessage());
    setFlashMessage('error', 'Error loading child.'); redirect('students.php');
}

// ── Handle POST (save) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name']           ?? '');
    $class          = trim($_POST['class']          ?? '');
    $section        = trim($_POST['section']        ?? '');
    $roll_number    = trim($_POST['roll_number']    ?? '');
    $gender         = trim($_POST['gender']         ?? '');
    $dob            = trim($_POST['dob']            ?? '');
    $status         = trim($_POST['status']         ?? 'active');
    $school_id      = intval($_POST['school_id']    ?? 0) ?: null;
    $parent_id      = intval($_POST['parent_id']    ?? 0) ?: null;
    $driver_id_val  = trim($_POST['driver_id']      ?? '') ?: null;
    $pickup_address = trim($_POST['pickup_address'] ?? '');
    $drop_address   = trim($_POST['drop_address']   ?? '');
    $pickup_lat     = trim($_POST['pickup_lat']     ?? '') ?: null;
    $pickup_lng     = trim($_POST['pickup_lng']     ?? '') ?: null;

    // Validations
    if (empty($name))  $errors[] = 'Child name is required.';
    if (empty($class)) $errors[] = 'Class is required.';
    if (!empty($pickup_lat)  && !is_numeric($pickup_lat))  $errors[] = 'Pickup Latitude must be a valid number.';
    if (!empty($pickup_lng)  && !is_numeric($pickup_lng))  $errors[] = 'Pickup Longitude must be a valid number.';

    // Photo upload
    $photo = $child['photo']; // keep old by default
    if (!empty($_FILES['photo']['name'])) {
        $allowed = ['jpg','jpeg','png','gif','webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid photo format. Allowed: jpg, jpeg, png, gif, webp';
        } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo size must be under 2MB.';
        } else {
            $uploadDir = 'uploads/students/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $newName = 'child_' . $child_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $newName)) {
                // Delete old photo
                if (!empty($child['photo']) && file_exists($uploadDir . $child['photo'])) {
                    unlink($uploadDir . $child['photo']);
                }
                $photo = $newName;
            } else {
                $errors[] = 'Photo upload failed. Please try again.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE students SET
                    name=?, class=?, section=?, roll_number=?, gender=?, dob=?,
                    status=?, school_id=?, parent_id=?, driver_id=?,
                    pickup_address=?, drop_address=?, pickup_lat=?, pickup_lng=?,
                    photo=?, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param(
                'sssssssiissssssi',
                $name, $class, $section, $roll_number, $gender, $dob,
                $status, $school_id, $parent_id, $driver_id_val,
                $pickup_address, $drop_address, $pickup_lat, $pickup_lng,
                $photo, $child_id
            );
            if ($stmt->execute()) {
                logAppError("Child updated: ID $child_id by $username");
                // Refresh child data
                $stmt2 = $conn->prepare("SELECT * FROM students WHERE id=?");
                $stmt2->bind_param("i", $child_id);
                $stmt2->execute();
                $child = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
                $success = "Child record updated successfully!";
            } else {
                $errors[] = 'Database error. Please try again.';
            }
            $stmt->close();
        } catch (Exception $e) {
            logAppError("Edit child save: " . $e->getMessage());
            $errors[] = 'Error saving changes. Please try again.';
        }
    }
}

if (isset($_GET['logout'])) { session_unset(); session_destroy(); redirect(LOGIN_PAGE); }
$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Child - <?php echo htmlspecialchars($child['name']); ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1a202c;line-height:1.6}
.app-container{display:flex;min-height:100vh}

/* Sidebar */
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

/* Main */
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

/* Content */
.main-content{padding:2rem}
.page-header{margin-bottom:2rem}
.page-header h1{font-size:2rem;font-weight:700;margin-bottom:.25rem}
.page-header p{color:#718096}

/* Alerts */
.alert{padding:1rem 1.5rem;border-radius:12px;display:flex;align-items:flex-start;gap:12px;font-weight:500;margin-bottom:1.5rem}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert ul{margin:.5rem 0 0 1rem}
.alert ul li{margin-bottom:4px;font-weight:400}

/* Form Cards */
.form-card{background:white;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.5rem}
.form-card-header{padding:1.1rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;gap:10px}
.form-card-header h3{font-size:1.05rem;font-weight:600;color:#1a202c}
.form-card-header i{color:#0000FF}
.form-body{padding:1.75rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.4rem}
.form-group{display:flex;flex-direction:column}
.form-group.full-width{grid-column:1/-1}
label{font-weight:600;font-size:.88rem;color:#374151;margin-bottom:.45rem;display:flex;align-items:center;gap:6px}
label .req{color:#dc2626}
.form-control{padding:11px 15px;border:1px solid #e2e8f0;border-radius:10px;font-size:.93rem;font-family:inherit;color:#1a202c;background:white;transition:all .3s;width:100%}
.form-control:focus{outline:none;border-color:#0000FF;box-shadow:0 0 0 3px rgba(0,0,255,.1)}
.form-control::placeholder{color:#9ca3af}
select.form-control{cursor:pointer}
textarea.form-control{resize:vertical;min-height:80px}
.hint{font-size:.78rem;color:#9ca3af;margin-top:3px}

/* Coord wrapper */
.coord-wrapper{position:relative}
.coord-wrapper .form-control{padding-right:40px}
.coord-clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:.8rem;padding:4px;border-radius:4px}
.coord-clear:hover{color:#dc2626}
.geocode-status{padding:7px 12px;border-radius:8px;font-size:.82rem;font-weight:500;margin-top:6px;display:none;align-items:center;gap:7px}
.geocode-status.loading{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:flex}
.geocode-status.success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;display:flex}
.geocode-status.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;display:flex}
.autofill-btn{background:#0000FF;color:white;border:none;padding:5px 13px;border-radius:8px;font-size:.75rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .2s;white-space:nowrap}
.autofill-btn:hover{background:#0000CC}
.autofill-btn:disabled{background:#94a3b8;cursor:not-allowed}
.section-label-row{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;font-size:.75rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;padding-bottom:.5rem;border-bottom:1px solid #f1f5f9;margin-top:.25rem}

/* Photo preview */
.photo-preview-wrap{display:flex;align-items:center;gap:1.5rem;margin-bottom:1rem}
.photo-preview{width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0}
.photo-placeholder-sm{width:90px;height:90px;border-radius:50%;background:linear-gradient(135deg,#0000FF,#4169E1);display:flex;align-items:center;justify-content:center;color:white;font-size:1.8rem;font-weight:700;border:3px solid #e2e8f0;flex-shrink:0}
.photo-info{font-size:.82rem;color:#718096}
.photo-info strong{display:block;color:#374151;margin-bottom:3px}

/* Form actions */
.form-actions{display:flex;gap:1rem;padding:1.5rem 1.75rem;border-top:1px solid #e2e8f0;background:#f8fafc;flex-wrap:wrap}
.btn-submit{background:#0000FF;color:white;border:none;padding:12px 26px;border-radius:12px;font-weight:600;cursor:pointer;font-size:.97rem;font-family:inherit;display:flex;align-items:center;gap:8px;transition:all .3s}
.btn-submit:hover{background:#0000CC;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,255,.3)}
.btn-cancel-form{background:#f3f4f6;color:#4a5568;border:none;padding:12px 22px;border-radius:12px;font-weight:500;font-size:.97rem;font-family:inherit;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:8px;transition:all .3s}
.btn-cancel-form:hover{background:#e5e7eb}

/* Sidebar overlay */
.sidebar-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;display:none}
.sidebar-overlay.active{display:block}

@media(max-width:1024px){
    .sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}
    .main-wrapper{margin-left:0}.menu-toggle{display:block}
    .form-grid{grid-template-columns:1fr}.form-group.full-width,.section-label-row{grid-column:1}
    .section-label-row{flex-direction:column;align-items:flex-start;gap:6px}
}
@media(max-width:768px){
    .main-content,.header{padding:1rem}.form-body{padding:1.25rem}
    .form-actions{padding:1rem;flex-direction:column}
    .header-actions .btn-back span{display:none}
}
</style>
</head>
<body>
<div class="app-container">

<!-- SIDEBAR -->
<!-- <nav class="sidebar" id="sidebar">
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
</nav> -->
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- MAIN -->
<div class="main-wrapper" id="mainWrapper">
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="<?php echo BASE_URL;?>dashboard.php">Home</a><i class="fas fa-chevron-right"></i>
                    <a href="<?php echo BASE_URL;?>students/students.php">Children</a><i class="fas fa-chevron-right"></i>
                    <a href="<?php echo BASE_URL;?>students/view_students.php?id=<?php echo $child_id; ?>"><?php echo htmlspecialchars($child['name']); ?></a><i class="fas fa-chevron-right"></i>
                    <span>Edit</span>
                </div>
            </div>
            <div class="header-actions">
                <a href="<?php echo BASE_URL;?>students/view_child.php?id=<?php echo $child_id; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i><span>Back</span>
                </a>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="main-content">

        <div class="page-header">
            <h1><i class="fas fa-user-edit" style="color:#0000FF;margin-right:10px;"></i>Edit Child</h1>
            <p>Update the details for <strong><?php echo htmlspecialchars($child['name']); ?></strong></p>
        </div>

        <!-- Success -->
        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle" style="font-size:1.2rem;flex-shrink:0;"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
        </div>
        <?php endif; ?>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div><strong>Please fix the following:</strong>
                <ul><?php foreach($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <!-- ── BASIC INFO ── -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-id-card"></i>
                    <h3>Basic Information</h3>
                </div>
                <div class="form-body">
                    <div class="form-grid">

                        <div class="form-group full-width">
                            <label><i class="fas fa-child" style="color:#0000FF;font-size:.8rem;"></i> Child Name <span class="req">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   placeholder="e.g. Aarav Sharma"
                                   value="<?php echo htmlspecialchars($child['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-chalkboard" style="color:#0000FF;font-size:.8rem;"></i> Class <span class="req">*</span></label>
                            <input type="text" name="class" class="form-control"
                                   placeholder="e.g. 5, 10, KG"
                                   value="<?php echo htmlspecialchars($child['class']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-layer-group" style="color:#0000FF;font-size:.8rem;"></i> Section</label>
                            <input type="text" name="section" class="form-control"
                                   placeholder="e.g. A, B, C"
                                   value="<?php echo htmlspecialchars($child['section'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-list-ol" style="color:#0000FF;font-size:.8rem;"></i> Roll Number</label>
                            <input type="text" name="roll_number" class="form-control"
                                   placeholder="e.g. 42"
                                   value="<?php echo htmlspecialchars($child['roll_number'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-venus-mars" style="color:#0000FF;font-size:.8rem;"></i> Gender</label>
                            <select name="gender" class="form-control">
                                <option value="">— Select —</option>
                                <option value="male"   <?php echo ($child['gender']==='male')   ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($child['gender']==='female') ? 'selected' : ''; ?>>Female</option>
                                <option value="other"  <?php echo ($child['gender']==='other')  ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-birthday-cake" style="color:#0000FF;font-size:.8rem;"></i> Date of Birth</label>
                            <input type="date" name="dob" class="form-control"
                                   value="<?php echo htmlspecialchars($child['dob'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-toggle-on" style="color:#0000FF;font-size:.8rem;"></i> Status</label>
                            <select name="status" class="form-control">
                                <option value="active"   <?php echo (($child['status'] ?? 'active')==='active')   ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (($child['status'] ?? '')==='inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── PARENT & SCHOOL ── -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-user-friends"></i>
                    <h3>Parent & Organization</h3>
                </div>
                <div class="form-body">
                    <div class="form-grid">

                        <div class="form-group">
                            <label><i class="fas fa-user" style="color:#0000FF;font-size:.8rem;"></i> Parent / Guardian</label>
                            <select name="parent_id" class="form-control">
                                <option value="">— No Parent —</option>
                                <?php foreach ($parents as $p): ?>
                                <option value="<?php echo $p['user_id']; ?>"
                                    <?php echo ($child['parent_id'] == $p['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['firstName'].' '.$p['lastName'].' (@'.$p['username'].')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">Parent ka account select karein</span>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-organization" style="color:#0000FF;font-size:.8rem;"></i> Organization</label>
                            <select name="school_id" class="form-control">
                                <option value="">— No Organization —</option>
                                <?php foreach ($schools as $s): ?>
                                <option value="<?php echo $s['id']; ?>"
                                    <?php echo ($child['school_id'] == $s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-car" style="color:#0000FF;font-size:.8rem;"></i> Driver ID</label>
                            <input type="text" name="driver_id" class="form-control"
                                   placeholder="e.g. DRV001"
                                   value="<?php echo htmlspecialchars($child['driver_id'] ?? ''); ?>">
                            <span class="hint">Optional</span>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── PICKUP / DROP LOCATION ── -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-map-marked-alt"></i>
                    <h3>Pickup & Drop Location</h3>
                </div>
                <div class="form-body">
                    <div class="form-grid">

                        <div class="form-group full-width">
                            <label><i class="fas fa-map-marker-alt" style="color:#0000FF;font-size:.8rem;"></i> Pickup Address</label>
                            <textarea id="pickupAddr" name="pickup_address" class="form-control"
                                      placeholder="Full pickup address"><?php echo htmlspecialchars($child['pickup_address'] ?? ''); ?></textarea>
                            <div class="geocode-status" id="geocodeStatus"></div>
                        </div>

                        <div class="form-group full-width">
                            <label><i class="fas fa-flag-checkered" style="color:#0000FF;font-size:.8rem;"></i> Drop Address</label>
                            <textarea name="drop_address" class="form-control"
                                      placeholder="Full drop address"><?php echo htmlspecialchars($child['drop_address'] ?? ''); ?></textarea>
                        </div>

                        <!-- Coordinates label row -->
                        <div class="section-label-row">
                            <span>Coordinates</span>
                            <button type="button" id="geocodeBtn" class="autofill-btn" onclick="fetchCoords()">
                                <i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address
                            </button>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-crosshairs" style="color:#0000FF;font-size:.8rem;"></i> Pickup Latitude</label>
                            <div class="coord-wrapper">
                                <input type="text" id="latField" name="pickup_lat" class="form-control"
                                       placeholder="e.g. 28.61234567"
                                       value="<?php echo htmlspecialchars($child['pickup_lat'] ?? ''); ?>"
                                       oninput="showManual()">
                                <button type="button" class="coord-clear" onclick="clearCoord('latField')" title="Clear"><i class="fas fa-times"></i></button>
                            </div>
                            <span class="hint">Auto-fill karein ya manually type karein</span>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-crosshairs" style="color:#0000FF;font-size:.8rem;"></i> Pickup Longitude</label>
                            <div class="coord-wrapper">
                                <input type="text" id="lngField" name="pickup_lng" class="form-control"
                                       placeholder="e.g. 77.20890123"
                                       value="<?php echo htmlspecialchars($child['pickup_lng'] ?? ''); ?>"
                                       oninput="showManual()">
                                <button type="button" class="coord-clear" onclick="clearCoord('lngField')" title="Clear"><i class="fas fa-times"></i></button>
                            </div>
                            <span class="hint">Auto-fill karein ya manually type karein</span>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── PHOTO ── -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-image"></i>
                    <h3>Child Photo</h3>
                </div>
                <div class="form-body">
                    <div class="photo-preview-wrap">
                        <?php if (!empty($child['photo'])): ?>
                            <img src="uploads/students/<?php echo htmlspecialchars($child['photo']); ?>"
                                 alt="Current Photo" class="photo-preview" id="photoPreview">
                        <?php else: ?>
                            <div class="photo-placeholder-sm" id="photoPlaceholder">
                                <?php echo strtoupper(substr($child['name'],0,2)); ?>
                            </div>
                        <?php endif; ?>
                        <div class="photo-info">
                            <strong>Current Photo</strong>
                            <?php echo !empty($child['photo']) ? htmlspecialchars($child['photo']) : 'No photo uploaded'; ?>
                            <br><span style="margin-top:4px;display:block;">Naya photo upload karne se purana replace ho jayega</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-upload" style="color:#0000FF;font-size:.8rem;"></i> Upload New Photo</label>
                        <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(this)">
                        <span class="hint">JPG, PNG, GIF, WebP — max 2MB. Optional.</span>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Changes</button>
                <a href="view_child.php?id=<?php echo $child_id; ?>" class="btn-cancel-form"><i class="fas fa-times"></i> Cancel</a>
            </div>

        </form>
    </main>
</div>
</div>

<script>
// Sidebar
const menuToggle = document.getElementById('menuToggle');
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('sidebarOverlay');
menuToggle.addEventListener('click', () => { sidebar.classList.toggle('active'); overlay.classList.toggle('active'); });
overlay.addEventListener('click',   () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
window.addEventListener('resize',   () => { if(window.innerWidth>1024){sidebar.classList.remove('active');overlay.classList.remove('active');} });

// Photo preview
function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        let img = document.getElementById('photoPreview');
        const ph = document.getElementById('photoPlaceholder');
        if (!img) {
            img = document.createElement('img');
            img.id = 'photoPreview';
            img.className = 'photo-preview';
            if (ph) ph.replaceWith(img);
            else document.querySelector('.photo-preview-wrap').prepend(img);
        }
        img.src = e.target.result;
        if (ph) ph.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

// Geocode
function showStatus(type, msg) {
    const el = document.getElementById('geocodeStatus');
    el.className = 'geocode-status ' + type;
    el.innerHTML = msg;
}
function showManual() { /* just allow typing, no badge needed */ }
function clearCoord(id) {
    document.getElementById(id).value = '';
    if (!document.getElementById('latField').value && !document.getElementById('lngField').value) {
        document.getElementById('geocodeStatus').className = 'geocode-status';
    }
}
function fetchCoords() {
    const addr = document.getElementById('pickupAddr').value.trim();
    if (!addr) { showStatus('error','<i class="fas fa-exclamation-circle"></i>&nbsp; Pehle pickup address darj karein.'); return; }
    const btn = document.getElementById('geocodeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching...';
    showStatus('loading','<i class="fas fa-spinner fa-spin"></i>&nbsp; Coordinates dhundh rahe hain...');
    fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(addr + ', India') + '&limit=1', { headers:{'Accept-Language':'en'} })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address';
        if (data && data.length > 0) {
            document.getElementById('latField').value = parseFloat(data[0].lat).toFixed(8);
            document.getElementById('lngField').value = parseFloat(data[0].lon).toFixed(8);
            showStatus('success','<i class="fas fa-check-circle"></i>&nbsp; Coordinates mil gaye: ' + document.getElementById('latField').value + ', ' + document.getElementById('lngField').value);
        } else {
            showStatus('error','<i class="fas fa-exclamation-circle"></i>&nbsp; Location nahi mila. Manually coordinates darj karein.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address';
        showStatus('error','<i class="fas fa-exclamation-circle"></i>&nbsp; Network error. Manually coordinates darj karein.');
    });
}
</script>
</body>
</html>