<?php
// api/admin/partners.php — Mobile Partners & Supporters REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $partners = $pdo->query("SELECT * FROM partners ORDER BY id DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($partners), 'partners' => $partners]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $partner_id = (int)($input['id'] ?? 0);
    $name = sanitize($input['name'] ?? '');
    $logo_url = sanitize($input['logo_url'] ?? '');
    $website = sanitize($input['website'] ?? '');

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../assets/images/partners/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $file_name = 'partner_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
            $logo_url = "/assets/images/partners/{$file_name}";
        }
    }

    if ($partner_id > 0) {
        $pdo->prepare("UPDATE partners SET name = ?, logo_url = ?, website = ? WHERE id = ?")->execute([$name, $logo_url, $website, $partner_id]);
        json_response(['status' => 'success', 'message' => 'Partner updated.']);
    } else {
        $pdo->prepare("INSERT INTO partners (name, logo_url, website) VALUES (?, ?, ?)")->execute([$name, $logo_url, $website]);
        json_response(['status' => 'success', 'message' => 'Partner added.']);
    }
} elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $partner_id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$partner_id]);
    json_response(['status' => 'success', 'message' => 'Partner removed.']);
}
