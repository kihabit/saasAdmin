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
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';
$driver_id = $_SESSION['driver_id'] ?? null;

$errors = [];
$name = $address = $city = $state = $postal_code = $phone = $email = $latitude = $longitude = $org_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $industry_id = trim($_POST['industry_id'] ?? '');
    $org_id      = strtoupper(trim($_POST['org_id'] ?? ''));
    $address     = trim($_POST['address']     ?? '');
    $city        = trim($_POST['city']        ?? '');
    $state       = trim($_POST['state']       ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $email       = trim($_POST['email']       ?? '');
    $latitude    = trim($_POST['latitude']    ?? '');
    $longitude   = trim($_POST['longitude']   ?? '');

    if (empty($name))        $errors[] = 'Organization name is required.';
    if (empty($industry_id)) $errors[] = 'Industry is required.';
    if (empty($org_id))      $errors[] = 'Org ID is required.';
    if (!empty($org_id) && !preg_match('/^[A-Z]{3}[0-9]{3}$/', $org_id))
        $errors[] = 'Org ID format galat hai (e.g. DPS001 — 3 letters + 3 numbers).';
    if (empty($address))     $errors[] = 'Address is required.';
    if (empty($city))        $errors[] = 'City is required.';
    if (empty($state))       $errors[] = 'State is required.';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Please enter a valid email address.';
    if (!empty($latitude) && !is_numeric($latitude))
        $errors[] = 'Latitude must be a valid number (e.g. 28.61234567).';
    if (!empty($longitude) && !is_numeric($longitude))
        $errors[] = 'Longitude must be a valid number (e.g. 77.20890123).';

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("INSERT INTO organization 
(name, industry_id, org_id, address, city, state, postal_code, phone, email, latitude, longitude) 
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt) {
                die("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param(
                'sisssssssss',
                $name,
                $industry_id,
                $org_id,
                $address,
                $city,
                $state,
                $postal_code,
                $phone,
                $email,
                $latitude,
                $longitude
            );

            if (!$stmt->execute()) {
                die("Execute failed: " . $stmt->error);
            }

            echo "Insert successful!";
            $stmt->close();
        } catch (Exception $e) {
            logAppError("addOrganization: " . $e->getMessage());
            $errors[] = 'Error saving organization. Please try again.';
        }
    }
}

