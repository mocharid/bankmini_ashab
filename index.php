<?php
require_once 'includes/auth.php'; // Pastikan baris ini ada

$role = $_SESSION['role'];

switch ($role) {
    case 'admin':
        redirect('pages/admin/dashboard.php');
        break;
    case 'petugas':
        redirect('pages/petugas/dashboard.php');
        break;
    case 'siswa':
        redirect('pages/siswa/dashboard.php');
        break;
    default:
        redirect('pages/login.php');
        break;
}
?>