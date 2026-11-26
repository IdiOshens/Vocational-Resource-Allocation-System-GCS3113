<?php
require_once __DIR__ . '/config/database.php';

// Token lifetime configuration
if (!defined('API_TOKEN_EXPIRY_DAYS')) {
    define('API_TOKEN_EXPIRY_DAYS', 3);
}
if (!defined('RESET_TOKEN_EXPIRY_MINUTES')) {
    define('RESET_TOKEN_EXPIRY_MINUTES', 60);
}


function connect_database()
{
    $connection = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);

    if ($connection->connect_error) {
        die('Connection failed: ' . $connection->connect_error);
    }

    $connection->set_charset('utf8mb4');
    return $connection;
}

function validate_email_for_role($email, $role)
{
    $email = strtolower(trim($email));

    if ($role === 'student') {
        if (!preg_match('/^\d+@student\.school\.ac\.ug$/', $email)) {
            return ['valid' => false, 'message' => 'Student email must be: studentnumber@student.school.ac.ug'];
        }
    } elseif ($role === 'instructor' || $role === 'staff') {
        if (!preg_match('/^[a-z]+\.[a-z]+@school\.ac\.ug$/', $email)) {
            return ['valid' => false, 'message' => 'Instructor/Staff email must be: firstname.lastname@school.ac.ug'];
        }
    } else {
        return ['valid' => false, 'message' => 'Invalid role specified'];
    }

    return ['valid' => true, 'email' => $email, 'message' => 'Email is valid for the specified role'];
}

function register_user($userData)
{
    $connection = connect_database();
    $connection->begin_transaction();

    try {
        $fullName = trim($userData['full_name'] ?? '');
        $email    = strtolower(trim($userData['email'] ?? ''));
        $username = trim($userData['username'] ?? '');
        $password = $userData['password'] ?? '';
        $phone    = trim($userData['phone'] ?? '');
        $role     = $userData['role'] ?? 'student';

        if (!in_array($role, ['student', 'instructor', 'staff'])) {
            throw new Exception('You cannot register with this role');
        }

        if (empty($fullName) || empty($email) || empty($username) || empty($password)) {
            throw new Exception('All required fields must be filled');
        }

        // CRITICAL RULE: Validate email format strictly per role first
        $emailValidation = validate_email_for_role($email, $role);
        if (!$emailValidation['valid']) {
            throw new Exception($emailValidation['message']);
        }
        $email = $emailValidation['email'];

        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters');
        }

        $preApprovedStudent = null;
        if ($role === 'student') {
            $studentNumber = explode('@', $email)[0];

            // 1. Query students table for pre-approved record where student_number = ? AND user_id IS NULL
            $checkStudent = $connection->prepare(
                "SELECT student_id, student_number, department_id, course_id, program, year_of_study
                 FROM students
                 WHERE student_number = ? AND user_id IS NULL"
            );
            $checkStudent->bind_param("s", $studentNumber);
            $checkStudent->execute();
            $studentRes = $checkStudent->get_result();
            if ($studentRes->num_rows === 0) {
                $checkStudent->close();
                throw new Exception('Your student number is not on the pre-approved enrollment list. Please contact the admin.');
            }
            $preApprovedStudent = $studentRes->fetch_assoc();
            $checkStudent->close();

            // 2. Validate course_id
            $courseId = isset($userData['course_id']) && is_numeric($userData['course_id']) && (int)$userData['course_id'] > 0
                ? (int)$userData['course_id']
                : ($preApprovedStudent['course_id'] ?? null);

            if (!$courseId) {
                throw new Exception('Course is required for student registration');
            }

            $courseCheck = $connection->prepare("SELECT course_id FROM courses WHERE course_id = ?");
            $courseCheck->bind_param("i", $courseId);
            $courseCheck->execute();
            if ($courseCheck->get_result()->num_rows === 0) {
                $courseCheck->close();
                throw new Exception('Invalid course selected');
            }
            $courseCheck->close();
        }

        // Email uniqueness
        $stmt = $connection->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            throw new Exception('This email is already registered');
        }
        $stmt->close();

        // Username uniqueness
        $stmt = $connection->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            throw new Exception('This username is already taken');
        }
        $stmt->close();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $accountStatus  = ($role === 'student') ? 'active' : 'pending';
        $isActive       = ($role === 'student') ? 1 : 0;
        $departmentId   = isset($userData['department_id']) && is_numeric($userData['department_id']) && (int)$userData['department_id'] > 0
            ? (int)$userData['department_id']
            : ($preApprovedStudent['department_id'] ?? null);

        $stmt = $connection->prepare(
            "INSERT INTO users(full_name, email, username, password_hash, phone_number, role, account_status, is_active, department_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssssii",
            $fullName,
            $email,
            $username,
            $hashedPassword,
            $phone,
            $role,
            $accountStatus,
            $isActive,
            $departmentId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Registration failed. Please try again.');
        }
        $newUserId = $connection->insert_id;
        $stmt->close();

        // Profile creation / update per role
        if ($role === 'student') {
            $courseId    = isset($userData['course_id']) && is_numeric($userData['course_id']) && (int)$userData['course_id'] > 0
                ? (int)$userData['course_id']
                : ($preApprovedStudent['course_id'] ?? null);
            $program     = trim($userData['program'] ?? $preApprovedStudent['program'] ?? '');
            $yearOfStudy = isset($userData['year_of_study']) ? (int)$userData['year_of_study'] : (int)($preApprovedStudent['year_of_study'] ?? 1);

            // Update existing pre-approved student row
            $stmt = $connection->prepare(
                "UPDATE students
                 SET user_id = ?,
                     full_name = ?,
                     email = ?,
                     department_id = ?,
                     course_id = ?,
                     program = ?,
                     year_of_study = ?
                 WHERE student_id = ?"
            );
            $stmt->bind_param(
                "issiisii",
                $newUserId,
                $fullName,
                $email,
                $departmentId,
                $courseId,
                $program,
                $yearOfStudy,
                $preApprovedStudent['student_id']
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Student profile could not be updated.');
            }
            $stmt->close();
        } elseif ($role === 'instructor') {
            $departmentId   = isset($userData['department_id']) ? (int)$userData['department_id'] : null;
            $specialization = trim($userData['specialization'] ?? '');
            $qualification  = trim($userData['qualification'] ?? '');
            $hireDate       = $userData['hire_date'] ?? null;
            $experience     = isset($userData['years_of_experience']) ? (int)$userData['years_of_experience'] : 0;
            $maxHours       = isset($userData['max_hours_per_week']) ? (int)$userData['max_hours_per_week'] : 20;

            $stmt = $connection->prepare(
                "INSERT INTO instructors(user_id, department_id, specialization, qualification, hire_date, years_of_experience, max_hours_per_week)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iisssii", $newUserId, $departmentId, $specialization, $qualification, $hireDate, $experience, $maxHours);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Instructor profile could not be created.');
            }
            $stmt->close();
        } elseif ($role === 'staff') {
            $departmentId = isset($userData['department_id']) ? (int)$userData['department_id'] : null;
            $jobTitle     = trim($userData['job_title'] ?? '');
            $hireDate     = $userData['hire_date'] ?? null;
            $notes        = trim($userData['notes'] ?? '');

            $stmt = $connection->prepare(
                "INSERT INTO staff(user_id, department_id, job_title, hire_date, notes)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iisss", $newUserId, $departmentId, $jobTitle, $hireDate, $notes);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Staff profile could not be created.');
            }
            $stmt->close();
        }

        $connection->commit();
        $connection->close();

        return [
            'success'        => true,
            'message'        => 'Account created successfully',
            'user_id'        => $newUserId,
            'account_status' => $accountStatus
        ];
    } catch (Exception $e) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function user_login($identifier, $password)
{
    $identifier = trim($identifier);
    $connection = connect_database();

    $stmt = $connection->prepare(
        "SELECT user_id, full_name, email, username, password_hash, role, account_status, is_active, department_id, profile_picture_url
         FROM users
         WHERE email = ? OR username = ?"
    );
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Invalid credentials'];
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user['account_status'] !== 'active' || $user['is_active'] != 1) {
        $connection->close();
        $messages = [
            'pending'   => 'Your account is pending approval',
            'suspended' => 'Your account has been suspended',
            'rejected'  => 'Your registration was rejected'
        ];
        $message = $messages[$user['account_status']] ?? 'Your account is not active';
        return ['success' => false, 'message' => $message];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $connection->close();
        return ['success' => false, 'message' => 'Invalid credentials'];
    }

    // Update last login
    $updateStmt = $connection->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
    $updateStmt->bind_param("i", $user['user_id']);
    $updateStmt->execute();
    $updateStmt->close();

    unset($user['password_hash']);

    // Issue API token
    $tokenResult = create_api_token($user['user_id'], $connection);
    $connection->close();

    if (!$tokenResult['success']) {
        return ['success' => false, 'message' => 'Login failed. Please try again.'];
    }

    return [
        'success' => true,
        'message' => 'Login successful',
        'user'    => $user,
        'token'   => $tokenResult['token']
    ];
}

function create_api_token($userId, $existingConnection = null)
{
    $connection = $existingConnection ?: connect_database();
    $ownsConnection = ($existingConnection === null);

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . API_TOKEN_EXPIRY_DAYS . ' days'));

    $stmt = $connection->prepare("INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");

    if (!$stmt) {
        if ($ownsConnection) $connection->close();
        return ['success' => false, 'message' => 'Could not create token'];
    }

    $stmt->bind_param("iss", $userId, $tokenHash, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ownsConnection) $connection->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Could not create token'];
    }

    return ['success' => true, 'token' => $rawToken, 'expires_at' => $expiresAt];
}

function validate_api_token($rawToken)
{
    $rawToken = trim($rawToken);
    if (empty($rawToken)) {
        return ['success' => false, 'message' => 'Token is required'];
    }

    $tokenHash  = hash('sha256', $rawToken);
    $connection = connect_database();

    $stmt = $connection->prepare(
        "SELECT token_id, user_id, expires_at, revoked_at
         FROM api_tokens
         WHERE token_hash = ?"
    );
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Invalid token'];
    }

    $token = $result->fetch_assoc();
    $stmt->close();

    if ($token['revoked_at'] !== null) {
        $connection->close();
        return ['success' => false, 'message' => 'Token has been revoked'];
    }

    if (strtotime($token['expires_at']) < time()) {
        $connection->close();
        return ['success' => false, 'message' => 'Token has expired'];
    }

    $userStmt = $connection->prepare(
        "SELECT user_id, full_name, email, username, phone_number, role, account_status, is_active, department_id, last_login, profile_picture_url
         FROM users WHERE user_id = ?"
    );
    $userStmt->bind_param("i", $token['user_id']);
    $userStmt->execute();
    $userResult = $userStmt->get_result();

    if ($userResult->num_rows === 0) {
        $userStmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'User not found'];
    }

    $user = $userResult->fetch_assoc();
    $userStmt->close();

    if ($user['account_status'] !== 'active' || $user['is_active'] != 1) {
        $connection->close();
        return ['success' => false, 'message' => 'Account is not active'];
    }

    $updateStmt = $connection->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE token_id = ?");
    $updateStmt->bind_param("i", $token['token_id']);
    $updateStmt->execute();
    $updateStmt->close();

    $connection->close();

    return ['success' => true, 'user' => $user];
}

function logout_user($rawToken)
{
    $rawToken = trim($rawToken);
    if (empty($rawToken)) {
        return ['success' => false, 'message' => 'Token is required'];
    }

    $tokenHash  = hash('sha256', $rawToken);
    $connection = connect_database();

    $stmt = $connection->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL");
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if ($affected === 0) {
        return ['success' => false, 'message' => 'Token not found or already revoked'];
    }

    return ['success' => true, 'message' => 'Logged out successfully'];
}

function revoke_all_user_tokens($userId, $existingConnection = null)
{
    $connection = $existingConnection ?: connect_database();
    $ownsConnection = ($existingConnection === null);

    $stmt = $connection->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    if ($ownsConnection) $connection->close();

    return ['success' => true, 'message' => 'All tokens revoked'];
}

function forgot_password($email)
{
    $email = strtolower(trim($email));
    $connection = connect_database();

    $stmt = $connection->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => true, 'message' => 'If this email exists, a reset link has been sent'];
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    $delStmt = $connection->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
    $delStmt->bind_param("i", $user['user_id']);
    $delStmt->execute();
    $delStmt->close();

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . RESET_TOKEN_EXPIRY_MINUTES . ' minutes'));

    $insStmt = $connection->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)VALUES (?, ?, ?)");
    $insStmt->bind_param("iss", $user['user_id'], $tokenHash, $expiresAt);
    $insStmt->execute();
    $insStmt->close();
    $connection->close();

    return [
        'success' => true,
        'message' => 'Password reset link has been sent',
        'token'   => $rawToken
    ];
}
function verify_reset_token($rawToken)
{
    $rawToken = trim($rawToken);
    if (empty($rawToken)) {
        return ['success' => false, 'message' => 'Reset token is required'];
    }

    $tokenHash  = hash('sha256', $rawToken);
    $connection = connect_database();

    $stmt = $connection->prepare("SELECT id, user_id, expires_at, used_at FROM password_reset_tokens WHERE token_hash = ?");
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Invalid reset token'];
    }

    $token = $result->fetch_assoc();
    $stmt->close();
    $connection->close();

    if ($token['used_at'] !== null) {
        return ['success' => false, 'message' => 'This reset link has already been used'];
    }

    if (strtotime($token['expires_at']) < time()) {
        return ['success' => false, 'message' => 'Reset link has expired'];
    }

    return ['success' => true, 'user_id' => $token['user_id'], 'token_id' => $token['id']];
}

function reset_password($rawToken, $newPassword, $confirmPassword)
{
    if (empty($newPassword) || empty($confirmPassword)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'Passwords do not match'];
    }
    if (strlen($newPassword) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters'];
    }

    $verified = verify_reset_token($rawToken);
    if (!$verified['success']) {
        return $verified;
    }

    $connection = connect_database();
    $connection->begin_transaction();

    try {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $updateStmt = $connection->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $updateStmt->bind_param("si", $hashedPassword, $verified['user_id']);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new Exception('Failed to update password');
        }
        $updateStmt->close();

        $markStmt = $connection->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
        $markStmt->bind_param("i", $verified['token_id']);
        $markStmt->execute();
        $markStmt->close();

        // Revoke all API tokens for security
        $revokeStmt = $connection->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $revokeStmt->bind_param("i", $verified['user_id']);
        $revokeStmt->execute();
        $revokeStmt->close();

        $connection->commit();
        $connection->close();

        return ['success' => true, 'message' => 'Password reset successful'];
    } catch (Exception $e) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function change_password($userId, $currentPassword, $newPassword, $confirmPassword)
{
    if (empty($userId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'User ID is required'];
    }
    $userId = (int)$userId;

    if (empty($currentPassword))  return ['success' => false, 'message' => 'Current password is required'];
    if (empty($newPassword))      return ['success' => false, 'message' => 'New password is required'];
    if (empty($confirmPassword))  return ['success' => false, 'message' => 'Password confirmation is required'];

    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'New passwords do not match'];
    }
    if (strlen($newPassword) < 6) {
        return ['success' => false, 'message' => 'New password must be at least 6 characters'];
    }
    if ($currentPassword === $newPassword) {
        return ['success' => false, 'message' => 'New password must be different from current password'];
    }

    $connection = connect_database();

    $stmt = $connection->prepare("SELECT user_id, password_hash, account_status FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'User not found'];
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user['account_status'] !== 'active') {
        $connection->close();
        return ['success' => false, 'message' => 'Your account is not active'];
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        $connection->close();
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }

    $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $connection->begin_transaction();
    try {
        $updateStmt = $connection->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $updateStmt->bind_param("si", $newHashedPassword, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new Exception('Failed to update password');
        }
        $updateStmt->close();

        // Revoke all other tokens for security
        $revokeStmt = $connection->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $revokeStmt->bind_param("i", $userId);
        $revokeStmt->execute();
        $revokeStmt->close();

        $connection->commit();
        $connection->close();

        return ['success' => true, 'message' => 'Password changed successfully. Please log in again.'];
    } catch (Exception $e) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function get_user($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare("SELECT user_id, full_name, email, username, phone_number, role, account_status, is_active, department_id, last_login, created_at FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'User not found'];
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $user];
}

