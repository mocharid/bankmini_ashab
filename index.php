<?php
session_start();
require_once 'includes/auth.php'; // Memuat fungsi autentikasi
require_once 'includes/config.php'; // Memuat BASE_URL

// Cek apakah pengguna sudah login
if (isLoggedIn()) {
    // Jika sudah login, cek peran dari sesi
    $role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

    switch ($role) {
        case 'admin':
            redirect(BASE_URL . 'pages/admin/dashboard.php');
            break;
        case 'petugas':
            redirect(BASE_URL . 'pages/petugas/dashboard.php');
            break;
        case 'siswa':
            redirect(BASE_URL . 'pages/siswa/dashboard.php');
            break;
        default:
            redirect(BASE_URL . 'landing.php');
            break;
    }
} else {
    // Jika belum login, arahkan ke landing.php
    redirect(BASE_URL . 'landing.php');
}
?>