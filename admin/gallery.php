<?php
// api/admin/gallery.php — Mobile Gallery Manager REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $category = sanitize($_GET['category'] ?? 'all');
    $sql = "SELECT * FROM gallery";
    if ($category !== 'all') {
        $sql .= " WHERE category = '$category'";
    }
    $sql .= " ORDER BY id DESC";
    $media = $pdo->query($sql)->fetchAll();
    json_response(['status' => 'success', 'count' => count($media), 'media' => $media]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $title = sanitize($_POST['title'] ?? '');
    $category = sanitize($_POST['category'] ?? 'General');
    $image_url = sanitize($_POST['image_url'] ?? '');

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../assets/images/gallery/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $file_name = 'gallery_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
            $image_url = "/assets/images/gallery/{$file_name}";
        }
    }

    if (empty($image_url)) {
        json_response(['status' => 'error', 'message' => 'Image file or URL required.'], 400);
    }

    $pdo->prepare("INSERT INTO gallery (title, category, image_url) VALUES (?, ?, ?)")->execute([$title, $category, $image_url]);
    json_response(['status' => 'success', 'message' => 'Photo added to gallery.']);
}
