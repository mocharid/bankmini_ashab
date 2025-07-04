<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';
$petugas_id = $_SESSION['user_id'] ?? 0;

date_default_timezone_set('Asia/Jakarta');

// Check petugas block status
$query_block = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
$result_block = $conn->query($query_block);
if (!$result_block) {
    error_log("Failed to fetch block status: " . $conn->error);
    $is_blocked = 0;
} else {
    $is_blocked = $result_block->fetch_assoc()['is_blocked'] ?? 0;
}

// Get today's officer schedule
$query_schedule = "SELECT * FROM petugas_tugas WHERE tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
if (!$stmt_schedule) {
    error_log("Prepare failed for schedule query: " . $conn->error);
} else {
    $stmt_schedule->execute();
    $result_schedule = $stmt_schedule->get_result();
    $schedule = $result_schedule->fetch_assoc();
    $stmt_schedule->close();
}

// Get the names of scheduled officers for today
$petugas1_nama = $schedule ? htmlspecialchars(trim($schedule['petugas1_nama'])) : null;
$petugas2_nama = $schedule ? htmlspecialchars(trim($schedule['petugas2_nama'])) : null;

// Check for old attendance records
$query_check_old = "SELECT id FROM absensi WHERE tanggal < CURDATE() AND waktu_keluar IS NULL";
$stmt_check_old = $conn->prepare($query_check_old);
if (!$stmt_check_old) {
    error_log("Prepare failed for old attendance check: " . $conn->error);
} else {
    $stmt_check_old->execute();
    $stmt_check_old->get_result();
    $stmt_check_old->close();
}

// Get attendance records for both officers for today
$attendance_petugas1 = null;
$attendance_petugas2 = null;

$query_attendance1 = "SELECT * FROM absensi WHERE petugas_type = 'petugas1' AND tanggal = CURDATE()";
$stmt_attendance1 = $conn->prepare($query_attendance1);
if ($stmt_attendance1) {
    $stmt_attendance1->execute();
    $attendance_petugas1 = $stmt_attendance1->get_result()->fetch_assoc();
    $stmt_attendance1->close();
}

$query_attendance2 = "SELECT * FROM absensi WHERE petugas_type = 'petugas2' AND tanggal = CURDATE()";
$stmt_attendance2 = $conn->prepare($query_attendance2);
if ($stmt_attendance2) {
    $stmt_attendance2->execute();
    $attendance_petugas2 = $stmt_attendance2->get_result()->fetch_assoc();
    $stmt_attendance2->close();
}

// Handle attendance form submissions (only if not blocked)
$success_message = '';
if (!$is_blocked && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $petugas_type = strpos($action, 'petugas1') !== false ? 'petugas1' : 'petugas2';
    $status_field = $petugas_type == 'petugas1' ? 'petugas1_status' : 'petugas2_status';
    $petugas_name = $petugas_type == 'petugas1' ? ($petugas1_nama ?: 'Petugas 1') : ($petugas2_nama ?: 'Petugas 2');

    try {
        if (strpos($action, 'check_in') !== false) {
            $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, waktu_masuk, $status_field) 
                     VALUES (?, ?, CURDATE(), NOW(), 'hadir')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $petugas_id, $petugas_type);
            if ($stmt->execute()) {
                $success_message = "Absen masuk $petugas_name berhasil!";
            }
        } elseif (strpos($action, 'check_out') !== false) {
            $query = "UPDATE absensi SET waktu_keluar = NOW() WHERE petugas_type = ? AND tanggal = CURDATE()";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $petugas_type);
            if ($stmt->execute()) {
                $success_message = "Absen pulang $petugas_name berhasil!";
            }
        } elseif (strpos($action, 'absent') !== false) {
            $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, $status_field) 
                     VALUES (?, ?, CURDATE(), 'tidak_hadir')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $petugas_id, $petugas_type);
            if ($stmt->execute()) {
                $success_message = "$petugas_name ditandai tidak hadir!";
            }
        } elseif (strpos($action, 'sakit') !== false) {
            $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, $status_field) 
                     VALUES (?, ?, CURDATE(), 'sakit')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $petugas_id, $petugas_type);
            if ($stmt->execute()) {
                $success_message = "$petugas_name ditandai sakit!";
            }
        } elseif (strpos($action, 'izin') !== false) {
            $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, $status_field) 
                     VALUES (?, ?, CURDATE(), 'izin')";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $petugas_id, $petugas_type);
            if ($stmt->execute()) {
                $success_message = "$petugas_name ditandai izin!";
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Attendance action failed: " . $e->getMessage());
        $success_message = "Gagal memproses absensi. Silakan coba lagi.";
    }

    $_SESSION['success_message'] = $success_message;
    header("Location: absensi_petugas.php", true, 303);
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    exit();
}

