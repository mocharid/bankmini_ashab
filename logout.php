<?php
session_start();
require_once 'includes/db_connection.php'; // Adjusted path

// Hapus sesi aktif dari database (jika menggunakan tabel active_sessions)
if (isset($_SESSION['user_id'])) {
    $query = "DELETE FROM active_sessions WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
}

// Hapus semua data sesi
session_unset();
session_destroy();

// Redirect ke halaman login dengan path yang benar
header("Location: pages/login.php"); // Adjusted path
exit();
?>