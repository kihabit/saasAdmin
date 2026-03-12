<?php
// Allow cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Only POST method is allowed'
    ]);
    exit();
}

// Database connection settings
$host = '127.0.0.1';
$db = 'u613073349_school';
$user = 'u613073349_school';
$pass = 'fi3G@LP8H9~';

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Validate input
    if (!isset($data['user_id']) || empty(trim($data['user_id']))) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Driver ID is required'
        ]);
        exit();
    }
    
    if (!isset($data['new_password']) || empty(trim($data['new_password']))) {
        echo json_encode([
            'status' => 'error',
            'message' => 'New password is required'
        ]);
        exit();
    }
    
    $driverId = trim($data['user_id']);
    $newPassword = trim($data['new_password']);
    
    // Password length validation
    if (strlen($newPassword) < 6) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Password must be at least 6 characters long'
        ]);
        exit();
    }
    
    // Check if driver exists by driverId column
    $stmt = $pdo->prepare("SELECT driverId, username, email FROM user_login WHERE driverId = :driverId LIMIT 1");
    $stmt->execute([
        'driverId' => $driverId
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Log for debugging
        error_log("Reset Password - Driver not found: " . $driverId);
        echo json_encode([
            'status' => 'error',
            'message' => 'Driver not found with this ID: ' . $driverId
        ]);
        exit();
    }
    
    // Hash password with MD5
    $hashedPassword = md5($newPassword);
    
    // Update password using driverId column
    $updateStmt = $pdo->prepare("UPDATE user_login SET password_hash = :password_hash WHERE driverId = :driverId");
    $updated = $updateStmt->execute([
        'password_hash' => $hashedPassword,
        'driverId' => $driverId
    ]);
    
    if ($updated) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Password reset successfully',
            'username' => $user['username'],
            'user_email' => $user['email']
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update password'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>