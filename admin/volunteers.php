<?php
// api/admin/volunteers.php — Mobile Volunteers REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = sanitize($_GET['status'] ?? 'all');
    $search = sanitize($_GET['search'] ?? '');

    // Calculate Summary Metrics
    $total_volunteers = (int)$pdo->query("SELECT COUNT(*) FROM volunteers")->fetchColumn();
    $active_members   = (int)$pdo->query("SELECT COUNT(*) FROM volunteers WHERE LOWER(status) IN ('active', 'approved')")->fetchColumn();
    $pending_review   = (int)$pdo->query("SELECT COUNT(*) FROM volunteers WHERE LOWER(status) = 'pending'")->fetchColumn();
    $verified_hours   = (float)$pdo->query("SELECT COALESCE(SUM(hours_logged), 0) FROM volunteers")->fetchColumn();

    // Filter counts for chips
    $all_count      = $total_volunteers;
    $active_count   = $active_members;
    $pending_count  = $pending_review;
    $inactive_count = (int)$pdo->query("SELECT COUNT(*) FROM volunteers WHERE LOWER(status) IN ('inactive', 'rejected', 'banned')")->fetchColumn();

    $sql = "SELECT * FROM volunteers WHERE 1=1";
    $params = [];

    if ($status !== 'all' && !empty($status)) {
        if ($status === 'active') {
            $sql .= " AND LOWER(status) IN ('active', 'approved')";
        } elseif ($status === 'inactive') {
            $sql .= " AND LOWER(status) IN ('inactive', 'rejected', 'banned')";
        } else {
            $sql .= " AND LOWER(status) = ?";
            $params[] = strtolower($status);
        }
    }

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR city LIKE ? OR phone LIKE ? OR area_of_interest LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $volunteers = $stmt->fetchAll();

    json_response([
        'status'           => 'success',
        'total_volunteers' => $total_volunteers,
        'active_members'   => $active_members,
        'pending_review'   => $pending_review,
        'verified_hours'   => $verified_hours,
        'counts' => [
            'all'      => $all_count,
            'active'   => $active_count,
            'pending'  => $pending_count,
            'inactive' => $inactive_count
        ],
        'count'            => count($volunteers),
        'volunteers'       => $volunteers
    ]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'update_status');
    $vol_id = (int)($input['id'] ?? 0);

    if ($vol_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid volunteer ID.'], 400);
    }

    if ($action === 'delete') {
        if ($vol_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid volunteer ID.'], 400);
        }
        $pdo->prepare("DELETE FROM volunteers WHERE id = ?")->execute([$vol_id]);
        json_response(['status' => 'success', 'message' => 'Volunteer record deleted successfully.']);
    } elseif ($action === 'add' || $action === 'add_volunteer') {
        $name     = sanitize($_POST['name'] ?? $input['name'] ?? '');
        $email    = sanitize($_POST['email'] ?? $input['email'] ?? '');
        $phone    = sanitize($_POST['phone'] ?? $input['phone'] ?? '');
        $city     = sanitize($_POST['city'] ?? $input['city'] ?? 'Bhubaneswar');
        $interest = sanitize($_POST['area_of_interest'] ?? $input['area_of_interest'] ?? 'General Seva');
        $status   = sanitize($_POST['status'] ?? $input['status'] ?? 'active');

        if (empty($name) || empty($email)) {
            json_response(['status' => 'error', 'message' => 'Volunteer name and email are required.'], 400);
        }

        $pdo->prepare("INSERT INTO volunteers (name, email, phone, city, area_of_interest, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$name, $email, $phone, $city, $interest, $status]);

        json_response(['status' => 'success', 'message' => "Volunteer {$name} registered successfully."]);
    } else {
        if ($vol_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid volunteer ID.'], 400);
        }
        $new_status = sanitize($input['status'] ?? 'active');
        $pdo->prepare("UPDATE volunteers SET status = ? WHERE id = ?")->execute([$new_status, $vol_id]);
        json_response(['status' => 'success', 'message' => "Volunteer status updated to {$new_status}."]);
    }
}
