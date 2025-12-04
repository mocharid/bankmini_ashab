<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict to admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../index.php");
    exit();
}

// CSRF Token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Admin ID
$admin_id = $_SESSION['user_id'] ?? 0;
if ($admin_id === 0) {
    $_SESSION['error_message'] = 'Sesi tidak valid. Silakan login kembali.';
    header("Location: ../../index.php");
    exit();
}

// Validate admin
$query_admin = "SELECT id FROM users WHERE id = ? AND role = 'admin'";
$stmt_admin = $conn->prepare($query_admin);
$stmt_admin->bind_param("i", $admin_id);
$stmt_admin->execute();
if ($stmt_admin->get_result()->num_rows === 0) {
    $_SESSION['error_message'] = 'Akun admin tidak valid.';
    header("Location: ../../index.php");
    exit();
}
$stmt_admin->close();

// Get block status
$query_block = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
$result_block = $conn->query($query_block);
if (!$result_block) {
    $is_blocked = 0;
} else {
    $row = $result_block->fetch_assoc();
    if (!$row) {
        $insert = "INSERT INTO petugas_block_status (id, is_blocked, updated_by, updated_at) VALUES (1, 0, ?, NOW())";
        $stmt_i = $conn->prepare($insert);
        $stmt_i->bind_param("i", $admin_id);
        $stmt_i->execute();
        $stmt_i->close();
        $is_blocked = 0;
    } else {
        $is_blocked = $row['is_blocked'] ?? 0;
    }
}

// Get today's schedule
$today = date('Y-m-d');
$jadwal_query = "SELECT pt.*, pi.petugas1_nama, pi.petugas2_nama
                 FROM petugas_tugas pt
                 LEFT JOIN petugas_info pi ON pt.petugas1_id = pi.user_id
                 WHERE pt.tanggal = ?
                 LIMIT 1";
$stmt_jadwal = $conn->prepare($jadwal_query);
$stmt_jadwal->bind_param("s", $today);
$stmt_jadwal->execute();
$jadwal_result = $stmt_jadwal->get_result();
$jadwal_today = null;
$has_schedule = false;
if ($jadwal_result->num_rows > 0) {
    $jadwal_today = $jadwal_result->fetch_assoc();
    $has_schedule = true;
}
$stmt_jadwal->close();

// Handle toggle
$success_message = $error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block']) && $_POST['token'] === $_SESSION['form_token']) {
    if (!$has_schedule) {
        $error_message = 'Tidak dapat mengubah status karena tidak ada jadwal petugas hari ini.';
    } else {
        $new_status = $is_blocked ? 0 : 1;
        $query_update = "UPDATE petugas_block_status SET is_blocked = ?, updated_by = ?, updated_at = NOW() WHERE id = 1";
        $stmt = $conn->prepare($query_update);
        if ($stmt) {
            $stmt->bind_param("ii", $new_status, $admin_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success_message = $new_status ? 'Aktivitas dashboard petugas berhasil diblokir!' : 'Blokir aktivitas dashboard petugas berhasil dibuka!';
                $_SESSION['success_message'] = $success_message;
                $_SESSION['form_token'] = bin2hex(random_bytes(32));
            } else {
                $error_message = 'Tidak ada perubahan status.';
            }
            $stmt->close();
        } else {
            $error_message = 'Gagal memperbarui status.';
        }
    }
    header("Location: kontrol_akses_petugas.php", true, 303);
    exit();
}

// Messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Format date for display
$tanggal_hari = date('j');
$bulan_indonesia = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$bulan_teks = $bulan_indonesia[date('n')];
$tahun = date('Y');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>kontrol Akses Petugas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* CLEAN & FORMAL DESIGN */
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e40af;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --danger-color: #dc2626;
            --success-color: #059669;
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

        .status-container {
            background: #f8fafc;
            border-radius: 5px;
            padding: clamp(20px, 4vw, 28px);
            border: 1px solid var(--border-color);
        }

        .status-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: clamp(15px, 3vw, 20px);
            text-align: center;
        }

        .toggle-switch-container {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 50px;
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .toggle-label {
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            color: var(--text-secondary);
        }

        .toggle-switch {
            position: relative;
            width: 60px;
            height: 30px;
            background: <?= $is_blocked ? '#dc2626' : '#059669' ?>;
            border-radius: 50px;
            cursor: <?= $has_schedule ? 'pointer' : 'not-allowed' ?>;
            transition: background 0.3s ease;
            opacity: <?= $has_schedule ? '1' : '0.5' ?>;
        }

        .toggle-switch.disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .toggle-slider {
            position: absolute;
            top: 3px;
            left: <?= $is_blocked ? '33px' : '3px' ?>;
            width: 24px;
            height: 24px;
            background: white;
            border-radius: 50%;
            transition: left 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .toggle-slider i {
            font-size: 12px;
            color: <?= $is_blocked ? '#dc2626' : '#059669' ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 600;
            background: <?= $is_blocked ? '#fee2e2' : '#d1fae5' ?>;
            color: <?= $is_blocked ? '#dc2626' : '#059669' ?>;
        }

        .status-description {
            font-size: clamp(0.85rem, 1.8vw, 0.92rem);
            color: var(--text-secondary);
            line-height: 1.6;
            max-width: 500px;
            margin: 0 auto;
        }

        .instruction-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 5px;
            padding: 12px 16px;
            margin-top: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .instruction-box.disabled {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .instruction-box i {
            font-size: 1.2rem;
            color: #3b82f6;
        }

        .instruction-box.disabled i {
            color: #dc2626;
        }

        .instruction-box p {
            font-size: clamp(0.82rem, 1.7vw, 0.88rem);
            color: var(--text-secondary);
            margin: 0;
        }

        .jadwal-section {
            margin-top: clamp(20px, 4vw, 28px);
            padding: clamp(18px, 3.5vw, 24px);
            background: white;
            border-radius: 5px;
            border: 1px solid var(--border-color);
        }

        .jadwal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
        }

        .jadwal-header i {
            font-size: 1.3rem;
            color: var(--secondary-color);
        }

        .jadwal-header h3 {
            font-size: clamp(1rem, 2.2vw, 1.15rem);
            font-weight: 600;
            color: var(--primary-dark);
        }

        .jadwal-table {
            width: 100%;
            border-collapse: collapse;
        }

        .jadwal-table th,
        .jadwal-table td {
            padding: clamp(10px, 2vw, 12px);
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: clamp(0.85rem, 1.8vw, 0.92rem);
        }

        .jadwal-table th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-size: clamp(0.78rem, 1.6vw, 0.85rem);
            letter-spacing: 0.3px;
        }

        .jadwal-table td {
            font-weight: 500;
            color: var(--text-primary);
        }

        .no-jadwal {
            text-align: center;
            padding: clamp(30px, 6vw, 40px);
            color: var(--text-secondary);
        }

        .no-jadwal i {
            font-size: 3rem;
            color: #fbbf24;
            margin-bottom: 15px;
            display: block;
        }

        .no-jadwal p {
            font-size: clamp(0.9rem, 2vw, 1rem);
            margin-top: 10px;
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

            .toggle-switch-container {
                flex-direction: column;
                text-align: center;
            }

            .jadwal-table {
                font-size: 0.85rem;
            }

            .jadwal-table th,
            .jadwal-table td {
                padding: 8px;
            }
        }

        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .toggle-switch {
                width: 50px;
                height: 26px;
            }

            .toggle-slider {
                width: 20px;
                height: 20px;
                top: 3px;
                left: <?= $is_blocked ? '27px' : '3px' ?>;
            }

            .jadwal-table {
                font-size: 0.8rem;
            }
        }

        @media (hover: none) and (pointer: coarse) {
            .toggle-switch {
                min-width: 60px;
                min-height: 30px;
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
                <h2><i class="fas fa-user-shield"></i> Kontrol Akses Petugas</h2>
                <p>Kontrol Akses Petugas yang sedang bertugas</p>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-toggle-on"></i> Status Aktivitas Dashboard Petugas
            </h2>

            <div class="status-container">
                <div class="status-info">
                    <div class="toggle-switch-container">
                        <span class="toggle-label">Status:</span>
                        <div class="toggle-switch <?= !$has_schedule ? 'disabled' : '' ?>" id="toggleSwitch">
                            <div class="toggle-slider">
                                <i class="fas <?= $is_blocked ? 'fa-lock' : 'fa-unlock' ?>" id="toggleIcon"></i>
                            </div>
                        </div>
                        <span class="status-badge" id="statusBadge">
                            <?= $is_blocked ? 'Diblokir' : 'Aktif' ?>
                        </span>
                    </div>

                    <p class="status-description" id="statusDescription">
                        <?= $is_blocked 
                            ? 'Dashboard petugas saat ini diblokir. Petugas tidak dapat melakukan transaksi, absensi, atau login ke sistem.' 
                            : 'Dashboard petugas beroperasi normal. Petugas dapat melakukan transaksi, absensi, dan aktivitas lainnya sesuai jadwal.' 
                        ?>
                    </p>

                    <div class="instruction-box <?= !$has_schedule ? 'disabled' : '' ?>">
                        <i class="fas <?= $has_schedule ? 'fa-info-circle' : 'fa-exclamation-triangle' ?>"></i>
                        <p id="instructionText">
                            <?= $has_schedule 
                                ? 'Klik toggle switch untuk mengubah status aktivitas dashboard petugas' 
                                : 'Status tidak dapat diubah karena tidak ada jadwal petugas untuk hari ini' 
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="jadwal-section">
                <div class="jadwal-header">
                    <i class="fas fa-calendar-day"></i>
                    <h3>Jadwal Petugas Bank Mini - <?= $tanggal_hari ?> <?= $bulan_teks ?> <?= $tahun ?></h3>
                </div>

                <?php if ($has_schedule): ?>
                    <table class="jadwal-table">
                        <thead>
                            <tr>
                                <th>Petugas 1 (Kelas 10)</th>
                                <th>Petugas 2 (Kelas 11)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?= htmlspecialchars($jadwal_today['petugas1_nama']) ?></td>
                                <td><?= htmlspecialchars($jadwal_today['petugas2_nama']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-jadwal">
                        <i class="fas fa-calendar-times"></i>
                        <p><strong>Tidak ada jadwal petugas untuk hari ini</strong></p>
                        <p style="font-size: 0.85rem; margin-top: 5px;">Silakan tambahkan jadwal petugas terlebih dahulu</p>
                    </div>
                <?php endif; ?>
            </div>

            <form action="" method="POST" id="toggleForm" style="display: none;">
                <input type="hidden" name="toggle_block" value="1">
                <input type="hidden" name="token" value="<?= $token ?>">
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleSwitch = document.getElementById('toggleSwitch');
            const toggleIcon = document.getElementById('toggleIcon');
            const statusBadge = document.getElementById('statusBadge');
            const statusDescription = document.getElementById('statusDescription');
            const instructionText = document.getElementById('instructionText');
            const form = document.getElementById('toggleForm');
            let submitting = false;

            // Prevent zoom on mobile
            document.addEventListener('touchstart', function(e) {
                if (e.touches.length > 1) {
                    e.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(e) {
                const now = Date.now();
                if (now - lastTouchEnd <= 300) {
                    e.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            // Sidebar toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });

                document.addEventListener('click', function(e) {
                    if (sidebar.classList.contains('active') && 
                        !sidebar.contains(e.target) && 
                        !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }

            // Toggle switch click handler
            toggleSwitch.addEventListener('click', function() {
                if (submitting || toggleSwitch.classList.contains('disabled')) {
                    return;
                }

                submitting = true;

                const isBlocked = statusBadge.textContent.trim() === 'Diblokir';
                const action = isBlocked ? 'mengaktifkan' : 'menonaktifkan';
                const title = isBlocked ? 'Mengaktifkan Dashboard Petugas?' : 'Memblokir Dashboard Petugas?';

                const impactList = isBlocked 
                    ? '<ul style="text-align: left; margin: 15px 0; padding-left: 25px; line-height: 1.8; font-size: 0.88rem; color: #4b5563;"><li>Dashboard petugas dapat melakukan transaksi secara normal</li><li>Sistem mengizinkan login dan operasi harian</li><li>Petugas dapat melakukan absensi sesuai jadwal</li><li>Perubahan berlaku segera dan tercatat dalam log</li></ul>'
                    : '<ul style="text-align: left; margin: 15px 0; padding-left: 25px; line-height: 1.8; font-size: 0.88rem; color: #4b5563;"><li>Dashboard petugas tidak dapat melakukan transaksi</li><li>Sistem memblokir login petugas</li><li>Petugas tidak dapat melakukan absensi</li><li>Perubahan berlaku segera dan tercatat dalam log</li></ul>';

                Swal.fire({
                    title: title,
                    html: `<div style="font-size: 0.95rem; color: #374151; margin-bottom: 10px;">Apakah Anda yakin ingin ${action} aktivitas dashboard petugas?</div><div style="color: #dc2626; font-size: 0.85rem; font-weight: 600; margin: 12px 0; text-transform: uppercase; letter-spacing: 0.5px;">Dampak Perubahan:</div>${impactList}`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    reverseButtons: true,
                    allowOutsideClick: false,
                    customClass: {
                        popup: 'swal-wide'
                    }
                }).then(function(result) {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Memproses Perubahan',
                            html: 'Mohon tunggu sebentar...',
                            icon: 'info',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: function() {
                                Swal.showLoading();
                            }
                        });

                        setTimeout(function() {
                            form.submit();
                        }, 800);
                    } else {
                        submitting = false;
                    }
                });
            });

            // Display messages
            <?php if ($success_message): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: '<?= addslashes($success_message) ?>',
                confirmButtonColor: '#1e3a8a',
                confirmButtonText: 'OK'
            });
            <?php elseif ($error_message): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?= addslashes($error_message) ?>',
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'OK'
            });
            <?php endif; ?>
        });
    </script>

    <style>
        .swal-wide {
            width: 600px !important;
            max-width: 90% !important;
        }

        @media (max-width: 768px) {
            .swal-wide {
                width: 95% !important;
            }
        }
    </style>
</body>
</html>