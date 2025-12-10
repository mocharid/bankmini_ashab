<?php
session_start();
require_once 'includes/auth.php';
require_once 'includes/config.php';

if (isLoggedIn()) {
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

        case 'bendahara':  
            redirect(BASE_URL . 'pages/bendahara/dashboard.php');
            break;

        default:
            redirect(BASE_URL . 'pages/login.php');
            break;
    }

} else {
    redirect(BASE_URL . 'pages/login.php');
}
?>
