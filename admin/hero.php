<?php
// api/admin/hero.php — Mobile Hero Slider REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Hero Banners ───────────────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT * FROM hero_slides WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (title LIKE ? OR subtitle LIKE ? OR image_url LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY display_order ASC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $slides = $stmt->fetchAll();

    json_response(['status' => 'success', 'count' => count($slides), 'slides' => $slides]);
}

// ── POST: Add, Edit, Update Sort Order, or Delete Slide ──────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $slide_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($slide_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid slide ID.'], 400);
        }
        $pdo->prepare("DELETE FROM hero_slides WHERE id = ?")->execute([$slide_id]);
        json_response(['status' => 'success', 'message' => 'Hero banner deleted successfully.']);
    }

    elseif ($action === 'update_order') {
        $sort_order = (int)($input['display_order'] ?? 0);
        if ($slide_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid slide ID.'], 400);
        }
        $pdo->prepare("UPDATE hero_slides SET display_order = ? WHERE id = ?")->execute([$sort_order, $slide_id]);
        json_response(['status' => 'success', 'message' => 'Banner sort order updated.']);
    }

    else {
        $title         = sanitize($_POST['title'] ?? $input['title'] ?? '');
        $subtitle      = sanitize($_POST['subtitle'] ?? $input['subtitle'] ?? '');
        $image_url     = sanitize($_POST['image_url'] ?? $input['image_url'] ?? '');
        $cta_text      = sanitize($_POST['cta_text'] ?? $input['cta_text'] ?? 'Learn More');
        $cta_link      = sanitize($_POST['cta_link'] ?? $input['cta_link'] ?? '/events.php');
        $display_order = (int)($_POST['display_order'] ?? $input['display_order'] ?? 0);

        // Handle WebP Banner Image Upload (16:9 recommended, saved to /assets/images/uploads/)
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../assets/images/uploads/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $file_name = 'img_' . dechex(time()) . '_' . rand(1000, 9999) . '.webp';
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                $image_url = "/assets/images/uploads/{$file_name}";
            }
        }

        if ($slide_id > 0) {
            $sql_upd = "UPDATE hero_slides SET title = ?, subtitle = ?, cta_text = ?, cta_link = ?, display_order = ?";
            $params_upd = [$title, $subtitle, $cta_text, $cta_link, $display_order];

            if (!empty($image_url)) {
                $sql_upd .= ", image_url = ?";
                $params_upd[] = $image_url;
            }

            $sql_upd .= " WHERE id = ?";
            $params_upd[] = $slide_id;

            $pdo->prepare($sql_upd)->execute($params_upd);
            json_response(['status' => 'success', 'message' => 'Hero banner slide updated successfully.']);
        } else {
            if (empty($image_url)) {
                json_response(['status' => 'error', 'message' => 'Please select a banner image or enter an image URL.'], 400);
            }

            $stmt_ins = $pdo->prepare("INSERT INTO hero_slides (title, subtitle, image_url, cta_text, cta_link, display_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$title, $subtitle, $image_url, $cta_text, $cta_link, $display_order]);
            json_response(['status' => 'success', 'message' => 'Hero banner slide uploaded & converted successfully.']);
        }
    }
}

// ── DELETE: Delete Hero Slide ─────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $slide_id = (int)($_GET['id'] ?? 0);
    if ($slide_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid slide ID.'], 400);
    }
    $pdo->prepare("DELETE FROM hero_slides WHERE id = ?")->execute([$slide_id]);
    json_response(['status' => 'success', 'message' => 'Hero banner deleted successfully.']);
}