// Retrieve and clear success message from session
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

// Function to convert date to Indonesian format
function tanggal_indonesia($tanggal) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $hari = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    $tanggal = strtotime($tanggal);
    $hari_ini = $hari[date('l', $tanggal)];
    $tanggal_format = date('d', $tanggal);
    $bulan_ini = $bulan[(int)date('n', $tanggal)];
    $tahun = date('Y', $tanggal);
    return "$hari_ini, $tanggal_format $bulan_ini $tahun";
}

// Check if all attendance is complete
$attendance_complete = false;
if ($schedule) {
    $petugas1_done = !$petugas1_nama || ($attendance_petugas1 && ($attendance_petugas1['petugas1_status'] != 'hadir' || $attendance_petugas1['waktu_keluar']));
    $petugas2_done = !$petugas2_nama || ($attendance_petugas2 && ($attendance_petugas2['petugas2_status'] != 'hadir' || $attendance_petugas2['waktu_keluar']));
    $attendance_complete = $petugas1_done && $petugas2_done;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Absensi Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --success-color: #2ecc71;
            --warning-color: #e67e22;
            --info-color: #3498db;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 0.625rem;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.25rem;
            width: 100%;
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 48rem;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .card-title {
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            text-align: center;
        }

        .no-petugas, .thank-you-message {
            font-size: clamp(1.4rem, 3.5vw, 1.8rem);
            font-weight: 600;
            text-align: center;
            padding: 2rem 0;
            position: relative;
            letter-spacing: 0.03em;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: fadeSlideIn 1s cubic-bezier(0.4, 0, 0.2, 1) forwards, glowPulse 2s ease-in-out infinite;
        }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes glowPulse {
            0%, 100% { text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); }
            50% { text-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        }

        .attendance-container {
            background: rgba(59, 130, 246, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
            align-items: stretch;
            flex-wrap: wrap;
        }

        .petugas-section {
            flex: 1;
            padding: 1rem;
            border: 1px solid #eee;
            border-radius: 0.5rem;
            text-align: center;
            transition: var(--transition);
            min-width: 200px;
            max-width: 300px;
        }

        .petugas-section:hover {
            box-shadow: var(--shadow-sm);
            transform: translateY(-2px);
        }

        .petugas-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
        }

        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            margin-right: 0.75rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .petugas-title {
            font-size: clamp(1rem, 2.2vw, 1.1rem);
            font-weight: 500;
            color: var(--primary-dark);
        }

        .petugas-details {
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .status-text {
            font-size: clamp(0.9rem, 2vw, 0.95rem);
            font-weight: 500;
            color: var(--text-primary);
            margin: 0.5rem 0;
        }

        .status-text .status {
            font-weight: 600;
        }

        .status-text .hadir { color: var(--success-color); }
        .status-text .tidak_hadir { color: var(--danger-color); }
        .status-text .sakit { color: var(--warning-color); }
        .status-text .izin { color: var(--info-color); }

        .dropdown-container {
            position: relative;
            margin-top: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .dropdown {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #ddd;
            background: white;
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
            appearance: none;
            background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%2212%22%20viewBox%3D%220%200%2012%2012%22%3E%3Cpath%20fill%3D%22%23666%22%20d%3D%22M1.5%204.5l4.5%204.5%204.5-4.5z%22%2F%3E%3C%2Fsvg%3E');
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .dropdown:disabled {
            background-color: #eee;
            cursor: not-allowed;
            color: var(--text-secondary);
            box-shadow: none;
        }

        .dropdown:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .action-button {
            display: flex;
            justify-content: center;
            margin-top: 0.5rem;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            box-shadow: 0 4px 8px rgba(30, 58, 138, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(30, 58, 138, 0.25);
        }

        .btn:active {
            transform: translateY(1px);
            box-shadow: 0 2px 5px rgba(30, 58, 138, 0.2);
        }

        .btn-checkout {
            background: var(--accent-color);
            box-shadow: 0 4px 8px rgba(245, 158, 11, 0.2);
        }

        .btn-checkout:hover {
            background: #d97706;
            box-shadow: 0 6px 12px rgba(245, 158, 11, 0.25);
        }

        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .instruction-text {
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            color: var(--text-secondary);
            margin-top: 1rem;
            font-style: italic;
            text-align: center;
        }

        /* Modal Styling */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            backdrop-filter: blur(5px);
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .confirm-modal {
            position: relative;
            text-align: center;
            width: clamp(320px, 85vw, 420px);
            border-radius: 18px;
            padding: clamp(25px, 5vw, 35px);
            box-shadow: var(--shadow-md);
            transform: scale(0.9);
            opacity: 0;
            animation: popInModal 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
            margin: 0 15px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .success-modal::before, .confirm-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.9); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .confirm-icon {
            font-size: clamp(3.5rem, 8vw, 4.5rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.15); }
            80% { transform: scale(0.95); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .confirm-modal h3 {
            color: white;
            margin: 0 0 15px;
            font-size: clamp(1.3rem, 3vw, 1.5rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .confirm-modal p {
            color: white;
            font-size: clamp(0.9rem, 2.3vw, 1rem);
            margin: 0 0 20px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
        }

        .btn-cancel {
            background: var(--danger-color);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.2);
        }

        .btn-cancel:hover {
            background: #c0392b;
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.25);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 0.75rem 1rem;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 0.75rem;
            }

            .card {
                padding: 1rem;
            }

            .card-title {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .no-petugas, .thank-you-message {
                font-size: clamp(1.3rem, 3.5vw, 1.6rem);
                padding: 1.5rem 0;
            }

            .attendance-container {
                flex-direction: column;
                align-items: center;
            }

            .petugas-section {
                max-width: 100%;
            }

            .petugas-title {
                font-size: clamp(0.95rem, 2vw, 1rem);
            }

            .petugas-details, .status-text, .instruction-text {
                font-size: clamp(0.8rem, 1.7vw, 0.85rem);
            }

            .avatar {
                width: 40px;
                height: 40px;
                font-size: clamp(1rem, 2.2vw, 1.2rem);
            }

            .success-modal, .confirm-modal {
                width: clamp(300px, 90vw, 380px);
                padding: clamp(20px, 5vw, 30px);
            }

            .success-icon, .confirm-icon {
                font-size: clamp(3.2rem, 7vw, 4rem);
            }

            .success-modal h3, .confirm-modal h3 {
                font-size: clamp(1.2rem, 2.8vw, 1.4rem);
            }

            .success-modal p, .confirm-modal p {
                font-size: clamp(0.85rem, 2.2vw, 0.95rem);
            }

            .btn {
                padding: 8px 16px;
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 0.5rem;
            }

            .card-title {
                font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            }

            .no-petugas, .thank-you-message {
                font-size: clamp(1.2rem, 3vw, 1.5rem);
                padding: 1.2rem 0;
            }

            .petugas-title {
                font-size: clamp(0.9rem, 1.8vw, 0.95rem);
            }

            .petugas-details, .status-text, .instruction-text {
                font-size: clamp(0.75rem, 1.6vw, 0.8rem);
            }

            .avatar {
                width: 36px;
                height: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            .success-modal, .confirm-modal {
                width: clamp(280px, 92vw, 350px);
                padding: clamp(18px, 4vw, 25px);
                border-radius: 16px;
            }

            .success-icon, .confirm-icon {
                font-size: clamp(2.8rem, 6.5vw, 3.5rem);
                margin-bottom: 15px;
            }

            .success-modal h3, .confirm-modal h3 {
                font-size: clamp(1.1rem, 2.7vw, 1.3rem);
                margin-bottom: 12px;
            }

            .success-modal p, .confirm-modal p {
                font-size: clamp(0.8rem, 2.1vw, 0.9rem);
                margin-bottom: 18px;
            }

            .btn {
                padding: 8px 14px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }
        }
    </style>
    <script>
        // Prevent zooming and double-tap issues
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });

        // Modal and attendance handling
        document.addEventListener('DOMContentLoaded', function() {
            const notificationModal = document.getElementById('notificationModal');
            const confirmModal = document.getElementById('confirmModal');
            const confirmBtn = document.getElementById('confirmBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            let currentAction = '';
            let currentPetugasName = '';
            let isSubmitting = false;

            // Handle notification modal (success)
            if (notificationModal) {
                setTimeout(() => {
                    notificationModal.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    setTimeout(() => {
                        notificationModal.remove();
                    }, 400);
                }, 3000);

                notificationModal.addEventListener('click', function(event) {
                    if (event.target.classList.contains('success-overlay')) {
                        notificationModal.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                        setTimeout(() => {
                            notificationModal.remove();
                        }, 400);
                    }
                });
            }

            // Show confirmation modal
            function showConfirmModal(action, title, petugasName) {
                if (isSubmitting) return;
                isSubmitting = true;
                currentAction = action;
                currentPetugasName = petugasName;
                document.getElementById('confirmTitle').textContent = title;
                document.getElementById('confirmMessage').textContent = `Apakah Anda yakin ingin ${title.toLowerCase()} untuk ${petugasName}?`;
                confirmModal.style.display = 'flex';
                confirmModal.style.opacity = '0';
                confirmModal.style.animation = 'fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            }

            // Close confirmation modal
            function closeConfirmModal() {
                confirmModal.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                setTimeout(() => {
                    confirmModal.style.display = 'none';
                    confirmModal.style.animation = '';
                    isSubmitting = false;
                }, 400);
            }

            // Handle confirm button
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    closeConfirmModal();
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'action';
                    input.value = currentAction;
                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                });
            }

            // Handle cancel button
            if (cancelBtn) {
                cancelBtn.addEventListener('click', closeConfirmModal);
            }

            // Close confirmation modal on click outside
            if (confirmModal) {
                confirmModal.addEventListener('click', function(event) {
                    if (event.target.classList.contains('success-overlay')) {
                        closeConfirmModal();
                    }
                });
            }

            // Handle dropdown changes
            document.querySelectorAll('.dropdown').forEach(dropdown => {
                dropdown.addEventListener('change', function() {
                    const action = this.value;
                    const title = this.options[this.selectedIndex].text;
                    const petugasName = this.getAttribute('data-petugas');
                    if (action) {
                        showConfirmModal(action, title, petugasName);
                        this.value = ''; // Reset dropdown after selection
                    }
                });
            });

            // Handle checkout buttons
            document.querySelectorAll('.btn-checkout').forEach(button => {
                button.addEventListener('click', function() {
                    const action = this.getAttribute('data-action');
                    const title = this.getAttribute('data-title');
                    const petugasName = this.getAttribute('data-petugas');
                    showConfirmModal(action, title, petugasName);
                });
            });
        });
    </script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Absensi Petugas</h2>
            <p class="instruction-text"><?= tanggal_indonesia(date('Y-m-d')) ?></p>

            <?php if (!$schedule): ?>
                <p class="no-petugas">Belum ada petugas yang diatur</p>
            <?php elseif ($is_blocked): ?>
                <div class="attendance-container">
                    <p class="status-text" style="color: var(--danger-color); text-align: center; width: 100%;">
                        Absensi dinonaktifkan karena aktivitas petugas diblokir oleh admin.
                    </p>
                </div>
            <?php elseif ($attendance_complete): ?>
                <p class="thank-you-message">Terima kasih atas pekerjaan Anda hari ini!</p>
            <?php else: ?>
                <div class="attendance-container">
                    <!-- Petugas 1 -->
                    <?php if ($petugas1_nama): ?>
                        <div class="petugas-section">
                            <div class="petugas-header">
                                <div class="avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <h3 class="petugas-title"><?= $petugas1_nama ?></h3>
                            </div>
                            <p class="status-text">
                                Status: 
                                <span class="status <?php 
                                    if ($attendance_petugas1) {
                                        echo $attendance_petugas1['petugas1_status'] == 'hadir' ? 'hadir' : 
                                             ($attendance_petugas1['petugas1_status'] == 'tidak_hadir' ? 'tidak_hadir' : 
                                             ($attendance_petugas1['petugas1_status'] == 'sakit' ? 'sakit' : 'izin'));
                                    }
                                ?>">
                                    <?php 
                                        if ($attendance_petugas1) {
                                            if ($attendance_petugas1['petugas1_status'] == 'hadir') {
                                                echo !$attendance_petugas1['waktu_keluar'] ? 'Sedang Bertugas' : 'Hadir';
                                            } else {
                                                echo ucfirst(str_replace('_', ' ', $attendance_petugas1['petugas1_status']));
                                            }
                                        } else {
                                            echo 'Belum Absen';
                                        }
                                    ?>
                                </span>
                            </p>
                            <?php if ($attendance_petugas1 && $attendance_petugas1['petugas1_status'] == 'hadir'): ?>
                                <p class="petugas-details">
                                    Jam Masuk: <?= $attendance_petugas1['waktu_masuk'] ? date('H:i', strtotime($attendance_petugas1['waktu_masuk'])) : '-' ?>
                                    <br>
                                    Jam Keluar: <?= $attendance_petugas1['waktu_keluar'] ? date('H:i', strtotime($attendance_petugas1['waktu_keluar'])) : '-' ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!$attendance_petugas1): ?>
                                <div class="dropdown-container">
                                    <select class="dropdown" data-petugas="<?= $petugas1_nama ?: 'Petugas 1' ?>">
                                        <option value="">Pilih Aksi</option>
                                        <option value="check_in_petugas1">Absen Masuk</option>
                                        <option value="absent_petugas1">Tandai Tidak Hadir</option>
                                        <option value="sakit_petugas1">Tandai Sakit</option>
                                        <option value="izin_petugas1">Tandai Izin</option>
                                    </select>
                                </div>
                            <?php elseif ($attendance_petugas1['petugas1_status'] == 'hadir' && !$attendance_petugas1['waktu_keluar']): ?>
                                <div class="action-button">
                                    <button class="btn btn-checkout" data-action="check_out_petugas1" data-title="Absen Pulang" data-petugas="<?= $petugas1_nama ?: 'Petugas 1' ?>">Absen Pulang</button>
                                </div>
                            <?php else: ?>
                                <div class="action-button">
                                    <button class="btn" disabled>Selesai</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Petugas 2 -->
                    <?php if ($petugas2_nama): ?>
                        <div class="petugas-section">
                            <div class="petugas-header">
                                <div class="avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <h3 class="petugas-title"><?= $petugas2_nama ?></h3>
                            </div>
                            <p class="status-text">
                                Status: 
                                <span class="status <?php 
                                    if ($attendance_petugas2) {
                                        echo $attendance_petugas2['petugas2_status'] == 'hadir' ? 'hadir' : 
                                             ($attendance_petugas2['petugas2_status'] == 'tidak_hadir' ? 'tidak_hadir' : 
                                             ($attendance_petugas2['petugas2_status'] == 'sakit' ? 'sakit' : 'izin'));
                                    }
                                ?>">
                                    <?php 
                                        if ($attendance_petugas2) {
                                            if ($attendance_petugas2['petugas2_status'] == 'hadir') {
                                                echo !$attendance_petugas2['waktu_keluar'] ? 'Sedang Bertugas' : 'Hadir';
                                            } else {
                                                echo ucfirst(str_replace('_', ' ', $attendance_petugas2['petugas2_status']));
                                            }
                                        } else {
                                            echo 'Belum Absen';
                                        }
                                    ?>
                                </span>
                            </p>
                            <?php if ($attendance_petugas2 && $attendance_petugas2['petugas2_status'] == 'hadir'): ?>
                                <p class="petugas-details">
                                    Jam Masuk: <?= $attendance_petugas2['waktu_masuk'] ? date('H:i', strtotime($attendance_petugas2['waktu_masuk'])) : '-' ?>
                                    <br>
                                    Jam Keluar: <?= $attendance_petugas2['waktu_keluar'] ? date('H:i', strtotime($attendance_petugas2['waktu_keluar'])) : '-' ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!$attendance_petugas2): ?>
                                <div class="dropdown-container">
                                    <select class="dropdown" data-petugas="<?= $petugas2_nama ?: 'Petugas 2' ?>">
                                        <option value="">Pilih Aksi</option>
                                        <option value="check_in_petugas2">Absen Masuk</option>
                                        <option value="absent_petugas2">Tandai Tidak Hadir</option>
                                        <option value="sakit_petugas2">Tandai Sakit</option>
                                        <option value="izin_petugas2">Tandai Izin</option>
                                    </select>
                                </div>
                            <?php elseif ($attendance_petugas2['petugas2_status'] == 'hadir' && !$attendance_petugas2['waktu_keluar']): ?>
                                <div class="action-button">
                                    <button class="btn btn-checkout" data-action="check_out_petugas2" data-title="Absen Pulang" data-petugas="<?= $petugas2_nama ?: 'Petugas 2' ?>">Absen Pulang</button>
                                </div>
                            <?php else: ?>
                                <div class="action-button">
                                    <button class="btn" disabled>Selesai</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="instruction-text">
                    Pilih aksi absensi untuk merekam kehadiran petugas hari ini.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="success-overlay" id="confirmModal" style="display: none;">
        <div class="confirm-modal">
            <div class="confirm-icon">
                <i class="fas fa-question-circle"></i>
            </div>
            <h3 id="confirmTitle">Konfirmasi</h3>
            <p id="confirmMessage"></p>
            <div class="modal-buttons">
                <button type="button" class="btn" id="confirmBtn">Konfirmasi</button>
                <button type="button" class="btn btn-cancel" id="cancelBtn">Batal</button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <?php if ($success_message): ?>
        <div class="success-overlay" id="notificationModal">
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>Berhasil</h3>
                <p><?= htmlspecialchars($success_message) ?></p>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>