function get_users()
{
    $connection = connect_database();
    $result = $connection->query("SELECT user_id, full_name, email, username, phone_number, role, account_status, is_active, department_id, last_login, created_atFROM users ORDER BY full_name");

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $users, 'count' => count($users)];
}

function get_pending_users()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT user_id, full_name, email, username, role, account_status, created_at FROM users
         WHERE account_status = 'pending'
         ORDER BY created_at ASC"
    );

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $users, 'count' => count($users)];
}

function get_users_by_role($role)
{
    $allowed = ['admin', 'department_head', 'instructor', 'student', 'staff'];
    if (!in_array($role, $allowed)) {
        return ['success' => false, 'message' => 'Invalid role'];
    }

    $connection = connect_database();
    $stmt = $connection->prepare("SELECT user_id, full_name, email, username, role, account_status FROM users WHERE role = ? ORDER BY full_name");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $users, 'count' => count($users)];
}

function get_users_by_department($departmentId)
{
    if (!is_numeric($departmentId)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $departmentId = (int)$departmentId;

    $connection = connect_database();
    $stmt = $connection->prepare("SELECT user_id, full_name, email, username, role, account_status FROM users WHERE department_id = ? ORDER BY full_name");
    $stmt->bind_param("i", $departmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $users, 'count' => count($users)];
}

function update_user($userId, $data)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    // Explicit allowlist with explicit bind types
    $allowedFields = [
        'full_name'     => 's',
        'phone_number'  => 's',
        'email'         => 's',
        'username'      => 's',
        'department_id' => 'i'
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $bindType) {
        if (array_key_exists($field, $data)) {
            $sets[]   = "$field = ?";
            $params[] = $data[$field];
            $types   .= $bindType;
        }
    }

    if (empty($sets)) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $connection = connect_database();

    // Uniqueness checks if email or username are being changed
    if (isset($data['email'])) {
        $check = $connection->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $check->bind_param("si", $data['email'], $userId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $check->close();
            $connection->close();
            return ['success' => false, 'message' => 'Email already in use'];
        }
        $check->close();
    }

    if (isset($data['username'])) {
        $check = $connection->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $check->bind_param("si", $data['username'], $userId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $check->close();
            $connection->close();
            return ['success' => false, 'message' => 'Username already in use'];
        }
        $check->close();
    }

    $params[] = $userId;
    $types   .= 'i';

    $sql  = "UPDATE users SET " . implode(', ', $sets) . " WHERE user_id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Update failed'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'No changes made'];
    }
    return ['success' => true, 'message' => 'User updated'];
}

function delete_user($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if ($affected === 0) {
        return ['success' => false, 'message' => 'User not found'];
    }

    return ['success' => true, 'message' => 'User and all related profiles deleted'];
}

function update_user_status($userId, $status)
{
    $allowed = ['pending', 'active', 'rejected', 'suspended'];
    if (!in_array($status, $allowed)) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $isActive = ($status === 'active') ? 1 : 0;

    $connection = connect_database();
    $connection->begin_transaction();

    try {
        $stmt = $connection->prepare(
            "UPDATE users SET account_status = ?, is_active = ? WHERE user_id = ?"
        );
        $stmt->bind_param("sii", $status, $isActive, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            $checkStmt = $connection->prepare(
                "SELECT user_id FROM users WHERE user_id = ?"
            );
            $checkStmt->bind_param("i", $userId);
            $checkStmt->execute();
            $userExists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if (!$userExists) {
                $connection->rollback();
                $connection->close();
                return ['success' => false, 'message' => 'User not found'];
            }
        }

        // If not active, revoke all their API tokens
        if ($status !== 'active') {
            $revokeStmt = $connection->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
            $revokeStmt->bind_param("i", $userId);
            $revokeStmt->execute();
            $revokeStmt->close();
        }

        $connection->commit();
        $connection->close();
        return ['success' => true, 'message' => 'User status updated'];
    } catch (Exception $e) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Update failed'];
    }
}

function create_student($data)
{
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'User ID is required'];
    }

    $studentNumber = trim($data['student_number'] ?? '');
    if (empty($studentNumber)) {
        return ['success' => false, 'message' => 'Student number is required'];
    }

    $departmentId = isset($data['department_id']) ? (int)$data['department_id'] : null;
    $program      = trim($data['program'] ?? '');
    $yearOfStudy  = isset($data['year_of_study']) ? (int)$data['year_of_study'] : 1;

    $connection = connect_database();

    // Check user exists
    $checkUser = $connection->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $checkUser->bind_param("i", $userId);
    $checkUser->execute();
    if ($checkUser->get_result()->num_rows === 0) {
        $checkUser->close();
        $connection->close();
        return ['success' => false, 'message' => 'User not found'];
    }
    $checkUser->close();

    // Check no existing student for this user
    $checkExisting = $connection->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $checkExisting->bind_param("i", $userId);
    $checkExisting->execute();
    if ($checkExisting->get_result()->num_rows > 0) {
        $checkExisting->close();
        $connection->close();
        return ['success' => false, 'message' => 'Student profile already exists for this user'];
    }
    $checkExisting->close();

    // Check student_number uniqueness
    $checkNum = $connection->prepare("SELECT student_id FROM students WHERE student_number = ?");
    $checkNum->bind_param("s", $studentNumber);
    $checkNum->execute();
    if ($checkNum->get_result()->num_rows > 0) {
        $checkNum->close();
        $connection->close();
        return ['success' => false, 'message' => 'Student number already exists'];
    }
    $checkNum->close();

    $stmt = $connection->prepare("INSERT INTO students (user_id, student_number, department_id, program, year_of_study) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isisi", $userId, $studentNumber, $departmentId, $program, $yearOfStudy);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to create student'];
    }

    $newId = $connection->insert_id;
    $stmt->close();
    $connection->close();
    return ['success' => true, 'message' => 'Student created', 'student_id' => $newId];
}

function get_student($studentId)
{
    if (!is_numeric($studentId)) {
        return ['success' => false, 'message' => 'Invalid student ID'];
    }
    $studentId = (int)$studentId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.student_id, s.student_number, s.program, s.year_of_study,
                s.enrollment_status, s.department_id,
                u.user_id, u.full_name, u.email, u.phone_number, u.account_status,
                d.department_name
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN departments d ON s.department_id = d.department_id
         WHERE s.student_id = ?"
    );
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Student not found'];
    }

    $student = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $student];
}

function get_student_by_user_id($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.student_id, s.student_number, s.program, s.year_of_study,
                s.enrollment_status, s.department_id,
                u.user_id, u.full_name, u.email, u.phone_number, u.account_status,
                d.department_name
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN departments d ON s.department_id = d.department_id
         WHERE s.user_id = ?"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Student profile not found'];
    }

    $student = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $student];
}

function get_students()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT s.student_id, s.student_number, s.program, s.year_of_study,
                s.enrollment_status,
                u.user_id, u.full_name, u.email, u.phone_number,
                d.department_name
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN departments d ON s.department_id = d.department_id
         ORDER BY u.full_name"
    );

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $students, 'count' => count($students)];
}

function update_student($studentId, $data)
{
    if (!is_numeric($studentId)) {
        return ['success' => false, 'message' => 'Invalid student ID'];
    }
    $studentId = (int)$studentId;

    $allowedFields = [
        'program'           => 's',
        'year_of_study'     => 'i',
        'enrollment_status' => 's',
        'department_id'     => 'i'
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $bindType) {
        if (array_key_exists($field, $data)) {
            $sets[]   = "$field = ?";
            $params[] = $data[$field];
            $types   .= $bindType;
        }
    }

    if (empty($sets)) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $params[] = $studentId;
    $types   .= 'i';

    $connection = connect_database();
    $sql  = "UPDATE students SET " . implode(', ', $sets) . " WHERE student_id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Update failed'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'No changes made or student not found'];
    }
    return ['success' => true, 'message' => 'Student updated'];
}

function update_student_status($studentId, $status)
{
    $allowed = ['active', 'graduated', 'withdrawn', 'suspended'];
    if (!in_array($status, $allowed)) {
        return ['success' => false, 'message' => 'Invalid enrollment status'];
    }
    return update_student($studentId, ['enrollment_status' => $status]);
}

function delete_student($studentId)
{
    if (!is_numeric($studentId)) {
        return ['success' => false, 'message' => 'Invalid student ID'];
    }
    $studentId = (int)$studentId;

    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM students WHERE student_id = ?");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if ($affected === 0) {
        return ['success' => false, 'message' => 'Student not found'];
    }
    return ['success' => true, 'message' => 'Student profile deleted'];
}

function get_students_by_department($departmentId)
{
    if (!is_numeric($departmentId)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $departmentId = (int)$departmentId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.student_id, s.student_number, s.program, s.year_of_study, u.full_name, u.email
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         WHERE s.department_id = ?
         ORDER BY u.full_name"
    );
    $stmt->bind_param("i", $departmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $students, 'count' => count($students)];
}

function search_students($search)
{
    $search = trim($search);
    $like = '%' . $search . '%';

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.student_id, s.student_number, s.program, u.full_name, u.email
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         WHERE u.full_name LIKE ? OR s.student_number LIKE ? OR u.email LIKE ?
         ORDER BY u.full_name"
    );
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $students, 'count' => count($students)];
}

function create_instructor($data)
{
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'User ID is required'];
    }

    $departmentId   = isset($data['department_id']) ? (int)$data['department_id'] : null;
    $specialization = trim($data['specialization'] ?? '');
    $qualification  = trim($data['qualification'] ?? '');
    $hireDate       = $data['hire_date'] ?? null;
    $experience     = isset($data['years_of_experience']) ? (int)$data['years_of_experience'] : 0;
    $maxHours       = isset($data['max_hours_per_week']) ? (int)$data['max_hours_per_week'] : 20;

    $connection = connect_database();

    $checkUser = $connection->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $checkUser->bind_param("i", $userId);
    $checkUser->execute();
    if ($checkUser->get_result()->num_rows === 0) {
        $checkUser->close();
        $connection->close();
        return ['success' => false, 'message' => 'User not found'];
    }
    $checkUser->close();

    $checkExisting = $connection->prepare("SELECT instructor_id FROM instructors WHERE user_id = ?");
    $checkExisting->bind_param("i", $userId);
    $checkExisting->execute();
    if ($checkExisting->get_result()->num_rows > 0) {
        $checkExisting->close();
        $connection->close();
        return ['success' => false, 'message' => 'Instructor profile already exists for this user'];
    }
    $checkExisting->close();

    $stmt = $connection->prepare("INSERT INTO instructors(user_id, department_id, specialization, qualification, hire_date, years_of_experience, max_hours_per_week) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssii", $userId, $departmentId, $specialization, $qualification, $hireDate, $experience, $maxHours);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to create instructor'];
    }

    $newId = $connection->insert_id;
    $stmt->close();
    $connection->close();
    return ['success' => true, 'message' => 'Instructor created', 'instructor_id' => $newId];
}

function get_instructor($instructorId)
{
    if (!is_numeric($instructorId)) {
        return ['success' => false, 'message' => 'Invalid instructor ID'];
    }
    $instructorId = (int)$instructorId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT i.instructor_id, i.specialization, i.qualification,
                i.hire_date, i.years_of_experience, i.max_hours_per_week,
                i.is_available, i.department_id,
                u.user_id, u.full_name, u.email, u.phone_number, u.account_status,
                d.department_name
         FROM instructors i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN departments d ON i.department_id = d.department_id
         WHERE i.instructor_id = ?"
    );
    $stmt->bind_param("i", $instructorId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Instructor not found'];
    }

    $instructor = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $instructor];
}

function get_instructor_by_user_id($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT i.instructor_id, i.specialization, i.qualification,
                i.hire_date, i.years_of_experience, i.max_hours_per_week,
                i.is_available, i.department_id,
                u.user_id, u.full_name, u.email, u.phone_number, u.account_status,
                d.department_name
         FROM instructors i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN departments d ON i.department_id = d.department_id
         WHERE i.user_id = ?"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Instructor profile not found'];
    }

    $instructor = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $instructor];
}

function get_instructors()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT i.instructor_id, i.specialization, i.qualification,
                i.years_of_experience, i.is_available,
                u.user_id, u.full_name, u.email, u.phone_number,
                d.department_name
         FROM instructors i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN departments d ON i.department_id = d.department_id
         ORDER BY u.full_name"
    );

    $instructors = [];
    while ($row = $result->fetch_assoc()) {
        $instructors[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $instructors, 'count' => count($instructors)];
}

function update_instructor($instructorId, $data)
{
    if (!is_numeric($instructorId)) {
        return ['success' => false, 'message' => 'Invalid instructor ID'];
    }
    $instructorId = (int)$instructorId;

    $allowedFields = [
        'specialization'      => 's',
        'qualification'       => 's',
        'hire_date'           => 's',
        'years_of_experience' => 'i',
        'max_hours_per_week'  => 'i',
        'is_available'        => 'i',
        'department_id'       => 'i'
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $bindType) {
        if (array_key_exists($field, $data)) {
            $sets[]   = "$field = ?";
            $params[] = $data[$field];
            $types   .= $bindType;
        }
    }

    if (empty($sets)) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $params[] = $instructorId;
    $types   .= 'i';

    $connection = connect_database();
    $sql  = "UPDATE instructors SET " . implode(', ', $sets) . " WHERE instructor_id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Update failed'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'No changes made or instructor not found'];
    }
    return ['success' => true, 'message' => 'Instructor updated'];
}

function update_instructor_availability($instructorId, $isAvailable)
{
    return update_instructor($instructorId, ['is_available' => (int)(bool)$isAvailable]);
}

function delete_instructor($instructorId)
{
    if (!is_numeric($instructorId)) {
        return ['success' => false, 'message' => 'Invalid instructor ID'];
    }
    $instructorId = (int)$instructorId;

    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM instructors WHERE instructor_id = ?");
    $stmt->bind_param("i", $instructorId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if ($affected === 0) {
        return ['success' => false, 'message' => 'Instructor not found'];
    }
    return ['success' => true, 'message' => 'Instructor profile deleted'];
}

function get_instructors_by_department($departmentId)
{
    if (!is_numeric($departmentId)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $departmentId = (int)$departmentId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT i.instructor_id, i.specialization,
                u.full_name, u.email
         FROM instructors i
         JOIN users u ON i.user_id = u.user_id
         WHERE i.department_id = ?
         ORDER BY u.full_name"
    );
    $stmt->bind_param("i", $departmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $instructors = [];
    while ($row = $result->fetch_assoc()) {
        $instructors[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $instructors, 'count' => count($instructors)];
}

function get_available_instructors()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT i.instructor_id, i.specialization,
                u.full_name, u.email
         FROM instructors i
         JOIN users u ON i.user_id = u.user_id
         WHERE i.is_available = 1 AND u.account_status = 'active'
         ORDER BY u.full_name"
    );

    $instructors = [];
    while ($row = $result->fetch_assoc()) {
        $instructors[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $instructors, 'count' => count($instructors)];
}

function create_staff($data)
{
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'User ID is required'];
    }

    $departmentId = isset($data['department_id']) ? (int)$data['department_id'] : null;
    $jobTitle     = trim($data['job_title'] ?? '');
    $hireDate     = $data['hire_date'] ?? null;
    $notes        = trim($data['notes'] ?? '');

    $connection = connect_database();

    $checkUser = $connection->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $checkUser->bind_param("i", $userId);
    $checkUser->execute();
    if ($checkUser->get_result()->num_rows === 0) {
        $checkUser->close();
        $connection->close();
        return ['success' => false, 'message' => 'User not found'];
    }
    $checkUser->close();

    $checkExisting = $connection->prepare("SELECT staff_id FROM staff WHERE user_id = ?");
    $checkExisting->bind_param("i", $userId);
    $checkExisting->execute();
    if ($checkExisting->get_result()->num_rows > 0) {
        $checkExisting->close();
        $connection->close();
        return ['success' => false, 'message' => 'Staff profile already exists for this user'];
    }
    $checkExisting->close();

    $stmt = $connection->prepare("INSERT INTO staff (user_id, department_id, job_title, hire_date, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $userId, $departmentId, $jobTitle, $hireDate, $notes);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to create staff member'];
    }

    $newId = $connection->insert_id;
    $stmt->close();
    $connection->close();
    return ['success' => true, 'message' => 'Staff created', 'staff_id' => $newId];
}

