<?php
session_start();

// Get logout reason
$reason = $_GET['reason'] ?? '';
$logout_message = '';

if ($reason === 'timeout') {
    $logout_message = 'Sesi Anda telah berakhir karena tidak aktif. Silakan login kembali.';
}

// Hapus semua data sesi
$_SESSION = [];
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

// Start new session to store message
session_start();
if (!empty($logout_message)) {
    $_SESSION['logout_message'] = $logout_message;
}

// Redirect ke halaman login
header("Location: pages/login.php");
exit();
?>