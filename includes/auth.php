<?php
require_once 'functions.php'; // Tambahkan baris ini
session_start();

if (!isLoggedIn()) {
    redirect('pages/login.php');
}
// auth.php atau file lain yang di-include
function logout() {
    session_start(); // Mulai sesi jika belum dimulai
    session_unset(); // Hapus semua variabel sesi
    session_destroy(); // Hancurkan sesi
    header("Location: ../login.php"); // Redirect ke halaman login
    exit();
}
?>