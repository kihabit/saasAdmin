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

    if (empty($data['driverId']) || empty($data['organization_id'])) {
        throw new Exception("driverId and organization_id are required");
    }

    $driverId = $data['driverId'];
    $organizationId = $data['organization_id'];

    $stmt = $pdo->prepare("
    SELECT 
        u.edu_user_id,
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

    FROM edu_user u

    LEFT JOIN edu_locations l 
        ON l.driverId = u.driverId
        AND l.organization_id = :organization_id

    LEFT JOIN organization s
        ON s.id = :organization_id

    WHERE u.edu_user_id = :driverId
    AND u.userType = 4
    LIMIT 1
");


    $stmt->execute([
        ':driverId' => $driverId,
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
    ]);

} catch (Exception $e) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
