<?php
// api/admin/blog.php — Mobile Blog Posts REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $posts = $pdo->query("SELECT * FROM blog_posts ORDER BY created_at DESC")->fetchAll();
    json_response(['status' => 'success', 'count' => count($posts), 'posts' => $posts]);
} elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $post_id = (int)($input['id'] ?? 0);
    $title = sanitize($input['title'] ?? '');
    $content = $input['content'] ?? '';
    $author = sanitize($input['author'] ?? $user['name']);

    if (empty($title) || empty($content)) {
        json_response(['status' => 'error', 'message' => 'Please provide blog title and content.'], 400);
    }

    if ($post_id > 0) {
        $pdo->prepare("UPDATE blog_posts SET title = ?, content = ?, author = ? WHERE id = ?")->execute([$title, $content, $author, $post_id]);
        json_response(['status' => 'success', 'message' => "Blog post updated."]);
    } else {
        $pdo->prepare("INSERT INTO blog_posts (title, content, author) VALUES (?, ?, ?)")->execute([$title, $content, $author]);
        json_response(['status' => 'success', 'message' => "Blog post published."]);
    }
}
