<?php
// api/admin/volunteers.php — Mobile Volunteers REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = sanitize($_GET['status'] ?? 'all');
    $sql = "SELECT * FROM volunteers";
    if ($status !== 'all') {
        $sql .= " WHERE status = '$status'";
    }
    $sql .= " ORDER BY created_at DESC";
    $volunteers = $pdo->query($sql)->fetchAll();
    json_response(['status' => 'success', 'count' => count($volunteers), 'volunteers' => $volunteers]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $vol_id = (int)($input['id'] ?? 0);
    $new_status = sanitize($input['status'] ?? 'approved');

    if ($vol_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid volunteer ID.'], 400);
    }

    $pdo->prepare("UPDATE volunteers SET status = ? WHERE id = ?")->execute([$new_status, $vol_id]);
    json_response(['status' => 'success', 'message' => "Volunteer status updated to {$new_status}."]);
}
