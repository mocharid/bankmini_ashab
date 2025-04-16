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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $no_rekening = $_POST['no_rekening'] ?? '';
    
    if (empty($no_rekening) || $no_rekening === 'REK') {
        $error = 'Nomor rekening tidak boleh kosong.';
    } else {
        // Fetch account details
        $query = "SELECT r.id, r.saldo, r.user_id, u.nama, u.username, u.email, u.pin, u.jurusan_id, u.kelas_id, 
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
            
            // Verify PIN if provided
            if (isset($_POST['verify_pin']) && !empty($nasabah['pin'])) {
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

            // Handle account deletion
            if (isset($_POST['confirm'])) {
                $pin_verified_from_form = isset($_POST['pin_verified']) && $_POST['pin_verified'] === '1';
                if ($pin_verified || $pin_verified_from_form || empty($nasabah['pin'])) {
                    $rekening_id = $nasabah['id'];
                    $user_id = $nasabah['user_id'];

                    $conn->begin_transaction();
                    try {
                        // 1. Delete mutasi records (depends on transaksi)
                        $query = "DELETE m FROM mutasi m 
                                  JOIN transaksi t ON m.transaksi_id = t.id 
                                  WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $rekening_id, $rekening_id);
                        $stmt->execute();

                        // 2. Delete transaksi records
                        $query = "DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $rekening_id, $rekening_id);
                        $stmt->execute();

                        // 3. Handle transaksi.petugas_id (set to NULL if user is petugas)
                        $query = "UPDATE transaksi SET petugas_id = NULL WHERE petugas_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        // 4. Delete notifications
                        $query = "DELETE FROM notifications WHERE user_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        // 5. Delete active_sessions
                        $query = "DELETE FROM active_sessions WHERE user_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        // 6. Delete log_recovery_attempts (petugas_id and siswa_id)
                        $query = "DELETE FROM log_recovery_attempts WHERE petugas_id = ? OR siswa_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $user_id, $user_id);
                        $stmt->execute();

                        // 7. Delete log_aktivitas (petugas_id and siswa_id)
                        $query = "DELETE FROM log_aktivitas WHERE petugas_id = ? OR siswa_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("ii", $user_id, $user_id);
                        $stmt->execute();

                        // 8. Delete rekening
                        $query = "DELETE FROM rekening WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $rekening_id);
                        $stmt->execute();

                        // 9. Delete user
                        $query = "DELETE FROM users WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();

                        // Optional: Log deletion for audit
                        $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, alasan, waktu) 
                                      VALUES (?, ?, ?, ?, NOW())";
                        $stmt = $conn->prepare($log_query);
                        $action = "delete_account";
                        $admin_id = $_SESSION['user_id'];
                        $reason = "Account deleted with no_rekening: $no_rekening";
                        $stmt->bind_param("iiss", $admin_id, $user_id, $action, $reason);
                        $stmt->execute();

                        $conn->commit();
                        $success = true;
                        $nasabah = null;
                        $pin_verified = false;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Gagal menghapus akun: ' . $e->getMessage();
                        error_log("Account deletion error: " . $e->getMessage());
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
    <title>Hapus Akun - SCHOBANK SYSTEM</title>
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
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            -webkit-text-size-adjust: none;
            zoom: 1;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
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
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
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
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .deposit-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .deposit-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .deposit-form {
            display: grid;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="tel"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
        }

        input[type="tel"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
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
            font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            border: 2px solid #ddd;
            border-radius: 50%;
            transition: var(--transition);
            -webkit-text-size-adjust: none;
            background-color: #fff;
            color: var(--text-primary);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            width: fit-content;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            transform: scale(0.95);
        }

        .btn-danger {
            background-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #d32f2f;
        }

        .results-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
            animation: slideStep 0.5s ease-in-out;
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
            gap: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
            text-align: left;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: left;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 5px solid #34d399;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .alert-warning {
            background-color: #fff3e0;
            color: #e65100;
            border-left: 5px solid #ff9800;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeInModal 0.3s ease-in;
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: var(--shadow-md);
            width: 90%;
            max-width: 500px;
            position: relative;
            text-align: center;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: clamp(1.3rem, 2.5vw, 1.5rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            padding: 25px;
            margin-bottom: 20px;
        }

        .modal-body i {
            font-size: 48px;
            color: #34d399;
            margin-bottom: 15px;
        }

        .modal-body h3 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .modal-body p {
            font-size: clamp(0.9rem, 2vw, 1rem);
            color: var(--text-secondary);
        }

        .modal-footer {
            padding: 0 25px 25px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading span {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
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
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
                margin-bottom: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .deposit-card,
            .results-card {
                padding: 20px;
                margin-bottom: 20px;
            }

            .deposit-form {
                gap: 15px;
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            input[type="tel"] {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .pin-input {
                width: 42px;
                height: 42px;
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            button {
                padding: 10px 20px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .modal-content {
                width: 95%;
                padding: 0;
            }

            .modal-header {
                padding: 15px;
            }

            .modal-header h3 {
                font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            }

            .modal-body {
                padding: 20px;
            }

            .modal-body h3 {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .modal-body p {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .modal-footer {
                padding: 0 20px 20px;
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                margin-top: 15px;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(0.9rem, 2.5vw, 1.1rem);
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

            .deposit-card,
            .results-card {
                padding: 15px;
                margin-bottom: 15px;
            }

            .deposit-form {
                gap: 12px;
            }

            .section-title {
                font-size: clamp(0.9rem, 2.5vw, 1rem);
            }

            input[type="tel"] {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .pin-input {
                width: 36px;
                height: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            button {
                padding: 8px 15px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .detail-row {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .modal-header {
                padding: 12px;
            }

            .modal-header h3 {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .modal-body {
                padding: 15px;
            }

            .modal-body h3 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .modal-body p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .modal-footer {
                padding: 0 15px 15px;
            }

            .alert {
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
                margin-top: 12px;
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
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-trash-alt"></i> Hapus Akun</h2>
            <p>Hapus akun dan semua data terkait secara permanen</p>
        </div>

        <div id="alertContainer">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
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

        <?php if ($nasabah !== null): ?>
            <div class="results-card">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
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
                    <div class="detail-value">Rp <?php echo number_format($nasabah['saldo'], 0, ',', '.'); ?></div>
                </div>

                <?php if (!$pin_verified && !isset($_POST['confirm'])): ?>
                    <?php if (!empty($nasabah['pin'])): ?>
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
                            <button type="submit" name="verify_pin" id="verifyPinBtn">
                                <i class="fas fa-check"></i>
                                <span>Verifikasi PIN</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>PERHATIAN: Tindakan ini akan menghapus akun, semua data pengguna, riwayat transaksi, dan log aktivitas secara permanen.</span>
                        </div>
                        <form id="closeAccountForm" action="" method="POST" class="deposit-form">
                            <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($nasabah['no_rekening']); ?>">
                            <input type="hidden" name="pin_verified" value="1">
                            <button type="submit" name="confirm" class="btn-danger" id="confirmBtn">
                                <i class="fas fa-trash-alt"></i>
                                <span>Konfirmasi Penghapusan</span>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($pin_verified || (isset($_POST['pin_verified']) && $_POST['pin_verified'] === '1')): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>PERHATIAN: Tindakan ini akan menghapus akun, semua data pengguna, riwayat transaksi, dan log aktivitas secara permanen.</span>
                    </div>
                    <form id="closeAccountForm" action="" method="POST" class="deposit-form">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($nasabah['no_rekening']); ?>">
                        <input type="hidden" name="pin_verified" value="1">
                        <button type="submit" name="confirm" class="btn-danger" id="confirmBtn">
                            <i class="fas fa-trash-alt"></i>
                            <span>Konfirmasi Penghapusan</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div id="successModal" class="modal<?php if ($success) echo ' show'; ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-check-circle"></i> Penutupan Akun Berhasil</h3>
                </div>
                <div class="modal-body">
                    <i class="fas fa-check-circle"></i>
                    <h3>Akun Berhasil Dihapus</h3>
                    <p>Akun, data pengguna, riwayat transaksi, dan semua data terkait telah dihapus sepenuhnya dari sistem.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" id="modalCloseBtn">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
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
            const alertContainer = document.getElementById('alertContainer');
            const modal = document.getElementById('successModal');
            const modalCloseBtn = document.getElementById('modalCloseBtn');
            const prefix = "REK";

            // Initialize account number input with prefix
            if (!inputNoRek.value) {
                inputNoRek.value = prefix;
            }

            // Restrict account number to numbers only
            inputNoRek.addEventListener('input', function(e) {
                let value = this.value;
                if (!value.startsWith(prefix)) {
                    this.value = prefix + value.replace(prefix, '');
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '');
                this.value = prefix + userInput;
            });

            inputNoRek.addEventListener('keydown', function(e) {
                let cursorPos = this.selectionStart;
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                }
            });

            inputNoRek.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                let currentValue = this.value.slice(prefix.length);
                let newValue = prefix + (currentValue + pastedData);
                this.value = newValue;
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
                            } else {
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
                        showAlert('Silakan masukkan nomor rekening lengkap', 'error');
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
                        showAlert('PIN harus 6 digit', 'error');
                        pinInputs.forEach(input => {
                            if (!input.value) {
                                input.classList.add('form-error');
                                setTimeout(() => input.classList.remove('form-error'), 400);
                            }
                        });
                        pinInputs[pinValues.findIndex(val => !val) || 0].focus();
                        return;
                    }
                    verifyPinBtn.classList.add('btn-loading');
                    setTimeout(() => {
                        verifyPinBtn.classList.remove('btn-loading');
                    }, 800);
                });
            }

            if (closeAccountForm) {
                closeAccountForm.addEventListener('submit', function(e) {
                    if (!confirm('PERINGATAN: Anda akan menghapus akun ini secara permanen, termasuk semua data pengguna, riwayat transaksi, dan log aktivitas. Apakah Anda yakin ingin melanjutkan?')) {
                        e.preventDefault();
                        return;
                    }
                    confirmBtn.classList.add('btn-loading');
                    setTimeout(() => {
                        confirmBtn.classList.remove('btn-loading');
                    }, 800);
                });
            }

            // Alert handling
            function showAlert(message, type) {
                const existingAlerts = document.querySelectorAll('.alert');
                existingAlerts.forEach(alert => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                let icon = 'info-circle';
                if (type === 'success') icon = 'check-circle';
                if (type === 'error') icon = 'exclamation-circle';
                if (type === 'warning') icon = 'exclamation-triangle';
                alertDiv.innerHTML = `
                    <i class="fas fa-${icon}"></i>
                    <span>${message}</span>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => {
                    alertDiv.classList.add('hide');
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
            }

            // Auto-hide existing alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Modal handling
            if (modal && modal.classList.contains('show')) {
                document.body.style.overflow = 'hidden';
                setTimeout(() => {
                    modal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                    window.location.href = 'dashboard.php';
                }, 5000);
                window.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        modal.classList.remove('show');
                        document.body.style.overflow = 'auto';
                        window.location.href = 'dashboard.php';
                    }
                });
                if (modalCloseBtn) {
                    modalCloseBtn.addEventListener('click', function() {
                        modal.classList.remove('show');
                        document.body.style.overflow = 'auto';
                        window.location.href = 'dashboard.php';
                    });
                }
            }

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