<?php
// api/admin/certificates.php — Mobile Certificates Generator REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $certificates = $pdo->query("SELECT * FROM certificates ORDER BY created_at DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($certificates), 'certificates' => $certificates]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    $recipient_name = sanitize($input['recipient_name'] ?? '');
    $recipient_email = sanitize($input['recipient_email'] ?? '');
    $certificate_type = sanitize($input['type'] ?? 'Volunteer Appreciation');
    $issue_date = sanitize($input['issue_date'] ?? date('Y-m-d'));
    
    if (empty($recipient_name)) {
        json_response(['status' => 'error', 'message' => 'Recipient name required.'], 400);
    }

    $cert_no = 'TTS-CERT-' . strtoupper(substr(md5(uniqid()), 0, 8));

    $pdo->prepare("INSERT INTO certificates (certificate_no, recipient_name, recipient_email, type, issue_date) VALUES (?, ?, ?, ?, ?)")
        ->execute([$cert_no, $recipient_name, $recipient_email, $certificate_type, $issue_date]);

    json_response(['status' => 'success', 'message' => "Certificate {$cert_no} issued successfully.", 'certificate_no' => $cert_no]);
}
