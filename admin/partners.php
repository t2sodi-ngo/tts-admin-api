<?php
// api/admin/partners.php — Mobile Partners & Supporters REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Partners List ──────────────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT * FROM partners WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR website LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY display_order ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $partners = $stmt->fetchAll();

    json_response(['status' => 'success', 'count' => count($partners), 'partners' => $partners]);
}

// ── POST: Add, Edit, or Delete Partner ──────────────────────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $partner_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($partner_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid partner ID.'], 400);
        }
        $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$partner_id]);
        json_response(['status' => 'success', 'message' => 'Partner removed successfully.']);
    } else {
        $name          = sanitize($_POST['name'] ?? $input['name'] ?? '');
        $website       = sanitize($_POST['website'] ?? $input['website'] ?? '');
        $logo_url      = sanitize($_POST['logo_url'] ?? $input['logo_url'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? $input['display_order'] ?? 0);

        // Handle File Upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/partners/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $file_name = 'partner_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
                $logo_url = "/uploads/partners/{$file_name}";
            }
        }

        if (empty($name)) {
            json_response(['status' => 'error', 'message' => 'Please provide partner name.'], 400);
        }

        if ($partner_id > 0) {
            $sql_upd = "UPDATE partners SET name = ?, website = ?, display_order = ?";
            $params_upd = [$name, $website, $display_order];

            if (!empty($logo_url)) {
                $sql_upd .= ", logo_url = ?";
                $params_upd[] = $logo_url;
            }

            $sql_upd .= " WHERE id = ?";
            $params_upd[] = $partner_id;

            $pdo->prepare($sql_upd)->execute($params_upd);
            json_response(['status' => 'success', 'message' => "Partner '{$name}' updated successfully."]);
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO partners (name, logo_url, website, display_order) VALUES (?, ?, ?, ?)");
            $stmt_ins->execute([$name, $logo_url, $website, $display_order]);
            json_response(['status' => 'success', 'message' => "Partner '{$name}' added successfully."]);
        }
    }
}

// ── DELETE: Delete Partner ───────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $partner_id = (int)($_GET['id'] ?? 0);
    if ($partner_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid partner ID.'], 400);
    }
    $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$partner_id]);
    json_response(['status' => 'success', 'message' => 'Partner removed successfully.']);
}
