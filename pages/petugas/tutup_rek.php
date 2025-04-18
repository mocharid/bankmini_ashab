<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = false;
$nasabah = null;
$pin_verified = false;
$pin_not_set = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $no_rekening = $_POST['no_rekening'] ?? '';
    
    if (empty($no_rekening) || $no_rekening === 'REK') {
        $error = 'Nomor rekening tidak boleh kosong.';
    } else {
        // Fetch account details
        $query = "SELECT r.id AS rekening_id, r.saldo, r.user_id, u.nama, u.username, u.email, u.pin, u.jurusan_id, u.kelas_id, 
                        j.nama_jurusan, k.nama_kelas, r.no_rekening 
                 FROM rekening r 
                 JOIN users u ON r.user_id = u.id 
                 LEFT JOIN jurusan j ON u.jurusan_id = j.id
                 LEFT JOIN kelas k ON u.kelas_id = k.id
                 WHERE r.no_rekening = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $nasabah = $result->fetch_assoc();
            
            // Check if PIN is not set
            if (empty($nasabah['pin'])) {
                $pin_not_set = true;
            } else {
                // Verify PIN if provided
                if (isset($_POST['verify_pin'])) {
                    $pin = implode('', [
                        $_POST['pin1'] ?? '',
                        $_POST['pin2'] ?? '',
                        $_POST['pin3'] ?? '',
                        $_POST['pin4'] ?? '',
                        $_POST['pin5'] ?? '',
                        $_POST['pin6'] ?? ''
                    ]);
                    if ($pin === $nasabah['pin']) {
                        $pin_verified = true;
                    } else {
                        $error = 'PIN salah. Silakan coba lagi.';
                    }
                }
            }

            // Handle account and user deletion
            if (isset($_POST['confirm'])) {
                $pin_verified_from_form = isset($_POST['pin_verified']) && $_POST['pin_verified'] === '1';
                if ($pin_verified || $pin_verified_from_form) {
                    $rekening_id = $nasabah['rekening_id'];
                    $user_id = $nasabah['user_id'];
                    $admin_id = $_SESSION['user_id'];
                    $saldo = $nasabah['saldo'];

                    $conn->begin_transaction();
                    try {
                        // 1. Log the action in log_aktivitas (before deleting user)
                        $query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, alasan_pemulihan, waktu) 
                                 VALUES (?, ?, ?, ?, ?, ?, NOW())";
                        $stmt = $conn->prepare($query);
                        $action = "pin";
                        $nilai_baru = "";
                        $alasan = "Penutupan rekening $no_rekening dan penghapusan pengguna oleh {$_SESSION['role']}";
                        $alasan_pemulihan = $_POST['reason'] ?? "Penutupan rekening dan penghapusan pengguna tanpa alasan spesifik";
                        if ($saldo > 0) {
                            $alasan .= " dengan sisa saldo Rp " . number_format($saldo, 0, ',', '.') . " akan dibayarkan";
                            $alasan_pemulihan .= " (Sisa saldo Rp " . number_format($saldo, 0, ',', '.') . " akan dibayarkan)";
                        }
                        $stmt->bind_param("iissss", $admin_id, $user_id, $action, $nilai_baru, $alasan, $alasan_pemulihan);
                        $stmt->execute();

                        // 2. Delete related mutasi
                        $query = "DELETE m FROM mutasi m 
                                 JOIN transaksi t ON m.transaksi_id = t.id 
                                 WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $rekening_id, $rekening_id);
                        $stmt->execute();

                        // 3. Delete related transaksi
                        $query = "DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $rekening_id, $rekening_id);
                        $stmt->execute();

                        // 4. Delete rekening
                        $query = "DELETE FROM rekening WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $rekening_id);
                        $stmt->execute();

                        // 5. Delete related data from other tables
                        $query = "DELETE FROM active_sessions WHERE user_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        $query = "DELETE FROM notifications WHERE user_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        $query = "DELETE FROM saldo_transfers WHERE petugas_id = ? OR admin_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $user_id, $user_id);
                        $stmt->execute();

                        $query = "DELETE FROM log_aktivitas WHERE siswa_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        // 6. Delete user
                        $query = "DELETE FROM users WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        $conn->commit();
                        $success = true;
                        $nasabah = null;
                        $pin_verified = false;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Gagal menutup rekening dan menghapus pengguna: ' . $e->getMessage();
                        error_log("Account and user deletion error: " . $e->getMessage());
                    }
                } else {
                    $error = 'PIN belum diverifikasi. Silakan masukkan PIN terlebih dahulu.';
                }
            }
        } else {
            $error = 'Nomor rekening tidak ditemukan.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tutup Rekening dan Hapus Pengguna - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --bg-light: #f8fafc;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 8px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
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
        }

        .top-nav {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            padding: 15px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.3rem, 2.5vw, 1.5rem);
            position: relative;
        }

        .back-btn {
            position: absolute;
            left: 20px;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: none;
            padding: 0;
            border-radius: 50%;
            cursor: pointer;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            overflow: hidden;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.1);
            border-radius: 50%;
        }

        .back-btn:active {
            transform: scale(0.95);
            border-radius: 50%;
        }

        .back-btn i {
            font-size: 1.2rem;
        }

        .main-content {
            flex: 1;
            padding: 30px 20px;
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.2);
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 10s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.6rem, 3.5vw, 2rem);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }

        .deposit-card, .results-card {
            background: linear-gradient(145deg, #ffffff, #f7fafc);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .deposit-card:hover, .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .deposit-form {
            display: grid;
            gap: 25px;
        }

        label {
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.9rem, 1.8vw, 1rem);
        }

        input[type="tel"] {
            width: 100%;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            transition: var(--transition);
            background: #fff;
        }

        input[type="tel"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 8px rgba(12, 77, 162, 0.2);
            transform: scale(1.01);
        }

        .pin-input-container {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin: 15px 0;
        }

        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            transition: var(--transition);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 8px rgba(12, 77, 162, 0.2);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--secondary-color);
            background: var(--primary-light);
            animation: bouncePin 0.3s ease;
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            width: fit-content;
            min-width: 150px;
            margin: 0 auto;
        }

        button:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        button:active {
            transform: scale(0.95);
        }

        .btn-danger {
            background: var(--danger-color);
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-confirm {
            background: var(--secondary-color);
        }

        .btn-confirm:hover {
            background: var(--secondary-dark);
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading i,
        .btn-loading span {
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 22px;
            height: 22px;
            margin: -11px 0 0 -11px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.6s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

        .results-card {
            animation: slideStep 0.5s ease-in-out;
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid #edf2f7;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
            border-radius: 8px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .detail-value.saldo-non-zero {
            font-weight: 700;
        }

        textarea {
            width: 100%;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            transition: var(--transition);
            resize: vertical;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 8px rgba(12, 77, 162, 0.2);
            transform: scale(1.01);
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .modal-content {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 50px;
            text-align: center;
            max-width: 90%;
            width: 500px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        .modal-content.error {
            background: linear-gradient(145deg, #ffffff, #fee2e2);
        }

        .modal-content.info {
            background: linear-gradient(145deg, #ffffff, #e0f2fe);
        }

        .modal-content.success {
            background: linear-gradient(145deg, #ffffff, #e6fffa);
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-icon {
            font-size: clamp(4.5rem, 8vw, 5rem);
            margin-bottom: 30px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.15));
        }

        .modal-icon.success {
            color: var(--secondary-color);
        }

        .modal-icon.error {
            color: var(--danger-color);
        }

        .modal-icon.info {
            color: var(--primary-color);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-content h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .modal-content.error h3 {
            color: var(--danger-color);
        }

        .modal-content.info h3 {
            color: var(--primary-color);
        }

        .modal-content.success h3 {
            color: var(--secondary-color);
        }

        .modal-content p {
            color: var(--text-secondary);
            font-size: clamp(1rem, 2.2vw, 1.15rem);
            margin-bottom: 20px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.6;
        }

        .modal-content.error p {
            color: #b91c1c;
        }

        .modal-content.info p {
            color: #0369a1;
        }

        .modal-content.success p {
            color: #047857;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .confetti {
            position: absolute;
            width: 12px;
            height: 12px;
            opacity: 0.8;
            animation: confettiFall 4s ease-out forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .section-title {
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 12px 15px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .back-btn {
                left: 15px;
                width: 40px;
                height: 40px;
            }

            .main-content {
                padding: 20px 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.4rem, 3vw, 1.8rem);
            }

            .welcome-banner p {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .deposit-card, .results-card {
                padding: 25px;
            }

            .deposit-form {
                gap: 15px;
            }

            .section-title {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            input[type="tel"], textarea {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .pin-input {
                width: 46px;
                height: 46px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            button {
                min-width: 120px;
                padding: 12px 15px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .detail-row {
                font-size: clamp(0.9rem, 2vw, 1rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .modal-content {
                width: 90%;
                padding: 40px;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 10px 12px;
                font-size: clamp(0.9rem, 2.5vw, 1.1rem);
            }

            .back-btn {
                left: 12px;
                width: 36px;
                height: 36px;
            }

            .main-content {
                padding: 12px;
            }

            .welcome-banner {
                padding: 15px;
                margin-bottom: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 2vw, 0.85rem);
            }

            .deposit-card, .results-card {
                padding: 15px;
                margin-bottom: 15px;
            }

            .deposit-form {
                gap: 12px;
            }

            .section-title {
                font-size: clamp(0.9rem, 2.5vw, 1rem);
            }

            input[type="tel"], textarea {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .pin-input {
                width: 36px;
                height: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            button {
                min-width: 100px;
                padding: 10px 12px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .detail-row {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .modal-content {
                width: 95%;
                padding: 30px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-trash-alt"></i> Tutup Rekening dan Hapus Pengguna</h2>
            <p>Nonaktifkan rekening dan hapus pengguna beserta semua data terkait dari sistem</p>
        </div>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Cari Rekening</h3>
            <form id="searchForm" action="" method="POST" class="deposit-form">
                <div>
                    <label for="no_rekening">Nomor Rekening:</label>
                    <input type="tel" id="no_rekening" name="no_rekening" inputmode="numeric" placeholder="REK..." required autofocus
                           value="<?php echo isset($no_rekening) && $no_rekening !== 'REK' ? htmlspecialchars($no_rekening) : 'REK'; ?>">
                </div>
                <button type="submit" id="searchBtn">
                    <i class="fas fa-search"></i>
                    <span>Cek Rekening</span>
                </button>
            </form>
        </div>

        <?php if ($nasabah !== null && !$pin_not_set): ?>
            <div class="results-card">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Pengguna</h3>
                <div class="detail-row">
                    <div class="detail-label">Nama:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['nama'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Username:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['username'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Email:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['email'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['no_rekening'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['nama_jurusan'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['nama_kelas'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Saldo:</div>
                    <div class="detail-value <?php echo $nasabah['saldo'] > 0 ? 'saldo-non-zero' : ''; ?>">
                        Rp <?php echo number_format($nasabah['saldo'], 0, ',', '.'); ?>
                    </div>
                </div>

                <?php if (!$pin_verified && !isset($_POST['confirm'])): ?>
                    <form id="pinForm" action="" method="POST" class="deposit-form">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($nasabah['no_rekening']); ?>">
                        <div>
                            <label>Masukkan PIN untuk Verifikasi:</label>
                            <div class="pin-input-container">
                                <input type="password" inputmode="numeric" class="pin-input" name="pin1" maxlength="1" pattern="[0-9]" required autofocus>
                                <input type="password" inputmode="numeric" class="pin-input" name="pin2" maxlength="1" pattern="[0-9]" required>
                                <input type="password" inputmode="numeric" class="pin-input" name="pin3" maxlength="1" pattern="[0-9]" required>
                                <input type="password" inputmode="numeric" class="pin-input" name="pin4" maxlength="1" pattern="[0-9]" required>
                                <input type="password" inputmode="numeric" class="pin-input" name="pin5" maxlength="1" pattern="[0-9]" required>
                                <input type="password" inputmode="numeric" class="pin-input" name="pin6" maxlength="1" pattern="[0-9]" required>
                            </div>
                        </div>
                        <button type="submit" name="verify_pin" id="verifyPinBtn" class="btn-confirm">
                            <i class="fas fa-check"></i>
                            <span>Verifikasi PIN</span>
                        </button>
                    </form>
                <?php elseif ($pin_verified || (isset($_POST['pin_verified']) && $_POST['pin_verified'] === '1')): ?>
                    <form id="closeAccountForm" action="" method="POST" class="deposit-form">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($nasabah['no_rekening']); ?>">
                        <input type="hidden" name="pin_verified" value="1">
                        <div>
                            <label for="reason">Alasan Penghapusan:</label>
                            <textarea id="reason" name="reason" rows="4" placeholder="Masukkan alasan penghapusan pengguna dan rekening" required></textarea>
                        </div>
                        <button type="submit" name="confirm" class="btn-danger" id="confirmBtn">
                            <i class="fas fa-trash-alt"></i>
                            <span>Konfirmasi Penghapusan</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Prevent pinch-to-zoom and double-tap zoom
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('gesturestart', function(event) {
            event.preventDefault();
        });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', function() {
            const searchForm = document.getElementById('searchForm');
            const pinForm = document.getElementById('pinForm');
            const closeAccountForm = document.getElementById('closeAccountForm');
            const searchBtn = document.getElementById('searchBtn');
            const verifyPinBtn = document.getElementById('verifyPinBtn');
            const confirmBtn = document.getElementById('confirmBtn');
            const inputNoRek = document.getElementById('no_rekening');
            const pinInputs = document.querySelectorAll('.pin-input');
            const prefix = "REK";

            // Initialize account number input with prefix
            if (!inputNoRek.value || inputNoRek.value === prefix) {
                inputNoRek.value = prefix;
            }

            // Restrict account number to 6 digits after REK
            inputNoRek.addEventListener('input', function(e) {
                let value = this.value;
                if (!value.startsWith(prefix)) {
                    this.value = prefix;
                    return;
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '').slice(0, 6);
                this.value = prefix + userInput;
            });

            inputNoRek.addEventListener('keydown', function(e) {
                let cursorPos = this.selectionStart;
                let userInput = this.value.slice(prefix.length);
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                } else if (userInput.length >= 6 && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                    e.preventDefault();
                }
            });

            inputNoRek.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                this.value = prefix + pastedData;
            });

            inputNoRek.addEventListener('focus', function() {
                if (this.value === prefix) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputNoRek.addEventListener('click', function(e) {
                if (this.selectionStart < prefix.length) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            // PIN input handling
            if (pinInputs.length > 0) {
                pinInputs.forEach((input, index) => {
                    input.addEventListener('input', function(e) {
                        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 1);
                        if (this.value) {
                            this.classList.add('filled');
                            if (index < pinInputs.length - 1) {
                                pinInputs[index + 1].focus();
                            } else if (verifyPinBtn) {
                                verifyPinBtn.focus();
                            }
                        } else {
                            this.classList.remove('filled');
                        }
                    });

                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && !this.value && index > 0) {
                            pinInputs[index - 1].focus();
                            pinInputs[index - 1].value = '';
                            pinInputs[index - 1].classList.remove('filled');
                        }
                    });

                    input.addEventListener('paste', function(e) {
                        e.preventDefault();
                        let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                        for (let i = 0; i < pastedData.length && i < pinInputs.length; i++) {
                            pinInputs[i].value = pastedData[i];
                            pinInputs[i].classList.add('filled');
                        }
                        if (pastedData.length < pinInputs.length) {
                            pinInputs[pastedData.length].focus();
                        } else {
                            pinInputs[pinInputs.length - 1].focus();
                        }
                    });
                });
            }

            // Form submission handling
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    const rekening = inputNoRek.value.trim();
                    if (rekening === prefix) {
                        e.preventDefault();
                        showModalAlert('Silakan masukkan nomor rekening lengkap', 'error');
                        inputNoRek.classList.add('form-error');
                        setTimeout(() => inputNoRek.classList.remove('form-error'), 400);
                        inputNoRek.focus();
                        return;
                    }
                    if (rekening.length !== prefix.length + 6) {
                        e.preventDefault();
                        showModalAlert('Nomor rekening harus terdiri dari 6 digit setelah REK', 'error');
                        inputNoRek.classList.add('form-error');
                        setTimeout(() => inputNoRek.classList.remove('form-error'), 400);
                        inputNoRek.focus();
                        return;
                    }
                    searchBtn.classList.add('btn-loading');
                    setTimeout(() => {
                        searchBtn.classList.remove('btn-loading');
                    }, 800);
                });
            }

            if (pinForm) {
                pinForm.addEventListener('submit', function(e) {
                    const pinValues = Array.from(pinInputs).map(input => input.value);
                    if (pinValues.some(val => !val)) {
                        e.preventDefault();
                        showModalAlert('PIN harus 6 digit', 'error');
                        pinInputs.forEach(input => {
                            if (!input.value) {
                                input.classList.add('form-error');
                                setTimeout(() => input.classList.remove('form-error'), 400);
                            }
                        });
                        pinInputs[pinValues.findIndex(val => !val) || 0].focus();
                        return;
                    }
                    if (verifyPinBtn) {
                        verifyPinBtn.classList.add('btn-loading');
                        setTimeout(() => {
                            verifyPinBtn.classList.remove('btn-loading');
                        }, 800);
                    }
                });
            }

            if (closeAccountForm) {
                closeAccountForm.addEventListener('submit', function(e) {
                    if (!confirm('PERINGATAN: Anda akan menghapus pengguna, rekening, dan semua data terkait secara permanen. ' +
                                 '<?php if ($nasabah && $nasabah['saldo'] > 0): ?>Sisa saldo Rp <?php echo number_format($nasabah['saldo'], 0, ',', '.'); ?> akan dibayarkan.<?php endif; ?>' +
                                 'Apakah Anda yakin ingin melanjutkan?')) {
                        e.preventDefault();
                        return;
                    }
                    if (confirmBtn) {
                        confirmBtn.classList.add('btn-loading');
                        setTimeout(() => {
                            confirmBtn.classList.remove('btn-loading');
                        }, 800);
                    }
                });
            }

            // Modal handling
            function closeModal(overlay, modal) {
                overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                modal.style.animation = 'popInModal 0.7s ease-out reverse';
                setTimeout(() => overlay.remove(), 500);
            }

            function showSuccessAnimation(message) {
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.innerHTML = `
                    <div class="modal-content success">
                        <div class="modal-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Penghapusan Berhasil!</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.modal-content');
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    modal.appendChild(confetti);
                }

                overlay.addEventListener('click', () => closeModal(overlay, modal));
                setTimeout(() => {
                    closeModal(overlay, modal);
                    window.location.href = 'dashboard.php';
                }, 7000);
            }

            function showModalAlert(message, type) {
                const existingModals = document.querySelectorAll('.modal-overlay');
                existingModals.forEach(modal => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modalContent = modal.querySelector('.modal-content');
                    if (modalContent) {
                        modalContent.style.animation = 'popInModal 0.7s ease-out reverse';
                    }
                    setTimeout(() => modal.remove(), 500);
                });

                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                const title = type === 'success' ? 'Berhasil' : type === 'error' ? 'Kesalahan' : 'Informasi';
                overlay.innerHTML = `
                    <div class="modal-content ${type}">
                        <div class="modal-icon ${type}">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                        </div>
                        <h3>${title}</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.modal-content');
                overlay.addEventListener('click', () => closeModal(overlay, modal));
                setTimeout(() => closeModal(overlay, modal), 7000);
            }

            // Handle success modal on page load
            <?php if ($success): ?>
                showSuccessAnimation('Pengguna, rekening, dan semua data terkait telah dihapus dari sistem.<?php if (isset($saldo) && $saldo > 0): ?> Sisa saldo Rp <?php echo number_format($saldo, 0, ',', '.'); ?> akan dibayarkan.<?php endif; ?>');
            <?php endif; ?>

            // Handle error modal on page load
            <?php if ($error): ?>
                showModalAlert('<?php echo htmlspecialchars($error); ?>', 'error');
            <?php endif; ?>

            // Handle PIN not set modal on page load
            <?php if ($pin_not_set): ?>
                showModalAlert('Pengguna belum mengatur PIN. Harap atur PIN terlebih dahulu untuk dapat menutup rekening.', 'info');
            <?php endif; ?>

            // Handle warning modal for PIN verification or confirmation
            <?php if ($nasabah && !$pin_verified && !empty($nasabah['pin']) && isset($_POST['no_rekening']) && !isset($_POST['verify_pin']) && !$pin_not_set): ?>
                showModalAlert('Masukkan PIN untuk melanjutkan penghapusan rekening dan pengguna.', 'info');
            <?php elseif ($nasabah && ($pin_verified || (isset($_POST['pin_verified']) && $_POST['pin_verified'] === '1'))): ?>
                showModalAlert('Tindakan ini akan menghapus pengguna, rekening, dan semua data terkait secara permanen.<?php if ($nasabah['saldo'] > 0): ?> Sisa saldo Rp <?php echo number_format($nasabah['saldo'], 0, ',', '.'); ?> akan dibayarkan.<?php endif; ?>', 'info');
            <?php endif; ?>

            // Focus input and handle Enter key
            inputNoRek.focus();
            if (inputNoRek.value === prefix) {
                inputNoRek.setSelectionRange(prefix.length, prefix.length);
            }

            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && searchForm) searchBtn.click();
            });

            if (pinInputs.length > 0) {
                pinInputs[0].addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && pinForm) verifyPinBtn.click();
                });
            }
        });
    </script>
</body>
</html>