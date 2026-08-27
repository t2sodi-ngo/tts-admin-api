<?php
// api/admin/certificates.php — Mobile Certificates Generator REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Issued Certificates ───────────────────────────────────────────
if ($method === 'GET') {
    $search = sanitize($_GET['search'] ?? '');
    $type   = sanitize($_GET['type'] ?? 'all');

    $sql = "SELECT * FROM certificates WHERE 1=1";
    $params = [];

    if ($type !== 'all' && !empty($type)) {
        $sql .= " AND LOWER(type) = ?";
        $params[] = strtolower($type);
    }

    if (!empty($search)) {
        $sql .= " AND (title LIKE ? OR recipient_name LIKE ? OR certificate_no LIKE ? OR type LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $certificates = $stmt->fetchAll();

    json_response([
        'status'       => 'success',
        'count'        => count($certificates),
        'certificates' => $certificates
    ]);
}

// ── POST: Issue New Certificate or Delete ────────────────────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'issue');
    $cert_id = (int)($input['id'] ?? $_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($cert_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid certificate ID.'], 400);
        }
        $pdo->prepare("DELETE FROM certificates WHERE id = ?")->execute([$cert_id]);
        json_response(['status' => 'success', 'message' => 'Certificate record deleted successfully.']);
    }

    else {
        $title            = sanitize($_POST['title'] ?? $input['title'] ?? 'Certificate of Recognition');
        $recipient_name   = sanitize($_POST['recipient_name'] ?? $input['recipient_name'] ?? '');
        $recipient_email  = sanitize($_POST['recipient_email'] ?? $input['recipient_email'] ?? '');
        $certificate_type = sanitize($_POST['type'] ?? $input['type'] ?? 'Volunteer Appreciation');
        $issue_date       = sanitize($_POST['issue_date'] ?? $input['issue_date'] ?? date('Y-m-d'));
        $file_path        = sanitize($_POST['file_path'] ?? $input['file_path'] ?? '');

        if (empty($recipient_name) && empty($title)) {
            json_response(['status' => 'error', 'message' => 'Certificate title or recipient name required.'], 400);
        }

        // Handle File Upload (Scan Photo or PDF)
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/certificates/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $file_name = 'cert_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                $file_path = "/uploads/certificates/{$file_name}";
            }
        }

        if ($cert_id > 0) {
            $sql_upd = "UPDATE certificates SET title = ?, recipient_name = ?, recipient_email = ?, type = ?, issue_date = ?";
            $params_upd = [$title, $recipient_name, $recipient_email, $certificate_type, $issue_date];

            if (!empty($file_path)) {
                $sql_upd .= ", file_path = ?";
                $params_upd[] = $file_path;
            }

            $sql_upd .= " WHERE id = ?";
            $params_upd[] = $cert_id;

            $pdo->prepare($sql_upd)->execute($params_upd);
            json_response(['status' => 'success', 'message' => 'Certificate details updated successfully.']);
        } else {
            $cert_no = 'TTS-CERT-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $stmt = $pdo->prepare("INSERT INTO certificates (certificate_no, title, recipient_name, recipient_email, type, issue_date, file_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cert_no, $title, $recipient_name, $recipient_email, $certificate_type, $issue_date, $file_path]);

            json_response([
                'status'         => 'success',
                'message'        => "Certificate {$cert_no} issued successfully.",
                'certificate_no' => $cert_no
            ]);
        }
    }
}

// ── DELETE: Delete Certificate ───────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $cert_id = (int)($_GET['id'] ?? 0);
    if ($cert_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid certificate ID.'], 400);
    }
    $pdo->prepare("DELETE FROM certificates WHERE id = ?")->execute([$cert_id]);
    json_response(['status' => 'success', 'message' => 'Certificate deleted successfully.']);
}
