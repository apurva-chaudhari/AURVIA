<?php

// ============================================================
// AURVIA AUTH FUNCTIONS
// ============================================================

require_once __DIR__ . "/functions.php";


// ------------------------------------------------------------
// Validate registration input (pure: no DB). Returns [field => msg]
// ------------------------------------------------------------

function validateRegistration($name, $email, $phone, $password, $confirm)
{
    $errors = [];

    if (!isValidName($name)) {
        $errors['name'] = 'Enter a valid name (2-100 letters).';
    }

    if ($email === '' || strlen($email) > 150 || !isValidEmail($email)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (!isValidPhone($phone)) {
        $errors['phone'] = 'Enter a valid 10-digit Indian mobile number.';
    }

    if (!isValidPassword($password)) {
        $errors['password'] = 'Password must be 8-72 characters with an uppercase letter, a lowercase letter and a number.';
    }

    if ($password !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    return $errors;
}


// ------------------------------------------------------------
// Register a new customer
// returns ['ok' => bool, 'errors' => [...], 'user_id' => int]
// ------------------------------------------------------------

function registerUser($conn, $name, $email, $phone, $password, $confirm)
{
    $name = cleanInput($name);
    $email = strtolower(cleanInput($email));
    $phone = cleanInput($phone);

    $errors = validateRegistration($name, $email, $phone, $password, $confirm);

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($exists) {
        return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $conn->prepare("
            INSERT INTO users (name, email, phone, password, role, status)
            VALUES (?, ?, ?, ?, 'customer', 'active')
        ");
        $stmt->bind_param("ssss", $name, $email, $phone, $hash);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
    } catch (mysqli_sql_exception $ex) {
        // 1062 = duplicate key (race condition between check and insert)
        if ((int)$ex->getCode() === 1062) {
            return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
        }
        throw $ex;
    }

    return ['ok' => true, 'errors' => [], 'user_id' => $id];
}


// ------------------------------------------------------------
// Login throttling (per email + IP)
// ------------------------------------------------------------

function countRecentFailures($conn, $email, $ip)
{
    $minutes = (int)LOCKOUT_MINUTES;

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM login_attempts
        WHERE email = ?
          AND ip_address = ?
          AND attempted_at > (NOW() - INTERVAL $minutes MINUTE)
    ");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$row['total'];
}

function isLoginLocked($conn, $email, $ip)
{
    return countRecentFailures($conn, $email, $ip) >= MAX_LOGIN_ATTEMPTS;
}

function recordFailedLogin($conn, $email, $ip)
{
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->close();
}

function clearFailedLogins($conn, $email, $ip)
{
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->close();
}


// ------------------------------------------------------------
// Verify credentials. Does NOT touch the session.
// returns ['ok' => bool, 'message' => string, 'user' => array|null]
// ------------------------------------------------------------

function attemptLogin($conn, $email, $password, $ip = null)
{
    $ip = $ip ?? clientIp();
    $email = strtolower(cleanInput($email));
    $generic = 'Invalid email or password.';

    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Please enter your email and password.', 'user' => null];
    }

    if (isLoginLocked($conn, $email, $ip)) {
        return [
            'ok' => false,
            'message' => 'Too many failed attempts. Please try again after ' . LOCKOUT_MINUTES . ' minutes.',
            'user' => null
        ];
    }

    $stmt = $conn->prepare("
        SELECT id, name, email, password, role, status
        FROM users
        WHERE email = ?
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // same work whether or not the user exists (timing-attack resistance)
    static $dummyHash = null;
    $dummyHash = $dummyHash ?? password_hash('aurvia-dummy', PASSWORD_DEFAULT);
    $hash = $user['password'] ?? $dummyHash;
    $valid = password_verify($password, $hash);

    if (!$user || !$valid) {
        recordFailedLogin($conn, $email, $ip);
        return ['ok' => false, 'message' => $generic, 'user' => null];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Your account has been blocked. Please contact support.', 'user' => null];
    }

    clearFailedLogins($conn, $email, $ip);

    // upgrade hash if PHP's default algorithm/cost changed
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $newHash, $user['id']);
        $stmt->execute();
        $stmt->close();
    }

    unset($user['password']);

    return ['ok' => true, 'message' => 'Login successful.', 'user' => $user];
}


// ------------------------------------------------------------
// Start / end a logged-in session
// ------------------------------------------------------------

function loginUser($user)
{
    // prevents session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function logoutUser()
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}


// ------------------------------------------------------------
// Forgot / reset password
// ------------------------------------------------------------

// Returns the plain token (to be emailed) or null if no such user.
function createPasswordReset($conn, $email)
{
    $email = strtolower(cleanInput($email));

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return null;
    }

    $userId = (int)$user['id'];

    // invalidate older tokens
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $minutes = (int)RESET_TOKEN_MINUTES;

    $stmt = $conn->prepare("
        INSERT INTO password_resets (user_id, token_hash, expires_at)
        VALUES (?, ?, NOW() + INTERVAL $minutes MINUTE)
    ");
    $stmt->bind_param("is", $userId, $hash);
    $stmt->execute();
    $stmt->close();

    return $token;
}

// Returns the reset row (with user_id) if token is valid, else null.
function findValidReset($conn, $token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $hash = hash('sha256', $token);

    $stmt = $conn->prepare("
        SELECT id, user_id
        FROM password_resets
        WHERE token_hash = ?
          AND used_at IS NULL
          AND expires_at > NOW()
    ");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// returns ['ok' => bool, 'message' => string]
function resetPassword($conn, $token, $password, $confirm)
{
    $reset = findValidReset($conn, $token);

    if (!$reset) {
        return ['ok' => false, 'message' => 'This reset link is invalid or has expired.'];
    }

    if (!isValidPassword($password)) {
        return ['ok' => false, 'message' => 'Password must be 8-72 characters with an uppercase letter, a lowercase letter and a number.'];
    }

    if ($password !== $confirm) {
        return ['ok' => false, 'message' => 'Passwords do not match.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $userId = (int)$reset['user_id'];

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $userId);
        $stmt->execute();
        $stmt->close();

        // token can be used only once; remove all tokens of this user
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        // reset lockouts for this account
        $stmt = $conn->prepare("
            DELETE la FROM login_attempts la
            JOIN users u ON u.email = la.email
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }

    return ['ok' => true, 'message' => 'Password updated. You can now login.'];
}

?>
