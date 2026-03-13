<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// eduUser folder me hai isliye ../config.php
require_once '../config.php';

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    error_log("EduUser signup data: " . print_r($data, true));

    // ── Required fields ──
    if (empty($data['firstName']) || empty($data['lastName']) || empty($data['email']) ||
        empty($data['username'])  || empty($data['password']) || empty($data['role'])  ||
        empty($data['address'])   || empty($data['phone_number']) || empty($data['street']) ||
        empty($data['city'])      || empty($data['state'])    || empty($data['country']) ||
        empty($data['zipcode'])) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit;
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }

    // ✅ Roles dynamically fetch karo DB se (prt=0, super_admin exclude)
    $roleMap = [];
    $rStmt = $conn->prepare("SELECT id, role_name FROM roles WHERE prt = 0 ORDER BY id ASC");
    $rStmt->execute();
    $rResult = $rStmt->get_result();
    while ($rRow = $rResult->fetch_assoc()) {
        $roleMap[$rRow['role_name']] = intval($rRow['id']);
    }
    $rStmt->close();

    // ✅ Valid roles dynamically check karo
    $validRoles = array_keys($roleMap);
    if (!in_array($data['role'], $validRoles)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
        exit;
    }

    date_default_timezone_set('Asia/Kolkata');
    $currentDateTime = date('Y-m-d H:i:s');

    // ── Duplicate checks — edu_user table ──
    $stmt = $conn->prepare("SELECT user_id FROM edu_user WHERE email = ?");
    $stmt->bind_param("s", $data['email']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT user_id FROM edu_user WHERE username = ?");
    $stmt->bind_param("s", $data['username']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT user_id FROM edu_user WHERE phone_number = ?");
    $stmt->bind_param("s", $data['phone_number']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Phone number already registered']);
        exit;
    }
    $stmt->close();

    // ── Values prepare ──
    $driverId     = 'EDU' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
    $passwordHash = md5($data['password']);
    $token        = bin2hex(random_bytes(32));

    $phone_number = $data['phone_number'] ?? '';
    $address      = $data['address']      ?? '';
    $street       = $data['street']       ?? '';
    $city         = $data['city']         ?? '';
    $state        = $data['state']        ?? '';
    $country      = $data['country']      ?? '';
    $zipcode      = $data['zipcode']      ?? '';

    // ── Organization ──
    $organization_id   = !empty($data['organization_id'])   ? intval($data['organization_id']) : null;
    $organization_name = !empty($data['organization_name']) ? $data['organization_name']       : null;

    // ✅ Role → userType dynamically from DB roleMap
    $userType = $roleMap[$data['role']] ?? 3;

    // ── Lat/Lng sirf parent ke liye ──
    $parentRoleName = array_search(6, $roleMap); // 'parent' ya jo bhi DB mein ho
    $isParent = ($data['role'] === 'parent' || ($parentRoleName && $data['role'] === $parentRoleName));
    $lat = ($isParent && !empty($data['latitude'])  && $data['latitude']  != '0') ? floatval($data['latitude'])  : null;
    $lng = ($isParent && !empty($data['longitude']) && $data['longitude'] != '0') ? floatval($data['longitude']) : null;

    $conn->begin_transaction();

    try {
        $sql = "INSERT INTO edu_user
                    (driverId, username, firstName, lastName, address, street, city, state, country,
                     zipcode, phone_number, organization_id, organization_name, userType,
                     latitude, longitude, password_hash, email, token, status, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, 0, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }

        // 20 params: sssssssssssisiiddssss
        $stmt->bind_param(
            "sssssssssssisiddssss",
            $driverId,          // s1
            $data['username'],  // s2
            $data['firstName'], // s3
            $data['lastName'],  // s4
            $address,           // s5
            $street,            // s6
            $city,              // s7
            $state,             // s8
            $country,           // s9
            $zipcode,           // s10
            $phone_number,      // s11
            $organization_id,   // i12
            $organization_name, // s13
            $userType,          // i14
            $lat,               // d15
            $lng,               // d16
            $passwordHash,      // s17
            $data['email'],     // s18
            $token,             // s19
            $currentDateTime    // s20
        );

        $stmt->execute();
        $userId = $conn->insert_id;
        $stmt->close();
        $conn->commit();

        error_log("New edu_user: {$data['username']} (ID:$userId, Org:$organization_name, SchoolID:$organization_id, userType:$userType)");

        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully!',
            'data'    => [
                'user_id'           => $userId,
                'driverId'          => $driverId,
                'username'          => $data['username'],
                'firstName'         => $data['firstName'],
                'lastName'          => $data['lastName'],
                'email'             => $data['email'],
                'phone_number'      => $phone_number,
                'city'              => $city,
                'role'              => $data['role'],
                'userType'          => $userType,
                'organization_id'   => $organization_id,
                'organization_name' => $organization_name
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("EduUser SQL Error: " . $e->getMessage());
        throw $e;
    }

} catch (mysqli_sql_exception $e) {
    error_log("EduUser DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("EduUser signup error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} finally {
    if (isset($db)) { $db->close(); }
}
?>