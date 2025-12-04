<?php
// includes/session_validator.php
if (!isset($_SESSION)) {
    session_start();
}

// Jika user adalah petugas, validasi jadwal
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'petugas') {
    require_once __DIR__ . '/db_connection.php';
    
    $user_id = $_SESSION['user_id'];
    $today = date('Y-m-d');
    
    // Cek apakah petugas masih terdaftar di jadwal hari ini
    $query_check = "SELECT id FROM petugas_tugas 
                    WHERE (petugas1_id = ? OR petugas2_id = ?) 
                    AND tanggal = ?";
    $stmt_check = $conn->prepare($query_check);
    $stmt_check->bind_param("iis", $user_id, $user_id, $today);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    // Jika tidak ada jadwal hari ini, logout paksa
    if ($result_check->num_rows === 0) {
        // Simpan pesan untuk ditampilkan di login
        $_SESSION['force_logout_message'] = 'Anda telah dikeluarkan dari sistem karena jadwal Anda telah diubah.';
        
        // Destroy session
        session_unset();
        session_destroy();
        
        // Redirect ke login
        header("Location: /schobank/pages/login.php");
        exit;
    }
    
    $stmt_check->close();
}
?>
