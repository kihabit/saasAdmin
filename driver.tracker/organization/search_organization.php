<?php
header('Content-Type: application/json');
require_once '../config.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 1) {
    echo json_encode(['schools' => []]);
    exit;
}

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $like = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.name,
            COALESCE(o.org_id, '') as org_id,
            o.city,
            o.state,
            o.industry_id,
            COALESCE(i.name, '') as industry_name
        FROM organization o
        LEFT JOIN industries i ON o.industry_id = i.id
        WHERE 
            o.name LIKE ?
            OR COALESCE(o.org_id, '') LIKE ?
            OR o.city LIKE ?
            OR o.state LIKE ?
        ORDER BY o.name ASC
        LIMIT 15
    ");

    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $result  = $stmt->get_result();
    $schools = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['schools' => $schools]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['schools' => [], 'error' => $e->getMessage()]);
}
?>