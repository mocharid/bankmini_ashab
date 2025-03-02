<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] == 'siswa') {
    header("Location: login.php");
    exit;
}

// Make sure $conn variable is available from db_connection.php
if (!isset($conn) && isset($koneksi)) {
    $conn = $koneksi;
} else if (!isset($conn) && !isset($koneksi)) {
    // Fallback connection if needed - adjust these credentials to match your configuration
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "bankmini";
    
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
}

// Check if transfer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: riwayat_transfer.php");
    exit;
}

$transfer_id = $_GET['id'];

// Get transfer details
$sql = "SELECT t.*, 
        r_asal.no_rekening as rekening_asal, u_asal.nama as nama_asal,
        r_tujuan.no_rekening as rekening_tujuan, u_tujuan.nama as nama_tujuan,
        u_petugas.nama as nama_petugas, u_petugas.username as username_petugas
        FROM transaksi t
        JOIN rekening r_asal ON t.rekening_id = r_asal.id
        JOIN users u_asal ON r_asal.user_id = u_asal.id
        JOIN rekening r_tujuan ON t.rekening_tujuan_id = r_tujuan.id
        JOIN users u_tujuan ON r_tujuan.user_id = u_tujuan.id
        JOIN users u_petugas ON t.petugas_id = u_petugas.id
        WHERE t.id = ? AND t.jenis_transaksi = 'transfer'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $transfer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: riwayat_transfer.php");
    exit;
}

$transfer = $result->fetch_assoc();
$stmt->close();

// Get mutations related to this transfer
$sql = "SELECT m.*, r.no_rekening, u.nama
        FROM mutasi m
        JOIN rekening r ON m.rekening_id = r.id
        JOIN users u ON r.user_id = u.id
        WHERE m.transaksi_id = ?
        ORDER BY m.created_at";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $transfer_id);
$stmt->execute();
$result = $stmt->get_result();
$mutations = [];
while ($row = $result->fetch_assoc()) {
    $mutations[] = $row;
}
$stmt->close();

// Set timezone to WIB (Western Indonesian Time)
date_default_timezone_set('Asia/Jakarta');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Transfer - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            box-shadow: var(--shadow-md);
            overflow: hidden;
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
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            text-decoration: none;
            z-index: 2;
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(-90deg);
        }

        .transaction-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 30px;
        }

        .transaction-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .card-header {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--primary-light);
            padding-bottom: 15px;
        }

        .card-header h3 {
            color: var(--primary-dark);
            font-weight: 600;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -15px;
            margin-bottom: 30px;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 15px;
        }

        h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border: 1px solid #eee;
        }

        th {
            background-color: var(--primary-light);
            font-weight: 600;
            color: var(--primary-dark);
        }

        tr:hover {
            background-color: var(--primary-light);
        }

        .text-danger {
            color: var(--danger-color);
        }

        .text-success {
            color: var(--secondary-color);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            border: none;
            transition: var(--transition);
            margin-right: 10px;
            margin-bottom: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-success {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #3d8b40;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #d32f2f;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }

        @media (max-width: 768px) {
            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 20px;
            }

            .row {
                margin-bottom: 0;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
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
            <h2><i class="fas fa-info-circle"></i> Detail Transfer</h2>
            <p>Informasi lengkap transaksi #<?= $transfer['no_transaksi'] ?></p>
            <a href="riwayat_transfer.php" class="cancel-btn" title="Kembali ke Riwayat Transfer">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <div class="transaction-card">
            <div class="card-header">
                <h3>Informasi Transfer #<?= $transfer['no_transaksi'] ?></h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4>Data Transaksi</h4>
                        <table>
                            <tr>
                                <th>No. Transaksi</th>
                                <td><?= $transfer['no_transaksi'] ?></td>
                            </tr>
                            <tr>
                                <th>Tanggal & Waktu</th>
                                <td><?= date('d/m/Y H:i:s', strtotime($transfer['created_at'])) ?> WIB</td>
                            </tr>
                            <tr>
                                <th>Jumlah Transfer</th>
                                <td>Rp <?= number_format($transfer['jumlah'], 2, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <?php if ($transfer['status'] == 'approved'): ?>
                                        <span class="badge badge-success">Berhasil</span>
                                    <?php elseif ($transfer['status'] == 'pending'): ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Ditolak</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Petugas</th>
                                <td><?= $transfer['nama_petugas'] ?> (<?= $transfer['username_petugas'] ?>)</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="col-md-6">
                        <h4>Rekening</h4>
                        <table>
                            <tr>
                                <th>Rekening Asal</th>
                                <td>
                                    <?= $transfer['rekening_asal'] ?><br>
                                    <small><?= $transfer['nama_asal'] ?></small>
                                </td>
                            </tr>
                            <tr>
                                <th>Rekening Tujuan</th>
                                <td>
                                    <?= $transfer['rekening_tujuan'] ?><br>
                                    <small><?= $transfer['nama_tujuan'] ?></small>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <h4>Mutasi Terkait</h4>
                <div class="table-responsive">
                    <table class="table-striped">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Rekening</th>
                                <th>Nama</th>
                                <th>Jumlah</th>
                                <th>Saldo Akhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mutations as $mutation): ?>
                                <tr>
                                    <td><?= date('d/m/Y H:i:s', strtotime($mutation['created_at'])) ?> WIB</td>
                                    <td><?= $mutation['no_rekening'] ?></td>
                                    <td><?= $mutation['nama'] ?></td>
                                    <td class="<?= $mutation['jumlah'] < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= $mutation['jumlah'] < 0 ? '-' : '+' ?> 
                                        Rp <?= number_format(abs($mutation['jumlah']), 2, ',', '.') ?>
                                    </td>
                                    <td>Rp <?= number_format($mutation['saldo_akhir'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                                
                <?php if ($transfer['status'] == 'pending' && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'petugas')): ?>
                <a href="proses_transfer.php?id=<?= $transfer['id'] ?>&action=approve" class="btn btn-success" 
                   onclick="return confirm('Apakah Anda yakin ingin menyetujui transfer ini?')">
                    <i class="fas fa-check"></i> Setujui
                </a>
                <a href="proses_transfer.php?id=<?= $transfer['id'] ?>&action=reject" class="btn btn-danger"
                   onclick="return confirm('Apakah Anda yakin ingin menolak transfer ini?')">
                    <i class="fas fa-times"></i> Tolak
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
</body>
</html>