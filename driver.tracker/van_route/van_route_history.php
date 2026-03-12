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

$db   = Database::getInstance();
$conn = $db->getConnection();

$user_id   = $_SESSION['user_id']   ?? 0;
$username  = $_SESSION['username']  ?? '';
$driver_id = $_SESSION['driver_id'] ?? null;

// ── Filters ───────────────────────────────────────────────────────────────────
$selected_driver = isset($_GET['driver_id']) ? trim($_GET['driver_id']) : '';
$selected_school = isset($_GET['school_id']) ? trim($_GET['school_id']) : '';
$selected_date   = isset($_GET['date'])       ? trim($_GET['date'])       : '';
$days_back       = isset($_GET['days'])       ? max(1, min(7, intval($_GET['days']))) : 2;

// ── Drivers dropdown: user_login primary, fnc/edu fallback ───────────────────
$drivers = [];
$driverQuery = "
    SELECT DISTINCT vrh.driver_id,
        COALESCE(
            CONCAT(ul.firstName, ' ', ul.lastName),
            CONCAT(fu.firstName, ' ', fu.lastName),
            CONCAT(eu.firstName, ' ', eu.lastName),
            vrh.driver_id
        ) AS driver_name,
        COALESCE(fu.school_name, eu.school_name, '') AS org_name,
        COALESCE(fu.school_id,   eu.school_id,   0)  AS user_school_id
    FROM van_route_history vrh
    LEFT JOIN user_login ul ON ul.driverId  = vrh.driver_id
    LEFT JOIN fnc_user   fu ON fu.driverId  = vrh.driver_id
    LEFT JOIN edu_user   eu ON eu.driverId  = vrh.driver_id
    ORDER BY driver_name ASC
";
$driverRes = $conn->query($driverQuery);
if (!$driverRes) {
    error_log("Driver query error: " . $conn->error);
}
while ($row = $driverRes->fetch_assoc()) {
    $drivers[] = $row;
}

// ── Organizations dropdown ────────────────────────────────────────────────────
$schools = [];
$seenSchools = [];
foreach ($drivers as $d) {
    $key  = $d['user_school_id'];
    $name = $d['org_name'];
    if ($key && $key != 0 && !empty($name) && !in_array($key, $seenSchools)) {
        $seenSchools[] = $key;
        $schools[] = ['school_id' => $key, 'school_name' => $name];
    }
}

// ── Build main route query ────────────────────────────────────────────────────
$whereConditions = ["vrh.recorded_at >= NOW() - INTERVAL ? DAY"];
$bindTypes  = "i";
$bindValues = [$days_back];

if ($selected_driver !== '') {
    $whereConditions[] = "vrh.driver_id = ?";
    $bindTypes  .= "s";
    $bindValues[] = $selected_driver;
}
if ($selected_school !== '') {
    $whereConditions[] = "(fu.school_id = ? OR eu.school_id = ?)";
    $bindTypes  .= "ii";
    $bindValues[] = intval($selected_school);
    $bindValues[] = intval($selected_school);
}
if ($selected_date !== '') {
    $whereConditions[] = "DATE(vrh.recorded_at) = ?";
    $bindTypes  .= "s";
    $bindValues[] = $selected_date;
}

$whereSQL = implode(" AND ", $whereConditions);

$routePoints = [];
$sql = "
    SELECT
        vrh.id, vrh.driver_id, vrh.school_id,
        vrh.latitude, vrh.longitude, vrh.speed, vrh.sos, vrh.recorded_at,
        COALESCE(
            CONCAT(ul.firstName, ' ', ul.lastName),
            CONCAT(fu.firstName, ' ', fu.lastName),
            CONCAT(eu.firstName, ' ', eu.lastName),
            vrh.driver_id
        ) AS driver_name,
        COALESCE(fu.school_name, eu.school_name, vrh.school_id) AS school_name,
        COALESCE(fu.van_number,  eu.van_number,  '') AS van_number
    FROM van_route_history vrh
    LEFT JOIN user_login ul ON ul.driverId  = vrh.driver_id
    LEFT JOIN fnc_user   fu ON fu.driverId  = vrh.driver_id
    LEFT JOIN edu_user   eu ON eu.driverId  = vrh.driver_id
    WHERE $whereSQL
    ORDER BY vrh.driver_id, vrh.recorded_at ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($bindTypes, ...$bindValues);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $routePoints[] = $row;
}
$stmt->close();

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalPoints  = count($routePoints);
$sosCount     = count(array_filter($routePoints, fn($r) => $r['sos'] == 1));
$stoppedCount = count(array_filter($routePoints, fn($r) => floatval($r['speed']) == 0));
$maxSpeed     = $totalPoints ? max(array_column($routePoints, 'speed')) : 0;