function get_staff($staffId)
{
    if (!is_numeric($staffId)) {
        return ['success' => false, 'message' => 'Invalid staff ID'];
    }
    $staffId = (int)$staffId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.staff_id, s.job_title, s.hire_date, s.is_available, s.notes,
                s.department_id,
                u.user_id, u.full_name, u.email, u.phone_number, u.account_status,
                d.department_name
         FROM staff s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN departments d ON s.department_id = d.department_id
         WHERE s.staff_id = ?"
    );
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Staff member not found'];
    }

    $staff = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $staff];
}

function get_staff_by_user_id($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.staff_id, s.job_title, s.hire_date, s.is_available, s.notes,
                s.department_id,
                u.user_id, u.full_name, u.email, u.phone_number, u.account_status,
                d.department_name
         FROM staff s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN departments d ON s.department_id = d.department_id
         WHERE s.user_id = ?"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Staff profile not found'];
    }

    $staff = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $staff];
}

function get_staff_members()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT s.staff_id, s.job_title, s.is_available,
                u.user_id, u.full_name, u.email, u.phone_number,
                d.department_name
         FROM staff s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN departments d ON s.department_id = d.department_id
         ORDER BY u.full_name"
    );

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $staff, 'count' => count($staff)];
}

function update_staff($staffId, $data)
{
    if (!is_numeric($staffId)) {
        return ['success' => false, 'message' => 'Invalid staff ID'];
    }
    $staffId = (int)$staffId;

    $allowedFields = [
        'job_title'     => 's',
        'hire_date'     => 's',
        'is_available'  => 'i',
        'notes'         => 's',
        'department_id' => 'i'
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $bindType) {
        if (array_key_exists($field, $data)) {
            $sets[]   = "$field = ?";
            $params[] = $data[$field];
            $types   .= $bindType;
        }
    }

    if (empty($sets)) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $params[] = $staffId;
    $types   .= 'i';

    $connection = connect_database();
    $sql  = "UPDATE staff SET " . implode(', ', $sets) . " WHERE staff_id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Update failed'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'No changes made or staff member not found'];
    }
    return ['success' => true, 'message' => 'Staff updated'];
}

function delete_staff($staffId)
{
    if (!is_numeric($staffId)) {
        return ['success' => false, 'message' => 'Invalid staff ID'];
    }
    $staffId = (int)$staffId;

    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM staff WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if ($affected === 0) {
        return ['success' => false, 'message' => 'Staff member not found'];
    }
    return ['success' => true, 'message' => 'Staff profile deleted'];
}

function get_staff_by_department($departmentId)
{
    if (!is_numeric($departmentId)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $departmentId = (int)$departmentId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT s.staff_id, s.job_title,
                u.full_name, u.email
         FROM staff s
         JOIN users u ON s.user_id = u.user_id
         WHERE s.department_id = ?
         ORDER BY u.full_name"
    );
    $stmt->bind_param("i", $departmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $staff, 'count' => count($staff)];
}

function create_department($data)
{
    $connection = connect_database();

    $code     = trim($data['department_code'] ?? '');
    $name     = trim($data['department_name'] ?? '');
    $campus   = $data['campus_location'] ?? 'Main Campus';
    $building = $data['building'] ?? '';

    if (empty($code) || empty($name)) {
        $connection->close();
        return ['success' => false, 'message' => 'Code and name are required'];
    }

    $stmt = $connection->prepare("SELECT department_id FROM departments WHERE department_code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Department code already exists'];
    }
    $stmt->close();

    $stmt = $connection->prepare("INSERT INTO departments (department_code, department_name, campus_location, building) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $code, $name, $campus, $building);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to create department'];
    }

    $newId = $connection->insert_id;
    $stmt->close();
    $connection->close();
    return ['success' => true, 'message' => 'Department created', 'department_id' => $newId];
}

function get_department($id)
{
    if (!is_numeric($id)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $id = (int)$id;

    $connection = connect_database();
    $stmt = $connection->prepare("SELECT * FROM departments WHERE department_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Department not found'];
    }

    $department = $result->fetch_assoc();
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $department];
}

function get_departments()
{
    $connection = connect_database();
    $result = $connection->query("SELECT * FROM departments ORDER BY department_name");

    $departments = [];
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $departments, 'count' => count($departments)];
}

function update_department($id, $data)
{
    if (!is_numeric($id)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $id = (int)$id;

    $allowedFields = [
        'department_name' => 's',
        'campus_location' => 's',
        'building'        => 's'
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $bindType) {
        if (array_key_exists($field, $data)) {
            $sets[]   = "$field = ?";
            $params[] = $data[$field];
            $types   .= $bindType;
        }
    }

    if (empty($sets)) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $params[] = $id;
    $types   .= 'i';

    $connection = connect_database();
    $sql  = "UPDATE departments SET " . implode(', ', $sets) . " WHERE department_id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Update failed'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'No changes made or department not found'];
    }
    return ['success' => true, 'message' => 'Department updated'];
}

function delete_department($id)
{
    if (!is_numeric($id)) {
        return ['success' => false, 'message' => 'Invalid department ID'];
    }
    $id = (int)$id;

    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM departments WHERE department_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if ($affected === 0) {
        return ['success' => false, 'message' => 'Department not found'];
    }
    return ['success' => true, 'message' => 'Department deleted'];
}

function get_classrooms($filters = [])
{
    $connection = connect_database();

    $sql = "SELECT
                c.classroom_id,
                c.room_number,
                c.building,
                c.department_id,
                c.department_id AS responsible_department_id,
                d.department_name,
                c.floor,
                c.campus_location,
                c.capacity,
                c.room_type,
                c.has_projector,
                c.has_smart_board,
                c.has_computers,
                c.number_of_computers,
                c.has_air_conditioning,
                c.has_wifi,
                c.requires_keycard,
                c.safety_level,
                c.status,
                c.profile_image_url,
                (
                    SELECT COUNT(*) FROM allocations a
                    WHERE a.resource_type = 'classroom'
                      AND a.resource_id = c.classroom_id
                      AND a.status IN ('pending', 'approved')
                      AND a.allocation_date = CURDATE()
                      AND CURTIME() BETWEEN a.start_time AND a.end_time
                ) AS busy_now_count
            FROM classrooms c
            LEFT JOIN departments d ON d.department_id = c.department_id
            WHERE 1=1";

    $params = [];
    $types = '';

    if (!empty($filters['building'])) {
        $sql .= " AND c.building = ?";
        $params[] = $filters['building'];
        $types .= 's';
    }
    if (!empty($filters['room_type'])) {
        $sql .= " AND c.room_type = ?";
        $params[] = $filters['room_type'];
        $types .= 's';
    }
    if (!empty($filters['min_capacity'])) {
        $sql .= " AND c.capacity >= ?";
        $params[] = (int)$filters['min_capacity'];
        $types .= 'i';
    }
    if (!empty($filters['status'])) {
        $sql .= " AND c.status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    if (array_key_exists('user_department_id', $filters)) {
        $departmentId = $filters['user_department_id'];
        if ($departmentId === null || (int)$departmentId <= 0) {
            $sql .= " AND c.department_id IS NULL";
        } else {
            $departmentId = (int)$departmentId;
            $sql .= " AND (c.department_id = ? OR c.department_id IS NULL)";
            $params[] = $departmentId;
            $types .= 'i';
        }
    }

    $sql .= " ORDER BY c.building, c.room_number";

    $stmt = $connection->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $classrooms = [];
    while ($row = $result->fetch_assoc()) {
        $row['has_projector']        = (bool)$row['has_projector'];
        $row['has_smart_board']      = (bool)$row['has_smart_board'];
        $row['has_computers']        = (bool)$row['has_computers'];
        $row['has_air_conditioning'] = (bool)$row['has_air_conditioning'];
        $row['has_wifi']             = (bool)$row['has_wifi'];
        $row['requires_keycard']     = (bool)$row['requires_keycard'];
        $row['is_busy_now']          = ((int)$row['busy_now_count']) > 0;
        unset($row['busy_now_count']);
        $classrooms[] = $row;
    }

    $stmt->close();
    $connection->close();

    return ['success' => true, 'data' => $classrooms, 'count' => count($classrooms)];
}

function get_classroom($classroomId)
{
    if (!is_numeric($classroomId)) {
        return ['success' => false, 'message' => 'Invalid classroom ID'];
    }
    $classroomId = (int)$classroomId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT * FROM classrooms WHERE classroom_id = ?"
    );
    $stmt->bind_param("i", $classroomId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Classroom not found'];
    }

    $row = $result->fetch_assoc();
    $row['has_projector']        = (bool)$row['has_projector'];
    $row['has_smart_board']      = (bool)$row['has_smart_board'];
    $row['has_computers']        = (bool)$row['has_computers'];
    $row['has_air_conditioning'] = (bool)$row['has_air_conditioning'];
    $row['has_wifi']             = (bool)$row['has_wifi'];
    $row['requires_keycard']     = (bool)$row['requires_keycard'];

    $stmt->close();
    $connection->close();

    return ['success' => true, 'data' => $row];
}

function get_equipment($filters = [])
{
    $connection = connect_database();

    $sql = "SELECT
                e.equipment_id,
                e.equipment_name,
                e.equipment_type,
                e.model,
                e.manufacturer,
                e.serial_number,
                e.asset_tag,
                e.battery_health,
                e.connectivity_type,
                e.safety_level,
                e.status,
                e.current_location,
                e.profile_image_url,
                e.responsible_department_id,
                e.responsible_department_id AS department_id,
                d.department_name,
                (
                    SELECT COUNT(*) FROM allocations a
                    WHERE a.resource_type = 'equipment'
                      AND a.resource_id = e.equipment_id
                      AND a.status IN ('pending', 'approved')
                      AND a.allocation_date = CURDATE()
                      AND CURTIME() BETWEEN a.start_time AND a.end_time
                ) AS busy_now_count
            FROM equipment e
            LEFT JOIN departments d ON e.responsible_department_id = d.department_id
            WHERE 1=1";

    $params = [];
    $types = '';

    if (!empty($filters['equipment_type'])) {
        $sql .= " AND e.equipment_type = ?";
        $params[] = $filters['equipment_type'];
        $types .= 's';
    }
    if (!empty($filters['status'])) {
        $sql .= " AND e.status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    if (!empty($filters['department_id'])) {
        $sql .= " AND e.responsible_department_id = ?";
        $params[] = (int)$filters['department_id'];
        $types .= 'i';
    }
    if (array_key_exists('user_department_id', $filters)) {
        $departmentId = $filters['user_department_id'];
        if ($departmentId === null || (int)$departmentId <= 0) {
            $sql .= " AND e.responsible_department_id IS NULL";
        } else {
            $sql .= " AND (
                e.responsible_department_id = ?
                OR e.responsible_department_id IS NULL
            )";
            $params[] = (int)$departmentId;
            $types .= 'i';
        }
    }

    $sql .= " ORDER BY e.equipment_name";

    $stmt = $connection->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $equipment = [];
    while ($row = $result->fetch_assoc()) {
        $row['is_busy_now'] = ((int)$row['busy_now_count']) > 0;
        unset($row['busy_now_count']);
        $equipment[] = $row;
    }

    $stmt->close();
    $connection->close();

    return ['success' => true, 'data' => $equipment, 'count' => count($equipment)];
}

function get_user_department_id($userId, $role)
{
    $profileTable = $role === 'student' ? 'students' : 'instructors';
    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT COALESCE(u.department_id, p.department_id) AS department_id
         FROM users u
         LEFT JOIN $profileTable p ON p.user_id = u.user_id
         WHERE u.user_id = ?"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $connection->close();
    return isset($row['department_id']) ? (int)$row['department_id'] : null;
}

function get_equipment_item($equipmentId)
{
    if (!is_numeric($equipmentId)) {
        return ['success' => false, 'message' => 'Invalid equipment ID'];
    }
    $equipmentId = (int)$equipmentId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT e.*, d.department_name
         FROM equipment e
         LEFT JOIN departments d ON e.responsible_department_id = d.department_id
         WHERE e.equipment_id = ?"
    );
    $stmt->bind_param("i", $equipmentId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Equipment not found'];
    }

    $row = $result->fetch_assoc();
    $stmt->close();
    $connection->close();

    return ['success' => true, 'data' => $row];
}

/**
 * Checks if a resource (classroom / equipment / instructor) exists.
 */
