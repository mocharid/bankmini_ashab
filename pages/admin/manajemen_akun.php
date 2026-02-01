<?php
/**
 * Manajemen Akun Siswa - Final Rapih Version (PIN BCRYPT + ensure user_security row)
 * File: pages/admin/manajemen_akun.php
 */
// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
if (!$project_root) {
    $project_root = $current_dir;
}
if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}
define('ASSETS_URL', BASE_URL . '/assets');
// ============================================
// FUNGSI UTILITAS
// ============================================
if (!function_exists('maskAccountNumber')) {
    function maskAccountNumber($accountNumber)
    {
        $accountNumber = (string) $accountNumber;
        if (strlen($accountNumber) <= 4) {
            return $accountNumber;
        }
        $visibleStart = 4;
        $visibleEnd = 2;
        $start = substr($accountNumber, 0, $visibleStart);
        $end = substr($accountNumber, -$visibleEnd);
        $hiddenLength = max(strlen($accountNumber) - $visibleStart - $visibleEnd, 0);
        $hidden = str_repeat('*', $hiddenLength);
        return $start . $hidden . $end;
    }
}
// ============================================
// LOGIC & DATABASE
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/pages/login.php?error=' . urlencode('Silakan login sebagai admin terlebih dahulu!'));
    exit();
}
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];
/**
 * Pastikan selalu ada baris di user_security untuk user_id tertentu.
 * Tidak mengubah logic lain, hanya create row default jika belum ada.
 */
