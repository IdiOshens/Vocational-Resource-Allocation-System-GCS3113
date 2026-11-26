<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);


header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Respond to CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/functions.php';

/**
 * Sends a JSON response and stops execution.
 *
 * @param bool   $success
 * @param string $message
 * @param mixed  $data
 * @param int    $httpCode
 */
function send_response($success, $message, $data = null, $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data'    => $data
    ]);
    exit;
}

function get_json_input()
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function get_bearer_token()
{
    // Apache sometimes exposes headers under different keys
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($header === '') {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function require_authentication()
{
    $token = get_bearer_token();

    if ($token === null) {
        send_response(false, 'Authentication required.', null, 401);
    }

    $result = validate_api_token($token);

    if (!$result['success']) {
        send_response(false, $result['message'] ?? 'Invalid or expired token.', null, 401);
    }

    return $result['user'];
}

function require_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_response(false, 'Method not allowed. Use POST.', null, 405);
    }
}

function require_get()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_response(false, 'Method not allowed. Use GET.', null, 405);
    }
}

$action = $_GET['action'] ?? '';

switch ($action) {

    // REGISTER — POST apis.php?action=register
    case 'register':
        require_post();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        // Basic presence check — real validation happens in functions.php
        $required = ['full_name', 'email', 'username', 'password', 'role'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                send_response(false, "Field '$field' is required.", null, 422);
            }
        }

        $result = register_user($input);

        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }

        send_response(true, $result['message'], [
            'user_id'        => $result['user_id'] ?? null,
            'account_status' => $result['account_status'] ?? null
        ], 201);
        break;


    case 'login':
        require_post();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        // Accept 'identifier' or fall back to 'email'
        $identifier = trim($input['identifier'] ?? $input['email'] ?? '');
        $password   = $input['password'] ?? '';

        if ($identifier === '' || $password === '') {
            send_response(false, 'Identifier and password are required.', null, 422);
        }

        $result = user_login($identifier, $password);

        if (!$result['success']) {
            send_response(false, $result['message'], null, 401);
        }

        send_response(true, $result['message'], [
            'user'  => $result['user'],
            'token' => $result['token']
        ]);
        break;

    case 'forgot-password':
        require_post();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $email = trim($input['email'] ?? '');
        if ($email === '') {
            send_response(false, 'Email is required.', null, 422);
        }

        $result = forgot_password($email);

        // forgot_password() already returns a generic message.
        $data = null;

        // Remove this block in production.
        if (!empty($result['token'])) {
            $data = ['token' => $result['token']];
        }

        send_response(true, $result['message'], $data);
        break;

    case 'verify-reset-token':
        require_post();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $token = trim($input['token'] ?? '');
        if ($token === '') {
            send_response(false, 'Reset token is required.', null, 422);
        }

        $result = verify_reset_token($token);

        if (!$result['success']) {
            send_response(false, $result['message'], null, 400);
        }

        send_response(true, 'Reset token is valid.', [
            'user_id' => $result['user_id']
        ]);
        break;

    case 'reset-password':
        require_post();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $token           = trim($input['token'] ?? '');
        $newPassword     = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        if ($token === '' || $newPassword === '' || $confirmPassword === '') {
            send_response(false, 'Token, new password, and confirmation are required.', null, 422);
        }

        $result = reset_password($token, $newPassword, $confirmPassword);

        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }

        send_response(true, $result['message']);
        break;

    case 'change-password':
        require_post();
        $user = require_authentication();

        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $currentPassword = $input['current_password'] ?? '';
        $newPassword     = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            send_response(false, 'All password fields are required.', null, 422);
        }

        // Use the authenticated user's ID — never trust a client-supplied user_id
        $result = change_password(
            $user['user_id'],
            $currentPassword,
            $newPassword,
            $confirmPassword
        );

        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }

        send_response(true, $result['message']);
        break;


    case 'logout':
        require_post();
        $token = get_bearer_token();

        if ($token === null) {
            send_response(false, 'Authentication required.', null, 401);
        }

        $result = logout_user($token);

        if (!$result['success']) {
            send_response(false, $result['message'], null, 400);
        }

        send_response(true, $result['message']);
        break;

    case 'session-check':
        require_get();
        $user = require_authentication();
        send_response(true, 'Session is valid', ['user' => $user]);
        break;

    case 'my-profile':
        require_get();
        $user = require_authentication();
        $result = get_user_profile($user['user_id']);
        if (!$result['success']) {
            $code = $result['message'] === 'User not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, 'Profile retrieved', $result['data']);
        break;

    case 'update-my-profile':
        require_post();
        $user = require_authentication();
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = update_my_profile($user['user_id'], $input);
        if (!$result['success']) {
            $code = $result['message'] === 'User not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'upload-avatar':
        require_post();
        $user = require_authentication();
        $file = $_FILES['avatar'] ?? $_FILES['image'] ?? $_FILES['file'] ?? null;
        if (!$file) {
            send_response(false, 'No image file uploaded. Expected form field: avatar, image, or file.', null, 400);
        }
        $result = upload_user_avatar($user['user_id'], $file);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], $result['data']);
        break;

    case 'upload-resource-image':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $file = $_FILES['image'] ?? $_FILES['file'] ?? null;
        if (!$file) {
            send_response(false, 'No image file uploaded. Expected form field: image or file.', null, 400);
        }
        $result = upload_resource_image(
            $_POST['resource_type'] ?? '',
            $_POST['resource_id'] ?? null,
            $file
        );
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], $result['data']);
        break;

    case 'departments':
        require_get();
        $result = get_departments();
        send_response($result['success'], 'Departments retrieved', $result['data']);
        break;

    case 'courses':
        require_get();
        $departmentId = isset($_GET['department_id']) && is_numeric($_GET['department_id'])
            ? (int)$_GET['department_id'] : null;
        $result = get_courses($departmentId);
        send_response($result['success'], 'Courses retrieved', $result['data']);
        break;

    case 'classrooms':
        require_get();
        $user = require_authentication();

        $filters = [];
        if (!empty($_GET['building']))      $filters['building']     = $_GET['building'];
        if (!empty($_GET['room_type']))     $filters['room_type']    = $_GET['room_type'];
        if (!empty($_GET['min_capacity']))  $filters['min_capacity'] = $_GET['min_capacity'];
        if (!empty($_GET['status']))        $filters['status']       = $_GET['status'];
        if (in_array($user['role'], ['student', 'instructor'], true)) {
            $filters['user_department_id'] = get_user_department_id(
                (int)$user['user_id'],
                $user['role']
            );
        }

        $result = get_classrooms($filters);
        send_response($result['success'], 'Classrooms retrieved', $result['data']);
        break;


    case 'classroom':
        require_get();
        require_authentication();

        $id = $_GET['id'] ?? null;
        if (!$id) {
            send_response(false, 'Classroom ID is required', null, 422);
        }

        $result = get_classroom($id);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 404);
        }
        send_response(true, 'Classroom retrieved', $result['data']);
        break;


    case 'equipment':
        require_get();
        $user = require_authentication();

        $filters = [];
        if (!empty($_GET['equipment_type'])) $filters['equipment_type'] = $_GET['equipment_type'];
        if (!empty($_GET['status']))         $filters['status']         = $_GET['status'];
        if (!empty($_GET['department_id']))  $filters['department_id']  = $_GET['department_id'];
        if (in_array($user['role'], ['student', 'instructor'], true)) {
            $filters['user_department_id'] = get_user_department_id(
                (int)$user['user_id'],
                $user['role']
            );
        }

        $result = get_equipment($filters);
        send_response($result['success'], 'Equipment retrieved', $result['data']);
        break;


    case 'equipment-item':
        require_get();
        require_authentication();

        $id = $_GET['id'] ?? null;
        if (!$id) {
            send_response(false, 'Equipment ID is required', null, 422);
        }

        $result = get_equipment_item($id);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 404);
        }
        send_response(true, 'Equipment retrieved', $result['data']);
        break;


    case 'create-booking':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $required = [
            'resource_type',
            'resource_id',
            'allocation_date',
            'start_time',
            'end_time',
            'purpose'
        ];
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                send_response(false, "Field '$field' is required.", null, 422);
            }
        }

        // Note: user_id and user_role come from the token, not the request body.
        $result = create_allocation($input, $user['user_id'], $user['role']);

        if (!$result['success']) {
            // Distinguish "not found" from general validation failures
            $msg = $result['message'];
            $code = (stripos($msg, 'not found') !== false) ? 404 : 422;
            send_response(false, $msg, null, $code);
        }

        send_response(true, $result['message'], [
            'allocation_id' => $result['allocation_id'],
            'status'        => $result['status'],
        ], 201);
        break;


    case 'cancel-booking':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $allocationId = $input['allocation_id'] ?? null;
        $reason       = isset($input['reason']) ? trim((string)$input['reason']) : '';

        if (!$allocationId || !is_numeric($allocationId)) {
            send_response(false, 'A valid allocation_id is required.', null, 422);
        }

        $result = cancel_allocation(
            (int)$allocationId,
            $user['user_id'],
            $user['role'],
            $reason
        );

        if (!$result['success']) {
            $msg = $result['message'];
            if (stripos($msg, 'not found') !== false) {
                send_response(false, $msg, null, 404);
            }
            if (stripos($msg, 'own booking') !== false) {
                send_response(false, $msg, null, 403);
            }
            send_response(false, $msg, null, 422);
        }

        send_response(true, $result['message']);
        break;


    case 'my-bookings':
        require_get();
        $user = require_authentication();

        $result = get_user_allocations($user['user_id']);
        send_response(true, 'Bookings retrieved', $result['data']);
        break;

    case 'all-bookings':
        require_get();
        $user = require_authentication();

        if (!in_array($user['role'], ['admin', 'department_head'], true)) {
            send_response(false, 'Administrative access required.', null, 403);
        }

        $result = get_all_allocations();
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'Bookings retrieved', $result['data']);
        break;


    case 'booking':
        require_get();
        $user = require_authentication();

        $id = $_GET['id'] ?? null;
        if (!$id || !is_numeric($id)) {
            send_response(false, 'A valid booking id is required.', null, 422);
        }

        $result = get_booking((int)$id, $user['user_id'], $user['role']);

        if (!$result['success']) {
            $msg = $result['message'];
            $code = (stripos($msg, 'not found') !== false) ? 404 : 403;
            send_response(false, $msg, null, $code);
        }

        send_response(true, 'Booking retrieved', $result['data']);
        break;

    case 'pending-bookings':
        require_get();
        $user = require_authentication();

        if ($user['role'] !== 'admin') {
            send_response(false, 'Only admins can view pending bookings.', null, 403);
        }

        $result = get_pending_allocations();
        send_response(true, 'Pending bookings retrieved', $result['data']);
        break;


    case 'approve-booking':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $allocationId = $input['allocation_id'] ?? null;
        if (!$allocationId || !is_numeric($allocationId)) {
            send_response(false, 'A valid allocation_id is required.', null, 422);
        }

        $result = approve_allocation(
            (int)$allocationId,
            $user['user_id'],
            $user['role']
        );

        if (!$result['success']) {
            $msg  = $result['message'];
            $code = (stripos($msg, 'not found') !== false) ? 404 : 403;
            send_response(false, $msg, null, $code);
        }

        send_response(true, $result['message']);
        break;


    case 'reject-booking':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $allocationId = $input['allocation_id'] ?? null;
        $notes        = isset($input['notes']) ? trim((string)$input['notes']) : '';

        if (!$allocationId || !is_numeric($allocationId)) {
            send_response(false, 'A valid allocation_id is required.', null, 422);
        }

        $result = reject_allocation(
            (int)$allocationId,
            $user['user_id'],
            $user['role'],
            $notes
        );

        if (!$result['success']) {
            $msg  = $result['message'];
            $code = (stripos($msg, 'not found') !== false) ? 404 : 403;
            send_response(false, $msg, null, $code);
        }

        send_response(true, $result['message']);
        break;


    case 'cancel-approved-booking':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $allocationId = $input['allocation_id'] ?? null;
        $reason       = isset($input['reason']) ? trim((string)$input['reason']) : '';

        if (!$allocationId || !is_numeric($allocationId)) {
            send_response(false, 'A valid allocation_id is required.', null, 422);
        }

        $result = cancel_approved_booking(
            (int)$allocationId,
            $user['user_id'],
            $user['role'],
            $reason
        );

        if (!$result['success']) {
            $msg = $result['message'];
            if (stripos($msg, 'not found') !== false) {
                send_response(false, $msg, null, 404);
            }
            if (stripos($msg, 'own booking') !== false) {
                send_response(false, $msg, null, 403);
            }
            send_response(false, $msg, null, 422);
        }

        send_response(true, $result['message']);
        break;


    case 'check-in':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $allocationId = $input['allocation_id'] ?? null;
        if (!$allocationId || !is_numeric($allocationId)) {
            send_response(false, 'A valid allocation_id is required.', null, 422);
        }

        $result = check_in_allocation((int)$allocationId, $user['user_id']);

        if (!$result['success']) {
            $msg = $result['message'];
            if (stripos($msg, 'not found') !== false) {
                send_response(false, $msg, null, 404);
            }
            if (stripos($msg, 'own booking') !== false) {
                send_response(false, $msg, null, 403);
            }
            send_response(false, $msg, null, 422);
        }

        send_response(true, $result['message']);
        break;


    case 'check-out':
        require_post();
        $user  = require_authentication();
        $input = get_json_input();

        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }

        $allocationId = $input['allocation_id'] ?? null;
        if (!$allocationId || !is_numeric($allocationId)) {
            send_response(false, 'A valid allocation_id is required.', null, 422);
        }

        $result = check_out_allocation((int)$allocationId, $user['user_id']);

        if (!$result['success']) {
            $msg = $result['message'];
            if (stripos($msg, 'not found') !== false) {
                send_response(false, $msg, null, 404);
            }
            if (stripos($msg, 'own booking') !== false) {
                send_response(false, $msg, null, 403);
            }
            send_response(false, $msg, null, 422);
        }

        send_response(true, $result['message']);
        break;

    case 'my-notifications':
        require_get();
        $user = require_authentication();
        $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
        $result = get_user_notifications($user['user_id'], $unreadOnly);
        send_response(true, 'Notifications retrieved', $result['data']);
        break;

    case 'notification-count':
        require_get();
        $user = require_authentication();
        $result = get_unread_notification_count($user['user_id']);
        send_response(true, 'Unread count retrieved', ['count' => $result['count']]);
        break;

    case 'mark-notification-read':
        require_post();
        $user = require_authentication();
        $input = get_json_input();
        if ($input === null || empty($input['notification_id']) || !is_numeric($input['notification_id'])) {
            send_response(false, 'A valid notification_id is required.', null, 422);
        }
        $result = mark_notification_read((int)$input['notification_id'], $user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 404);
        }
        send_response(true, $result['message']);
        break;

    case 'mark-all-notifications-read':
        require_post();
        $user = require_authentication();
        $result = mark_all_notifications_read($user['user_id']);
        send_response(true, 'All notifications marked as read', ['affected' => $result['affected']]);
        break;

    case 'admin-dashboard':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $result = get_admin_dashboard_stats();
        send_response(true, 'Admin stats retrieved', $result['data']);
        break;

    case 'instructor-dashboard':
        require_get();
        $user = require_authentication();
        $result = get_instructor_dashboard_stats($user['user_id']);
        send_response(true, 'Instructor stats retrieved', $result['data']);
        break;

    case 'department-head-dashboard':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'department_head') {
            send_response(false, 'Department head access required', null, 403);
        }
        $result = get_department_head_stats($user['department_id']);
        send_response(true, 'Department head stats retrieved', $result['data']);
        break;

    case 'instructor-students':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'instructor') {
            send_response(false, 'Instructor access required', null, 403);
        }
        $result = get_instructor_students($user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, 'Instructor students retrieved', $result['data']);
        break;

    case 'department-head-stats':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'department_head') {
            send_response(false, 'Department head access required', null, 403);
        }
        $result = get_department_head_stats($user['department_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, 'Department statistics retrieved', [
            'total_students' => $result['data']['total_students'],
            'total_instructors' => $result['data']['total_instructors'],
            'total_staff' => $result['data']['total_staff'],
        ]);
        break;

    case 'admin-analytics':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $result = get_admin_analytics();
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'Admin analytics retrieved', $result['data']);
        break;

    case 'all-students':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $result = admin_get_students();
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'Students retrieved', $result['data']);
        break;

    case 'all-instructors':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $result = admin_get_instructors();
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'Instructors retrieved', $result['data']);
        break;

    case 'all-staff':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $result = admin_get_staff();
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'Staff retrieved', $result['data']);
        break;

    case 'all-admins':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $result = admin_get_admins();
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'Admins retrieved', $result['data']);
        break;

    case 'admin-users':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        foreach (['role', 'status', 'search'] as $filter) {
            if (isset($_GET[$filter]) && !is_string($_GET[$filter])) {
                send_response(false, "Invalid $filter filter.", null, 422);
            }
        }
        $result = get_all_users_admin(
            $_GET['role'] ?? null,
            $_GET['status'] ?? null,
            $_GET['search'] ?? null
        );
        send_response(true, 'Users retrieved', $result['data']);
        break;

    case 'admin-user':
        require_get();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $userId = $_GET['id'] ?? null;
        if ($userId === null || !is_numeric($userId) || (int)$userId <= 0) {
            send_response(false, 'A valid user ID is required.', null, 422);
        }
        $result = get_admin_user_details((int)$userId);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 404);
        }
        send_response(true, 'User retrieved', $result['data']);
        break;

    case 'admin-update-user-status':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['user_id']) ||
            !is_numeric($input['user_id']) ||
            (int)$input['user_id'] <= 0 ||
            !isset($input['status']) ||
            !is_string($input['status']) ||
            !in_array($input['status'], ['active', 'rejected', 'suspended', 'pending'], true)
        ) {
            send_response(false, 'A valid user_id and status are required.', null, 422);
        }
        $result = update_user_status((int)$input['user_id'], (string)$input['status']);
        if (!$result['success']) {
            $code = $result['message'] === 'User not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'admin-create-user':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = admin_create_user($input);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        $statusCode = ($input['role'] ?? '') === 'department_head' ? 200 : 201;
        send_response(true, $result['message'], ['user_id' => $result['user_id']], $statusCode);
        break;

    case 'admin-update-user':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['user_id']) ||
            !is_numeric($input['user_id']) ||
            (int)$input['user_id'] <= 0
        ) {
            send_response(false, 'A valid user_id is required.', null, 422);
        }
        $result = admin_update_user($input);
        if (!$result['success']) {
            $code = $result['message'] === 'User not found.' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'admin-create-classroom':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = admin_create_classroom($input, $user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], ['classroom_id' => $result['classroom_id']], 201);
        break;

    case 'admin-update-classroom':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['classroom_id']) ||
            !is_numeric($input['classroom_id'])
        ) {
            send_response(false, 'A valid classroom_id is required.', null, 422);
        }
        $result = admin_update_classroom((int)$input['classroom_id'], $input, $user['user_id']);
        if (!$result['success']) {
            $code = $result['message'] === 'Classroom not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'admin-delete-classroom':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['classroom_id']) ||
            !is_numeric($input['classroom_id'])
        ) {
            send_response(false, 'A valid classroom_id is required.', null, 422);
        }
        $result = admin_delete_classroom((int)$input['classroom_id']);
        if (!$result['success']) {
            $code = $result['message'] === 'Classroom not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'admin-create-equipment':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = admin_create_equipment($input, $user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], ['equipment_id' => $result['equipment_id']], 201);
        break;

    case 'admin-update-equipment':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['equipment_id']) ||
            !is_numeric($input['equipment_id'])
        ) {
            send_response(false, 'A valid equipment_id is required.', null, 422);
        }
        $result = admin_update_equipment((int)$input['equipment_id'], $input, $user['user_id']);
        if (!$result['success']) {
            $code = $result['message'] === 'Equipment not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'admin-delete-equipment':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['equipment_id']) ||
            !is_numeric($input['equipment_id'])
        ) {
            send_response(false, 'A valid equipment_id is required.', null, 422);
        }
        $result = admin_delete_equipment((int)$input['equipment_id']);
        if (!$result['success']) {
            $code = $result['message'] === 'Equipment not found' ? 404 : 422;
            send_response(false, $result['message'], null, $code);
        }
        send_response(true, $result['message']);
        break;

    case 'admin-create-student':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = admin_create_student($input, $user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], $result['data'], 201);
        break;

    case 'admin-students':
        require_get();
        $user = require_authentication();
        if (!in_array($user['role'], ['admin', 'department_head', 'instructor'], true)) {
            send_response(false, 'Access denied', null, 403);
        }
        $result = admin_get_students();
        send_response($result['success'], 'Students retrieved', $result['data']);
        break;

    case 'admin-create-course':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = admin_create_course($input, $user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], $result['data'], 201);
        break;

    case 'admin-update-course':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null || !isset($input['course_id']) || !is_numeric($input['course_id'])) {
            send_response(false, 'A valid course_id is required.', null, 422);
        }
        $result = admin_update_course((int)$input['course_id'], $input, $user['user_id']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message']);
        break;

    case 'reschedule-booking':
        require_post();
        $user = require_authentication();
        $input = get_json_input();
        if (
            $input === null ||
            !isset($input['allocation_id']) ||
            !is_numeric($input['allocation_id']) ||
            !isset($input['allocation_date']) ||
            !is_string($input['allocation_date']) ||
            !isset($input['start_time']) ||
            !is_string($input['start_time']) ||
            !isset($input['end_time']) ||
            !is_string($input['end_time'])
        ) {
            send_response(false, 'A valid allocation_id, allocation_date, start_time, and end_time are required.', null, 422);
        }

        $result = reschedule_allocation(
            (int)$input['allocation_id'],
            $user['user_id'],
            $user['role'],
            $input['allocation_date'],
            $input['start_time'],
            $input['end_time']
        );
        if (!$result['success']) {
            $message = $result['message'];
            $statusCode = stripos($message, 'not found') !== false
                ? 404
                : (stripos($message, 'only reschedule your own') !== false ? 403 : 422);
            send_response(false, $message, null, $statusCode);
        }
        send_response(true, $result['message'], ['status' => $result['status']]);
        break;

    case 'send-support':
        require_post();
        $user = require_authentication();
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid or empty JSON body.', null, 400);
        }
        $result = send_support_message($user['user_id'], $input['subject'] ?? '', $input['message'] ?? '');
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message'], $result['data'] ?? null, 201);
        break;

    case 'support-messages':
        require_get();
        $user = require_authentication();
        if ($user['role'] === 'admin') {
            $result = get_support_messages();
        } else {
            $result = get_support_messages($user['user_id']);
        }
        if (!$result['success']) {
            send_response(false, $result['message'], null, 500);
        }
        send_response(true, 'OK', $result['data']);
        break;

    case 'reply-support':
        require_post();
        $user = require_authentication();
        if ($user['role'] !== 'admin') {
            send_response(false, 'Admin access required', null, 403);
        }
        $input = get_json_input();
        if ($input === null) {
            send_response(false, 'Invalid request. Provide message_id and reply.', null, 400);
        }
        $messageId = (int)($input['message_id'] ?? $input['id'] ?? 0);
        if ($messageId <= 0 || !isset($input['reply'])) {
            send_response(false, 'Invalid request. Provide message_id and reply.', null, 400);
        }
        $result = reply_support_message($messageId, $input['reply']);
        if (!$result['success']) {
            send_response(false, $result['message'], null, 422);
        }
        send_response(true, $result['message']);
        break;

    default:
        send_response(false, 'Unknown action.', [
            'available_actions' => [
                'register',
                'login',
                'forgot-password',
                'verify-reset-token',
                'reset-password',
                'change-password',
                'logout'
            ]
        ], 404);
        break;
}
