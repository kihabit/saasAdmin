<?php
// get_driver_last_location.php

// Database connection
$host = 'localhost';
$db   = 'your_database_name';
$user = 'your_database_user';
$pass = 'your_database_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get user_id from GET parameters
if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user_id']);
    exit;
}

$user_id = $_GET['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT order_id, latitude, longitude, created_at, updated_at 
        FROM locations 
        WHERE user_id = :user_id 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $user_id]);
    $location = $stmt->fetch();

    if ($location) {
        echo json_encode([
            'success' => true,
            'data' => $location
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No location data found for this driver'
        ]);
    }
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
}
?>
