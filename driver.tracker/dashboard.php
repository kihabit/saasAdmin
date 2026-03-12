<?php
session_start();

// Include configuration file
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect(LOGIN_PAGE);
}

// Check session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please login again.');
    redirect(LOGIN_PAGE);
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Get database connection
$db = Database::getInstance();
$conn = $db->getConnection();

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$driver_id = $_SESSION['driver_id'];
$email = $_SESSION['email'];

// Initialize dashboard data
$dashboardData = [
    'total_trips' => 0,
    'total_distance' => 0,
    'total_earnings' => 0,
    'avg_rating' => 0,
    'recent_trips' => [],
    'monthly_stats' => []
];

try {
    // ✅ FIXED QUERY - Sirf unique order_ids with latest data
  $stmt = $conn->prepare("
    SELECT 
        loc.id,
        loc.driverId,
        loc.school_id,
        loc.latitude,
        loc.longitude,
        loc.speed,
        loc.updated_at,
        user_login.username,
        user_login.firstName,
        user_login.lastName,
        user_login.email
    FROM locations AS loc
    INNER JOIN (
        SELECT driverId, MAX(updated_at) AS max_updated
        FROM locations
        GROUP BY driverId
    ) AS latest_loc 
    ON loc.driverId = latest_loc.driverId
    AND loc.updated_at = latest_loc.max_updated
    LEFT JOIN user_login 
    ON user_login.user_id = loc.driverId
    ORDER BY loc.updated_at DESC
    LIMIT 20
");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $tripData = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // For demo purposes, using sample data
    $dashboardData['total_trips'] = count($tripData);
    $dashboardData['total_distance'] = 2847.5;
    $dashboardData['total_earnings'] = 15640.75;
    $dashboardData['avg_rating'] = 4.7;
    
    // Recent trips data - Now with unique order_ids only
    $dashboardData['recent_trips'] = $tripData;
    
} catch (Exception $e) {
    logAppError("Dashboard data error: " . $e->getMessage());
    $dashboardData = ['error' => 'Unable to load dashboard data'];
}

// Handle logout
if (isset($_GET['logout'])) {
    // Clear remember me cookie
    if (isset($_COOKIE['remember_login'])) {
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
        
        // Remove remember token from database
        try {
            $stmt = $conn->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            logAppError("Logout error: " . $e->getMessage());
        }
    }
    
    // Destroy session
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
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>

        .welcome-section {
            background: linear-gradient(135deg, #0000FF, #4169E1);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="1.5" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="80" r="1" fill="rgba(255,255,255,0.1)"/></svg>');
            opacity: 0.3;
        }

        .welcome-content {
            position: relative;
            z-index: 1;
        }

        .welcome-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .welcome-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Search Section - Google-style */
        .search-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: .5rem .25rem;
            margin-bottom: 2rem;
        }

        .search-container {
            width: 100%;
            max-width: 600px;
            position: relative;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 50px;
            padding: 12px 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .search-box:focus-within {
            border-color: #0000FF;
            box-shadow: 0 8px 25px rgba(0, 0, 255, 0.15);
            transform: translateY(-2px);
        }

        .search-icon {
            color: #9ca3af;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        .search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 1.1rem;
            padding: 8px 0;
            background: transparent;
            color: #1a202c;
        }

        .search-input::placeholder {
            color: #9ca3af;
            font-weight: 400;
        }

        .go-button {
            background: #0000FF;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .go-button:hover {
            background: #0000CC;
            transform: scale(1.05);
        }

        .go-button:active {
            transform: scale(0.98);
        }

        .go-button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }

        /* Auto-suggestion Dropdown */
        .suggestions-dropdown {
            position: absolute;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            margin-top: 2px;
        }

        .suggestions-dropdown.active {
            display: block;
        }

        .suggestion-item {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .suggestion-item:hover,
        .suggestion-item.highlighted {
            background: #f7fafc;
            color: #0000FF;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0000FF, #4169E1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }

        .suggestion-details {
            flex: 1;
        }

        .suggestion-name {
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 2px;
            font-size: 0.95rem;
        }

        .suggestion-id {
            font-size: 0.8rem;
            color: #718096;
        }

        .suggestion-item:hover .suggestion-name,
        .suggestion-item.highlighted .suggestion-name {
            color: #0000FF;
        }

        .no-suggestions {
            padding: 20px;
            text-align: center;
            color: #718096;
            font-style: italic;
        }

        .search-suggestions {
            margin-top: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
        }

        .suggestion-btn {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 20px;
            color: #4a5568;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .suggestion-btn:hover {
            background: #f7fafc;
            border-color: #0000FF;
            color: #0000FF;
            transform: translateY(-1px);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #0000FF, #4169E1);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 255, 0.15);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.9rem;
            color: #718096;
            font-weight: 500;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-icon.trips {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            float:right;
        }

        .stat-icon.distance {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .stat-icon.earnings {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .stat-icon.rating {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.85rem;
            color: #10b981;
            font-weight: 500;
        }

        /* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}

        .content-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-content {
            padding: 1.5rem;
        }

        /* Recent Trips Table */
        .trips-table {
            width: 100%;
            border-collapse: collapse;
        }

        .trips-table th,
        .trips-table td {
            padding: 3px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .trips-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .trips-table td {
            font-size: 0.9rem;
        }

        .trip-id {
            font-weight: 600;
            color: #0000FF;
        }

        .trip-route {
            color: #4a5568;
        }

        .trip-status {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            background: #d1fae5;
            color: #065f46;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 1rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: #0000FF;
            color: white;
            transform: translateX(4px);
        }

        .action-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: #0000FF;
        }

        .action-btn:hover .action-icon {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* Sidebar Overlay for Mobile */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .notification.success {
            background: #10b981;
        }

        .notification.error {
            background: #ef4444;
        }

        .notification.info {
            background: #3b82f6;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header {
                padding: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .search-section {
                padding: 2rem 1rem;
            }

            .search-box {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
                padding: 1rem;
                border-radius: 16px;
            }

            .go-button {
                margin-left: 0;
                justify-content: center;
            }

            .search-suggestions {
                margin-top: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .welcome-title {
                font-size: 1.5rem;
            }

            .header-actions {
                gap: 0.5rem;
            }
        }

        @media (max-width: 640px) {
            .trips-table {
                font-size: 0.8rem;
            }

            .trips-table th,
            .trips-table td {
                padding: 8px 4px;
                
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .sidebar {
                width: 260px;
            }
        }
    </style>
</head>

<body>
    <div class="app-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <!-- Sidebar -->
        

        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content Area -->
        <div class="main-wrapper" id="mainWrapper">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="header-left">
                        <button class="menu-toggle" id="menuToggle">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="breadcrumb">
                            <a href="<?php echo BASE_URL;?>dashboard.php">Home</a>
                            <i class="fas fa-chevron-right"></i>
                            <span>Dashboard</span>
                        </div>
                    </div>
                    <div class="header-actions">
                        <!--<button class="notification-btn">-->
                        <!--    <i class="fas fa-bell"></i>-->
                        <!--    <span class="notification-badge">3</span>-->
                        <!--</button>-->
                        <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Welcome Section -->
                <!--<div class="welcome-section">-->
                <!--    <div class="welcome-content">-->
                <!--        <h2 class="welcome-title">Welcome back, <?php echo htmlspecialchars($username); ?>!</h2>-->
                <!--        <p class="welcome-subtitle">Here's your driving summary for today. Keep up the great work!</p>-->
                <!--    </div>-->
                <!--</div>-->

                <!-- Search Section -->
                

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card" style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                        <div class="stat-header" style="display: flex; align-items: center; gap: 8px;">
                            <span class="stat-title">Total Trips:  <?php echo number_format($dashboardData['total_trips']); ?> </span>
                            <div class="stat-icon trips">
                                <i class="fas fa-route"></i>
                            </div>
                        </div>
                        <!--<div class="stat-value"><?php echo number_format($dashboardData['total_trips']); ?></div>-->
                        <!--<div class="stat-change">+12% from last month</div>-->
                    </div>

                    
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <!-- Recent Trips -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-clock"></i>
                                Recent Trips
                            </h3>
                        </div>
                        <div class="card-content">
                            <table class="trips-table">
                                <thead>
                                    <tr>
                                    
                                        <th>Driver Name</th>
                                      <th>Speed</th>

                                        <th>Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboardData['recent_trips'] as $trip):  
                                    if(!empty($trip)){ ?>
                                    <tr>
                                      <tr>
    
    <td>
<?= htmlspecialchars($trip['firstName'] ?? '') . ' ' . htmlspecialchars($trip['lastName'] ?? '') ?>
</td>
    <td class="trip-route"><?php echo htmlspecialchars($trip['speed']); ?> km/h</td>
<td><?php
$latitude = floatval($trip['latitude']);
$longitude = floatval($trip['longitude']);

// Check if valid coordinates
if ($latitude != 0 && $longitude != 0) {
    $googleMapsLink = "https://www.google.com/maps/search/?api=1&query=" . $latitude . "," . $longitude;
    echo '<a href="' . $googleMapsLink . '" target="_blank" style="color: #0000FF; text-decoration: none; font-weight: 500;">
            <i class="fas fa-map-marker-alt"></i> View on Maps
          </a>';
} else {
    echo '<span style="color: #9ca3af;">No location</span>';
}
?></td>
                                    </tr>
                                    <?php }?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Quick Actions -->
    <!--                <div class="content-card">-->
    <!--                    <div class="card-header">-->
    <!--                        <h3 class="card-title">-->
    <!--                            <i class="fas fa-bolt"></i>-->
    <!--                            Quick Actions-->
    <!--                        </h3>-->
    <!--                    </div>-->
    <!--                    <div class="card-content">-->
    <!--                        <div class="quick-actions">-->
    <!--                            <a href="#" class="action-btn">-->
    <!--                                <div class="action-icon">-->
    <!--                                    <i class="fas fa-plus"></i>-->
    <!--                                </div>-->
    <!--                                <div>-->
    <!--                                    <div>New Trip</div>-->
    <!--                                    <small>Start a new journey.<br>    Comming soon</small>-->
    <!--                                </div>-->
    <!--                            </a>-->
                                
    <!--                            <a href="#" class="action-btn">-->
    <!--                                <div class="action-icon">-->
    <!--                                    <i class="fas fa-history"></i>-->
    <!--                                </div>-->
    <!--                                <div>-->
    <!--                                    <div>Trip History</div>-->
    <!--                                    <small>View all trips. <br>   Comming soon</small>-->
    <!--                                </div>-->
    <!--                            </a>-->
                                
    <!--                            <a href="#" class="action-btn">-->
    <!--                                <div class="action-icon">-->
    <!--                                    <i class="fas fa-chart-line"></i>-->
    <!--                                </div>-->
    <!--                                <div>-->
    <!--                                    <div>Earnings Report</div>-->
    <!--                                    <small>View earnings details. <br>   Comming soon</small>-->
    <!--                                </div>-->
    <!--                            </a>-->
                                
    <!--                            <a href="#" class="action-btn">-->
    <!--                                <div class="action-icon">-->
    <!--                                    <i class="fas fa-user-cog"></i>-->
    <!--                                </div>-->
    <!--                                <div>-->
    <!--                                    <div>Profile Settings</div>-->
    <!--                                    <small>Update your profile. <br>   Comming soon</small>-->
    <!--                                </div>-->
    <!--                            </a>-->
    <!--                        </div>-->
    <!--                    </div>-->
    <!--                </div>-->
    <!--            </div>-->
    <!--        </main>-->
    <!--    </div>-->
    <!--</div>-->

    <script>
        // Auto-suggestion functionality
        let selectedDriverId = null;
        let currentHighlight = -1;
        let suggestionTimeout = null;

        const searchInput = document.getElementById('searchInput');
        const suggestionsDropdown = document.getElementById('suggestionsDropdown');

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            
            // Reset selected driver when typing
            selectedDriverId = null;
            
            // Clear previous timeout
            if (suggestionTimeout) {
                clearTimeout(suggestionTimeout);
            }
            
            if (query.length >= 2) {
                // Debounce the search to avoid too many requests
                suggestionTimeout = setTimeout(() => {
                    fetchSuggestions(query);
                }, 300);
            } else {
                hideSuggestions();
            }
        });

        searchInput.addEventListener('keydown', function(e) {
            const items = suggestionsDropdown.querySelectorAll('.suggestion-item');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentHighlight = Math.min(currentHighlight + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentHighlight = Math.max(currentHighlight - 1, -1);
                updateHighlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentHighlight >= 0 && items[currentHighlight]) {
                    selectSuggestion(items[currentHighlight]);
                } else if (selectedDriverId) {
                    openDriverLocationInMaps(selectedDriverId, this.value);
                } else {
                    handleSearch(this.value);
                }
            } else if (e.key === 'Escape') {
                hideSuggestions();
            }
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                hideSuggestions();
            }
        });

        function fetchSuggestions(query) {
            // Create FormData for the AJAX request
            const formData = new FormData();
            formData.append('action', 'search_drivers');
            formData.append('query', query);

            fetch('search_suggestions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('API Response:', data);
                if (data.success && data.drivers) {
                    displaySuggestions(data.drivers);
                } else {
                    console.error('Error fetching suggestions:', data.error);
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                    }
                    hideSuggestions();
                }
            })
            .catch(error => {
                console.error('Network error:', error);
                hideSuggestions();
            });
        }

        function displaySuggestions(drivers) {
            if (drivers.length === 0) {
                suggestionsDropdown.innerHTML = '<div class="no-suggestions">No drivers found</div>';
                suggestionsDropdown.classList.add('active');
                return;
            }

            let html = '';
            drivers.forEach((driver, index) => {
                // Handle both firstName/lastName and username
                let firstName = driver.firstName || '';
                let lastName = driver.lastName || '';
                let fullName = '';
                
                if (firstName && lastName) {
                    fullName = `${firstName} ${lastName}`;
                } else if (firstName) {
                    fullName = firstName;
                } else if (driver.username) {
                    fullName = driver.username;
                } else {
                    fullName = 'Unknown Driver';
                }
                
                // Get initials for avatar
                let initials = '';
                if (firstName && lastName) {
                    initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();
                } else if (firstName) {
                    initials = firstName.substring(0, 2).toUpperCase();
                } else if (driver.username) {
                    initials = driver.username.substring(0, 2).toUpperCase();
                } else {
                    initials = 'DR';
                }
                
                html += `
                    <div class="suggestion-item" data-driver-id="${driver.driverId}" data-full-name="${fullName}" data-index="${index}">
                        <div class="suggestion-avatar">${initials}</div>
                        <div class="suggestion-details">
                            <div class="suggestion-name">${fullName}</div>
                            <div class="suggestion-id">ID: ${driver.driverId}</div>
                        </div>
                    </div>
                `;
            });

            suggestionsDropdown.innerHTML = html;
            suggestionsDropdown.classList.add('active');
            currentHighlight = -1;

            // Add click event listeners to suggestion items
            suggestionsDropdown.querySelectorAll('.suggestion-item').forEach(item => {
                item.addEventListener('click', function() {
                    selectSuggestion(this);
                });
            });
        }

        function updateHighlight(items) {
            items.forEach((item, index) => {
                item.classList.toggle('highlighted', index === currentHighlight);
            });
        }

        function selectSuggestion(item) {
            const driverId = item.getAttribute('data-driver-id');
            const fullName = item.getAttribute('data-full-name');
            
            selectedDriverId = driverId;
            searchInput.value = fullName;
            hideSuggestions();
            
            // Show confirmation message
            showNotification(`Driver selected: ${fullName} (ID: ${driverId})`, 'info');
        }

        function hideSuggestions() {
            suggestionsDropdown.classList.remove('active');
            currentHighlight = -1;
        }

        function openDriverLocationInMaps(driverId, driverName) {
            // Show loading state
            const goButton = document.querySelector('.go-button');
            const originalText = goButton.innerHTML;
            goButton.innerHTML = '<div class="loading"></div> Loading...';
            goButton.disabled = true;

            // Fetch driver's location
            const formData = new FormData();
            formData.append('action', 'get_driver_location');
            formData.append('driverId', driverId);

            fetch('search_suggestions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Reset button
                goButton.innerHTML = originalText;
                goButton.disabled = false;

                if (data.success && data.location) {
                    const { latitude, longitude, address, timestamp } = data.location;
                    
                    // Show success message
                    showNotification(`Opening location for ${driverName} in Google Maps...`, 'success');
                    
                    // Open Google Maps in a new tab with marker
                    const mapsUrl = `https://www.google.com/maps?q=${latitude},${longitude}&ll=${latitude},${longitude}&z=15`;
                    window.open(mapsUrl, '_blank');
                    
                    // Optional: Show location details in console
                    console.log('Driver Location:', {
                        driverId,
                        driverName,
                        latitude,
                        longitude,
                        address,
                        timestamp
                    });
                } else {
                    showNotification(data.error || 'Location data not available for this driver', 'error');
                }
            })
            .catch(error => {
                // Reset button
                goButton.innerHTML = originalText;
                goButton.disabled = false;
                
                console.error('Error fetching location:', error);
                showNotification('Failed to fetch driver location. Please try again.', 'error');
            });
        }

        // Sidebar toggle functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainWrapper = document.getElementById('mainWrapper');

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });

        // Close sidebar when clicking on nav items (mobile)
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 1024) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            }
        });

        // Search form submission
        // document.getElementById('searchForm').addEventListener('submit', function(e) {
        //     e.preventDefault();
        //     const searchTerm = document.getElementById('searchInput').value.trim();
            
        //     if (searchTerm) {
        //         if (selectedDriverId) {
        //             // If a driver is selected, open their location in Google Maps
        //             openDriverLocationInMaps(selectedDriverId, searchTerm);
        //         } else {
        //             // Handle regular search
        //             handleSearch(searchTerm);
        //         }
        //     }
        // });

        function searchFor(term) {
            selectedDriverId = null; // Reset selected driver
            document.getElementById('searchInput').value = term;
            handleSearch(term);
        }

        function handleSearch(searchTerm) {
            // Show loading state
            const goButton = document.querySelector('.go-button');
            const originalText = goButton.innerHTML;
            goButton.innerHTML = '<div class="loading"></div> Searching...';
            goButton.disabled = true;

            // Simulate search processing
            setTimeout(() => {
                // Reset button
                goButton.innerHTML = originalText;
                goButton.disabled = false;

                // Handle different search terms
                const lowerTerm = searchTerm.toLowerCase();
                
                if (lowerTerm.includes('trip')) {
                    showNotification(`Showing results for: ${searchTerm}`, 'success');
                } else if (lowerTerm.includes('earning')) {
                    showNotification(`Showing earnings data for: ${searchTerm}`, 'success');
                } else if (lowerTerm.includes('setting')) {
                    showNotification(`Opening settings...`, 'success');
                } else {
                    showNotification(`Searching for: ${searchTerm}`, 'info');
                }
            }, 1000);
        }

        function showNotification(message, type) {
            // Remove any existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notif => notif.remove());

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 4000);
        }
    </script>
<script>
// (function(){
//   const trySoftReload = () => location.reload();
//   const forceReload = () => window.location.href = window.location.pathname + '?_cb=' + Date.now();

//   setTimeout(() => {
//     try {
//       trySoftReload();
//       // after short delay, if still same URL, force-bust
//       setTimeout(() => {
//         if (performance && performance.getEntriesByType) {
//           // best-effort: if page didn't unload within 1s, force reload
//           forceReload();
//         } else {
//           forceReload();
//         }
//       }, 1000);
//     } catch (e) {
//       forceReload();
//     }
//   }, 5000);
// })();
</script>

</body>
</html>