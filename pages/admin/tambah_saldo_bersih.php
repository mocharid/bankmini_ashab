<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

$admin_id = $_SESSION['user_id'] ?? 0;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $petugas_id = $_POST['petugas_id'] ?? '';
    $jumlah = str_replace(['.', ''], '', $_POST['jumlah'] ?? ''); // Remove formatting

    // Validasi input
    if (empty($petugas_id) || empty($jumlah)) {
        $error = "Semua kolom harus diisi!";
    } elseif (!is_numeric($jumlah) || $jumlah <= 0) {
        $error = "Jumlah transfer harus angka positif!";
    } else {
        // Cek apakah petugas valid
        $query = "SELECT id FROM users WHERE id = ? AND role = 'petugas'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $error = "Petugas tidak valid!";
        } else {
            // Cek saldo cukup
            $query_saldo = "SELECT SUM(net_setoran) as total_saldo
                            FROM (
                                SELECT DATE(created_at) as tanggal,
                                       SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                                       SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                                FROM transaksi
                                WHERE status = 'approved' AND DATE(created_at) < CURDATE()
                                GROUP BY DATE(created_at)
                            ) as daily_net";
            $result_saldo = $conn->query($query_saldo);
            $total_saldo = $result_saldo->fetch_assoc()['total_saldo'] ?? 0;

            if ($jumlah > $total_saldo) {
                $error = "Saldo tidak cukup untuk transfer!";
            } else {
                // Simpan transfer
                $query = "INSERT INTO saldo_transfers (admin_id, petugas_id, jumlah, tanggal) VALUES (?, ?, ?, CURDATE())";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("iii", $admin_id, $petugas_id, $jumlah);

                if ($stmt->execute()) {
                    $success = "Transfer saldo berhasil!";
                } else {
                    $error = "Gagal menyimpan transfer: " . $conn->error;
                }
            }
        }
    }
}

// Ambil daftar petugas
$query_petugas = "SELECT id, nama FROM users WHERE role = 'petugas' ORDER BY nama ASC";
$result_petugas = $conn->query($query_petugas);
$petugas_list = $result_petugas->fetch_all(MYSQLI_ASSOC);

// Ambil riwayat transfer
$query_transfers = "SELECT st.id, st.jumlah, st.tanggal, st.created_at, 
                           admin.nama as admin_nama, petugas.nama as petugas_nama
                    FROM saldo_transfers st
                    JOIN users admin ON st.admin_id = admin.id
                    JOIN users petugas ON st.petugas_id = petugas.id
                    ORDER BY st.created_at DESC";
$result_transfers = $conn->query($query_transfers);

// Fungsi format tanggal Indonesia
function formatTanggalIndonesia($date) {
    $bulan = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $bulan[$dateObj->format('F')];
    $year = $dateObj->format('Y');
    return "$day $month $year";
}

// Fungsi nama hari Indonesia
function getHariIndonesia($date) {
    $hari = date('l', strtotime($date));
    $hariIndo = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    return $hariIndo[$hari];
}

// Fungsi format Rupiah
function formatRupiah($jumlah) {
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transfer Saldo ke Petugas - SCHOBANK SYSTEM</title>
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
            font-size: clamp(0.9rem, 2vw, 1rem);
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

        .welcome-banner .date {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        select, input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        select:focus, input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        input[type="text"] {
            -webkit-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }

        .btn {
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
            width: auto;
            max-width: 200px;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: var(--text-secondary);
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
        }

        .button-group {
            display: flex;
            gap: 15px;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 5px solid var(--primary-color);
        }

        .transfer-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .transfer-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .transfer-list h3 {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            min-width: 80px;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        tr:hover {
            background-color: #f8faff;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(2rem, 4vw, 2.5rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .no-data p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .success-overlay {
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

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--secondary-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            animation: slideUpText 0.5s ease-out 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.5;
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

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner .date {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .form-card, .transfer-list {
                padding: 20px;
            }

            .btn {
                width: 100%;
                max-width: none;
                justify-content: center;
            }

            .button-group {
                flex-direction: column;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .transfer-list h3 {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .no-data i {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .success-modal h3 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2><i class="fas fa-hand-holding-usd"></i> Transfer Saldo ke Petugas</h2>
            <div class="date">
                <i class="far fa-calendar-alt"></i>
                <?= getHariIndonesia(date('Y-m-d')) . ', ' . formatTanggalIndonesia(date('Y-m-d')) ?>
            </div>
        </div>

        <!-- Alerts -->
        <div id="alertContainer">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="transferForm">
                <div class="form-group">
                    <label for="petugas_id">Pilih Petugas</label>
                    <select name="petugas_id" id="petugas_id" required>
                        <option value="">-- Pilih Petugas --</option>
                        <?php foreach ($petugas_list as $petugas): ?>
                            <option value="<?= $petugas['id'] ?>"><?= htmlspecialchars($petugas['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="jumlah">Jumlah Transfer (Rp)</label>
                    <input type="text" name="jumlah" id="jumlah" placeholder="Contoh: 10.000" inputmode="numeric" required>
                </div>
                <div class="button-group">
                    <button type="submit" class="btn" id="submitBtn">
                        <i class="fas fa-paper-plane"></i>
                        Kirim Transfer
                    </button>
                </div>
            </form>
        </div>

        <!-- Transfer History List -->
        <div class="transfer-list">
            <h3><i class="fas fa-list"></i> Riwayat Transfer Saldo</h3>
            <?php if ($result_transfers && $result_transfers->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Admin</th>
                            <th>Petugas</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        while ($row = $result_transfers->fetch_assoc()): 
                        ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= formatTanggalIndonesia($row['tanggal']) ?></td>
                                <td><?= htmlspecialchars($row['admin_nama']) ?></td>
                                <td><?= htmlspecialchars($row['petugas_nama']) ?></td>
                                <td><?= formatRupiah($row['jumlah']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>Belum ada riwayat transfer saldo</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Success Modal -->
        <?php if ($success): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Saldo telah berhasil ditransfer ke petugas!</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Format jumlah input
            const jumlahInput = document.getElementById('jumlah');
            jumlahInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value) {
                    value = parseInt(value).toLocaleString('id-ID');
                }
                e.target.value = value;
            });

            // Form submission with loading state
            const form = document.getElementById('transferForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (form && submitBtn) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                    setTimeout(() => {
                        form.submit();
                    }, 1000);
                });
            }

            // Handle success modal
            const successModal = document.querySelector('#successModal .success-modal');
            if (successModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    successModal.appendChild(confetti);
                }
                setTimeout(() => {
                    const overlay = successModal.closest('.success-overlay');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        overlay.remove();
                        window.location.href = 'tambah_saldo_bersih.php';
                    }, 500);
                }, 2000);
            }

            // Handle alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Show alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = document.querySelectorAll('.alert');
                existingAlerts.forEach(alert => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                let icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
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
        });

        // Prevent pinch zooming
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-tap zooming
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        // Prevent zoom on wheel with ctrl
        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-click zooming
        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });
    </script>
</body>
</html>