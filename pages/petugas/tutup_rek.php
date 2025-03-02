<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$error = ''; // Variabel untuk menyimpan pesan error
$success = ''; // Variabel untuk menyimpan pesan sukses
$nasabah = null; // Variabel untuk menyimpan data nasabah

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $no_rekening = $_POST['no_rekening'];

    // Validasi input
    if (empty($no_rekening)) {
        $error = 'Nomor rekening tidak boleh kosong.';
    } else {
        // Cek apakah rekening ada - query disesuaikan dengan struktur database yang benar
        $query = "SELECT r.id, r.saldo, r.user_id, u.nama, u.username 
                 FROM rekening r 
                 JOIN users u ON r.user_id = u.id 
                 WHERE r.no_rekening = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $nasabah = $result->fetch_assoc();

            // Jika tombol konfirmasi ditekan
            if (isset($_POST['confirm'])) {
                $rekening_id = $nasabah['id'];
                $user_id = $nasabah['user_id'];

                // Mulai transaksi database
                $conn->begin_transaction();

                try {
                    // Hapus semua mutasi terkait rekening
                    $query = "DELETE FROM mutasi WHERE rekening_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $rekening_id);
                    $stmt->execute();
                    
                    // Hapus semua transaksi terkait rekening
                    $query = "DELETE FROM transaksi WHERE rekening_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $rekening_id);
                    $stmt->execute();

                    // Hapus rekening
                    $query = "DELETE FROM rekening WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $rekening_id);
                    $stmt->execute();

                    // Hapus user (siswa) dari database
                    $query = "DELETE FROM users WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();

                    // Commit transaksi
                    $conn->commit();

                    $success = 'Rekening berhasil ditutup dan semua data nasabah berhasil dihapus dari sistem.';
                    $nasabah = null; // Reset data nasabah setelah rekening ditutup
                } catch (Exception $e) {
                    // Rollback transaksi jika terjadi error
                    $conn->rollback();
                    $error = 'Terjadi kesalahan saat menutup rekening: ' . $e->getMessage();
                }
            }
        } else {
            $error = 'Nomor rekening tidak ditemukan.';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Tutup Rekening - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
            background: 
                linear-gradient(45deg, #f0f5ff 25%, transparent 25%) -50px 0,
                linear-gradient(-45deg, #f0f5ff 25%, transparent 25%) -50px 0,
                linear-gradient(45deg, transparent 75%, #f0f5ff 75%),
                linear-gradient(-45deg, transparent 75%, #f0f5ff 75%);
            background-size: 100px 100px;
            background-color: #e6eeff;
        }

        /* Geometric overlay pattern */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.3;
            background: 
                linear-gradient(30deg, #0a2e5c 12%, transparent 12.5%, transparent 87%, #0a2e5c 87.5%, #0a2e5c),
                linear-gradient(150deg, #0a2e5c 12%, transparent 12.5%, transparent 87%, #0a2e5c 87.5%, #0a2e5c),
                linear-gradient(30deg, #0a2e5c 12%, transparent 12.5%, transparent 87%, #0a2e5c 87.5%, #0a2e5c),
                linear-gradient(150deg, #0a2e5c 12%, transparent 12.5%, transparent 87%, #0a2e5c 87.5%, #0a2e5c),
                linear-gradient(60deg, #154785 25%, transparent 25.5%, transparent 75%, #154785 75%, #154785);
            background-size: 80px 140px;
            background-position: 0 0, 0 0, 40px 70px, 40px 70px, 0 0;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .welcome-banner {
            background: linear-gradient(135deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            position: relative;
            box-shadow: 0 4px 15px rgba(10, 46, 92, 0.2);
        }

        .welcome-banner h2 {
            font-size: 24px;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
            backdrop-filter: blur(5px);
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        form {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        input[type="text"]:focus {
            border-color: #0a2e5c;
            box-shadow: 0 0 5px rgba(10, 46, 92, 0.2);
            outline: none;
        }

        button {
            background-color: #0a2e5c;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(10, 46, 92, 0.2);
        }

        button:hover {
            background-color: #154785;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(10, 46, 92, 0.3);
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 14px;
            position: relative;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background-color: rgba(220, 252, 231, 0.95);
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background-color: rgba(254, 226, 226, 0.95);
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: inherit;
            transition: transform 0.3s ease;
        }

        .close-btn:hover {
            transform: scale(1.1);
        }

        .nasabah-detail {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .nasabah-detail h3 {
            margin-bottom: 15px;
            color: #0a2e5c;
            font-size: 20px;
            border-bottom: 2px solid #0a2e5c;
            padding-bottom: 10px;
        }

        .nasabah-detail p {
            margin-bottom: 12px;
            font-size: 15px;
            line-height: 1.6;
        }

        .nasabah-detail form {
            box-shadow: none;
            padding: 0;
            margin-top: 20px;
            background: none;
            border: none;
        }

        .nasabah-detail button {
            background-color: #dc2626;
            width: 100%;
            margin-top: 15px;
        }

        .nasabah-detail button:hover {
            background-color: #b91c1c;
        }

        .warning-text {
            color: #dc2626;
            font-weight: bold;
            margin-bottom: 15px;
            padding: 15px;
            background-color: rgba(254, 226, 226, 0.5);
            border-radius: 5px;
            border-left: 4px solid #dc2626;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: 20px;
            }

            .nasabah-detail {
                padding: 20px;
            }

            button {
                padding: 10px 20px;
            }
        }
    </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>
    
    <div class="main-content">
        <div class="welcome-banner">
            <h2>Tutup Rekening</h2>
            <a href="dashboard.php" class="cancel-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
                <button class="close-btn" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
                <button class="close-btn" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <form action="" method="POST">
            <label for="no_rekening">Nomor Rekening:</label>
            <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan nomor rekening" required>
            <button type="submit">Tampilkan Detail Nasabah</button>
        </form>

        <?php if ($nasabah): ?>
            <div class="nasabah-detail">
                <h3>Detail Nasabah</h3>
                <p><strong>Nama:</strong> <?php echo htmlspecialchars($nasabah['nama']); ?></p>
                <p><strong>Username:</strong> <?php echo htmlspecialchars($nasabah['username']); ?></p>
                <p><strong>Saldo:</strong> Rp <?php echo number_format($nasabah['saldo'], 0, ',', '.'); ?></p>
                <p><strong>Saldo yang harus dibayar:</strong> Rp <?php echo number_format($nasabah['saldo'], 0, ',', '.'); ?></p>

                <div class="warning-text">
                    <p>PERHATIAN: Tindakan ini akan menghapus semua data nasabah termasuk username, password, dan riwayat transaksi secara permanen.</p>
                </div>

                <form action="" method="POST" onsubmit="return confirm('PERINGATAN: Anda akan menghapus SELURUH data nasabah ini termasuk username, password, dan semua riwayat transaksi. Data yang dihapus TIDAK DAPAT DIPULIHKAN. Apakah Anda yakin ingin melanjutkan?');">
                    <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($no_rekening); ?>">
                    <button type="submit" name="confirm">Konfirmasi Tutup Rekening</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Hilangkan notifikasi setelah 8 detik
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => alert.remove());
        }, 8000);
    </script>
</body>
</html>