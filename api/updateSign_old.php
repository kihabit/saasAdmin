<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// DB config
$host = "72.167.69.235";
$dbname = "shipping_tracker";
$user = "tracker";
$pass = "{aJjwBl{j5;(";

// Connect to DB
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed"]));
}

// Debug output (remove in production)
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

// Validate input
if (!isset($_POST['driverId']) || !isset($_POST['orderId']) || !isset($_FILES['signature'])) {
    echo json_encode(["success" => false, "message" => "Missing required fields or file"]);
    exit;
}

$driverId = $_POST['driverId'];
$orderId = $_POST['orderId'];
$status = isset($_POST['status']) ? $_POST['status'] : '';
$file = $_FILES['signature'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = "Upload error: ";
    switch ($file['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $errorMessage .= "File too large";
            break;
        case UPLOAD_ERR_PARTIAL:
            $errorMessage .= "File partially uploaded";
            break;
        case UPLOAD_ERR_NO_FILE:
            $errorMessage .= "No file uploaded";
            break;
        default:
            $errorMessage .= "Unknown error";
    }
    echo json_encode(["success" => false, "message" => $errorMessage]);
    exit;
}

// Validate file size (5MB limit)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode(["success" => false, "message" => "File too large. Maximum 5MB allowed"]);
    exit;
}

// Validate PNG - check both MIME type and file extension
$allowedTypes = ['image/png'];
$allowedExtensions = ['png'];
$fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($file['type'], $allowedTypes) || !in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(["success" => false, "message" => "Only PNG files are allowed"]);
    exit;
}

// Additional validation: check if it's actually a PNG file
$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false || $imageInfo['mime'] !== 'image/png') {
    echo json_encode(["success" => false, "message" => "Invalid PNG file"]);
    exit;
}

// Create uploads directory if not exists
$uploadDir = "/home/n1epxxcubz1o/public_html/api/signImg/";
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(["success" => false, "message" => "Failed to create upload directory"]);
        exit;
    }
}

// Check if directory is writable
if (!is_writable($uploadDir)) {
    echo json_encode(["success" => false, "message" => "Upload directory is not writable"]);
    exit;
}

// Generate unique file name - FIXED: Use the generated filename, not tmp_name
$filename = uniqid("sign_") . ".png";
$filepath = $uploadDir . $filename; // Use $filename instead of $file['tmp_name']

// Debug output
error_log("Attempting to move file from: " . $file['tmp_name']);
error_log("To: " . $filepath);

// Move file
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    // Save path in DB - FIXED: Corrected parameter binding order
    $stmt = $conn->prepare("UPDATE Orders SET CustomerSignPath = ?, recived = 1, status = ? WHERE driverId = ? AND orderId = ?");
    $stmt->bind_param("ssss", $filepath, $status, $driverId, $orderId);
    
    if ($stmt->execute()) {
        // Check if any rows were affected
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                "success" => true, 
                "message" => "File uploaded successfully", 
                "path" => $filepath,
                "filename" => $filename
            ]);
        } else {
            echo json_encode([
                "success" => false, 
                "message" => "No matching order found to update"
            ]);
        }
    } else {
        echo json_encode([
            "success" => false, 
            "message" => "Database update failed: " . $stmt->error
        ]);
    }
    $stmt->close();
} else {
    // Get more specific error information
    $error = error_get_last();
    echo json_encode([
        "success" => false, 
        "message" => "Failed to move uploaded file",
        "debug" => [
            "source" => $file['tmp_name'],
            "destination" => $filepath,
            "error" => $error ? $error['message'] : 'Unknown error'
        ]
    ]);
}

$conn->close();
?>