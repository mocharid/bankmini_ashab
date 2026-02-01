<?php
// includes/session_validator.php
// Validasi session untuk petugas - cek jadwal shift hari ini

// Start session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone Indonesia
date_default_timezone_set('Asia/Jakarta');

// Validasi khusus untuk petugas - cek jadwal shift
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'petugas') {

    // Cek apakah sudah ada jadwal_id di session (sudah divalidasi saat login)
    // Jika ada jadwal_id, berarti sudah divalidasi saat login - skip validasi ulang
    if (isset($_SESSION['jadwal_id']) && !empty($_SESSION['jadwal_id'])) {
        // Session valid - jadwal sudah tersimpan saat login
        // Tidak perlu query ulang ke database
    } else {
        // Jika tidak ada jadwal_id di session, lakukan validasi
        $db_path = __DIR__ . '/db_connection.php';

        if (file_exists($db_path)) {
            require_once $db_path;

            // Pastikan koneksi tersedia
            if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
                $user_id = $_SESSION['user_id'];
                $today = date('Y-m-d');

                try {
                    $query_check = "SELECT id FROM petugas_shift 
                                    WHERE (petugas1_id = ? OR petugas2_id = ?) 
                                    AND tanggal = ?";

                    $stmt_check = $conn->prepare($query_check);

                    if ($stmt_check) {
                        $stmt_check->bind_param("iis", $user_id, $user_id, $today);
                        $stmt_check->execute();
                        $result_check = $stmt_check->get_result();

                        if ($result_check->num_rows > 0) {
                            // Jadwal ditemukan - simpan ke session untuk skip validasi berikutnya
                            $jadwal = $result_check->fetch_assoc();
                            $_SESSION['jadwal_id'] = $jadwal['id'];
                        } else {
                            // Tidak ada jadwal - logout
                            $_SESSION['errors'] = ['general' => 'Anda tidak bertugas hari ini.'];

                            // Gunakan path adaptif untuk redirect
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                            $host = $_SERVER['HTTP_HOST'];
                            $script = $_SERVER['SCRIPT_NAME'];
                            $base_path = dirname(dirname($script));
                            $base_path = preg_replace('#/pages.*$#', '', $base_path);
                            if ($base_path !== '/' && !empty($base_path)) {
                                $base_path = '/' . ltrim($base_path, '/');
                            }
                            $redirect_url = $protocol . $host . $base_path . '/pages/login.php';

                            session_unset();
                            session_destroy();

                            header("Location: " . $redirect_url);
                            exit;
                        }

                        $stmt_check->close();
                    }
                } catch (Exception $e) {
                    // Log error tapi jangan logout - biarkan user tetap login
                    error_log("Session Validator Error: " . $e->getMessage());
                }
            }
        }
    }
}
?>