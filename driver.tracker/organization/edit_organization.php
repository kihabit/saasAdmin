<?php
session_start();

require_once '../config.php';

if (!isLoggedIn()) {
    redirect(LOGIN_PAGE);
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please login again.');
    redirect(LOGIN_PAGE);
}

$_SESSION['last_activity'] = time();

$db = Database::getInstance();
$conn = $db->getConnection();

$logged_user_id = $_SESSION['user_id'];
$logged_username = $_SESSION['username'];

if (!isset($_GET['id']) || empty($_GET['id'])) {
    setFlashMessage('error', 'Organization ID not provided.');
    redirect('organization.php');
}

$edit_school_id = intval($_GET['id']);

$organization = null;
try {
    $stmt = $conn->prepare("SELECT id, name, address, city, state, postal_code, phone, email, latitude, longitude, created_at FROM organization WHERE id = ?");
    $stmt->bind_param("i", $edit_school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $organization = $result->fetch_assoc();
    $stmt->close();

    if (!$organization) {
        setFlashMessage('error', 'Organization not found.');
        redirect('organization.php');
    }
} catch (Exception $e) {
    logAppError("Edit organization fetch error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading organization details.');
    redirect('organization.php');
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_school'])) {
    $name        = trim($_POST['name'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $state       = trim($_POST['state'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $latitude    = trim($_POST['latitude'] ?? '');
    $longitude   = trim($_POST['longitude'] ?? '');

    if (empty($name)) {
        $message = 'Organization name is required.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE organization SET name=?, address=?, city=?, state=?, postal_code=?, phone=?, email=?, latitude=?, longitude=? WHERE id=?");
            $lat = $latitude !== '' ? (float)$latitude : null;
            $lng = $longitude !== '' ? (float)$longitude : null;
            $stmt->bind_param("sssssssddi", $name, $address, $city, $state, $postal_code, $phone, $email, $lat, $lng, $edit_school_id);
            $stmt->execute();
            $stmt->close();

            logAppError("Organization updated: ID $edit_school_id by $logged_username");
            $_SESSION['message'] = "Organization '{$name}' updated successfully.";
            $_SESSION['messageType'] = 'success';
            redirect("view-organization.php?id=$edit_school_id");
        } catch (Exception $e) {
            logAppError("Edit organization update error: " . $e->getMessage());
            $message = 'Error updating organization. Please try again.';
            $messageType = 'error';
        }
    }

    // Update local $organization array to reflect entered values on error
    if ($messageType === 'error') {
        $organization['name']        = $name;
        $organization['address']     = $address;
        $organization['city']        = $city;
        $organization['state']       = $state;
        $organization['postal_code'] = $postal_code;
        $organization['phone']       = $phone;
        $organization['email']       = $email;
        $organization['latitude']    = $latitude;
        $organization['longitude']   = $longitude;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        try {
            $stmt = $conn->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            $stmt->bind_param("i", $logged_user_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            logAppError("Logout error: " . $e->getMessage());
        }
    }
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
    <title>Edit Organization - <?php echo htmlspecialchars($organization['name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            line-height: 1.6;
        }

        .app-container { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            height: 100vh;
            left: 0; top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
        }

        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
        .sidebar-logo h2 { font-size: 1.3rem; font-weight: 700; }

        .sidebar-user {
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .sidebar-user .user-avatar {
            width: 48px; height: 48px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 16px;
            margin-bottom: 0.5rem;
        }

        .sidebar-nav { padding: 1rem 0; }

        .nav-item {
            display: flex;
            padding: 0.75rem 1.5rem;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            align-items: center;
            gap: 12px;
        }

        .nav-item:hover, .nav-item.active {
            background: #f7fafc;
            color: #0000FF;
            border-left-color: #0000FF;
        }

        .nav-item i { width: 20px; text-align: center; }

        /* Main Wrapper */
        .main-wrapper { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }

        /* Header */
        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 2rem;
            position: sticky; top: 0; z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header-content { display: flex; justify-content: space-between; align-items: center; }

        .breadcrumb { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 0.9rem; }
        .breadcrumb a { color: #0000FF; text-decoration: none; }

        .header-actions { display: flex; align-items: center; gap: 1rem; }

        .btn-back {
            background: #f7fafc; color: #4a5568;
            border: 1px solid #e2e8f0;
            padding: 8px 16px; border-radius: 8px;
            text-decoration: none;
            display: flex; align-items: center; gap: 8px;
            font-weight: 500; transition: all 0.3s ease;
        }

        .btn-back:hover { background: #e2e8f0; }

        .logout-btn {
            background: #dc3545; color: white;
            border: none; padding: 8px 16px; border-radius: 8px;
            font-weight: 500; cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }

        .logout-btn:hover { background: #c82333; }

        /* Main Content */
        .main-content { padding: 2rem; }

        /* Message */
        .message-container { margin-bottom: 1.5rem; }
        .message {
            padding: 1rem 1.5rem; border-radius: 12px;
            display: flex; align-items: center; gap: 12px;
            font-weight: 500;
        }
        .message.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .message.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .form-card-header {
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .form-avatar {
            width: 100px; height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 2.5rem;
            margin: 0 auto 1rem;
            border: 4px solid rgba(255,255,255,0.3);
        }

        .form-card-header h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        .form-card-header p  { font-size: 1rem; opacity: 0.9; }

        .form-body { padding: 2rem; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }

        .form-group.full-width { grid-column: 1 / -1; }

        .form-label {
            font-size: 0.85rem;
            color: #718096;
            font-weight: 500;
            display: flex; align-items: center; gap: 8px;
        }

        .form-input {
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            color: #1a202c;
            background: #f8fafc;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #0000FF;
            background: white;
            box-shadow: 0 0 0 3px rgba(0,0,255,0.08);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .btn-cancel-form {
            background: #f7fafc; color: #4a5568;
            border: 1px solid #e2e8f0;
            padding: 10px 24px; border-radius: 10px;
            font-size: 0.95rem; font-weight: 600;
            cursor: pointer; font-family: inherit;
            text-decoration: none;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-cancel-form:hover { background: #e2e8f0; }

        .btn-save {
            background: #0000FF; color: white;
            border: none;
            padding: 10px 28px; border-radius: 10px;
            font-size: 0.95rem; font-weight: 600;
            cursor: pointer; font-family: inherit;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-save:hover { background: #0000CC; transform: translateY(-1px); }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .main-wrapper { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .main-content { padding: 1rem; }
            .form-card-header { padding: 1.5rem; }
            .form-body { padding: 1.5rem; }
            .form-actions { flex-direction: column; }
            .btn-save, .btn-cancel-form { justify-content: center; }
        }
    </style>
</head>
<body>
<div class="app-container">

    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="/schoolAdmin/driver.tracker/icon/schooladmin.jpg" alt="Logo">
                <h2>Organization Admin</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($logged_username, 0, 2)); ?>
                </div>
                <h3><?php echo htmlspecialchars($logged_username); ?></h3>
            </div>
        </div>

        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="users.php" class="nav-item">
                <i class="fas fa-users"></i> Users
            </a>
            <a href="profile.php" class="nav-item">
                <i class="fas fa-user-circle"></i> Profile
            </a>
            <a href="organization.php" class="nav-item active">
                <i class="fas fa-organization"></i> Organization
            </a>
            <a href="children.php" class="nav-item">
                <i class="fas fa-child"></i> Children
            </a>
            
             <a href="alert.php" class="nav-item">
                <i class="fas fa-child"></i> Alert
            </a>
        </div>
    </nav>

    <!-- Main Wrapper -->
    <div class="main-wrapper">

        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="breadcrumb">
                    <a href="dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="organization.php">Organizations</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="view-organization.php?id=<?php echo $edit_school_id; ?>"><?php echo htmlspecialchars($organization['name']); ?></a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Edit</span>
                </div>
                <div class="header-actions">
                    <a href="view-organization.php?id=<?php echo $edit_school_id; ?>" class="btn-back">
                        <i class="fas fa-arrow-left"></i>
                        Back to Organization
                    </a>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">

            <?php if ($message): ?>
            <div class="message-container">
                <div class="message <?php echo htmlspecialchars($messageType); ?>">
                    <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-avatar">
                        <?php echo strtoupper(substr($organization['name'], 0, 2)); ?>
                    </div>
                    <h1>Edit Organization</h1>
                    <p><?php echo htmlspecialchars($organization['name']); ?></p>
                </div>

                <div class="form-body">
                    <form method="POST">
                        <div class="form-grid">

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <i class="fas fa-organization"></i> Organization Name <span style="color:#dc2626;">*</span>
                                </label>
                                <input type="text" name="name" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['name']); ?>"
                                    placeholder="Enter organization name" required>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <i class="fas fa-map-marker-alt"></i> Address
                                </label>
                                <input type="text" name="address" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['address'] ?? ''); ?>"
                                    placeholder="Enter full address">
                            </div>
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
                                 <option value="<?= $row['id']; ?>"
                                    <?= ($current_industry_id == $row['id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($row['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-city"></i> City
                                </label>
                                <input type="text" name="city" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['city'] ?? ''); ?>"
                                    placeholder="Enter city">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-map"></i> State
                                </label>
                                <input type="text" name="state" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['state'] ?? ''); ?>"
                                    placeholder="Enter state">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-mail-bulk"></i> Postal Code
                                </label>
                                <input type="text" name="postal_code" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['postal_code'] ?? ''); ?>"
                                    placeholder="Enter postal code">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-phone"></i> Phone
                                </label>
                                <input type="text" name="phone" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['phone'] ?? ''); ?>"
                                    placeholder="Enter phone number">
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                                <input type="email" name="email" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['email'] ?? ''); ?>"
                                    placeholder="Enter email address">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-map-pin"></i> Latitude
                                </label>
                                <input type="number" step="any" name="latitude" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['latitude'] ?? ''); ?>"
                                    placeholder="e.g. 40.712776">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-map-pin"></i> Longitude
                                </label>
                                <input type="number" step="any" name="longitude" class="form-input"
                                    value="<?php echo htmlspecialchars($organization['longitude'] ?? ''); ?>"
                                    placeholder="e.g. -74.005974">
                            </div>

                        </div>

                        <div class="form-actions">
                            <a href="view-organization.php?id=<?php echo $edit_school_id; ?>" class="btn-cancel-form">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" name="update_school" class="btn-save">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>
</body>
</html>