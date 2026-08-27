<?php
// api/admin/users.php — Mobile Console Admin Users REST API (Super Admin Only)
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
require_api_role(['super_admin'], $user);

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Admin Users ───────────────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT id, name, email, role, totp_enabled, preferred_2fa, created_at FROM users WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR role LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    json_response(['status' => 'success', 'count' => count($users), 'users' => $users]);
}

// ── POST: Add, Edit, or Delete Admin User ──────────────────────────────────────
elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $user_id = (int)($input['id'] ?? 0);

    if ($action === 'delete') {
        if ($user_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid user ID.'], 400);
        }
        if ($user_id === (int)$user['id']) {
            json_response(['status' => 'error', 'message' => 'You cannot delete your own active admin session.'], 400);
        }
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        json_response(['status' => 'success', 'message' => 'Admin user deleted successfully.']);
    } else {
        $name = sanitize($input['name'] ?? $_POST['name'] ?? '');
        $email = sanitize($input['email'] ?? $_POST['email'] ?? '');
        $role = sanitize($input['role'] ?? $_POST['role'] ?? 'viewer');
        $password = $input['password'] ?? $_POST['password'] ?? '';

        if (empty($name) || empty($email)) {
            json_response(['status' => 'error', 'message' => 'Please provide Full Name and Email address.'], 400);
        }

        if ($user_id > 0) {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, password = ? WHERE id = ?")->execute([$name, $email, $role, $hash, $user_id]);
            } else {
                $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?")->execute([$name, $email, $role, $user_id]);
            }
            json_response(['status' => 'success', 'message' => "Admin user '{$name}' updated successfully."]);
        } else {
            if (empty($password)) {
                json_response(['status' => 'error', 'message' => 'Password is required for new administrative accounts.'], 400);
            }
            // Check if email already registered
            $stmt_chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_chk->execute([$email]);
            if ($stmt_chk->fetch()) {
                json_response(['status' => 'error', 'message' => 'This email address is already registered.'], 400);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())")->execute([$name, $email, $hash, $role]);
            json_response(['status' => 'success', 'message' => "Admin user '{$name}' registered successfully."]);
        }
    }
}

// ── DELETE: Delete Admin User ─────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    $user_id = (int)($_GET['id'] ?? 0);
    if ($user_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid user ID.'], 400);
    }
    if ($user_id === (int)$user['id']) {
        json_response(['status' => 'error', 'message' => 'You cannot delete your own active admin session.'], 400);
    }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
    json_response(['status' => 'success', 'message' => 'Admin user deleted successfully.']);
}
