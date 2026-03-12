<?php
// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once dirname(__FILE__) . '/config.php'; // 📄 Load DB config

// Set headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Adjust for production
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// Connect to database
$conn = new mysqli("localhost", "u613073349_school", "fi3G@LP8H9~", "u613073349_school");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit();
}

// Get raw input and decode JSON
$rawInput = file_get_contents("php://input");

// Optional: log input for debugging (disable in production)
// file_put_contents("php_input_log.txt", $rawInput);

$data = json_decode($rawInput);

// Handle invalid or empty JSON
if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid or empty JSON input."]);
    exit();
}

// Validate required fields
if (!isset($data->username) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing username or password."]);
    exit();
}

$username = $conn->real_escape_string(trim($data->username));
$password = $data->password;

// *** CRITICAL FIX: Query user by username AND include status field ***
$sql = "SELECT user_id, driverId, email,userType, school_id, van_number, username, password_hash, status FROM user_login WHERE username = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Query preparation failed."]);
    exit();
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    
    // *** CRITICAL: CHECK USER STATUS FIRST ***
    $userStatus = isset($user['status']) ? intval($user['status']) : 1;
    
    if ($userStatus != 1) {
        // Account is inactive
        http_response_code(403); // 403 Forbidden
        echo json_encode([
            "status" => "error", 
            "message" => "Your account has been deactivated. Please contact administrator.",
            "error_code" => "ACCOUNT_INACTIVE"
        ]);
        
        // Log the attempt
        if (function_exists('logAppError')) {
            logAppError("Mobile login attempt for inactive account: " . $username);
        }
        
    } elseif (md5(trim($password)) == $user['password_hash']) {
        // Account is active AND password is correct - Successful login
        
        if($user['userType']==6){
            $parentSql = " SELECT 
            c.id AS child_id,
            c.name AS child_name,
            c.class,
            c.section,
            c.parent_id,
            d.user_id AS driver_id,
            CONCAT(d.firstName, d.lastName) AS driver_name,
            d.phone_number,
            d.van_number,
            s.name as sname,
            s.latitude,
            s.longitude,
            s.phone as school_number
        FROM children c
        LEFT JOIN user_login d 
            ON c.driver_id = d.user_id left join school as s on s.id=d.school_id
        WHERE c.parent_id = ?";
        $prtStmt = $conn->prepare($parentSql);
        $prtStmt->bind_param("s",$user['user_id']);
        $prtStmt->execute();
        $prtresult = $prtStmt->get_result();
        $prtresult = $prtresult->fetch_assoc();
        
        echo json_encode([
            "status" => "success",
            "message" => "Login successful.",
            "user_id" => $user['user_id'],
            "van_number" => $user['van_number'],
            "driverId" => $user['driverId'],
            "schoolid" => $user['school_id'],
            "email" => $user['email'],
            "username" => $user['username'],
            "account_status" => "active",
            "dname"=>$prtresult['driver_name'],
            "did"=>$prtresult['driver_id'],
            "dphone"=>$prtresult['phone_number'],
            "sname"=>$prtresult['sname'],
            "slat"=>$prtresult['latitude'],
            "slong"=>$prtresult['longitude'],
            "school_number"=>$prtresult['school_number']
        ]);
           
        }else{
            
            echo json_encode([
            "status" => "success",
            "message" => "Login successful.",
            "user_id" => $user['user_id'],
            "van_number" => $user['van_number'],
            "driverId" => $user['driverId'],
            "schoolid" => $user['school_id'],
            "email" => $user['email'],
            "username" => $user['username'],
            "account_status" => "active"
        ]);
        }
        
        // Update last login timestamp
        $updateSql = "UPDATE user_login SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        if ($updateStmt) {
            $updateStmt->bind_param("i", $user['user_id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
        
        
        
        // Log successful login
        if (function_exists('logAppError')) {
            logAppError("Successful mobile login for user: " . $username);
        }
        
    } else {
        // Invalid password
        http_response_code(401);
        echo json_encode([
            "status" => "error", 
            "message" => "Invalid credentials.",
            "error_code" => "INVALID_PASSWORD"
        ]);
        
        // Log failed attempt
        if (function_exists('logAppError')) {
            logAppError("Failed mobile login attempt for user: " . $username);
        }
    }
    
} else {
    // User not found
    http_response_code(404);
    echo json_encode([
        "status" => "error", 
        "message" => "User not found.",
        "error_code" => "USER_NOT_FOUND"
    ]);
}

// Cleanup
$stmt->close();
$conn->close();
?>