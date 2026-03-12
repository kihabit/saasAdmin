<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
/*production*/
    // $pdo = new PDO(
    //     "mysql:host=localhost;dbname=u613073349_organization;charset=utf8mb4",
    //     "u613073349_organization",
    //     "fi3G@LP8H9~",
    //     [
    //         PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    //         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    //     ]
    // );
/*dev*/
    $pdo = new PDO(
        "mysql:host=localhost;dbname=u613073349_school;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (empty($data['organization_id'])) {
        throw new Exception("organization_id is required");
    }

    $organizationId = $data['organization_id'];

    if (!empty($data['edu_user_id'])) {

        $userId = $data['edu_user_id'];
        $userTable = 'edu_user';
        $userIdColumn = 'edu_user_id';
        $locationsTable = 'edu_locations';

    } elseif (!empty($data['fnc_user_id'])) {

        $userId = $data['fnc_user_id'];
        $userTable = 'fin_user';
        $userIdColumn = 'fnc_user_id';
        $locationsTable = 'fin_locations';

    } else {
        throw new Exception("edu_user_id or fnc_user_id is required");
    }

    $stmt = $pdo->prepare("
    SELECT 
        u.$userIdColumn AS user_id,
        u.driverId,
        u.firstName,
        u.lastName,
        u.phone_number,
        u.email,
        u.status,
        u.created_at,

        s.id AS organization_id,
        s.name AS organization_name,
        s.address AS organization_address,
        s.city,
        s.state,
        s.postal_code,
        s.phone AS organization_phone,
        s.email AS organization_email,
        s.latitude AS organization_latitude,
        s.longitude AS organization_longitude,

        l.latitude AS van_latitude,
        l.longitude AS van_longitude,
        l.speed,
        l.is_active, 
        l.updated_at

    FROM $userTable u

    LEFT JOIN $locationsTable l 
        ON l.driverId = u.driverId
        AND l.organization_id = :organization_id

    INNER JOIN organization s
        ON s.id = :organization_id

    WHERE u.$userIdColumn = :userId
    AND u.userType = 4
    AND u.organization_id = :organization_id
    LIMIT 1
");

    $stmt->execute([
        ':userId' => $userId,
        ':organization_id' => $organizationId
    ]);

    $result = $stmt->fetch();

    if (!$result) {
        echo json_encode([
            "status" => "not_found",
            "message" => "Driver not found"
        ]);
        exit;
    }

  echo json_encode([
    "status" => "success",
    "data" => $result
], JSON_PRETTY_PRINT);

} catch (Exception $e) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}