<?php
/* MANAJEMEN AKUN SISWA */
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');
// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Restrict access to admin only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai admin terlebih dahulu!'));
    exit();
}
// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];
// Initialize variables
$search = '';
$jurusan_filter = '';
$tingkatan_filter = '';
$kelas_filter = '';
$page = 1;
$limit = 15;
$offset = 0;
// Handle search and filter parameters
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}
if (isset($_GET['jurusan'])) {
    $jurusan_filter = $_GET['jurusan'];
}
if (isset($_GET['tingkatan'])) {
    $tingkatan_filter = $_GET['tingkatan'];
}
if (isset($_GET['kelas'])) {
    $kelas_filter = $_GET['kelas'];
}
// Auto-set jurusan_filter and tingkatan_filter if kelas_filter is set
if (!empty($kelas_filter) && (empty($jurusan_filter) || empty($tingkatan_filter))) {
    $get_details_query = "SELECT k.jurusan_id, k.tingkatan_kelas_id FROM kelas k WHERE k.id = ?";
    $get_details_stmt = $conn->prepare($get_details_query);
    if ($get_details_stmt) {
        $get_details_stmt->bind_param("i", $kelas_filter);
        $get_details_stmt->execute();
        $get_details_result = $get_details_stmt->get_result();
        if ($row = $get_details_result->fetch_assoc()) {
            if (empty($jurusan_filter)) {
                $jurusan_filter = $row['jurusan_id'];
            }
            if (empty($tingkatan_filter)) {
                $tingkatan_filter = $row['tingkatan_kelas_id'];
            }
        }
        $get_details_stmt->close();
    }
}
if (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) {
    $page = (int)$_GET['page'];
    $offset = ($page - 1) * $limit;
}
// HANDLE POST ACTIONS
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $action = $_POST['action'] ?? '';
    $rekening_id = $_POST['rekening_id'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    switch ($action) {
        case 'freeze':
            $alasan = $_POST['alasan'] ?? '';
            if (!empty($user_id) && !empty($alasan)) {
                $freeze_query = "UPDATE users SET is_frozen = 1, freeze_timestamp = NOW() WHERE id = ?";
                $freeze_stmt = $conn->prepare($freeze_query);
                if ($freeze_stmt) {
                    $freeze_stmt->bind_param("i", $user_id);
                    if ($freeze_stmt->execute()) {
                        $log_query = "INSERT INTO account_freeze_log (petugas_id, siswa_id, action, alasan, waktu) VALUES (?, ?, 'freeze', ?, NOW())";
                        $log_stmt = $conn->prepare($log_query);
                        if ($log_stmt) {
                            $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $alasan);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        $_SESSION['success_message'] = "Akun berhasil dibekukan!";
                    } else {
                        $_SESSION['error_message'] = "Gagal membekukan akun: " . $freeze_stmt->error;
                    }
                    $freeze_stmt->close();
                }
            }
            break;
        
        case 'unfreeze':
            if (!empty($user_id)) {
                $unfreeze_query = "UPDATE users SET is_frozen = 0, freeze_timestamp = NULL WHERE id = ?";
                $unfreeze_stmt = $conn->prepare($unfreeze_query);
                if ($unfreeze_stmt) {
                    $unfreeze_stmt->bind_param("i", $user_id);
                    if ($unfreeze_stmt->execute()) {
                        $log_query = "INSERT INTO account_freeze_log (petugas_id, siswa_id, action, alasan, waktu) VALUES (?, ?, 'unfreeze', 'Aktivasi ulang oleh admin', NOW())";
                        $log_stmt = $conn->prepare($log_query);
                        if ($log_stmt) {
                            $log_stmt->bind_param("ii", $_SESSION['user_id'], $user_id);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        $_SESSION['success_message'] = "Akun berhasil diaktifkan kembali!";
                    } else {
                        $_SESSION['error_message'] = "Gagal mengaktifkan akun: " . $unfreeze_stmt->error;
                    }
                    $unfreeze_stmt->close();
                }
            }
            break;
        
        case 'reset_password':
            if (!empty($user_id)) {
                $new_password = '12345';
                $reset_query = "UPDATE users SET password = SHA2(?, 256) WHERE id = ?";
                $reset_stmt = $conn->prepare($reset_query);
                if ($reset_stmt) {
                    $reset_stmt->bind_param("si", $new_password, $user_id);
                    if ($reset_stmt->execute()) {
                        $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) VALUES (?, ?, 'password', ?, 'Reset password oleh admin', NOW())";
                        $log_stmt = $conn->prepare($log_query);
                        if ($log_stmt) {
                            $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $new_password);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        $_SESSION['success_message'] = "Password berhasil direset ke default (12345)!";
                    } else {
                        $_SESSION['error_message'] = "Gagal reset password: " . $reset_stmt->error;
                    }
                    $reset_stmt->close();
                }
            }
            break;
        
        case 'reset_pin':
            if (!empty($user_id)) {
                if (isset($_POST['new_pin']) && !empty($_POST['new_pin'])) {
                    $new_pin = trim($_POST['new_pin']);
                    if (strlen($new_pin) == 6 && ctype_digit($new_pin)) {
                        $hashed_pin = hash('sha256', $new_pin);
                        $reset_query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
                        $reset_stmt = $conn->prepare($reset_query);
                        if ($reset_stmt) {
                            $reset_stmt->bind_param("si", $hashed_pin, $user_id);
                            if ($reset_stmt->execute()) {
                                $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) VALUES (?, ?, 'pin', ?, 'Set PIN baru oleh admin', NOW())";
                                $log_stmt = $conn->prepare($log_query);
                                if ($log_stmt) {
                                    $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $new_pin);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                }
                                $_SESSION['success_message'] = "PIN berhasil direset ke nilai baru!";
                            } else {
                                $_SESSION['error_message'] = "Gagal reset PIN: " . $reset_stmt->error;
                            }
                            $reset_stmt->close();
                        }
                    } else {
                        $_SESSION['error_message'] = "PIN harus tepat 6 digit angka!";
                    }
                } else {
                    $reset_query = "UPDATE users SET pin = NULL, has_pin = 0 WHERE id = ?";
                    $reset_stmt = $conn->prepare($reset_query);
                    if ($reset_stmt) {
                        $reset_stmt->bind_param("i", $user_id);
                        if ($reset_stmt->execute()) {
                            $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) VALUES (?, ?, 'pin', '', 'Kosongkan PIN oleh admin', NOW())";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_stmt->bind_param("ii", $_SESSION['user_id'], $user_id);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                            $_SESSION['success_message'] = "PIN berhasil dikosongkan! Siswa harus membuat PIN baru saat login.";
                        } else {
                            $_SESSION['error_message'] = "Gagal kosongkan PIN: " . $reset_stmt->error;
                        }
                        $reset_stmt->close();
                    }
                }
            }
            break;
        
        case 'reset_email':
            if (!empty($user_id)) {
                $reset_query = "UPDATE users SET email = NULL, email_status = 'kosong' WHERE id = ?";
                $reset_stmt = $conn->prepare($reset_query);
                if ($reset_stmt) {
                    $reset_stmt->bind_param("i", $user_id);
                    if ($reset_stmt->execute()) {
                        $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) VALUES (?, ?, 'email', '', 'Reset email oleh admin', NOW())";
                        $log_stmt = $conn->prepare($log_query);
                        if ($log_stmt) {
                            $log_stmt->bind_param("ii", $_SESSION['user_id'], $user_id);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        $_SESSION['success_message'] = "Email berhasil direset! Email dikosongkan.";
                    } else {
                        $_SESSION['error_message'] = "Gagal reset email: " . $reset_stmt->error;
                    }
                    $reset_stmt->close();
                }
            }
            break;
        
        case 'reset_username':
            if (!empty($user_id)) {
                $user_query = "SELECT nama FROM users WHERE id = ?";
                $user_stmt = $conn->prepare($user_query);
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_result->num_rows > 0) {
                    $user_data = $user_result->fetch_assoc();
                    $nama = $user_data['nama'];
                
                    function generateUsernameFromNama($nama, $conn) {
                        $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
                        $username = $base_username;
                        $counter = 1;
                    
                        $check_query = "SELECT id FROM users WHERE username = ?";
                        $check_stmt = $conn->prepare($check_query);
                        if (!$check_stmt) {
                            return $base_username;
                        }
                        $check_stmt->bind_param("s", $username);
                        $check_stmt->execute();
                        $result = $check_stmt->get_result();
                    
                        while ($result->num_rows > 0) {
                            $username = $base_username . $counter;
                            $check_stmt->bind_param("s", $username);
                            $check_stmt->execute();
                            $result = $check_stmt->get_result();
                            $counter++;
                        }
                    
                        $check_stmt->close();
                        return $username;
                    }
                
                    $new_username = generateUsernameFromNama($nama, $conn);
                    $reset_query = "UPDATE users SET username = ? WHERE id = ?";
                    $reset_stmt = $conn->prepare($reset_query);
                    if ($reset_stmt) {
                        $reset_stmt->bind_param("si", $new_username, $user_id);
                        if ($reset_stmt->execute()) {
                            $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) VALUES (?, ?, 'username', ?, 'Reset username oleh admin', NOW())";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $new_username);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                            $_SESSION['success_message'] = "Username berhasil direset menjadi: " . $new_username;
                        } else {
                            $_SESSION['error_message'] = "Gagal reset username: " . $reset_stmt->error;
                        }
                        $reset_stmt->close();
                    }
                }
                $user_stmt->close();
            }
            break;
        
        case 'reset_all':
            if (!empty($user_id)) {
                $user_query = "SELECT nama FROM users WHERE id = ?";
                $user_stmt = $conn->prepare($user_query);
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_result->num_rows > 0) {
                    $user_data = $user_result->fetch_assoc();
                    $nama = $user_data['nama'];
                
                    function generateUsernameFromNama2($nama, $conn) {
                        $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
                        $username = $base_username;
                        $counter = 1;
                    
                        $check_query = "SELECT id FROM users WHERE username = ?";
                        $check_stmt = $conn->prepare($check_query);
                        if (!$check_stmt) {
                            return $base_username;
                        }
                        $check_stmt->bind_param("s", $username);
                        $check_stmt->execute();
                        $result = $check_stmt->get_result();
                    
                        while ($result->num_rows > 0) {
                            $username = $base_username . $counter;
                            $check_stmt->bind_param("s", $username);
                            $check_stmt->execute();
                            $result = $check_stmt->get_result();
                            $counter++;
                        }
                    
                        $check_stmt->close();
                        return $username;
                    }
                
                    $new_username = generateUsernameFromNama2($nama, $conn);
                    $new_password = '12345';
                
                    $conn->begin_transaction();
                    try {
                        $reset_query = "UPDATE users SET username = ?, password = SHA2(?, 256), email = NULL, email_status = 'kosong', pin = NULL, has_pin = 0 WHERE id = ?";
                        $reset_stmt = $conn->prepare($reset_query);
                        $reset_stmt->bind_param("ssi", $new_username, $new_password, $user_id);
                    
                        if ($reset_stmt->execute()) {
                            $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) VALUES (?, ?, 'all', ?, 'Reset semua data oleh admin', NOW())";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_values = "Username: $new_username, Password: 12345, Email: kosong, PIN: kosong";
                                $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $log_values);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                            $conn->commit();
                            $_SESSION['success_message'] = "Semua data berhasil direset!<br>Username: " . $new_username . "<br>Password: 12345<br>Email: Kosong<br>PIN: Kosong";
                        } else {
                            throw new Exception("Gagal reset semua data: " . $reset_stmt->error);
                        }
                        $reset_stmt->close();
                    } catch (Exception $e) {
                        $conn->rollback();
                        $_SESSION['error_message'] = $e->getMessage();
                    }
                }
                $user_stmt->close();
            }
            break;
        
        case 'delete':
            if (!empty($rekening_id) && !empty($user_id)) {
                // Cek jika akun dibekukan
                $check_frozen_query = "SELECT is_frozen FROM users WHERE id = ?";
                $check_frozen_stmt = $conn->prepare($check_frozen_query);
                $check_frozen_stmt->bind_param("i", $user_id);
                $check_frozen_stmt->execute();
                $frozen_result = $check_frozen_stmt->get_result();
                if ($frozen_result->num_rows > 0) {
                    $frozen_data = $frozen_result->fetch_assoc();
                    if ($frozen_data['is_frozen'] == 1) {
                        $_SESSION['error_message'] = "Gagal! Akun sedang dibekukan, tidak dapat dihapus!";
                        $check_frozen_stmt->close();
                        break;
                    }
                }
                $check_frozen_stmt->close();

                $check_saldo_query = "SELECT saldo FROM rekening WHERE id = ?";
                $check_saldo_stmt = $conn->prepare($check_saldo_query);
                $check_saldo_stmt->bind_param("i", $rekening_id);
                $check_saldo_stmt->execute();
                $saldo_result = $check_saldo_stmt->get_result();
            
                if ($saldo_result->num_rows > 0) {
                    $saldo_data = $saldo_result->fetch_assoc();
                    $saldo = intval($saldo_data['saldo'] ?? 0);
                
                    if ($saldo != 0) {
                        $_SESSION['error_message'] = "Gagal menghapus rekening! Saldo masih tersedia, pastikan saldo sudah Rp 0.";
                        $check_saldo_stmt->close();
                        break;
                    }
                }
                $check_saldo_stmt->close();
            
                $conn->begin_transaction();
                try {
                    $delete_mutasi = "DELETE m FROM mutasi m INNER JOIN transaksi t ON m.transaksi_id = t.id WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?";
                    $mutasi_stmt = $conn->prepare($delete_mutasi);
                    $mutasi_stmt->bind_param("ii", $rekening_id, $rekening_id);
                    $mutasi_stmt->execute();
                    $mutasi_stmt->close();
                
                    $delete_transaksi = "DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?";
                    $transaksi_stmt = $conn->prepare($delete_transaksi);
                    $transaksi_stmt->bind_param("ii", $rekening_id, $rekening_id);
                    $transaksi_stmt->execute();
                    $transaksi_stmt->close();
                
                    $delete_rekening = "DELETE FROM rekening WHERE id = ?";
                    $rekening_stmt = $conn->prepare($delete_rekening);
                    $rekening_stmt->bind_param("i", $rekening_id);
                    $rekening_stmt->execute();
                    $rekening_stmt->close();

                    // Hapus record dari reset_request_cooldown
                    $delete_cooldown = "DELETE FROM reset_request_cooldown WHERE user_id = ?";
                    $cooldown_stmt = $conn->prepare($delete_cooldown);
                    $cooldown_stmt->bind_param("i", $user_id);
                    $cooldown_stmt->execute();
                    $cooldown_stmt->close();
                
                    $delete_user = "DELETE FROM users WHERE id = ?";
                    $user_stmt = $conn->prepare($delete_user);
                    $user_stmt->bind_param("i", $user_id);
                    $user_stmt->execute();
                    $user_stmt->close();
                
                    $conn->commit();
                    $_SESSION['success_message'] = "Data rekening dan nasabah berhasil dihapus!";
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['error_message'] = "Gagal menghapus rekening: " . $e->getMessage();
                }
            }
            break;
    }
    header("Location: manajemen_akun.php?" . http_build_query($_GET));
    exit;
}
// Fetch jurusan data
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan";
$result_jurusan = $conn->query($query_jurusan);
if (!$result_jurusan) {
    error_log("Error fetching jurusan: " . $conn->error);
}
// Fetch tingkatan data
$query_tingkatan = "SELECT * FROM tingkatan_kelas ORDER BY nama_tingkatan";
$result_tingkatan = $conn->query($query_tingkatan);
if (!$result_tingkatan) {
    error_log("Error fetching tingkatan: " . $conn->error);
}
// Fetch kelas data
$query_kelas = "SELECT k.*, j.nama_jurusan, tk.nama_tingkatan
                FROM kelas k
                LEFT JOIN jurusan j ON k.jurusan_id = j.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE 1=1";