function resource_exists($resourceType, $resourceId)
{
    $allowed = ['classroom', 'equipment', 'instructor'];
    if (!in_array($resourceType, $allowed)) {
        return ['success' => false, 'message' => 'Invalid resource type'];
    }
    if (!is_numeric($resourceId)) {
        return ['success' => false, 'message' => 'Invalid resource ID'];
    }
    $resourceId = (int)$resourceId;

    $connection = connect_database();

    if ($resourceType === 'classroom') {
        $stmt = $connection->prepare("SELECT classroom_id FROM classrooms WHERE classroom_id = ?");
    } elseif ($resourceType === 'equipment') {
        $stmt = $connection->prepare("SELECT equipment_id FROM equipment WHERE equipment_id = ?");
    } else {
        $stmt = $connection->prepare("SELECT instructor_id FROM instructors WHERE instructor_id = ?");
    }

    $stmt->bind_param("i", $resourceId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    $connection->close();

    if (!$exists) {
        return ['success' => false, 'message' => ucfirst($resourceType) . ' not found'];
    }
    return ['success' => true];
}

/**
 * Checks if a resource is available for booking.
 * Available statuses differ between classrooms and equipment.
 */
function resource_is_available($resourceType, $resourceId)
{
    $connection = connect_database();
    $resourceId = (int)$resourceId;

    if ($resourceType === 'classroom') {
        $stmt = $connection->prepare("SELECT status FROM classrooms WHERE classroom_id = ?");
        $stmt->bind_param("i", $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $connection->close();

        if (!$row) {
            return ['success' => false, 'message' => 'Classroom not found'];
        }
        if ($row['status'] !== 'available') {
            return ['success' => false, 'message' => 'This classroom is not available for booking'];
        }
        return ['success' => true];
    }

    if ($resourceType === 'equipment') {
        $stmt = $connection->prepare("SELECT status FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $connection->close();

        if (!$row) {
            return ['success' => false, 'message' => 'Equipment not found'];
        }
        if ($row['status'] !== 'available') {
            return ['success' => false, 'message' => 'This equipment is not available for booking'];
        }
        return ['success' => true];
    }

    // Instructors: check is_available flag
    $stmt = $connection->prepare("SELECT is_available FROM instructors WHERE instructor_id = ?");
    $stmt->bind_param("i", $resourceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $connection->close();

    if (!$row) {
        return ['success' => false, 'message' => 'Instructor not found'];
    }
    if ((int)$row['is_available'] !== 1) {
        return ['success' => false, 'message' => 'This instructor is not available for booking'];
    }
    return ['success' => true];
}

/**
 * Checks if a specific resource already has an overlapping allocation
 * in a pending or approved state across the date/time range.
 */
function has_resource_conflict($resourceType, $resourceId, $startDate, $endDate, $startTime, $endTime)
{
    $connection = connect_database();
    $resourceId = (int)$resourceId;
    $startDateTime = $startDate . ' ' . $startTime . ':00';
    $endDateTime   = $endDate . ' ' . $endTime . ':00';

    $stmt = $connection->prepare(
        "SELECT allocation_id
         FROM allocations
         WHERE resource_type = ?
           AND resource_id = ?
           AND status IN ('pending', 'approved')
           AND CONCAT(allocation_date, ' ', start_time) < ?
           AND CONCAT(COALESCE(end_date, allocation_date), ' ', end_time) > ?"
    );
    $stmt->bind_param("siss", $resourceType, $resourceId, $endDateTime, $startDateTime);
    $stmt->execute();
    $conflict = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    $connection->close();

    return $conflict;
}

/**
 * Checks if a user already has an overlapping allocation
 * (any resource) in pending or approved state across date/time range.
 * Used to prevent double-booking a person.
 */
function user_has_overlapping_booking($userId, $startDate, $endDate, $startTime, $endTime)
{
    $connection = connect_database();
    $userId = (int)$userId;
    $startDateTime = $startDate . ' ' . $startTime . ':00';
    $endDateTime   = $endDate . ' ' . $endTime . ':00';

    $stmt = $connection->prepare(
        "SELECT allocation_id
         FROM allocations
         WHERE booked_by_user_id = ?
           AND status IN ('pending', 'approved')
           AND CONCAT(allocation_date, ' ', start_time) < ?
           AND CONCAT(COALESCE(end_date, allocation_date), ' ', end_time) > ?"
    );
    $stmt->bind_param("iss", $userId, $endDateTime, $startDateTime);
    $stmt->execute();
    $overlap = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    $connection->close();

    return $overlap;
}

/**
 * Counts a student's active classroom bookings (pending or approved).
 */
function student_active_classroom_count($userId)
{
    $connection = connect_database();
    $userId = (int)$userId;

    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS total
         FROM allocations
         WHERE booked_by_user_id = ?
           AND resource_type = 'classroom'
           AND status IN ('pending', 'approved')"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $connection->close();

    return (int)($row['total'] ?? 0);
}

/**
 * Checks whether a user is allowed to make a new booking,
 * based on their role and their existing bookings.
 */
function user_can_create_allocation($userId, $userRole, $resourceType, $startDate, $endDate, $startTime, $endTime)
{
    // No overlapping bookings for any user (any role)
    if (user_has_overlapping_booking($userId, $startDate, $endDate, $startTime, $endTime)) {
        return ['success' => false, 'message' => 'You already have a booking that overlaps with this time'];
    }

    // Students: one active classroom booking at a time
    if ($userRole === 'student' && $resourceType === 'classroom') {
        $count = student_active_classroom_count($userId);
        if ($count >= 1) {
            return ['success' => false, 'message' => 'Students can only have one active classroom booking at a time'];
        }
    }

    // Instructors, staff, admins, department heads: no per-user cap
    return ['success' => true];
}

/**
 * Validates date and time inputs for a booking.
 */
function validate_booking_datetime($startDate, $endDate, $startTime, $endTime = null)
{
    // Backwards-compatibility if called with ($date, $startTime, $endTime)
    if ($endTime === null) {
        $endTime = $startTime;
        $startTime = $endDate;
        $endDate = $startDate;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return ['success' => false, 'message' => 'Invalid start date format. Use YYYY-MM-DD.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return ['success' => false, 'message' => 'Invalid end date format. Use YYYY-MM-DD.'];
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        return ['success' => false, 'message' => 'Invalid time format. Use HH:MM.'];
    }

    $startDateTime = $startDate . ' ' . $startTime;
    $endDateTime   = $endDate . ' ' . $endTime;

    if ($startDateTime >= $endDateTime) {
        return ['success' => false, 'message' => 'End date and time must be strictly after start date and time'];
    }

    $today = date('Y-m-d');
    if ($startDate < $today) {
        return ['success' => false, 'message' => 'Booking date cannot be in the past'];
    }
    if ($startDate === $today && $startTime < date('H:i')) {
        return ['success' => false, 'message' => 'Booking time has already passed today'];
    }

    return ['success' => true];
}

/**
 * Main booking function.
 * Creates a new allocation with status = 'pending'.
 * The client cannot choose the status.
 */
function create_allocation($data, $userId, $userRole)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user'];
    }
    $userId = (int)$userId;

    $allowedRoles = ['student', 'instructor', 'staff', 'admin', 'department_head'];
    if (!in_array($userRole, $allowedRoles)) {
        return ['success' => false, 'message' => 'You are not allowed to make bookings'];
    }

    // --- Required inputs ---
    $resourceType = trim($data['resource_type'] ?? '');
    $resourceId   = $data['resource_id'] ?? null;
    $startDate    = trim($data['allocation_date'] ?? '');
    $endDate      = trim($data['end_date'] ?? $data['allocation_date'] ?? '');
    $startTime    = trim($data['start_time'] ?? '');
    $endTime      = trim($data['end_time'] ?? '');
    $purpose      = trim($data['purpose'] ?? '');
    $priority     = $data['priority'] ?? 'medium';

    if ($resourceType === '' || !$resourceId || $startDate === '' || $endDate === '' || $startTime === '' || $endTime === '' || $purpose === '') {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (!in_array($priority, ['low', 'medium', 'high', 'emergency'])) {
        $priority = 'medium';
    }

    // --- Validate date/time ---
    $dtCheck = validate_booking_datetime($startDate, $endDate, $startTime, $endTime);
    if (!$dtCheck['success']) {
        return $dtCheck;
    }

    // --- Validate resource exists ---
    $exists = resource_exists($resourceType, $resourceId);
    if (!$exists['success']) {
        return $exists;
    }

    // --- Validate resource available ---
    $available = resource_is_available($resourceType, $resourceId);
    if (!$available['success']) {
        return $available;
    }

    // --- Instructor Booking Rules: only book classrooms where department_id matches their own or is NULL ---
    if ($userRole === 'instructor' && $resourceType === 'classroom') {
        $instructorDeptId = get_user_department_id($userId, 'instructor');
        $connection = connect_database();
        $classStmt = $connection->prepare("SELECT department_id FROM classrooms WHERE classroom_id = ?");
        $classStmt->bind_param("i", $resourceId);
        $classStmt->execute();
        $classRow = $classStmt->get_result()->fetch_assoc();
        $classStmt->close();
        $connection->close();

        if ($classRow && $classRow['department_id'] !== null) {
            $classroomDeptId = (int)$classRow['department_id'];
            if ($instructorDeptId === null || $classroomDeptId !== (int)$instructorDeptId) {
                return [
                    'success' => false,
                    'message' => 'Instructors can only book classrooms belonging to their own department (or general classrooms).'
                ];
            }
        }
    }

    // --- Resource-level conflict ---
    if (has_resource_conflict($resourceType, $resourceId, $startDate, $endDate, $startTime, $endTime)) {
        return ['success' => false, 'message' => 'This resource is already booked for the selected time'];
    }

    // --- Per-user rules (overlap + role limits) ---
    $userCheck = user_can_create_allocation(
        $userId,
        $userRole,
        $resourceType,
        $startDate,
        $endDate,
        $startTime,
        $endTime
    );
    if (!$userCheck['success']) {
        return $userCheck;
    }

    // --- Optional: department / course ---
    $departmentId = isset($data['department_id']) && is_numeric($data['department_id'])
        ? (int)$data['department_id'] : null;
    $courseId = isset($data['course_id']) && is_numeric($data['course_id'])
        ? (int)$data['course_id'] : null;

    // --- Insert ---
    $connection = connect_database();

    $stmt = $connection->prepare(
        "INSERT INTO allocations
            (resource_type, resource_id, booked_by_user_id,
             department_id, course_id, allocation_date, end_date,
             start_time, end_time, purpose, priority, status)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
    );

    if (!$stmt) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking failed. Please try again.'];
    }

    $stmt->bind_param(
        "siiiissssss",
        $resourceType,
        $resourceId,
        $userId,
        $departmentId,
        $courseId,
        $startDate,
        $endDate,
        $startTime,
        $endTime,
        $purpose,
        $priority
    );

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Booking failed. Please try again.'];
    }

    $allocationId = $connection->insert_id;
    $stmt->close();
    $connection->close();

    return [
        'success'       => true,
        'message'       => 'Booking request submitted successfully',
        'allocation_id' => $allocationId,
        'status'        => 'pending',
    ];
}

/**
 * Cancel an allocation.
 * Only the owner (or an admin) may cancel.
 * Refuses if the booking is already completed/rejected/cancelled.
 */
function cancel_allocation($allocationId, $userId, $userRole, $reason = '')
{
    if (!is_numeric($allocationId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId = (int)$allocationId;
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT booked_by_user_id, status FROM allocations WHERE allocation_id = ?"
    );
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }

    if ((int)$row['booked_by_user_id'] !== $userId && $userRole !== 'admin') {
        $connection->close();
        return ['success' => false, 'message' => 'You can only cancel your own booking'];
    }

    if (in_array($row['status'], ['completed', 'rejected', 'cancelled'])) {
        $connection->close();
        return ['success' => false, 'message' => 'This booking can no longer be cancelled'];
    }

    $stmt = $connection->prepare(
        "UPDATE allocations
         SET status = 'cancelled',
             cancellation_reason = ?
         WHERE allocation_id = ?"
    );
    $reason = $reason !== '' ? $reason : null;
    $stmt->bind_param("si", $reason, $allocationId);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to cancel booking'];
    }
    $stmt->close();
    $connection->close();

    return ['success' => true, 'message' => 'Booking cancelled'];
}

/**
 * Returns all allocations created by a specific user.
 * Joins classroom and equipment names for convenience.
 */
function get_user_allocations($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    auto_complete_past_allocations();

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT
            a.allocation_id,
            a.resource_type,
            a.resource_id,
            a.allocation_date,
            a.end_date,
            a.start_time,
            a.end_time,
            a.purpose,
            a.priority,
            a.status,
            a.created_at,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         WHERE a.booked_by_user_id = ?
         ORDER BY a.allocation_date DESC, a.start_time DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        if ($row['resource_type'] === 'classroom') {
            $row['resource_name'] = trim(($row['room_number'] ?? '') . ' · ' . ($row['building'] ?? ''));
        } elseif ($row['resource_type'] === 'equipment') {
            $row['resource_name'] = $row['equipment_name'] ?? 'Equipment';
        } else {
            $row['resource_name'] = 'Resource';
        }
        $bookings[] = $row;
    }

    $stmt->close();
    $connection->close();

    return [
        'success' => true,
        'data'    => $bookings,
        'count'   => count($bookings),
    ];
}

/**
 * Returns all allocations for administrative booking views.
 */
function get_all_allocations()
{
    auto_complete_past_allocations();

    $connection = connect_database();
    $result = $connection->query(
        "SELECT
            a.allocation_id,
            a.resource_type,
            a.resource_id,
            a.booked_by_user_id,
            a.allocation_date,
            a.end_date,
            a.start_time,
            a.end_time,
            a.purpose,
            a.priority,
            a.status,
            a.created_at,
            u.full_name AS booked_by_name,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         LEFT JOIN users u ON a.booked_by_user_id = u.user_id
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         ORDER BY a.allocation_date DESC, a.start_time DESC"
    );

    if (!$result) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to retrieve bookings.'];
    }

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        if ($row['resource_type'] === 'classroom') {
            $row['resource_name'] = trim(($row['room_number'] ?? '') . ' · ' . ($row['building'] ?? ''));
        } elseif ($row['resource_type'] === 'equipment') {
            $row['resource_name'] = $row['equipment_name'] ?? 'Equipment';
        } else {
            $row['resource_name'] = 'Resource';
        }
        $bookings[] = $row;
    }

    $connection->close();
    return ['success' => true, 'data' => $bookings, 'count' => count($bookings)];
}

/**
 * Returns a single allocation by its ID.
 * Only returns it if it belongs to the given user, OR the user is admin.
 */
function get_booking($allocationId, $userId, $userRole)
{
    if (!is_numeric($allocationId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId = (int)$allocationId;
    $userId       = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT
            a.allocation_id,
            a.resource_type,
            a.resource_id,
            a.booked_by_user_id,
            a.approver_user_id,
            a.department_id,
            a.course_id,
            a.allocation_date,
            a.end_date,
            a.start_time,
            a.end_time,
            a.purpose,
            a.priority,
            a.status,
            a.approval_notes,
            a.cancellation_reason,
            a.checked_in_at,
            a.checked_out_at,
            a.created_at,
            a.updated_at,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         WHERE a.allocation_id = ?"
    );
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $connection->close();

    if (!$row) {
        return ['success' => false, 'message' => 'Booking not found'];
    }

    // Ownership check: only the owner or an admin can view
    if ((int)$row['booked_by_user_id'] !== $userId && $userRole !== 'admin') {
        return ['success' => false, 'message' => 'You do not have access to this booking'];
    }

    if ($row['resource_type'] === 'classroom') {
        $row['resource_name'] = trim(($row['room_number'] ?? '') . ' · ' . ($row['building'] ?? ''));
    } elseif ($row['resource_type'] === 'equipment') {
        $row['resource_name'] = $row['equipment_name'] ?? 'Equipment';
    } else {
        $row['resource_name'] = 'Resource';
    }

    return ['success' => true, 'data' => $row];
}

/**
 * Returns all allocations currently pending approval.
 * Includes who booked, and which resource.
 */
function get_pending_allocations()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT
            a.allocation_id,
            a.resource_type,
            a.resource_id,
            a.booked_by_user_id,
            a.allocation_date,
            a.end_date,
            a.start_time,
            a.end_time,
            a.purpose,
            a.priority,
            a.status,
            a.created_at,
            u.full_name AS booked_by_name,
            u.email     AS booked_by_email,
            u.role      AS booked_by_role,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         JOIN users u ON a.booked_by_user_id = u.user_id
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         WHERE a.status = 'pending'
         ORDER BY a.created_at ASC"
    );

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        if ($r['resource_type'] === 'classroom') {
            $r['resource_name'] = trim(($r['room_number'] ?? '') . ' · ' . ($r['building'] ?? ''));
        } elseif ($r['resource_type'] === 'equipment') {
            $r['resource_name'] = $r['equipment_name'] ?? 'Equipment';
        } else {
            $r['resource_name'] = 'Resource';
        }
        $rows[] = $r;
    }

    $connection->close();
    return ['success' => true, 'data' => $rows, 'count' => count($rows)];
}

/**
 * Approves a pending allocation.
 * Sets status='approved' and records approver_user_id.
 */
function approve_allocation($allocationId, $approverUserId, $approverRole)
{
    if ($approverRole !== 'admin') {
        return ['success' => false, 'message' => 'Only admins can approve bookings'];
    }
    if (!is_numeric($allocationId) || !is_numeric($approverUserId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId   = (int)$allocationId;
    $approverUserId = (int)$approverUserId;

    $connection = connect_database();

    $stmt = $connection->prepare(
        "SELECT 
            a.booked_by_user_id,
            a.status,
            a.allocation_date,
            a.start_time,
            a.end_time,
            a.resource_type,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         WHERE a.allocation_id = ?"
    );
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }
    if ($row['status'] !== 'pending') {
        $connection->close();
        return ['success' => false, 'message' => 'Only pending bookings can be approved'];
    }

    $stmt = $connection->prepare(
        "UPDATE allocations
         SET status = 'approved',
             approver_user_id = ?
         WHERE allocation_id = ?"
    );
    $stmt->bind_param("ii", $approverUserId, $allocationId);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to approve booking'];
    }
    $stmt->close();
    $connection->close();

    $resource = $row['resource_type'] === 'classroom'
        ? trim(($row['room_number'] ?? '') . ' · ' . ($row['building'] ?? ''))
        : ($row['equipment_name'] ?? 'Equipment');
    $startTime = substr($row['start_time'], 0, 5);
    $endTime   = substr($row['end_time'], 0, 5);
    $message   = "Your booking for {$resource} on {$row['allocation_date']} {$startTime}-{$endTime} has been approved.";

    create_notification(
        (int)$row['booked_by_user_id'],
        "Booking Approved",
        $message,
        "approval",
        $allocationId,
        "medium"
    );

    return ['success' => true, 'message' => 'Booking approved'];
}

/**
 * Rejects a pending allocation.
 * Sets status='rejected' and records approver + notes.
 */
function reject_allocation($allocationId, $approverUserId, $approverRole, $notes = '')
{
    if ($approverRole !== 'admin') {
        return ['success' => false, 'message' => 'Only admins can reject bookings'];
    }
    if (!is_numeric($allocationId) || !is_numeric($approverUserId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId   = (int)$allocationId;
    $approverUserId = (int)$approverUserId;

    $connection = connect_database();

    $stmt = $connection->prepare(
        "SELECT 
            a.booked_by_user_id,
            a.status,
            a.allocation_date,
            a.start_time,
            a.end_time,
            a.resource_type,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         WHERE a.allocation_id = ?"
    );
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }
    if ($row['status'] !== 'pending') {
        $connection->close();
        return ['success' => false, 'message' => 'Only pending bookings can be rejected'];
    }

    $stmt = $connection->prepare(
        "UPDATE allocations
         SET status = 'rejected',
             approver_user_id = ?,
             approval_notes = ?
         WHERE allocation_id = ?"
    );
    $notesParam = $notes !== '' ? $notes : null;
    $stmt->bind_param("isi", $approverUserId, $notesParam, $allocationId);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to reject booking'];
    }
    $stmt->close();
    $connection->close();

    $resource = $row['resource_type'] === 'classroom'
        ? trim(($row['room_number'] ?? '') . ' · ' . ($row['building'] ?? ''))
        : ($row['equipment_name'] ?? 'Equipment');
    $startTime = substr($row['start_time'], 0, 5);
    $endTime   = substr($row['end_time'], 0, 5);
    $message   = "Your booking for {$resource} on {$row['allocation_date']} {$startTime}-{$endTime} has been rejected.";
    if (!empty($notes)) {
        $message .= " Reason: " . trim($notes);
    }

    create_notification(
        (int)$row['booked_by_user_id'],
        "Booking Rejected",
        $message,
        "rejection",
        $allocationId,
        "high"
    );

    return ['success' => true, 'message' => 'Booking rejected'];
}


