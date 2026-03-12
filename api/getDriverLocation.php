<?php
header('Content-Type: application/json');

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

    $stmt = $pdo->prepare("
        SELECT 
            latitude,
            longitude,
            speed,
            created_at,
            updated_at
        FROM edu_locations
        WHERE driverId = :driverId
        AND organization_id = :organization_id
        LIMIT 1
    ");

    $stmt->execute([
        ':driverId' => $data['driverId'],
        ':organization_id' => $data['organization_id']
    ]);

    $result = $stmt->fetch();

    if (!$result) {
        echo json_encode([
            "status" => "not_found",
            "message" => "No location found"
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
