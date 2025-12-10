<?php
// includes/session_validator.php

// Start session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi khusus untuk petugas - cek jadwal shift
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'petugas') {
    
    // Path adaptif untuk db_connection.php
    $db_path = __DIR__ . '/db_connection.php';
    
    // Cek apakah file ada sebelum include
    if (file_exists($db_path)) {
        require_once $db_path;
        
        $user_id = $_SESSION['user_id'];
        $today = date('Y-m-d');
        
        try {
            // Query disesuaikan dengan struktur database baru (tabel petugas_shift)
            $query_check = "SELECT id FROM petugas_shift 
                            WHERE (petugas1_id = ? OR petugas2_id = ?) 
                            AND tanggal = ?";
            
            $stmt_check = $conn->prepare($query_check);
            
            if ($stmt_check) {
                $stmt_check->bind_param("iis", $user_id, $user_id, $today);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                // Jika tidak ada jadwal hari ini, logout paksa
                if ($result_check->num_rows === 0) {
                    // Simpan pesan untuk ditampilkan di login
                    $_SESSION['force_logout_message'] = 'Anda telah dikeluarkan dari sistem karena jadwal Anda telah diubah.';
                    
                    // Simpan info sebelum destroy
                    $redirect_url = '/schobank/pages/login.php';
                    
                    // Destroy session
                    session_unset();
                    session_destroy();
                    
                    // Redirect ke login
                    header("Location: " . $redirect_url);
                    exit;
                }
                
                $stmt_check->close();
            }
        } catch (mysqli_sql_exception $e) {
            // Log error jika perlu (opsional)
            error_log("Session Validator Error: " . $e->getMessage());
            
            // Jangan logout jika ada error database
            // Biarkan user tetap login untuk menghindari gangguan operasional
        }
    }
}
?>
