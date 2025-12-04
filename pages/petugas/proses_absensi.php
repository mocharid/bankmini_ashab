<?php
// proses_absensi.php (Logic)
require_once '../../includes/auth.php';
require_once '../../includes/session_validator.php'; 
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

// Function to fetch absensi data
function fetchAbsensiData($conn) {
    $data = [];
    
    // Check block status
    $query_block = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
    $result_block = $conn->query($query_block);
    if (!$result_block) {
        error_log("Failed to fetch block status: " . $conn->error);
        $data['is_blocked'] = 0;
    } else {
        $data['is_blocked'] = $result_block->fetch_assoc()['is_blocked'] ?? 0;
    }
    
    // Get schedule for today
    $query_schedule = "SELECT pt.*, u1.nama AS petugas1_nama, u2.nama AS petugas2_nama 
                       FROM petugas_tugas pt 
                       LEFT JOIN users u1 ON pt.petugas1_id = u1.id
                       LEFT JOIN users u2 ON pt.petugas2_id = u2.id
                       WHERE pt.tanggal = CURDATE()";
    $stmt_schedule = $conn->prepare($query_schedule);
    if (!$stmt_schedule) {
        error_log("Prepare failed for schedule query: " . $conn->error);
    } else {
        $stmt_schedule->execute();
        $result_schedule = $stmt_schedule->get_result();
        $data['schedule'] = $result_schedule->fetch_assoc();
        $stmt_schedule->close();
    }
    
    $data['petugas1_nama'] = $data['schedule'] ? htmlspecialchars(trim($data['schedule']['petugas1_nama'])) : null;
    $data['petugas2_nama'] = $data['schedule'] ? htmlspecialchars(trim($data['schedule']['petugas2_nama'])) : null;
    
    $data['attendance_petugas1'] = null;
    $data['attendance_petugas2'] = null;
    
    // Get attendance records
    if ($data['schedule']) {
        $query_attendance1 = "SELECT * FROM absensi WHERE petugas_type = 'petugas1' AND tanggal = CURDATE() AND user_id = ?";
        $stmt_attendance1 = $conn->prepare($query_attendance1);
        if ($stmt_attendance1) {
            $stmt_attendance1->bind_param("i", $data['schedule']['petugas1_id']);
            $stmt_attendance1->execute();
            $data['attendance_petugas1'] = $stmt_attendance1->get_result()->fetch_assoc();
            $stmt_attendance1->close();
        }

        $query_attendance2 = "SELECT * FROM absensi WHERE petugas_type = 'petugas2' AND tanggal = CURDATE() AND user_id = ?";
        $stmt_attendance2 = $conn->prepare($query_attendance2);
        if ($stmt_attendance2) {
            $stmt_attendance2->bind_param("i", $data['schedule']['petugas2_id']);
            $stmt_attendance2->execute();
            $data['attendance_petugas2'] = $stmt_attendance2->get_result()->fetch_assoc();
            $stmt_attendance2->close();
        }
    }
    
    // Check if attendance is complete
    $data['attendance_complete'] = false;
    if ($data['schedule']) {
        $petugas1_done = !$data['petugas1_nama'] || (
            $data['attendance_petugas1'] && (
                ($data['attendance_petugas1']['petugas1_status'] == 'hadir' && $data['attendance_petugas1']['waktu_keluar']) ||
                ($data['attendance_petugas1']['petugas1_status'] != 'hadir')
            )
        );
        $petugas2_done = !$data['petugas2_nama'] || (
            $data['attendance_petugas2'] && (
                ($data['attendance_petugas2']['petugas2_status'] == 'hadir' && $data['attendance_petugas2']['waktu_keluar']) ||
                ($data['attendance_petugas2']['petugas2_status'] != 'hadir')
            )
        );
        $data['attendance_complete'] = $petugas1_done && $petugas2_done;
    }
    
    return $data;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $page_data = fetchAbsensiData($conn);
    
    if ($page_data['is_blocked']) {
        $_SESSION['error_message'] = 'Absensi sedang diblokir oleh administrator';
        header("Location: absensi.php", true, 303);
        exit();
    }
    
    $action = $_POST['action'];
    $petugas_type = $_POST['petugas_type'] ?? '';
    $schedule = $page_data['schedule'];
    
    if (!$schedule) {
        $_SESSION['error_message'] = 'Tidak ada jadwal petugas untuk hari ini';
        header("Location: absensi.php", true, 303);
        exit();
    }
    
    $status_field = $petugas_type == 'petugas1' ? 'petugas1_status' : 'petugas2_status';
    $petugas_name = $petugas_type == 'petugas1' ? ($page_data['petugas1_nama'] ?: 'Petugas 1') : ($page_data['petugas2_nama'] ?: 'Petugas 2');
    $current_petugas_id = $petugas_type == 'petugas1' ? $schedule['petugas1_id'] : $schedule['petugas2_id'];

    try {
        if ($action == 'hadir') {
            $check_query = "SELECT id FROM absensi WHERE petugas_type = ? AND tanggal = CURDATE() AND user_id = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $petugas_type, $current_petugas_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($exists) {
                $_SESSION['error_message'] = "Absensi untuk $petugas_name sudah tercatat hari ini!";
            } else {
                $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, waktu_masuk, $status_field) 
                         VALUES (?, ?, CURDATE(), NOW(), 'hadir')";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("is", $current_petugas_id, $petugas_type);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Absen masuk $petugas_name berhasil!";
                }
                $stmt->close();
            }
        } elseif (in_array($action, ['tidak_hadir', 'sakit', 'izin'])) {
            $check_query = "SELECT id FROM absensi WHERE petugas_type = ? AND tanggal = CURDATE() AND user_id = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $petugas_type, $current_petugas_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($exists) {
                $_SESSION['error_message'] = "Absensi untuk $petugas_name sudah tercatat hari ini!";
            } else {
                $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, $status_field) VALUES (?, ?, CURDATE(), ?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("iss", $current_petugas_id, $petugas_type, $action);
                if ($stmt->execute()) {
                    $status_text = $action == 'tidak_hadir' ? 'Tidak Hadir' : ucfirst($action);
                    $_SESSION['success_message'] = "$petugas_name ditandai $status_text!";
                }
                $stmt->close();
            }
        } elseif ($action == 'check_out') {
            $query = "UPDATE absensi SET waktu_keluar = NOW() WHERE petugas_type = ? AND tanggal = CURDATE() AND user_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $petugas_type, $current_petugas_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Absen pulang $petugas_name berhasil!";
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Attendance action failed: " . $e->getMessage());
        $_SESSION['error_message'] = "Gagal memproses absensi. Silakan coba lagi.";
    }

    header("Location: absensi.php", true, 303);
    exit();
}

// If directly accessed, fetch data and store in session
if (basename($_SERVER['PHP_SELF']) == 'proses_absensi.php' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['absensi_data'] = fetchAbsensiData($conn);
    header("Location: absensi.php", true, 303);
    exit();
}
?>
