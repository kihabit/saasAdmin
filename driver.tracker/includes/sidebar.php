<!-- <nav class="sidebar">

    <div class="sidebar-header">
        <h2>Organization Admin</h2>
        <p><?= htmlspecialchars($_SESSION['username']) ?></p>
    </div>

    <a href="<?php echo BASE_URL;?>dashboard.php">Dashboard</a>
    <a href="<?php echo BASE_URL;?>users.php">Users</a>
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

        /* Layout Structure */
        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
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

        .sidebar-user {
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .sidebar-user .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 0.5rem;
        }

        .sidebar-user h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .sidebar-user p {
            font-size: 0.85rem;
            opacity: 0.8;
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

        /* Main Content Area */
        .main-wrapper {
            flex: 1;
            margin-left: 201px;
            transition: margin-left 0.3s ease;
        }

        .main-wrapper.expanded {
            margin-left: 0;
        }

        /* Header */
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

        /*.notification-btn {*/
        /*    position: relative;*/
        /*    background: #f7fafc;*/
        /*    border: 1px solid #e2e8f0;*/
        /*    padding: 8px;*/
        /*    border-radius: 8px;*/
        /*    color: #4a5568;*/
        /*    cursor: pointer;*/
        /*    transition: all 0.3s ease;*/
        /*}*/

        /*.notification-btn:hover {*/
        /*    background: #0000FF;*/
        /*    color: white;*/
        /*}*/

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

        /* Main Content */
        .main-content {
            padding: 1rem;
        } </style>
<nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                <img src="<?php echo BASE_URL;?>/icon/schooladmin.jpg" alt="Logo">
                    <h2>Admin</h2>
                </div>
                <!-- <div class="sidebar-user">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($username, 0, 2)); ?>
                    </div>
                    <h3><?php echo htmlspecialchars($username); ?></h3>
                    <p>ID: <?php echo htmlspecialchars($driver_id ?? 'N/A'); ?></p>
                </div> -->
            </div>

           <div class="sidebar-nav">
    <div class="nav-section">
        <a href="<?php echo BASE_URL;?>dashboard.php" class="nav-item <?php echo isActivePage('dashboard.php'); ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span class="nav-text">Dashboard</span>
        </a>
        
        <a href="<?php echo BASE_URL;?>users.php" class="nav-item <?php echo isActivePage('users.php'); ?>">
            <i class="fas fa-users"></i>
            <span class="nav-text">Users</span>
        </a>
        
        <!--<a href="completed_orders.php" class="nav-item <?php echo isActivePage('completed_orders.php'); ?>">-->
        <!--    <i class="fas fa-check-circle"></i>-->
        <!--    <span class="nav-text">Completed Orders</span>-->
        <!--</a>-->
         
        <!--<a href="progress_orders.php" class="nav-item <?php echo isActivePage('progress_orders.php'); ?>">-->
        <!--    <i class="fas fa-hourglass-half"></i>-->
        <!--    <span class="nav-text">In-Progress Orders</span>-->
        <!--</a>-->
        
        <a href="<?php echo BASE_URL;?>profile.php" class="nav-item <?php echo isActivePage('profile.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">Profile</span>
        </a>
         <a href="<?php echo BASE_URL;?>organization/organization.php" class="nav-item <?php echo isActivePage('organization/organization.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">Organization</span>
            
             <a href="<?php echo BASE_URL;?>students/students.php" class="nav-item <?php echo isActivePage('children.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">Students</span>
        </a>
        
         <a href="<?php echo BASE_URL;?>alert.php" class="nav-item <?php echo isActivePage('alert.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">Alert</span>
        </a>
        
        </a>
    </div>
</div>
</nav>