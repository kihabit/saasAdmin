<?php
ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database credentials
$host = "72.167.69.235";
$dbname = "shipping_tracker";
$user = "tracker";
$pass = '{aJjwBl{j5;(';

// Log file for debugging
$log_file = "debug_log.txt";

// Create connection
$conn = new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    $error = "Database connection failed: " . $conn->connect_error;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $error . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $error]);
    exit();
}

// Get POST data
$raw_input = file_get_contents("php://input");
//file_put_contents($log_file, date('Y-m-d H:i:s') . " - Raw Input: " . $raw_input . PHP_EOL, FILE_APPEND);

$data = json_decode($raw_input, true);

// Log decoded data
//file_put_contents($log_file, date('Y-m-d H:i:s') . " - Decoded Data: " . json_encode($data) . PHP_EOL, FILE_APPEND);

// ✅ YAHAN CHANGE HAI - orderId bhi accept karein
$driverId = isset($data['driverId']) ? $data['driverId'] : null;
$order_id = isset($data['order_id']) ? $data['order_id'] : (isset($data['orderId']) ? $data['orderId'] : null);
$latitude = isset($data['latitude']) ? $data['latitude'] : null;
$longitude = isset($data['longitude']) ? $data['longitude'] : null;
$orderStatus = isset($data['orderStatus']) ? $data['orderStatus'] : null;

// Validate required fields
if (!$driverId || !$order_id || !$latitude || !$longitude) {
    $error = "Missing required fields. Received: " . json_encode($data);
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $error . PHP_EOL, FILE_APPEND);
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => "Missing required fields",
        "received" => $data
    ]);
    exit();
}

// Sanitize inputs
$driverId = $conn->real_escape_string($driverId);
$order_id = $order_id;
$latitude = (float)$latitude; 
$longitude = (float)$longitude;
$orderStatus = $orderStatus;

// Log sanitized data
file_put_contents($log_file, date('Y-m-d H:i:s') . " - Sanitized: driverId=$driverId, order_id=$order_id, lat=$latitude, orderStatus=$orderStatus lng=$longitude" . PHP_EOL, FILE_APPEND);

// Check if record exists
$check_sql = "SELECT id FROM locations WHERE driverId = '$driverId' AND school_id = '$school_id' LIMIT 1";
file_put_contents($log_file, date('Y-m-d H:i:s') . " - Check SQL: " . $check_sql . PHP_EOL, FILE_APPEND);

$result = $conn->query($check_sql);

if ($result === FALSE) {
    $error = "Query failed: " . $conn->error;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $error . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $error]);
    $conn->close();
    exit();
}

if ($result->num_rows > 0) {
    // Update existing record
    $update_sql = "UPDATE locations 
                   SET latitude = $latitude, 
                       longitude = $longitude, 
                       orderStatus = $orderStatus,
                       updated_at = CURRENT_TIMESTAMP 
                   WHERE driverId = '$driverId' AND school_id = '$school_id'";
    
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update SQL: " . $update_sql . PHP_EOL, FILE_APPEND);
    
    if ($conn->query($update_sql) === TRUE) {
        $response = ["status" => "success", "message" => "Location updated", "action" => "update"];
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update successful" . PHP_EOL, FILE_APPEND);
        echo json_encode($response);
    } else {
        $error = "Update failed: " . $conn->error;
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $error . PHP_EOL, FILE_APPEND);
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $error]);
    }
} else {
        if (trim($order_id)==4) {
        // OrderId is ONLY numbers → Not allowed
        echo json_encode([
            "status" => false,
            "message" => "Order ID should be alphanumeric, numeric not allowed!"
        ]);
        exit;
        }
    // Insert new record
    $insert_sql = "INSERT INTO locations (driverId, order_id, latitude, longitude) 
                   VALUES ('$driverId', '$order_id', $latitude, $longitude)";
    
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Insert SQL: " . $insert_sql . PHP_EOL, FILE_APPEND);
    
    if ($conn->query($insert_sql) === TRUE) {
        $response = ["status" => "success", "message" => "Location saved", "action" => "insert", "id" => $conn->insert_id];
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Insert successful, ID: " . $conn->insert_id . PHP_EOL, FILE_APPEND);
        echo json_encode($response);
    } else {
        $error = "Insert failed: " . $conn->error;
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $error . PHP_EOL, FILE_APPEND);
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $error]);
    }
}

$conn->close();
?>