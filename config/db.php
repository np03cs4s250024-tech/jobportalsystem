<?php
// Configuration Constants
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'jstack_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

/**
 * Get Database Connection (Singleton Pattern)
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // If this fails, it returns a JSON error instead of a PHP crash
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

/**
 * Global helper to send JSON responses easily
 */
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $code = 200) {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }
}

/**
 * Sanitize user input to prevent XSS
 */
if (!function_exists('sanitize')) {
    function sanitize($data) {
        if ($data === null) return '';
        if (is_array($data)) return array_map('sanitize', $data);
        return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get the JSON body sent from JavaScript
 * Logic: Checks if global $data is already set, otherwise reads input
 */
if (!function_exists('getBody')) {
    function getBody() {
        static $cachedBody = null;
        if ($cachedBody !== null) return $cachedBody;
        
        $json = file_get_contents('php://input');
        $cachedBody = json_decode($json, true) ?? [];
        return $cachedBody;
    }
}