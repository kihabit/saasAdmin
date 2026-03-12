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

    error_log("FncUser signup data: " . print_r($data, true));

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

    if (!in_array($data['role'], ['school_admin', 'school_staff', 'driver', 'teacher', 'parent'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
        exit;
    }

    date_default_timezone_set('Asia/Kolkata');
    $currentDateTime = date('Y-m-d H:i:s');

    // ── Duplicate checks — fnc_user table ──
    $stmt = $conn->prepare("SELECT fnc_user_id FROM fnc_user WHERE email = ?");
    $stmt->bind_param("s", $data['email']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT fnc_user_id FROM fnc_user WHERE username = ?");
    $stmt->bind_param("s", $data['username']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT fnc_user_id FROM fnc_user WHERE phone_number = ?");
    $stmt->bind_param("s", $data['phone_number']); $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Phone number already registered']);
        exit;
    }
    $stmt->close();

    // ── Values prepare ──
    $driverId     = 'FNC' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
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
    $school_id   = !empty($data['school_id'])   ? intval($data['school_id']) : null;
    $school_name = !empty($data['school_name']) ? $data['school_name']       : null;

    // ── Role → userType (roles table ke hisab se) ──
    // 2=OrgAdmin, 3=BranchManager, 4=driver, 5=teacher, 6=parent
    $roleMap = [
        'school_admin' => 2,  // OrgAdmin
        'school_staff' => 3,  // BranchManager
        'driver'       => 4,  // driver
        'teacher'      => 5,  // teacher
        'parent'       => 6,  // parent
    ];
    $userType = $roleMap[$data['role']] ?? 3;

    // ── Lat/Lng sirf parent ke liye ──
    $lat = ($data['role'] === 'parent' && !empty($data['latitude'])  && $data['latitude']  != '0') ? floatval($data['latitude'])  : null;
    $lng = ($data['role'] === 'parent' && !empty($data['longitude']) && $data['longitude'] != '0') ? floatval($data['longitude']) : null;

    $conn->begin_transaction();

    try {
        $sql = "INSERT INTO fnc_user
                    (driverId, username, firstName, lastName, address, street, city, state, country,
                     zipcode, phone_number, school_id, school_name, userType,
                     latitude, longitude, password_hash, email, token, status, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, 0, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }

        // 20 params: s(driverId) s(username) s(firstName) s(lastName) s(address) s(street) s(city) s(state) s(country) s(zipcode) s(phone_number) i(school_id) s(school_name) i(userType) d(lat) d(lng) s(passwordHash) s(email) s(token) s(created_at)
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
            $school_id,         // i12
            $school_name,       // s13
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

        error_log("New fnc_user: {$data['username']} (ID:$userId, Org:$school_name, SchoolID:$school_id, userType:$userType)");

        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully!',
            'data'    => [
                'user_id'      => $userId,
                'driverId'     => $driverId,
                'username'     => $data['username'],
                'firstName'    => $data['firstName'],
                'lastName'     => $data['lastName'],
                'email'        => $data['email'],
                'phone_number' => $phone_number,
                'city'         => $city,
                'role'         => $data['role'],
                'userType'     => $userType,
                'school_id'    => $school_id,
                'school_name'  => $school_name
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("FncUser SQL Error: " . $e->getMessage());
        throw $e;
    }

} catch (mysqli_sql_exception $e) {
    error_log("FncUser DB error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("FncUser signup error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} finally {
    if (isset($db)) { $db->close(); }
}
?>