/**
 * Cancel an approved (or pending) booking.
 * Cannot cancel if already checked in, completed, rejected, or cancelled.
 */
function cancel_approved_booking($allocationId, $userId, $userRole, $reason = '')
{
    if (!is_numeric($allocationId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId = (int)$allocationId;
    $userId = (int)$userId;

    $connection = connect_database();

    $stmt = $connection->prepare(
        "SELECT 
            a.booked_by_user_id,
            a.status,
            a.checked_in_at,
            a.allocation_date,
            a.start_time,
            a.end_time,
            a.resource_type,
            c.room_number,
            c.building,
            e.equipment_name
         FROM allocations a
         LEFT JOIN classrooms c
             ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
         LEFT JOIN equipment e
             ON a.resource_type = 'equipment' AND a.resource_id = e.equipment_id
         WHERE a.allocation_id = ?"
    );
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }

    if ((int)$row['booked_by_user_id'] !== $userId && $userRole !== 'admin') {
        $connection->close();
        return ['success' => false, 'message' => 'You can only cancel your own booking'];
    }

    if (in_array($row['status'], ['completed', 'rejected', 'cancelled'])) {
        $connection->close();
        return ['success' => false, 'message' => 'This booking can no longer be cancelled'];
    }

    if ($row['checked_in_at'] !== null) {
        $connection->close();
        return ['success' => false, 'message' => 'You have already checked in. Cannot cancel now.'];
    }

    $reasonParam = $reason !== '' ? $reason : null;

    $stmt = $connection->prepare(
        "UPDATE allocations
         SET status = 'cancelled',
             cancellation_reason = ?
         WHERE allocation_id = ?"
    );
    $stmt->bind_param("si", $reasonParam, $allocationId);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to cancel booking'];
    }
    $stmt->close();
    $connection->close();

    // Only create notification if the actor is an admin and the booking belongs to someone else
    if ($userRole === 'admin' && (int)$row['booked_by_user_id'] !== $userId) {
        $resource = $row['resource_type'] === 'classroom'
            ? trim(($row['room_number'] ?? '') . ' · ' . ($row['building'] ?? ''))
            : ($row['equipment_name'] ?? 'Equipment');
        $startTime = substr($row['start_time'], 0, 5);
        $endTime   = substr($row['end_time'], 0, 5);
        $message   = "Your booking for {$resource} on {$row['allocation_date']} {$startTime}-{$endTime} was cancelled by an administrator.";
        if (!empty($reason)) {
            $message .= " Reason: " . trim($reason);
        }

        create_notification(
            (int)$row['booked_by_user_id'],
            "Booking Cancelled by Admin",
            $message,
            "general",
            $allocationId,
            "high"
        );
    }

    return ['success' => true, 'message' => 'Booking cancelled'];
}


/**
 * Check in to an approved booking.
 * Allowed from 15 minutes before start time until end time.
 */
function check_in_allocation($allocationId, $userId)
{
    if (!is_numeric($allocationId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId = (int)$allocationId;
    $userId = (int)$userId;

    $connection = connect_database();

    $stmt = $connection->prepare(
        "SELECT booked_by_user_id, status, allocation_date, start_time, end_time, checked_in_at
         FROM allocations
         WHERE allocation_id = ?"
    );
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }

    if ((int)$row['booked_by_user_id'] !== $userId) {
        $connection->close();
        return ['success' => false, 'message' => 'You can only check in to your own booking'];
    }

    if ($row['status'] !== 'approved') {
        $connection->close();
        return ['success' => false, 'message' => 'Only approved bookings can be checked in'];
    }

    if ($row['checked_in_at'] !== null) {
        $connection->close();
        return ['success' => false, 'message' => 'You have already checked in'];
    }

    // Get MySQL's current time as the single source of truth.
    // This avoids PHP-vs-MySQL timezone mismatches.
    $timeStmt = $connection->prepare("SELECT NOW() AS now_time");
    $timeStmt->execute();
    $nowRow = $timeStmt->get_result()->fetch_assoc();
    $timeStmt->close();

    $now   = new DateTime($nowRow['now_time']);
    $start = new DateTime($row['allocation_date'] . ' ' . $row['start_time']);

    $earlyWindow = clone $start;
    $earlyWindow->modify('-15 minutes');

    $lateWindow = clone $start;
    $lateWindow->modify('+1 hour');

    if ($now < $earlyWindow) {
        $connection->close();
        return [
            'success' => false,
            'message' => 'You can only check in starting 15 minutes before the booking'
        ];
    }

    if ($now > $lateWindow) {
        $connection->close();
        return [
            'success' => false,
            'message' => 'You are too late to check in (check-in window is 15 minutes before to 1 hour after start)'
        ];
    }

    $stmt = $connection->prepare("UPDATE allocations SET checked_in_at = NOW() WHERE allocation_id = ?");
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $stmt->close();
    $connection->close();

    return ['success' => true, 'message' => 'Checked in successfully'];
}


/**
 * Check out from an approved, checked-in booking.
 * Marks the booking as completed.
 */
function check_out_allocation($allocationId, $userId)
{
    if (!is_numeric($allocationId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $allocationId = (int)$allocationId;
    $userId = (int)$userId;

    $connection = connect_database();

    $stmt = $connection->prepare("SELECT booked_by_user_id, status, checked_in_at, checked_out_at FROM allocations WHERE allocation_id = ?");
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }

    if ((int)$row['booked_by_user_id'] !== $userId) {
        $connection->close();
        return ['success' => false, 'message' => 'You can only check out from your own booking'];
    }

    if ($row['status'] !== 'approved') {
        $connection->close();
        return ['success' => false, 'message' => 'Only approved bookings can be checked out'];
    }

    if ($row['checked_in_at'] === null) {
        $connection->close();
        return ['success' => false, 'message' => 'You must check in before checking out'];
    }

    if ($row['checked_out_at'] !== null) {
        $connection->close();
        return ['success' => false, 'message' => 'You have already checked out'];
    }

    $stmt = $connection->prepare("UPDATE allocations SET checked_out_at = NOW(), status = 'completed' WHERE allocation_id = ?");
    $stmt->bind_param("i", $allocationId);
    $stmt->execute();
    $stmt->close();
    $connection->close();

    return ['success' => true, 'message' => 'Checked out. Booking completed.'];
}


/**
 * Marks past approved bookings as 'completed' if they were never checked in.
 * Called automatically when a user fetches their bookings.
 * Returns the number of rows updated.
 */
function auto_complete_past_allocations()
{
    $connection = connect_database();

    $stmt = $connection->prepare("UPDATE allocations SET status = 'completed' WHERE status = 'approved' AND checked_in_at IS NULL AND CONCAT(COALESCE(end_date, allocation_date), ' ', end_time) < NOW()");

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    return $affected;
}

// ============================================================
// NOTIFICATIONS
// ============================================================

/**
 * Creates a new notification for a user.
 *
 * @param int $userId
 * @param string $title
 * @param string $message
 * @param string $type
 * @param int|null $relatedAllocationId
 * @param string $priority
 * @return array ['success' => bool, 'notification_id' => int|null, 'message' => string]
 */
function create_notification($userId, $title, $message, $type, $relatedAllocationId = null, $priority = 'medium')
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'notification_id' => null, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;
    $title = trim($title);
    $message = trim($message);

    if ($title === '' || $message === '') {
        return ['success' => false, 'notification_id' => null, 'message' => 'Title and message are required'];
    }

    $validTypes = [
        'booking_confirmation', 'reminder', 'overdue', 'rejection',
        'approval', 'maintenance', 'endorsement', 'strike_warning', 'general'
    ];
    if (!in_array($type, $validTypes, true)) {
        return ['success' => false, 'notification_id' => null, 'message' => 'Invalid notification type'];
    }

    $validPriorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $validPriorities, true)) {
        return ['success' => false, 'notification_id' => null, 'message' => 'Invalid notification priority'];
    }

    $allocationId = ($relatedAllocationId !== null && is_numeric($relatedAllocationId))
        ? (int)$relatedAllocationId
        : null;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "INSERT INTO notifications (user_id, title, message, type, related_allocation_id, priority)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isssis", $userId, $title, $message, $type, $allocationId, $priority);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'notification_id' => null, 'message' => 'Failed to create notification'];
    }

    $notificationId = $connection->insert_id;
    $stmt->close();
    $connection->close();

    return [
        'success' => true,
        'notification_id' => $notificationId,
        'message' => 'Notification created successfully'
    ];
}

/**
 * Retrieves notifications for a given user.
 *
 * @param int $userId
 * @param bool $unreadOnly
 * @return array ['success' => true, 'data' => [...], 'count' => N]
 */
function get_user_notifications($userId, $unreadOnly = false)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'data' => [], 'count' => 0, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $sql = "SELECT notification_id, title, message, type, is_read, read_at, related_allocation_id, priority, created_at
            FROM notifications
            WHERE user_id = ?";
    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['notification_id'] = (int)$row['notification_id'];
        $row['is_read'] = (bool)$row['is_read'];
        $row['related_allocation_id'] = $row['related_allocation_id'] !== null ? (int)$row['related_allocation_id'] : null;
        $rows[] = $row;
    }
    $stmt->close();
    $connection->close();

    return [
        'success' => true,
        'data' => $rows,
        'count' => count($rows)
    ];
}

/**
 * Returns the unread notification count for a user.
 *
 * @param int $userId
 * @return array ['success' => true, 'count' => N]
 */
function get_unread_notification_count($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'count' => 0, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $connection->close();

    return [
        'success' => true,
        'count' => (int)($row['total'] ?? 0)
    ];
}

/**
 * Marks a single notification as read if it belongs to the user.
 *
 * @param int $notificationId
 * @param int $userId
 * @return array ['success' => bool, 'message' => string]
 */
function mark_notification_read($notificationId, $userId)
{
    if (!is_numeric($notificationId) || !is_numeric($userId)) {
        return ['success' => false, 'message' => 'Invalid identifiers'];
    }
    $notificationId = (int)$notificationId;
    $userId = (int)$userId;

    $connection = connect_database();

    $checkStmt = $connection->prepare(
        "SELECT notification_id FROM notifications WHERE notification_id = ? AND user_id = ?"
    );
    $checkStmt->bind_param("ii", $notificationId, $userId);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$exists) {
        $connection->close();
        return ['success' => false, 'message' => 'Notification not found'];
    }

    $stmt = $connection->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?"
    );
    $stmt->bind_param("ii", $notificationId, $userId);
    $stmt->execute();
    $stmt->close();
    $connection->close();

    return ['success' => true, 'message' => 'Notification marked as read'];
}

/**
 * Marks all unread notifications as read for a given user.
 *
 * @param int $userId
 * @return array ['success' => true, 'affected' => N]
 */
function mark_all_notifications_read($userId)
{
    if (!is_numeric($userId)) {
        return ['success' => false, 'affected' => 0, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    return ['success' => true, 'affected' => $affected];
}

// ============================================================
// DASHBOARD STATISTICS
// ============================================================

function get_admin_dashboard_stats()
{
    $queries = [
        'total_users' => "SELECT COUNT(*) AS total FROM users",
        'total_students' => "SELECT COUNT(*) AS total FROM users WHERE role = 'student'",
        'total_instructors' => "SELECT COUNT(*) AS total FROM users WHERE role = 'instructor'",
        'total_staff' => "SELECT COUNT(*) AS total FROM users WHERE role = 'staff'",
        'pending_registrations' => "SELECT COUNT(*) AS total FROM users WHERE account_status = 'pending'",
        'total_classrooms' => "SELECT COUNT(*) AS total FROM classrooms",
        'total_equipment' => "SELECT COUNT(*) AS total FROM equipment",
        'pending_bookings' => "SELECT COUNT(*) AS total FROM allocations WHERE status = 'pending'",
        'bookings_today' => "SELECT COUNT(*) AS total FROM allocations WHERE allocation_date = CURDATE()",
    ];

    $connection = connect_database();
    $stats = [];

    foreach ($queries as $key => $query) {
        $stmt = $connection->prepare($query);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stats[$key] = (int)($row['total'] ?? 0);
        $stmt->close();
    }

    $connection->close();

    return ['success' => true, 'data' => $stats];
}

function get_instructor_dashboard_stats($instructorUserId)
{
    $instructorUserId = (int)$instructorUserId;
    $connection = connect_database();
    $stats = [];

    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS total
         FROM allocations
         WHERE booked_by_user_id = ? AND allocation_date >= CURDATE()"
    );
    $stmt->bind_param("i", $instructorUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stats['my_upcoming_bookings_count'] = (int)($row['total'] ?? 0);
    $stmt->close();

    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS total
         FROM allocations
         WHERE booked_by_user_id = ? AND status = 'pending'"
    );
    $stmt->bind_param("i", $instructorUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stats['my_pending_bookings_count'] = (int)($row['total'] ?? 0);
    $stmt->close();

    $connection->close();

    return ['success' => true, 'data' => $stats];
}

function get_department_head_stats($departmentId)
{
    $departmentId = (int)$departmentId;
    $connection = connect_database();
    $stats = [];

    foreach ([
        'dept_students_count' => 'students',
        'dept_instructors_count' => 'instructors',
        'dept_staff_count' => 'staff',
    ] as $key => $table) {
        $stmt = $connection->prepare(
            "SELECT COUNT(*) AS total FROM $table WHERE department_id = ?"
        );
        $stmt->bind_param("i", $departmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stats[$key] = (int)($row['total'] ?? 0);
        $stmt->close();
    }
    $stats['total_students'] = $stats['dept_students_count'];
    $stats['total_instructors'] = $stats['dept_instructors_count'];
    $stats['total_staff'] = $stats['dept_staff_count'];

    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS total
         FROM allocations
         WHERE department_id = ? AND status = 'pending'"
    );
    $stmt->bind_param("i", $departmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stats['dept_pending_bookings'] = (int)($row['total'] ?? 0);
    $stmt->close();

    $connection->close();

    return ['success' => true, 'data' => $stats];
}

function get_instructor_students($instructorUserId)
{
    if (!is_numeric($instructorUserId) || (int)$instructorUserId <= 0) {
        return ['success' => false, 'message' => 'Invalid instructor ID'];
    }
    $instructorUserId = (int)$instructorUserId;
    $connection = connect_database();
    $departmentStmt = $connection->prepare(
        "SELECT COALESCE(i.department_id, u.department_id) AS department_id
         FROM users u
         LEFT JOIN instructors i ON i.user_id = u.user_id
         WHERE u.user_id = ? AND u.role = 'instructor'"
    );
    if (!$departmentStmt) {
        $connection->close();
        return ['success' => false, 'message' => 'Could not find instructor department'];
    }
    $departmentStmt->bind_param("i", $instructorUserId);
    if (!$departmentStmt->execute()) {
        $departmentStmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Could not find instructor department'];
    }
    $instructor = $departmentStmt->get_result()->fetch_assoc();
    $departmentStmt->close();
    if (!$instructor || $instructor['department_id'] === null) {
        $connection->close();
        return ['success' => false, 'message' => 'Instructor department not found'];
    }
    $departmentId = (int)$instructor['department_id'];

    $stmt = $connection->prepare(
        "SELECT s.student_id, s.student_number, s.program, s.year_of_study,
                u.full_name, u.email
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         WHERE s.department_id = ?
         ORDER BY u.full_name"
    );
    if (!$stmt) {
        $connection->close();
        return ['success' => false, 'message' => 'Could not load department students'];
    }
    $stmt->bind_param("i", $departmentId);
    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Could not load department students'];
    }
    $result = $stmt->get_result();
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    $connection->close();
    return ['success' => true, 'data' => $students, 'count' => count($students)];
}

function get_admin_analytics()
{
    $connection = connect_database();
    $queries = [
        'total_users' => "SELECT COUNT(*) AS total FROM users",
        'total_bookings_today' => "SELECT COUNT(*) AS total FROM allocations WHERE allocation_date = CURDATE()",
        'total_bookings_this_week' => "SELECT COUNT(*) AS total FROM allocations WHERE allocation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    ];
    $stats = [];
    foreach ($queries as $key => $query) {
        $stmt = $connection->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            $connection->close();
            return ['success' => false, 'message' => 'Could not retrieve admin analytics'];
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stats[$key] = (int)($row['total'] ?? 0);
        $stmt->close();
    }

    $utilizationStmt = $connection->prepare(
        "SELECT
            (SELECT COUNT(DISTINCT a.resource_id)
             FROM allocations a
             JOIN classrooms c ON a.resource_type = 'classroom' AND a.resource_id = c.classroom_id
             WHERE a.allocation_date = CURDATE()) AS utilized,
            (SELECT COUNT(*) FROM classrooms) AS total"
    );
    if (!$utilizationStmt || !$utilizationStmt->execute()) {
        if ($utilizationStmt) {
            $utilizationStmt->close();
        }
        $connection->close();
        return ['success' => false, 'message' => 'Could not retrieve classroom utilization'];
    }
    $utilization = $utilizationStmt->get_result()->fetch_assoc();
    $utilizationStmt->close();
    $connection->close();

    $totalClassrooms = (int)($utilization['total'] ?? 0);
    $utilizedClassrooms = (int)($utilization['utilized'] ?? 0);
    $stats['utilization_rate'] = $totalClassrooms > 0
        ? round(($utilizedClassrooms / $totalClassrooms) * 100, 2)
        : 0;

    return ['success' => true, 'data' => $stats];
}

function get_all_users_admin($role = null, $status = null, $search = null)
{
    $role = ($role !== null && trim((string)$role) !== '') ? trim((string)$role) : null;
    $status = ($status !== null && trim((string)$status) !== '') ? trim((string)$status) : null;
    $search = ($search !== null && trim((string)$search) !== '') ? trim((string)$search) : null;
    $searchPattern = $search !== null ? '%' . $search . '%' : null;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT user_id, full_name, email, username, phone_number, role,
                account_status, is_active, department_id, last_login, created_at
         FROM users
         WHERE (? IS NULL OR role = ?)
           AND (? IS NULL OR account_status = ?)
           AND (? IS NULL OR full_name LIKE ? OR email LIKE ?)
         ORDER BY full_name"
    );
    $stmt->bind_param(
        "sssssss",
        $role,
        $role,
        $status,
        $status,
        $search,
        $searchPattern,
        $searchPattern
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    $stmt->close();
    $connection->close();

    return ['success' => true, 'data' => $users, 'count' => count($users)];
}

function admin_get_instructors()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT u.user_id, u.full_name, u.email, u.username, u.phone_number,
                u.role, u.account_status, u.is_active,
                COALESCE(u.department_id, i.department_id) AS department_id,
                d.department_name, i.specialization, i.qualification,
                i.years_of_experience, i.max_hours_per_week, u.last_login, u.created_at
         FROM instructors i
         JOIN users u ON u.user_id = i.user_id
         LEFT JOIN departments d
           ON d.department_id = COALESCE(u.department_id, i.department_id)
         WHERE u.role = 'instructor'
         ORDER BY u.full_name"
    );
    if (!$result) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to fetch instructors'];
    }

    $instructors = [];
    while ($row = $result->fetch_assoc()) {
        $instructors[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $instructors, 'count' => count($instructors)];
}

function admin_get_staff()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT u.user_id, u.full_name, u.email, u.username, u.phone_number,
                u.role, u.account_status, u.is_active,
                COALESCE(u.department_id, s.department_id) AS department_id,
                d.department_name, s.job_title, s.hire_date, s.is_available,
                u.last_login, u.created_at
         FROM staff s
         JOIN users u ON u.user_id = s.user_id
         LEFT JOIN departments d
           ON d.department_id = COALESCE(u.department_id, s.department_id)
         WHERE u.role = 'staff'
         ORDER BY u.full_name"
    );
    if (!$result) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to fetch staff'];
    }

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $staff, 'count' => count($staff)];
}

