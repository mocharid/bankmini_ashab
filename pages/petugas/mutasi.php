<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get rekening selection for admin/petugas
$rekening_list = [];
if ($role == 'admin' || $role == 'petugas') {
    $sql = "SELECT r.*, u.nama FROM rekening r JOIN users u ON r.user_id = u.id ORDER BY u.nama";
    $result = $koneksi->query($sql);
    while ($row = $result->fetch_assoc()) {
        $rekening_list[] = $row;
    }
}

// Get default rekening for siswa
$default_rekening = null;
if ($role == 'siswa') {
    $sql = "SELECT r.* FROM rekening r WHERE r.user_id = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $default_rekening = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Get selected rekening
$rekening_id = null;
if (isset($_GET['rekening_id']) && !empty($_GET['rekening_id'])) {
    $rekening_id = $_GET['rekening_id'];
} elseif ($default_rekening) {
    $rekening_id = $default_rekening['id'];
}

// Get date range
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Check if user has permission to view this rekening
if ($role == 'siswa' && $rekening_id != $default_rekening['id']) {
    header("Location: dashboard.php");
    exit;
}

// Get mutasi data
$mutasi = [];
if ($rekening_id) {
    $sql = "SELECT m.*, t.no_transaksi, t.jenis_transaksi, 
           t.rekening_tujuan_id, r_tujuan.no_rekening as rekening_tujuan, u_tujuan.nama as nama_tujuan,
           t.rekening_id as rekening_asal_id, r_asal.no_rekening as rekening_asal, u_asal.nama as nama_asal
           FROM mutasi m
           JOIN transaksi t ON m.transaksi_id = t.id
           LEFT JOIN rekening r_tujuan ON t.rekening_tujuan_id = r_tujuan.id
           LEFT JOIN users u_tujuan ON r_tujuan.user_id = u_tujuan.id
           LEFT JOIN rekening r_asal ON t.rekening_id = r_asal.id
           LEFT JOIN users u_asal ON r_asal.user_id = u_asal.id
           WHERE m.rekening_id = ? AND DATE(m.created_at) BETWEEN ? AND ?
           ORDER BY m.created_at DESC";
    
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("iss", $rekening_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $mutasi[] = $row;
    }
    $stmt->close();
    
    // Get rekening details
    $sql = "SELECT r.*, u.nama FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.id = ?";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("i", $rekening_id);
    $stmt->execute();
    $rekening = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mutasi Rekening - SCHOBANK SYSTEM</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <h2><i class="fas fa-list-alt"></i> Mutasi Rekening</h2>
        
        <div class="card">
            <div class="card-header">
                <h3>Filter Mutasi</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <?php if ($role == 'admin' || $role == 'petugas'): ?>
                        <div class="form-group">
                            <label>Pilih Rekening:</label>
                            <select name="rekening_id" class="form-control" required>
                                <option value="">-- Pilih Rekening --</option>
                                <?php foreach ($rekening_list as $rek): ?>
                                    <option value="<?= $rek['id'] ?>" <?= ($rekening_id == $rek['id']) ? 'selected' : '' ?>>
                                        <?= $rek['no_rekening'] ?> - <?= $rek['nama'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="rekening_id" value="<?= $default_rekening['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tanggal Mulai:</label>
                                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tanggal Akhir:</label>
                                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Tampilkan
                    </button>
                </form>
            </div>
        </div>
        
        <?php if ($rekening_id): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h3>Informasi Rekening</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>No. Rekening:</strong> <?= $rekening['no_rekening'] ?></p>
                            <p><strong>Nama Pemilik:</strong> <?= $rekening['nama'] ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Saldo:</strong> Rp <?= number_format($rekening['saldo'], 2, ',', '.') ?></p>
                            <p><strong>Tanggal Dibuat:</strong> <?= date('d/m/Y', strtotime($rekening['created_at'])) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <h3>Mutasi Rekening</h3>
                    <p class="period">Periode: <?= date('d/m/Y', strtotime($start_date)) ?> s/d <?= date('d/m/Y', strtotime($end_date)) ?></p>
                </div>
                <div class="card-body">
                    <?php if (empty($mutasi)): ?>
                        <p class="text-center">Tidak ada data mutasi pada periode ini.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>No. Transaksi</th>
                                        <th>Jenis</th>
                                        <th>Keterangan</th>
                                        <th>Debit</th>
                                        <th>Kredit</th>
                                        <th>Saldo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mutasi as $m): ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                                            <td><?= $m['no_transaksi'] ?></td>
                                            <td>
                                                <?php if ($m['jenis_transaksi'] == 'setor'): ?>
                                                    <span class="badge badge-success">Setor Tunai</span>
                                                <?php elseif ($m['jenis_transaksi'] == 'tarik'): ?>
                                                    <span class="badge badge-danger">Tarik Tunai</span>
                                                <?php elseif ($m['jenis_transaksi'] == 'transfer'): ?>
                                                    <?php if ($m['jumlah'] > 0): ?>
                                                        <span class="badge badge-info">Transfer Masuk</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Transfer Keluar</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($m['jenis_transaksi'] == 'transfer'): ?>
                                                    <?php if ($m['jumlah'] > 0): ?>
                                                        Transfer dari <?= $m['rekening_asal'] ?> (<?= $m['nama_asal'] ?>)
                                                    <?php else: ?>
                                                        Transfer ke <?= $m['rekening_tujuan'] ?> (<?= $m['nama_tujuan'] ?>)
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?= ucfirst($m['jenis_transaksi']) ?> Tunai
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($m['jumlah'] > 0): ?>
                                                    Rp <?= number_format($m['jumlah'], 2, ',', '.') ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($m['jumlah'] < 0): ?>
                                                    Rp <?= number_format(abs($m['jumlah']), 2, ',', '.') ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>Rp <?= number_format($m['saldo_akhir'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                    <button onclick="window.print()" class="btn btn-secondary mt-3">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="js/script.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
</body>
</html>