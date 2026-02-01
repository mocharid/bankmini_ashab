<?php
/**
 * Absensi Petugas - Adaptive Path Version (Normalized DB + IN/OUT)
 * File: pages/petugas/absensi.php
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

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';

// ============================================
// CHECK AUTHORIZATION
// ============================================
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ' . PROJECT_ROOT . '/login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
date_default_timezone_set('Asia/Jakarta');

// ============================================
// SESSION MESSAGES
// ============================================
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// ============================================
// HELPER: TANGGAL INDONESIA
// ============================================
function tanggal_indonesia($tanggal)
{
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    $hari = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    $ts = strtotime($tanggal);
    return $hari[date('l', $ts)] . ', ' . date('d', $ts) . ' ' .
        $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

// ============================================
// BLOKIR (OPSIONAL) – DEFAULT FALSE
// ============================================
$is_blocked = false;

// ============================================
// FETCH SCHEDULE (petugas_shift + petugas_profiles)
// ============================================
$schedule = null;
$petugas1_nama = null;
$petugas2_nama = null;

try {
    $query_schedule = "SELECT ps.*,
                       COALESCE(pp1.petugas1_nama, pp1.petugas2_nama) AS petugas1_nama,
                       COALESCE(pp2.petugas2_nama, pp2.petugas1_nama) AS petugas2_nama
                       FROM petugas_shift ps
                       LEFT JOIN petugas_profiles pp1 ON ps.petugas1_id = pp1.user_id
                       LEFT JOIN petugas_profiles pp2 ON ps.petugas2_id = pp2.user_id
                       WHERE ps.tanggal = CURDATE()";
    $stmt_schedule = $conn->prepare($query_schedule);
    $stmt_schedule->execute();
    $result_schedule = $stmt_schedule->get_result();
    $schedule = $result_schedule->fetch_assoc();
    $stmt_schedule->close();

    if ($schedule) {
        $petugas1_nama = $schedule['petugas1_nama'] ?: null;
        $petugas2_nama = $schedule['petugas2_nama'] ?: null;
    }
} catch (mysqli_sql_exception $e) {
    error_log("Absensi Schedule Error: " . $e->getMessage());
}

// ============================================
// FETCH ATTENDANCE (petugas_status)
// ============================================
$attendance_petugas1 = null;
$attendance_petugas2 = null;
$attendance_complete = false;

if ($schedule) {
    try {
        // Petugas 1
        if ($petugas1_nama) {
            $query_attendance1 = "SELECT * FROM petugas_status
                                  WHERE petugas_shift_id = ?
                                  AND petugas_type = 'petugas1'";
            $stmt_attendance1 = $conn->prepare($query_attendance1);
            $stmt_attendance1->bind_param("i", $schedule['id']);
            $stmt_attendance1->execute();
            $attendance_petugas1 = $stmt_attendance1->get_result()->fetch_assoc();
            $stmt_attendance1->close();
        }

        // Petugas 2
        if ($petugas2_nama) {
            $query_attendance2 = "SELECT * FROM petugas_status
                                  WHERE petugas_shift_id = ?
                                  AND petugas_type = 'petugas2'";
            $stmt_attendance2 = $conn->prepare($query_attendance2);
            $stmt_attendance2->bind_param("i", $schedule['id']);
            $stmt_attendance2->execute();
            $attendance_petugas2 = $stmt_attendance2->get_result()->fetch_assoc();
            $stmt_attendance2->close();
        }

        // Lengkap jika:
        // - Hadir & sudah punya waktu_keluar, atau
        // - Status != hadir (alfa/sakit/izin), atau
        // - Tidak ada nama petugas (null)
        $petugas1_done = !$petugas1_nama || (
            $attendance_petugas1 && (
                ($attendance_petugas1['status'] === 'hadir' && $attendance_petugas1['waktu_keluar']) ||
                $attendance_petugas1['status'] !== 'hadir'
            )
        );
        $petugas2_done = !$petugas2_nama || (
            $attendance_petugas2 && (
                ($attendance_petugas2['status'] === 'hadir' && $attendance_petugas2['waktu_keluar']) ||
                $attendance_petugas2['status'] !== 'hadir'
            )
        );
        $attendance_complete = $petugas1_done && $petugas2_done;
    } catch (mysqli_sql_exception $e) {
        error_log("Absensi Attendance Error: " . $e->getMessage());
    }
}

// ============================================
// BASE URL
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script));
    $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}
define('ASSETS_URL', BASE_URL . '/assets');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta name="format-detection" content="telephone=no">
    <title>Absensi | KASDIG</title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-50: #f9fafb;
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
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR OVERLAY FIX */
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
            padding: 2rem;
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
        .staff-card,
        .alert-card {
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
            justify-content: space-between;
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
            flex-shrink: 0;
        }

        .card-header-text {
            flex: 1;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Staff Card Specifics */
        .staff-card {
            display: flex;
            flex-direction: column;
            margin-bottom: 0px;
            /* Reset for grid */
        }

        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .card-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .card-badge.active {
            background-color: #dcfce7;
            color: #166534;
        }

        .card-badge.done {
            background-color: #e0e7ff;
            color: #3730a3;
        }

        .card-badge.pending {
            background-color: #fef9c3;
            color: #854d0e;
        }

        .card-badge.absent {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .profile-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .profile-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            font-size: 1.5rem;
        }

        .profile-info h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .profile-info p {
            font-size: 0.875rem;
            color: var(--gray-500);
        }

        .time-display {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .time-box {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: var(--radius);
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        .time-box-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .time-box-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .status-label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 500;
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        .status-options {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .status-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .status-option input[type="radio"] {
            width: 1.125rem;
            height: 1.125rem;
            margin: 0;
            cursor: pointer;
            accent-color: var(--gray-800);
        }

        .status-option-label {
            font-size: 0.95rem;
            color: var(--gray-700);
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
            /* width: 100%; Removed to allow auto width */
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-disabled {
            background: var(--gray-300);
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Alert Cards */
        .alert-card {
            text-align: center;
            padding: 3rem 2rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .alert-icon-wrapper {
            width: 64px;
            height: 64px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            color: var(--gray-500);
        }

        .alert-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.75rem;
        }

        .alert-message {
            color: var(--gray-600);
            font-size: 1rem;
            line-height: 1.6;
        }

        /* Specific Alert Styles */
        .alert-card.blocked .alert-icon-wrapper {
            color: #ef4444;
            background: #fee2e2;
        }

        .alert-card.success .alert-icon-wrapper {
            color: #16a34a;
            background: #dcfce7;
        }

        .alert-card.blocked .alert-title {
            color: #991b1b;
        }

        .alert-card.success .alert-title {
            color: #166534;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-section {
                padding: 1.25rem;
                margin: -1rem -1rem 1.5rem -1rem;
            }

            .attendance-grid {
                grid-template-columns: 1fr;
            }
        }

        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Absensi Petugas</h1>
                <p class="page-subtitle"><?= tanggal_indonesia(date('Y-m-d')) ?></p>
            </div>
        </div>

        <!-- Content -->
        <?php if ($is_blocked): ?>
            <div class="alert-card blocked">
                <div class="alert-icon-wrapper">
                    <i class="fas fa-ban"></i>
                </div>
                <h2 class="alert-title">Absensi Dinonaktifkan</h2>
                <p class="alert-message">Aktivitas absensi petugas sementara diblokir oleh administrator.</p>
            </div>
        <?php elseif (!$schedule): ?>
            <div class="alert-card">
                <div class="alert-icon-wrapper">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <h2 class="alert-title">Belum Ada Jadwal</h2>
                <p class="alert-message">Tidak ada penugasan petugas untuk hari ini. Silakan hubungi administrator.</p>
            </div>
        <?php elseif ($attendance_complete): ?>
            <div class="alert-card success">
                <div class="alert-icon-wrapper">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 class="alert-title">Absensi Selesai</h2>
                <p class="alert-message">Terima kasih! Semua absensi petugas untuk hari ini telah lengkap.</p>
            </div>
        <?php else: ?>
            <div class="attendance-grid">
                <!-- PETUGAS 1 -->
                <?php if ($petugas1_nama):
                    // Determine badge class and text
                    $badge_class = 'pending';
                    $badge_text = 'Belum Absen';
                    if ($attendance_petugas1) {
                        if ($attendance_petugas1['status'] === 'hadir' && !$attendance_petugas1['waktu_keluar']) {
                            $badge_class = 'active';
                            $badge_text = 'Bertugas';
                        } elseif ($attendance_petugas1['status'] === 'hadir' && $attendance_petugas1['waktu_keluar']) {
                            $badge_class = 'done';
                            $badge_text = 'Selesai';
                        } else {
                            $badge_class = 'absent';
                            $badge_text = ucfirst(str_replace('_', ' ', $attendance_petugas1['status']));
                        }
                    }
                    ?>
                    <div class="staff-card">
                        <div class="card-header">
                            <div class="card-header-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="card-header-text">
                                <h3>Petugas 1</h3>
                            </div>
                            <span class="card-badge <?= $badge_class ?>"><?= $badge_text ?></span>
                        </div>

                        <div class="card-body">
                            <div class="profile-section">
                                <div class="profile-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="profile-info">
                                    <h4><?= htmlspecialchars($petugas1_nama) ?></h4>
                                    <p>Petugas Bank Mini</p>
                                </div>
                            </div>

                            <div class="time-display">
                                <div class="time-box">
                                    <div class="time-box-label">Masuk</div>
                                    <div class="time-box-value">
                                        <?= $attendance_petugas1 && $attendance_petugas1['waktu_masuk']
                                            ? date('H:i', strtotime($attendance_petugas1['waktu_masuk']))
                                            : '--:--' ?>
                                    </div>
                                </div>
                                <div class="time-box">
                                    <div class="time-box-label">Keluar</div>
                                    <div class="time-box-value">
                                        <?= $attendance_petugas1 && $attendance_petugas1['waktu_keluar']
                                            ? date('H:i', strtotime($attendance_petugas1['waktu_keluar']))
                                            : '--:--' ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$attendance_petugas1): ?>
                                <form method="POST" action="proses_absensi.php"
                                    onsubmit="return confirmSubmit(event, '<?= addslashes($petugas1_nama) ?>')">
                                    <input type="hidden" name="petugas_type" value="petugas1">
                                    <p class="status-label">Pilih status kehadiran:</p>
                                    <div class="status-options">
                                        <label class="status-option">
                                            <input type="radio" name="action" value="hadir" class="hadir" required>
                                            <span class="status-option-label">Hadir</span>
                                        </label>
                                        <label class="status-option">
                                            <input type="radio" name="action" value="tidak_hadir" class="alfa">
                                            <span class="status-option-label">Alfa</span>
                                        </label>
                                        <label class="status-option">
                                            <input type="radio" name="action" value="izin" class="izin">
                                            <span class="status-option-label">Izin</span>
                                        </label>
                                        <label class="status-option">
                                            <input type="radio" name="action" value="sakit" class="sakit">
                                            <span class="status-option-label">Sakit</span>
                                        </label>
                                    </div>
                                    <button type="submit" class="btn">
                                        Kirim Absensi
                                    </button>
                                </form>
                            <?php elseif (
                                $attendance_petugas1['status'] === 'hadir' &&
                                !$attendance_petugas1['waktu_keluar']
                            ): ?>
                                <button type="button" class="btn"
                                    onclick="confirmCheckout('<?= addslashes($petugas1_nama) ?>','petugas1')">
                                    Absen Pulang
                                </button>
                            <?php else: ?>
                                <button class="btn btn-disabled" disabled>
                                    Absensi Selesai
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- PETUGAS 2 -->
                <?php if ($petugas2_nama):
                    // Determine badge class and text
                    $badge_class2 = 'pending';
                    $badge_text2 = 'Belum Absen';
                    if ($attendance_petugas2) {
                        if ($attendance_petugas2['status'] === 'hadir' && !$attendance_petugas2['waktu_keluar']) {
                            $badge_class2 = 'active';
                            $badge_text2 = 'Bertugas';
                        } elseif ($attendance_petugas2['status'] === 'hadir' && $attendance_petugas2['waktu_keluar']) {
                            $badge_class2 = 'done';
                            $badge_text2 = 'Selesai';
                        } else {
                            $badge_class2 = 'absent';
                            $badge_text2 = ucfirst(str_replace('_', ' ', $attendance_petugas2['status']));
                        }
                    }
                    ?>
                    <div class="staff-card">
                        <div class="card-header">
                            <div class="card-header-icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="card-header-text">
                                <h3>Petugas 2</h3>
                            </div>
                            <span class="card-badge <?= $badge_class2 ?>"><?= $badge_text2 ?></span>
                        </div>

                        <div class="card-body">
                            <div class="profile-section">
                                <div class="profile-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="profile-info">
                                    <h4><?= htmlspecialchars($petugas2_nama) ?></h4>
                                    <p>Petugas Bank Mini</p>
                                </div>
                            </div>

                            <div class="time-display">
                                <div class="time-box">
                                    <div class="time-box-label">Masuk</div>
                                    <div class="time-box-value">
                                        <?= $attendance_petugas2 && $attendance_petugas2['waktu_masuk']
                                            ? date('H:i', strtotime($attendance_petugas2['waktu_masuk']))
                                            : '--:--' ?>
                                    </div>
                                </div>
                                <div class="time-box">
                                    <div class="time-box-label">Keluar</div>
                                    <div class="time-box-value">
                                        <?= $attendance_petugas2 && $attendance_petugas2['waktu_keluar']
                                            ? date('H:i', strtotime($attendance_petugas2['waktu_keluar']))
                                            : '--:--' ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$attendance_petugas2): ?>
                                <form method="POST" action="proses_absensi.php"
                                    onsubmit="return confirmSubmit(event, '<?= addslashes($petugas2_nama) ?>')">
                                    <input type="hidden" name="petugas_type" value="petugas2">
                                    <p class="status-label">Pilih status kehadiran:</p>
                                    <div class="status-options">
                                        <label class="status-option">
                                            <input type="radio" name="action" value="hadir" class="hadir" required>
                                            <span class="status-option-label">Hadir</span>
                                        </label>
                                        <label class="status-option">
                                            <input type="radio" name="action" value="tidak_hadir" class="alfa">
                                            <span class="status-option-label">Alfa</span>
                                        </label>
                                        <label class="status-option">
                                            <input type="radio" name="action" value="izin" class="izin">
                                            <span class="status-option-label">Izin</span>
                                        </label>
                                        <label class="status-option">
                                            <input type="radio" name="action" value="sakit" class="sakit">
                                            <span class="status-option-label">Sakit</span>
                                        </label>
                                    </div>
                                    <button type="submit" class="btn">
                                        Kirim Absensi
                                    </button>
                                </form>
                            <?php elseif (
                                $attendance_petugas2['status'] === 'hadir' &&
                                !$attendance_petugas2['waktu_keluar']
                            ): ?>
                                <button type="button" class="btn"
                                    onclick="confirmCheckout('<?= addslashes($petugas2_nama) ?>','petugas2')">
                                    Absen Pulang
                                </button>
                            <?php else: ?>
                                <button class="btn btn-disabled" disabled>
                                    Absensi Selesai
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const pageHamburgerBtn = document.getElementById('pageHamburgerBtn');
            const sidebar = document.getElementById('sidebar');

            if (pageHamburgerBtn && sidebar) {
                pageHamburgerBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    document.body.classList.toggle('sidebar-open');
                });
            }

            document.addEventListener('click', (e) => {
                if (document.body.classList.contains('sidebar-open') &&
                    sidebar && !sidebar.contains(e.target) &&
                    pageHamburgerBtn && !pageHamburgerBtn.contains(e.target)) {
                    document.body.classList.remove('sidebar-open');
                }
            });

            <?php if ($success_message): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: '<?= addslashes($success_message) ?>',
                    confirmButtonColor: '#64748b'
                });
            <?php endif; ?>

            <?php if ($error_message): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '<?= addslashes($error_message) ?>',
                    confirmButtonColor: '#64748b'
                });
            <?php endif; ?>
        });

        function confirmSubmit(event, petugasName) {
            event.preventDefault();
            const form = event.target;
            const selected = form.querySelector('input[name="action"]:checked');
            if (!selected) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih Status',
                    text: 'Anda harus memilih status absensi.',
                    confirmButtonColor: '#64748b'
                });
                return false;
            }
            const actionText = selected.value.replace('_', ' ');
            Swal.fire({
                title: 'Konfirmasi Absensi',
                html: `Apakah Anda yakin ingin menandai <strong>${petugasName}</strong> sebagai <strong>${actionText}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Konfirmasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#64748b',
                cancelButtonColor: '#94a3b8'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
            return false;
        }

        function confirmCheckout(petugasName, petugasType) {
            Swal.fire({
                title: 'Absen Pulang',
                text: `Konfirmasi absen pulang untuk ${petugasName}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Pulang',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#64748b',
                cancelButtonColor: '#94a3b8'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'proses_absensi.php';
                    form.innerHTML = `<input type="hidden" name="action" value="check_out">
                          <input type="hidden" name="petugas_type" value="${petugasType}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
</body>

</html>