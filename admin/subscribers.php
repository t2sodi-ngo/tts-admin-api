<?php
// api/admin/subscribers.php — Mobile Subscribers & Email Broadcast REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $subscribers = $pdo->query("SELECT * FROM subscribers ORDER BY subscribed_at DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($subscribers), 'subscribers' => $subscribers]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    $subject = sanitize($input['subject'] ?? '');
    $body_content = $input['body'] ?? '';

    if (empty($subject) || empty($body_content)) {
        json_response(['status' => 'error', 'message' => 'Subject and email body are required.'], 400);
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

    json_response(['status' => 'success', 'message' => "Newsletter broadcast spooled for {$sent_count} active subscriber(s)."]);
}