function ensureUserSecurityRow($conn, $user_id)
{
    $check = $conn->prepare("SELECT id FROM user_security WHERE user_id = ?");
    if (!$check) {
        return;
    }
    $check->bind_param("i", $user_id);
    $check->execute();
    $res = $check->get_result();
    $exists = $res->num_rows > 0;
    $check->close();
    if (!$exists) {
        $ins = $conn->prepare(
            "INSERT INTO user_security
             (user_id, pin, has_pin, failed_pin_attempts, pin_block_until, is_frozen, freeze_timestamp, is_blocked)
             VALUES (?, NULL, 0, 0, NULL, 0, NULL, 0)"
        );
        if ($ins) {
            $ins->bind_param("i", $user_id);
            $ins->execute();
            $ins->close();
        }
    }
}
// ============================================
// INISIALISASI FILTER & PAGING
// ============================================
$search = '';
$jurusan_filter = '';
$tingkatan_filter = '';
$kelas_filter = '';
$page = 1;
$limit = 10;
$offset = 0;
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
// Auto set jurusan & tingkatan dari kelas
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
    $page = (int) $_GET['page'];
    $offset = ($page - 1) * $limit;
}
// ============================================
// HANDLE POST ACTIONS
// ============================================
if (
    $_SERVER['REQUEST_METHOD'] == 'POST'
    && isset($_POST['token'])
    && $_POST['token'] === $_SESSION['form_token']
) {
    $action = $_POST['action'] ?? '';
    $rekening_id = $_POST['rekening_id'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    switch ($action) {
        case 'freeze':
            $alasan = $_POST['alasan'] ?? '';
            if (!empty($user_id) && !empty($alasan)) {
                // pastikan row user_security ada dulu
                ensureUserSecurityRow($conn, (int) $user_id);
                $freeze_query = "UPDATE user_security
                                 SET is_frozen = 1, freeze_timestamp = NOW()
                                 WHERE user_id = ?";
                $freeze_stmt = $conn->prepare($freeze_query);
                if ($freeze_stmt) {
                    $freeze_stmt->bind_param("i", $user_id);
                    if ($freeze_stmt->execute()) {
                        $log_query = "INSERT INTO account_freeze_log
                                      (petugas_id, siswa_id, action, alasan, waktu)
                                      VALUES (?, ?, 'freeze', ?, NOW())";
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
                // pastikan row user_security ada dulu
                ensureUserSecurityRow($conn, (int) $user_id);
                $unfreeze_query = "UPDATE user_security
                                   SET is_frozen = 0, freeze_timestamp = NULL
                                   WHERE user_id = ?";
                $unfreeze_stmt = $conn->prepare($unfreeze_query);
                if ($unfreeze_stmt) {
                    $unfreeze_stmt->bind_param("i", $user_id);
                    if ($unfreeze_stmt->execute()) {
                        $log_query = "INSERT INTO account_freeze_log
                                      (petugas_id, siswa_id, action, alasan, waktu)
                                      VALUES (?, ?, 'unfreeze', 'Aktivasi ulang oleh admin', NOW())";
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
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $reset_query = "UPDATE users SET password = ? WHERE id = ?";
                $reset_stmt = $conn->prepare($reset_query);
                if ($reset_stmt) {
                    $reset_stmt->bind_param("si", $hashed_password, $user_id);
                    if ($reset_stmt->execute()) {
                        $log_query = "INSERT INTO log_aktivitas
                                      (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu)
                                      VALUES (?, ?, 'password', ?, 'Reset password oleh admin', NOW())";
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
                // pastikan row user_security ada dulu
                ensureUserSecurityRow($conn, (int) $user_id);
                if (isset($_POST['new_pin']) && !empty($_POST['new_pin'])) {
                    $new_pin = trim($_POST['new_pin']);
                    if (strlen($new_pin) == 6 && ctype_digit($new_pin)) {
                        // PIN pakai BCRYPT
                        $hashed_pin = password_hash($new_pin, PASSWORD_BCRYPT);
                        $reset_query = "UPDATE user_security
                                        SET pin = ?, has_pin = 1
                                        WHERE user_id = ?";
                        $reset_stmt = $conn->prepare($reset_query);
                        if ($reset_stmt) {
                            $reset_stmt->bind_param("si", $hashed_pin, $user_id);
                            if ($reset_stmt->execute()) {
                                $log_query = "INSERT INTO log_aktivitas
                                              (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu)
                                              VALUES (?, ?, 'pin', ?, 'Set PIN baru oleh admin', NOW())";
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
                    $reset_query = "UPDATE user_security
                                    SET pin = NULL, has_pin = 0
                                    WHERE user_id = ?";
                    $reset_stmt = $conn->prepare($reset_query);
                    if ($reset_stmt) {
                        $reset_stmt->bind_param("i", $user_id);
                        if ($reset_stmt->execute()) {
                            $log_query = "INSERT INTO log_aktivitas
                                          (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu)
                                          VALUES (?, ?, 'pin', '', 'Kosongkan PIN oleh admin', NOW())";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_stmt->bind_param("ii", $_SESSION['user_id'], $user_id);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                            $_SESSION['success_message'] =
                                "PIN berhasil dikosongkan! Siswa harus membuat PIN baru saat login.";
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
                $reset_query = "UPDATE users
                                SET email = NULL, email_status = 'kosong'
                                WHERE id = ?";
                $reset_stmt = $conn->prepare($reset_query);
                if ($reset_stmt) {
                    $reset_stmt->bind_param("i", $user_id);
                    if ($reset_stmt->execute()) {
                        $log_query = "INSERT INTO log_aktivitas
                                      (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu)
                                      VALUES (?, ?, 'email', '', 'Reset email oleh admin', NOW())";
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
                    function generateUsernameFromNama($nama, $conn)
                    {
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
                            $log_query = "INSERT INTO log_aktivitas
                                          (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu)
                                          VALUES (?, ?, 'username', ?, 'Reset username oleh admin', NOW())";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $new_username);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                            $_SESSION['success_message'] =
                                "Username berhasil direset menjadi: " . $new_username;
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
                    function generateUsernameFromNama2($nama, $conn)
                    {
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
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $conn->begin_transaction();
                    try {
                        $reset_query = "UPDATE users
                                        SET username = ?,
                                            password = ?,
                                            email = NULL,
                                            email_status = 'kosong'
                                        WHERE id = ?";
                        $reset_stmt = $conn->prepare($reset_query);
                        $reset_stmt->bind_param("ssi", $new_username, $hashed_password, $user_id);
                        if ($reset_stmt->execute()) {
                            $reset_security = "UPDATE user_security
                                               SET pin = NULL, has_pin = 0
                                               WHERE user_id = ?";
                            $security_stmt = $conn->prepare($reset_security);
                            $security_stmt->bind_param("i", $user_id);
                            $security_stmt->execute();
                            $security_stmt->close();
                            $log_query = "INSERT INTO log_aktivitas
                                          (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu)
                                          VALUES (?, ?, 'all', ?, 'Reset semua data oleh admin', NOW())";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_values = "Username: $new_username, Password: 12345, Email: kosong, PIN: kosong";
                                $log_stmt->bind_param("iis", $_SESSION['user_id'], $user_id, $log_values);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                            $conn->commit();
                            $_SESSION['success_message'] =
                                "Semua data berhasil direset!<br>Username: " . $new_username .
                                "<br>Password: 12345<br>Email: Kosong<br>PIN: Kosong";
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
                $check_frozen_query = "SELECT is_frozen FROM user_security WHERE user_id = ?";
                $check_frozen_stmt = $conn->prepare($check_frozen_query);
                $check_frozen_stmt->bind_param("i", $user_id);
                $check_frozen_stmt->execute();
                $frozen_result = $check_frozen_stmt->get_result();
                if ($frozen_result->num_rows > 0) {
                    $frozen_data = $frozen_result->fetch_assoc();
                    if ($frozen_data['is_frozen'] == 1) {
                        $_SESSION['error_message'] =
                            "Gagal! Akun sedang dibekukan, tidak dapat dihapus!";
                        $check_frozen_stmt->close();
                        break;
                    }
                }
                $check_frozen_stmt->close();
                // Pastikan saldo 0
                $check_saldo_query = "SELECT saldo FROM rekening WHERE id = ?";
                $check_saldo_stmt = $conn->prepare($check_saldo_query);
                $check_saldo_stmt->bind_param("i", $rekening_id);
                $check_saldo_stmt->execute();
                $saldo_result = $check_saldo_stmt->get_result();
                if ($saldo_result->num_rows > 0) {
                    $saldo_data = $saldo_result->fetch_assoc();
                    $saldo = intval($saldo_data['saldo'] ?? 0);
                    if ($saldo != 0) {
                        $_SESSION['error_message'] =
                            "Gagal menghapus rekening! Saldo masih tersedia, pastikan saldo sudah Rp 0.";
                        $check_saldo_stmt->close();
                        break;
                    }
                }
                $check_saldo_stmt->close();
                $conn->begin_transaction();
                try {
                    // Hapus mutasi yang terkait
                    $delete_mutasi = "DELETE m FROM mutasi m
                                      INNER JOIN transaksi t ON m.transaksi_id = t.id
                                      WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?";
                    $mutasi_stmt = $conn->prepare($delete_mutasi);
                    $mutasi_stmt->bind_param("ii", $rekening_id, $rekening_id);
                    $mutasi_stmt->execute();
                    $mutasi_stmt->close();
                    // Hapus transaksi
                    $delete_transaksi = "DELETE FROM transaksi
                                         WHERE rekening_id = ? OR rekening_tujuan_id = ?";
                    $transaksi_stmt = $conn->prepare($delete_transaksi);
                    $transaksi_stmt->bind_param("ii", $rekening_id, $rekening_id);
                    $transaksi_stmt->execute();
                    $transaksi_stmt->close();
                    // Hapus rekening
                    $delete_rekening = "DELETE FROM rekening WHERE id = ?";
                    $rekening_stmt = $conn->prepare($delete_rekening);
                    $rekening_stmt->bind_param("i", $rekening_id);
                    $rekening_stmt->execute();
                    $rekening_stmt->close();
                    // Hapus cooldown
                    $delete_cooldown = "DELETE FROM reset_request_cooldown WHERE user_id = ?";
                    $cooldown_stmt = $conn->prepare($delete_cooldown);
                    $cooldown_stmt->bind_param("i", $user_id);
                    $cooldown_stmt->execute();
                    $cooldown_stmt->close();
                    // Hapus user
                    $delete_user = "DELETE FROM users WHERE id = ?";
                    $user_stmt = $conn->prepare($delete_user);
                    $user_stmt->bind_param("i", $user_id);
                    $user_stmt->execute();
                    $user_stmt->close();
                    $conn->commit();
                    $_SESSION['success_message'] = "Data rekening dan nasabah berhasil dihapus!";
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['error_message'] =
                        "Gagal menghapus rekening: " . $e->getMessage();
                }
            }
            break;
    }
    header("Location: " . BASE_URL . "/pages/admin/manajemen_akun.php?" . http_build_query($_GET));
    exit;
}
// ============================================
// FETCH MASTER DATA
// ============================================
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan";
$result_jurusan = $conn->query($query_jurusan);
if (!$result_jurusan) {
    error_log("Error fetching jurusan: " . $conn->error);
}
$query_tingkatan = "SELECT * FROM tingkatan_kelas ORDER BY nama_tingkatan";
$result_tingkatan = $conn->query($query_tingkatan);
if (!$result_tingkatan) {
    error_log("Error fetching tingkatan: " . $conn->error);
}
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
// ============================================
// MAIN QUERY
// ============================================
$where_conditions = [];
$params = [];
$types = "";
$query = "SELECT
            r.id AS rekening_id,
            r.no_rekening,
            r.saldo,
            u.id AS user_id,
            u.nama,
            u.username,
            us.is_frozen,
            j.nama_jurusan,
            k.nama_kelas,
            tk.nama_tingkatan
          FROM rekening r
          INNER JOIN users u ON r.user_id = u.id
          INNER JOIN siswa_profiles sp ON u.id = sp.user_id
          LEFT JOIN user_security us ON u.id = us.user_id
          LEFT JOIN jurusan j ON sp.jurusan_id = j.id
          LEFT JOIN kelas k ON sp.kelas_id = k.id
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
    $where_conditions[] = "sp.jurusan_id = ?";
    $params[] = $jurusan_filter;
    $types .= "i";
}
if (!empty($tingkatan_filter)) {
    $where_conditions[] = "k.tingkatan_kelas_id = ?";
    $params[] = $tingkatan_filter;
    $types .= "i";
}
if (!empty($kelas_filter)) {
    $where_conditions[] = "sp.kelas_id = ?";
    $params[] = $kelas_filter;
    $types .= "i";
}
if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}
$query .= " ORDER BY u.nama ASC";
// Count total
$count_query = "SELECT COUNT(*) AS total
                FROM rekening r
                INNER JOIN users u ON r.user_id = u.id
                INNER JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN user_security us ON u.id = us.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
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
    }
    unset($rekening);
    $stmt->close();
} else {
    error_log("Error preparing main query: " . $conn->error);
    $rekening_data = [];
}
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Manajemen Akun Siswa | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --primary-color: var(--gray-800);
            --primary-dark: var(--gray-900);
            --secondary-color: var(--gray-600);

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        body.sidebar-open {
            overflow: hidden;
        }

        body.sidebar-open::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            backdrop-filter: blur(5px);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            position: relative;
            z-index: 1;
        }

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content {
            flex: 1;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        .page-hamburger {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            align-items: center;
            justify-content: center;
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        /* Cards */
        .card,
        .form-card,
        .jadwal-list {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: var(--gray-100);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
            padding: 1.25rem 1.5rem;
            background: white;
        }

        .filter-actions {
            width: 100%;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: flex-start;
            margin-top: 0.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            font-size: .9rem;
            color: var(--text-secondary);
        }

        input[type="text"],
        input[type="date"],
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
            height: 48px;
            color: var(--gray-800);
        }

        input:focus,
        select:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        .btn {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-cancel {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
            box-shadow: none;
        }

        .btn-cancel:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table th {
            background: var(--gray-100);
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .data-table tbody tr {
            background: white;
        }

        .data-table tr:hover {
            background: var(--gray-100) !important;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
            cursor: default;
        }

        .page-link.page-arrow {
            padding: 0.5rem 0.75rem;
        }

        .page-link.disabled {
            color: var(--gray-300);
            cursor: not-allowed;
            background: var(--gray-50);
            pointer-events: none;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .actions .btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .actions .btn i {
            margin-right: 4px;
        }

        .btn-action {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            color: white;
        }

        .btn-action:hover {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-section {
                margin: -1rem -1rem 1rem -1rem;
                padding: 1rem;
            }

            .page-title {
                font-size: 1.25rem;
            }

            .page-subtitle {
                font-size: 0.85rem;
            }

            .filter-bar {
                flex-direction: column;
                padding: 1rem;
            }

            .filter-group {
                width: 100%;
                min-width: 100%;
            }

            .filter-bar .btn {
                width: 100%;
            }

            .card-header {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .table-wrapper {
                overflow-x: auto;
            }

            .data-table {
                min-width: auto;
            }

            .data-table th,
            .data-table td {
                padding: 0.5rem;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .actions {
                flex-direction: row;
                flex-wrap: nowrap;
                gap: 4px;
            }

            .actions .btn {
                padding: 6px 12px;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .actions .btn i {
                margin-right: 2px;
            }

            .pagination {
                flex-wrap: wrap;
                padding: 1rem;
            }

            .page-link {
                padding: 0.4rem 0.75rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .page-subtitle {
                font-size: 0.8rem;
            }
        }

        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>
    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Manajemen Akun Siswa</h1>
                <p class="page-subtitle">Kelola akun siswa dengan mudah di sini</p>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-filter"></i>
                </div>
                <div class="card-header-text">
                    <h3>Filter Data Akun</h3>
                    <p>Cari dan kelola akun siswa</p>
                </div>
            </div>

            <form method="GET" action="" class="filter-bar">
                <div class="filter-group">
                    <label for="search">Pencarian</label>
                    <input type="text" id="search" name="search" placeholder="Nama, Username, No. Rekening"
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label for="jurusan">Jurusan</label>
                    <select id="jurusan" name="jurusan" onchange="updateTingkatanOptions()">
                        <option value="">Semua Jurusan</option>
                        <?php
                        if ($result_jurusan && $result_jurusan->num_rows > 0) {
                            $result_jurusan->data_seek(0);
                            while ($row = $result_jurusan->fetch_assoc()) { ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $jurusan_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                </option>
                            <?php }
                        } ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="tingkatan">Tingkatan</label>
                    <select id="tingkatan" name="tingkatan" onchange="updateKelasOptions()">
                        <option value="">Semua Tingkatan</option>
                        <?php
                        if ($result_tingkatan && $result_tingkatan->num_rows > 0) {
                            $result_tingkatan->data_seek(0);
                            while ($row = $result_tingkatan->fetch_assoc()) { ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $tingkatan_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                                </option>
                            <?php }
                        } ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="kelas">Kelas</label>
                    <select id="kelas" name="kelas">
                        <option value="">Semua Kelas</option>
                        <?php
                        if ($result_kelas && $result_kelas->num_rows > 0) {
                            $result_kelas->data_seek(0);
                            while ($row = $result_kelas->fetch_assoc()) { ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $kelas_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(
                                        $row['nama_tingkatan'] . ' ' . $row['nama_kelas'] . ' - ' . $row['nama_jurusan']
                                    ); ?>
                                </option>
                            <?php }
                        } ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn"><i class="fas fa-search"></i> Filter</button>
                    <button type="button" id="resetBtn" class="btn btn-cancel"><i class="fas fa-redo"></i>
                        Reset</button>
                </div>
            </form>
        </div>

        <!-- CARD DATA TABLE -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-header-text">
                    <h3>Data Akun Siswa</h3>
                    <p>Menampilkan <?php echo count($rekening_data); ?> dari <?php echo $total_records; ?> data</p>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th>Jurusan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rekening_data)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:30px;color:#999;">
                                    Belum ada data akun siswa.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; ?>
                            <?php foreach ($rekening_data as $rekening): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td style="font-weight:500;">
                                        <?php echo htmlspecialchars($rekening['nama']); ?>
                                    </td>
                                    <td style="font-size:0.8125rem;">
                                        <?php
                                        $kelas_lengkap = '';
                                        if (!empty($rekening['nama_tingkatan']) && !empty($rekening['nama_kelas'])) {
                                            $kelas_lengkap = $rekening['nama_tingkatan'] . ' ' . $rekening['nama_kelas'];
                                        }
                                        echo htmlspecialchars($kelas_lengkap ?: '-');
                                        ?>
                                    </td>
                                    <td style="font-size:0.8125rem;">
                                        <?php echo htmlspecialchars($rekening['nama_jurusan'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (!empty($rekening['is_frozen'])): ?>
                                                <button class="btn btn-action unfreeze-btn"
                                                    data-user-id="<?php echo $rekening['user_id']; ?>"
                                                    data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>">
                                                    <i class="fas fa-unlock"></i> Buka
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-action freeze-btn"
                                                    data-user-id="<?php echo $rekening['user_id']; ?>"
                                                    data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>">
                                                    <i class="fas fa-lock"></i> Bekukan
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-action reset-btn"
                                                data-user-id="<?php echo $rekening['user_id']; ?>"
                                                data-nama="<?php echo htmlspecialchars($rekening['nama']); ?>"
                                                data-has-username="<?php echo empty($rekening['username']) ? '0' : '1'; ?>">
                                                <i class="fas fa-redo"></i> Reset
                                            </button>
                                            <button class="btn btn-action delete-btn"
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
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <?php
                $q = '&search=' . urlencode($search) . '&jurusan=' . $jurusan_filter . '&tingkatan=' . $tingkatan_filter . '&kelas=' . $kelas_filter;
                ?>
                <div class="pagination">
                    <!-- Previous -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= $q ?>" class="page-link page-arrow">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Current -->
                    <span class="page-link active"><?= $page ?></span>

                    <!-- Next -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $q ?>" class="page-link page-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
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
        const pageHamburgerBtn = document.getElementById('pageHamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;
        function openSidebar() { body.classList.add('sidebar-open'); if (sidebar) sidebar.classList.add('active'); }
        function closeSidebar() { body.classList.remove('sidebar-open'); if (sidebar) sidebar.classList.remove('active'); }
        if (pageHamburgerBtn) {
            pageHamburgerBtn.addEventListener('click', e => {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) closeSidebar(); else openSidebar();
            });
        }
        document.addEventListener('click', e => {
            if (body.classList.contains('sidebar-open') && !sidebar?.contains(e.target) && !pageHamburgerBtn?.contains(e.target)) {
                closeSidebar();
            }
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) closeSidebar();
        });
        document.getElementById('resetBtn').addEventListener('click', () => {
            window.location.href = '<?= BASE_URL ?>/pages/admin/manajemen_akun.php';
        });
        <?php if (!empty($success_message)): ?>
            Swal.fire({
                title: 'Berhasil',
                text: '<?= addslashes($success_message) ?>',
                icon: 'success',
                confirmButtonColor: '#475569'
            });
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            Swal.fire({
                title: 'Gagal',
                text: '<?= addslashes($error_message) ?>',
                icon: 'error',
                confirmButtonColor: '#475569'
            });
        <?php endif; ?>
        window.updateTingkatanOptions = function () {
            const jurusan = document.getElementById('jurusan').value;
            const tingkatanSelect = document.getElementById('tingkatan');
            const selected = tingkatanSelect.value;
            tingkatanSelect.innerHTML = '<option value="">Semua Tingkatan</option>';
            if (jurusan) {
                fetch('<?= BASE_URL ?>/includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=' + jurusan + '&action=get_tingkatan')
                    .then(r => r.text())
                    .then(data => {
                        if (data.trim()) {
                            tingkatanSelect.innerHTML += data;
                            if (selected) {
                                const opt = tingkatanSelect.querySelector('option[value="' + selected + '"]');
                                if (opt) tingkatanSelect.value = selected;
                            }
                        }
                        updateKelasOptions();
                    }).catch(() => { });
            } else {
                updateKelasOptions();
            }
        };
        window.updateKelasOptions = function () {
            const jurusan = document.getElementById('jurusan').value;
            const tingkatan = document.getElementById('tingkatan').value;
            const kelasSelect = document.getElementById('kelas');
            const selected = kelasSelect.value;
            kelasSelect.innerHTML = '<option value="">Semua Kelas</option>';
            if (jurusan) {
                let url = '<?= BASE_URL ?>/includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=' + jurusan + '&action=get_kelas';
                if (tingkatan) { url += '&tingkatan_id=' + tingkatan; }
                fetch(url)
                    .then(r => r.text())
                    .then(data => {
                        if (data.trim()) {
                            kelasSelect.innerHTML += data;
                            if (selected) {
                                const opt = kelasSelect.querySelector('option[value="' + selected + '"]');
                                if (opt) kelasSelect.value = selected;
                            }
                        }
                    }).catch(() => { });
            }
        };
        if (document.getElementById('jurusan').value) {
            updateTingkatanOptions();
        }
        // RESET HANDLER (password, pin, email, username, all)
        document.querySelectorAll('.reset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const userId = this.getAttribute('data-user-id');
                const nama = this.getAttribute('data-nama');
                const hasUsername = this.getAttribute('data-has-username') === '1';
                let htmlContent = `
<div style="text-align:left; padding:15px 10px;">
  <p style="margin-bottom:18px; color:#6b7280; font-size:0.92rem;">Pilih opsi reset yang diinginkan:</p>
  <button class="swal-custom-btn swal-btn-pin" data-action="reset_pin">
    <i class="fas fa-lock"></i>
    <div><strong>Reset PIN</strong><small>Kosongkan atau set PIN baru (6 digit)</small></div>
  </button>
`;
                if (hasUsername) {
                    htmlContent += `
  <button class="swal-custom-btn swal-btn-password" data-action="reset_password">
    <i class="fas fa-key"></i>
    <div><strong>Reset Password</strong><small>Password direset ke '12345'</small></div>
  </button>
  <button class="swal-custom-btn swal-btn-email" data-action="reset_email">
    <i class="fas fa-envelope"></i>
    <div><strong>Reset Email</strong><small>Email dikosongkan</small></div>
  </button>
  <button class="swal-custom-btn swal-btn-username" data-action="reset_username">
    <i class="fas fa-user"></i>
    <div><strong>Reset Username</strong><small>Username dibuat ulang dari nama</small></div>
  </button>
  <button class="swal-custom-btn swal-btn-all" data-action="reset_all">
    <i class="fas fa-sync-alt"></i>
    <div><strong>Reset Semua Data</strong><small>Reset seluruh kredensial akun</small></div>
  </button>
`;
                }
                htmlContent += `
</div>
<style>
.swal-custom-btn{width:100%;padding:14px;margin-bottom:10px;border:1px solid #e5e7eb;border-radius:5px;background:#fff;cursor:pointer;display:flex;align-items:center;gap:14px;transition:.2s;font-family:'Poppins',sans-serif;}
.swal-custom-btn:hover{border-color:#3b82f6;background:#f0f5ff;}
.swal-custom-btn i{font-size:1.4rem;width:36px;text-align:center;}
.swal-custom-btn div{text-align:left;flex:1;}
.swal-custom-btn strong{display:block;font-size:0.92rem;color:#1f2937;margin-bottom:3px;}
.swal-custom-btn small{display:block;font-size:0.78rem;color:#6b7280;}
.swal-btn-password i{color:#f59e0b;}
.swal-btn-pin i{color:#8b5cf6;}
.swal-btn-email i{color:#06b6d4;}
.swal-btn-username i{color:#10b981;}
.swal-btn-all i{color:#dc2626;}
</style>
`;
                Swal.fire({
                    title: `Reset Akun: ${nama}`,
                    html: htmlContent,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Tutup',
                    width: '480px',
                    didOpen: () => {
                        document.querySelectorAll('.swal-custom-btn').forEach(button => {
                            button.addEventListener('click', function () {
                                const action = this.getAttribute('data-action');
                                Swal.close();
                                if (action === 'reset_pin') {
                                    Swal.fire({
                                        title: 'Pilihan Reset PIN',
                                        html: `
<div style="text-align:left; padding:15px 10px;">
  <p style="margin-bottom:18px; color:#6b7280; font-size:0.92rem;">Pilih aksi untuk PIN:</p>
  <button class="swal-pin-btn swal-pin-clear" data-mode="clear">
    <i class="fas fa-trash"></i>
    <div><strong>Kosongkan PIN</strong><small>Siswa harus buat PIN baru saat login</small></div>
  </button>
  <button class="swal-pin-btn swal-pin-set" data-mode="set">
    <i class="fas fa-plus"></i>
    <div><strong>Set PIN Baru</strong><small>Masukkan 6 digit angka baru</small></div>
  </button>
</div>
<style>
.swal-pin-btn{width:100%;padding:14px;margin-bottom:10px;border:1px solid #e5e7eb;border-radius:5px;background:#fff;cursor:pointer;display:flex;align-items:center;gap:14px;transition:.2s;font-family:'Poppins',sans-serif;}
.swal-pin-btn:hover{border-color:#8b5cf6;background:#f3e8ff;}
.swal-pin-btn i{font-size:1.4rem;width:36px;text-align:center;}
.swal-pin-btn div{text-align:left;flex:1;}
.swal-pin-btn strong{display:block;font-size:0.92rem;color:#1f2937;margin-bottom:3px;}
.swal-pin-btn small{display:block;font-size:0.78rem;color:#6b7280;}
.swal-pin-clear i{color:#dc2626;}
.swal-pin-set i{color:#8b5cf6;}
</style>
                                    `,
                                        showConfirmButton: false,
                                        showCancelButton: true,
                                        cancelButtonText: 'Batal',
                                        width: '420px',
                                        didOpen: () => {
                                            document.querySelectorAll('.swal-pin-btn').forEach(pinBtn => {
                                                pinBtn.addEventListener('click', function () {
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
                                                        }).then(result => {
                                                            if (result.isConfirmed) {
                                                                document.getElementById('action-field').value = 'reset_pin';
                                                                document.getElementById('user-id-field').value = userId;
                                                                document.getElementById('new-pin-field').value = '';
                                                                document.getElementById('action-form').submit();
                                                            }
                                                        });
                                                    } else {
                                                        Swal.fire({
                                                            title: 'Set PIN Baru',
                                                            text: 'Masukkan PIN baru tepat 6 digit angka',
                                                            input: 'password',
                                                            inputAttributes: {
                                                                type: 'tel',
                                                                maxlength: 6,
                                                                pattern: '[0-9]{6}',
                                                                inputmode: 'numeric'
                                                            },
                                                            inputValidator: value => {
                                                                if (!value || value.length !== 6 || !/^[0-9]{6}$/.test(value)) {
                                                                    return 'PIN harus tepat 6 digit angka!';
                                                                }
                                                            },
                                                            showCancelButton: true,
                                                            confirmButtonText: 'Set PIN',
                                                            cancelButtonText: 'Batal',
                                                            confirmButtonColor: '#8b5cf6'
                                                        }).then(result => {
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
                                }
                                let confirmText = '';
                                if (action === 'reset_password') confirmText = "Password akan direset ke '12345'";
                                else if (action === 'reset_email') confirmText = "Email akan dikosongkan";
                                else if (action === 'reset_username') confirmText = "Username akan digenerate dari nama";
                                else if (action === 'reset_all') confirmText = "Semua data akun akan direset";
                                Swal.fire({
                                    title: 'Konfirmasi Reset',
                                    text: confirmText,
                                    icon: 'warning',
                                    showCancelButton: true,
                                    confirmButtonText: 'Ya, Reset!',
                                    cancelButtonText: 'Batal'
                                }).then(result => {
                                    if (result.isConfirmed) {
                                        document.getElementById('action-field').value = action;
                                        document.getElementById('user-id-field').value = userId;
                                        document.getElementById('action-form').submit();
                                    }
                                });
                            });
                        });
                    }
                });
            });
        });
        // Freeze / Unfreeze handler
        document.querySelectorAll('.freeze-btn, .unfreeze-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const userId = this.getAttribute('data-user-id');
                const nama = this.getAttribute('data-nama');
                const isFreeze = this.classList.contains('freeze-btn');
                if (isFreeze) {
                    Swal.fire({
                        title: 'Bekukan Akun?',
                        html: `
<div style="text-align:left; padding:10px 0;">
  <p style="margin-bottom:10px; color:#6b7280; font-size:0.9rem;">
    Pilih alasan pembekuan akun untuk <strong>${nama}</strong>:
  </p>
  <div style="display:flex;justify-content:center;">
    <select id="alasan-freeze-select" class="swal2-select"
            style="width:80%;max-width:360px;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:0.9rem;">
      <option value="">-- Pilih Alasan --</option>
      <option value="Melanggar aturan tata tertib">Melanggar aturan tata tertib</option>
      <option value="Penyalahgunaan rekening / transaksi mencurigakan">Penyalahgunaan rekening / transaksi mencurigakan</option>
      <option value="Permintaan wali / pihak sekolah">Permintaan wali / pihak sekolah</option>
      <option value="Rekening tidak aktif / tidak digunakan">Rekening tidak aktif / tidak digunakan</option>
      <option value="Lainnya">Lainnya (catat manual di log terpisah)</option>
    </select>
  </div>
</div>
                    `,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Bekukan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#d97706',
                        preConfirm: () => {
                            const select = document.getElementById('alasan-freeze-select');
                            if (!select || !select.value) {
                                Swal.showValidationMessage('Silakan pilih alasan pembekuan');
                                return false;
                            }
                            return select.value;
                        }
                    }).then(result => {
                        if (result.isConfirmed) {
                            document.getElementById('action-field').value = 'freeze';
                            document.getElementById('user-id-field').value = userId;
                            document.getElementById('alasan-field').value = result.value;
                            document.getElementById('action-form').submit();
                        }
                    });
                } else {
                    Swal.fire({
                        title: 'Buka Akun?',
                        text: `Akun ${nama} akan diaktifkan kembali.`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Buka Akun',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#059669'
                    }).then(result => {
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
            btn.addEventListener('click', function () {
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
                if (saldo !== 0) {
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
                }).then(result => {
                    if (result.isConfirmed) {
                        document.getElementById('action-field').value = 'delete';
                        document.getElementById('rekening-id-field').value = rekeningId;
                        document.getElementById('user-id-field').value = userId;
                        document.getElementById('action-form').submit();
                    }
                });
            });
        });
    </script>
</body>

</html>