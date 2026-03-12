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

$user_id  = $_SESSION['user_id']  ?? 0;
$username = $_SESSION['username'] ?? '';

// ── Fetch organizations for dropdown ──
$organizations = [];
try {
    $res = $conn->query("SELECT id, org_id, name FROM organization ORDER BY name ASC");
    if ($res) while ($r = $res->fetch_assoc()) $organizations[] = $r;
} catch (Exception $e) { logAppError("Fetch orgs: " . $e->getMessage()); }

// ── Fetch parents for dropdown ──
$parents = [];
try {
    $res = $conn->query("SELECT edu_user_id, firstName, lastName, username FROM edu_user WHERE userType = 6 ORDER BY firstName ASC");
    if ($res) while ($r = $res->fetch_assoc()) $parents[] = $r;
} catch (Exception $e) { logAppError("Fetch parents: " . $e->getMessage()); }

// ── Fetch drivers for dropdown ──
$drivers = [];
try {
    $res = $conn->query("SELECT driverId, firstName, lastName FROM edu_user WHERE userType = 4 ORDER BY firstName ASC");
    if ($res) while ($r = $res->fetch_assoc()) $drivers[] = $r;
} catch (Exception $e) { logAppError("Fetch drivers: " . $e->getMessage()); }

$errors = [];
$name = $class = $section = $roll_number = $gender = $dob = $status = '';
$pickup_address = $drop_address = $pickup_lat = $pickup_lng = '';
$organization_id = $parent_id = $driver_id_val = '';

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name']           ?? '');
    $organization_id      = trim($_POST['organization_id']      ?? '');
    $parent_id      = trim($_POST['parent_id']      ?? '');
    $driver_id_val  = trim($_POST['driver_id']      ?? '');
    $class          = trim($_POST['class']          ?? '');
    $section        = trim($_POST['section']        ?? '');
    $roll_number    = trim($_POST['roll_number']    ?? '');
    $gender         = trim($_POST['gender']         ?? '');
    $dob            = trim($_POST['dob']            ?? '');
    $pickup_address = trim($_POST['pickup_address'] ?? '');
    $drop_address   = trim($_POST['drop_address']   ?? '');
    $pickup_lat     = trim($_POST['pickup_lat']     ?? '');
    $pickup_lng     = trim($_POST['pickup_lng']     ?? '');
    $status         = trim($_POST['status']         ?? 'active');

    if (empty($name))           $errors[] = 'Student name is required.';
    if (empty($class))          $errors[] = 'Class is required.';
    if (empty($pickup_address)) $errors[] = 'Pickup address is required.';
    if (empty($drop_address))   $errors[] = 'Drop address is required.';
    if (!empty($dob) && !strtotime($dob)) $errors[] = 'Invalid date of birth.';
    if (!empty($pickup_lat) && !is_numeric($pickup_lat)) $errors[] = 'Latitude must be a valid number.';
    if (!empty($pickup_lng) && !is_numeric($pickup_lng)) $errors[] = 'Longitude must be a valid number.';

    if (empty($errors)) {
        try {
            $sid  = !empty($organization_id)     ? intval($organization_id)    : null;
            $pid  = !empty($parent_id)     ? intval($parent_id)    : null;
            $did  = !empty($driver_id_val) ? $driver_id_val        : null;
            $dobv = !empty($dob)           ? $dob                  : null;
            $lat  = !empty($pickup_lat)    ? floatval($pickup_lat) : null;
            $lng  = !empty($pickup_lng)    ? floatval($pickup_lng) : null;
            $sec  = !empty($section)       ? $section              : null;
            $rno  = !empty($roll_number)   ? $roll_number          : null;
            $gen  = !empty($gender)        ? $gender               : null;

            $stmt = $conn->prepare("INSERT INTO students
                (organization_id, parent_id, driver_id, name, class, section, roll_number, gender, dob, pickup_address, drop_address, pickup_lat, pickup_lng, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
         $stmt->bind_param("iisssssssssdds",
                $sid, $pid, $did,
                $name, $class, $sec, $rno, $gen, $dobv,
                $pickup_address, $drop_address,
                $lat, $lng,
                $status
            );
            if ($stmt->execute()) {
                logAppError("New student added: $name by $username");
                $_SESSION['message']     = "Student '$name' added successfully!";
                $_SESSION['messageType'] = 'success';
                header('Location: students.php'); exit();
            } else { $errors[] = 'Database error. Please try again.'; }
            $stmt->close();
        } catch (Exception $e) {
            logAppError("addStudent: " . $e->getMessage());
            $errors[] = 'Error saving student: ' . $e->getMessage();
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
    <title>Add Student - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #f8fafc; color: #1a202c; line-height: 1.6; }
        .app-container { display: flex; min-height: 100vh; }
        /* ── Sidebar ── */
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
        /* ── Main ── */
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; font-size: 13px; }
        .header { background: white; border-bottom: 1px solid #e2e8f0; padding: 0.75rem 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .menu-toggle { background: none; border: none; font-size: 1.2rem; color: #4a5568; cursor: pointer; padding: 8px; border-radius: 8px; transition: all 0.3s ease; display: none; }
        .menu-toggle:hover { background: #f7fafc; color: #0000FF; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 0.9rem; }
        .breadcrumb a { color: #0000FF; text-decoration: none; }
        .header-actions { display: flex; gap: 0.75rem; align-items: center; }
        .btn-back { background: #f3f4f6; color: #4a5568; border: 1px solid #e2e8f0; padding: 7px 14px; border-radius: 8px; font-weight: 500; font-size: 0.85rem; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-back:hover { background: #e5e7eb; }
        .logout-btn { background: #dc3545; color: white; border: none; padding: 7px 14px; border-radius: 8px; font-weight: 500; font-size: 0.85rem; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: all 0.3s; }
        .logout-btn:hover { background: #c82333; transform: translateY(-1px); }
        /* ── Content ── */
        .main-content { padding: 1.5rem 2rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem; }
        .page-title h1 { font-size: 1.35rem; font-weight: 700; color: #1a202c; margin-bottom: 2px; }
        .page-title p { color: #718096; font-size: 0.82rem; }
        /* ── Alert ── */
        .alert { padding: 0.85rem 1.25rem; border-radius: 10px; display: flex; align-items: flex-start; gap: 10px; margin-bottom: 1.25rem; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert ul { margin: 0.4rem 0 0 1rem; }
        .alert ul li { margin-bottom: 3px; font-size: 0.85rem; }
        /* ── Form Card ── */
        .form-card { background: white; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .card-title { font-size: 1rem; font-weight: 600; color: #1a202c; display: flex; align-items: center; gap: 8px; }
        .card-body { padding: 1.5rem; }
        /* ── Section Label ── */
        .section-block { margin-bottom: 1.75rem; }
        .section-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #718096; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
        .section-label-left { display: flex; align-items: center; gap: 8px; }
        .section-label i { color: #0000FF; font-size: 0.85rem; }
        /* ── Form Grid ── */
        .fg3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
        .fg2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 1rem; }
        .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 0.78rem; font-weight: 600; color: #4a5568; text-transform: uppercase; letter-spacing: 0.04em; }
        .form-label span { color: #dc2626; }
        .iw { position: relative; display: flex; align-items: center; }
        .ii { position: absolute; left: 12px; color: #9ca3af; font-size: 13px; pointer-events: none; z-index: 1; }
        .fc { width: 100%; padding: 9px 12px 9px 36px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 0.88rem; font-family: 'Inter', sans-serif; color: #1a202c; transition: all 0.25s; outline: none; }
        .fc:focus { border-color: #0000FF; background: white; box-shadow: 0 0 0 3px rgba(0,0,255,0.08); }
        .fc::placeholder { color: #9ca3af; }
        select.fc { appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='7' viewBox='0 0 10 7'%3E%3Cpath fill='%2394a3b8' d='M5 7L0 0h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }
        textarea.fc { min-height: 80px; resize: vertical; padding-top: 10px; }
        .hint { font-size: 0.75rem; color: #9ca3af; margin-top: 3px; }
        /* ── Geocode ── */
        .geocode-status { padding: 7px 11px; border-radius: 7px; font-size: 0.82rem; font-weight: 500; margin-top: 7px; display: none; align-items: center; gap: 7px; }
        .geocode-status.loading { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; display: flex; }
        .geocode-status.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; display: flex; }
        .geocode-status.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; display: flex; }
        .autofill-btn { background: #0000FF; color: white; border: none; padding: 5px 13px; border-radius: 7px; font-size: 0.75rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; white-space: nowrap; }
        .autofill-btn:hover { background: #0000CC; }
        .autofill-btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .coord-clear-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #9ca3af; cursor: pointer; font-size: 0.82rem; padding: 4px; }
        .coord-clear-btn:hover { color: #dc2626; }
        /* ── Form Actions ── */
        .form-actions { display: flex; gap: 0.75rem; justify-content: flex-end; padding-top: 1.25rem; border-top: 1px solid #e2e8f0; }
        .btn-cancel { background: #f3f4f6; color: #4a5568; border: none; padding: 9px 18px; border-radius: 8px; font-weight: 500; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 0.88rem; text-decoration: none; transition: all 0.2s; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-submit { background: #0000FF; color: white; border: none; padding: 9px 22px; border-radius: 8px; font-weight: 600; font-family: 'Inter', sans-serif; font-size: 0.88rem; cursor: pointer; transition: all 0.25s; display: flex; align-items: center; gap: 7px; }
        .btn-submit:hover { background: #0000CC; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,255,0.3); }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; display: none; }
        .sidebar-overlay.active { display: block; }
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); }
            .main-wrapper { margin-left: 0; } .menu-toggle { display: block; }
            .fg3 { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 768px) {
            .header, .main-content { padding: 0.75rem 1rem; } .card-body { padding: 1rem; }
            .fg3, .fg2 { grid-template-columns: 1fr; } .form-actions { flex-direction: column-reverse; }
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
                        <a href="<?php echo BASE_URL; ?>dashboard.php">Home</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="students.php">Students</a>
                        <i class="fas fa-chevron-right"></i>
                        <span>Add Student</span>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="students.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>

        <main class="main-content">

            <div class="page-header">
                <div class="page-title">
                    <h1>Add New Student</h1>
                    <p>Fill in the details below to register a new student in the system</p>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle" style="font-size:1.1rem;margin-top:2px;flex-shrink:0;"></i>
                <div><strong>Please fix the following errors:</strong>
                    <ul><?php foreach($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-graduate" style="color:#0000FF;"></i> Student Registration Form</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="add_students.php">

                        <!-- ── Basic Info ── -->
                        <div class="section-block">
                            <div class="section-label">
                                <span class="section-label-left"><i class="fas fa-user"></i> Basic Information</span>
                            </div>
                            <div class="fg3">
                                <div class="form-group full">
                                    <label class="form-label">Student Full Name <span>*</span></label>
                                    <div class="iw"><i class="fas fa-user ii"></i>
                                        <input type="text" name="name" class="fc" placeholder="e.g. Rahul Sharma" value="<?php echo htmlspecialchars($name); ?>" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Class <span>*</span></label>
                                    <div class="iw"><i class="fas fa-chalkboard ii"></i>
                                        <input type="text" name="class" class="fc" placeholder="e.g. 5, 10, KG" value="<?php echo htmlspecialchars($class); ?>" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Section</label>
                                    <div class="iw"><i class="fas fa-layer-group ii"></i>
                                        <input type="text" name="section" class="fc" placeholder="e.g. A, B, C" value="<?php echo htmlspecialchars($section); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Roll Number</label>
                                    <div class="iw"><i class="fas fa-hashtag ii"></i>
                                        <input type="text" name="roll_number" class="fc" placeholder="e.g. 25" value="<?php echo htmlspecialchars($roll_number); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Gender</label>
                                    <div class="iw"><i class="fas fa-venus-mars ii"></i>
                                        <select name="gender" class="fc">
                                            <option value="">-- Select Gender --</option>
                                            <option value="male"   <?php echo $gender==='male'   ? 'selected':''; ?>>Male</option>
                                            <option value="female" <?php echo $gender==='female' ? 'selected':''; ?>>Female</option>
                                            <option value="other"  <?php echo $gender==='other'  ? 'selected':''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Date of Birth</label>
                                    <div class="iw"><i class="fas fa-calendar ii"></i>
                                        <input type="date" name="dob" class="fc" value="<?php echo htmlspecialchars($dob); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <div class="iw"><i class="fas fa-toggle-on ii"></i>
                                        <select name="status" class="fc">
                                            <option value="active"   <?php echo $status!=='inactive' ? 'selected':''; ?>>Active</option>
                                            <option value="inactive" <?php echo $status==='inactive' ? 'selected':''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── School / Parent / Driver ── -->
                        <div class="section-block">
                            <div class="section-label">
                                <span class="section-label-left"><i class="fas fa-school"></i> School, Parent & Driver</span>
                            </div>
                            <div class="fg3">
                                <div class="form-group">
                                    <label class="form-label">School / Organization</label>
                                    <div class="iw"><i class="fas fa-building ii"></i>
                                        <select name="organization_id" class="fc">
                                            <option value="">-- Select School --</option>
                                            <?php foreach ($organizations as $org): ?>
                                            <option value="<?php echo $org['id']; ?>" <?php echo $organization_id == $org['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($org['name']); ?> (<?php echo htmlspecialchars($org['org_id']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <span class="hint">Optional</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Parent / Guardian</label>
                                    <div class="iw"><i class="fas fa-user-friends ii"></i>
                                        <select name="parent_id" class="fc">
                                            <option value="">-- Select Parent --</option>
                                            <?php foreach ($parents as $p): ?>
                                            <option value="<?php echo $p['edu_user_id']; ?>" <?php echo $parent_id == $p['edu_user_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['firstName'] . ' ' . $p['lastName']); ?> (<?php echo htmlspecialchars($p['username']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <span class="hint">Optional</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Assigned Driver</label>
                                    <div class="iw"><i class="fas fa-bus ii"></i>
                                        <select name="driver_id" class="fc">
                                            <option value="">-- Select Driver --</option>
                                            <?php foreach ($drivers as $d): ?>
                                            <option value="<?php echo htmlspecialchars($d['driverId']); ?>" <?php echo $driver_id_val == $d['driverId'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($d['firstName'] . ' ' . $d['lastName']); ?> (<?php echo htmlspecialchars($d['driverId']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <span class="hint">Optional</span>
                                </div>
                            </div>
                        </div>

                        <!-- ── Pickup & Drop ── -->
                        <div class="section-block">
                            <div class="section-label">
                                <span class="section-label-left"><i class="fas fa-map-marker-alt"></i> Pickup & Drop Address</span>
                            </div>
                            <div class="fg2">
                                <div class="form-group">
                                    <label class="form-label">Pickup Address <span>*</span></label>
                                    <div class="iw"><i class="fas fa-map-pin ii"></i>
                                        <textarea id="pickupAddr" name="pickup_address" class="fc" placeholder="Enter full pickup address"><?php echo htmlspecialchars($pickup_address); ?></textarea>
                                    </div>
                                    <div class="geocode-status" id="geocodeStatus"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Drop Address <span>*</span></label>
                                    <div class="iw"><i class="fas fa-map-marker ii"></i>
                                        <textarea name="drop_address" class="fc" placeholder="Enter full drop address"><?php echo htmlspecialchars($drop_address); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Coordinates ── -->
                        <div class="section-block">
                            <div class="section-label">
                                <span class="section-label-left"><i class="fas fa-crosshairs"></i> Pickup Coordinates</span>
                                <button type="button" class="autofill-btn" id="geocodeBtn" onclick="fetchCoordinates()">
                                    <i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address
                                </button>
                            </div>
                            <div class="fg2">
                                <div class="form-group">
                                    <label class="form-label">Latitude</label>
                                    <div class="iw"><i class="fas fa-map-pin ii"></i>
                                        <input type="text" name="pickup_lat" id="latField" class="fc" placeholder="e.g. 28.61234567" value="<?php echo htmlspecialchars($pickup_lat); ?>">
                                        <button type="button" class="coord-clear-btn" onclick="clearCoord('lat')"><i class="fas fa-times"></i></button>
                                    </div>
                                    <span class="hint">Auto-fill or enter manually</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Longitude</label>
                                    <div class="iw"><i class="fas fa-map-pin ii"></i>
                                        <input type="text" name="pickup_lng" id="lngField" class="fc" placeholder="e.g. 77.20890123" value="<?php echo htmlspecialchars($pickup_lng); ?>">
                                        <button type="button" class="coord-clear-btn" onclick="clearCoord('lng')"><i class="fas fa-times"></i></button>
                                    </div>
                                    <span class="hint">Auto-fill or enter manually</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="students.php" class="btn-cancel">Cancel</a>
                            <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Student</button>
                        </div>

                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
// Sidebar
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => { sidebar.classList.toggle('active'); overlay.classList.toggle('active'); });
overlay.addEventListener('click', () => { sidebar.classList.remove('active'); overlay.classList.remove('active'); });
window.addEventListener('resize', () => { if(window.innerWidth > 1024){ sidebar.classList.remove('active'); overlay.classList.remove('active'); }});

// Geocode
function showStatus(type, msg) {
    const el = document.getElementById('geocodeStatus');
    el.className = 'geocode-status ' + type;
    el.innerHTML = msg;
}
function clearCoord(field) {
    document.getElementById(field + 'Field').value = '';
    document.getElementById('geocodeStatus').className = 'geocode-status';
}
function fetchCoordinates() {
    const address = document.getElementById('pickupAddr').value.trim();
    if (!address) { showStatus('error', '<i class="fas fa-exclamation-circle"></i>&nbsp; Pehle pickup address darj karein.'); return; }
    const btn = document.getElementById('geocodeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching...';
    showStatus('loading', '<i class="fas fa-spinner fa-spin"></i>&nbsp; Coordinates dhundh rahe hain...');
    fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(address + ', India') + '&limit=1', {
        headers: { 'Accept-Language': 'en' }
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address';
        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat).toFixed(8);
            const lng = parseFloat(data[0].lon).toFixed(8);
            document.getElementById('latField').value = lat;
            document.getElementById('lngField').value = lng;
            showStatus('success', '<i class="fas fa-check-circle"></i>&nbsp; Coordinates found: ' + lat + ', ' + lng);
        } else {
            showStatus('error', '<i class="fas fa-exclamation-circle"></i>&nbsp; Location not found. Manually enter karein.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Auto-fill from Pickup Address';
        showStatus('error', '<i class="fas fa-exclamation-circle"></i>&nbsp; Network error. Manually coordinates darj karein.');
    });
}
</script>
</body>
</html>
