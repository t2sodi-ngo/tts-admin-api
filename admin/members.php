<?php
// api/admin/members.php — Mobile Board Members & Trustees REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Board Members ──────────────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT * FROM board_members WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR designation LIKE ? OR bio LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY display_order ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll();

    json_response(['status' => 'success', 'count' => count($members), 'members' => $members]);
}

// ── POST: Add, Edit, or Delete Board Member ─────────────────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $member_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($member_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid member ID.'], 400);
        }
        $pdo->prepare("DELETE FROM board_members WHERE id = ?")->execute([$member_id]);
        json_response(['status' => 'success', 'message' => 'Board member removed successfully.']);
    } else {
        $name          = sanitize($_POST['name'] ?? $input['name'] ?? '');
        $designation   = sanitize($_POST['designation'] ?? $input['designation'] ?? '');
        $bio           = sanitize($_POST['bio'] ?? $input['bio'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? $input['display_order'] ?? 0);
        $is_founder    = isset($_POST['is_founder']) ? (int)$_POST['is_founder'] : (int)($input['is_founder'] ?? 0);
        $photo_url     = sanitize($_POST['photo_url'] ?? $input['photo_url'] ?? '');

        // If marked as founder president, reset others (limit one founder)
        if ($is_founder === 1) {
            $pdo->query("UPDATE board_members SET is_founder = 0 WHERE is_founder = 1");
        }

        // Handle Photo Upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/members/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $file_name = 'member_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                $photo_url = "/uploads/members/{$file_name}";
            }
        }

        if (empty($name) || empty($designation)) {
            json_response(['status' => 'error', 'message' => 'Please provide member name and designation.'], 400);
        }

        if ($member_id > 0) {
            $sql_upd = "UPDATE board_members SET name = ?, designation = ?, bio = ?, display_order = ?, is_founder = ?";
            $params_upd = [$name, $designation, $bio, $display_order, $is_founder];

            if (!empty($photo_url)) {
                $sql_upd .= ", photo_url = ?";
                $params_upd[] = $photo_url;
            }

            $sql_upd .= " WHERE id = ?";
            $params_upd[] = $member_id;

            $pdo->prepare($sql_upd)->execute($params_upd);
            json_response(['status' => 'success', 'message' => "Board member '{$name}' updated successfully."]);
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO board_members (name, designation, photo_url, bio, display_order, is_founder) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$name, $designation, $photo_url, $bio, $display_order, $is_founder]);
            json_response(['status' => 'success', 'message' => "Board member '{$name}' added successfully."]);
        }
    }
}

// ── DELETE: Remove Member ────────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $member_id = (int)($_GET['id'] ?? 0);
    if ($member_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid member ID.'], 400);
    }
    $pdo->prepare("DELETE FROM board_members WHERE id = ?")->execute([$member_id]);
    json_response(['status' => 'success', 'message' => 'Board member removed successfully.']);
}