function admin_get_admins()
{
    $connection = connect_database();
    $result = $connection->query(
        "SELECT u.user_id, u.full_name, u.email, u.username, u.phone_number,
                u.role, u.account_status, u.is_active, u.department_id,
                d.department_name, u.last_login, u.created_at
         FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE u.role IN ('admin', 'department_head')
         ORDER BY u.full_name"
    );
    if (!$result) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to fetch admins'];
    }

    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $admins, 'count' => count($admins)];
}

function get_admin_user_details($userId)
{
    $userResult = get_user($userId);
    if (!$userResult['success']) {
        return $userResult;
    }

    $user = $userResult['data'];
    $profileResult = null;

    switch ($user['role']) {
        case 'student':
            $profileResult = get_student_by_user_id($userId);
            break;
        case 'instructor':
            $profileResult = get_instructor_by_user_id($userId);
            break;
        case 'staff':
            $profileResult = get_staff_by_user_id($userId);
            break;
    }

    $user['profile'] = ($profileResult !== null && $profileResult['success'])
        ? $profileResult['data']
        : null;

    return ['success' => true, 'data' => $user];
}

function create_admin_user_profile($connection, $userId, $role, $email, $data = [])
{
    $departmentId = !empty($data['department_id']) && is_numeric($data['department_id']) ? (int)$data['department_id'] : null;

    if ($role === 'student') {
        $studentNumber = !empty($data['student_number']) ? trim((string)$data['student_number']) : explode('@', $email)[0];
        $program = trim((string)($data['program'] ?? ''));
        $yearOfStudy = !empty($data['year_of_study']) ? (int)$data['year_of_study'] : 1;
        $stmt = $connection->prepare(
            "INSERT INTO students(user_id, student_number, department_id, program, year_of_study)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("isisi", $userId, $studentNumber, $departmentId, $program, $yearOfStudy);
    } elseif ($role === 'instructor') {
        $specialization = trim((string)($data['specialization'] ?? ''));
        $qualification = trim((string)($data['qualification'] ?? ''));
        $hireDate = !empty($data['hire_date']) ? $data['hire_date'] : null;
        $experience = !empty($data['years_of_experience']) ? (int)$data['years_of_experience'] : 0;
        $maxHours = !empty($data['max_hours_per_week']) ? (int)$data['max_hours_per_week'] : 20;
        $stmt = $connection->prepare(
            "INSERT INTO instructors(user_id, department_id, specialization, qualification, hire_date, years_of_experience, max_hours_per_week)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisssii", $userId, $departmentId, $specialization, $qualification, $hireDate, $experience, $maxHours);
    } elseif ($role === 'staff') {
        $jobTitle = trim((string)($data['job_title'] ?? ''));
        $hireDate = !empty($data['hire_date']) ? $data['hire_date'] : null;
        $notes = trim((string)($data['notes'] ?? ''));
        $stmt = $connection->prepare(
            "INSERT INTO staff(user_id, department_id, job_title, hire_date, notes)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisss", $userId, $departmentId, $jobTitle, $hireDate, $notes);
    } else {
        return true;
    }

    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function admin_create_user($data)
{
    $role = trim((string)($data['role'] ?? ''));
    if ($role === 'department_head') {
        $instructorUserId = filter_var(
            $data['instructor_user_id'] ?? null,
            FILTER_VALIDATE_INT
        );
        if (!$instructorUserId || $instructorUserId <= 0) {
            return ['success' => false, 'message' => 'Select a valid instructor to promote.'];
        }

        $connection = connect_database();
        if (!$connection->begin_transaction()) {
            $connection->close();
            return ['success' => false, 'message' => 'Could not promote instructor.'];
        }
        try {
            $stmt = $connection->prepare(
                "SELECT i.user_id
                 FROM instructors i
                 JOIN users u ON u.user_id = i.user_id
                 WHERE i.user_id = ? AND u.role = 'instructor'
                 FOR UPDATE"
            );
            $stmt->bind_param("i", $instructorUserId);
            $stmt->execute();
            $instructor = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$instructor) {
                throw new Exception('Selected user is not an instructor.');
            }

            $stmt = $connection->prepare(
                "UPDATE users SET role = 'department_head' WHERE user_id = ?"
            );
            $stmt->bind_param("i", $instructorUserId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Could not promote instructor.');
            }
            $stmt->close();

            if (!$connection->commit()) {
                throw new Exception('Could not promote instructor.');
            }
            $connection->close();
            return [
                'success' => true,
                'message' => 'Instructor promoted to Department Head.',
                'user_id' => $instructorUserId,
            ];
        } catch (Throwable $error) {
            $connection->rollback();
            $connection->close();
            return ['success' => false, 'message' => $error->getMessage()];
        }
    }

    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $username = trim((string)($data['username'] ?? ''));
    if ($username === '' && $email !== '') {
        $username = explode('@', $email)[0];
    }
    $password = (string)($data['password'] ?? '');
    if ($password === '') {
        $password = 'Welcome123!';
    }
    $allowedRoles = ['student', 'instructor', 'staff', 'admin'];
    $departmentId = !empty($data['department_id']) && is_numeric($data['department_id']) ? (int)$data['department_id'] : null;

    if ($fullName === '' || $email === '') {
        return ['success' => false, 'message' => 'Full name and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'A valid email address is required.'];
    }
    if (!in_array($role, $allowedRoles, true)) {
        return ['success' => false, 'message' => 'A valid user role is required.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }

    $connection = connect_database();
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) {
            throw new Exception('Email or username is already in use.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $phoneNumber = trim((string)($data['phone_number'] ?? ''));
        $accountStatus = 'active';
        $isActive = 1;
        $stmt = $connection->prepare(
            "INSERT INTO users(full_name, email, username, password_hash, phone_number, role, department_id, account_status, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "ssssssisi",
            $fullName,
            $email,
            $username,
            $passwordHash,
            $phoneNumber,
            $role,
            $departmentId,
            $accountStatus,
            $isActive
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Could not create user.');
        }
        $userId = $connection->insert_id;
        $stmt->close();

        if (!create_admin_user_profile($connection, $userId, $role, $email, $data)) {
            throw new Exception('Could not create the role-specific user profile.');
        }
        $connection->commit();
        $connection->close();
        return ['success' => true, 'message' => 'User created.', 'user_id' => $userId];
    } catch (Throwable $error) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => $error->getMessage()];
    }
}

function admin_update_user($data)
{
    $userId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $username = trim((string)($data['username'] ?? ''));
    $role = trim((string)($data['role'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $allowedRoles = ['student', 'instructor', 'staff', 'admin', 'department_head'];

    if (!$userId || $fullName === '' || $email === '' || $username === '') {
        return ['success' => false, 'message' => 'A valid user ID, full name, email, and username are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'A valid email address is required.'];
    }
    if (!in_array($role, $allowedRoles, true)) {
        return ['success' => false, 'message' => 'A valid user role is required.'];
    }
    if ($password !== '' && strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }

    $connection = connect_database();
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT role FROM users WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existing) {
            throw new Exception('User not found.');
        }

        $stmt = $connection->prepare(
            "SELECT user_id FROM users WHERE (email = ? OR username = ?) AND user_id <> ? LIMIT 1"
        );
        $stmt->bind_param("ssi", $email, $username, $userId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) {
            throw new Exception('Email or username is already in use.');
        }

        if ($existing['role'] !== $role) {
            foreach (['students', 'instructors', 'staff'] as $profileTable) {
                $stmt = $connection->prepare("DELETE FROM $profileTable WHERE user_id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $connection->prepare(
                "UPDATE users SET full_name = ?, email = ?, username = ?, role = ?, password_hash = ? WHERE user_id = ?"
            );
            $stmt->bind_param("sssssi", $fullName, $email, $username, $role, $passwordHash, $userId);
        } else {
            $stmt = $connection->prepare(
                "UPDATE users SET full_name = ?, email = ?, username = ?, role = ? WHERE user_id = ?"
            );
            $stmt->bind_param("ssssi", $fullName, $email, $username, $role, $userId);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Could not update user.');
        }
        $stmt->close();

        if ($existing['role'] !== $role && !create_admin_user_profile($connection, $userId, $role, $email)) {
            throw new Exception('Could not create the role-specific user profile.');
        }
        $connection->commit();
        $connection->close();
        return ['success' => true, 'message' => 'User updated.'];
    } catch (Throwable $error) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => $error->getMessage()];
    }
}

function get_courses($departmentId = null)
{
    $connection = connect_database();
    if ($departmentId !== null && is_numeric($departmentId) && (int)$departmentId > 0) {
        $deptId = (int)$departmentId;
        $stmt = $connection->prepare(
            "SELECT course_id, course_code, course_name, department_id, credits,
                    course_level, requires_lab
             FROM courses
             WHERE department_id = ?
             ORDER BY course_code"
        );
        $stmt->bind_param("i", $deptId);
    } else {
        $stmt = $connection->prepare(
            "SELECT course_id, course_code, course_name, department_id, credits,
                    course_level, requires_lab
             FROM courses
             ORDER BY course_code"
        );
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $row['requires_lab'] = (bool)$row['requires_lab'];
        $courses[] = $row;
    }

    $stmt->close();
    $connection->close();

    return ['success' => true, 'data' => $courses, 'count' => count($courses)];
}

function admin_create_classroom($data, $adminUserId = null)
{
    $required = ['room_number', 'building', 'capacity', 'room_type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            return ['success' => false, 'message' => "Field '$field' is required"];
        }
    }
    if (!is_numeric($data['capacity']) || (int)$data['capacity'] <= 0) {
        return ['success' => false, 'message' => 'Capacity must be a positive number'];
    }
    $departmentValue = array_key_exists('department_id', $data)
        ? $data['department_id']
        : ($data['responsible_department_id'] ?? null);
    if ($departmentValue !== null && $departmentValue !== ''
        && (!is_numeric($departmentValue) || (int)$departmentValue <= 0)) {
        return ['success' => false, 'message' => 'Department ID must be a positive number'];
    }

    $fields = [
        'room_number' => 's',
        'building' => 's',
        'department_id' => 'i',
        'floor' => 'i',
        'campus_location' => 's',
        'capacity' => 'i',
        'room_type' => 's',
        'has_projector' => 'i',
        'has_smart_board' => 'i',
        'has_computers' => 'i',
        'number_of_computers' => 'i',
        'has_wifi' => 'i',
        'has_air_conditioning' => 'i',
        'status' => 's',
    ];
    $values = [
        'room_number' => trim((string)$data['room_number']),
        'building' => trim((string)$data['building']),
        'department_id' => $departmentValue !== null && $departmentValue !== ''
            ? (int)$departmentValue : null,
        'floor' => isset($data['floor']) ? (int)$data['floor'] : 0,
        'campus_location' => trim((string)($data['campus_location'] ?? '')),
        'capacity' => (int)$data['capacity'],
        'room_type' => trim((string)$data['room_type']),
        'has_projector' => !empty($data['has_projector']) ? 1 : 0,
        'has_smart_board' => !empty($data['has_smart_board']) ? 1 : 0,
        'has_computers' => !empty($data['has_computers']) ? 1 : 0,
        'number_of_computers' => isset($data['number_of_computers'])
            ? (int)$data['number_of_computers'] : 0,
        'has_wifi' => !empty($data['has_wifi']) ? 1 : 0,
        'has_air_conditioning' => !empty($data['has_air_conditioning']) ? 1 : 0,
        'status' => trim((string)($data['status'] ?? 'available')),
    ];

    if ($adminUserId !== null && is_numeric($adminUserId)) {
        $fields['created_by'] = 'i';
        $values['created_by'] = (int)$adminUserId;
    }

    $columns = array_keys($fields);
    $types = implode('', array_values($fields));
    $params = array_values($values);

    $connection = connect_database();
    $stmt = $connection->prepare(
        "INSERT INTO classrooms (" . implode(', ', $columns) . ")
         VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")"
    );
    $stmt->bind_param($types, ...$params);
    $success = $stmt->execute();
    $classroomId = $success ? $connection->insert_id : null;
    $stmt->close();
    $connection->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Failed to create classroom'];
    }
    return [
        'success' => true,
        'message' => 'Classroom created',
        'classroom_id' => $classroomId,
    ];
}

function admin_update_classroom($id, $data, $adminUserId = null)
{
    if (!is_numeric($id) || (int)$id <= 0) {
        return ['success' => false, 'message' => 'Invalid classroom ID'];
    }
    if (!array_key_exists('department_id', $data) && array_key_exists('responsible_department_id', $data)) {
        $data['department_id'] = $data['responsible_department_id'];
    }

    $allowedFields = [
        'room_number' => 's',
        'building' => 's',
        'department_id' => 'i',
        'floor' => 'i',
        'campus_location' => 's',
        'capacity' => 'i',
        'room_type' => 's',
        'has_projector' => 'i',
        'has_smart_board' => 'i',
        'has_computers' => 'i',
        'number_of_computers' => 'i',
        'has_air_conditioning' => 'i',
        'has_wifi' => 'i',
        'requires_keycard' => 'i',
        'safety_level' => 's',
        'status' => 's',
    ];
    $sets = [];
    $params = [];
    $types = '';
    foreach ($allowedFields as $field => $type) {
        if (!array_key_exists($field, $data)) {
            continue;
        }
        $value = $data[$field];
        if ($field === 'department_id' && ($value === null || $value === '')) {
            $value = null;
        } elseif ($type === 'i') {
            if ($field === 'capacity' && (!is_numeric($value) || (int)$value <= 0)) {
                return ['success' => false, 'message' => 'Capacity must be a positive number'];
            }
            if ($field === 'department_id' && (!is_numeric($value) || (int)$value <= 0)) {
                return ['success' => false, 'message' => 'Department ID must be a positive number'];
            }
            if (in_array($field, [
                'has_projector', 'has_smart_board', 'has_computers',
                'has_air_conditioning', 'has_wifi', 'requires_keycard',
            ], true)) {
                $value = !empty($value) ? 1 : 0;
            } elseif (!is_numeric($value)) {
                return ['success' => false, 'message' => "Field '$field' must be numeric"];
            } else {
                $value = (int)$value;
            }
        } elseif ($value !== null) {
            $value = trim((string)$value);
        }
        $sets[] = "$field = ?";
        $params[] = $value;
        $types .= $type;
    }

    if ($adminUserId !== null && is_numeric($adminUserId)) {
        $sets[] = "updated_by = ?";
        $params[] = (int)$adminUserId;
        $types .= 'i';
    }

    if (!$sets) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $classroomId = (int)$id;
    $params[] = $classroomId;
    $types .= 'i';
    $connection = connect_database();
    $stmt = $connection->prepare(
        "UPDATE classrooms SET " . implode(', ', $sets) . " WHERE classroom_id = ?"
    );
    $stmt->bind_param($types, ...$params);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($success && $affected === 0) {
        $checkStmt = $connection->prepare(
            "SELECT classroom_id FROM classrooms WHERE classroom_id = ?"
        );
        $checkStmt->bind_param("i", $classroomId);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        if (!$exists) {
            $connection->close();
            return ['success' => false, 'message' => 'Classroom not found'];
        }
    }
    $connection->close();

    return $success
        ? ['success' => true, 'message' => 'Classroom updated']
        : ['success' => false, 'message' => 'Failed to update classroom'];
}

function admin_delete_classroom($id)
{
    if (!is_numeric($id) || (int)$id <= 0) {
        return ['success' => false, 'message' => 'Invalid classroom ID'];
    }
    $classroomId = (int)$id;
    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM classrooms WHERE classroom_id = ?");
    $stmt->bind_param("i", $classroomId);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Failed to delete classroom'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'Classroom not found'];
    }
    return ['success' => true, 'message' => 'Classroom deleted'];
}

