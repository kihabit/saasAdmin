<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=u613073349_school;charset=utf8mb4",
        "u613073349_school",
        "fi3G@LP8H9~",
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
    if (isset($data['action']) && $data['action'] === 'update_token') {
        $stmt = $pdo->prepare("UPDATE user_login set notification_token ='".$data['notifi_token']."'  WHERE user_id = ".$data['user_id']);
        $stmt->execute();
       echo json_encode([["status" => "success", "message" => "Token saved successfully"]]);
        exit;
    }
    echo json_encode([["status" => "failed", "message" => "Token saved failed"]]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}