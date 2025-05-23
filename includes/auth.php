<?php
require_once 'functions.php'; // Memuat fungsi seperti isLoggedIn dan redirect
session_start();

function logout() {
    session_unset(); // Hapus semua variabel sesi
    session_destroy(); // Hancurkan sesi
    redirect('../login.php'); // Arahkan ke landing.php setelah logout
}
?>