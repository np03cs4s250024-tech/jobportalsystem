<?php
// Disable internal error reporting for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';      // This now provides getBody(), jsonResponse(), and sanitize()
require_once '../config/session.php';
require_once '../config/otp.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * --- THE UPDATE ---
 * We no longer define getBody() here as it is handled by db.php
 */
$data = getBody();
$action = $_GET['action'] ?? $data['action'] ?? '';

// ── STEP 1: Send OTP (Registration or Reset) ──────────────────────────────────
if ($method === 'POST' && ($action === 'send-otp' || $action === 'forgot-password')) {
    $email = sanitize($data['email'] ?? '');
    
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $userExists = $stmt->fetch();

    if ($action === 'send-otp') {
        if ($userExists) jsonResponse(['error' => 'Email already registered.'], 409);
        $pass = $data['password'] ?? '';
        if (strlen($pass) < 6) jsonResponse(['error' => 'Password too short.'], 400);

        $_SESSION['pending_reg'] = [
            'name'     => sanitize($data['name'] ?? 'User'),
            'email'    => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'role'     => $data['role'] ?? 'seeker',
        ];
    } else {
        if (!$userExists) jsonResponse(['error' => 'No account found with this email.'], 404);
        $_SESSION['reset_email'] = $email;
    }

    $otp = generateOtp($email);
    sendOtpEmail($email, $otp);
    jsonResponse(['success' => true, 'message' => 'Verification code sent.', 'dev_otp' => $otp]);
}

// ── STEP 2: Verify OTP & Create Account ──────────────────────────────────────
if ($method === 'POST' && $action === 'verify-otp') {
    $email = sanitize($data['email'] ?? '');
    $otp   = trim($data['otp'] ?? '');

    if (!verifyOtp($email, $otp)) jsonResponse(['error' => 'Invalid or expired code.'], 400);

    $pending = $_SESSION['pending_reg'] ?? null;
    if (!$pending || $pending['email'] !== $email) jsonResponse(['error' => 'Session mismatch.'], 400);

    $db = getDB();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
        $stmt->execute([$pending['name'], $pending['email'], $pending['password'], $pending['role']]);
        $newId = (int)$db->lastInsertId();

        if ($pending['role'] === 'seeker') {
            $db->prepare('INSERT INTO seeker_profiles (user_id) VALUES (?)')->execute([$newId]);
        } else if ($pending['role'] === 'employer') {
            $db->prepare('INSERT INTO employer_profiles (user_id) VALUES (?)')->execute([$newId]);
        }

        $db->commit();
        clearOtp();
        unset($_SESSION['pending_reg']);
        jsonResponse(['success' => true, 'message' => 'Account created!']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonResponse(['error' => 'Registration failed: ' . $e->getMessage()], 500);
    }
}

// ── STEP 3: Password Reset ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'reset-password') {
    $email = $_SESSION['reset_email'] ?? sanitize($data['email'] ?? '');
    $otp   = trim($data['otp'] ?? '');
    $new_pass = $data['new_password'] ?? '';

    if (!$email) jsonResponse(['error' => 'Session expired. Request a new code.'], 400);
    if (!verifyOtp($email, $otp)) jsonResponse(['error' => 'Invalid or expired code.'], 400);
    if (strlen($new_pass) < 6) jsonResponse(['error' => 'Password too short.'], 400);

    $db = getDB();
    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE users SET password = ? WHERE email = ?');
    
    if ($stmt->execute([$hashed, $email])) {
        unset($_SESSION['reset_email']);
        clearOtp();
        jsonResponse(['success' => true, 'message' => 'Password updated!']);
    } else {
        jsonResponse(['error' => 'Update failed.'], 500);
    }
}

// ── LOGIN ────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $email = trim($data['email'] ?? '');
    $pass  = $data['password'] ?? '';

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Verify hashed password
    if (!$user || !password_verify($pass, $user['password'])) {
        jsonResponse(['error' => 'Invalid email or password.'], 401);
    }

    unset($user['password']);
    $_SESSION['user'] = $user;
    session_write_close();
    jsonResponse(['success' => true, 'user' => $user]);
}

// ── LOGOUT & ME ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    session_unset();
    session_destroy();
    jsonResponse(['success' => true]);
}

if ($method === 'GET' && $action === 'me') {
    $user = $_SESSION['user'] ?? null;
    jsonResponse(['success' => !!$user, 'user' => $user], $user ? 200 : 401);
}

// Fallback error
jsonResponse(['error' => "Action '$action' not found."], 404);