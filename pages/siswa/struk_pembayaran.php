<?php
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
// DETECT BASE URL
// ============================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));

$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header("Location: " . $base_url . "/pages/login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

// Get User Data with student profile
$query = "SELECT u.nama, u.email, r.no_rekening,
          sp.nis_nisn, k.nama_kelas, tk.nama_tingkatan, j.nama_jurusan
          FROM users u
          JOIN rekening r ON u.id = r.user_id
          LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
          LEFT JOIN kelas k ON sp.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          LEFT JOIN jurusan j ON sp.jurusan_id = j.id
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data) {
    header("Location: dashboard.php");
    exit();
}

$no_transaksi = $_GET['no_transaksi'] ?? null;
if (!$no_transaksi) {
    header("Location: pembayaran.php");
    exit();
}

// Fetch Transaction Details with pembayaran_tagihan info
$query = "SELECT t.*, 
                 r1.no_rekening AS rekening_asal, u1.nama AS nama_pengirim,
                 r2.no_rekening AS rekening_tujuan, u2.nama AS nama_penerima,
                 pt.tanggal_bayar, pt.no_pembayaran,
                 ptg.no_tagihan, ptg.tanggal_jatuh_tempo,
                 pi.nama_item
          FROM transaksi t
          LEFT JOIN rekening r1 ON t.rekening_id = r1.id
          LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
          LEFT JOIN users u1 ON r1.user_id = u1.id
          LEFT JOIN users u2 ON r2.user_id = u2.id
          LEFT JOIN pembayaran_transaksi pt ON t.no_transaksi = pt.no_pembayaran
          LEFT JOIN pembayaran_tagihan ptg ON pt.tagihan_id = ptg.id
          LEFT JOIN pembayaran_item pi ON ptg.item_id = pi.id
          WHERE t.no_transaksi = ? AND (r1.user_id = ? OR r2.user_id = ?)";
$stmt = $conn->prepare($query);
$stmt->bind_param("sii", $no_transaksi, $user_id, $user_id);
$stmt->execute();
$transaksi = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaksi) {
    header("Location: pembayaran.php");
    exit();
}

// Formatters
function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, ',', '.') . ',00';
}

