<?php
// api/admin/sync.php — Database Sync Webhook between Mobile App (Render) & Desktop Website (InfinityFree)

require_once __DIR__ . '/middleware.php';

// Secret Sync Key for security
define('SYNC_SECRET_KEY', 'TTS_SECRET_SYNC_KEY_2026');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$received_key = $input['sync_key'] ?? $_SERVER['HTTP_X_SYNC_KEY'] ?? '';

if ($received_key !== SYNC_SECRET_KEY) {
    json_response(['status' => 'error', 'message' => 'Unauthorized Sync Request.'], 401);
}

$action = $input['action'] ?? '';
$table = sanitize($input['table'] ?? '');
$data = $input['data'] ?? [];

if (empty($table) || empty($data)) {
    json_response(['status' => 'error', 'message' => 'Missing table or sync data.'], 400);
}

try {
    if ($action === 'insert') {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})");
        $stmt->execute(array_values($data));
        json_response(['status' => 'success', 'message' => "Record synced to {$table} on desktop website."]);

    } elseif ($action === 'update' && !empty($input['id'])) {
        $set_parts = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set_parts[] = "{$k} = ?";
            $params[] = $v;
        }
        $params[] = (int)$input['id'];
        $set_clause = implode(', ', $set_parts);
        $stmt = $pdo->prepare("UPDATE {$table} SET {$set_clause} WHERE id = ?");
        $stmt->execute($params);
        json_response(['status' => 'success', 'message' => "Record #{$input['id']} updated in {$table} on desktop website."]);

    } elseif ($action === 'delete' && !empty($input['id'])) {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->execute([(int)$input['id']]);
        json_response(['status' => 'success', 'message' => "Record #{$input['id']} deleted from {$table} on desktop website."]);
    } else {
        json_response(['status' => 'error', 'message' => 'Invalid sync action.'], 400);
    }
} catch (Exception $e) {
    json_response(['status' => 'error', 'message' => 'Sync failed: ' . $e->getMessage()], 500);
}

