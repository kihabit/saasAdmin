<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once 'config.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    error_log("Received signup data: " . print_r($data, true));
    
    if (empty($data['firstName']) || empty($data['lastName']) || empty($data['email']) || 
        empty($data['username']) || empty($data['password']) || empty($data['role']) || 
        empty($data['address']) || empty($data['phone_number']) || empty($data['street']) || 
        empty($data['city']) || empty($data['state']) || empty($data['country']) || 
        empty($data['zipcode'])) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit;
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }
    
    if (!in_array($data['role'], ['school_admin', 'school_staff', 'driver', 'teacher', 'parent'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
        exit;
    }

    date_default_timezone_set('Asia/Kolkata');
    $currentDateTime = date('Y-m-d H:i:s');

    // Duplicate checks
    $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE email = ?");
    $stmt->bind_param("s", $data['email']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); echo json_encode(['success'=>false,'message'=>'Email already registered']); exit; }
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE username = ?");
    $stmt->bind_param("s", $data['username']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); echo json_encode(['success'=>false,'message'=>'Username already taken']); exit; }
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE phone_number = ?");
    $stmt->bind_param("s", $data['phone_number']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); echo json_encode(['success'=>false,'message'=>'Phone number already registered']); exit; }
    $stmt->close();
    
    // Prepare values
    $driverId     = 'ABC' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
    $passwordHash = md5($data['password']);
    $token        = bin2hex(random_bytes(32));
    
    $phone_number = $data['phone_number'] ?? '';
    $address      = $data['address']      ?? '';
    $street       = $data['street']       ?? '';
    $city         = $data['city']         ?? '';
    $state        = $data['state']        ?? '';
    $country      = $data['country']      ?? '';
    $zipcode      = $data['zipcode']      ?? '';

    // Organization fields
    $school_id   = !empty($data['school_id'])   ? intval($data['school_id']) : null;
    $school_name = !empty($data['school_name']) ? $data['school_name']       : null;

    $roleMap  = ['school_admin'=>2, 'school_staff'=>3, 'driver'=>4, 'teacher'=>5, 'parent'=>6];
    $userType = $roleMap[$data['role']] ?? 2;

    $lat = ($data['role'] === 'parent' && !empty($data['latitude'])  && $data['latitude']  != '0') ? $data['latitude']  : null;
    $lng = ($data['role'] === 'parent' && !empty($data['longitude']) && $data['longitude'] != '0') ? $data['longitude'] : null;

    $conn->begin_transaction();
    
    try {
        // 20 bound params (status hardcoded as 0 in SQL, not bound)
        // Types: s s s s s s s s s s s i s s s s s s i s
        //        1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0
        $sql = "INSERT INTO user_login 
            (driverId, username, firstName, lastName, address, phone_number, 
             street, city, state, country, zipcode, userType, password_hash, 
             email, token, status, created_at, latitude, longitude, school_id, school_name) 
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }

        // 20 params — types: 11 strings, 1 int (userType), 3 strings, 1 string (created_at), 2 strings (lat/lng), 1 int (school_id), 1 string (school_name)
        $stmt->bind_param(
            "sssssssssssississsis",
            $driverId,        // s1
            $data['username'],// s2
            $data['firstName'],// s3
            $data['lastName'], // s4
            $address,          // s5
            $phone_number,     // s6
            $street,           // s7
            $city,             // s8
            $state,            // s9
            $country,          // s10
            $zipcode,          // s11
            $userType,         // i12
            $passwordHash,     // s13
            $data['email'],    // s14
            $token,            // s15
            $currentDateTime,  // s16
            $lat,              // s17
            $lng,              // s18
            $school_id,        // i19
            $school_name       // s20
        );
        
        $stmt->execute();
        $userId = $conn->insert_id;
        $stmt->close();
        $conn->commit();
        
        logAppError("New user registered: {$data['username']} (ID:$userId, Organization:$school_name, SchoolID:$school_id)");
        
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully!',
            'data' => [
                'user_id'     => $userId,
                'driverId'    => $driverId,
                'username'    => $data['username'],
                'firstName'   => $data['firstName'],
                'lastName'    => $data['lastName'],
                'email'       => $data['email'],
                'phone_number'=> $phone_number,
                'city'        => $city,
                'role'        => $data['role'],
                'userType'    => $userType,
                'school_id'   => $school_id,
                'school_name' => $school_name
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("SQL Error: " . $e->getMessage());
        throw $e;
    }
    
} catch (mysqli_sql_exception $e) {
    logAppError("Signup DB error: " . $e->getMessage());
    echo json_encode(['success'=>false, 'message'=>'Database error: '.$e->getMessage()]);
} catch (Exception $e) {
    logAppError("Signup error: " . $e->getMessage());
    echo json_encode(['success'=>false, 'message'=>'Error: '.$e->getMessage()]);
} finally {
    if (isset($db)) { $db->close(); }
}
?>