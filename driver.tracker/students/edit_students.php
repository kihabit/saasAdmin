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

// Fetch organizations
$schools = [];
try {
    $res = $conn->query("SELECT id, name FROM organization ORDER BY name ASC");
    while ($row = $res->fetch_assoc()) $schools[] = $row;
} catch (Exception $e) {}

// ✅ Fetch ALL parents with organization_id
$parents = [];
try {
    $res = $conn->query("SELECT user_id, firstName, lastName, username, organization_id FROM edu_user WHERE userType = 6 ORDER BY firstName ASC");
    while ($row = $res->fetch_assoc()) $parents[] = $row;
} catch (Exception $e) { logAppError("Fetch parents: " . $e->getMessage()); }

// ✅ Fetch ALL drivers with organization_id
$drivers = [];
try {
    $res = $conn->query("SELECT driverId, firstName, lastName, organization_id FROM edu_user WHERE userType = 4 ORDER BY firstName ASC");
    while ($row = $res->fetch_assoc()) $drivers[] = $row;
} catch (Exception $e) { logAppError("Fetch drivers: " . $e->getMessage()); }

// Fetch child
$child = null;
try {
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $child_id); $stmt->execute();
    $child = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$child) { setFlashMessage('error', 'Child not found.'); redirect('students.php'); }
} catch (Exception $e) {
    logAppError("Edit child fetch: " . $e->getMessage());
    setFlashMessage('error', 'Error loading child.'); redirect('students.php');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name']           ?? '');
    $class          = trim($_POST['class']          ?? '');
    $section        = trim($_POST['section']        ?? '');
    $roll_number    = trim($_POST['roll_number']    ?? '');
    $gender         = trim($_POST['gender']         ?? '');
    $dob            = trim($_POST['dob']            ?? '');
    $status         = trim($_POST['status']         ?? 'active');
    $organization_id      = intval($_POST['organization_id']    ?? 0) ?: null;
    $parent_id      = intval($_POST['parent_id']    ?? 0) ?: null;
    $driver_id_val  = isset($_POST['driver_id']) && $_POST['driver_id'] !== '' ? trim($_POST['driver_id']) : null;
    $pickup_address = trim($_POST['pickup_address'] ?? '');
    $drop_address   = trim($_POST['drop_address']   ?? '');
    $pickup_lat     = (trim($_POST['pickup_lat'] ?? '') !== '') ? trim($_POST['pickup_lat']) : null;
    $pickup_lng     = (trim($_POST['pickup_lng'] ?? '') !== '') ? trim($_POST['pickup_lng']) : null;

    if (empty($name))  $errors[] = 'Child name is required.';
    if (empty($class)) $errors[] = 'Class is required.';
    if (!empty($pickup_lat) && !is_numeric($pickup_lat)) $errors[] = 'Pickup Latitude must be a valid number.';
    if (!empty($pickup_lng) && !is_numeric($pickup_lng)) $errors[] = 'Pickup Longitude must be a valid number.';

    $photo = $child['photo'];
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
                if (!empty($child['photo']) && file_exists($uploadDir . $child['photo'])) unlink($uploadDir . $child['photo']);
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
                    status=?, organization_id=?, parent_id=?, driver_id=?,
                    pickup_address=?, drop_address=?, pickup_lat=?, pickup_lng=?,
                    photo=?, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param(
                'sssssssiissssssi',
                $name, $class, $section, $roll_number, $gender, $dob,
                $status, $organization_id, $parent_id, $driver_id_val,
                $pickup_address, $drop_address, $pickup_lat, $pickup_lng,
                $photo, $child_id
            );
            if ($stmt->execute()) {
                logAppError("Child updated: ID $child_id by $username");
                $stmt2 = $conn->prepare("SELECT * FROM students WHERE id=?");
                $stmt2->bind_param("i", $child_id); $stmt2->execute();
                $child = $stmt2->get_result()->fetch_assoc(); $stmt2->close();
                $success = "Child record updated successfully!";
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
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

// Pass data to JS
$parentsJson = json_encode($parents);
$driversJson = json_encode($drivers);
$currentOrgId    = $child['organization_id'] ?? '';
$currentParentId = $child['parent_id'] ?? '';
$currentDriverId = $child['driver_id'] ?? '';
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
.nav-item .nav-text{flex:1}

/* Main */
.main-wrapper{flex:1;margin-left:280px;transition:margin-left .3s}
.header{background:white;border-bottom:1px solid #e2e8f0;padding:.75rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.header-content{display:flex;justify-content:space-between;align-items:center}
.header-left{display:flex;align-items:center;gap:1rem}
.menu-toggle{background:none;border:none;font-size:1.2rem;color:#4a5568;cursor:pointer;padding:8px;border-radius:8px;display:none;transition:all .3s}
.menu-toggle:hover{background:#f7fafc;color:#0000FF}
.breadcrumb{display:flex;align-items:center;gap:8px;color:#718096;font-size:.85rem;flex-wrap:wrap}
.breadcrumb a{color:#0000FF;text-decoration:none}
.header-actions{display:flex;align-items:center;gap:.75rem}
.btn-back{background:#f7fafc;color:#4a5568;border:1px solid #e2e8f0;padding:7px 14px;border-radius:8px;font-size:.85rem;text-decoration:none;display:flex;align-items:center;gap:6px;font-weight:500;transition:all .3s}
.btn-back:hover{background:#e2e8f0}
.logout-btn{background:#dc3545;color:white;border:none;padding:7px 14px;border-radius:8px;font-weight:500;font-size:.85rem;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all .3s}
.logout-btn:hover{background:#c82333;transform:translateY(-1px)}

/* Content */
.main-content{padding:1.5rem 2rem}

/* Page Header */
.page-header{margin-bottom:1.25rem}
.page-header h1{font-size:1.35rem;font-weight:700;margin-bottom:2px;display:flex;align-items:center;gap:8px}
.page-header p{color:#718096;font-size:.82rem}

/* Alerts */
.alert{padding:.85rem 1.25rem;border-radius:10px;display:flex;align-items:flex-start;gap:10px;font-weight:500;font-size:.88rem;margin-bottom:1.25rem}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert ul{margin:.4rem 0 0 1rem}
.alert ul li{margin-bottom:3px;font-weight:400}

/* Form Cards */
.form-card{background:white;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.25rem}
.form-card-header{padding:.9rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;gap:9px}
.form-card-header h3{font-size:.95rem;font-weight:600;color:#1a202c}
.form-card-header i{color:#0000FF;font-size:.9rem}
.form-body{padding:1.4rem 1.5rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
.form-group{display:flex;flex-direction:column}
.form-group.full-width{grid-column:1/-1}
label{font-weight:600;font-size:.83rem;color:#374151;margin-bottom:.4rem;display:flex;align-items:center;gap:6px}
label .req{color:#dc2626}
.form-control{padding:10px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:.88rem;font-family:inherit;color:#1a202c;background:white;transition:all .3s;width:100%}
.form-control:focus{outline:none;border-color:#0000FF;box-shadow:0 0 0 3px rgba(0,0,255,.1)}
.form-control::placeholder{color:#9ca3af}
select.form-control{cursor:pointer}
textarea.form-control{resize:vertical;min-height:75px}
.hint{font-size:.74rem;color:#9ca3af;margin-top:3px}

/* Filter badge */
.filter-badge{display:none;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:7px;padding:5px 10px;font-size:.76rem;font-weight:500;margin-top:5px;align-items:center;gap:5px}
.filter-badge.show{display:flex}
.filter-badge i{font-size:.72rem}

/* Coord wrapper */
.coord-wrapper{position:relative}
.coord-wrapper .form-control{padding-right:38px}
.coord-clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:.78rem;padding:4px;border-radius:4px}
.coord-clear:hover{color:#dc2626}
.geocode-status{padding:6px 11px;border-radius:8px;font-size:.78rem;font-weight:500;margin-top:5px;display:none;align-items:center;gap:6px}
.geocode-status.loading{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:flex}
.geocode-status.success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;display:flex}
.geocode-status.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;display:flex}
.autofill-btn{background:#0000FF;color:white;border:none;padding:5px 12px;border-radius:8px;font-size:.73rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .2s;white-space:nowrap;font-family:inherit}
.autofill-btn:hover{background:#0000CC}
.autofill-btn:disabled{background:#94a3b8;cursor:not-allowed}
.section-label-row{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;padding-bottom:.45rem;border-bottom:1px solid #f1f5f9;margin-top:.2rem}

/* Photo preview */
.photo-preview-wrap{display:flex;align-items:center;gap:1.25rem;margin-bottom:.85rem}
.photo-preview{width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0}
.photo-placeholder-sm{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#0000FF,#4169E1);display:flex;align-items:center;justify-content:center;color:white;font-size:1.6rem;font-weight:700;border:3px solid #e2e8f0;flex-shrink:0}
.photo-info{font-size:.78rem;color:#718096}
.photo-info strong{display:block;color:#374151;margin-bottom:2px;font-size:.82rem}

/* Form actions */
.form-actions{display:flex;gap:.85rem;padding:1.1rem 1.5rem;border-top:1px solid #e2e8f0;background:#f8fafc;flex-wrap:wrap}
.btn-submit{background:#0000FF;color:white;border:none;padding:10px 22px;border-radius:10px;font-weight:600;cursor:pointer;font-size:.9rem;font-family:inherit;display:flex;align-items:center;gap:7px;transition:all .3s}
.btn-submit:hover{background:#0000CC;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,255,.3)}
.btn-cancel-form{background:#f3f4f6;color:#4a5568;border:none;padding:10px 20px;border-radius:10px;font-weight:500;font-size:.9rem;font-family:inherit;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:7px;transition:all .3s}
.btn-cancel-form:hover{background:#e5e7eb}

/* Sidebar overlay */
.sidebar-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;display:none}
.sidebar-overlay.active{display:block}

@media(max-width:1024px){
    .sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}
    .main-wrapper{margin-left:0}.menu-toggle{display:block}
    .form-grid{grid-template-columns:1fr}
    .form-group.full-width,.section-label-row{grid-column:1}
    .section-label-row{flex-direction:column;align-items:flex-start;gap:6px}
}
@media(max-width:768px){
    .header,.main-content{padding:.75rem 1rem}
    .form-body{padding:1rem}
    .form-actions{padding:.85rem 1rem;flex-direction:column}
    .header-actions .btn-back span{display:none}
}
</style>
</head>
<body>
<div class="app-container">

<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="main-wrapper" id="mainWrapper">
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="<?php echo BASE_URL; ?>dashboard.php">Home</a><i class="fas fa-chevron-right"></i>
                    <a href="<?php echo BASE_URL; ?>students/students.php">Students</a><i class="fas fa-chevron-right"></i>
                    <a href="<?php echo BASE_URL; ?>students/view_students.php?id=<?php echo $child_id; ?>"><?php echo htmlspecialchars($child['name']); ?></a><i class="fas fa-chevron-right"></i>
                    <span>Edit</span>
                </div>
            </div>
            <div class="header-actions">
                <a href="<?php echo BASE_URL; ?>students/view_students.php?id=<?php echo $child_id; ?>" class="btn-back">
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
            <h1><i class="fas fa-user-edit" style="color:#0000FF;font-size:1.2rem;"></i> Edit Child</h1>
            <p>Update the details for <strong><?php echo htmlspecialchars($child['name']); ?></strong></p>
        </div>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
            <div><strong>Please fix the following:</strong>
                <ul><?php foreach($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <!-- Basic Info -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-id-card"></i>
                    <h3>Basic Information</h3>
                </div>
                <div class="form-body">
                    <div class="form-grid">

                        <div class="form-group full-width">
                            <label><i class="fas fa-child" style="color:#0000FF;font-size:.78rem;"></i> Child Name <span class="req">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Aarav Sharma"
                                   value="<?php echo htmlspecialchars($child['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-chalkboard" style="color:#0000FF;font-size:.78rem;"></i> Class <span class="req">*</span></label>
                            <input type="text" name="class" class="form-control" placeholder="e.g. 5, 10, KG"
                                   value="<?php echo htmlspecialchars($child['class']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-layer-group" style="color:#0000FF;font-size:.78rem;"></i> Section</label>
                            <input type="text" name="section" class="form-control" placeholder="e.g. A, B, C"
                                   value="<?php echo htmlspecialchars($child['section'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-list-ol" style="color:#0000FF;font-size:.78rem;"></i> Roll Number</label>
                            <input type="text" name="roll_number" class="form-control" placeholder="e.g. 42"
                                   value="<?php echo htmlspecialchars($child['roll_number'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-venus-mars" style="color:#0000FF;font-size:.78rem;"></i> Gender</label>
                            <select name="gender" class="form-control">
                                <option value="">— Select —</option>
                                <option value="male"   <?php echo ($child['gender']==='male')   ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($child['gender']==='female') ? 'selected' : ''; ?>>Female</option>
                                <option value="other"  <?php echo ($child['gender']==='other')  ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-birthday-cake" style="color:#0000FF;font-size:.78rem;"></i> Date of Birth</label>
                            <input type="date" name="dob" class="form-control"
                                   value="<?php echo htmlspecialchars($child['dob'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-toggle-on" style="color:#0000FF;font-size:.78rem;"></i> Status</label>
                            <select name="status" class="form-control">
                                <option value="active"   <?php echo (($child['status'] ?? 'active')==='active')   ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (($child['status'] ?? '')==='inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Parent & Organization -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-user-friends"></i>
                    <h3>Parent &amp; Organization</h3>
                </div>
                <div class="form-body">
                    <div class="form-grid">

                        <!-- Organization -->
                        <div class="form-group">
                            <label><i class="fas fa-building" style="color:#0000FF;font-size:.78rem;"></i> Organization</label>
                            <select name="organization_id" id="organizationSelect" class="form-control" onchange="filterByOrganization()">
                                <option value="">— No Organization —</option>
                                <?php foreach ($schools as $s): ?>
                                <option value="<?php echo $s['id']; ?>"
                                    <?php echo ($child['organization_id'] == $s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">Select to filter parents & drivers</span>
                        </div>

                        <!-- Parent -->
                        <div class="form-group">
                            <label><i class="fas fa-user" style="color:#0000FF;font-size:.78rem;"></i> Parent / Guardian</label>
                            <select name="parent_id" id="parentSelect" class="form-control">
                                <option value="">— Select Organization First —</option>
                            </select>
                            <div class="filter-badge" id="parentBadge">
                                <i class="fas fa-filter"></i>
                                <span id="parentBadgeText"></span>
                            </div>
                            <span class="hint">Parent ka account select karein</span>
                        </div>

                        <!-- Driver -->
                        <div class="form-group">
                            <label><i class="fas fa-bus" style="color:#0000FF;font-size:.78rem;"></i> Assigned Driver</label>
                            <select name="driver_id" id="driverSelect" class="form-control">
                                <option value="">— Select Organization First —</option>
                            </select>
                            <div class="filter-badge" id="driverBadge">
                                <i class="fas fa-filter"></i>
                                <span id="driverBadgeText"></span>
                            </div>
                            <span class="hint">Optional</span>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Pickup / Drop Location -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-map-marked-alt"></i>
                    <h3>Pickup &amp; Drop Location</h3>
                </div>
                <div class="form-body">
                    <div class="form-grid">

                        <div class="form-group full-width">
                            <label><i class="fas fa-map-marker-alt" style="color:#0000FF;font-size:.78rem;"></i> Pickup Address</label>
                            <textarea id="pickupAddr" name="pickup_address" class="form-control"
                                      placeholder="Full pickup address"><?php echo htmlspecialchars($child['pickup_address'] ?? ''); ?></textarea>
                            <div class="geocode-status" id="geocodeStatus"></div>
                        </div>

                        <div class="form-group full-width">
                            <label><i class="fas fa-flag-checkered" style="color:#0000FF;font-size:.78rem;"></i> Drop Address</label>
                            <textarea name="drop_address" class="form-control"
                                      placeholder="Full drop address"><?php echo htmlspecialchars($child['drop_address'] ?? ''); ?></textarea>
                        </div>

                        <div class="section-label-row">
                            <span>Coordinates</span>
                            <button type="button" id="geocodeBtn" class="autofill-btn" onclick="fetchCoords()">
                                <i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address
                            </button>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-crosshairs" style="color:#0000FF;font-size:.78rem;"></i> Pickup Latitude</label>
                            <div class="coord-wrapper">
                                <input type="text" id="latField" name="pickup_lat" class="form-control"
                                       placeholder="e.g. 28.61234567"
                                       value="<?php echo htmlspecialchars($child['pickup_lat'] ?? ''); ?>"
                                       oninput="showManual()">
                                <button type="button" class="coord-clear" onclick="clearCoord('latField')" title="Clear"><i class="fas fa-times"></i></button>
                            </div>
                            <span class="hint">Auto-fill</span>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-crosshairs" style="color:#0000FF;font-size:.78rem;"></i> Pickup Longitude</label>
                            <div class="coord-wrapper">
                                <input type="text" id="lngField" name="pickup_lng" class="form-control"
                                       placeholder="e.g. 77.20890123"
                                       value="<?php echo htmlspecialchars($child['pickup_lng'] ?? ''); ?>"
                                       oninput="showManual()">
                                <button type="button" class="coord-clear" onclick="clearCoord('lngField')" title="Clear"><i class="fas fa-times"></i></button>
                            </div>
                            <span class="hint">Auto-fill</span>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Photo -->
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
                            <br><span style="margin-top:3px;display:block;">Naya photo upload karne se purana replace ho jayega</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-upload" style="color:#0000FF;font-size:.78rem;"></i> Upload New Photo</label>
                        <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(this)">
                        <span class="hint">JPG, PNG, GIF, WebP — max 2MB. Optional.</span>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Changes</button>
                <a href="view_students.php?id=<?php echo $child_id; ?>" class="btn-cancel-form"><i class="fas fa-times"></i> Cancel</a>
            </div>

        </form>
    </main>
</div>
</div>

<script>
// ── PHP data → JS ──
const allParents      = <?php echo $parentsJson; ?>;
const allDrivers      = <?php echo $driversJson; ?>;
const currentOrgId    = "<?php echo addslashes($currentOrgId); ?>";
const currentParentId = "<?php echo addslashes($currentParentId); ?>";
const currentDriverId = "<?php echo addslashes($currentDriverId); ?>";

// ── Filter dropdowns based on selected organization ──
function filterByOrganization() {
    const orgId      = document.getElementById('organizationSelect').value;
    const parentSel  = document.getElementById('parentSelect');
    const driverSel  = document.getElementById('driverSelect');
    const parentBadge = document.getElementById('parentBadge');
    const driverBadge = document.getElementById('driverBadge');

    parentSel.innerHTML = '';
    driverSel.innerHTML = '';

    if (!orgId) {
        parentSel.innerHTML = '<option value="">— Select Organization First —</option>';
        driverSel.innerHTML = '<option value="">— Select Organization First —</option>';
        parentBadge.classList.remove('show');
        driverBadge.classList.remove('show');
        return;
    }

    // ── Filter Parents ──
    const filteredParents = allParents.filter(p => String(p.organization_id) === String(orgId));
    if (filteredParents.length === 0) {
        parentSel.innerHTML = '<option value="">— No parents found for this school —</option>';
    } else {
        parentSel.innerHTML = '<option value="">— No Parent —</option>';
        filteredParents.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.user_id;
            opt.textContent = p.firstName + ' ' + p.lastName + ' (@' + p.username + ')';
            if (String(p.user_id) === String(currentParentId)) opt.selected = true;
            parentSel.appendChild(opt);
        });
    }
    document.getElementById('parentBadgeText').textContent = filteredParents.length + ' parent(s) available';
    parentBadge.classList.add('show');

    // ── Filter Drivers ──
    const filteredDrivers = allDrivers.filter(d => String(d.organization_id) === String(orgId));
    if (filteredDrivers.length === 0) {
        driverSel.innerHTML = '<option value="">— No drivers found for this school —</option>';
    } else {
        driverSel.innerHTML = '<option value="">— No Driver —</option>';
        filteredDrivers.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.driverId;
            opt.textContent = d.firstName + ' ' + d.lastName + ' (' + d.driverId + ')';
            if (String(d.driverId) === String(currentDriverId)) opt.selected = true;
            driverSel.appendChild(opt);
        });
    }
    document.getElementById('driverBadgeText').textContent = filteredDrivers.length + ' driver(s) available';
    driverBadge.classList.add('show');
}

// ── On page load: auto-trigger filter with existing org ──
window.addEventListener('DOMContentLoaded', () => {
    if (currentOrgId) {
        filterByOrganization();
    }
});

// ── Sidebar ──
const menuToggle = document.getElementById('menuToggle');
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('sidebarOverlay');
menuToggle.addEventListener('click', () => { sidebar.classList.toggle('active'); overlay.classList.toggle('active'); });
overlay.addEventListener('click',   () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
window.addEventListener('resize',   () => { if(window.innerWidth>1024){sidebar.classList.remove('active');overlay.classList.remove('active');} });
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => { if(window.innerWidth<=1024){sidebar.classList.remove('active');overlay.classList.remove('active');} });
});

function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        let img = document.getElementById('photoPreview');
        const ph = document.getElementById('photoPlaceholder');
        if (!img) {
            img = document.createElement('img');
            img.id = 'photoPreview'; img.className = 'photo-preview';
            if (ph) ph.replaceWith(img);
            else document.querySelector('.photo-preview-wrap').prepend(img);
        }
        img.src = e.target.result;
        if (ph) ph.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

function showStatus(type, msg) {
    const el = document.getElementById('geocodeStatus');
    el.className = 'geocode-status ' + type;
    el.innerHTML = msg;
}
function showManual() {}
function clearCoord(id) {
    document.getElementById(id).value = '';
    if (!document.getElementById('latField').value && !document.getElementById('lngField').value)
        document.getElementById('geocodeStatus').className = 'geocode-status';
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