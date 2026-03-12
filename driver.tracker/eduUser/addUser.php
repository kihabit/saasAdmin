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

$user_id  = $_SESSION['user_id']  ?? 0;
$username = $_SESSION['username'] ?? '';

if (isset($_GET['logout'])) {
    session_unset(); session_destroy(); redirect(LOGIN_PAGE);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - <?php echo APP_NAME; ?></title>
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
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }
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

        /* ── Messages ── */
        .message-container { margin-bottom: 1.25rem; }
        .message { padding: 0.85rem 1.25rem; border-radius: 10px; display: flex; align-items: center; gap: 10px; font-weight: 500; font-size: 0.9rem; animation: slideIn 0.3s ease; }
        .message.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .message.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* ── Form Card ── */
        .form-card { background: white; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1rem; font-weight: 600; color: #1a202c; display: flex; align-items: center; gap: 8px; }
        .card-body { padding: 1.5rem; }

        /* ── Section Label ── */
        .section-block { margin-bottom: 1.75rem; }
        .section-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #718096; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; }
        .section-label i { color: #0000FF; font-size: 0.85rem; }

        /* ── Form Grid ── */
        .fg3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
        .fg2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 1rem; }
        .fg1 { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 0.78rem; font-weight: 600; color: #4a5568; text-transform: uppercase; letter-spacing: 0.04em; }
        .form-label span { color: #dc2626; }
        .iw { position: relative; display: flex; align-items: center; }
        .ii { position: absolute; left: 12px; color: #9ca3af; font-size: 13px; pointer-events: none; z-index: 1; }
        .fc { width: 100%; padding: 9px 12px 9px 36px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 0.88rem; font-family: 'Inter', sans-serif; color: #1a202c; transition: all 0.25s; outline: none; }
        .fc:focus { border-color: #0000FF; background: white; box-shadow: 0 0 0 3px rgba(0,0,255,0.08); }
        .fc.valid   { border-color: #10b981; background: white; }
        .fc.invalid { border-color: #ef4444; background: #fff5f5; }
        .fc::placeholder { color: #9ca3af; }
        select.fc { appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='7' viewBox='0 0 10 7'%3E%3Cpath fill='%2394a3b8' d='M5 7L0 0h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }
        .fc[readonly] { background: #f0fdf4; cursor: not-allowed; color: #15803d; font-weight: 600; }
        .ferr { font-size: 0.74rem; color: #ef4444; font-weight: 500; display: none; }

        /* ── Org Search ── */
        .org-wrap { position: relative; }
        .org-drop { display: none; position: absolute; top: calc(100% + 3px); left: 0; right: 0; background: white; border: 1px solid #0000FF; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,255,0.1); z-index: 500; max-height: 260px; overflow-y: auto; }
        .org-drop.open { display: block; }
        .org-hdr { padding: 7px 12px; font-size: 0.7rem; font-weight: 700; color: #718096; text-transform: uppercase; letter-spacing: 0.07em; border-bottom: 1px solid #f1f5f9; background: #f8fafc; border-radius: 10px 10px 0 0; }
        .org-opt { padding: 9px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.15s; }
        .org-opt:last-child { border-bottom: none; border-radius: 0 0 10px 10px; }
        .org-opt:hover, .org-opt.hl { background: #eff6ff; }
        .org-opt-name { font-size: 0.86rem; font-weight: 600; color: #1a202c; }
        .org-opt-meta { font-size: 0.74rem; color: #718096; display: flex; gap: 7px; margin-top: 2px; }
        .org-id-tag { background: #dbeafe; color: #1d4ed8; padding: 1px 5px; border-radius: 4px; font-size: 0.68rem; font-weight: 700; }
        .org-empty { padding: 14px 12px; text-align: center; color: #9ca3af; font-size: 0.83rem; }
        .mhl { color: #0000FF; font-weight: 700; }
        .org-badge { display: none; align-items: center; gap: 7px; margin-top: 6px; padding: 5px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 7px; font-size: 0.76rem; color: #15803d; font-weight: 500; }
        .org-badge.show { display: flex; }
        .clr-btn { margin-left: auto; background: none; border: none; color: #15803d; cursor: pointer; font-size: 11px; opacity: 0.7; }
        .clr-btn:hover { opacity: 1; }

        /* ── Password ── */
        .pw-tog { position: absolute; right: 11px; background: none; border: none; color: #9ca3af; cursor: pointer; font-size: 13px; z-index: 1; }
        .pw-tog:hover { color: #0000FF; }
        .pw-str { display: flex; gap: 3px; margin-top: 5px; }
        .pw-bar { height: 3px; flex: 1; background: #e2e8f0; border-radius: 2px; transition: background 0.3s; }
        .pw-bar.w { background: #ef4444; }
        .pw-bar.m { background: #f59e0b; }
        .pw-bar.s { background: #10b981; }
        .pw-reqs { margin-top: 7px; font-size: 0.74rem; color: #9ca3af; display: flex; flex-direction: column; gap: 2px; }
        .req { display: flex; align-items: center; gap: 5px; transition: color 0.2s; }
        .req.met { color: #10b981; }
        .req-ic { width: 12px; text-align: center; font-size: 10px; }
        .pw-match { font-size: 0.76rem; font-weight: 500; margin-top: 5px; }

        /* ── Location ── */
        .loc-ban { display: none; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 8px; font-size: 0.83rem; font-weight: 500; margin-bottom: 1rem; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }
        .loc-ban.show { display: flex; }
        .loc-ban.ok  { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
        .loc-ban.err { background: #fff7ed; border-color: #fed7aa; color: #c2410c; }
        .loc-spin { width: 14px; height: 14px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.7s linear infinite; flex-shrink: 0; }

        /* ── Checkbox ── */
        .chk-row { display: flex; align-items: flex-start; gap: 9px; margin: 0.5rem 0 1.5rem; }
        .chk-box { width: 17px; height: 17px; border: 1.5px solid #e2e8f0; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; background: white; transition: all 0.2s; }
        .chk-box.on { background: #0000FF; border-color: #0000FF; }
        .chk-box.on::after { content: '✓'; color: white; font-size: 9px; font-weight: 700; }
        .chk-lbl { font-size: 0.83rem; color: #4a5568; cursor: pointer; user-select: none; font-weight: 500; }
        .chk-lbl a { color: #0000FF; text-decoration: none; font-weight: 600; }

        /* ── Form Actions ── */
        .form-actions { display: flex; gap: 0.75rem; justify-content: flex-end; padding-top: 1.25rem; border-top: 1px solid #e2e8f0; }
        .btn-cancel { background: #f3f4f6; color: #4a5568; border: none; padding: 9px 18px; border-radius: 8px; font-weight: 500; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 0.88rem; text-decoration: none; transition: all 0.2s; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-submit { background: #0000FF; color: white; border: none; padding: 9px 22px; border-radius: 8px; font-weight: 600; font-family: 'Inter', sans-serif; font-size: 0.88rem; cursor: pointer; transition: all 0.25s; position: relative; white-space: nowrap; }
        .btn-submit:hover:not(:disabled) { background: #0000CC; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,255,0.3); }
        .btn-submit:disabled { background: #9ca3af; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-submit.loading::after { content: ''; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; border: 2px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; animation: spin 0.7s linear infinite; }

        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; display: none; }
        .sidebar-overlay.active { display: block; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .menu-toggle { display: block; }
            .fg3 { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 768px) {
            .header, .main-content { padding: 0.75rem 1rem; }
            .card-body { padding: 1rem; }
            .fg3, .fg2 { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column-reverse; }
        }
        @keyframes spin    { to { transform: rotate(360deg); } }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut{ from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
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
                        <a href="dashboard.php">Home</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="users.php">Users</a>
                        <i class="fas fa-chevron-right"></i>
                        <span>Add User</span>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="users.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>

        <main class="main-content">

            <div class="page-header">
                <div class="page-title">
                    <h1>Add New User</h1>
                    <p>Create a new account for organization staff, driver, teacher or parent</p>
                </div>
            </div>

            <div id="msgBox"></div>

            <div class="form-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-plus"></i> User Registration Form</h3>
                </div>
                <div class="card-body">

                    <div class="loc-ban" id="locBan"></div>

                    <form id="addUserForm">

                        <!-- Personal Info -->
                        <div class="section-block">
                            <div class="section-label"><i class="fas fa-user"></i> Personal Information</div>
                            <div class="fg3">
                                <div class="form-group">
                                    <label class="form-label">First Name <span>*</span></label>
                                    <div class="iw"><i class="fas fa-user ii"></i>
                                        <input type="text" id="firstName" class="fc" placeholder="First name">
                                    </div>
                                    <span class="ferr" id="firstNameError">Min 2 chars, letters only</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Last Name <span>*</span></label>
                                    <div class="iw"><i class="fas fa-user ii"></i>
                                        <input type="text" id="lastName" class="fc" placeholder="Last name">
                                    </div>
                                    <span class="ferr" id="lastNameError">Min 2 chars, letters only</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">User Type <span>*</span></label>
                                    <div class="iw"><i class="fas fa-users ii"></i>
                                        <select id="role" class="fc">
                                            <option value="">-- Select Type --</option>
                                            <option value="school_admin">Organization Admin</option>
                                            <option value="school_staff">Organization Staff</option>
                                            <option value="driver">Driver</option>
                                            <option value="teacher">Teacher</option>
                                            <option value="parent">Parent</option>
                                        </select>
                                    </div>
                                    <span class="ferr" id="roleError">Please select a user type</span>
                                </div>
                            </div>
                        </div>

                        <!-- Organization -->
                        <div class="section-block">
                            <div class="section-label"><i class="fas fa-school"></i> Organization</div>
                            <div class="fg1">
                                <div class="form-group full">
                                    <label class="form-label">Search Organization <span>*</span></label>
                                    <div class="iw org-wrap">
                                        <i class="fas fa-search ii"></i>
                                        <input type="text" id="orgSearch" class="fc" placeholder="Type organization name or ID to search..." autocomplete="off">
                                        <span id="orgSpn" style="display:none;position:absolute;right:12px;">
                                            <span style="display:inline-block;width:13px;height:13px;border:2px solid #9ca3af;border-top-color:#0000FF;border-radius:50%;animation:spin 0.7s linear infinite;"></span>
                                        </span>
                                        <div class="org-drop" id="orgDrop">
                                            <div class="org-hdr">🏫 Search Results</div>
                                            <div id="orgBody"></div>
                                        </div>
                                    </div>
                                    <div class="org-badge" id="orgBadge">
                                        <i class="fas fa-check-circle"></i>
                                        <span id="orgBadgeTx"></span>
                                        <button type="button" class="clr-btn" id="clrOrg">✕ Clear</button>
                                    </div>
                                    <span class="ferr" id="orgSearchError">Please select an organization</span>
                                </div>
                            </div>
                            <div class="fg3" style="margin-top:0.75rem;">
                                <div class="form-group">
                                    <label class="form-label">Organization ID</label>
                                    <div class="iw"><i class="fas fa-id-badge ii"></i>
                                        <input type="text" id="orgIdDis" class="fc" placeholder="Auto-filled" readonly>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Organization Name</label>
                                    <div class="iw"><i class="fas fa-building ii"></i>
                                        <input type="text" id="orgNmDis" class="fc" placeholder="Auto-filled" readonly>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Industry Type</label>
                                    <div class="iw"><i class="fas fa-industry ii"></i>
                                        <input type="text" id="orgIndDis" class="fc" placeholder="Auto-filled" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" id="school_id"   value="">
                        <input type="hidden" id="school_name" value="">
                        <input type="hidden" id="industry_id" value="">

                        <!-- Account Info -->
                        <div class="section-block">
                            <div class="section-label"><i class="fas fa-id-card"></i> Account Information</div>
                            <div class="fg3">
                                <div class="form-group">
                                    <label class="form-label">Email <span>*</span></label>
                                    <div class="iw"><i class="fas fa-envelope ii"></i>
                                        <input type="email" id="email" class="fc" placeholder="Email address">
                                    </div>
                                    <span class="ferr" id="emailError">Enter a valid email</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Username <span>*</span></label>
                                    <div class="iw"><i class="fas fa-at ii"></i>
                                        <input type="text" id="username" class="fc" placeholder="Username">
                                    </div>
                                    <span class="ferr" id="usernameError">Min 3 chars, alphanumeric</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone <span>*</span></label>
                                    <div class="iw"><i class="fas fa-phone ii"></i>
                                        <input type="text" id="phoneNumber" class="fc" placeholder="Phone number">
                                    </div>
                                    <span class="ferr" id="phoneNumberError">Enter phone number</span>
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="section-block">
                            <div class="section-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                            <div class="fg3">
                                <div class="form-group full">
                                    <label class="form-label">Street <span>*</span></label>
                                    <div class="iw"><i class="fas fa-road ii"></i>
                                        <input type="text" id="street" class="fc" placeholder="Street address">
                                    </div>
                                    <span class="ferr" id="streetError">Enter street address</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">City <span>*</span></label>
                                    <div class="iw"><i class="fas fa-city ii"></i>
                                        <input type="text" id="city" class="fc" placeholder="City">
                                    </div>
                                    <span class="ferr" id="cityError">Enter city</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">State <span>*</span></label>
                                    <div class="iw"><i class="fas fa-map ii"></i>
                                        <input type="text" id="state" class="fc" placeholder="State">
                                    </div>
                                    <span class="ferr" id="stateError">Enter state</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Country <span>*</span></label>
                                    <div class="iw"><i class="fas fa-globe ii"></i>
                                        <input type="text" id="country" class="fc" placeholder="Country">
                                    </div>
                                    <span class="ferr" id="countryError">Enter country</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Zip Code <span>*</span></label>
                                    <div class="iw"><i class="fas fa-mail-bulk ii"></i>
                                        <input type="text" id="zipCode" class="fc" placeholder="Zip code">
                                    </div>
                                    <span class="ferr" id="zipCodeError">Enter zip code</span>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" id="latitude"  value="">
                        <input type="hidden" id="longitude" value="">

                        <!-- Security -->
                        <div class="section-block">
                            <div class="section-label"><i class="fas fa-lock"></i> Security</div>
                            <div class="fg2">
                                <div class="form-group">
                                    <label class="form-label">Password <span>*</span></label>
                                    <div class="iw"><i class="fas fa-lock ii"></i>
                                        <input type="password" id="password" class="fc" placeholder="Create password">
                                        <button type="button" class="pw-tog" id="pTog"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="pw-str">
                                        <div class="pw-bar" id="pb1"></div><div class="pw-bar" id="pb2"></div>
                                        <div class="pw-bar" id="pb3"></div><div class="pw-bar" id="pb4"></div>
                                    </div>
                                    <div class="pw-reqs">
                                        <div class="req" id="rL"><span class="req-ic">○</span> 8+ characters</div>
                                        <div class="req" id="rU"><span class="req-ic">○</span> Uppercase letter</div>
                                        <div class="req" id="rN"><span class="req-ic">○</span> Number</div>
                                        <div class="req" id="rS"><span class="req-ic">○</span> Special character</div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Confirm Password <span>*</span></label>
                                    <div class="iw"><i class="fas fa-lock ii"></i>
                                        <input type="password" id="confirmPassword" class="fc" placeholder="Confirm password">
                                        <button type="button" class="pw-tog" id="pTog2"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="pw-match" id="pwMatch"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Terms -->
                        <div class="chk-row">
                            <div class="chk-box" id="agreeChk"></div>
                            <label class="chk-lbl" id="chkLbl">
                                I agree to the <a href="#" id="termsLink">Terms of Service</a> and <a href="#" id="privLink">Privacy Policy</a>
                            </label>
                        </div>

                        <div class="form-actions">
                            <a href="users.php" class="btn-cancel">Cancel</a>
                            <button type="submit" class="btn-submit" id="subBtn">Add User</button>
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
window.addEventListener('resize', () => { if(window.innerWidth>1024){ sidebar.classList.remove('active'); overlay.classList.remove('active'); }});

// Form elements
const elFN  = document.getElementById('firstName');
const elLN  = document.getElementById('lastName');
const elRL  = document.getElementById('role');
const elEM  = document.getElementById('email');
const elUN  = document.getElementById('username');
const elPH  = document.getElementById('phoneNumber');
const elST  = document.getElementById('street');
const elCT  = document.getElementById('city');
const elSTE = document.getElementById('state');
const elCN  = document.getElementById('country');
const elZIP = document.getElementById('zipCode');
const elPW  = document.getElementById('password');
const elCPW = document.getElementById('confirmPassword');
const elCHK = document.getElementById('agreeChk');
const elBtn = document.getElementById('subBtn');
const locBan= document.getElementById('locBan');
const pwBars= [document.getElementById('pb1'),document.getElementById('pb2'),document.getElementById('pb3'),document.getElementById('pb4')];

// ── Org Search ──
const orgSrch  = document.getElementById('orgSearch');
const orgDrop  = document.getElementById('orgDrop');
const orgBody  = document.getElementById('orgBody');
const orgSpn   = document.getElementById('orgSpn');
const orgBadge = document.getElementById('orgBadge');
const orgBTx   = document.getElementById('orgBadgeTx');
const orgIdH   = document.getElementById('school_id');
const orgNmH   = document.getElementById('school_name');
const orgIdD   = document.getElementById('orgIdDis');
const orgNmD   = document.getElementById('orgNmDis');
let selOrg=null, orgTmr=null, orgIdx=-1, orgRes=[];

// ── Database se fetch ──
async function fetchOrgs(q) {
    try {
      const r = await fetch(`../organization/search_organization.php?q=${encodeURIComponent(q)}`);
        const d = await r.json();
        if (d.schools && d.schools.length) return d.schools;
        return [];
    } catch(e) {
        return [];
    }
}

function hlText(t,q) {
    if(!q) return t;
    return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),'<span class="mhl">$1</span>');
}

// ── FIX 2: org_id show karo dropdown mein ──
function renderOrgs(res,q) {
    orgRes=res; orgIdx=-1; orgBody.innerHTML='';
    if(!res.length) {
        orgBody.innerHTML=`<div class="org-empty">No organization found for "<strong>${q}</strong>"</div>`;
    } else {
        res.forEach((o,i) => {
            const d=document.createElement('div'); d.className='org-opt'; d.dataset.i=i;
            d.innerHTML=`<div class="org-opt-name">${hlText(o.name,q)}</div>
                <div class="org-opt-meta">
                    <span class="org-id-tag">${o.org_id || o.id}</span>
                    <span>📍 ${o.city}, ${o.state}</span>
                </div>`;
            d.addEventListener('mousedown', e=>{e.preventDefault(); pickOrg(o);});
            orgBody.appendChild(d);
        });
    }
    orgDrop.classList.add('open');
}

// ── FIX 3: org_id fields mein fill karo ──
function pickOrg(o) {
    selOrg = o;
    orgIdH.value  = o.id;
    orgNmH.value  = o.name;
    orgIdD.value  = o.org_id || o.id;
    orgNmD.value  = o.name;
    document.getElementById('orgIndDis').value   = o.industry_name || '';
    document.getElementById('industry_id').value = o.industry_id   || '';
    orgSrch.value = o.name;
    orgSrch.classList.add('valid');
    orgSrch.classList.remove('invalid');
    orgBTx.textContent = `✅ ${o.name} | ${o.org_id || o.id} | ${o.industry_name || 'N/A'} | ${o.city}, ${o.state}`;
    orgBadge.classList.add('show');
    document.getElementById('orgSearchError').style.display='none';
    orgDrop.classList.remove('open');
    revalidate();
}

function clearOrg() {
    selOrg=null;
    orgIdH.value=''; orgNmH.value='';
    orgIdD.value=''; orgNmD.value='';
    document.getElementById('orgIndDis').value   = '';
    document.getElementById('industry_id').value = '';
    orgSrch.value=''; orgSrch.classList.remove('valid','invalid');
    orgBadge.classList.remove('show'); orgDrop.classList.remove('open'); revalidate();
}

document.getElementById('clrOrg').addEventListener('click', clearOrg);

orgSrch.addEventListener('input', function() {
    const q=this.value.trim(); clearTimeout(orgTmr);
    if(selOrg&&q!==selOrg.name){ selOrg=null; orgIdH.value=''; orgNmH.value=''; orgIdD.value=''; orgNmD.value=''; document.getElementById('orgIndDis').value=''; document.getElementById('industry_id').value=''; orgBadge.classList.remove('show'); }
    if(q.length<2){orgDrop.classList.remove('open'); orgSpn.style.display='none'; return;}
    orgSpn.style.display='block';
    orgTmr=setTimeout(async()=>{const res=await fetchOrgs(q); orgSpn.style.display='none'; renderOrgs(res,q);},300);
});

orgSrch.addEventListener('keydown', function(e) {
    const opts=orgBody.querySelectorAll('.org-opt'); if(!opts.length) return;
    if(e.key==='ArrowDown'){e.preventDefault(); orgIdx=Math.min(orgIdx+1,opts.length-1);}
    else if(e.key==='ArrowUp'){e.preventDefault(); orgIdx=Math.max(orgIdx-1,0);}
    else if(e.key==='Enter'&&orgIdx>=0){e.preventDefault(); pickOrg(orgRes[orgIdx]); return;}
    else if(e.key==='Escape'){orgDrop.classList.remove('open'); return;}
    opts.forEach((o,i)=>o.classList.toggle('hl',i===orgIdx));
    if(orgIdx>=0) opts[orgIdx].scrollIntoView({block:'nearest'});
});

document.addEventListener('click', e=>{ if(!e.target.closest('.org-wrap')) orgDrop.classList.remove('open'); });

// ── GPS ──
function setLoc(cls,html){ locBan.className='loc-ban show '+cls; locBan.innerHTML=html; }
function fetchGPS() {
    if(!navigator.geolocation){setLoc('err','<i class="fas fa-exclamation-circle"></i>&nbsp; Geolocation not supported.');return;}
    setLoc('','<div class="loc-spin"></div>&nbsp; Fetching your location...');
    navigator.geolocation.getCurrentPosition(
        p=>{
            document.getElementById('latitude').value=p.coords.latitude;
            document.getElementById('longitude').value=p.coords.longitude;
            setLoc('ok',`<i class="fas fa-check-circle"></i>&nbsp; Location saved: ${p.coords.latitude.toFixed(5)}, ${p.coords.longitude.toFixed(5)}`);
            setTimeout(()=>locBan.classList.remove('show'),4000);
        },
        err=>{
            document.getElementById('latitude').value=''; document.getElementById('longitude').value='';
            const m={1:'Allow location in browser settings.',2:'Position unavailable.',3:'Request timed out.'};
            setLoc('err',`<i class="fas fa-exclamation-triangle"></i>&nbsp; Location denied. ${m[err.code]||''}`);
        },
        {enableHighAccuracy:true,timeout:10000,maximumAge:0}
    );
}

elRL.addEventListener('change', function() {
    setVld('role', this.value!=='');
    if(this.value==='parent') fetchGPS();
    else{document.getElementById('latitude').value='';document.getElementById('longitude').value='';locBan.classList.remove('show');}
    revalidate();
});

// ── Password ──
document.getElementById('pTog').addEventListener('click', function(){
    const t=elPW.type==='password'?'text':'password'; elPW.type=t;
    this.querySelector('i').className=t==='password'?'fas fa-eye':'fas fa-eye-slash';
});
document.getElementById('pTog2').addEventListener('click', function(){
    const t=elCPW.type==='password'?'text':'password'; elCPW.type=t;
    this.querySelector('i').className=t==='password'?'fas fa-eye':'fas fa-eye-slash';
});

function chkPW(v) {
    const r={l:v.length>=8,u:/[A-Z]/.test(v),n:/\d/.test(v),s:/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(v)};
    const upd=(id,m)=>{const e=document.getElementById(id);e.classList.toggle('met',m);e.querySelector('.req-ic').textContent=m?'✓':'○';};
    upd('rL',r.l);upd('rU',r.u);upd('rN',r.n);upd('rS',r.s);
    const cnt=Object.values(r).filter(Boolean).length;
    pwBars.forEach((b,i)=>{b.className='pw-bar';if(i<cnt)b.classList.add(cnt<=1?'w':cnt<=3?'m':'s');});
    return Object.values(r).every(Boolean);
}

function chkMatch() {
    const ok=elPW.value===elCPW.value&&elCPW.value!=='';
    const el=document.getElementById('pwMatch');
    if(!elCPW.value){el.textContent='';return false;}
    el.textContent=ok?'✓ Passwords match':'✗ Passwords do not match';
    el.style.color=ok?'#10b981':'#ef4444';
    return ok;
}

elPW.addEventListener('input',  ()=>{chkPW(elPW.value);chkMatch();revalidate();});
elCPW.addEventListener('input', ()=>{chkMatch();revalidate();});

// ── Checkbox ──
elCHK.addEventListener('click', function(e){e.stopPropagation();this.classList.toggle('on');revalidate();});
document.getElementById('chkLbl').addEventListener('click', function(e){if(e.target.tagName!=='A'){e.preventDefault();e.stopPropagation();elCHK.click();}});

// ── Field Validation ──
function setVld(id, valid) {
    const el=document.getElementById(id); const er=document.getElementById(id+'Error');
    if(!el||!el.value){el&&el.classList.remove('valid','invalid');er&&(er.style.display='none');return;}
    el.classList.toggle('valid',valid);el.classList.toggle('invalid',!valid);
    if(er)er.style.display=valid?'none':'block';
}

const fVld=[
    ['firstName',   ()=>/^[a-zA-Z\s]{2,}$/.test(elFN.value.trim())],
    ['lastName',    ()=>/^[a-zA-Z\s]{2,}$/.test(elLN.value.trim())],
    ['email',       ()=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(elEM.value.trim())],
    ['username',    ()=>/^[a-zA-Z0-9_]{3,}$/.test(elUN.value.trim())],
    ['phoneNumber', ()=>elPH.value.trim().length>0],
    ['street',      ()=>elST.value.trim().length>0],
    ['city',        ()=>elCT.value.trim().length>0],
    ['state',       ()=>elSTE.value.trim().length>0],
    ['country',     ()=>elCN.value.trim().length>0],
    ['zipCode',     ()=>elZIP.value.trim().length>0],
];
fVld.forEach(([id,fn])=>{document.getElementById(id).addEventListener('input',()=>{setVld(id,fn());revalidate();});});

// ── Revalidate ──
const LBLS=['Enter Valid First Name','Enter Valid Last Name','Select User Type',
    'Select an Organization','Enter Valid Email','Enter Valid Username',
    'Enter Phone Number','Enter Street','Enter City','Enter State',
    'Enter Country','Enter Zip Code','Complete Password','Passwords Must Match','Accept Terms'];

function revalidate() {
    const c=[
        /^[a-zA-Z\s]{2,}$/.test(elFN.value.trim()),
        /^[a-zA-Z\s]{2,}$/.test(elLN.value.trim()),
        elRL.value!=='', selOrg!==null,
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(elEM.value.trim()),
        /^[a-zA-Z0-9_]{3,}$/.test(elUN.value.trim()),
        elPH.value.trim().length>0, elST.value.trim().length>0,
        elCT.value.trim().length>0, elSTE.value.trim().length>0,
        elCN.value.trim().length>0, elZIP.value.trim().length>0,
        chkPW(elPW.value), chkMatch(), elCHK.classList.contains('on')
    ];
    const ok=c.every(Boolean); const idx=c.findIndex(x=>!x);
    elBtn.disabled=!ok;
    elBtn.textContent=(!ok&&idx>=0)?LBLS[idx]:'Add User';
}

// ── Submit ──
document.getElementById('addUserForm').addEventListener('submit', async function(e) {
    e.preventDefault(); revalidate(); if(elBtn.disabled) return;
    elBtn.classList.add('loading'); elBtn.textContent='Adding User...'; elBtn.disabled=true;

    const payload={
        firstName:   elFN.value.trim(),
        lastName:    elLN.value.trim(),
        role:        elRL.value,
        school_id:   selOrg ? selOrg.id   : null,
        school_name: selOrg ? selOrg.name : null,
        org_id:      selOrg ? (selOrg.org_id || selOrg.id) : null,
        email:       elEM.value.trim(),
        username:    elUN.value.trim(),
        phone_number:elPH.value.trim(),
        address:     [elST.value.trim(),elCT.value.trim(),elSTE.value.trim(),elCN.value.trim()].join(', ')+' - '+elZIP.value.trim(),
        street:      elST.value.trim(),
        city:        elCT.value.trim(),
        state:       elSTE.value.trim(),
        country:     elCN.value.trim(),
        zipcode:     elZIP.value.trim(),
        password:    elPW.value,
        latitude:    document.getElementById('latitude').value  || null,
        longitude:   document.getElementById('longitude').value || null,
    };

    try {
     const res=await fetch('eduSignup.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const data=await res.json();
        elBtn.classList.remove('loading'); elBtn.disabled=false;
        if(data.success){
            showMsg('success',`User <strong>${payload.firstName} ${payload.lastName}</strong> added successfully!`);
            document.getElementById('addUserForm').reset(); clearOrg();
            elCHK.classList.remove('on');
            document.getElementById('pwMatch').textContent='';
            pwBars.forEach(b=>b.className='pw-bar');
            ['rL','rU','rN','rS'].forEach(id=>{const e=document.getElementById(id);e.classList.remove('met');e.querySelector('.req-ic').textContent='○';});
            document.getElementById('latitude').value=''; document.getElementById('longitude').value='';
            locBan.classList.remove('show'); revalidate();
            setTimeout(()=>{window.location.href='users.php';},1800);
        } else {
            showMsg('error', data.message);
        }
    } catch(err) {
        elBtn.classList.remove('loading'); elBtn.disabled=false;
        showMsg('error','Network error: '+err.message);
    }
});

function showMsg(type,html){
    const c=document.getElementById('msgBox');
    c.innerHTML=`<div class="message-container"><div class="message ${type}"><i class="fas ${type==='success'?'fa-check-circle':'fa-exclamation-circle'}"></i> ${html}</div></div>`;
    setTimeout(()=>{c.innerHTML='';},5000);
    window.scrollTo({top:0,behavior:'smooth'});
}

document.getElementById('termsLink').addEventListener('click', e=>{e.preventDefault();alert('Terms of Service');});
document.getElementById('privLink').addEventListener('click',  e=>{e.preventDefault();alert('Privacy Policy');});

revalidate();
</script>
</body>
</html>