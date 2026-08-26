<?php
// api/admin/testimonials.php — Mobile Beneficiary Testimonials REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $testimonials = $pdo->query("SELECT * FROM testimonials ORDER BY id DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($testimonials), 'testimonials' => $testimonials]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $t_id = (int)($input['id'] ?? 0);
    $name = sanitize($input['name'] ?? '');
    $role_desc = sanitize($input['role_desc'] ?? '');
    $quote = sanitize($input['quote'] ?? '');
    $is_approved = (int)($input['is_approved'] ?? 1);

    if ($t_id > 0) {
        $pdo->prepare("UPDATE testimonials SET name = ?, role_desc = ?, quote = ?, is_approved = ? WHERE id = ?")
            ->execute([$name, $role_desc, $quote, $is_approved, $t_id]);
        json_response(['status' => 'success', 'message' => 'Testimonial updated.']);
    } else {
        $pdo->prepare("INSERT INTO testimonials (name, role_desc, quote, is_approved) VALUES (?, ?, ?, ?)")
            ->execute([$name, $role_desc, $quote, $is_approved]);
        json_response(['status' => 'success', 'message' => 'Testimonial added.']);
    }
} elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $t_id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM testimonials WHERE id = ?")->execute([$t_id]);
    json_response(['status' => 'success', 'message' => 'Testimonial deleted.']);
}
