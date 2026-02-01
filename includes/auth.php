<?php
require_once 'functions.php'; // Memuat fungsi seperti isLoggedIn dan redirect
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include session timeout handler
require_once __DIR__ . '/session_timeout.php';

function logout()
{
    session_unset(); // Hapus semua variabel sesi
    session_destroy(); // Hancurkan sesi
    redirect('../login.php'); // Arahkan ke landing.php setelah logout
}
?>