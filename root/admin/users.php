<?php
// api/admin/users.php — Mobile Console Admin Users REST API (Super Admin Only)
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
require_api_role(['super_admin'], $user);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $users = $pdo->query("SELECT id, name, email, role, totp_enabled, preferred_2fa, created_at FROM users ORDER BY id ASC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($users), 'users' => $users]);
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $user_id = (int)($input['id'] ?? 0);
    $name = sanitize($input['name'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $role = sanitize($input['role'] ?? 'viewer');
    $password = $input['password'] ?? '';

    if (empty($name) || empty($email)) {
        json_response(['status' => 'error', 'message' => 'Please provide name and email.'], 400);
    }

    if ($user_id > 0) {
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, password = ? WHERE id = ?")->execute([$name, $email, $role, $hash, $user_id]);
        } else {
            $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?")->execute([$name, $email, $role, $user_id]);
        }
        json_response(['status' => 'success', 'message' => "Admin user '{$name}' updated."]);
    } else {
        if (empty($password)) {
            json_response(['status' => 'error', 'message' => 'Password is required for new accounts.'], 400);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)")->execute([$name, $email, $hash, $role]);
        json_response(['status' => 'success', 'message' => "Admin user '{$name}' created."]);
    }
}
