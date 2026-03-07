<?php
/**
 * search_school.php
 * Organization search - name, org_id, city, state return karta hai
 */

header('Content-Type: application/json');

$host   = '127.0.0.1';
$dbname = 'u613073349_school';
$user   = 'u613073349_school';
$pass   = 'fi3G@LP8H9~';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode(['schools' => []]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            org_id,
            city,
            state
        FROM organization
        WHERE 
            name   LIKE :q
            OR org_id LIKE :q
            OR city   LIKE :q
            OR state  LIKE :q
        ORDER BY name ASC
        LIMIT 15
    ");

    $like = '%' . $q . '%';
    $stmt->execute([':q' => $like]);
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['schools' => $schools]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['schools' => [], 'error' => $e->getMessage()]);
}