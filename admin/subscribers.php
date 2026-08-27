<?php
// api/admin/subscribers.php — Mobile Subscribers & Email Broadcast REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Subscribers List ───────────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $sql = "SELECT * FROM subscribers WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND email LIKE ?";
        $params[] = "%{$search}%";
    }

    $sql .= " ORDER BY subscribed_at DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subscribers = $stmt->fetchAll();

    json_response([
        'status' => 'success',
        'count' => count($subscribers),
        'subscribers' => $subscribers
    ]);
}

// ── POST: Add Subscriber, Toggle Status, Delete, or Dispatch Broadcast ────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'broadcast');

    if ($action === 'add_subscriber') {
        $email = strtolower(trim(sanitize($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['status' => 'error', 'message' => 'Valid email address is required.'], 400);
        }

        // Check if exists
        $stmt_check = $pdo->prepare("SELECT id FROM subscribers WHERE email = ?");
        $stmt_check->execute([$email]);
        if ($stmt_check->fetch()) {
            json_response(['status' => 'error', 'message' => 'This email is already in the subscriber registry.'], 400);
        }

        $stmt_ins = $pdo->prepare("INSERT INTO subscribers (email, status, subscribed_at) VALUES (?, 'active', NOW())");
        $stmt_ins->execute([$email]);

        json_response(['status' => 'success', 'message' => "Subscriber '{$email}' added to registry."]);
    }

    elseif ($action === 'toggle_status') {
        $sub_id = (int)($input['id'] ?? 0);
        if ($sub_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid subscriber ID.'], 400);
        }

        $stmt_cur = $pdo->prepare("SELECT status FROM subscribers WHERE id = ?");
        $stmt_cur->execute([$sub_id]);
        $curr = $stmt_cur->fetchColumn();

        $new_status = ($curr === 'active') ? 'unsubscribed' : 'active';
        $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$new_status, $sub_id]);

        json_response(['status' => 'success', 'message' => "Subscriber status updated to '{$new_status}'."]);
    }

    elseif ($action === 'delete') {
        $sub_id = (int)($input['id'] ?? 0);
        if ($sub_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid subscriber ID.'], 400);
        }
        $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$sub_id]);
        json_response(['status' => 'success', 'message' => 'Subscriber removed from registry.']);
    }

    elseif ($action === 'broadcast') {
        $subject = sanitize($input['subject'] ?? '');
        $body_content = $input['body'] ?? '';

        if (empty($subject) || empty($body_content)) {
            json_response(['status' => 'error', 'message' => 'Subject and message body are required for broadcast.'], 400);
        }

        $subscribers = $pdo->query("SELECT email FROM subscribers WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($subscribers)) {
            json_response(['status' => 'error', 'message' => 'No active subscribers found in mailing list.'], 400);
        }

        $sent_count = 0;
        foreach ($subscribers as $sub_email) {
            spool_mail($sub_email, $subject, $body_content);
            $sent_count++;
        }

        json_response(['status' => 'success', 'message' => "Newsletter broadcast dispatched to {$sent_count} active subscriber(s)."]);
    }
}

// ── DELETE: Delete Subscriber ─────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $sub_id = (int)($_GET['id'] ?? 0);
    if ($sub_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid subscriber ID.'], 400);
    }
    $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$sub_id]);
    json_response(['status' => 'success', 'message' => 'Subscriber removed from registry.']);
}