if (!empty($jurusan_filter)) {
    $query_kelas .= " AND k.jurusan_id = " . intval($jurusan_filter);
}
if (!empty($tingkatan_filter)) {
    $query_kelas .= " AND k.tingkatan_kelas_id = " . intval($tingkatan_filter);
}
$query_kelas .= " ORDER BY tk.nama_tingkatan, k.nama_kelas";
$result_kelas = $conn->query($query_kelas);
if (!$result_kelas) {
    error_log("Error fetching kelas: " . $conn->error);
}
// Build main query
$where_conditions = [];
$params = [];
$types = "";
$query = "SELECT r.id as rekening_id, r.no_rekening, r.saldo,
                 u.id as user_id, u.nama, u.is_frozen,
                 j.nama_jurusan, k.nama_kelas, tk.nama_tingkatan
          FROM rekening r
          INNER JOIN users u ON r.user_id = u.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          WHERE u.role = 'siswa'";
if (!empty($search)) {
    $where_conditions[] = "(u.nama LIKE ? OR u.username LIKE ? OR r.no_rekening LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}
if (!empty($jurusan_filter)) {
    $where_conditions[] = "u.jurusan_id = ?";
    $params[] = $jurusan_filter;
    $types .= "i";
}
if (!empty($tingkatan_filter)) {
    $where_conditions[] = "k.tingkatan_kelas_id = ?";
    $params[] = $tingkatan_filter;
    $types .= "i";
}
if (!empty($kelas_filter)) {
    $where_conditions[] = "u.kelas_id = ?";
    $params[] = $kelas_filter;
    $types .= "i";
}
if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}
$query .= " ORDER BY u.nama ASC";
// Count total
$count_query = "SELECT COUNT(*) as total FROM rekening r
                INNER JOIN users u ON r.user_id = u.id
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE u.role = 'siswa'";
if (!empty($where_conditions)) {
    $count_query .= " AND " . implode(" AND ", $where_conditions);
}
$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    error_log("Error preparing count query: " . $conn->error);
    $total_records = 0;
}
$total_pages = ceil($total_records / $limit);
// Pagination
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
// Execute main query
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rekening_data = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($rekening_data as &$rekening) {
        $rekening['saldo'] = intval($rekening['saldo'] ?? 0);
        unset($rekening);
    }
    $stmt->close();
} else {
    error_log("Error preparing main query: " . $conn->error);
    $rekening_data = [];
}
// Messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Manajemen Akun Siswa | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e40af;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #dc2626;
            --success-color: #059669;
            --warning-color: #d97706;
            --info-color: #0284c7;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --bg-light: #f9fafb;
            --border-color: #e5e7eb;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
    
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
    
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
        }
    
        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            touch-action: pan-y;
        }
    
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: clamp(15px, 4vw, 30px);
            max-width: calc(100% - 280px);
        }
    
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }
    
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: clamp(20px, 4vw, 28px);
            border-radius: 5px;
            margin-bottom: clamp(20px, 4vw, 30px);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 15px;
        }
    
        .welcome-banner .content {
            flex: 1;
        }
    
        .welcome-banner h2 {
            margin-bottom: 8px;
            font-size: clamp(1.2rem, 3.5vw, 1.5rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    
        .welcome-banner p {
            font-size: clamp(0.8rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.95;
        }
    
        .menu-toggle {
            font-size: clamp(1.3rem, 3vw, 1.5rem);
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            padding: 8px;
            -webkit-tap-highlight-color: transparent;
        }
    
        .card {
            background: white;
            border-radius: 5px;
            padding: clamp(20px, 4vw, 28px);
            box-shadow: var(--shadow-sm);
            margin-bottom: clamp(20px, 4vw, 30px);
            border: 1px solid var(--border-color);
        }
    
        .card-title {
            font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: clamp(15px, 3vw, 22px);
            display: flex;
            align-items: center;
            gap: 10px;
        }
    
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: clamp(12px, 2.5vw, 15px);
            align-items: flex-end;
            margin-bottom: clamp(15px, 3vw, 20px);
        }
    
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
    
        .filter-group label {
            display: block;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: clamp(6px, 1.5vw, 8px);
            font-size: clamp(0.85rem, 1.8vw, 0.92rem);
        }
    
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 14px);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 16px;
            -webkit-user-select: text;
            user-select: text;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: white;
            min-height: 44px;
            transition: border-color 0.2s;
        }
    
        .filter-group select {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 18px;
            padding-right: 36px;
        }
    
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.08);
        }
    
        .button-group {
            display: flex;
            gap: clamp(8px, 2vw, 10px);
        }
    
        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            padding: clamp(10px, 2vw, 12px) clamp(18px, 3.5vw, 22px);
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: clamp(6px, 1.5vw, 8px);
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
            transition: opacity 0.2s;
            text-decoration: none;
        }
    
        .btn:hover {
            opacity: 0.9;
        }
    
        .btn:active {
            transform: scale(0.98);
        }
    
        .btn-secondary {
            background: #e5e7eb;
            color: var(--text-secondary);
        }
    
        .btn-secondary:hover {
            background: #d1d5db;
        }
    
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }
    
        .btn-success {
            background: var(--success-color);
        }
    
        .btn-warning {
            background: var(--warning-color);
        }
    
        .btn-info {
            background: var(--info-color);
        }
    
        .btn-danger {
            background: var(--danger-color);
        }
    
        .btn-sm {
            padding: clamp(6px, 1.5vw, 8px) clamp(12px, 2.5vw, 14px);
            font-size: 14px;
            min-height: 36px;
        }
    
        .table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            margin-bottom: clamp(15px, 3vw, 18px);
            border-radius: 5px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
    
        .table-container::-webkit-scrollbar {
            display: none;
        }
    
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            background: white;
            min-width: 700px;
        }
    
        th, td {
            padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 14px);
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.92rem);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
    
        th {
            background: #f3f4f6;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: clamp(0.78rem, 1.6vw, 0.85rem);
            letter-spacing: 0.3px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
    
        td {
            background: white;
        }
    
        tr:hover {
            background-color: #f9fafb;
        }
    
        .actions {
            display: flex;
            gap: clamp(6px, 1.5vw, 8px);
            flex-wrap: wrap;
        }
    
        @media (max-width: 768px) {
            .actions {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
                width: 100%;
            }
        
            .actions .btn-sm {
                width: 100%;
                padding: 8px 6px;
                font-size: 12px;
                min-height: 36px;
            }
        
            .actions .btn-sm i {
                display: none;
            }
        }
    
        .empty-state {
            text-align: center;
            padding: clamp(40px, 8vw, 60px) clamp(20px, 4vw, 30px);
            background: #f9fafb;
            border-radius: 5px;
            border: 2px dashed var(--border-color);
            margin: clamp(15px, 3vw, 18px) 0;
        }
    
        .empty-state p {
            font-size: clamp(1rem, 2.5vw, 1.15rem);
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: clamp(8px, 2vw, 10px);
        }
    
        .empty-state .subtext {
            font-size: clamp(0.85rem, 2vw, 0.92rem);
            color: var(--text-secondary);
            font-weight: 400;
        }
    
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: clamp(8px, 2vw, 10px);
            margin-top: clamp(15px, 3vw, 18px);
            flex-wrap: wrap;
        }
    
        .pagination a,
        .pagination span {
            padding: clamp(8px, 2vw, 10px) clamp(12px, 2.5vw, 14px);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: clamp(0.85rem, 1.8vw, 0.92rem);
            min-width: 44px;
            min-height: 44px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
    
        .pagination a:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
    
        .pagination span.current {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            font-weight: 600;
        }
    
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
            }
        
            .menu-toggle {
                display: block;
            }
        
            .welcome-banner {
                padding: 15px;
            }
        
            .card {
                padding: 15px;
            }
        
            .filter-container {
                flex-direction: column;
            }
        
            .filter-group {
                min-width: 100%;
            }
        
            .button-group {
                width: 100%;
            }
        
            .button-group .btn {
                flex: 1;
            }
        
            table {
                min-width: 800px;
            }
        }
    
        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }
    
        @media (max-width: 480px) {
            table {
                min-width: 900px;
            }
        
            .filter-group input,
            .filter-group select,
            .btn {
                font-size: 16px !important;
            }
        }
    
        @media (hover: none) and (pointer: coarse) {
            .filter-group input,
            .filter-group select,
            .btn {
                min-height: 44px;
                font-size: 16px;
            }
        
            .menu-toggle {
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2><i class="fas fa-tools"></i> Manajemen Akun Siswa</h2>
                <p>Kelola akun siswa: bekukan, reset, dan hapus akun</p>
            </div>
        </div>
    
        <div class="card">
            <form method="GET" action="">
                <div class="filter-container">
                    <div class="filter-group">
                        <label for="search">Pencarian</label>
                        <input type="text" id="search" name="search" placeholder="Nama, Username, No. Rekening" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                
                    <div class="filter-group">
                        <label for="jurusan">Jurusan</label>
                        <select id="jurusan" name="jurusan" onchange="updateTingkatanOptions()">
                            <option value="">Semua Jurusan</option>
                            <?php
                            if ($result_jurusan && $result_jurusan->num_rows > 0) {
                                $result_jurusan->data_seek(0);
                                while ($row = $result_jurusan->fetch_assoc()) {
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $jurusan_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                </option>
                            <?php
                                }
                            }
                            ?>
                        </select>
                    </div>
                
                    <div class="filter-group">
                        <label for="tingkatan">Tingkatan</label>
                        <select id="tingkatan" name="tingkatan" onchange="updateKelasOptions()">
                            <option value="">Semua Tingkatan</option>
                            <?php
                            if ($result_tingkatan && $result_tingkatan->num_rows > 0) {
                                $result_tingkatan->data_seek(0);
                                while ($row = $result_tingkatan->fetch_assoc()) {
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $tingkatan_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                                </option>
                            <?php
                                }
                            }
                            ?>
                        </select>
                    </div>
                
                    <div class="filter-group">
                        <label for="kelas">Kelas</label>
                        <select id="kelas" name="kelas">
                            <option value="">Semua Kelas</option>
                            <?php
                            if ($result_kelas && $result_kelas->num_rows > 0) {
                                $result_kelas->data_seek(0);
                                while ($row = $result_kelas->fetch_assoc()) {
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $kelas_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_tingkatan'] . ' ' . $row['nama_kelas'] . ' - ' . $row['nama_jurusan']); ?>
                                </option>
                            <?php
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
            
                <div class="button-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                    <button type="button" id="resetBtn" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </form>
        </div>
    
        <div class="card">
            <h3 class="card-title">
                <i class="fas fa-table"></i> Daftar Akun Siswa
                <span style="font-size:0.9rem;color:var(--text-secondary);font-weight:normal;margin-left:8px;">
                    (<?php echo number_format($total_records, 0, ",", "."); ?> data)
                </span>
            </h3>
        
            <div class="table-container">
                <?php if (empty($rekening_data)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox" style="font-size:3.5rem;color:var(--text-secondary);opacity:0.4;margin-bottom:15px;"></i>
                        <p>Tidak ada data yang ditemukan</p>
                        <p class="subtext">Silakan ubah filter atau kata kunci pencarian</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No. Rekening</th>
                                <th>Nama Siswa</th>
                                <th>Jurusan/Kelas</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rekening_data as $rekening): ?>
                                <tr>
                                    <td style="font-weight:600;font-family:monospace;font-size:0.9rem;">
                                        <?php
                                        $no_rek = $rekening['no_rekening'];
                                        if (strlen($no_rek) > 5) {
                                            echo htmlspecialchars(substr($no_rek, 0, 3) . '***' . substr($no_rek, -2));
                                        } else {
                                            echo htmlspecialchars($no_rek);
                                        }
                                        ?>
                                    </td>
                                    <td style="font-weight:500;"><?php echo htmlspecialchars($rekening['nama']); ?></td>
                                    <td style="font-size:0.88rem;">
                                        <?php
                                        $kelas_lengkap = '';
                                        if (!empty($rekening['nama_tingkatan']) && !empty($rekening['nama_kelas'])) {
                                            $kelas_lengkap = $rekening['nama_tingkatan'] . ' ' . $rekening['nama_kelas'];
                                            if (!empty($rekening['nama_jurusan'])) {
                                                $kelas_lengkap .= ' - ' . $rekening['nama_jurusan'];
                                            }
                                        }
                                        echo htmlspecialchars($kelas_lengkap ?: '-');
                                        ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($rekening['is_frozen']): ?>
                                                <button class="btn btn-success btn-sm unfreeze-btn"
                                                        data-user-id="<?php echo $rekening['user_id']; ?>"
                                                        data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>">
                                                    <i class="fas fa-unlock"></i> Aktifkan
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-warning btn-sm freeze-btn"
                                                        data-user-id="<?php echo $rekening['user_id']; ?>"
                                                        data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>">
                                                    <i class="fas fa-lock"></i> Bekukan
                                                </button>
                                            <?php endif; ?>
                                        
                                            <button class="btn btn-info btn-sm reset-btn"
                                                    data-user-id="<?php echo $rekening['user_id']; ?>"
                                                    data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>">
                                                <i class="fas fa-redo"></i> Reset
                                            </button>
                                        
                                            <button class="btn btn-danger btn-sm delete-btn"
                                                    data-rekening-id="<?php echo $rekening['rekening_id']; ?>"
                                                    data-user-id="<?php echo $rekening['user_id']; ?>"
                                                    data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>"
                                                    data-rekening="<?php echo htmlspecialchars($rekening['no_rekening']); ?>"
                                                    data-saldo="<?php echo $rekening['saldo']; ?>"
                                                    data-frozen="<?php echo $rekening['is_frozen']; ?>">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        
            <?php if ($total_records > 15): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Sebelumnya
                        </a>
                    <?php endif; ?>
                
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                
                    if ($start > 1) {
                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a>';
                        if ($start > 2) echo '<span>...</span>';
                    }
                
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                
                    <?php
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span>...</span>';
                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a>';
                    }
                    ?>
                
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Selanjutnya <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <form id="action-form" method="POST" style="display:none;">
        <input type="hidden" name="token" value="<?php echo $token; ?>">
        <input type="hidden" name="action" id="action-field">
        <input type="hidden" name="rekening_id" id="rekening-id-field">
        <input type="hidden" name="user_id" id="user-id-field">
        <input type="hidden" name="alasan" id="alasan-field">
        <input type="hidden" name="new_pin" id="new-pin-field">
    </form>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
        
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }
        
            document.addEventListener('click', (e) => {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
        
            document.getElementById('resetBtn').addEventListener('click', () => {
                window.location.href = 'manajemen_akun.php';
            });
        
            <?php if (!empty($success_message)): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    html: '<?php echo addslashes($success_message); ?>',
                    confirmButtonText: 'OK'
                });
            <?php endif; ?>
        
            <?php if (!empty($error_message)): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    html: '<?php echo addslashes($error_message); ?>',
                    confirmButtonText: 'OK'
                });
            <?php endif; ?>
        
            window.updateTingkatanOptions = function() {
                const jurusan = document.getElementById('jurusan').value;
                const tingkatanSelect = document.getElementById('tingkatan');
                const selectedTingkatan = tingkatanSelect.value;
            
                tingkatanSelect.innerHTML = '<option value="">Semua Tingkatan</option>';
            
                if (jurusan) {
                    fetch(`../../includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=${jurusan}&action=get_tingkatan`)
                        .then(r => r.text())
                        .then(data => {
                            if (data.trim()) {
                                tingkatanSelect.innerHTML += data;
                                if (selectedTingkatan) {
                                    const opt = tingkatanSelect.querySelector(`option[value="${selectedTingkatan}"]`);
                                    if (opt) tingkatanSelect.value = selectedTingkatan;
                                }
                            }
                            updateKelasOptions();
                        })
                        .catch(() => {});
                } else {
                    updateKelasOptions();
                }
            };
        
            window.updateKelasOptions = function() {
                const jurusan = document.getElementById('jurusan').value;
                const tingkatan = document.getElementById('tingkatan').value;
                const kelasSelect = document.getElementById('kelas');
                const selectedKelas = kelasSelect.value;
            
                kelasSelect.innerHTML = '<option value="">Semua Kelas</option>';
            
                if (jurusan) {
                    let url = `../../includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=${jurusan}&action=get_kelas`;
                    if (tingkatan) {
                        url += `&tingkatan_id=${tingkatan}`;
                    }
                    fetch(url)
                        .then(r => r.text())
                        .then(data => {
                            if (data.trim()) {
                                kelasSelect.innerHTML += data;
                                if (selectedKelas) {
                                    const opt = kelasSelect.querySelector(`option[value="${selectedKelas}"]`);
                                    if (opt) kelasSelect.value = selectedKelas;
                                }
                            }
                        })
                        .catch(() => {});
                }
            };
        
            if (document.getElementById('jurusan').value) {
                updateTingkatanOptions();
            }
        
            // Reset button handlers
            document.querySelectorAll('.reset-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const userId = this.getAttribute('data-user-id');
                    const nama = this.getAttribute('data-nama');
                
                    Swal.fire({
                        title: `Reset Akun: ${nama}`,
                        html: `
                            <div style="text-align:left; padding:15px 10px;">
                                <p style="margin-bottom:18px; color:#6b7280; font-size:0.92rem;">Pilih opsi reset yang diinginkan:</p>
                            
                                <button class="swal-custom-btn swal-btn-password" data-action="reset_password">
                                    <i class="fas fa-key"></i>
                                    <div>
                                        <strong>Reset Password</strong>
                                        <small>Password direset ke '12345'</small>
                                    </div>
                                </button>
                            
                                <button class="swal-custom-btn swal-btn-pin" data-action="reset_pin">
                                    <i class="fas fa-lock"></i>
                                    <div>
                                        <strong>Reset PIN</strong>
                                        <small>Kosongkan atau set PIN baru (6 digit)</small>
                                    </div>
                                </button>
                            
                                <button class="swal-custom-btn swal-btn-email" data-action="reset_email">
                                    <i class="fas fa-envelope"></i>
                                    <div>
                                        <strong>Reset Email</strong>
                                        <small>Email dikosongkan</small>
                                    </div>
                                </button>
                            
                                <button class="swal-custom-btn swal-btn-username" data-action="reset_username">
                                    <i class="fas fa-user"></i>
                                    <div>
                                        <strong>Reset Username</strong>
                                        <small>Username dibuat ulang dari nama</small>
                                    </div>
                                </button>
                            
                                <button class="swal-custom-btn swal-btn-all" data-action="reset_all">
                                    <i class="fas fa-sync-alt"></i>
                                    <div>
                                        <strong>Reset Semua Data</strong>
                                        <small>Reset seluruh kredensial akun</small>
                                    </div>
                                </button>
                            </div>
                        
                            <style>
                                .swal-custom-btn {
                                    width: 100%;
                                    padding: 14px;
                                    margin-bottom: 10px;
                                    border: 1px solid #e5e7eb;
                                    border-radius: 5px;
                                    background: white;
                                    cursor: pointer;
                                    display: flex;
                                    align-items: center;
                                    gap: 14px;
                                    transition: all 0.2s ease;
                                    font-family: 'Poppins', sans-serif;
                                }
                                .swal-custom-btn:hover {
                                    border-color: #3b82f6;
                                    background: #f0f5ff;
                                }
                                .swal-custom-btn i {
                                    font-size: 1.4rem;
                                    width: 36px;
                                    text-align: center;
                                }
                                .swal-custom-btn div {
                                    text-align: left;
                                    flex: 1;
                                }
                                .swal-custom-btn strong {
                                    display: block;
                                    font-size: 0.92rem;
                                    color: #1f2937;
                                    margin-bottom: 3px;
                                }
                                .swal-custom-btn small {
                                    display: block;
                                    font-size: 0.78rem;
                                    color: #6b7280;
                                }
                                .swal-btn-password i { color: #f59e0b; }
                                .swal-btn-pin i { color: #8b5cf6; }
                                .swal-btn-email i { color: #06b6d4; }
                                .swal-btn-username i { color: #10b981; }
                                .swal-btn-all i { color: #dc2626; }
                            </style>
                        `,
                        showConfirmButton: false,
                        showCancelButton: true,
                        cancelButtonText: 'Tutup',
                        width: '480px',
                        didOpen: () => {
                            document.querySelectorAll('.swal-custom-btn').forEach(button => {
                                button.addEventListener('click', function() {
                                    const action = this.getAttribute('data-action');
                                    Swal.close();
                                
                                    let confirmText = '';
                                    switch(action) {
                                        case 'reset_password':
                                            confirmText = "Password akan direset ke '12345'";
                                            break;
                                        case 'reset_pin':
                                            Swal.fire({
                                                title: 'Pilihan Reset PIN',
                                                html: `
                                                    <div style="text-align:left; padding:15px 10px;">
                                                        <p style="margin-bottom:18px; color:#6b7280; font-size:0.92rem;">Pilih aksi untuk PIN:</p>
                                                    
                                                        <button class="swal-pin-btn swal-pin-clear" data-mode="clear">
                                                            <i class="fas fa-trash"></i>
                                                            <div>
                                                                <strong>Kosongkan PIN</strong>
                                                                <small>Siswa harus buat PIN baru saat login</small>
                                                            </div>
                                                        </button>
                                                    
                                                        <button class="swal-pin-btn swal-pin-set" data-mode="set">
                                                            <i class="fas fa-plus"></i>
                                                            <div>
                                                                <strong>Set PIN Baru</strong>
                                                                <small>Masukkan 6 digit angka baru</small>
                                                            </div>
                                                        </button>
                                                    </div>
                                                
                                                    <style>
                                                        .swal-pin-btn {
                                                            width: 100%;
                                                            padding: 14px;
                                                            margin-bottom: 10px;
                                                            border: 1px solid #e5e7eb;
                                                            border-radius: 5px;
                                                            background: white;
                                                            cursor: pointer;
                                                            display: flex;
                                                            align-items: center;
                                                            gap: 14px;
                                                            transition: all 0.2s ease;
                                                            font-family: 'Poppins', sans-serif;
                                                        }
                                                        .swal-pin-btn:hover {
                                                            border-color: #8b5cf6;
                                                            background: #f3e8ff;
                                                        }
                                                        .swal-pin-btn i {
                                                            font-size: 1.4rem;
                                                            width: 36px;
                                                            text-align: center;
                                                        }
                                                        .swal-pin-btn div {
                                                            text-align: left;
                                                            flex: 1;
                                                        }
                                                        .swal-pin-btn strong {
                                                            display: block;
                                                            font-size: 0.92rem;
                                                            color: #1f2937;
                                                            margin-bottom: 3px;
                                                        }
                                                        .swal-pin-btn small {
                                                            display: block;
                                                            font-size: 0.78rem;
                                                            color: #6b7280;
                                                        }
                                                        .swal-pin-clear i { color: #dc2626; }
                                                        .swal-pin-set i { color: #8b5cf6; }
                                                    </style>
                                                `,
                                                showConfirmButton: false,
                                                showCancelButton: true,
                                                cancelButtonText: 'Batal',
                                                width: '420px',
                                                didOpen: () => {
                                                    document.querySelectorAll('.swal-pin-btn').forEach(pinBtn => {
                                                        pinBtn.addEventListener('click', function() {
                                                            const mode = this.getAttribute('data-mode');
                                                            Swal.close();
                                                        
                                                            if (mode === 'clear') {
                                                                Swal.fire({
                                                                    title: 'Konfirmasi Kosongkan PIN',
                                                                    text: 'PIN akan dikosongkan. Siswa harus buat PIN baru saat login.',
                                                                    icon: 'warning',
                                                                    showCancelButton: true,
                                                                    confirmButtonText: 'Kosongkan',
                                                                    cancelButtonText: 'Batal',
                                                                    confirmButtonColor: '#dc2626'
                                                                }).then((result) => {
                                                                    if (result.isConfirmed) {
                                                                        document.getElementById('action-field').value = 'reset_pin';
                                                                        document.getElementById('user-id-field').value = userId;
                                                                        document.getElementById('new-pin-field').value = '';
                                                                        document.getElementById('action-form').submit();
                                                                    }
                                                                });
                                                            } else if (mode === 'set') {
                                                                Swal.fire({
                                                                    title: 'Set PIN Baru',
                                                                    text: 'Masukkan PIN baru (tepat 6 digit angka)',
                                                                    input: 'password',
                                                                    inputAttributes: {
                                                                        type: 'tel',
                                                                        maxlength: 6,
                                                                        pattern: '[0-9]{6}',
                                                                        inputmode: 'numeric'
                                                                    },
                                                                    inputValidator: (value) => {
                                                                        if (!value || value.length != 6 || !/^[0-9]{6}$/.test(value)) {
                                                                            return 'PIN harus tepat 6 digit angka!';
                                                                        }
                                                                    },
                                                                    showCancelButton: true,
                                                                    confirmButtonText: 'Set PIN',
                                                                    cancelButtonText: 'Batal',
                                                                    confirmButtonColor: '#8b5cf6'
                                                                }).then((result) => {
                                                                    if (result.isConfirmed) {
                                                                        document.getElementById('action-field').value = 'reset_pin';
                                                                        document.getElementById('user-id-field').value = userId;
                                                                        document.getElementById('new-pin-field').value = result.value;
                                                                        document.getElementById('action-form').submit();
                                                                    }
                                                                });
                                                            }
                                                        });
                                                    });
                                                }
                                            });
                                            return;
                                        case 'reset_email':
                                            confirmText = "Email akan dikosongkan";
                                            break;
                                        case 'reset_username':
                                            confirmText = "Username akan digenerate dari nama";
                                            break;
                                        case 'reset_all':
                                            confirmText = "Semua data akun akan direset";
                                            break;
                                    }
                                
                                    if (action !== 'reset_pin') {
                                        Swal.fire({
                                            title: 'Konfirmasi Reset',
                                            text: confirmText,
                                            icon: 'warning',
                                            showCancelButton: true,
                                            confirmButtonText: 'Ya, Reset!',
                                            cancelButtonText: 'Batal'
                                        }).then((result) => {
                                            if (result.isConfirmed) {
                                                document.getElementById('action-field').value = action;
                                                document.getElementById('user-id-field').value = userId;
                                                document.getElementById('action-form').submit();
                                            }
                                        });
                                    }
                                });
                            });
                        }
                    });
                });
            });
        
            // Freeze/Unfreeze handlers
            document.querySelectorAll('.freeze-btn, .unfreeze-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const userId = this.getAttribute('data-user-id');
                    const nama = this.getAttribute('data-nama');
                    const isFreeze = this.classList.contains('freeze-btn');
                
                    if (isFreeze) {
                        Swal.fire({
                            title: 'Bekukan Akun?',
                            text: `Akun ${nama} akan dibekukan. Berikan alasan:`,
                            input: 'text',
                            inputPlaceholder: 'Contoh: Melanggar aturan',
                            showCancelButton: true,
                            confirmButtonText: 'Bekukan',
                            cancelButtonText: 'Batal',
                            confirmButtonColor: '#d97706',
                            preConfirm: (alasan) => {
                                if (!alasan) {
                                    Swal.showValidationMessage('Alasan wajib diisi');
                                }
                                return alasan;
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                document.getElementById('action-field').value = 'freeze';
                                document.getElementById('user-id-field').value = userId;
                                document.getElementById('alasan-field').value = result.value;
                                document.getElementById('action-form').submit();
                            }
                        });
                    } else {
                        Swal.fire({
                            title: 'Aktifkan Kembali?',
                            text: `Akun ${nama} akan diaktifkan kembali.`,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Aktifkan',
                            cancelButtonText: 'Batal',
                            confirmButtonColor: '#059669'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                document.getElementById('action-field').value = 'unfreeze';
                                document.getElementById('user-id-field').value = userId;
                                document.getElementById('action-form').submit();
                            }
                        });
                    }
                });
            });
        
            // Delete handler
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const frozen = parseInt(this.getAttribute('data-frozen')) || 0;
                    if (frozen === 1) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal!',
                            text: 'Akun sedang dibekukan, tidak dapat dihapus!',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    const saldo = parseInt(this.getAttribute('data-saldo')) || 0;
                
                    if (saldo != 0) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Tidak Dapat Dihapus!',
                            text: 'Saldo masih tersedia, pastikan saldo sudah Rp 0.',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                
                    const nama = this.getAttribute('data-nama');
                    const rekeningId = this.getAttribute('data-rekening-id');
                    const userId = this.getAttribute('data-user-id');
                
                    Swal.fire({
                        title: 'Hapus Rekening?',
                        html: `Semua data <strong>${nama}</strong> akan dihapus permanen.<br><small>Tindakan ini tidak dapat dibatalkan!</small>`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Hapus',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#dc2626'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            document.getElementById('action-field').value = 'delete';
                            document.getElementById('rekening-id-field').value = rekeningId;
                            document.getElementById('user-id-field').value = userId;
                            document.getElementById('action-form').submit();
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>