<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/home/n1epxxcubz1o/public_html/api/mi_exception_log.txt');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// === LOG FUNCTION ===
function logError($message) {
    $logFile = '/home/n1epxxcubz1o/public_html/api/mi_exception_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// === DB CONFIG ===
$host = "72.167.69.235";
$dbname = "shipping_tracker";
$user = "tracker";
$pass = "{aJjwBl{j5;(";

// === DATABASE CONNECT ===
try {
    $conn = new mysqli($host, $user, $pass, $dbname);
    if ($conn->connect_error) {
        logError("DB Connection Failed: " . $conn->connect_error);
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database connection failed"]);
        exit;
    }
    $conn->set_charset("utf8mb4");
    
    // ✅ SET TIMEZONE TO IST (Indian Standard Time)
    $conn->query("SET time_zone = '+05:30'");
    date_default_timezone_set('Asia/Kolkata');
    
} catch (Exception $e) {
    logError("DB Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

// === READ JSON INPUT ===
$rawInput = file_get_contents('php://input');
logError("Raw Input: " . $rawInput);

$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logError("JSON Decode Error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
}

logError("Decoded Input: " . print_r($input, true));

// === VALIDATION ===
if (empty($input['driverId']) || empty($input['orderId']) || empty($input['signature'])) {
    logError("Missing fields - driverId: " . ($input['driverId'] ?? 'null') . ", orderId: " . ($input['orderId'] ?? 'null'));
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

$driverId = trim($input['driverId']);
$orderId = trim($input['orderId']);
$driverName = isset($input['driverName']) ? trim($input['driverName']) : '';
$status = isset($input['status']) ? trim($input['status']) : 'Completed';
$latitude = isset($input['latitude']) ? trim($input['latitude']) : '';
$longitude = isset($input['longitude']) ? trim($input['longitude']) : '';
$signatureBase64 = $input['signature'];

logError("Processing - DriverID: $driverId, OrderID: $orderId");

// === CLEAN BASE64 SIGNATURE ===
if (strpos($signatureBase64, 'data:image/png;base64,') === 0) {
    $signatureBase64 = str_replace('data:image/png;base64,', '', $signatureBase64);
}
$signatureBase64 = str_replace(' ', '+', $signatureBase64);

$signatureData = base64_decode($signatureBase64, true);
if ($signatureData === false) {
    logError("Base64 decode failed");
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid base64 signature"]);
    exit;
}

// === SAVE IMAGE TO FOLDER ===
$uploadDir = "/home/n1epxxcubz1o/public_html/api/signImg/";
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        logError("Failed to create directory: $uploadDir");
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to create upload directory"]);
        exit;
    }
}

$filename = "sign_" . $driverId . "_" . preg_replace('/[^A-Za-z0-9\-\._]/', '_', $orderId) . "_" . time() . ".png";

$filepath = $uploadDir . $filename;
$fileUrl = "https://erphub.ai/api/signImg/" . $filename;

if (file_put_contents($filepath, $signatureData) === false) {
    logError("Failed to save file: $filepath");
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to save signature file"]);
    exit;
}

logError("File saved successfully: $filepath");
chmod($filepath, 0644);

// === CHECK IF ORDER EXISTS & GET CURRENT recived VALUE ===
$checkQuery = "SELECT orderId, driverId, status, recived 
               FROM Orders 
               WHERE driverId = ? AND orderId = ?";

$checkStmt = $conn->prepare($checkQuery);

if (!$checkStmt) {
    logError("Prepare check failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
    exit;
}

$checkStmt->bind_param("ss", $driverId, $orderId);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    logError("No order found for driverID: $driverId, orderID: $orderId");
    http_response_code(404);
    echo json_encode([
        "success" => false, 
        "message" => "Order not found",
        "debug" => ["driverId" => $driverId, "orderId" => $orderId]
    ]);
    exit;
}

$orderData = $result->fetch_assoc();
logError("BEFORE UPDATE - Order data: " . print_r($orderData, true));

$checkStmt->close();

// === UPDATE DATABASE WITH recived = 1 ===
// ✅ NOW() will use IST timezone because we set it in connection
$query = "UPDATE Orders 
          SET CustomerSignPath = ?, 
              status = ?,
              recived = '1',
              updatedDate = NOW()
          WHERE driverId = ? AND orderId = ?";

logError("UPDATE QUERY: $query");
logError("PARAMS: fileUrl=$fileUrl, status=$status, driverId=$driverId, orderId=$orderId");

$stmt = $conn->prepare($query);

if (!$stmt) {
    logError("Prepare update failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database prepare error: " . $conn->error]);
    exit;
}

$stmt->bind_param("ssss", $fileUrl, $status, $driverId, $orderId);

if (!$stmt->execute()) {
    logError("Execute failed: " . $stmt->error);
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database update error: " . $stmt->error]);
    exit;
}

$affectedRows = $stmt->affected_rows;
logError("AFFECTED ROWS: $affectedRows");

$stmt->close();

// === VERIFY UPDATE - Check if recived was actually set to 1 ===
$verifyQuery = "SELECT orderId, status, recived, CustomerSignPath, updatedDate FROM Orders WHERE driverId = ? AND orderId = ?";
$verifyStmt = $conn->prepare($verifyQuery);
$verifyStmt->bind_param("ss", $driverId, $orderId);
$verifyStmt->execute();
$verifyResult = $verifyStmt->get_result();
$updatedOrder = $verifyResult->fetch_assoc();

logError("AFTER UPDATE - Order data: " . print_r($updatedOrder, true));

$verifyStmt->close();

if ($affectedRows > 0) {
    logError("SUCCESS - Order updated: $orderId, Signature URL: $fileUrl");
    echo json_encode([
        "success" => true,
        "message" => "Signature saved & order marked as completed",
        "signature_url" => $fileUrl,
        "filename" => $filename,
        "recived" => $updatedOrder['recived'],
        "status" => $updatedOrder['status'],
        "updatedDate" => $updatedOrder['updatedDate'],  // ✅ Return IST updated date
        "debug" => [
            "affected_rows" => $affectedRows,
            "before_recived" => $orderData['recived'],
            "after_recived" => $updatedOrder['recived']
        ]
    ]);
} else {
    logError("No rows affected - Order may already have this data");
    echo json_encode([
        "success" => true,
        "message" => "Order already updated or no changes made",
        "signature_url" => $fileUrl,
        "recived" => $updatedOrder['recived'],
        "updatedDate" => $updatedOrder['updatedDate'],
        "debug" => [
            "affected_rows" => 0,
            "current_recived" => $updatedOrder['recived']
        ]
    ]);
}

$conn->close();
?>