function admin_create_equipment($data, $adminUserId = null)
{
    $required = ['equipment_name', 'equipment_type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            return ['success' => false, 'message' => "Field '$field' is required"];
        }
    }
    $departmentId = $data['responsible_department_id'] ?? null;
    if ($departmentId !== null && $departmentId !== '' && !is_numeric($departmentId)) {
        return ['success' => false, 'message' => 'Invalid responsible department ID'];
    }
    $departmentId = ($departmentId === '' || $departmentId === null)
        ? null : (int)$departmentId;
    $batteryHealth = $data['battery_health'] ?? null;
    if ($batteryHealth !== null && $batteryHealth !== '' &&
        (!is_numeric($batteryHealth) || (int)$batteryHealth < 0 || (int)$batteryHealth > 100)) {
        return ['success' => false, 'message' => 'Battery health must be between 0 and 100'];
    }
    $batteryHealth = ($batteryHealth === '' || $batteryHealth === null)
        ? null : (int)$batteryHealth;
    $safetyLevel = trim((string)($data['safety_level'] ?? 'low'));
    if (!in_array($safetyLevel, ['low', 'medium', 'high'], true)) {
        return ['success' => false, 'message' => 'Invalid safety level'];
    }
    $equipmentName = trim((string)$data['equipment_name']);
    $equipmentType = trim((string)$data['equipment_type']);
    $model = trim((string)($data['model'] ?? ''));
    $serialNumber = trim((string)($data['serial_number'] ?? ''));
    $status = trim((string)($data['status'] ?? 'available'));
    $currentLocation = trim((string)($data['current_location'] ?? ''));
    $adminId = ($adminUserId && is_numeric($adminUserId)) ? (int)$adminUserId : null;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "INSERT INTO equipment
            (equipment_name, equipment_type, model, serial_number, battery_health,
             safety_level, status, current_location, responsible_department_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "ssssisssii",
        $equipmentName,
        $equipmentType,
        $model,
        $serialNumber,
        $batteryHealth,
        $safetyLevel,
        $status,
        $currentLocation,
        $departmentId,
        $adminId
    );
    $success = $stmt->execute();
    $equipmentId = $success ? $connection->insert_id : null;
    $stmt->close();
    $connection->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Failed to create equipment'];
    }
    return [
        'success' => true,
        'message' => 'Equipment created',
        'equipment_id' => $equipmentId,
    ];
}

function admin_update_equipment($id, $data, $adminUserId = null)
{
    if (!is_numeric($id) || (int)$id <= 0) {
        return ['success' => false, 'message' => 'Invalid equipment ID'];
    }

    $allowedFields = [
        'equipment_name' => 's',
        'equipment_type' => 's',
        'model' => 's',
        'serial_number' => 's',
        'battery_health' => 'i',
        'safety_level' => 's',
        'status' => 's',
        'current_location' => 's',
        'responsible_department_id' => 'i',
    ];
    $sets = [];
    $params = [];
    $types = '';
    foreach ($allowedFields as $field => $type) {
        if (!array_key_exists($field, $data)) {
            continue;
        }
        $value = $data[$field];
        if ($field === 'battery_health') {
            if ($value === '' || $value === null) {
                $value = null;
            } elseif (!is_numeric($value) || (int)$value < 0 || (int)$value > 100) {
                return ['success' => false, 'message' => 'Battery health must be between 0 and 100'];
            } else {
                $value = (int)$value;
            }
        } elseif ($field === 'safety_level') {
            $value = trim((string)$value);
            if (!in_array($value, ['low', 'medium', 'high'], true)) {
                return ['success' => false, 'message' => 'Invalid safety level'];
            }
        } elseif ($field === 'responsible_department_id') {
            if ($value === '' || $value === null) {
                $value = null;
            } elseif (!is_numeric($value)) {
                return ['success' => false, 'message' => 'Invalid responsible department ID'];
            } else {
                $value = (int)$value;
            }
        } else {
            $value = trim((string)$value);
        }
        $sets[] = "$field = ?";
        $params[] = $value;
        $types .= $type;
    }

    if ($adminUserId !== null && is_numeric($adminUserId)) {
        $sets[] = "updated_by = ?";
        $params[] = (int)$adminUserId;
        $types .= 'i';
    }

    if (!$sets) {
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $equipmentId = (int)$id;
    $params[] = $equipmentId;
    $types .= 'i';
    $connection = connect_database();
    $stmt = $connection->prepare(
        "UPDATE equipment SET " . implode(', ', $sets) . " WHERE equipment_id = ?"
    );
    $stmt->bind_param($types, ...$params);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($success && $affected === 0) {
        $checkStmt = $connection->prepare(
            "SELECT equipment_id FROM equipment WHERE equipment_id = ?"
        );
        $checkStmt->bind_param("i", $equipmentId);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        if (!$exists) {
            $connection->close();
            return ['success' => false, 'message' => 'Equipment not found'];
        }
    }
    $connection->close();

    return $success
        ? ['success' => true, 'message' => 'Equipment updated']
        : ['success' => false, 'message' => 'Failed to update equipment'];
}

function admin_delete_equipment($id)
{
    if (!is_numeric($id) || (int)$id <= 0) {
        return ['success' => false, 'message' => 'Invalid equipment ID'];
    }
    $equipmentId = (int)$id;
    $connection = connect_database();
    $stmt = $connection->prepare("DELETE FROM equipment WHERE equipment_id = ?");
    $stmt->bind_param("i", $equipmentId);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Failed to delete equipment'];
    }
    if ($affected === 0) {
        return ['success' => false, 'message' => 'Equipment not found'];
    }
    return ['success' => true, 'message' => 'Equipment deleted'];
}

function reschedule_allocation($allocationId, $userId, $userRole, $newDate, $newStart, $newEnd)
{
    if (!is_numeric($allocationId) || (int)$allocationId <= 0 ||
        !is_numeric($userId) || (int)$userId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking or user ID'];
    }

    $allocationId = (int)$allocationId;
    $userId = (int)$userId;
    $newDate = trim((string)$newDate);
    $newStart = trim((string)$newStart);
    $newEnd = trim((string)$newEnd);

    $connection = connect_database();
    if (!$connection->begin_transaction()) {
        $connection->close();
        return ['success' => false, 'message' => 'Could not begin booking update'];
    }

    $stmt = $connection->prepare(
        "SELECT booked_by_user_id, status, checked_in_at, resource_type, resource_id
         FROM allocations
         WHERE allocation_id = ?
         FOR UPDATE"
    );
    if (!$stmt) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not load booking'];
    }
    $stmt->bind_param("i", $allocationId);
    if (!$stmt->execute()) {
        $stmt->close();
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not load booking'];
    }
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Booking not found'];
    }
    $bookingUserId = (int)$booking['booked_by_user_id'];
    if ($bookingUserId !== $userId && $userRole !== 'admin') {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'You can only reschedule your own booking'];
    }
    if (!in_array($booking['status'], ['pending', 'approved'], true)) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Only pending or approved bookings can be rescheduled'];
    }
    if ($booking['checked_in_at'] !== null) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'A checked-in booking cannot be rescheduled'];
    }

    $datetimeCheck = validate_booking_datetime($newDate, $newStart, $newEnd);
    if (!$datetimeCheck['success']) {
        $connection->rollback();
        $connection->close();
        return $datetimeCheck;
    }

    $resourceType = $booking['resource_type'];
    $resourceId = (int)$booking['resource_id'];
    $conflictStmt = $connection->prepare(
        "SELECT allocation_id
         FROM allocations
         WHERE resource_type = ?
           AND resource_id = ?
           AND allocation_date = ?
           AND status IN ('pending', 'approved')
           AND start_time < ?
           AND end_time > ?
           AND allocation_id <> ?
         LIMIT 1"
    );
    if (!$conflictStmt) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not check resource availability'];
    }
    $conflictStmt->bind_param(
        "sisssi",
        $resourceType,
        $resourceId,
        $newDate,
        $newEnd,
        $newStart,
        $allocationId
    );
    if (!$conflictStmt->execute()) {
        $conflictStmt->close();
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not check resource availability'];
    }
    $resourceConflict = $conflictStmt->get_result()->num_rows > 0;
    $conflictStmt->close();
    if ($resourceConflict) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'This resource is already booked for the selected time'];
    }

    $overlapStmt = $connection->prepare(
        "SELECT allocation_id
         FROM allocations
         WHERE booked_by_user_id = ?
           AND allocation_date = ?
           AND status IN ('pending', 'approved')
           AND start_time < ?
           AND end_time > ?
           AND allocation_id <> ?
         LIMIT 1"
    );
    if (!$overlapStmt) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not check user availability'];
    }
    $overlapStmt->bind_param(
        "isssi",
        $bookingUserId,
        $newDate,
        $newEnd,
        $newStart,
        $allocationId
    );
    if (!$overlapStmt->execute()) {
        $overlapStmt->close();
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not check user availability'];
    }
    $userOverlap = $overlapStmt->get_result()->num_rows > 0;
    $overlapStmt->close();
    if ($userOverlap) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'The booking owner already has an overlapping booking'];
    }

    $newStatus = 'pending';
    $updateStmt = $connection->prepare(
        "UPDATE allocations
         SET allocation_date = ?, start_time = ?, end_time = ?, status = ?
         WHERE allocation_id = ? AND checked_in_at IS NULL"
    );
    if (!$updateStmt) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not prepare booking update'];
    }
    $updateStmt->bind_param(
        "ssssi",
        $newDate,
        $newStart,
        $newEnd,
        $newStatus,
        $allocationId
    );
    $updated = $updateStmt->execute();
    $updateStmt->close();
    if (!$updated) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Booking could not be rescheduled'];
    }
    if (!$connection->commit()) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Could not save booking changes'];
    }
    $connection->close();

    return ['success' => true, 'message' => 'Booking rescheduled', 'status' => $newStatus];
}

function get_user_profile($userId)
{
    if (!is_numeric($userId) || (int)$userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;
    $connection = connect_database();
    $stmt = $connection->prepare(
        "SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.username,
            u.phone_number,
            u.role,
            u.account_status,
            u.profile_picture_url,
            COALESCE(u.department_id, s.department_id, i.department_id, st.department_id) AS department_id,
            d.department_name,
            s.student_number,
            s.course_id,
            c.course_code,
            c.course_name,
            s.program,
            s.year_of_study,
            i.specialization,
            st.job_title
         FROM users u
         LEFT JOIN students s ON s.user_id = u.user_id
         LEFT JOIN courses c ON c.course_id = s.course_id
         LEFT JOIN instructors i ON i.user_id = u.user_id
         LEFT JOIN staff st ON st.user_id = u.user_id
         LEFT JOIN departments d
            ON d.department_id = COALESCE(u.department_id, s.department_id, i.department_id, st.department_id)
         WHERE u.user_id = ?"
    );
    if (!$stmt) {
        $connection->close();
        return ['success' => false, 'message' => 'Could not load profile'];
    }
    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Could not load profile'];
    }
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $connection->close();

    if (!$profile) {
        return ['success' => false, 'message' => 'User not found'];
    }
    return ['success' => true, 'data' => $profile];
}

function update_my_profile($userId, $data)
{
    if (!is_numeric($userId) || (int)$userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    if (!isset($data['full_name']) || !is_string($data['full_name'])) {
        return ['success' => false, 'message' => 'A valid full_name is required'];
    }
    if (!array_key_exists('phone_number', $data) || !is_string($data['phone_number'])) {
        return ['success' => false, 'message' => 'A valid phone_number is required'];
    }

    $fullName = trim($data['full_name']);
    $phoneNumber = trim($data['phone_number']);
    if ($fullName === '' || strlen($fullName) > 255) {
        return ['success' => false, 'message' => 'Full name must be between 1 and 255 characters'];
    }
    if (strlen($phoneNumber) > 50) {
        return ['success' => false, 'message' => 'Phone number is too long'];
    }

    $userId = (int)$userId;
    $connection = connect_database();
    $stmt = $connection->prepare(
        "UPDATE users SET full_name = ?, phone_number = ? WHERE user_id = ?"
    );
    if (!$stmt) {
        $connection->close();
        return ['success' => false, 'message' => 'Could not update profile'];
    }
    $stmt->bind_param("ssi", $fullName, $phoneNumber, $userId);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!$success) {
        $connection->close();
        return ['success' => false, 'message' => 'Could not update profile'];
    }
    if ($affected === 0) {
        $checkStmt = $connection->prepare("SELECT user_id FROM users WHERE user_id = ?");
        if (!$checkStmt) {
            $connection->close();
            return ['success' => false, 'message' => 'Could not verify profile'];
        }
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        if (!$exists) {
            $connection->close();
            return ['success' => false, 'message' => 'User not found'];
        }
    }
    $connection->close();
    return ['success' => true, 'message' => 'Profile updated'];
}

