<!-- <nav class="sidebar">

    <div class="sidebar-header">
        <h2>Organization Admin</h2>
        <p><?= htmlspecialchars($_SESSION['username']) ?></p>
    </div>

    <a href="<?php echo BASE_URL;?>dashboard.php">Dashboard</a>
    <a href="<?php echo BASE_URL;?>users.php">Users</a>
    <a href="<?php echo BASE_URL;?>eduUser/users.php">Edu User</a>
    <a href="<?php echo BASE_URL;?>fncUser/users.php">Fnc User</a>
    
    <a href="<?php echo BASE_URL;?>organizations/organization.php">Organization</a>
    <a href="<?php echo BASE_URL;?>students/students.php">Students</a>
    <a href="<?php echo BASE_URL;?>alert.php">Alert</a>
    <a href="<?php echo BASE_URL;?>?logout=1">Logout</a>

</nav> -->
<style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            line-height: 1.6;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 200px;
            background: white;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo img {
            width: 36px;
            height: 36px;
            border-radius: 8px;
        }

        .sidebar-logo h2 {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-section {
            margin-bottom: 2rem;
        }

        .nav-item {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-item:hover,
        .nav-item.active {
            background: #f7fafc;
            color: #0000FF;
            border-left-color: #0000FF;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .nav-item .nav-text {
            flex: 1;
        }

        .main-wrapper {
            flex: 1;
            margin-left: 201px;
            transition: margin-left 0.3s ease;
        }

        .main-wrapper.expanded {
            margin-left: 0;
        }

        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #4a5568;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: none;
        }

        .menu-toggle:hover {
            background: #f7fafc;
            color: #0000FF;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #718096;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: #0000FF;
            text-decoration: none;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }

        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .main-content {
            padding: 1rem;
        }
</style>

<?php
// Active page detection
$currentPage     = basename($_SERVER['PHP_SELF']);
$currentDir      = basename(dirname($_SERVER['PHP_SELF']));
$currentFullPath = $_SERVER['PHP_SELF'];
$isFinUser       = (strpos($currentFullPath, 'finUser') !== false);
$isEduUser       = (strpos($currentFullPath, 'eduUser') !== false);
?>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="<?php echo BASE_URL;?>/icon/schooladmin.jpg" alt="Logo">
            <h2>Admin</h2>
        </div>
    </div>

    <div class="sidebar-nav">
        <div class="nav-section">

            <a href="<?php echo BASE_URL;?>dashboard.php" 
               class="nav-item <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="<?php echo BASE_URL;?>users.php" 
               class="nav-item <?php echo ($currentPage == 'users.php' && !$isEduUser && !$isFinUser) ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="nav-text">Users</span>
            </a>

            <a href="<?php echo BASE_URL;?>eduUser/users.php" 
               class="nav-item <?php echo $isEduUser ? 'active' : ''; ?>">
                <i class="fas fa-user-tie"></i>
                <span class="nav-text">Edu User</span>
            </a>

            <a href="<?php echo BASE_URL;?>finUser/users.php" 
               class="nav-item <?php echo $isFinUser ? 'active' : ''; ?>">
                <i class="fas fa-user-shield"></i>
                <span class="nav-text">Fin User</span>
            </a>

            <a href="<?php echo BASE_URL;?>profile.php" 
               class="nav-item <?php echo ($currentPage == 'profile.php') ? 'active' : ''; ?>">
                <i class="fas fa-user-circle"></i>
                <span class="nav-text">Profile</span>
            </a>

            <a href="<?php echo BASE_URL;?>organization/organization.php" 
               class="nav-item <?php echo ($currentPage == 'organization.php' && $currentDir == 'organization') ? 'active' : ''; ?>">
                <i class="fas fa-building"></i>
                <span class="nav-text">Organization</span>
            </a>

            <a href="<?php echo BASE_URL;?>students/students.php" 
               class="nav-item <?php echo ($currentPage == 'students.php' && $currentDir == 'students') ? 'active' : ''; ?>">
                <i class="fas fa-user-graduate"></i>
                <span class="nav-text">Students</span>
            </a>

            <a href="<?php echo BASE_URL;?>alert.php" 
               class="nav-item <?php echo ($currentPage == 'alert.php') ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span class="nav-text">Alert</span>
            </a>

        </div>
    </div>
</nav>