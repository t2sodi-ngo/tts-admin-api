<?php
// api/admin/newspaper.php — Mobile Newspaper Mentions REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $clippings = $pdo->query("SELECT * FROM newspaper_mentions ORDER BY publish_date DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($clippings), 'clippings' => $clippings]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $clip_id = (int)($input['id'] ?? 0);
    $paper_name = sanitize($input['paper_name'] ?? '');
    $headline = sanitize($input['headline'] ?? '');
    $pub_date = sanitize($input['publish_date'] ?? date('Y-m-d'));
    $image_url = sanitize($input['image_url'] ?? '');

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../assets/images/press/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $file_name = 'press_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
            $image_url = "/assets/images/press/{$file_name}";
        }
    }

    if ($clip_id > 0) {
        $pdo->prepare("UPDATE newspaper_mentions SET paper_name = ?, headline = ?, publish_date = ?, image_url = ? WHERE id = ?")
            ->execute([$paper_name, $headline, $pub_date, $image_url, $clip_id]);
        json_response(['status' => 'success', 'message' => 'Press clipping updated.']);
    } else {
        $pdo->prepare("INSERT INTO newspaper_mentions (paper_name, headline, publish_date, image_url) VALUES (?, ?, ?, ?)")
            ->execute([$paper_name, $headline, $pub_date, $image_url]);
        json_response(['status' => 'success', 'message' => 'Press clipping added.']);
    }
} elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $clip_id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM newspaper_mentions WHERE id = ?")->execute([$clip_id]);
    json_response(['status' => 'success', 'message' => 'Press clipping deleted.']);
}
