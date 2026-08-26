<?php
// api/admin/members.php — Mobile Board Members & Trustees REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $members = $pdo->query("SELECT * FROM board_members ORDER BY display_order ASC, id ASC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($members), 'members' => $members]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $member_id = (int)($input['id'] ?? 0);
    $name = sanitize($input['name'] ?? '');
    $designation = sanitize($input['designation'] ?? '');
    $photo_url = sanitize($input['photo_url'] ?? '');
    $bio = sanitize($input['bio'] ?? '');

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../assets/images/members/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $file_name = 'member_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
            $photo_url = "/assets/images/members/{$file_name}";
        }
    }

    if ($member_id > 0) {
        $pdo->prepare("UPDATE board_members SET name = ?, designation = ?, photo_url = ?, bio = ? WHERE id = ?")
            ->execute([$name, $designation, $photo_url, $bio, $member_id]);
        json_response(['status' => 'success', 'message' => 'Board member updated.']);
    } else {
        $pdo->prepare("INSERT INTO board_members (name, designation, photo_url, bio) VALUES (?, ?, ?, ?)")
            ->execute([$name, $designation, $photo_url, $bio]);
        json_response(['status' => 'success', 'message' => 'Board member added.']);
    }
} elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $member_id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM board_members WHERE id = ?")->execute([$member_id]);
    json_response(['status' => 'success', 'message' => 'Board member removed.']);
}
