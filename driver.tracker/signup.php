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

    // GET requests return role list (dynamic from roles table)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $roles = [];
        $rolesQuery = "SELECT id, role_name FROM roles ORDER BY id ASC";
        $rolesResult = $conn->query($rolesQuery);
        if ($rolesResult) {
            while ($row = $rolesResult->fetch_assoc()) {
                $roles[] = $row;
            }
        }
        echo json_encode(['success' => true, 'roles' => $roles]);
        exit;
    }

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
    
    // Load valid roles from database (dynamic)
    $roleMap = [];
    $rolesQuery = "SELECT id, role_name FROM roles";
    $rolesResult = $conn->query($rolesQuery);
    if ($rolesResult) {
        while ($row = $rolesResult->fetch_assoc()) {
            $roleMap[$row['role_name']] = intval($row['id']);
        }
    }

    if (empty($data['role']) || !isset($roleMap[$data['role']])) {
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
    $organization_id   = !empty($data['organization_id'])   ? intval($data['organization_id']) : null;
    $organization_name = !empty($data['organization_name']) ? $data['organization_name']       : null;

    $userType = $roleMap[$data['role']];

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
             email, token, status, created_at, latitude, longitude, organization_id, organization_name) 
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }

        // 20 params — types: 11 strings, 1 int (userType), 3 strings, 1 string (created_at), 2 strings (lat/lng), 1 int (organization_id), 1 string (organization_name)
        $stmt->bind_param(
           "sssssssssssisssssiss",
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
            $organization_id,        // i19
            $organization_name       // s20
        );
        
        $stmt->execute();
        $userId = $conn->insert_id;
        $stmt->close();
        $conn->commit();
        
        logAppError("New user registered: {$data['username']} (ID:$userId, Organization:$organization_name, SchoolID:$organization_id)");
        
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
                'organization_id'   => $organization_id,
                'organization_name' => $organization_name
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