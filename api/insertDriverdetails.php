<?php
// insert_or_update_location.php

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

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['user_id'], $data['order_id'], $data['latitude'], $data['longitude'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$user_id = $data['user_id'];
$order_id = $data['order_id'];
$latitude = $data['latitude'];
$longitude = $data['longitude'];

try {
    // Check if a record exists
    $checkStmt = $pdo->prepare("SELECT id FROM locations WHERE user_id = :user_id AND order_id = :order_id LIMIT 1");
    $checkStmt->execute([
        ':user_id' => $user_id,
        ':order_id' => $order_id
    ]);

    if ($checkStmt->rowCount() > 0) {
        // Update existing record
        $updateStmt = $pdo->prepare("UPDATE locations SET latitude = :latitude, longitude = :longitude, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND order_id = :order_id");
        $updateStmt->execute([
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':user_id' => $user_id,
            ':order_id' => $order_id
        ]);
        echo json_encode(['message' => 'Location updated successfully']);
    } else {
        // Insert new record
        $insertStmt = $pdo->prepare("INSERT INTO locations (user_id, order_id, latitude, longitude) VALUES (:user_id, :order_id, :latitude, :longitude)");
        $insertStmt->execute([
            ':user_id' => $user_id,
            ':order_id' => $order_id,
            ':latitude' => $latitude,
            ':longitude' => $longitude
        ]);
        echo json_encode(['message' => 'Location inserted successfully']);
    }
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