if (isset($_GET['logout'])) { session_unset(); session_destroy(); redirect(LOGIN_PAGE); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Organization - <?php echo APP_NAME; ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1a202c;line-height:1.6}
.app-container{display:flex;min-height:100vh}
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
.main-wrapper{flex:1;margin-left:280px;transition:margin-left .3s}
.header{background:white;border-bottom:1px solid #e2e8f0;padding:1rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.header-content{display:flex;justify-content:space-between;align-items:center}
.header-left{display:flex;align-items:center;gap:1rem}
.menu-toggle{background:none;border:none;font-size:1.2rem;color:#4a5568;cursor:pointer;padding:8px;border-radius:8px;display:none}
.breadcrumb{display:flex;align-items:center;gap:8px;color:#718096;font-size:.9rem}
.breadcrumb a{color:#0000FF;text-decoration:none}
.logout-btn{background:#dc3545;color:white;border:none;padding:8px 16px;border-radius:8px;font-weight:500;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:8px;transition:all .3s}
.logout-btn:hover{background:#c82333}
.main-content{padding:2rem}
.page-header{margin-bottom:2rem}
.page-header h1{font-size:2rem;font-weight:700;margin-bottom:.5rem}
.page-header p{color:#718096}
.alert{padding:1rem 1.5rem;border-radius:12px;display:flex;align-items:flex-start;gap:12px;font-weight:500;margin-bottom:1.5rem}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.alert ul{margin:.5rem 0 0 1rem}
.alert ul li{margin-bottom:4px;font-weight:400}
.form-card{background:white;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden}
.form-card-header{padding:1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc}
.form-card-title{font-size:1.25rem;font-weight:600;color:#1a202c;display:flex;align-items:center;gap:8px}
.form-body{padding:2rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem}
.form-group{display:flex;flex-direction:column}
.form-group.full-width{grid-column:1 / -1}
label{font-weight:600;font-size:.9rem;color:#374151;margin-bottom:.5rem;display:flex;align-items:center;gap:6px}
label .req{color:#dc2626}
.form-control{padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:.95rem;font-family:inherit;color:#1a202c;background:white;transition:all .3s;width:100%}
.form-control:focus{outline:none;border-color:#0000FF;box-shadow:0 0 0 3px rgba(0,0,255,.1)}
.form-control::placeholder{color:#9ca3af}
textarea.form-control{resize:vertical;min-height:90px}
.hint{font-size:.8rem;color:#9ca3af;margin-top:4px}
.section-label{font-size:.78rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:1px;grid-column:1 / -1;padding-bottom:.5rem;border-bottom:1px solid #f1f5f9;margin-top:.5rem;display:flex;align-items:center;justify-content:space-between}
.form-actions{display:flex;gap:1rem;margin-top:2rem;padding-top:1.5rem;border-top:1px solid #e2e8f0}
.btn-submit{background:#0000FF;color:white;border:none;padding:13px 28px;border-radius:12px;font-weight:600;cursor:pointer;font-size:1rem;font-family:inherit;display:flex;align-items:center;gap:8px;transition:all .3s}
.btn-submit:hover{background:#0000CC;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,255,.3)}
.btn-back{background:#f3f4f6;color:#4a5568;border:none;padding:13px 24px;border-radius:12px;font-weight:500;font-size:1rem;font-family:inherit;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:8px;transition:all .3s}
.btn-back:hover{background:#e5e7eb}
.sidebar-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;display:none}
.sidebar-overlay.active{display:block}
.geocode-status{padding:8px 12px;border-radius:8px;font-size:.85rem;font-weight:500;margin-top:8px;display:none;align-items:center;gap:8px}
.geocode-status.loading{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:flex}
.geocode-status.success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;display:flex}
.geocode-status.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;display:flex}
.coord-field-wrapper{position:relative}
.coord-field-wrapper .form-control{padding-right:44px}
.coord-clear-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:.85rem;padding:4px 6px;border-radius:4px;transition:color .2s;line-height:1}
.coord-clear-btn:hover{color:#dc2626}
.autofill-btn{background:#0000FF;color:white;border:none;padding:5px 14px;border-radius:8px;font-size:.75rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;white-space:nowrap;transition:all .2s}
.autofill-btn:hover{background:#0000CC}
.autofill-btn:disabled{background:#94a3b8;cursor:not-allowed}
.manual-badge{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:600;color:#059669;background:#d1fae5;border:1px solid #a7f3d0;border-radius:6px;padding:2px 8px;margin-left:8px;vertical-align:middle}

@media(max-width:1024px){
    .sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}
    .main-wrapper{margin-left:0}.menu-toggle{display:block}
    .form-grid{grid-template-columns:1fr}.form-group.full-width{grid-column:1}
    .section-label{flex-direction:column;align-items:flex-start;gap:8px}
}
@media(max-width:768px){
    .header,.main-content{padding:1rem}.form-body{padding:1.25rem}
    .form-actions{flex-direction:column}
}
</style>
</head>
<body>
<div class="app-container">
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
        <a href="organization.php" class="nav-item <?php echo isActivePage('organization.php'); ?>"><i class="fas fa-building"></i><span>Organizations</span></a>
        <a href="profile.php"   class="nav-item <?php echo isActivePage('profile.php'); ?>"><i class="fas fa-user-circle"></i><span>Profile</span></a>
        <a href="children.php"  class="nav-item <?php echo isActivePage('children.php'); ?>"><i class="fas fa-user-circle"></i><span>Children</span></a>
        <a href="alert.php"     class="nav-item <?php echo isActivePage('alert.php'); ?>"><i class="fas fa-bell"></i><span>Alert</span></a>
    </div>
</nav> -->
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="main-wrapper" id="mainWrapper">
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb">
                    <a href="<?php echo BASE_URL;?>dashboard.php">Home</a><i class="fas fa-chevron-right"></i>
                    <a href="<?php echo BASE_URL;?>organization/organization.php">Organizations</a><i class="fas fa-chevron-right"></i>
                    <span>Add Organization</span>
                </div>
            </div>
            <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>
    <main class="main-content">

        <div class="page-header">
            <h1><i class="fas fa-plus-circle" style="color:#0000FF;margin-right:10px;"></i>Add New Organization</h1>
            <p>Fill in the details below to register a new organization in the system</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
            <div><strong>Please fix the following errors:</strong>
                <ul><?php foreach($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title"><i class="fas fa-building" style="color:#0000FF;"></i> Organization Information</h3>
            </div>
            <div class="form-body">
                <form method="POST" action="addOrganization.php">
                    <div class="form-grid">

                        <div class="section-label">Basic Information</div>

                        <!-- Organization Name -->
                        <div class="form-group full-width">
                            <label><i class="fas fa-building" style="color:#0000FF;font-size:.85rem;"></i> Organization Name <span class="req">*</span></label>
                            <input type="text" name="name" id="nameField" class="form-control"
                                   placeholder="e.g. Delhi Public School"
                                   value="<?php echo htmlspecialchars($name); ?>" required>
                        </div>

                        <!-- Address -->
                        <div class="form-group full-width">
                            <label><i class="fas fa-map-marker-alt" style="color:#0000FF;font-size:.85rem;"></i> Address <span class="req">*</span></label>
                            <textarea id="addressField" name="address" class="form-control" placeholder="Enter full street address"><?php echo htmlspecialchars($address); ?></textarea>
                            <div class="geocode-status" id="geocodeStatus"></div>
                        </div>

                        <!-- Industry Type -->
                        <div class="form-group">
                            <label>
                                <i class="fas fa-industry" style="color:#0000FF;font-size:.85rem;"></i>
                                Industry Type <span class="req">*</span>
                            </label>
                            <?php
                            $stmt = $conn->prepare("SELECT id, name FROM industries");
                            $stmt->execute();
                            $result = $stmt->get_result();
                            ?>
                            <select name="industry_id" class="form-control" required>
                                <option value="">Select Industry</option>
                                <?php while($row = $result->fetch_assoc()): ?>
                                    <option value="<?= $row['id']; ?>" <?= (isset($industry_id) && $industry_id == $row['id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($row['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- ===== ORG ID FIELD ===== -->
                        <div class="form-group">
                            <label>
                                <i class="fas fa-id-badge" style="color:#0000FF;font-size:.85rem;"></i>
                                Org ID <span class="req">*</span>
                            </label>
                            <input type="text" name="org_id" id="orgIdField" class="form-control"
                                   placeholder="e.g. DPS001"
                                   value="<?php echo htmlspecialchars($org_id); ?>"
                                   maxlength="6"
                                   style="text-transform:uppercase;letter-spacing:2px;font-weight:600;"
                                   required>
                            <span class="hint">Auto-generated</span>
                        </div>
                        <!-- ===== END ORG ID ===== -->

                        <div class="section-label">Location</div>

                        <div class="form-group">
                            <label><i class="fas fa-city" style="color:#0000FF;font-size:.85rem;"></i> City <span class="req">*</span></label>
                            <input type="text" id="cityField" name="city" class="form-control" placeholder="e.g. New Delhi" value="<?php echo htmlspecialchars($city); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-map" style="color:#0000FF;font-size:.85rem;"></i> State <span class="req">*</span></label>
                            <input type="text" id="stateField" name="state" class="form-control" placeholder="e.g. Delhi" value="<?php echo htmlspecialchars($state); ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-envelope-open-text" style="color:#0000FF;font-size:.85rem;"></i> Postal Code</label>
                            <input type="text" name="postal_code" class="form-control" placeholder="e.g. 110001" value="<?php echo htmlspecialchars($postal_code); ?>">
                            <span class="hint">Optional — 6 digit PIN code</span>
                        </div>

                        <div class="section-label">Contact Information</div>

                        <div class="form-group">
                            <label><i class="fas fa-phone" style="color:#0000FF;font-size:.85rem;"></i> Phone Number</label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g. +91 98765 43210" value="<?php echo htmlspecialchars($phone); ?>">
                            <span class="hint">Optional</span>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-envelope" style="color:#0000FF;font-size:.85rem;"></i> Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. info@organization.com" value="<?php echo htmlspecialchars($email); ?>">
                            <span class="hint">Optional</span>
                        </div>

                        <!-- ===== COORDINATES SECTION ===== -->
                        <div class="section-label">
                            <span>
                                Coordinates
                                <span id="manualBadge" class="manual-badge" style="display:none;">
                                    <i class="fas fa-pencil-alt"></i> Manual
                                </span>
                            </span>
                            <button type="button" id="geocodeBtn" class="autofill-btn" onclick="fetchCoordinates()">
                                <i class="fas fa-location-crosshairs"></i> Auto-fill from Address
                            </button>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-map-pin" style="color:#0000FF;font-size:.85rem;"></i> Latitude</label>
                            <div class="coord-field-wrapper">
                                <input type="text" name="latitude" id="latitudeField" class="form-control"
                                       placeholder="e.g. 28.61234567"
                                       value="<?php echo htmlspecialchars($latitude); ?>"
                                       oninput="onManualCoordInput()">
                                <button type="button" class="coord-clear-btn" onclick="clearCoord('latitude')" title="Clear">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <span class="hint">Auto-fill</span>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-map-pin" style="color:#0000FF;font-size:.85rem;"></i> Longitude</label>
                            <div class="coord-field-wrapper">
                                <input type="text" name="longitude" id="longitudeField" class="form-control"
                                       placeholder="e.g. 77.20890123"
                                       value="<?php echo htmlspecialchars($longitude); ?>"
                                       oninput="onManualCoordInput()">
                                <button type="button" class="coord-clear-btn" onclick="clearCoord('longitude')" title="Clear">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <span class="hint">Auto-fill</span>
                        </div>
                        <!-- ===== END COORDINATES ===== -->

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Organization</button>
                        <a href="organization.php" class="btn-back"><i class="fas fa-arrow-left"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>
</div>

<script>
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
menuToggle.addEventListener('click', () => { sidebar.classList.toggle('active'); sidebarOverlay.classList.toggle('active'); });
sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('active'); sidebarOverlay.classList.remove('active'); });
window.addEventListener('resize', () => { if (window.innerWidth > 1024) { sidebar.classList.remove('active'); sidebarOverlay.classList.remove('active'); } });

// ===== ORG ID AUTO-GENERATE =====
document.getElementById('nameField').addEventListener('blur', function () {
    const orgIdField = document.getElementById('orgIdField');
    
    if (orgIdField.value.trim() !== '') return;

    const name = this.value.trim();
    if (!name) return;

    // 3-letter prefix banana — har word ka pehla letter
    const words = name.split(/\s+/);
    let prefix = words.map(w => w[0] ? w[0].toUpperCase() : '').join('').substring(0, 3);
    prefix = prefix.padEnd(3, 'X'); // agar 3 se kam words hain

    // Random 3-digit number (user baad mein edit kar sakta hai)
    const num = String(Math.floor(Math.random() * 900) + 100);
    orgIdField.value = prefix + num;
});

// OrgId manually type karne par uppercase enforce karo
document.getElementById('orgIdField').addEventListener('input', function () {
    this.value = this.value.toUpperCase();
});
// ===== END ORG ID =====

function showStatus(type, msg) {
    const el = document.getElementById('geocodeStatus');
    el.className = 'geocode-status ' + type;
    el.innerHTML = msg;
}

function onManualCoordInput() {
    document.getElementById('manualBadge').style.display = 'inline-flex';
}

function clearCoord(field) {
    document.getElementById(field + 'Field').value = '';
    const lat = document.getElementById('latitudeField').value.trim();
    const lon = document.getElementById('longitudeField').value.trim();
    if (!lat && !lon) {
        document.getElementById('manualBadge').style.display = 'none';
        document.getElementById('geocodeStatus').className = 'geocode-status';
    }
}

function fetchCoordinates() {
    const address = document.getElementById('addressField').value.trim();
    const city    = document.getElementById('cityField').value.trim();
    const state   = document.getElementById('stateField').value.trim();

    if (!address && !city) {
        showStatus('error', '<i class="fas fa-exclamation-circle"></i>&nbsp; Pehle address ya city darj karein.');
        return;
    }

    const btn = document.getElementById('geocodeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching...';

    const fullAddress = [address, city, state, 'India'].filter(Boolean).join(', ');
    showStatus('loading', '<i class="fas fa-spinner fa-spin"></i>&nbsp; Coordinates dhundh rahe hain...');

    fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(fullAddress) + '&limit=1', {
        headers: { 'Accept-Language': 'en' }
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Auto-fill from Address';

        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat).toFixed(8);
            const lon = parseFloat(data[0].lon).toFixed(8);
            document.getElementById('latitudeField').value  = lat;
            document.getElementById('longitudeField').value = lon;
            document.getElementById('manualBadge').style.display = 'none';
            showStatus('success', '<i class="fas fa-check-circle"></i>&nbsp; Coordinates found: ' + lat + ', ' + lon);
        } else {
            document.getElementById('latitudeField').value  = '';
            document.getElementById('longitudeField').value = '';
            showStatus('error', '<i class="fas fa-exclamation-circle"></i>&nbsp; Location not found.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Auto-fill from Address';
        showStatus('error', '<i class="fas fa-exclamation-circle"></i>&nbsp; Network error. Manually coordinates darj karein.');
    });
}
</script>
</body>
</html>