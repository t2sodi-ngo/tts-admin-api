<?php
// api/admin/events.php — Mobile Events Manager REST API
require_once __DIR__ . '/middleware.php';

$user = verify_mobile_token($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch Events List & Summary Metrics ─────────────────────────────────
if ($method === 'GET') {
    $filter = sanitize($_GET['filter'] ?? 'all');
    $search = sanitize($_GET['search'] ?? '');

    // Calculate Summary Metrics
    $total_events = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $upcoming_count = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE LOWER(status) = 'upcoming'")->fetchColumn();

    // Fetch Event Attendees / Registrations
    $registrations = [];
    try {
        $reg_stmt = $pdo->query("SELECT s.id, s.event_id, e.title as event_title, s.name, s.email, COALESCE(s.role, 'ATTENDEE') as role, s.created_at FROM event_signups s LEFT JOIN events e ON e.id = s.event_id ORDER BY s.id DESC LIMIT 50");
        if ($reg_stmt) {
            $registrations = $reg_stmt->fetchAll();
            $total_registrations = count($registrations);
        }
    } catch (Exception $e) {
        try {
            $reg_stmt = $pdo->query("SELECT s.id, s.event_id, e.title as event_title, s.name, s.email, COALESCE(s.role, 'ATTENDEE') as role, s.created_at FROM event_registrations s LEFT JOIN events e ON e.id = s.event_id ORDER BY s.id DESC LIMIT 50");
            if ($reg_stmt) {
                $registrations = $reg_stmt->fetchAll();
                $total_registrations = count($registrations);
            }
        } catch (Exception $e2) {
            $registrations = [];
        }
    }

    $sql = "SELECT * FROM events WHERE 1=1";
    $params = [];

    if ($filter === 'upcoming') {
        $sql .= " AND LOWER(status) = 'upcoming'";
    } elseif ($filter === 'past') {
        $sql .= " AND LOWER(status) = 'past'";
    }

    if (!empty($search)) {
        $sql .= " AND (title LIKE ? OR venue LIKE ? OR description LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $sql .= " ORDER BY event_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    json_response([
        'status'              => 'success',
        'total_events'        => $total_events,
        'upcoming_count'      => $upcoming_count,
        'total_registrations' => $total_registrations,
        'count'               => count($events),
        'events'              => $events,
        'registrations'       => $registrations
    ]);
}

// ── POST: Create, Edit, or Delete Event ──────────────────────────────────────
elseif ($method === 'POST') {
    require_api_role(['super_admin', 'editor'], $user);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = sanitize($input['action'] ?? 'save');
    $event_id = (int)($input['id'] ?? 0);

    if ($action === 'delete') {
        if ($event_id <= 0) {
            json_response(['status' => 'error', 'message' => 'Invalid event ID.'], 400);
        }
        $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
        json_response(['status' => 'success', 'message' => 'Event deleted successfully.']);
    } else {
        $title = sanitize($input['title'] ?? '');
        $description = sanitize($input['description'] ?? '');
        $event_date = sanitize($input['event_date'] ?? '');
        $venue = sanitize($input['venue'] ?? '');
        $image_url = sanitize($input['image_url'] ?? '');
        $status = sanitize($input['status'] ?? 'upcoming');

        // Handle File Upload if poster photo uploaded from phone camera
        if (isset($_FILES['poster']) && $_FILES['poster']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../assets/images/events/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['poster']['name'], PATHINFO_EXTENSION));
            $file_name = 'event_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['poster']['tmp_name'], $upload_dir . $file_name)) {
                $image_url = "/assets/images/events/{$file_name}";
            }
        }

        if (empty($title) || empty($event_date) || empty($venue)) {
            json_response(['status' => 'error', 'message' => 'Please provide event title, date, and venue.'], 400);
        }

        if ($event_id > 0) {
            // Update Event
            $stmt_upd = $pdo->prepare("UPDATE events SET title = ?, description = ?, event_date = ?, venue = ?, image_url = ?, status = ? WHERE id = ?");
            $stmt_upd->execute([$title, $description, $event_date, $venue, $image_url, $status, $event_id]);
            json_response(['status' => 'success', 'message' => "Event '{$title}' updated successfully."]);
        } else {
            // Insert Event
            $stmt_ins = $pdo->prepare("INSERT INTO events (title, description, event_date, venue, image_url, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$title, $description, $event_date, $venue, $image_url, $status]);
            json_response(['status' => 'success', 'message' => "Event '{$title}' published successfully."]);
        }
    }
}

// ── DELETE: Delete Event ─────────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    require_api_role(['super_admin'], $user);
    $event_id = (int)($_GET['id'] ?? 0);
    if ($event_id <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid event ID.'], 400);
    }
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
    json_response(['status' => 'success', 'message' => 'Event deleted successfully.']);
}
