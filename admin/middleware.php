<?php
// api/admin/middleware.php — Mobile REST API Auth & CORS Middleware
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle CORS Preflight
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
    if (file_exists(__DIR__ . '/../functions.php')) {
        require_once __DIR__ . '/../functions.php';
    }
} elseif (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
    if (file_exists(__DIR__ . '/../includes/functions.php')) {
        require_once __DIR__ . '/../includes/functions.php';
    }
} elseif (file_exists(__DIR__ . '/../../includes/db.php')) {
    require_once __DIR__ . '/../../includes/db.php';
    if (file_exists(__DIR__ . '/../../includes/functions.php')) {
        require_once __DIR__ . '/../../includes/functions.php';
    }
}

/**
 * Send JSON Response and terminate script execution
 */
function json_response($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Extract Bearer token from Authorization HTTP Header
 */
function get_bearer_token(): ?string {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    if (!empty($headers) && preg_match('/Bearer\s+(\S+)/i', $headers, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Verify Mobile Bearer Token and return user array
 */
function verify_mobile_token(PDO $pdo): array {
    $token = get_bearer_token();
    if (empty($token)) {
        json_response([
            'status'  => 'error',
            'message' => 'Missing Authorization Bearer token.'
        ], 401);
    }

    $token_hash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role, u.totp_enabled, u.preferred_2fa, t.id as token_id, t.expires_at
        FROM api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > CURRENT_TIMESTAMP
    ");
    $stmt->execute([$token_hash]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response([
            'status'  => 'error',
            'message' => 'Invalid or expired Authorization token. Please log in again.'
        ], 401);
    }

    return $user;
}

/**
 * Require minimum user role permission for API route
 */
function require_api_role(array $allowed_roles, array $user): void {
    if (!in_array($user['role'], $allowed_roles)) {
        json_response([
            'status'  => 'error',
            'message' => 'Forbidden: Your user role (' . $user['role'] . ') does not have permission for this action.'
        ], 403);
    }
}

/**
 * Issue new Bearer Access Token for User ID
 */
function generate_api_token(PDO $pdo, int $user_id, string $device_name = 'Android App'): string {
    $raw_token = bin2hex(random_bytes(32)); // 64 chars
    $token_hash = hash('sha256', $raw_token);
    $expires_at = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days validity

    $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $token_hash, $device_name, $expires_at]);

    return $raw_token;
}
