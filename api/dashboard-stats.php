<?php
// Disable internal error reporting to ensure only clean JSON is sent
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';
require_once '../config/db.php';
require_once '../config/session.php';

// 1. Method & Auth Guard
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method Not Allowed. Use GET.'], 405);
}

// requireLogin() ensures the session is valid and returns user data
$user = requireLogin();
$db   = getDB();

// ── ADMIN ROLE: Full System Overview ─────────────────────────────────────────
if ($user['role'] === 'admin') {
    $statsQuery = "SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE role='employer') AS total_employers,
        (SELECT COUNT(*) FROM users WHERE role='seeker') AS total_seekers,
        (SELECT COUNT(*) FROM jobs) AS total_jobs,
        (SELECT COUNT(*) FROM jobs WHERE status='active') AS active_jobs,
        (SELECT COUNT(*) FROM applications) AS total_applications,
        (SELECT COUNT(*) FROM applications WHERE status='accepted') AS accepted_applications,
        (SELECT COUNT(*) FROM applications WHERE status='pending') AS pending_applications,
        (SELECT COUNT(*) FROM applications WHERE status='rejected') AS rejected_applications,
        (SELECT COUNT(*) FROM reviews) AS total_reviews";
    
    $row = $db->query($statsQuery)->fetch(PDO::FETCH_ASSOC);

    $companyStats = $db->query("SELECT 
            company, 
            ROUND(AVG(rating), 1) as avg_rating, 
            COUNT(*) as review_count 
        FROM reviews 
        GROUP BY company 
        ORDER BY avg_rating DESC, review_count DESC
        LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $recentJobs = $db->query("SELECT j.id, j.title, j.company, j.status, j.created_at, u.name AS employer_name 
        FROM jobs j 
        LEFT JOIN users u ON j.employer_id = u.id 
        ORDER BY j.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    $recentUsers = $db->query("SELECT id, name, email, role, created_at 
        FROM users 
        ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse([
        'success' => true, 
        'stats' => array_map('intval', $row ?: []), 
        'company_stats' => $companyStats, 
        'recent_jobs' => $recentJobs, 
        'recent_users' => $recentUsers
    ]);
}

// ── EMPLOYER ROLE: Business Performance ──────────────────────────────────────
if ($user['role'] === 'employer') {
    // Optimized to match your dashboard UI: My Jobs, Applications, Accepted, Pending
    $stmt = $db->prepare("SELECT 
            (SELECT COUNT(*) FROM jobs WHERE employer_id = ?) AS my_jobs, 
            COUNT(a.id) AS total_applications, 
            COALESCE(SUM(CASE WHEN a.status='accepted' THEN 1 ELSE 0 END), 0) AS accepted, 
            COALESCE(SUM(CASE WHEN a.status='pending' THEN 1 ELSE 0 END), 0) AS pending, 
            COALESCE(SUM(CASE WHEN a.status='rejected' THEN 1 ELSE 0 END), 0) AS rejected 
        FROM jobs j 
        LEFT JOIN applications a ON j.id = a.job_id 
        WHERE j.employer_id = ?");
    
    // We pass the user ID twice for the subquery and the main WHERE clause
    $stmt->execute([$user['id'], $user['id']]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    jsonResponse([
        'success' => true, 
        'stats' => array_map('intval', $res ?: [
            'my_jobs' => 0, 
            'total_applications' => 0, 
            'accepted' => 0, 
            'pending' => 0, 
            'rejected' => 0
        ])
    ]);
}

// ── SEEKER ROLE: Application Tracking ────────────────────────────────────────
if ($user['role'] === 'seeker') {
    $stmt = $db->prepare("SELECT 
            COUNT(*) AS total_applications, 
            COALESCE(SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END), 0) AS accepted, 
            COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END), 0) AS pending, 
            COALESCE(SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END), 0) AS rejected 
        FROM applications 
        WHERE seeker_id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Count saved jobs from the saved_jobs table
    $saved = $db->prepare('SELECT COUNT(*) FROM saved_jobs WHERE user_id = ?');
    $saved->execute([$user['id']]);
    $row['saved_jobs'] = (int)$saved->fetchColumn();

    jsonResponse([
        'success' => true, 
        'stats' => array_map('intval', $row)
    ]);
}

jsonResponse(['error' => 'Unauthorized role access.'], 403);