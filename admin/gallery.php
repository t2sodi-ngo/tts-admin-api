<?php
// api/admin/gallery.php — Mobile Gallery Manager REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Gallery Media & Categories ────────────────────────────────────
if ($method === 'GET') {
    $category = sanitize($_GET['category'] ?? 'all');
    $search   = sanitize($_GET['search'] ?? '');

    $total_photos = (int)$pdo->query("SELECT COUNT(*) FROM gallery")->fetchColumn();
    $categories   = $pdo->query("SELECT DISTINCT category FROM gallery WHERE category IS NOT NULL AND category != ''")->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT * FROM gallery WHERE 1=1";
    $params = [];

    if ($category !== 'all' && !empty($category)) {
        $sql .= " AND LOWER(category) = ?";
        $params[] = strtolower($category);
    }

    if (!empty($search)) {
        $sql .= " AND (title LIKE ? OR subtitle LIKE ? OR category LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $media = $stmt->fetchAll();

    json_response([
        'status'       => 'success',
        'total_photos' => $total_photos,
        'categories'   => $categories,
        'count'        => count($media),
        'media'        => $media
    ]);
}

// ── POST: Add Photo or Delete Photo ──────────────────────────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'add');
    $photo_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($photo_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid photo ID.'], 400);
        }
        $pdo->prepare("DELETE FROM gallery WHERE id = ?")->execute([$photo_id]);
        json_response(['status' => 'success', 'message' => 'Photo deleted from gallery successfully.']);
    } else {
        $title     = sanitize($_POST['title'] ?? $input['title'] ?? '');
        $subtitle  = sanitize($_POST['subtitle'] ?? $input['subtitle'] ?? '');
        $category  = sanitize($_POST['category'] ?? $input['category'] ?? 'General');
        $image_url = sanitize($_POST['image_url'] ?? $input['image_url'] ?? '');

        // Handle Photo Upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/gallery/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $file_name = 'gallery_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                $image_url = "/uploads/gallery/{$file_name}";
            }
        }

        if (empty($image_url)) {
            json_response(['status' => 'error', 'message' => 'Image file or URL required.'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO gallery (title, subtitle, category, image_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $subtitle, $category, $image_url]);

        json_response(['status' => 'success', 'message' => 'Photo added to gallery successfully.']);
    }
}

// ── DELETE: Delete Photo ─────────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $photo_id = (int)($_GET['id'] ?? 0);
    if ($photo_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid photo ID.'], 400);
    }
    $pdo->prepare("DELETE FROM gallery WHERE id = ?")->execute([$photo_id]);
    json_response(['status' => 'success', 'message' => 'Photo deleted successfully.']);
}
