<?php
// api/admin/districts.php — Mobile Odisha Districts REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Odisha Districts List ──────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT * FROM districts WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (district_name LIKE ? OR state LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY district_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $districts = $stmt->fetchAll();

    json_response([
        'status'    => 'success',
        'count'     => count($districts),
        'districts' => $districts
    ]);
}

// ── POST: Add District, Toggle Active Status, or Delete ───────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $district_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($district_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid district ID.'], 400);
        }
        $pdo->prepare("DELETE FROM districts WHERE id = ?")->execute([$district_id]);
        json_response(['status' => 'success', 'message' => 'District deleted successfully.']);
    }

    elseif ($action === 'toggle_status') {
        $is_active = (int)($input['is_active'] ?? 1);
        if ($district_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid district ID.'], 400);
        }
        $pdo->prepare("UPDATE districts SET is_active = ? WHERE id = ?")->execute([$is_active, $district_id]);
        json_response(['status' => 'success', 'message' => 'District active status updated.']);
    }

    else {
        $district_name = sanitize($_POST['district_name'] ?? $input['district_name'] ?? $input['name'] ?? '');
        $state         = sanitize($_POST['state'] ?? $input['state'] ?? 'Odisha');
        $is_active     = (int)($_POST['is_active'] ?? $input['is_active'] ?? 1);

        if (empty($district_name)) {
            json_response(['status' => 'error', 'message' => 'District name is required.'], 400);
        }

        if ($district_id > 0) {
            $pdo->prepare("UPDATE districts SET district_name = ?, state = ?, is_active = ? WHERE id = ?")
                ->execute([$district_name, $state, $is_active, $district_id]);
            json_response(['status' => 'success', 'message' => 'District updated successfully.']);
        } else {
            $pdo->prepare("INSERT INTO districts (district_name, state, is_active) VALUES (?, ?, ?)")
                ->execute([$district_name, $state, $is_active]);
            json_response(['status' => 'success', 'message' => 'District added successfully.']);
        }
    }
}

// ── DELETE: Delete District ───────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $district_id = (int)($_GET['id'] ?? 0);
    if ($district_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid district ID.'], 400);
    }
    $pdo->prepare("DELETE FROM districts WHERE id = ?")->execute([$district_id]);
    json_response(['status' => 'success', 'message' => 'District deleted successfully.']);
}