function formatDate($date)
{
    $months = [
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
    $d = new DateTime($date);
    return $d->format('d') . ' ' . $months[(int) $d->format('m')] . ' ' . $d->format('Y');
}

function formatDateTime($date)
{
    $months = [
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
    $d = new DateTime($date);
    return $d->format('d') . ' ' . $months[(int) $d->format('m')] . ' ' . $d->format('Y') . ', ' . $d->format('H:i') . ' WIB';
}

// Parse invoice description
$invoice_desc = 'Pembayaran Tagihan';
if (!empty($transaksi['nama_item'])) {
    $invoice_desc = $transaksi['nama_item'];
} elseif (!empty($transaksi['keterangan'])) {
    $keterangan = $transaksi['keterangan'];
    if (strpos($keterangan, 'Pembayaran Tagihan ') === 0) {
        $invoice_desc = substr($keterangan, 19);
        // Remove " Via KASDIG" if present
        $invoice_desc = str_replace(' Via KASDIG', '', $invoice_desc);
    } elseif (strpos($keterangan, 'Pembayaran ') === 0) {
        $invoice_desc = substr($keterangan, 11);
    }
}

// Get kelas info
$kelas_display = '';
if (!empty($user_data['nama_tingkatan']) && !empty($user_data['nama_kelas'])) {
    $kelas_display = $user_data['nama_tingkatan'] . ' ' . $user_data['nama_kelas'];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <title>Bukti Pembayaran - <?php echo htmlspecialchars($no_transaksi); ?></title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --success: #10b981;
            --text-dark: #1f2937;
            --text-gray: #6b7280;
            --text-light: #9ca3af;
            --bg-light: #f8fafc;
            --white: #ffffff;
            --border: #e5e7eb;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Check icon animation */
        @keyframes pulse-check {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.15);
                opacity: 0.8;
            }
        }

        .check-icon-animated {
            color: var(--primary);
            font-size: 48px;
            animation: pulse-check 2s ease-in-out infinite;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .invoice-wrapper {
            max-width: 800px;
            margin: 0 auto;
        }

        .invoice-card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* Header Section */
        .invoice-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 30px;
            position: relative;
        }

        .invoice-header::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 0;
            right: 0;
            height: 40px;
            background: var(--white);
            border-radius: 20px 20px 0 0;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }

        .school-info {
            flex: 1;
            min-width: 200px;
        }

        .school-logo {
            height: 70px;
            max-width: 200px;
            margin-bottom: 10px;
            object-fit: contain;
        }

        .school-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .school-address {
            font-size: 12px;
            opacity: 0.8;
            line-height: 1.5;
        }

        .invoice-badge {
            padding: 12px 20px;
            text-align: center;
        }

        .badge-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-bottom: 4px;
        }

        .badge-value {
            font-size: 14px;
            font-weight: 700;
        }

        /* Status Badge */
        .status-section {
            text-align: center;
            padding: 20px 30px 0;
            position: relative;
            z-index: 1;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .status-badge i {
            font-size: 16px;
        }

        /* Content Section */
        .invoice-content {
            padding: 30px;
        }

        .invoice-title {
            text-align: center;
            margin-bottom: 30px;
        }

        .invoice-title h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        .invoice-title p {
            font-size: 13px;
            color: var(--text-gray);
        }

        /* Info Grid */
        .info-section {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-box {
            background: var(--bg-light);
            border-radius: 12px;
            padding: 16px;
        }

        .info-box-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .info-box-content {
            font-size: 14px;
            color: var(--text-dark);
            font-weight: 500;
        }

        .info-box-content.large {
            font-size: 16px;
            font-weight: 700;
        }

        .info-box-sub {
            font-size: 12px;
            color: var(--text-gray);
            margin-top: 4px;
        }

        /* Details Table */
        .details-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--primary);
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table th,
        .details-table td {
            padding: 12px 16px;
            text-align: left;
            font-size: 13px;
        }

        .details-table th {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }

        .details-table th:first-child {
            border-radius: 8px 0 0 8px;
        }

        .details-table th:last-child {
            border-radius: 0 8px 8px 0;
            text-align: right;
        }

        .details-table td {
            background: var(--bg-light);
            color: var(--text-dark);
            border-bottom: 2px solid var(--white);
        }

        .details-table td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .details-table tbody tr:last-child td:first-child {
            border-radius: 0 0 0 8px;
        }

        .details-table tbody tr:last-child td:last-child {
            border-radius: 0 0 8px 0;
        }

        /* Total Section */
        .total-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: rgba(255, 255, 255, 0.8);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .total-row.grand {
            color: white;
            font-size: 16px;
            font-weight: 700;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 0;
        }

        .total-amount {
            font-size: 24px;
            font-weight: 800;
        }

        /* Student Info */
        .student-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .student-row {
            display: flex;
            font-size: 13px;
        }

        .student-label {
            color: var(--text-gray);
            width: 100px;
            flex-shrink: 0;
        }

        .student-value {
            color: var(--text-dark);
            font-weight: 500;
        }

        /* QR Code Section */
        .qr-section {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed var(--border);
            margin-bottom: 20px;
        }

        .qr-code {
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 6px;
            background: white;
            display: inline-block;
        }

        .qr-code img {
            display: block;
        }

        .qr-label {
            font-size: 10px;
            color: var(--text-gray);
            text-align: center;
            margin-top: 8px;
        }

        .qr-note {
            font-size: 11px;
            color: var(--text-gray);
            text-align: center;
            margin-top: 15px;
            line-height: 1.6;
        }

        .qr-note strong {
            color: var(--text-dark);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
        }

        .btn {
            flex: 1;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-secondary {
            background: var(--bg-light);
            color: var(--text-dark);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(44, 62, 80, 0.3);
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .invoice-wrapper {
                max-width: none;
            }

            .invoice-card {
                box-shadow: none;
                border-radius: 0;
            }

            .action-buttons {
                display: none !important;
            }

            .invoice-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .total-section {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* Responsive */
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .invoice-header {
                padding: 20px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .invoice-badge {
                width: 100%;
                margin-top: 15px;
            }

            .invoice-content {
                padding: 20px;
            }

            .info-section {
                grid-template-columns: 1fr;
            }

            .student-info {
                grid-template-columns: 1fr;
            }

            .student-row {
                flex-direction: column;
                gap: 2px;
            }

            .student-label {
                width: auto;
            }

            .invoice-title h1 {
                font-size: 20px;
            }

            .total-amount {
                font-size: 20px;
            }

            .details-table th,
            .details-table td {
                padding: 10px 12px;
                font-size: 12px;
            }

            .qr-section {
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <div class="invoice-wrapper">
        <div class="invoice-card">
            <!-- Content -->
            <div class="invoice-content" style="padding-top: 30px;">
                <div class="invoice-title">
                    <div
                        style="display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 10px;">
                        <i class="fas fa-check-circle check-icon-animated"></i>
                    </div>
                    <h1>BUKTI PEMBAYARAN</h1>
                    <p>Diterbitkan pada <?php echo formatDateTime($transaksi['created_at']); ?></p>
                </div>

                <!-- Info Boxes -->
                <div class="info-section">
                    <div class="info-box">
                        <div class="info-box-title">No. Pembayaran</div>
                        <div class="info-box-content large"><?php echo htmlspecialchars($no_transaksi); ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-title">Jenis Tagihan</div>
                        <div class="info-box-content large"><?php echo htmlspecialchars($invoice_desc); ?></div>
                    </div>
                </div>

                <!-- Student Info -->
                <div class="section-title">
                    <i class="fas fa-user-graduate"></i>
                    Data Siswa
                </div>
                <div class="student-info">
                    <div class="student-row">
                        <span class="student-label">Nama</span>
                        <span class="student-value"><?php echo htmlspecialchars($user_data['nama']); ?></span>
                    </div>
                    <div class="student-row">
                        <span class="student-label">NIS/NISN</span>
                        <span
                            class="student-value"><?php echo htmlspecialchars($user_data['nis_nisn'] ?? '-'); ?></span>
                    </div>
                    <div class="student-row">
                        <span class="student-label">Kelas</span>
                        <span class="student-value"><?php echo htmlspecialchars($kelas_display ?: '-'); ?></span>
                    </div>
                    <div class="student-row">
                        <span class="student-label">Jurusan</span>
                        <span
                            class="student-value"><?php echo htmlspecialchars($user_data['nama_jurusan'] ?? '-'); ?></span>
                    </div>
                    <div class="student-row">
                        <span class="student-label">No. Rekening</span>
                        <span class="student-value"><?php echo htmlspecialchars($user_data['no_rekening']); ?></span>
                    </div>
                    <div class="student-row">
                        <span class="student-label">Email</span>
                        <span class="student-value"><?php echo htmlspecialchars($user_data['email'] ?? '-'); ?></span>
                    </div>
                </div>

                <!-- Details Table -->
                <div class="details-section">
                    <div class="section-title">
                        <i class="fas fa-receipt"></i>
                        Rincian Pembayaran
                    </div>
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Keterangan</th>
                                <th>Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($invoice_desc); ?></div>
                                    <div style="font-size: 11px; color: var(--text-light); margin-top: 2px;">
                                        <?php
                                        $keterangan_display = $transaksi['keterangan'] ?? 'Pembayaran Tagihan Sekolah';
                                        $keterangan_display = str_replace('MySchobank', 'KASDIG', $keterangan_display);
                                        $keterangan_display = str_replace('Via KASDIG', 'via KASDIG', $keterangan_display);
                                        echo htmlspecialchars($keterangan_display);
                                        ?>
                                    </div>
                                </td>
                                <td><?php echo formatRupiah($transaksi['jumlah']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Total -->
                <div class="total-section">
                    <div class="total-row">
                        <span>Subtotal</span>
                        <span><?php echo formatRupiah($transaksi['jumlah']); ?></span>
                    </div>
                    <div class="total-row">
                        <span>Biaya Admin</span>
                        <span>Rp 0,00</span>
                    </div>
                    <div class="total-row grand">
                        <span>TOTAL DIBAYAR</span>
                        <span class="total-amount"><?php echo formatRupiah($transaksi['jumlah']); ?></span>
                    </div>
                </div>

                <!-- QR Code Section -->
                <div class="qr-section">
                    <div class="qr-code">
                        <?php
                        $verify_url = $base_url . '/pages/verifikasi_pembayaran.php?trx=' . urlencode($no_transaksi);
                        $qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($verify_url);
                        ?>
                        <img src="<?php echo $qr_api; ?>" alt="QR" width="80" height="80">
                    </div>
                    <div class="qr-label">Scan QR untuk verifikasi keaslian</div>
                    <div class="qr-note">
                        <strong>Pembayaran telah diterima dan diverifikasi.</strong><br>
                        Bukti ini sah sebagai tanda terima pembayaran tagihan sekolah.<br>
                        Simpan bukti ini sebagai arsip Anda.
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <button class="btn btn-secondary" onclick="window.location.href='pembayaran.php'">
                        <i class="fas fa-arrow-left"></i>
                        Kembali
                    </button>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i>
                        Cetak PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
<?php $conn->close(); ?>