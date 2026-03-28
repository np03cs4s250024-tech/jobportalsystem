<?php
/**
 * ── USER MANAGEMENT CONTROLLER ──
 * Handles CRUD for Users with role-based access control.
 */
require_once '../config/core.php'; // Includes session, auth, and helpers
require_once '../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();
$data   = getRequestData();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// --- 1. READ (GET) ---
if ($method === 'GET') {
    $current = requireLogin();

    // Specific User Details
    if ($id) {
        // Only Admins or the User themselves can see their details
        if ($current['role'] !== 'admin' && (int)$current['id'] !== $id) {
            jsonResponse(['error' => 'Forbidden.'], 403);
        }

        $stmt = $db->prepare('SELECT id, name, email, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) jsonResponse(['error' => 'User not found.'], 404);
        jsonResponse($user);
    }

    // List All Users (Admin Only)
    requireRole('admin');
    $users = $db->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC')->fetchAll();
    jsonResponse($users);
}

// --- 2. CREATE (POST) ---
if ($method === 'POST') {
    requireRole('admin');

    $name  = sanitize($data['name'] ?? '');
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $pass  = $data['password'] ?? '';
    $role  = sanitize($data['role'] ?? 'seeker');

    if (!$name || !$email || !$pass) jsonResponse(['error' => 'All fields are required.'], 400);
    if (strlen($pass) < 6) jsonResponse(['error' => 'Password must be at least 6 characters.'], 400);

    // Check Duplicate Email
    $chk = $db->prepare('SELECT id FROM users WHERE email = ?');
    $chk->execute([$email]);
    if ($chk->fetch()) jsonResponse(['error' => 'Email already registered.'], 409);

    $sql = 'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)';
    $db->prepare($sql)->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    
    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
}

// --- 3. UPDATE (PUT) ---
if ($method === 'PUT' && $id) {
    $current = requireLogin();
    
    // Only Admin or Self
    if ($current['role'] !== 'admin' && (int)$current['id'] !== $id) {
        jsonResponse(['error' => 'Forbidden.'], 403);
    }

    $name = sanitize($data['name'] ?? '');
    if (!$name) jsonResponse(['error' => 'Name is required.'], 400);

    // Note: You might want to add email/password update logic here too
    $db->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $id]);
    jsonResponse(['success' => true, 'message' => 'Profile updated.']);
}

// --- 4. DELETE (DELETE) ---
if ($method === 'DELETE' && $id) {
    $current = requireLogin();

    // Prevent Self-Deletion for Admins (to avoid locking out the system)
    if ($current['role'] === 'admin' && $id === (int)$current['id']) {
        jsonResponse(['error' => 'You cannot delete your own admin account.'], 403);
    }

    // Authorization: Only Admin can delete others; Users can only delete themselves
    if ($current['role'] !== 'admin' && (int)$current['id'] !== $id) {
        jsonResponse(['error' => 'Forbidden.'], 403);
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            $db->rollBack();
            jsonResponse(['error' => 'User not found.'], 404);
        }

        // Clean up dependent data based on role
        if ($targetUser['role'] === 'seeker') {
            $db->prepare('DELETE FROM seeker_profiles WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM applications WHERE seeker_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM saved_jobs WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM resumes WHERE user_id = ?')->execute([$id]);
        } elseif ($targetUser['role'] === 'employer') {
            $db->prepare('DELETE FROM employer_profiles WHERE user_id = ?')->execute([$id]);
            // Instead of deleting jobs (which might have applicants), we close them
            $db->prepare("UPDATE jobs SET status = 'closed' WHERE employer_id = ?")->execute([$id]);
        }

        // Shared Cleanup
        $db->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM messages WHERE from_user = ? OR to_user = ?')->execute([$id, $id]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

        $db->commit();

        // If a user deleted themselves, destroy session
        if ($id === (int)$current['id']) {
            session_destroy();
        }

        jsonResponse(['success' => true, 'message' => 'User and related data removed.']);

    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Critical Error: ' . $e->getMessage()], 500);
    }
}

// Fallback for unhandled methods
jsonResponse(['error' => 'Invalid request method.'], 405);