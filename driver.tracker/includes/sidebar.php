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
<nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                <img src="<?php echo BASE_URL;?>/icon/schooladmin.jpg" alt="Logo">
                    <h2>Organization Admin</h2>
                </div>
                <div class="sidebar-user">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($username, 0, 2)); ?>
                    </div>
                    <h3><?php echo htmlspecialchars($username); ?></h3>
                    <p>ID: <?php echo htmlspecialchars($driver_id ?? 'N/A'); ?></p>
                </div>
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
            
             <a href="children.php" class="nav-item <?php echo isActivePage('children.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">children</span>
        </a>
        
         <a href="<?php echo BASE_URL;?>alert.php" class="nav-item <?php echo isActivePage('alert.php'); ?>">
            <i class="fas fa-user-circle"></i>
            <span class="nav-text">Alert</span>
        </a>
        
        </a>
    </div>
</div>
</nav>