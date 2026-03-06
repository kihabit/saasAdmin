<?php
header('Content-Type: application/json');

require_once 'config.php';

try {

    $db   = Database::getInstance();
    $conn = $db->getConnection();

    // Check request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success'=>false,'message'=>'Invalid request']);
        exit;
    }

    // Get POST data directly
    $firstName    = trim($_POST['firstName'] ?? '');
    $lastName     = trim($_POST['lastName'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = trim($_POST['password'] ?? '');
    $role         = trim($_POST['role'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $street       = trim($_POST['street'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $state        = trim($_POST['state'] ?? '');
    $country      = trim($_POST['country'] ?? '');
    $zipcode      = trim($_POST['zipcode'] ?? '');
    $school_id    = !empty($_POST['school_id']) ? intval($_POST['school_id']) : null;
    $school_name  = $_POST['school_name'] ?? null;
    $latitude     = $_POST['latitude'] ?? null;
    $longitude    = $_POST['longitude'] ?? null;

    // Validation
    if (
        empty($firstName) || empty($lastName) || empty($email) ||
        empty($username) || empty($password) || empty($role) ||
        empty($address) || empty($phone_number) || empty($street) ||
        empty($city) || empty($state) || empty($country) || empty($zipcode)
    ) {
        echo json_encode(['success'=>false,'message'=>'All fields are required']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success'=>false,'message'=>'Invalid email']);
        exit;
    }

    // Role map
    $roleMap = [
        'school_admin'=>2,
        'school_staff'=>3,
        'driver'=>4,
        'teacher'=>5,
        'parent'=>6
    ];

    if (!array_key_exists($role, $roleMap)) {
        echo json_encode(['success'=>false,'message'=>'Invalid role']);
        exit;
    }

    $userType = $roleMap[$role];

    // Secure password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Generate ID & token
    $driverId = 'DRV' . strtoupper(substr(md5(uniqid()), 0, 6));
    $token    = bin2hex(random_bytes(32));

    date_default_timezone_set('Asia/Kolkata');
    $created_at = date('Y-m-d H:i:s');

    // Duplicate check (single query)
    $stmt = $conn->prepare("
        SELECT user_id, email, username, phone_number
        FROM user_login
        WHERE email = ? OR username = ? OR phone_number = ?
    ");
    $stmt->bind_param("sss", $email, $username, $phone_number);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($row['email'] === $email) {
            echo json_encode(['success'=>false,'message'=>'Email already exists']);
        } elseif ($row['username'] === $username) {
            echo json_encode(['success'=>false,'message'=>'Username already exists']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Phone already exists']);
        }
        exit;
    }
    $stmt->close();

    // Transaction
    $conn->begin_transaction();

    try {

        $sql = "INSERT INTO user_login
        (driverId, username, firstName, lastName, address, phone_number,
         street, city, state, country, zipcode, userType, password_hash,
         email, token, status, created_at, latitude, longitude, school_id, school_name)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "sssssssssssisssssiss",
            $driverId,
            $username,
            $firstName,
            $lastName,
            $address,
            $phone_number,
            $street,
            $city,
            $state,
            $country,
            $zipcode,
            $userType,
            $passwordHash,
            $email,
            $token,
            $created_at,
            $latitude,
            $longitude,
            $school_id,
            $school_name
        );

        $stmt->execute();
        $userId = $conn->insert_id;

        $conn->commit();

        echo json_encode([
            'success'=>true,
            'message'=>'User added successfully',
            'user_id'=>$userId
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'Insert failed']);
    }

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - Organization Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --blue: #0000FF;
            --blue-mid: #4169E1;
            --green: #10b981;
            --red: #ef4444;
            --amber: #f59e0b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-400: #94a3b8;
            --gray-600: #475569;
            --gray-800: #1e293b;
        }

        body { font-family: 'DM Sans', sans-serif; background: var(--gray-50); color: var(--gray-800); line-height: 1.6; }
        .app-container { display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        .sidebar { width: 280px; background: white; border-right: 1px solid var(--gray-200); position: fixed; height: 100vh; left: 0; top: 0; z-index: 1000; overflow-y: auto; transition: transform 0.3s ease; }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid var(--gray-200); background: linear-gradient(135deg, var(--blue), var(--blue-mid)); color: white; }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
        .sidebar-logo h2 { font-family: 'Sora', sans-serif; font-size: 1.2rem; font-weight: 700; }
        .sidebar-user { margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 12px; }
        .user-avatar { width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 15px; margin-bottom: 0.5rem; }
        .sidebar-user h3 { font-size: 0.95rem; font-weight: 600; margin-bottom: 2px; }
        .sidebar-user p { font-size: 0.8rem; opacity: 0.75; }
        .sidebar-nav { padding: 1rem 0; }
        .nav-item { display: flex; padding: 0.75rem 1.5rem; color: var(--gray-600); text-decoration: none; transition: all 0.25s ease; border-left: 3px solid transparent; align-items: center; gap: 12px; font-size: 0.92rem; font-weight: 500; }
        .nav-item:hover, .nav-item.active { background: #f0f4ff; color: var(--blue); border-left-color: var(--blue); }
        .nav-item i { width: 20px; text-align: center; }

        /* ── Header ── */
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }
        .header { background: white; border-bottom: 1px solid var(--gray-200); padding: 1rem 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .menu-toggle { background: none; border: none; font-size: 1.1rem; color: var(--gray-600); cursor: pointer; padding: 8px; border-radius: 8px; display: none; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; color: var(--gray-400); font-size: 0.88rem; }
        .breadcrumb a { color: var(--blue); text-decoration: none; }
        .header-actions { display: flex; align-items: center; gap: 1rem; }
        .btn-back { background: var(--gray-100); color: var(--gray-600); border: 1px solid var(--gray-200); padding: 8px 14px; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 7px; font-size: 0.88rem; font-weight: 500; transition: all 0.2s; }
        .btn-back:hover { background: var(--gray-200); }
        .logout-btn { background: #dc3545; color: white; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 500; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 7px; font-size: 0.88rem; transition: all 0.2s; }
        .logout-btn:hover { background: #c82333; }

        /* ── Main Content ── */
        .main-content { padding: 2rem; max-width: 900px; }
        .page-title h1 { font-family: 'Sora', sans-serif; font-size: 1.7rem; font-weight: 700; color: var(--gray-800); margin-bottom: 4px; }
        .page-title p { color: var(--gray-400); font-size: 0.92rem; margin-bottom: 2rem; }

        /* ── Form Card ── */
        .form-card { background: white; border-radius: 20px; padding: 2.5rem; border: 1px solid var(--gray-200); box-shadow: 0 2px 12px rgba(0,0,0,0.05); }

        /* Location Banner */
        .location-banner { display: none; align-items: center; gap: 12px; background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #bfdbfe; border-radius: 12px; padding: 13px 16px; margin-bottom: 24px; font-size: 0.87rem; color: #1d4ed8; font-weight: 500; animation: slideDown 0.35s ease; }
        .location-banner.show { display: flex; }
        .location-banner.success { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-color: #bbf7d0; color: #15803d; }
        .location-banner.error { background: linear-gradient(135deg, #fff7ed, #ffedd5); border-color: #fed7aa; color: #c2410c; }
        .loc-spin { width: 16px; height: 16px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.7s linear infinite; flex-shrink: 0; }
        .loc-icon { flex-shrink: 0; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Section */
        .section-label { font-family: 'Sora', sans-serif; font-size: 0.73rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gray-400); margin: 4px 0 18px; display: flex; align-items: center; gap: 10px; }
        .section-label::before, .section-label::after { content: ''; flex: 1; height: 1px; background: var(--gray-200); }

        /* Form rows */
        .form-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .form-row.two-col { grid-template-columns: repeat(2, 1fr); }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; color: var(--gray-600); font-weight: 600; margin-bottom: 6px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 13px; color: var(--gray-400); font-size: 14px; pointer-events: none; z-index: 1; transition: color 0.25s; }
        .form-input, .form-select { width: 100%; padding: 11px 13px 11px 40px; border: 1.5px solid var(--gray-200); border-radius: 11px; font-size: 0.9rem; background: var(--gray-50); transition: all 0.25s; outline: none; font-weight: 500; color: var(--gray-800); font-family: 'DM Sans', sans-serif; }
        .form-select { appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='7' viewBox='0 0 10 7'%3E%3Cpath fill='%2394a3b8' d='M5 7L0 0h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px; }
        .form-input:focus, .form-select:focus { border-color: var(--blue); background: white; box-shadow: 0 0 0 3px rgba(0,0,255,0.09); }
        .form-input.invalid, .form-select.invalid { border-color: var(--red); background: #fff5f5; }
        .form-input.valid, .form-select.valid { border-color: var(--green); }
        .form-input::placeholder { color: var(--gray-400); font-weight: 400; }
        .field-error { margin-top: 4px; font-size: 0.76rem; color: var(--red); display: none; font-weight: 500; }

        /* ── Organization Search Autocomplete ── */
        .organization-search-wrapper { position: relative; }

        .organization-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0; right: 0;
            background: white;
            border: 1.5px solid var(--blue);
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,255,0.12);
            z-index: 500;
            max-height: 280px;
            overflow-y: auto;
            animation: slideDown 0.2s ease;
        }

        .organization-dropdown.open { display: block; }

        .organization-dropdown-header {
            padding: 8px 14px;
            font-size: 0.72rem;
            color: var(--gray-400);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border-bottom: 1px solid var(--gray-100);
            background: var(--gray-50);
            border-radius: 12px 12px 0 0;
        }

        .organization-option {
            padding: 10px 14px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .organization-option:last-child { border-bottom: none; border-radius: 0 0 12px 12px; }
        .organization-option:hover, .organization-option.highlighted { background: #eff6ff; }

        .organization-option-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        .organization-option-meta {
            font-size: 0.76rem;
            color: var(--gray-400);
            display: flex;
            gap: 8px;
        }

        .organization-option-id {
            background: #dbeafe;
            color: #1d4ed8;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .organization-loading {
            padding: 16px 14px;
            text-align: center;
            color: var(--gray-400);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .organization-no-result {
            padding: 16px 14px;
            text-align: center;
            color: var(--gray-400);
            font-size: 0.85rem;
        }

        .organization-selected-badge {
            display: none;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            padding: 6px 10px;
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            font-size: 0.78rem;
            color: #15803d;
            font-weight: 500;
        }

        .organization-selected-badge.show { display: flex; }

        .badge-clear {
            margin-left: auto;
            cursor: pointer;
            color: #15803d;
            font-size: 12px;
            opacity: 0.7;
            transition: opacity 0.2s;
            background: none;
            border: none;
        }

        .badge-clear:hover { opacity: 1; }

        /* highlight match text */
        .match-highlight { color: var(--blue); font-weight: 700; }

        /* Password */
        .password-toggle { position: absolute; right: 12px; background: none; border: none; color: var(--gray-400); cursor: pointer; font-size: 14px; transition: color 0.2s; z-index: 1; }
        .password-toggle:hover { color: var(--blue); }
        .password-strength { margin-top: 6px; display: flex; gap: 3px; }
        .strength-bar { height: 3px; flex: 1; background: var(--gray-200); border-radius: 2px; transition: background 0.3s; }
        .strength-bar.weak { background: var(--red); }
        .strength-bar.medium { background: var(--amber); }
        .strength-bar.strong { background: var(--green); }
        .password-requirements { margin-top: 9px; font-size: 0.76rem; color: var(--gray-400); }
        .requirement { display: flex; align-items: center; gap: 6px; margin-bottom: 3px; transition: color 0.25s; }
        .requirement.met { color: var(--green); }
        .req-icon { width: 13px; text-align: center; font-size: 11px; }
        .password-match { margin-top: 6px; font-size: 0.78rem; font-weight: 500; }

        /* Checkbox */
        .checkbox-group { display: flex; align-items: flex-start; margin: 6px 0 24px; gap: 10px; }
        .custom-checkbox { width: 19px; height: 19px; border: 1.5px solid var(--gray-200); border-radius: 5px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; background: white; transition: all 0.25s; }
        .custom-checkbox.checked { background: var(--blue); border-color: var(--blue); }
        .custom-checkbox.checked::after { content: '✓'; color: white; font-size: 10px; font-weight: 700; }
        .checkbox-label { color: var(--gray-600); font-size: 0.84rem; cursor: pointer; user-select: none; font-weight: 500; line-height: 1.5; }
        .checkbox-label a { color: var(--blue); text-decoration: none; font-weight: 600; }

        /* Actions */
        .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--gray-200); }
        .btn-cancel { padding: 11px 22px; background: var(--gray-100); color: var(--gray-600); border: none; border-radius: 10px; font-size: 0.92rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; }
        .btn-cancel:hover { background: var(--gray-200); }
        .signup-button { padding: 11px 28px; background: linear-gradient(135deg, var(--blue), var(--blue-mid)); color: white; border: none; border-radius: 10px; font-size: 0.92rem; font-weight: 600; font-family: 'Sora', sans-serif; cursor: pointer; transition: all 0.25s; position: relative; }
        .signup-button:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,255,0.3); }
        .signup-button:disabled { background: var(--gray-400); cursor: not-allowed; transform: none; box-shadow: none; }
        .signup-button.loading::after { content: ''; position: absolute; right: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; border: 2px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; animation: spin 0.7s linear infinite; }

        .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 999; display: none; }
        .sidebar-overlay.active { display: block; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .menu-toggle { display: block; }
            .form-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .header { padding: 1rem; }
            .main-content { padding: 1rem; }
            .form-card { padding: 1.5rem; }
            .form-row, .form-row.two-col { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="app-container">

    <!-- Sidebar -->
    <!-- <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="/schoolAdmin/driver.tracker/icon/schooladmin.jpg" alt="Logo" onerror="this.style.display='none'">
                <h2>Organization Admin</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">AD</div>
                <h3>Admin User</h3>
                <p>Administrator</p>
            </div>
        </div>
        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
            <a href="users.php" class="nav-item active"><i class="fas fa-users"></i><span>Users</span></a>
            <a href="profile.php" class="nav-item"><i class="fas fa-user-circle"></i><span>Profile</span></a>
            <a href="organization.php" class="nav-item"><i class="fas fa-organization"></i><span>Organization</span></a>
            <a href="children.php" class="nav-item"><i class="fas fa-organization"></i><span>Children</span></a>
             <a href="alert.php" class="nav-item"><i class="fas fa-organization"></i><span>alert</span></a>
        </div>
    </nav> -->
<?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-wrapper" id="mainWrapper">
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Home</a>
                        <i class="fas fa-chevron-right" style="font-size:10px"></i>
                        <a href="users.php">Users</a>
                        <i class="fas fa-chevron-right" style="font-size:10px"></i>
                        <span>Add User</span>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="users.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Logout?')">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>

        <main class="main-content">
            <div class="page-title">
                <h1>Add New User</h1>
                <p>Create a new account for organization staff, driver, teacher or parent</p>
            </div>

            <div class="form-card">

                <!-- Location Status Banner -->
                <div class="location-banner" id="locationBanner">
                    <div class="loc-spin" id="locationSpinner"></div>
                    <span class="loc-icon" id="locationIcon" style="display:none"></span>
                    <span id="locationText">Fetching your location...</span>
                </div>

                <form id="signupForm">

                    <div class="section-label">Personal Info</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <div class="input-wrapper">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" id="firstName" class="form-input" placeholder="First name" required>
                            </div>
                            <div class="field-error" id="firstNameError">Min 2 chars, letters only</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <div class="input-wrapper">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" id="lastName" class="form-input" placeholder="Last name" required>
                            </div>
                            <div class="field-error" id="lastNameError">Min 2 chars, letters only</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">User Type</label>
                            <div class="input-wrapper">
                                <i class="fas fa-users input-icon"></i>
                                <select id="role" class="form-select" required>
                                    <option value="">-- Choose Type --</option>
                                    <option value="school_admin">Organization Admin</option>
                                    <option value="school_staff">Organization Staff</option>
                                    <option value="driver">Driver</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="parent">Parent</option>
                                </select>
                            </div>
                            <div class="field-error" id="roleError">Select a user type</div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════════
                         SCHOOL SEARCH SECTION (NEW)
                    ════════════════════════════════════════════════════════ -->
                    <div class="section-label">Organization Info</div>

                    <div class="form-row two-col">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">Search Organization <span style="color:var(--red)">*</span></label>
                            <div class="input-wrapper organization-search-wrapper">
                                <i class="fas fa-organization input-icon"></i>
                                <input
                                    type="text"
                                    id="schoolSearch"
                                    class="form-input"
                                    placeholder="Type organization name to search... (e.g. Delhi Public Organization)"
                                    autocomplete="off"
                                    required
                                >
                                <!-- Loading spinner inside input -->
                                <span id="schoolSpinner" style="display:none; position:absolute; right:14px;">
                                    <span style="display:inline-block;width:14px;height:14px;border:2px solid #94a3b8;border-top-color:var(--blue);border-radius:50%;animation:spin 0.7s linear infinite;"></span>
                                </span>

                                <!-- Dropdown -->
                                <div class="organization-dropdown" id="schoolDropdown">
                                    <div class="organization-dropdown-header">🏫 Organizations found in India</div>
                                    <div id="schoolDropdownBody"></div>
                                </div>
                            </div>

                            <!-- Selected organization badge -->
                            <div class="organization-selected-badge" id="schoolSelectedBadge">
                                <i class="fas fa-check-circle"></i>
                                <span id="schoolSelectedText"></span>
                                <button type="button" class="badge-clear" id="clearSchool" title="Clear selection">✕ Clear</button>
                            </div>

                            <div class="field-error" id="schoolSearchError">Please select a organization from the list</div>
                        </div>
                    </div>

                    <!-- Hidden fields for school_id and school_name -->
                    <div class="form-row two-col" style="max-width: 520px;">
                        <div class="form-group">
                            <label class="form-label">Organization ID</label>
                            <div class="input-wrapper">
                                <i class="fas fa-id-badge input-icon"></i>
                                <input type="text" id="schoolIdDisplay" class="form-input" placeholder="Auto-filled" readonly
                                    style="background:#f0fdf4; cursor:not-allowed; color:#15803d; font-weight:700;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Organization Name</label>
                            <div class="input-wrapper">
                                <i class="fas fa-organization input-icon"></i>
                                <input type="text" id="schoolNameDisplay" class="form-input" placeholder="Auto-filled" readonly
                                    style="background:#f0fdf4; cursor:not-allowed; color:#15803d;">
                            </div>
                        </div>
                    </div>

                    <!-- Hidden actual submit values -->
                    <input type="hidden" id="school_id" name="school_id" value="">
                    <input type="hidden" id="school_name" name="school_name" value="">
                    <!-- ══════════════════════════════════════════════════════ -->

                    <div class="section-label">Account Info</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" id="email" class="form-input" placeholder="Email address" required>
                            </div>
                            <div class="field-error" id="emailError">Enter a valid email</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <div class="input-wrapper">
                                <i class="fas fa-at input-icon"></i>
                                <input type="text" id="username" class="form-input" placeholder="Username" required>
                            </div>
                            <div class="field-error" id="usernameError">Min 3 chars, alphanumeric</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone input-icon"></i>
                                <input type="text" id="phoneNumber" class="form-input" placeholder="Phone number" required>
                            </div>
                            <div class="field-error" id="phoneNumberError">Enter phone number</div>
                        </div>
                    </div>

                    <div class="section-label">Address</div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: 1/-1">
                            <label class="form-label">Street</label>
                            <div class="input-wrapper">
                                <i class="fas fa-road input-icon"></i>
                                <input type="text" id="street" class="form-input" placeholder="Street address" required>
                            </div>
                            <div class="field-error" id="streetError">Enter street address</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <div class="input-wrapper">
                                <i class="fas fa-city input-icon"></i>
                                <input type="text" id="city" class="form-input" placeholder="City" required>
                            </div>
                            <div class="field-error" id="cityError">Enter city</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">State</label>
                            <div class="input-wrapper">
                                <i class="fas fa-map input-icon"></i>
                                <input type="text" id="state" class="form-input" placeholder="State" required>
                            </div>
                            <div class="field-error" id="stateError">Enter state</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Country</label>
                            <div class="input-wrapper">
                                <i class="fas fa-globe input-icon"></i>
                                <input type="text" id="country" class="form-input" placeholder="Country" required>
                            </div>
                            <div class="field-error" id="countryError">Enter country</div>
                        </div>
                    </div>

                    <div class="form-row two-col" style="max-width: 400px;">
                        <div class="form-group">
                            <label class="form-label">Zip Code</label>
                            <div class="input-wrapper">
                                <i class="fas fa-mail-bulk input-icon"></i>
                                <input type="text" id="zipCode" class="form-input" placeholder="Zip code" required>
                            </div>
                            <div class="field-error" id="zipCodeError">Enter zip code</div>
                        </div>
                    </div>

                    <!-- Hidden GPS fields -->
                    <input type="hidden" id="latitude" name="latitude" value="">
                    <input type="hidden" id="longitude" name="longitude" value="">

                    <div class="section-label">Security</div>

                    <div class="form-row two-col">
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" id="password" class="form-input" placeholder="Create password" required>
                                <button type="button" class="password-toggle" id="passwordToggle"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-strength">
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                            </div>
                            <div class="password-requirements">
                                <div class="requirement" id="lengthReq"><span class="req-icon">○</span> 8+ characters</div>
                                <div class="requirement" id="uppercaseReq"><span class="req-icon">○</span> Uppercase letter</div>
                                <div class="requirement" id="numberReq"><span class="req-icon">○</span> Number</div>
                                <div class="requirement" id="specialReq"><span class="req-icon">○</span> Special character</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" id="confirmPassword" class="form-input" placeholder="Confirm password" required>
                                <button type="button" class="password-toggle" id="confirmPasswordToggle"><i class="fas fa-eye"></i></button>
                            </div>
                            <div id="passwordMatch" class="password-match"></div>
                        </div>
                    </div>

                    <div class="checkbox-group">
                        <div class="custom-checkbox" id="agreeTerms"></div>
                        <label class="checkbox-label" id="checkboxLabel">
                            I agree to the <a href="#" id="termsLink">Terms of Service</a> and <a href="#" id="privacyLink">Privacy Policy</a>
                        </label>
                    </div>

                    <div class="form-actions">
                        <a href="users.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="signup-button" id="signupBtn">Add User</button>
                    </div>

                </form>
            </div>
        </main>
    </div>
</div>

<script>
// ── Sidebar toggle ────────────────────────────────────────────────────────
document.getElementById('menuToggle').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('sidebarOverlay').classList.toggle('active');
});
document.getElementById('sidebarOverlay').addEventListener('click', function() {
    document.getElementById('sidebar').classList.remove('active');
    this.classList.remove('active');
});

// ── Elements ──────────────────────────────────────────────────────────────
const signupForm           = document.getElementById('signupForm');
const firstNameInput       = document.getElementById('firstName');
const lastNameInput        = document.getElementById('lastName');
const roleInput            = document.getElementById('role');
const emailInput           = document.getElementById('email');
const usernameInput        = document.getElementById('username');
const phoneNumberInput     = document.getElementById('phoneNumber');
const streetInput          = document.getElementById('street');
const cityInput            = document.getElementById('city');
const stateInput           = document.getElementById('state');
const countryInput         = document.getElementById('country');
const zipCodeInput         = document.getElementById('zipCode');
const passwordInput        = document.getElementById('password');
const confirmPasswordInput = document.getElementById('confirmPassword');
const agreeTermsCheckbox   = document.getElementById('agreeTerms');
const signupBtn            = document.getElementById('signupBtn');
const strengthBars         = document.querySelectorAll('.strength-bar');
const lengthReq            = document.getElementById('lengthReq');
const uppercaseReq         = document.getElementById('uppercaseReq');
const numberReq            = document.getElementById('numberReq');
const specialReq           = document.getElementById('specialReq');
const passwordMatch        = document.getElementById('passwordMatch');
const locationBanner       = document.getElementById('locationBanner');
const locationSpinner      = document.getElementById('locationSpinner');
const locationIcon         = document.getElementById('locationIcon');
const locationText         = document.getElementById('locationText');

// ══════════════════════════════════════════════════════════════════════════
//  SCHOOL AUTO-SEARCH SYSTEM
// ══════════════════════════════════════════════════════════════════════════
const schoolSearchInput   = document.getElementById('schoolSearch');
const schoolDropdown      = document.getElementById('schoolDropdown');
const schoolDropdownBody  = document.getElementById('schoolDropdownBody');
const schoolSpinner       = document.getElementById('schoolSpinner');
const schoolSelectedBadge = document.getElementById('schoolSelectedBadge');
const schoolSelectedText  = document.getElementById('schoolSelectedText');
const clearSchoolBtn      = document.getElementById('clearSchool');
const schoolIdDisplay     = document.getElementById('schoolIdDisplay');
const schoolNameDisplay   = document.getElementById('schoolNameDisplay');
const schoolIdHidden      = document.getElementById('school_id');
const schoolNameHidden    = document.getElementById('school_name');

let schoolSearchTimer     = null;
let selectedSchool        = null;
let highlightedIndex      = -1;
let currentResults        = [];

// Fetch schools from your database via search_school.php
// OR use the built-in fallback Indian organization data below
async function searchSchools(query) {
    // ── Aapke database se schools fetch karta hai ──────────────────────
    try {
        const res = await fetch(`search_school.php?q=${encodeURIComponent(query)}`);
        const data = await res.json();
        return data.schools || [];
    } catch(e) {
        console.error('Organization search failed:', e);
        return [];
    }
}

// ── Large India organization dataset (for offline fallback / demo) ──────────
// You can remove this whole block once your PHP API is working
const INDIA_SCHOOLS = [
    // Delhi
    { id: 1,  name: "Delhi Public Organization, R.K. Puram",        city: "New Delhi",    state: "Delhi" },
    { id: 2,  name: "Delhi Public Organization, Dwarka",             city: "New Delhi",    state: "Delhi" },
    { id: 3,  name: "Delhi Public Organization, Mathura Road",       city: "New Delhi",    state: "Delhi" },
    { id: 4,  name: "Kendriya Vidyalaya, Andrews Ganj",        city: "New Delhi",    state: "Delhi" },
    { id: 5,  name: "Kendriya Vidyalaya, Janakpuri",           city: "New Delhi",    state: "Delhi" },
    { id: 6,  name: "Modern Organization, Barakhamba Road",          city: "New Delhi",    state: "Delhi" },
    { id: 7,  name: "Springdales Organization, Pusa Road",           city: "New Delhi",    state: "Delhi" },
    { id: 8,  name: "The Mother's International Organization",       city: "New Delhi",    state: "Delhi" },
    { id: 9,  name: "Laxman Public Organization",                    city: "New Delhi",    state: "Delhi" },
    { id: 10, name: "Ryan International Organization, Vasant Kunj",  city: "New Delhi",    state: "Delhi" },
    // UP
    { id: 11, name: "City Montessori Organization, Lucknow",         city: "Lucknow",      state: "Uttar Pradesh" },
    { id: 12, name: "La Martiniere College",                    city: "Lucknow",      state: "Uttar Pradesh" },
    { id: 13, name: "Colvin Taluqdars' College",               city: "Lucknow",      state: "Uttar Pradesh" },
    { id: 14, name: "Seth Anandram Jaipuria Organization",           city: "Lucknow",      state: "Uttar Pradesh" },
    { id: 15, name: "Delhi Public Organization, Agra",               city: "Agra",         state: "Uttar Pradesh" },
    { id: 16, name: "St. Peter's College, Agra",               city: "Agra",         state: "Uttar Pradesh" },
    { id: 17, name: "Kendriya Vidyalaya, Gangoh",              city: "Gangoh",       state: "Uttar Pradesh" },
    { id: 18, name: "Kendriya Vidyalaya, Saharanpur",          city: "Saharanpur",   state: "Uttar Pradesh" },
    { id: 19, name: "Delhi Public Organization, Varanasi",           city: "Varanasi",     state: "Uttar Pradesh" },
    { id: 20, name: "Sun Valley International Organization, Noida",  city: "Noida",        state: "Uttar Pradesh" },
    { id: 21, name: "Delhi Public Organization, Noida",              city: "Noida",        state: "Uttar Pradesh" },
    // Maharashtra
    { id: 22, name: "Cathedral and John Connon Organization",        city: "Mumbai",       state: "Maharashtra" },
    { id: 23, name: "Dhirubhai Ambani International Organization",   city: "Mumbai",       state: "Maharashtra" },
    { id: 24, name: "Ryan International Organization, Chembur",      city: "Mumbai",       state: "Maharashtra" },
    { id: 25, name: "Delhi Public Organization, Pune",               city: "Pune",         state: "Maharashtra" },
    { id: 26, name: "The Orchid Organization, Pune",                 city: "Pune",         state: "Maharashtra" },
    // Karnataka
    { id: 27, name: "Delhi Public Organization, Bangalore",          city: "Bangalore",    state: "Karnataka" },
    { id: 28, name: "National Public Organization, Bangalore",       city: "Bangalore",    state: "Karnataka" },
    { id: 29, name: "Bishop Cotton Boys' Organization",              city: "Bangalore",    state: "Karnataka" },
    { id: 30, name: "Mallya Aditi International Organization",       city: "Bangalore",    state: "Karnataka" },
    // Tamil Nadu
    { id: 31, name: "The Hindu Senior Secondary Organization",       city: "Chennai",      state: "Tamil Nadu" },
    { id: 32, name: "Chettinad Vidyashram",                    city: "Chennai",      state: "Tamil Nadu" },
    { id: 33, name: "Delhi Public Organization, Chennai",            city: "Chennai",      state: "Tamil Nadu" },
    // Rajasthan
    { id: 34, name: "Maharaja Sawai Man Singh Vidyalaya",      city: "Jaipur",       state: "Rajasthan" },
    { id: 35, name: "Delhi Public Organization, Jaipur",             city: "Jaipur",       state: "Rajasthan" },
    { id: 36, name: "St. Xavier's Organization, Jaipur",             city: "Jaipur",       state: "Rajasthan" },
    // Gujarat
    { id: 37, name: "Delhi Public Organization, Ahmedabad",          city: "Ahmedabad",    state: "Gujarat" },
    { id: 38, name: "Udgam Organization for Children",               city: "Ahmedabad",    state: "Gujarat" },
    // West Bengal
    { id: 39, name: "La Martiniere for Boys",                  city: "Kolkata",      state: "West Bengal" },
    { id: 40, name: "St. Xavier's Collegiate Organization",          city: "Kolkata",      state: "West Bengal" },
    { id: 41, name: "Delhi Public Organization, Kolkata",            city: "Kolkata",      state: "West Bengal" },
    // Haryana
    { id: 42, name: "Delhi Public Organization, Gurgaon",            city: "Gurgaon",      state: "Haryana" },
    { id: 43, name: "Shri Ram Organization, Gurgaon",                city: "Gurgaon",      state: "Haryana" },
    // Telangana
    { id: 44, name: "Delhi Public Organization, Hyderabad",          city: "Hyderabad",    state: "Telangana" },
    { id: 45, name: "Oakridge International Organization",           city: "Hyderabad",    state: "Telangana" },
    // Punjab
    { id: 46, name: "Sacred Heart Organization, Amritsar",           city: "Amritsar",     state: "Punjab" },
    { id: 47, name: "Bhavan Vidyalaya, Chandigarh",            city: "Chandigarh",   state: "Punjab" },
    // Bihar
    { id: 48, name: "Delhi Public Organization, Patna",              city: "Patna",        state: "Bihar" },
    { id: 49, name: "Notre Dame Academy, Patna",               city: "Patna",        state: "Bihar" },
    // Madhya Pradesh
    { id: 50, name: "Delhi Public Organization, Bhopal",             city: "Bhopal",       state: "Madhya Pradesh" },
];

function filterLocalSchools(query) {
    if (!query || query.length < 2) return [];
    const q = query.toLowerCase();
    return INDIA_SCHOOLS.filter(s =>
        s.name.toLowerCase().includes(q) ||
        s.city.toLowerCase().includes(q) ||
        s.state.toLowerCase().includes(q)
    ).slice(0, 10);
}

function highlightText(text, query) {
    if (!query) return text;
    const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<span class="match-highlight">$1</span>');
}

function renderDropdown(results, query) {
    currentResults = results;
    highlightedIndex = -1;
    schoolDropdownBody.innerHTML = '';

    if (results.length === 0) {
        schoolDropdownBody.innerHTML = `<div class="organization-no-result">😕 No organization found for "<strong>${query}</strong>".<br><small>Try a different name, city or state.</small></div>`;
    } else {
        results.forEach((organization, i) => {
            const div = document.createElement('div');
            div.className = 'organization-option';
            div.setAttribute('data-index', i);
            div.innerHTML = `
                <div class="organization-option-name">${highlightText(organization.name, query)}</div>
                <div class="organization-option-meta">
                    <span class="organization-option-id">ID: ${organization.id}</span>
                    <span>📍 ${organization.city}, ${organization.state}</span>
                </div>`;
            div.addEventListener('mousedown', (e) => {
                e.preventDefault();
                selectSchool(organization);
            });
            schoolDropdownBody.appendChild(div);
        });
    }
    schoolDropdown.classList.add('open');
}

function selectSchool(organization) {
    selectedSchool = organization;
    // Fill hidden fields
    schoolIdHidden.value    = organization.id;
    schoolNameHidden.value  = organization.name;
    // Fill display fields
    schoolIdDisplay.value   = organization.id;
    schoolNameDisplay.value = organization.name;
    // Fill search box
    schoolSearchInput.value = organization.name;
    schoolSearchInput.classList.add('valid');
    schoolSearchInput.classList.remove('invalid');
    // Show badge
    schoolSelectedText.textContent = `✅ ${organization.name} | ID: ${organization.id} | ${organization.city}, ${organization.state}`;
    schoolSelectedBadge.classList.add('show');
    // Hide error
    document.getElementById('schoolSearchError').style.display = 'none';
    // Close dropdown
    schoolDropdown.classList.remove('open');
    updateSubmitButton();
}

function clearSchool() {
    selectedSchool          = null;
    schoolIdHidden.value    = '';
    schoolNameHidden.value  = '';
    schoolIdDisplay.value   = '';
    schoolNameDisplay.value = '';
    schoolSearchInput.value = '';
    schoolSearchInput.classList.remove('valid', 'invalid');
    schoolSelectedBadge.classList.remove('show');
    schoolDropdown.classList.remove('open');
    updateSubmitButton();
}

clearSchoolBtn.addEventListener('click', clearSchool);

schoolSearchInput.addEventListener('input', function() {
    const q = this.value.trim();
    clearTimeout(schoolSearchTimer);

    if (selectedSchool && q !== selectedSchool.name) {
        // User changed text after selecting — reset
        selectedSchool         = null;
        schoolIdHidden.value   = '';
        schoolNameHidden.value = '';
        schoolIdDisplay.value  = '';
        schoolNameDisplay.value= '';
        schoolSelectedBadge.classList.remove('show');
    }

    if (q.length < 2) {
        schoolDropdown.classList.remove('open');
        schoolSpinner.style.display = 'none';
        return;
    }

    schoolSpinner.style.display = 'block';
    schoolSearchTimer = setTimeout(async () => {
        const results = await searchSchools(q);
        schoolSpinner.style.display = 'none';
        renderDropdown(results, q);
    }, 300);
});

// Keyboard navigation in dropdown
schoolSearchInput.addEventListener('keydown', function(e) {
    const options = schoolDropdownBody.querySelectorAll('.organization-option');
    if (!options.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightedIndex = Math.min(highlightedIndex + 1, options.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightedIndex = Math.max(highlightedIndex - 1, 0);
    } else if (e.key === 'Enter' && highlightedIndex >= 0) {
        e.preventDefault();
        selectSchool(currentResults[highlightedIndex]);
        return;
    } else if (e.key === 'Escape') {
        schoolDropdown.classList.remove('open');
        return;
    }

    options.forEach((o, i) => o.classList.toggle('highlighted', i === highlightedIndex));
    if (highlightedIndex >= 0) options[highlightedIndex].scrollIntoView({ block: 'nearest' });
});

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.organization-search-wrapper')) {
        schoolDropdown.classList.remove('open');
    }
});

// ══════════════════════════════════════════════════════════════════════════
//  END SCHOOL SEARCH
// ══════════════════════════════════════════════════════════════════════════

// ── GPS Location ──────────────────────────────────────────────────────────
function showLocationBanner(state, msg) {
    locationBanner.className = 'location-banner show ' + (state || '');
    locationSpinner.style.display = state === 'loading' ? 'block' : 'none';
    locationIcon.style.display    = state !== 'loading' ? 'inline' : 'none';
    locationIcon.textContent = state === 'success' ? '✅' : state === 'error' ? '⚠️' : '📍';
    locationText.textContent = msg;
    if (state === 'success') setTimeout(() => locationBanner.classList.remove('show'), 4000);
}

function fetchParentLocation() {
    if (!navigator.geolocation) { showLocationBanner('error', 'Geolocation not supported.'); return; }
    showLocationBanner('loading', 'Fetching location...');
    navigator.geolocation.getCurrentPosition(
        pos => {
            document.getElementById('latitude').value  = pos.coords.latitude;
            document.getElementById('longitude').value = pos.coords.longitude;
            showLocationBanner('success', 'Location saved: ' + pos.coords.latitude.toFixed(5) + ', ' + pos.coords.longitude.toFixed(5));
        },
        err => {
            let msg = 'Location access denied. ';
            if (err.code === 1) msg += 'Allow location in browser settings.';
            else if (err.code === 2) msg += 'Position unavailable.';
            else if (err.code === 3) msg += 'Request timed out.';
            document.getElementById('latitude').value  = '';
            document.getElementById('longitude').value = '';
            showLocationBanner('error', msg);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

roleInput.addEventListener('change', function() {
    const isValid = this.value !== '';
    this.classList.toggle('valid', isValid);
    this.classList.toggle('invalid', !isValid);
    document.getElementById('roleError').style.display = isValid ? 'none' : 'block';
    if (this.value === 'parent') fetchParentLocation();
    else { document.getElementById('latitude').value = ''; document.getElementById('longitude').value = ''; locationBanner.classList.remove('show'); }
    updateSubmitButton();
});

// ── Password Toggles ──────────────────────────────────────────────────────
document.getElementById('passwordToggle').addEventListener('click', function() {
    const t = passwordInput.type === 'password' ? 'text' : 'password';
    passwordInput.type = t;
    this.querySelector('i').className = t === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
});
document.getElementById('confirmPasswordToggle').addEventListener('click', function() {
    const t = confirmPasswordInput.type === 'password' ? 'text' : 'password';
    confirmPasswordInput.type = t;
    this.querySelector('i').className = t === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
});

// ── Checkbox ──────────────────────────────────────────────────────────────
agreeTermsCheckbox.addEventListener('click', function(e) { e.stopPropagation(); this.classList.toggle('checked'); updateSubmitButton(); });
document.getElementById('checkboxLabel').addEventListener('click', function(e) { if (e.target.tagName !== 'A') { e.preventDefault(); e.stopPropagation(); agreeTermsCheckbox.click(); } });

// ── Validators ────────────────────────────────────────────────────────────
function validateName(v)     { const t = v.trim(); return t.length >= 2 && /^[a-zA-Z\s]+$/.test(t); }
function validateEmail(v)    { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); }
function validateUsername(v) { const t = v.trim(); return t.length >= 3 && /^[a-zA-Z0-9_]+$/.test(t); }
function validateNotEmpty(v) { return v && v.trim().length > 0; }
function validateRole(v)     { return v !== ''; }
function validateSchool()    { return selectedSchool !== null; }

function showFieldValidation(fieldId, isValid) {
    const field = document.getElementById(fieldId);
    const errEl = document.getElementById(fieldId + 'Error');
    if (!field.value || field.value.trim() === '') { field.classList.remove('valid', 'invalid'); if (errEl) errEl.style.display = 'none'; return false; }
    field.classList.toggle('valid', isValid);
    field.classList.toggle('invalid', !isValid);
    if (errEl) errEl.style.display = isValid ? 'none' : 'block';
    return isValid;
}

const fieldValidations = [
    ['firstName',   () => validateName(firstNameInput.value)],
    ['lastName',    () => validateName(lastNameInput.value)],
    ['email',       () => validateEmail(emailInput.value)],
    ['username',    () => validateUsername(usernameInput.value)],
    ['phoneNumber', () => validateNotEmpty(phoneNumberInput.value)],
    ['street',      () => validateNotEmpty(streetInput.value)],
    ['city',        () => validateNotEmpty(cityInput.value)],
    ['state',       () => validateNotEmpty(stateInput.value)],
    ['country',     () => validateNotEmpty(countryInput.value)],
    ['zipCode',     () => validateNotEmpty(zipCodeInput.value)],
];

fieldValidations.forEach(([id, fn]) => {
    document.getElementById(id).addEventListener('input', function() { showFieldValidation(id, fn()); updateSubmitButton(); });
});

passwordInput.addEventListener('input', function() { checkPasswordStrength(this.value); checkPasswordMatch(); updateSubmitButton(); });
confirmPasswordInput.addEventListener('input', function() { checkPasswordMatch(); updateSubmitButton(); });

// ── Password Strength ─────────────────────────────────────────────────────
function checkPasswordStrength(pw) {
    const req = { length: pw.length >= 8, uppercase: /[A-Z]/.test(pw), number: /\d/.test(pw), special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pw) };
    const upd = (el, met) => { el.classList.toggle('met', met); el.querySelector('.req-icon').textContent = met ? '✓' : '○'; };
    upd(lengthReq, req.length); upd(uppercaseReq, req.uppercase); upd(numberReq, req.number); upd(specialReq, req.special);
    const str = Object.values(req).filter(Boolean).length;
    strengthBars.forEach((b, i) => { b.className = 'strength-bar'; if (i < str) b.classList.add(str <= 1 ? 'weak' : str <= 3 ? 'medium' : 'strong'); });
    return Object.values(req).every(Boolean);
}

function checkPasswordMatch() {
    const p = passwordInput.value, c = confirmPasswordInput.value;
    if (!c) { passwordMatch.textContent = ''; return false; }
    const ok = p === c;
    passwordMatch.textContent = ok ? '✓ Passwords match' : '✗ Passwords do not match';
    passwordMatch.style.color = ok ? '#10b981' : '#ef4444';
    return ok;
}

// ── Submit Button State ───────────────────────────────────────────────────
const checkLabels = [
    'Enter Valid First Name', 'Enter Valid Last Name', 'Select a User Type',
    'Select a Organization',        'Enter Valid Email',     'Enter Valid Username',
    'Enter Phone Number',     'Enter Street',          'Enter City',
    'Enter State',            'Enter Country',         'Enter Zip Code',
    'Complete Password Requirements', 'Passwords Must Match', 'Accept Terms & Conditions'
];

function updateSubmitButton() {
    const checks = [
        validateName(firstNameInput.value),
        validateName(lastNameInput.value),
        validateRole(roleInput.value),
        validateSchool(),
        validateEmail(emailInput.value),
        validateUsername(usernameInput.value),
        validateNotEmpty(phoneNumberInput.value),
        validateNotEmpty(streetInput.value),
        validateNotEmpty(cityInput.value),
        validateNotEmpty(stateInput.value),
        validateNotEmpty(countryInput.value),
        validateNotEmpty(zipCodeInput.value),
        checkPasswordStrength(passwordInput.value),
        checkPasswordMatch(),
        agreeTermsCheckbox.classList.contains('checked')
    ];
    const valid = checks.every(Boolean);
    signupBtn.disabled = !valid;
    const idx = checks.findIndex(c => !c);
    signupBtn.textContent = (!valid && idx >= 0) ? checkLabels[idx] : 'Add User';
}

// ── Form Submit ───────────────────────────────────────────────────────────
signupForm.addEventListener('submit', function(e) {
    e.preventDefault();
    updateSubmitButton();
    if (signupBtn.disabled) { alert('Please fix all errors before submitting.'); return; }

    signupBtn.classList.add('loading');
    signupBtn.textContent = 'Adding User...';
    signupBtn.disabled = true;

    const lat = document.getElementById('latitude').value;
    const lng = document.getElementById('longitude').value;
    const fullAddress = [streetInput.value.trim(), cityInput.value.trim(), stateInput.value.trim(), countryInput.value.trim()].join(', ') + ' - ' + zipCodeInput.value.trim();

    const formData = {
        firstName:    firstNameInput.value.trim(),
        lastName:     lastNameInput.value.trim(),
        name:         firstNameInput.value.trim() + ' ' + lastNameInput.value.trim(),
        role:         roleInput.value,
        school_id:    selectedSchool ? selectedSchool.id   : null,
        school_name:  selectedSchool ? selectedSchool.name : null,
        email:        emailInput.value.trim(),
        username:     usernameInput.value.trim(),
        phone_number: phoneNumberInput.value.trim(),
        address:      fullAddress,
        street:       streetInput.value.trim(),
        city:         cityInput.value.trim(),
        state:        stateInput.value.trim(),
        country:      countryInput.value.trim(),
        zipcode:      zipCodeInput.value.trim(),
        password:     passwordInput.value,
        latitude:     lat || null,
        longitude:    lng || null
    };

    fetch('signup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
    .then(r => r.json())
    .then(data => {
        signupBtn.classList.remove('loading');
        signupBtn.textContent = 'Add User';
        if (data.success) {
            alert('User Added Successfully!\nName: ' + formData.name + '\nRole: ' + formData.role + '\nSchool: ' + formData.school_name + ' (ID: ' + formData.school_id + ')');
            signupForm.reset();
            clearSchool();
            agreeTermsCheckbox.classList.remove('checked');
            passwordMatch.textContent = '';
            strengthBars.forEach(b => b.className = 'strength-bar');
            [lengthReq, uppercaseReq, numberReq, specialReq].forEach(r => { r.classList.remove('met'); r.querySelector('.req-icon').textContent = '○'; });
            document.getElementById('latitude').value  = '';
            document.getElementById('longitude').value = '';
            locationBanner.classList.remove('show');
            updateSubmitButton();
            setTimeout(() => { window.location.href = 'users.php'; }, 1500);
        } else {
            alert('Error: ' + data.message);
            signupBtn.disabled = false;
        }
    })
    .catch(err => {
        signupBtn.classList.remove('loading');
        signupBtn.textContent = 'Add User';
        signupBtn.disabled = false;
        alert('Error: ' + err.message);
    });
});

document.getElementById('termsLink').addEventListener('click', e => { e.preventDefault(); alert('Terms of Service'); });
document.getElementById('privacyLink').addEventListener('click', e => { e.preventDefault(); alert('Privacy Policy'); });

updateSubmitButton();
</script>
</body>
</html>