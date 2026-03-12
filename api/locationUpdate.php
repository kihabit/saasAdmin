<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
$file = "../sendNotification.php";

if (!file_exists($file)) {
    die("File not found: " . realpath($file));
}

require_once($file);

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

    if (!$data || !is_array($data)) {
        throw new Exception("Invalid JSON data");
    }

    // ✅ STOP action check - single object {action: stop, driverId: xxx}
    if (isset($data['action']) && $data['action'] === 'stop') {
        $stmt = $pdo->prepare("UPDATE edu_locations SET is_active = 0, notification_sent = 0 WHERE driverId = ?");
        $stmt->execute([$data['driverId']]);
        echo json_encode(["status" => "success", "message" => "Driver stopped"]);
        exit;
    }

    $driverRo = $pdo->prepare("SELECT notification_sent from edu_locations where driverId=? and organization_id=?");
    $driverRo->execute([$data['driverId'], $data['organization_id']]);
    $driverRow = $driverRo->fetch();


    if ($driverRow['notification_sent'] == 0) {
        $ptokenRo = $pdo->prepare("
            SELECT ul.notification_token 
            FROM students as stu
            LEFT JOIN edu_user as ul ON stu.parent_id = ul.user_id
            WHERE stu.driver_id = ? AND stu.organization_id = ?
        ");

        $ptokenRo->execute([$data['driverId'], $data['organization_id']]);
        $ptokenRow = $ptokenRo->fetchAll(PDO::FETCH_ASSOC);

        $tokens = array_column($ptokenRow, 'notification_token');

        $sendNotification = sendNotification($tokens, [
            "title" => "Driver has started van",
            "body" => ["description" => "Van has been started for your child pickup"]
        ]);
    }
    $pdo->beginTransaction();

    $historyStmt = $pdo->prepare("
        INSERT INTO edu_route_history
        (driver_id, organization_id, latitude, longitude, speed, sos, recorded_at)
        VALUES
        (:driverId, :organization_id, :latitude, :longitude, :speed, :sos, :recorded_at)
    ");

    // ✅ is_active = 1 add kiya
    $liveStmt = $pdo->prepare("
        INSERT INTO edu_locations
        (driverId, organization_id, driverName, latitude, longitude, speed, updated_at, is_active)
        VALUES
        (:driverId, :organization_id, :driverName, :latitude, :longitude, :speed, :updated_at, 1)
        ON DUPLICATE KEY UPDATE
            organization_id   = VALUES(organization_id),
            driverName  = VALUES(driverName),
            latitude    = VALUES(latitude),
            longitude   = VALUES(longitude),
            speed       = VALUES(speed),
            updated_at  = VALUES(updated_at),
            is_active   = 1,
            notification_sent = 1
    ");

    $alertStmt = $pdo->prepare("
    INSERT INTO alerts
    (latitude, longitude, type, driver_id, message)
    VALUES
    (:latitude, :longitude, :type, :driver_id, :message)
");

    foreach ($data as $row) {
        if (empty($row['driverId']) || empty($row['latitude']) || empty($row['longitude'])) {
            continue;
        }

        $driverId = $row['driverId'];
        $schoolId = $row['organization_id'] ?? 0;
        $driverName = $row['driverName'] ?? '';
        $latitude = $row['latitude'];
        $longitude = $row['longitude'];
        $speed = $row['speed'] ?? 0;
        $sos = $row['sos'] ?? 0;
        $datetime = $row['datetime'] ?? date('Y-m-d H:i:s');

        $historyStmt->execute([
            ':driverId' => $driverId,
            ':organization_id' => $schoolId,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':speed' => $speed,
            ':sos' => $sos,
            ':recorded_at' => $datetime
        ]);

        $liveStmt->execute([
            ':driverId' => $driverId,
            ':organization_id' => $schoolId,
            ':driverName' => $driverName,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':speed' => $speed,
            ':updated_at' => $datetime
        ]);

        if ($sos == 1) {
            $message = "SOS Alert triggered by Driver: " . $driverId . " (" . $driverName . ")";
            // Pehle user_id fetch karo driverId se
            $userStmt = $pdo->prepare("SELECT user_id FROM user_login WHERE driverId = ?");
            $userStmt->execute([$driverId]);
            $userRow = $userStmt->fetch();
            $actualDriverId = $userRow ? $userRow['user_id'] : 0;

            $alertStmt->execute([
                ':latitude' => $latitude,
                ':longitude' => $longitude,
                ':type' => 'sos',
                ':driver_id' => $driverId,
                ':message' => $message
            ]);
        }
    }

    $pdo->commit();
    echo json_encode([["status" => "success", "message" => "Data saved successfully"]]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}