<?php
header('Content-Type: application/json');

try {
/*production*/
    //  $pdo = new PDO(
    //     "mysql:host=localhost;dbname=u613073349_school;charset=utf8mb4",
    //     "u613073349_school",
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

if (empty($data['driverId'])) {
    throw new Exception("driverId must be valid");
}

$stmt = $pdo->prepare("
    SELECT * FROM alerts
    WHERE driver_id = :driverId
    ORDER BY id DESC
");

$stmt->execute([
    ':driverId' => $data['driverId']
]);

$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$result) {
    echo json_encode([
        "status" => "not_found",
        "message" => "No SOS found"
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