function upload_user_avatar($userId, $file)
{
    if (!is_numeric($userId) || (int)$userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    $userId = (int)$userId;

    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No image file uploaded or upload error occurred'];
    }

    // Maximum file size: 5MB
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Image file must not exceed 5MB'];
    }

    $tempPath = $file['tmp_name'] ?? '';
    if (!file_exists($tempPath)) {
        return ['success' => false, 'message' => 'Uploaded temporary file not found'];
    }

    $imageInfo = @getimagesize($tempPath);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'The uploaded file is not a valid image'];
    }

    $mime = $imageInfo['mime'] ?? '';
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!array_key_exists($mime, $allowedMimes)) {
        return ['success' => false, 'message' => 'Unsupported image format. Allowed: JPG, PNG, GIF, WEBP'];
    }

    $extension = $allowedMimes[$mime];
    $targetDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            return ['success' => false, 'message' => 'Failed to create upload directory'];
        }
    }

    $fileName = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetFile = $targetDir . $fileName;

    if (!move_uploaded_file($tempPath, $targetFile)) {
        return ['success' => false, 'message' => 'Failed to save uploaded image file'];
    }

    // Formulate publicly reachable URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $avatarUrl = $protocol . $host . $baseDir . '/uploads/avatars/' . $fileName;

    $connection = connect_database();
    // Ensure column exists
    @$connection->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_url VARCHAR(255) DEFAULT NULL");

    $stmt = $connection->prepare("UPDATE users SET profile_picture_url = ? WHERE user_id = ?");
    if (!$stmt) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to prepare database update'];
    }
    $stmt->bind_param("si", $avatarUrl, $userId);
    $executed = $stmt->execute();
    $stmt->close();
    $connection->close();

    if (!$executed) {
        return ['success' => false, 'message' => 'Failed to update user profile picture in database'];
    }

    return [
        'success' => true,
        'message' => 'Profile picture uploaded successfully',
        'data' => [
            'profile_picture_url' => $avatarUrl,
            'file_name' => $fileName,
        ]
    ];
}

function upload_resource_image($resourceType, $resourceId, $file)
{
    $resources = [
        'classroom' => ['table' => 'classrooms', 'id_column' => 'classroom_id'],
        'equipment' => ['table' => 'equipment', 'id_column' => 'equipment_id'],
    ];
    if (!isset($resources[$resourceType])) {
        return ['success' => false, 'message' => 'Invalid resource type'];
    }
    if (!is_numeric($resourceId) || (int)$resourceId <= 0) {
        return ['success' => false, 'message' => 'Invalid resource ID'];
    }
    $resourceId = (int)$resourceId;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No image file uploaded or upload error occurred'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Image file must not exceed 5MB'];
    }

    $tempPath = $file['tmp_name'] ?? '';
    if ($tempPath === '' || !file_exists($tempPath)) {
        return ['success' => false, 'message' => 'Uploaded temporary file not found'];
    }
    $imageInfo = @getimagesize($tempPath);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'The uploaded file is not a valid image'];
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $mime = $imageInfo['mime'] ?? '';
    if (!isset($allowedMimes[$mime])) {
        return ['success' => false, 'message' => 'Unsupported image format. Allowed: JPG, PNG, GIF, WEBP'];
    }

    $targetDir = __DIR__ . '/uploads/resources/' . $resourceType . '/';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        return ['success' => false, 'message' => 'Failed to create upload directory'];
    }

    $fileName = $resourceType . '_' . $resourceId . '_' . time() . '_'
        . bin2hex(random_bytes(4)) . '.' . $allowedMimes[$mime];
    $targetFile = $targetDir . $fileName;
    if (!move_uploaded_file($tempPath, $targetFile)) {
        return ['success' => false, 'message' => 'Failed to save uploaded image file'];
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $imageUrl = $protocol . $host . $baseDir . '/uploads/resources/'
        . $resourceType . '/' . $fileName;

    $resource = $resources[$resourceType];
    $connection = connect_database();
    $stmt = $connection->prepare(
        "UPDATE {$resource['table']} SET profile_image_url = ? WHERE {$resource['id_column']} = ?"
    );
    if (!$stmt) {
        $connection->close();
        unlink($targetFile);
        return ['success' => false, 'message' => 'Failed to prepare database update'];
    }
    $stmt->bind_param("si", $imageUrl, $resourceId);
    $executed = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$executed || $affectedRows === 0) {
        unlink($targetFile);
        return ['success' => false, 'message' => 'Resource not found or image URL could not be saved'];
    }

    return [
        'success' => true,
        'message' => 'Resource image uploaded successfully',
        'data' => [
            'profile_image_url' => $imageUrl,
            'file_name' => $fileName,
        ],
    ];
}

function admin_create_course($data, $adminUserId = null)
{
    $code = strtoupper(trim((string)($data['course_code'] ?? '')));
    $name = trim((string)($data['course_name'] ?? ''));
    if ($code === '' || $name === '') {
        return ['success' => false, 'message' => 'Course code and course name are required'];
    }
    $departmentId = isset($data['department_id']) && is_numeric($data['department_id']) ? (int)$data['department_id'] : null;
    $credits = isset($data['credits']) && is_numeric($data['credits']) ? (int)$data['credits'] : 3;
    $level = trim((string)($data['course_level'] ?? 'diploma'));
    if (!in_array($level, ['certificate', 'diploma', 'degree', 'advanced_diploma'], true)) {
        $level = 'diploma';
    }
    $requiresLab = !empty($data['requires_lab']) ? 1 : 0;
    $adminId = ($adminUserId && is_numeric($adminUserId)) ? (int)$adminUserId : null;

    $connection = connect_database();
    $stmt = $connection->prepare(
        "INSERT INTO courses (course_code, course_name, department_id, credits, course_level, requires_lab, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssiisii", $code, $name, $departmentId, $credits, $level, $requiresLab, $adminId);
    $success = $stmt->execute();
    $courseId = $success ? $connection->insert_id : null;
    $stmt->close();
    $connection->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Failed to create course. Course code may already exist.'];
    }
    return [
        'success' => true,
        'message' => 'Course created',
        'data' => [
            'course_id' => $courseId,
            'course_code' => $code,
            'course_name' => $name,
        ]
    ];
}

function admin_update_course($id, $data, $adminUserId = null)
{
    if (!is_numeric($id) || (int)$id <= 0) {
        return ['success' => false, 'message' => 'Invalid course ID'];
    }
    $courseId = (int)$id;
    $connection = connect_database();
    $sets = [];
    $params = [];
    $types = '';

    if (isset($data['course_code']) && trim($data['course_code']) !== '') {
        $sets[] = "course_code = ?";
        $params[] = strtoupper(trim($data['course_code']));
        $types .= 's';
    }
    if (isset($data['course_name']) && trim($data['course_name']) !== '') {
        $sets[] = "course_name = ?";
        $params[] = trim($data['course_name']);
        $types .= 's';
    }
    if (array_key_exists('department_id', $data)) {
        $sets[] = "department_id = ?";
        $params[] = (isset($data['department_id']) && is_numeric($data['department_id'])) ? (int)$data['department_id'] : null;
        $types .= 'i';
    }
    if (isset($data['credits']) && is_numeric($data['credits'])) {
        $sets[] = "credits = ?";
        $params[] = (int)$data['credits'];
        $types .= 'i';
    }
    if (isset($data['course_level']) && trim($data['course_level']) !== '') {
        $sets[] = "course_level = ?";
        $params[] = trim($data['course_level']);
        $types .= 's';
    }
    if (array_key_exists('requires_lab', $data)) {
        $sets[] = "requires_lab = ?";
        $params[] = !empty($data['requires_lab']) ? 1 : 0;
        $types .= 'i';
    }

    if ($adminUserId && is_numeric($adminUserId)) {
        $sets[] = "updated_by = ?";
        $params[] = (int)$adminUserId;
        $types .= 'i';
    }

    if (!$sets) {
        $connection->close();
        return ['success' => false, 'message' => 'No valid fields to update'];
    }

    $params[] = $courseId;
    $types .= 'i';
    $stmt = $connection->prepare("UPDATE courses SET " . implode(', ', $sets) . " WHERE course_id = ?");
    $stmt->bind_param($types, ...$params);
    $success = $stmt->execute();
    $stmt->close();
    $connection->close();

    return $success ? ['success' => true, 'message' => 'Course updated'] : ['success' => false, 'message' => 'Failed to update course'];
}

function admin_create_student($data, $adminUserId = null)
{
    $studentNumber = trim((string)($data['student_number'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));

    if ($email === '' && $studentNumber !== '') {
        $email = $studentNumber . '@student.school.ac.ug';
    }

    if ($email === '') {
        return ['success' => false, 'message' => 'Email or student number is required'];
    }

    // Must strictly validate student email format
    $emailValidation = validate_email_for_role($email, 'student');
    if (!$emailValidation['valid']) {
        return ['success' => false, 'message' => $emailValidation['message']];
    }
    $email = $emailValidation['email'];

    if ($studentNumber === '') {
        $studentNumber = explode('@', $email)[0];
    }

    $fullName = trim((string)($data['full_name'] ?? ''));
    if ($fullName === '') {
        return ['success' => false, 'message' => 'Full name is required'];
    }

    $departmentId = isset($data['department_id']) && is_numeric($data['department_id']) ? (int)$data['department_id'] : null;
    $courseId = isset($data['course_id']) && is_numeric($data['course_id']) ? (int)$data['course_id'] : null;
    $program = trim((string)($data['program'] ?? ''));
    $yearOfStudy = isset($data['year_of_study']) ? (int)$data['year_of_study'] : 1;
    $adminId = ($adminUserId && is_numeric($adminUserId)) ? (int)$adminUserId : null;

    $connection = connect_database();

    // Ensure department exists if provided
    if ($departmentId !== null) {
        $dStmt = $connection->prepare("SELECT department_id FROM departments WHERE department_id = ?");
        $dStmt->bind_param("i", $departmentId);
        $dStmt->execute();
        if ($dStmt->get_result()->num_rows === 0) {
            $dStmt->close();
            $connection->close();
            return ['success' => false, 'message' => 'Selected department does not exist'];
        }
        $dStmt->close();
    }

    // Ensure course exists if provided
    if ($courseId !== null) {
        $cStmt = $connection->prepare("SELECT course_id FROM courses WHERE course_id = ?");
        $cStmt->bind_param("i", $courseId);
        $cStmt->execute();
        if ($cStmt->get_result()->num_rows === 0) {
            $cStmt->close();
            $connection->close();
            return ['success' => false, 'message' => 'Selected course does not exist'];
        }
        $cStmt->close();
    }

    // Check if student_number already exists in students table
    $checkStmt = $connection->prepare("SELECT student_id FROM students WHERE student_number = ?");
    $checkStmt->bind_param("s", $studentNumber);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $checkStmt->close();
        $connection->close();
        return ['success' => false, 'message' => 'Student number already exists in pre-approved list'];
    }
    $checkStmt->close();

    $stmt = $connection->prepare(
        "INSERT INTO students (student_number, full_name, email, department_id, course_id, program, year_of_study, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssiisii", $studentNumber, $fullName, $email, $departmentId, $courseId, $program, $yearOfStudy, $adminId);
    $success = $stmt->execute();
    $newId = $success ? $connection->insert_id : null;
    $stmt->close();
    $connection->close();

    if (!$success) {
        return ['success' => false, 'message' => 'Failed to pre-approve student'];
    }

    return [
        'success' => true,
        'message' => 'Student pre-approved successfully',
        'data' => [
            'student_id' => $newId,
            'student_number' => $studentNumber,
            'full_name' => $fullName,
            'email' => $email,
            'department_id' => $departmentId,
            'course_id' => $courseId,
        ]
    ];
}

function admin_get_students()
{
    $connection = connect_database();
    $query = "
        SELECT 
            s.student_id,
            s.user_id,
            s.student_number,
            COALESCE(u.full_name, s.full_name) AS full_name,
            COALESCE(u.email, s.email) AS email,
            COALESCE(u.phone_number, '') AS phone_number,
            COALESCE(u.department_id, s.department_id) AS department_id,
            COALESCE(u.role, 'student') AS role,
            d.department_name,
            s.course_id,
            c.course_code,
            c.course_name,
            s.program,
            s.year_of_study,
            s.enrollment_status,
            u.account_status,
            CASE WHEN s.user_id IS NOT NULL THEN 1 ELSE 0 END AS is_registered,
            s.created_at,
            s.created_by
        FROM students s
        LEFT JOIN users u ON u.user_id = s.user_id
        LEFT JOIN departments d ON d.department_id = COALESCE(u.department_id, s.department_id)
        LEFT JOIN courses c ON c.course_id = s.course_id
        ORDER BY s.student_id DESC
    ";

    $result = $connection->query($query);
    if (!$result) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to fetch students'];
    }

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $row['is_registered'] = (bool)$row['is_registered'];
        $students[] = $row;
    }
    $connection->close();

    return ['success' => true, 'data' => $students, 'count' => count($students)];
}

// =============================================================================
// SUPPORT MESSAGES
// =============================================================================

function send_support_message($userId, $subject, $message)
{
    $subject = trim((string)$subject);
    $message = trim((string)$message);
    if ($subject === '' || $message === '') {
        return ['success' => false, 'message' => 'Subject and message are required.'];
    }

    $connection = connect_database();
    if (!$connection->begin_transaction()) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to send message.'];
    }

    $stmt = $connection->prepare(
        "INSERT INTO support_messages (user_id, subject, message) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("iss", $userId, $subject, $message);
    $success = $stmt->execute();
    $newId = $success ? $connection->insert_id : null;
    $stmt->close();

    if (!$success) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to send message.'];
    }

    $notificationStmt = $connection->prepare(
        "INSERT INTO notifications (user_id, title, message, type)
         SELECT admin_users.user_id, 'New Support Message',
                CONCAT('New support message from ', sender.full_name), 'general'
         FROM users AS admin_users
         JOIN users AS sender ON sender.user_id = ?
         WHERE admin_users.role = 'admin'"
    );
    $notificationStmt->bind_param("i", $userId);
    $notificationsCreated = $notificationStmt->execute();
    $notificationStmt->close();

    if (!$notificationsCreated || !$connection->commit()) {
        $connection->rollback();
        $connection->close();
        return ['success' => false, 'message' => 'Failed to send message notifications.'];
    }

    $connection->close();
    return ['success' => true, 'message' => 'Message sent successfully.', 'data' => ['id' => $newId]];
}

function get_support_messages($userId = null)
{
    $connection = connect_database();
    if ($userId !== null) {
        $stmt = $connection->prepare(
            "SELECT sm.*, u.full_name, u.email, u.role
             FROM support_messages sm
             LEFT JOIN users u ON u.user_id = sm.user_id
             WHERE sm.user_id = ?
             ORDER BY sm.created_at DESC"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $result = $connection->query(
            "SELECT sm.*, u.full_name, u.email, u.role
             FROM support_messages sm
             LEFT JOIN users u ON u.user_id = sm.user_id
             ORDER BY sm.created_at DESC"
        );
    }

    if (!$result) {
        $connection->close();
        return ['success' => false, 'message' => 'Failed to fetch messages.'];
    }

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    $connection->close();
    return ['success' => true, 'data' => $messages, 'count' => count($messages)];
}

function reply_support_message($messageId, $reply)
{
    $reply = trim((string)$reply);
    if ($reply === '') {
        return ['success' => false, 'message' => 'Reply text is required.'];
    }
    $connection = connect_database();
    $stmt = $connection->prepare(
        "UPDATE support_messages SET admin_reply = ?, replied_at = NOW(), status = 'replied' WHERE message_id = ?"
    );
    $stmt->bind_param("si", $reply, $messageId);
    $success = $stmt->execute();
    $affected = $connection->affected_rows;
    $stmt->close();
    $connection->close();

    if (!$success || $affected === 0) {
        return ['success' => false, 'message' => 'Message not found or could not be updated.'];
    }
    return ['success' => true, 'message' => 'Reply sent.'];
}
