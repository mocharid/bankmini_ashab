<?php
/**
 * Proses Absensi Petugas - FULLY COMPATIBLE DB BARU + JAM MASUK/KELUAR
 * File: pages/petugas/proses_absensi.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
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
    $project_root = dirname(dirname($current_dir));
}

define('PROJECT_ROOT', rtrim($project_root, '/'));
define('INCLUDES_PATH', PROJECT_ROOT . '/includes');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';

date_default_timezone_set('Asia/Jakarta');

// ============================================
// DEBUG FUNCTION
// ============================================
function debug_log($message) {
    error_log("[ABSENSI] " . date('Y-m-d H:i:s') . " - " . $message);
}

// ============================================
// FETCH ABSENSI DATA - SESUAI DB BARU
// ============================================
function fetchAbsensiData($conn) {
    $data = [
        'is_blocked' => false,
        'schedule' => null,
        'petugas1_nama' => null,
        'petugas2_nama' => null,
        'attendance_petugas1' => null,
        'attendance_petugas2' => null,
        'attendance_complete' => false
    ];

    try {
        // Schedule dari petugas_shift + nama dari petugas_profiles
        $query_schedule = "SELECT ps.*, 
                           COALESCE(pp1.petugas1_nama, pp1.petugas2_nama) AS petugas1_nama,
                           COALESCE(pp2.petugas2_nama, pp2.petugas1_nama) AS petugas2_nama,
                           pp1.user_id AS petugas1_user_id,
                           pp2.user_id AS petugas2_user_id
                           FROM petugas_shift ps
                           LEFT JOIN petugas_profiles pp1 ON ps.petugas1_id = pp1.user_id
                           LEFT JOIN petugas_profiles pp2 ON ps.petugas2_id = pp2.user_id
                           WHERE ps.tanggal = CURDATE()";
        
        $stmt_schedule = $conn->prepare($query_schedule);
        if (!$stmt_schedule) {
            debug_log("Schedule prepare failed: " . $conn->error);
            return $data;
        }
        
        $stmt_schedule->execute();
        $result_schedule = $stmt_schedule->get_result();
        $data['schedule'] = $result_schedule->fetch_assoc();
        $stmt_schedule->close();

        debug_log("Schedule: " . ($data['schedule'] ? 'FOUND' : 'NOT FOUND'));

        if ($data['schedule']) {
            $data['petugas1_nama'] = trim($data['schedule']['petugas1_nama'] ?? '');
            $data['petugas2_nama'] = trim($data['schedule']['petugas2_nama'] ?? '');
            $shift_id = $data['schedule']['id'];

            debug_log("Petugas1: '{$data['petugas1_nama']}', Petugas2: '{$data['petugas2_nama']}'");

            // Attendance petugas1 ✅ DENGAN JAM
            if ($data['petugas1_nama']) {
                $query_att1 = "SELECT * FROM petugas_status 
                              WHERE petugas_shift_id = ? AND petugas_type = 'petugas1'";
                $stmt_att1 = $conn->prepare($query_att1);
                if ($stmt_att1) {
                    $stmt_att1->bind_param("i", $shift_id);
                    $stmt_att1->execute();
                    $data['attendance_petugas1'] = $stmt_att1->get_result()->fetch_assoc();
                    $stmt_att1->close();
                    debug_log("Att1: " . ($data['attendance_petugas1'] ? 'FOUND' : 'NOT FOUND'));
                }
            }

            // Attendance petugas2 ✅ DENGAN JAM
            if ($data['petugas2_nama']) {
                $query_att2 = "SELECT * FROM petugas_status 
                              WHERE petugas_shift_id = ? AND petugas_type = 'petugas2'";
                $stmt_att2 = $conn->prepare($query_att2);
                if ($stmt_att2) {
                    $stmt_att2->bind_param("i", $shift_id);
                    $stmt_att2->execute();
                    $data['attendance_petugas2'] = $stmt_att2->get_result()->fetch_assoc();
                    $stmt_att2->close();
                    debug_log("Att2: " . ($data['attendance_petugas2'] ? 'FOUND' : 'NOT FOUND'));
                }
            }

            // Complete check ✅ DENGAN JAM LOGIC
            $petugas1_done = !$data['petugas1_nama'] || (
                $data['attendance_petugas1'] && (
                    ($data['attendance_petugas1']['status'] == 'hadir' && $data['attendance_petugas1']['waktu_keluar']) ||
                    $data['attendance_petugas1']['status'] != 'hadir'
                )
            );
            $petugas2_done = !$data['petugas2_nama'] || (
                $data['attendance_petugas2'] && (
                    ($data['attendance_petugas2']['status'] == 'hadir' && $data['attendance_petugas2']['waktu_keluar']) ||
                    $data['attendance_petugas2']['status'] != 'hadir'
                )
            );
            $data['attendance_complete'] = $petugas1_done && $petugas2_done;
        }
    } catch (Exception $e) {
        debug_log("Fetch error: " . $e->getMessage());
    }

    return $data;
}

// ============================================
// PROCESS POST REQUEST ✅ SESUAI DB BARU + JAM
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    debug_log("POST: " . json_encode($_POST));
    
    $action = trim($_POST['action'] ?? '');
    $petugas_type = trim($_POST['petugas_type'] ?? '');
    
    if (empty($action) || empty($petugas_type)) {
        $_SESSION['error_message'] = 'Data tidak lengkap.';
        debug_log("Missing action or petugas_type");
        header("Location: absensi.php", true, 303);
        exit();
    }

    $page_data = fetchAbsensiData($conn);
    $schedule = $page_data['schedule'];
    
    if (!$schedule) {
        $_SESSION['error_message'] = 'Tidak ada jadwal petugas hari ini.';
        debug_log("No schedule");
        header("Location: absensi.php", true, 303);
        exit();
    }

    $shift_id = $schedule['id'];
    $petugas_id = ($petugas_type === 'petugas1') ? $schedule['petugas1_id'] : $schedule['petugas2_id'];
    $petugas_name = ($petugas_type === 'petugas1') ? $page_data['petugas1_nama'] : $page_data['petugas2_nama'];

    debug_log("Process: shift=$shift_id, petugas_id=$petugas_id, type=$petugas_type, action=$action");

    try {
        $conn->autocommit(FALSE);
        $conn->begin_transaction();

        if (in_array($action, ['hadir', 'tidak_hadir', 'sakit', 'izin'])) {
            // Check existing record
            $check_query = "SELECT id, status, waktu_masuk, waktu_keluar 
                           FROM petugas_status 
                           WHERE petugas_shift_id = ? AND petugas_type = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("is", $shift_id, $petugas_type);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($existing) {
                $_SESSION['error_message'] = "Absensi $petugas_name sudah ada (Status: {$existing['status']})";
                debug_log("Already exists: " . $existing['status']);
                $conn->rollback();
                $conn->autocommit(TRUE);
                header("Location: absensi.php", true, 303);
                exit();
            }

            // ✅ INSERT LENGKAP - SESUAI DB BARU + JAM
            $waktu_masuk = ($action === 'hadir') ? date('Y-m-d H:i:s') : NULL;
            $keterangan = ($action === 'hadir') ? 'Absen masuk otomatis' : ucfirst($action);
            
            $insert_query = "INSERT INTO petugas_status 
                           (petugas_shift_id, petugas_id, petugas_type, status, keterangan, waktu_masuk) 
                           VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt_insert = $conn->prepare($insert_query);
            if (!$stmt_insert) {
                throw new Exception("Insert prepare failed: " . $conn->error);
            }
            
            $stmt_insert->bind_param("iissss", $shift_id, $petugas_id, $petugas_type, $action, $keterangan, $waktu_masuk);
            
            if (!$stmt_insert->execute()) {
                throw new Exception("Insert execute failed: " . $stmt_insert->error);
            }
            
            $stmt_insert->close();
            
            $status_text = ($action === 'tidak_hadir') ? 'Tidak Hadir' : ucfirst($action);
            $jam_text = $waktu_masuk ? date('H:i', strtotime($waktu_masuk)) : '-';
            $_SESSION['success_message'] = "$petugas_name $status_text pukul $jam_text";
            debug_log("INSERT SUCCESS: $action pukul $jam_text");

        } elseif ($action === 'check_out') {
            // Check existing untuk checkout
            $check_query = "SELECT id, status, waktu_keluar FROM petugas_status 
                           WHERE petugas_shift_id = ? AND petugas_type = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("is", $shift_id, $petugas_type);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if (!$existing) {
                $_SESSION['error_message'] = 'Belum absen masuk.';
                debug_log("No record for checkout");
                $conn->rollback();
                $conn->autocommit(TRUE);
                header("Location: absensi.php", true, 303);
                exit();
            }

            if ($existing['status'] !== 'hadir') {
                $_SESSION['error_message'] = 'Hanya status "hadir" bisa checkout.';
                debug_log("Invalid status: " . $existing['status']);
                $conn->rollback();
                $conn->autocommit(TRUE);
                header("Location: absensi.php", true, 303);
                exit();
            }

            if ($existing['waktu_keluar']) {
                $_SESSION['error_message'] = 'Sudah checkout pukul ' . date('H:i', strtotime($existing['waktu_keluar']));
                debug_log("Already checked out");
                $conn->rollback();
                $conn->autocommit(TRUE);
                header("Location: absensi.php", true, 303);
                exit();
            }

            // ✅ UPDATE WAKTU KELUAR
            $update_query = "UPDATE petugas_status SET waktu_keluar = NOW() WHERE id = ?";
            $stmt_update = $conn->prepare($update_query);
            $stmt_update->bind_param("i", $existing['id']);
            
            if (!$stmt_update->execute()) {
                throw new Exception("Checkout failed: " . $stmt_update->error);
            }
            
            $stmt_update->close();
            $_SESSION['success_message'] = "Checkout $petugas_name pukul " . date('H:i') . " berhasil!";
            debug_log("CHECKOUT SUCCESS: " . date('H:i'));

        } else {
            $_SESSION['error_message'] = 'Aksi tidak valid: ' . $action;
            debug_log("Invalid action");
            $conn->rollback();
            $conn->autocommit(TRUE);
            header("Location: absensi.php", true, 303);
            exit();
        }

        $conn->commit();
        $conn->autocommit(TRUE);
        debug_log("Transaction COMMITTED");

    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(TRUE);
        debug_log("ERROR: " . $e->getMessage());
        $_SESSION['error_message'] = "Gagal: " . $e->getMessage();
    }

    header("Location: absensi.php", true, 303);
    exit();
}

// ============================================
// FALLBACK ACCESS
// ============================================
debug_log("Fallback access - setting session data");
$_SESSION['absensi_data'] = fetchAbsensiData($conn);
header("Location: absensi.php", true, 303);
exit();
?>
