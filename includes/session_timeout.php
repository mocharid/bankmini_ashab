<?php
/**
 * Session Timeout Handler
 * File: includes/session_timeout.php
 * 
 * Handles server-side session timeout validation
 * Different timeout values per role:
 * - Siswa & Admin: 5 minutes
 * - Petugas: 30 minutes
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Skip if not logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    return;
}

// Define timeout values in seconds
// Siswa, Admin, Bendahara: 5 minutes
// Petugas: 30 minutes
define('SESSION_TIMEOUT_SISWA', 5 * 60);     // 5 minutes
define('SESSION_TIMEOUT_ADMIN', 5 * 60);     // 5 minutes
define('SESSION_TIMEOUT_BENDAHARA', 5 * 60); // 5 minutes
define('SESSION_TIMEOUT_PETUGAS', 30 * 60);  // 30 minutes

/**
 * Get timeout value based on user role
 */
function getSessionTimeout()
{
    $role = $_SESSION['role'] ?? 'siswa';
    switch ($role) {
        case 'petugas':
            return SESSION_TIMEOUT_PETUGAS;
        case 'admin':
            return SESSION_TIMEOUT_ADMIN;
        case 'bendahara':
            return SESSION_TIMEOUT_BENDAHARA;
        case 'siswa':
        default:
            return SESSION_TIMEOUT_SISWA;
    }
}

/**
 * Check if session has timed out
 */
function isSessionTimedOut()
{
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    $timeout = getSessionTimeout();
    $elapsed = time() - $_SESSION['last_activity'];

    return $elapsed > $timeout;
}

/**
 * Handle session timeout - destroy session and redirect
 */
function handleSessionTimeout()
{
    // Clear session
    $_SESSION = [];

    // Destroy cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    // Get base URL for redirect
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script));
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }

    $login_url = $protocol . $host . $base_path . '/pages/login.php?timeout=1';

    header("Location: " . $login_url);
    exit;
}

// Check for timeout
if (isSessionTimedOut()) {
    handleSessionTimeout();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Store timeout config for JavaScript auto-logout
$_SESSION['session_timeout'] = getSessionTimeout();
?>