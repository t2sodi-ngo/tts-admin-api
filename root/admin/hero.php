<?php
// api/admin/hero.php — Mobile Hero Slider REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $slides = $pdo->query("SELECT * FROM hero_slides ORDER BY display_order ASC, id DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($slides), 'slides' => $slides]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $slide_id = (int)($input['id'] ?? 0);
    $title = sanitize($input['title'] ?? '');
    $subtitle = sanitize($input['subtitle'] ?? '');
    $image_url = sanitize($input['image_url'] ?? '');
    $cta_text = sanitize($input['cta_text'] ?? '');
    $cta_link = sanitize($input['cta_link'] ?? '');
    $display_order = (int)($input['display_order'] ?? 0);

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../assets/images/hero/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $file_name = 'hero_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
            $image_url = "/assets/images/hero/{$file_name}";
        }
    }

    if ($slide_id > 0) {
        $pdo->prepare("UPDATE hero_slides SET title = ?, subtitle = ?, image_url = ?, cta_text = ?, cta_link = ?, display_order = ? WHERE id = ?")
            ->execute([$title, $subtitle, $image_url, $cta_text, $cta_link, $display_order, $slide_id]);
        json_response(['status' => 'success', 'message' => 'Hero slide updated.']);
    } else {
        $pdo->prepare("INSERT INTO hero_slides (title, subtitle, image_url, cta_text, cta_link, display_order) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$title, $subtitle, $image_url, $cta_text, $cta_link, $display_order]);
        json_response(['status' => 'success', 'message' => 'Hero slide created.']);
    }
} elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $slide_id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM hero_slides WHERE id = ?")->execute([$slide_id]);
    json_response(['status' => 'success', 'message' => 'Hero slide deleted.']);
}