// Group by driver for map — 
$byDriver = [];
foreach ($routePoints as $pt) {
    $byDriver[$pt['driver_id']][] = $pt;
}
$routeJson = json_encode($byDriver);

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Van Route History - <?php echo APP_NAME; ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:#f8fafc;color:#1a202c;line-height:1.6;}

    /* ── App layout ── */
    .app-container{display:flex;min-height:100vh;}
    .sidebar{width:280px;background:white;border-right:1px solid #e2e8f0;position:fixed;height:100vh;left:0;top:0;z-index:1000;overflow-y:auto;transition:transform 0.3s ease;}
    .sidebar.collapsed{transform:translateX(-100%);}
    .sidebar-header{padding:1.5rem;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#0000FF,#4169E1);color:white;}
    .sidebar-logo{display:flex;align-items:center;gap:12px;}
    .sidebar-logo img{width:36px;height:36px;border-radius:8px;}
    .sidebar-logo h2{font-size:1.3rem;font-weight:700;}
    .sidebar-nav{padding:1rem 0;}
    .nav-section{margin-bottom:2rem;}
    .nav-item{display:flex;padding:0.75rem 1.5rem;color:#4a5568;text-decoration:none;transition:all 0.3s ease;border-left:3px solid transparent;align-items:center;gap:12px;}
    .nav-item:hover,.nav-item.active{background:#f7fafc;color:#0000FF;border-left-color:#0000FF;}
    .nav-item i{width:20px;text-align:center;font-size:1.1rem;}
    .nav-item .nav-text{flex:1;}
    .main-wrapper{flex:1;margin-left:280px;transition:margin-left 0.3s ease;font-size:13px;}
    .main-wrapper.expanded{margin-left:0;}

    /* ── Header ── */
    .header{background:white;border-bottom:1px solid #e2e8f0;padding:.75rem 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
    .header-content{display:flex;justify-content:space-between;align-items:center;}
    .header-left{display:flex;align-items:center;gap:.75rem;}
    .menu-toggle{background:none;border:none;font-size:1.1rem;color:#4a5568;cursor:pointer;padding:7px;border-radius:8px;transition:all 0.3s ease;display:none;}
    .menu-toggle:hover{background:#f7fafc;color:#0000FF;}
    .breadcrumb{display:flex;align-items:center;gap:6px;color:#718096;font-size:.78rem;}
    .breadcrumb a{color:#0000FF;text-decoration:none;}
    .header-actions{display:flex;align-items:center;gap:.75rem;}
    .logout-btn{background:#dc3545;color:white;border:none;padding:6px 13px;border-radius:7px;font-weight:500;font-size:.78rem;cursor:pointer;transition:all 0.3s ease;text-decoration:none;display:flex;align-items:center;gap:6px;}
    .logout-btn:hover{background:#c82333;transform:translateY(-1px);}

    /* ── Main content ── */
    .main-content{padding:1.5rem;}
    .page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;}
    .page-title h1{font-size:1.5rem;font-weight:700;color:#1a202c;margin-bottom:.3rem;}
    .page-title p{color:#718096;font-size:.82rem;}

    /* ── Stats ── */
    .stats-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1.1rem;margin-bottom:1.5rem;}
    .stat-item{background:white;padding:1.1rem;border-radius:10px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:.75rem;transition:all 0.3s ease;}
    .stat-item:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.1);}
    .stat-item i{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;background:linear-gradient(135deg,#0000FF,#4169E1);color:white;flex-shrink:0;}
    .stat-item.red   i{background:linear-gradient(135deg,#ef4444,#f87171);}
    .stat-item.amber i{background:linear-gradient(135deg,#f59e0b,#fbbf24);}
    .stat-item.green i{background:linear-gradient(135deg,#10b981,#34d399);}
    .stat-item h4{font-size:1.2rem;font-weight:700;color:#1a202c;margin-bottom:2px;}
    .stat-item p{color:#718096;font-size:.75rem;margin:0;}

    /* ── Filter card ── */
    .filter-card{background:white;border:1px solid #e2e8f0;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;}
    .filter-row{display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;}
    .filter-group{display:flex;flex-direction:column;gap:.3rem;}
    .filter-group label{font-size:.72rem;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;}
    .filter-group select,
    .filter-group input[type="date"]{background:white;border:1px solid #e2e8f0;border-radius:8px;color:#1a202c;padding:7px 10px;font-family:'Inter',sans-serif;font-size:.82rem;cursor:pointer;outline:none;transition:border-color .2s;min-width:150px;}
    .filter-group select:focus,
    .filter-group input[type="date"]:focus{border-color:#0000FF;box-shadow:0 0 0 3px rgba(0,0,255,.1);}
    .btn-primary{background:#0000FF;color:white;border:none;padding:8px 16px;border-radius:8px;font-weight:600;font-size:.82rem;cursor:pointer;transition:all 0.3s ease;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
    .btn-primary:hover{background:#0000CC;transform:translateY(-1px);}
    .btn-secondary{background:#f3f4f6;color:#4a5568;border:1px solid #e2e8f0;padding:8px 14px;border-radius:8px;font-weight:500;font-size:.82rem;cursor:pointer;transition:all 0.3s ease;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
    .btn-secondary:hover{background:#e5e7eb;color:#1a202c;}

    /* ── Content grid ── */
    .content-grid{display:grid;grid-template-columns:1fr 400px;gap:1.25rem;}
    .content-card{background:white;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;}
    .card-header{padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;}
    .card-title{font-size:.95rem;font-weight:600;color:#1a202c;display:flex;align-items:center;gap:7px;}
    .card-title i{color:#0000FF;}
    .record-count{background:#0000FF;color:white;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;}

    /* Map */
    #map{height:500px;width:100%;}
    .map-legend{display:flex;gap:1rem;flex-wrap:wrap;}
    .legend-item{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#4a5568;font-weight:500;}
    .legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}

    /* Table */
    .table-scroll{overflow-y:auto;max-height:500px;}
    .route-table{width:100%;border-collapse:collapse;}
    .route-table th,.route-table td{padding:9px 12px;text-align:left;border-bottom:1px solid #e2e8f0;}
    .route-table th{background:#f8fafc;font-weight:600;color:#4a5568;font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;position:sticky;top:0;z-index:1;}
    .route-table td{font-size:.8rem;}
    .route-table tbody tr:hover{background:#f8fafc;cursor:pointer;}
    .route-table tbody tr.sos-row{background:#fff5f5;}
    .route-table tbody tr.sos-row:hover{background:#fee2e2;}
    .driver-badge{font-weight:600;color:#0000FF;font-size:.8rem;}
    .speed-pill{padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-block;}
    .speed-stopped{background:#f1f5f9;color:#64748b;}
    .speed-slow{background:#d1fae5;color:#065f46;}
    .speed-medium{background:#fef3c7;color:#92400e;}
    .speed-fast{background:#fee2e2;color:#991b1b;}
    .sos-badge{background:#fee2e2;color:#dc2626;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
    .date-text{color:#4a5568;font-size:.75rem;white-space:nowrap;}

    /* Empty state */
    .empty-state{text-align:center;padding:3rem 2rem;color:#718096;}
    .empty-state i{font-size:3rem;margin-bottom:.75rem;opacity:.5;}
    .empty-state h3{font-size:1rem;margin-bottom:.4rem;color:#4a5568;}
    .empty-state p{font-size:.82rem;}

    /* Sidebar overlay */
    .sidebar-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999;display:none;}
    .sidebar-overlay.active{display:block;}

    /* Responsive */
    @media(max-width:1200px){.content-grid{grid-template-columns:1fr;}}
    @media(max-width:1024px){
        .sidebar{transform:translateX(-100%);}
        .sidebar.active{transform:translateX(0);}
        .main-wrapper{margin-left:0;}
        .menu-toggle{display:block;}
    }
    @media(max-width:768px){
        .header{padding:.75rem 1rem;}
        .main-content{padding:.75rem;}
        .stats-summary{grid-template-columns:1fr 1fr;}
        .filter-row{flex-direction:column;align-items:stretch;}
    }
    @media(max-width:640px){
        .sidebar{width:260px;}
        .stats-summary{grid-template-columns:1fr;}
    }
</style>
</head>
<body>
<div class="app-container">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-wrapper" id="mainWrapper">

        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                    <div class="breadcrumb">
                        <a href="<?php echo BASE_URL; ?>dashboard.php">Home</a>
                        <i class="fas fa-chevron-right"></i>
                        <span>Van Route History</span>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt"></i>Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">

            <div class="page-header">
                <div class="page-title">
                    <h1>Van Route History</h1>
                    <p>Track van routes, stops and SOS alerts</p>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-summary">
                <div class="stat-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <div><h4><?php echo number_format($totalPoints); ?></h4><p>Total Points</p></div>
                </div>
                <div class="stat-item red">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><h4><?php echo number_format($sosCount); ?></h4><p>SOS Alerts</p></div>
                </div>
                <div class="stat-item amber">
                    <i class="fas fa-parking"></i>
                    <div><h4><?php echo number_format($stoppedCount); ?></h4><p>Stopped Points</p></div>
                </div>
                <div class="stat-item green">
                    <i class="fas fa-tachometer-alt"></i>
                    <div><h4><?php echo number_format($maxSpeed, 1); ?></h4><p>Max Speed (km/h)</p></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Time Range</label>
                            <select name="days">
                                <option value="1" <?php echo $days_back==1?'selected':''; ?>>Last 1 Day</option>
                                <option value="2" <?php echo $days_back==2?'selected':''; ?>>Last 2 Days</option>
                                <option value="3" <?php echo $days_back==3?'selected':''; ?>>Last 3 Days</option>
                                <option value="7" <?php echo $days_back==7?'selected':''; ?>>Last 7 Days</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Driver</label>
                            <select name="driver_id">
                                <option value="">All Drivers</option>
                                <?php foreach ($drivers as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['driver_id']); ?>"
                                    <?php echo $selected_driver == $d['driver_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['driver_name']); ?>
                                    (<?php echo htmlspecialchars($d['driver_id']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Organization</label>
                            <select name="school_id">
                                <option value="">All Organizations</option>
                                <?php foreach ($schools as $s): ?>
                                <option value="<?php echo htmlspecialchars($s['school_id']); ?>"
                                    <?php echo $selected_school == $s['school_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['school_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Specific Date</label>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="van_route_history.php" class="btn-secondary"><i class="fas fa-times"></i> Reset</a>
                    </div>
                </form>
            </div>

            <!-- Map + Table -->
            <div class="content-grid">

                <!-- Map Card -->
                <div class="content-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-map"></i> Route Map</h3>
                        <div class="map-legend">
                            <div class="legend-item"><div class="legend-dot" style="background:#0000FF"></div>Moving</div>
                            <div class="legend-item"><div class="legend-dot" style="background:#f59e0b"></div>Stopped</div>
                            <div class="legend-item"><div class="legend-dot" style="background:#ef4444"></div>SOS</div>
                            <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div>Start</div>
                        </div>
                    </div>
                    <div id="map"></div>
                </div>

                <!-- Table Card -->
                <div class="content-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list"></i> Route Log</h3>
                        <span class="record-count"><?php echo $totalPoints; ?> records</span>
                    </div>
                    <div class="table-scroll">
                        <?php if ($totalPoints > 0): ?>
                        <table class="route-table">
                            <thead>
                                <tr>
                                    <th>Driver</th>
                                    <th>Organization</th>
                                    <th>Van No.</th>
                                    <th>Speed</th>
                                    <th>SOS</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($routePoints as $pt): ?>
                            <?php
                                $spd = floatval($pt['speed']);
                                if ($spd == 0)     { $sc = 'speed-stopped'; $sl = 'Stopped'; }
                                elseif ($spd < 20) { $sc = 'speed-slow';    $sl = $spd.' km/h'; }
                                elseif ($spd < 50) { $sc = 'speed-medium';  $sl = $spd.' km/h'; }
                                else               { $sc = 'speed-fast';    $sl = $spd.' km/h'; }
                            ?>
                            <tr class="<?php echo $pt['sos']?'sos-row':''; ?>"
                                onclick="panToPoint(<?php echo $pt['latitude']; ?>, <?php echo $pt['longitude']; ?>)"
                                title="Click to zoom on map">
                                <td>
                                    <span class="driver-badge"><?php echo htmlspecialchars($pt['driver_name']); ?></span>
                                    <div style="font-size:.68rem;color:#9ca3af;margin-top:1px"><?php echo htmlspecialchars($pt['driver_id']); ?></div>
                                </td>
                                <td style="font-size:.75rem;color:#4a5568"><?php echo htmlspecialchars($pt['school_name']); ?></td>
                                <td style="font-size:.75rem;color:#4a5568"><?php echo htmlspecialchars($pt['van_number']) ?: '—'; ?></td>
                                <td><span class="speed-pill <?php echo $sc; ?>"><?php echo $sl; ?></span></td>
                                <td>
                                    <?php if ($pt['sos']): ?>
                                        <span class="sos-badge"><i class="fas fa-exclamation"></i> SOS</span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="date-text"><?php echo date('M j, H:i', strtotime($pt['recorded_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-route"></i>
                            <h3>No route data found</h3>
                            <p>Try changing the filters above.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /content-grid -->
        </main>
    </div><!-- /main-wrapper -->
</div><!-- /app-container -->

<!-- Google Maps — Replace YOUR_GOOGLE_MAPS_API_KEY with your actual key -->
<script>
const routeByDriver = <?php echo $routeJson; ?>;
const COLOURS = ['#0000FF','#ef4444','#10b981','#f59e0b','#a855f7','#ec4899','#14b8a6','#f97316'];
let map;

function initMap() {
    const drivers = Object.keys(routeByDriver);
    let centre = { lat: 29.7788, lng: 77.2710 };
    if (drivers.length && routeByDriver[drivers[0]].length) {
        const f = routeByDriver[drivers[0]][0];
        centre = { lat: parseFloat(f.latitude), lng: parseFloat(f.longitude) };
    }

    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 14,
        center: centre,
        mapTypeId: 'roadmap'
    });

    drivers.forEach((driverId, idx) => {
        const pts    = routeByDriver[driverId];
        const colour = COLOURS[idx % COLOURS.length];
        const path   = pts.map(p => ({ lat: parseFloat(p.latitude), lng: parseFloat(p.longitude) }));

        new google.maps.Polyline({ path, map, strokeColor: colour, strokeOpacity: 0.8, strokeWeight: 3 });

        pts.forEach((pt, i) => {
            const isSOS     = pt.sos == 1;
            const isStopped = parseFloat(pt.speed) === 0;
            const isFirst   = i === 0;

            let fillColor = colour;
            let label = '';
            let zIndex = 10;
            if (isSOS)        { fillColor = '#ef4444'; label = '!'; zIndex = 30; }
            else if (isFirst) { fillColor = '#10b981'; label = 'S'; zIndex = 25; }
            else if (isStopped){ fillColor = '#f59e0b'; zIndex = 15; }

            const r   = (isSOS || isFirst) ? 9 : 6;
            const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22">
                <circle cx="11" cy="11" r="${r}" fill="${fillColor}" stroke="white" stroke-width="2"/>
                <text x="11" y="15" text-anchor="middle" font-size="8" font-family="Arial" fill="white" font-weight="bold">${label}</text>
            </svg>`;

            const marker = new google.maps.Marker({
                position: { lat: parseFloat(pt.latitude), lng: parseFloat(pt.longitude) },
                map, zIndex,
                icon: { url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg), scaledSize: new google.maps.Size(22, 22) },
                title: `${driverId} | ${pt.speed} km/h | ${pt.recorded_at}`
            });

            const info = new google.maps.InfoWindow({
                content: `<div style="font-family:'Inter',sans-serif;font-size:12px;line-height:1.8;min-width:190px">
                    <b style="font-size:13px;color:#0000FF">${pt.driver_name || driverId}</b>
                    <span style="color:#9ca3af;font-size:11px"> (${driverId})</span><br>
                    🏢 ${pt.school_name || ''}<br>
                    🚐 Van: ${pt.van_number || '—'}<br>
                    🚗 Speed: <b>${pt.speed} km/h</b><br>
                    🕐 ${pt.recorded_at}
                    ${pt.sos==1 ? '<br><span style="color:#dc2626;font-weight:600">🚨 SOS ALERT</span>' : ''}
                </div>`
            });
            marker.addListener('click', () => info.open(map, marker));
        });
    });
}

function panToPoint(lat, lng) {
    if (!map) return;
    map.panTo({ lat: parseFloat(lat), lng: parseFloat(lng) });
    map.setZoom(16);
}
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initMap"></script>

<!-- Sidebar toggle -->
<script>
const menuToggle = document.getElementById('menuToggle');
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('sidebarOverlay');

menuToggle.addEventListener('click', function() {
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
});
overlay.addEventListener('click', function() {
    sidebar.classList.remove('active');
    overlay.classList.remove('active');
});
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', function() {
        if (window.innerWidth <= 1024) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    });
});
window.addEventListener('resize', function() {
    if (window.innerWidth > 1024) {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    }
});
</script>
</body>
</html>