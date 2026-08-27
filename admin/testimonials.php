<?php
// api/admin/testimonials.php — Mobile Beneficiary Testimonials REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Testimonials List ──────────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT * FROM testimonials WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR role_desc LIKE ? OR quote LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY display_order ASC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $testimonials = $stmt->fetchAll();

    json_response(['status' => 'success', 'count' => count($testimonials), 'testimonials' => $testimonials]);
}

// ── POST: Add, Edit, Approve/Hide, or Delete Testimonial ──────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $t_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($t_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid testimonial ID.'], 400);
        }
        $pdo->prepare("DELETE FROM testimonials WHERE id = ?")->execute([$t_id]);
        json_response(['status' => 'success', 'message' => 'Testimonial deleted successfully.']);
    } else {
        $name          = sanitize($_POST['name'] ?? $input['name'] ?? '');
        $role_desc     = sanitize($_POST['role_desc'] ?? $input['role_desc'] ?? '');
        $quote         = sanitize($_POST['quote'] ?? $input['quote'] ?? '');
        $avatar_url    = sanitize($_POST['avatar_url'] ?? $input['avatar_url'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? $input['display_order'] ?? 0);
        $is_approved   = isset($_POST['is_approved']) ? (int)$_POST['is_approved'] : (int)($input['is_approved'] ?? 1);

        // Handle Avatar Photo Upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/testimonials/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $file_name = 'avatar_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_dir . $file_name)) {
                $avatar_url = "/uploads/testimonials/{$file_name}";
            }
        }

        if (empty($name) || empty($quote)) {
            json_response(['status' => 'error', 'message' => 'Please provide full name and quote message.'], 400);
        }

        if ($t_id > 0) {
            $sql_upd = "UPDATE testimonials SET name = ?, role_desc = ?, quote = ?, display_order = ?, is_approved = ?";
            $params_upd = [$name, $role_desc, $quote, $display_order, $is_approved];

            if (!empty($avatar_url)) {
                $sql_upd .= ", avatar_url = ?";
                $params_upd[] = $avatar_url;
            }

            $sql_upd .= " WHERE id = ?";
            $params_upd[] = $t_id;

            $pdo->prepare($sql_upd)->execute($params_upd);
            json_response(['status' => 'success', 'message' => "Testimonial from '{$name}' updated successfully."]);
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO testimonials (name, role_desc, quote, avatar_url, display_order, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$name, $role_desc, $quote, $avatar_url, $display_order, $is_approved]);
            json_response(['status' => 'success', 'message' => "Testimonial from '{$name}' published successfully."]);
        }
    }
}

// ── DELETE: Delete Testimonial ────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $t_id = (int)($_GET['id'] ?? 0);
    if ($t_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid testimonial ID.'], 400);
    }
    $pdo->prepare("DELETE FROM testimonials WHERE id = ?")->execute([$t_id]);
    json_response(['status' => 'success', 'message' => 'Testimonial deleted successfully.']);
}
