<?php
// absensi.php (UI)
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/session_validator.php'; 

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
date_default_timezone_set('Asia/Jakarta');

// Get data from proses_absensi.php via session
$page_data = $_SESSION['absensi_data'] ?? null;
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';

// Clear session messages
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Function to convert date to Indonesian format
function tanggal_indonesia($tanggal) {
    $bulan = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
              7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $hari = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu',
             'Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $tanggal = strtotime($tanggal);
    return $hari[date('l',$tanggal)].', '.date('d',$tanggal).' '.$bulan[(int)date('n',$tanggal)].' '.date('Y',$tanggal);
}

// If no data in session, fetch from database
if (!$page_data) {
    require_once 'proses_absensi.php';
    $page_data = fetchAbsensiData($conn);
}

$is_blocked = $page_data['is_blocked'] ?? 0;
$schedule = $page_data['schedule'] ?? null;
$petugas1_nama = $page_data['petugas1_nama'] ?? null;
$petugas2_nama = $page_data['petugas2_nama'] ?? null;
$attendance_petugas1 = $page_data['attendance_petugas1'] ?? null;
$attendance_petugas2 = $page_data['attendance_petugas2'] ?? null;
$attendance_complete = $page_data['attendance_complete'] ?? false;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <meta name="format-detection" content="telephone=no">
    <title>Absensi | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --bg-light: #f0f5ff;
            --bg-table: #ffffff;
            --border-color: #e2e8f0;
            --success-color: #059669;
            --danger-color: #dc2626;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Poppins', sans-serif; 
            -webkit-user-select: none;
            user-select: none; 
        }
        html, body { 
            width: 100%; 
            min-height: 100vh; 
            overflow-x: hidden; 
        }
        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            font-size: 14px;
            touch-action: pan-y;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            transition: margin-left 0.3s ease;
            overflow-y: auto;
            min-height: 100vh;
        }
        
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }

        /* Welcome Banner - Updated to match previous syntax */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .welcome-banner .content {
            flex: 1;
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.8;
            margin: 0;
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .petugas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }
        .petugas-card {
            background: var(--bg-table);
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        .petugas-card:hover { 
            transform: translateY(-3px); 
            box-shadow: var(--shadow-md); 
        }
        .petugas-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        .petugas-title { 
            font-size: 1.1rem; 
            font-weight: 600; 
            color: var(--primary-dark); 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        .petugas-title i { 
            color: var(--secondary-color); 
        }
        .petugas-info-section {
            background: #f8fafc;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #f1f5f9;
        }
        .petugas-name-wrapper { 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            margin-bottom: 15px; 
        }
        .petugas-avatar {
            width: 50px; 
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            display: flex; 
            align-items: center; 
            justify-content: center;
            color: var(--secondary-color);
            font-size: 1.5rem;
            flex-shrink: 0;
            border: 2px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .petugas-name-text { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            gap: 4px; 
            min-width: 0; 
        }
        .petugas-name { 
            font-size: 1.05rem; 
            font-weight: 600; 
            color: var(--text-primary); 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
        }
        .petugas-status {
            font-size: 0.75rem; 
            font-weight: 600; 
            padding: 4px 10px;
            border-radius: 20px; 
            display: inline-block; 
            width: fit-content;
            text-transform: uppercase; 
            letter-spacing: 0.5px;
        }
        .petugas-status.hadir { 
            background: #dcfce7; 
            color: #166534; 
        }
        .petugas-status.belum { 
            background: #fee2e2; 
            color: #991b1b; 
        }
        .petugas-status.pulang { 
            background: #fef3c7; 
            color: #92400e; 
        }
        .petugas-status.tidak-hadir { 
            background: #fee2e2; 
            color: #991b1b; 
        }
        .petugas-status.sakit { 
            background: #ffedd5; 
            color: #9a3412; 
        }
        .petugas-status.izin { 
            background: #dbeafe; 
            color: #1e40af; 
        }

        .time-info-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 10px; 
        }
        .time-info-item { 
            background: white; 
            border-radius: 5px; 
            padding: 10px; 
            border: 1px solid #e2e8f0; 
            text-align: center; 
        }
        .time-label { 
            color: var(--text-secondary); 
            font-size: 0.7rem; 
            font-weight: 600; 
            margin-bottom: 4px; 
            text-transform: uppercase; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 5px; 
        }
        .time-value { 
            color: var(--text-primary); 
            font-size: 1rem; 
            font-weight: 700; 
            font-family: 'Courier New', monospace; 
        }

        .radio-group { 
            display: flex; 
            gap: 10px; 
            margin: 20px 0 15px 0; 
            flex-wrap: wrap; 
        }
        .radio-option {
            position: relative; 
            display: inline-flex; 
            align-items: center;
            padding: 10px 15px; 
            background: white; 
            border: 1px solid #d1d5db;
            border-radius: 5px; 
            cursor: pointer; 
            transition: 0.2s;
            flex: 1; 
            justify-content: center; 
            min-width: 80px;
        }
        .radio-option:hover { 
            border-color: var(--secondary-color); 
            background: #eff6ff; 
        }
        .radio-option input[type='radio'] { 
            position: absolute; 
            opacity: 0; 
        }
        .radio-option input[type='radio']:checked ~ .radio-custom { 
            border-color: var(--secondary-color); 
            background: var(--secondary-color); 
        }
        .radio-option input[type='radio']:checked ~ .radio-custom::after { 
            opacity: 1; 
            transform: translate(-50%, -50%) scale(1); 
        }
        .radio-custom {
            width: 16px; 
            height: 16px; 
            border: 2px solid #9ca3af;
            border-radius: 50%; 
            margin-right: 8px; 
            position: relative; 
            flex-shrink: 0;
        }
        .radio-custom::after {
            content: ''; 
            position: absolute; 
            top: 50%; 
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            width: 8px; 
            height: 8px; 
            border-radius: 50%; 
            background: white;
            transition: 0.2s; 
            opacity: 0;
        }
        .radio-label { 
            font-size: 0.9rem; 
            font-weight: 500; 
            color: var(--text-primary); 
        }

        .btn-submit, .btn-checkout, .btn-disabled {
            width: 100%; 
            padding: 12px; 
            border-radius: 5px;
            font-size: 0.95rem; 
            font-weight: 600;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 8px;
            border: none; 
            cursor: pointer; 
            transition: 0.2s;
        }
        .btn-submit { 
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color)); 
            color: white; 
        }
        .btn-submit:hover { 
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); 
            transform: translateY(-1px); 
        }
        .btn-checkout { 
            background: #475569; 
            color: white; 
        }
        .btn-checkout:hover { 
            background: #334155; 
        }
        .btn-disabled { 
            background: #e2e8f0; 
            color: #94a3b8; 
            cursor: not-allowed; 
        }

        .alert-container {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white; 
            border-radius: 5px; 
            padding: 30px 20px;
            text-align: center; 
            box-shadow: var(--shadow-md);
        }
        .alert-container.blocked { 
            background: linear-gradient(135deg, #ef4444, #b91c1c); 
        }
        .alert-icon { 
            font-size: 3rem; 
            margin-bottom: 15px; 
            opacity: 0.9; 
        }
        .alert-title { 
            font-size: 1.4rem; 
            font-weight: 700; 
            margin-bottom: 10px; 
        }
        .alert-message { 
            font-size: 0.95rem; 
            line-height: 1.5; 
            opacity: 0.95; 
        }

        @media (max-width: 992px) {
            .petugas-grid { 
                grid-template-columns: 1fr; 
            }
        }

        @media (max-width: 768px) {
            .menu-toggle { 
                display: block; 
            }
            
            .main-content { 
                margin-left: 0; 
                max-width: 100%; 
                padding: 15px; 
            }
            
            .welcome-banner { 
                padding: 20px; 
                border-radius: 5px;
                align-items: center;
            }
            
            .welcome-banner h2 { 
                font-size: clamp(1.3rem, 3vw, 1.4rem); 
            }
            
            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }
            
            .radio-option { 
                min-width: calc(50% - 5px); 
                flex-grow: 0; 
            }
        }
        
        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.8vw, 1.3rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2><i class="fas fa-clipboard-check"></i> Absensi Petugas</h2>
                <p><?= tanggal_indonesia(date('Y-m-d')) ?></p>
            </div>
        </div>

        <?php if ($is_blocked): ?>
            <div class="alert-container blocked">
                <i class="fas fa-ban alert-icon"></i>
                <h2 class="alert-title">Absensi Dinonaktifkan</h2>
                <p class="alert-message">Aktivitas absensi petugas sementara diblokir oleh administrator.</p>
            </div>
        <?php elseif (!$schedule): ?>
            <div class="alert-container">
                <i class="fas fa-exclamation-triangle alert-icon"></i>
                <h2 class="alert-title">Belum Ada Jadwal</h2>
                <p class="alert-message">Tidak ada penugasan petugas untuk hari ini.</p>
            </div>
        <?php elseif ($attendance_complete): ?>
            <div class="alert-container">
                <i class="fas fa-check-circle alert-icon"></i>
                <h2 class="alert-title">Selesai!</h2>
                <p class="alert-message">Terima kasih, semua absensi hari ini telah lengkap.</p>
            </div>
        <?php else: ?>
            <div class="petugas-grid">
                <!-- Petugas 1 Card -->
                <?php if ($petugas1_nama): ?>
                    <div class="petugas-card">
                        <div class="petugas-header">
                            <div class="petugas-title"><i class="fas fa-user-tie"></i> Petugas 1</div>
                        </div>

                        <div class="petugas-info-section">
                            <div class="petugas-name-wrapper">
                                <div class="petugas-avatar"><i class="fas fa-user-circle"></i></div>
                                <div class="petugas-name-text">
                                    <div class="petugas-name"><?= htmlspecialchars($petugas1_nama) ?></div>
                                    <?php if (!$attendance_petugas1): ?>
                                        <div class="petugas-status belum">Belum Absen</div>
                                    <?php elseif ($attendance_petugas1['petugas1_status'] == 'hadir' && !$attendance_petugas1['waktu_keluar']): ?>
                                        <div class="petugas-status hadir">Sedang Bertugas</div>
                                    <?php else: 
                                        $status = $attendance_petugas1['petugas1_status'];
                                        $class = ($status == 'tidak_hadir') ? 'tidak-hadir' : (($status == 'sakit') ? 'sakit' : (($status == 'izin') ? 'izin' : 'pulang'));
                                        $text = ucfirst(str_replace('_', ' ', $status));
                                    ?>
                                        <div class="petugas-status <?= $class ?>"><?= $text ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="time-info-grid">
                                <div class="time-info-item">
                                    <div class="time-label"><i class="fas fa-sign-in-alt"></i> Masuk</div>
                                    <div class="time-value">
                                        <?= $attendance_petugas1 && $attendance_petugas1['waktu_masuk'] ? date('H:i', strtotime($attendance_petugas1['waktu_masuk'])) : '--:--' ?>
                                    </div>
                                </div>
                                <div class="time-info-item">
                                    <div class="time-label"><i class="fas fa-sign-out-alt"></i> Keluar</div>
                                    <div class="time-value">
                                        <?= $attendance_petugas1 && $attendance_petugas1['waktu_keluar'] ? date('H:i', strtotime($attendance_petugas1['waktu_keluar'])) : '--:--' ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$attendance_petugas1): ?>
                            <form method="POST" action="proses_absensi.php" onsubmit="return confirmSubmit(event, '<?= addslashes($petugas1_nama) ?>')">
                                <input type="hidden" name="petugas_type" value="petugas1"/>
                                <div class="radio-group">
                                    <label class="radio-option"><input type="radio" name="action" value="hadir" required/><span class="radio-custom"></span><span class="radio-label">Hadir</span></label>
                                    <label class="radio-option"><input type="radio" name="action" value="tidak_hadir"/><span class="radio-custom"></span><span class="radio-label">Alfa</span></label>
                                    <label class="radio-option"><input type="radio" name="action" value="sakit"/><span class="radio-custom"></span><span class="radio-label">Sakit</span></label>
                                    <label class="radio-option"><input type="radio" name="action" value="izin"/><span class="radio-custom"></span><span class="radio-label">Izin</span></label>
                                </div>
                                <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Kirim Absensi</button>
                            </form>
                        <?php elseif ($attendance_petugas1['petugas1_status'] == 'hadir' && !$attendance_petugas1['waktu_keluar']): ?>
                            <button type="button" class="btn-checkout" onclick="confirmCheckout('<?= addslashes($petugas1_nama) ?>', 'petugas1')"><i class="fas fa-sign-out-alt"></i> Absen Pulang</button>
                        <?php else: ?>
                            <button class="btn-disabled" disabled><i class="fas fa-check"></i> Selesai</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Petugas 2 Card -->
                <?php if ($petugas2_nama): ?>
                    <div class="petugas-card">
                        <div class="petugas-header">
                            <div class="petugas-title"><i class="fas fa-user-tie"></i> Petugas 2</div>
                        </div>
                        <div class="petugas-info-section">
                            <div class="petugas-name-wrapper">
                                <div class="petugas-avatar"><i class="fas fa-user-circle"></i></div>
                                <div class="petugas-name-text">
                                    <div class="petugas-name"><?= htmlspecialchars($petugas2_nama) ?></div>
                                    <?php if (!$attendance_petugas2): ?>
                                        <div class="petugas-status belum">Belum Absen</div>
                                    <?php elseif ($attendance_petugas2['petugas2_status'] == 'hadir' && !$attendance_petugas2['waktu_keluar']): ?>
                                        <div class="petugas-status hadir">Sedang Bertugas</div>
                                    <?php else: 
                                        $status = $attendance_petugas2['petugas2_status'];
                                        $class = ($status == 'tidak_hadir') ? 'tidak-hadir' : (($status == 'sakit') ? 'sakit' : (($status == 'izin') ? 'izin' : 'pulang'));
                                        $text = ucfirst(str_replace('_', ' ', $status));
                                    ?>
                                        <div class="petugas-status <?= $class ?>"><?= $text ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="time-info-grid">
                                <div class="time-info-item">
                                    <div class="time-label"><i class="fas fa-sign-in-alt"></i> Masuk</div>
                                    <div class="time-value">
                                        <?= $attendance_petugas2 && $attendance_petugas2['waktu_masuk'] ? date('H:i', strtotime($attendance_petugas2['waktu_masuk'])) : '--:--' ?>
                                    </div>
                                </div>
                                <div class="time-info-item">
                                    <div class="time-label"><i class="fas fa-sign-out-alt"></i> Keluar</div>
                                    <div class="time-value">
                                        <?= $attendance_petugas2 && $attendance_petugas2['waktu_keluar'] ? date('H:i', strtotime($attendance_petugas2['waktu_keluar'])) : '--:--' ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$attendance_petugas2): ?>
                            <form method="POST" action="proses_absensi.php" onsubmit="return confirmSubmit(event, '<?= addslashes($petugas2_nama) ?>')">
                                <input type="hidden" name="petugas_type" value="petugas2"/>
                                <div class="radio-group">
                                <label class="radio-option"><input type="radio" name="action" value="hadir" required/><span class="radio-custom"></span><span class="radio-label">Hadir</span></label>
                                    <label class="radio-option"><input type="radio" name="action" value="tidak_hadir"/><span class="radio-custom"></span><span class="radio-label">Alfa</span></label>
                                    <label class="radio-option"><input type="radio" name="action" value="sakit"/><span class="radio-custom"></span><span class="radio-label">Sakit</span></label>
                                    <label class="radio-option"><input type="radio" name="action" value="izin"/><span class="radio-custom"></span><span class="radio-label">Izin</span></label>
                                </div>
                                <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Kirim Absensi</button>
                            </form>
                        <?php elseif ($attendance_petugas2['petugas2_status'] == 'hadir' && !$attendance_petugas2['waktu_keluar']): ?>
                            <button type="button" class="btn-checkout" onclick="confirmCheckout('<?= addslashes($petugas2_nama) ?>', 'petugas2')"><i class="fas fa-sign-out-alt"></i> Absen Pulang</button>
                        <?php else: ?>
                            <button class="btn-disabled" disabled><i class="fas fa-check"></i> Selesai</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
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

            <?php if ($success_message): ?>
            Swal.fire({ 
                icon: 'success', 
                title: 'Berhasil', 
                text: '<?= addslashes($success_message) ?>', 
                confirmButtonColor: '#1e3a8a' 
            });
            <?php endif; ?>

            <?php if ($error_message): ?>
            Swal.fire({ 
                icon: 'error', 
                title: 'Gagal', 
                text: '<?= addslashes($error_message) ?>', 
                confirmButtonColor: '#1e3a8a' 
            });
            <?php endif; ?>
        });

        function confirmSubmit(event, petugasName) {
            event.preventDefault();
            const form = event.target;
            const selectedOption = form.querySelector('input[name="action"]:checked');

            if (!selectedOption) {
                Swal.fire({ 
                    icon: 'warning', 
                    title: 'Pilih Status', 
                    text: 'Anda harus memilih status absensi.', 
                    confirmButtonColor: '#1e3a8a' 
                });
                return false;
            }

            let actionText = selectedOption.value.replace('_', ' ');

            Swal.fire({
                title: 'Konfirmasi Absensi',
                html: `Apakah Anda yakin ingin menandai <strong>${petugasName}</strong> sebagai <strong>${actionText}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Konfirmasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#6b7280'
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
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'proses_absensi.php';
                    form.innerHTML = `<input type="hidden" name="action" value="check_out"><input type="hidden" name="petugas_type" value="${petugasType}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
</body>
</html>
