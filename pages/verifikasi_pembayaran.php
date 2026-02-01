<?php
/**
 * Verifikasi Pembayaran - Public Page
 * Halaman ini dapat diakses tanpa login untuk verifikasi keaslian transaksi via QR
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
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
    $project_root = dirname($current_dir);
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
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

require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// Get transaction number from URL
$no_transaksi = $_GET['trx'] ?? null;

$transaksi = null;
$error_message = null;
$is_valid = false;

if (!$no_transaksi) {
    $error_message = 'Nomor transaksi tidak ditemukan';
} else {
    // Fetch transaction data
    $query = "SELECT t.*, 
                     r1.no_rekening AS rekening_asal, u1.nama AS nama_pengirim,
                     r2.no_rekening AS rekening_tujuan, u2.nama AS nama_penerima
              FROM transaksi t
              LEFT JOIN rekening r1 ON t.rekening_id = r1.id
              LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
              LEFT JOIN users u1 ON r1.user_id = u1.id
              LEFT JOIN users u2 ON r2.user_id = u2.id
              WHERE t.no_transaksi = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $no_transaksi);
    $stmt->execute();
    $transaksi = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transaksi) {
        $error_message = 'Transaksi tidak ditemukan dalam sistem';
    } else {
        $is_valid = true;
    }
}

function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, ',', '.') . ',00';
}

function formatIndonesianDate($date)
{
    $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $dt = new DateTime($date);
    return $dt->format('d ') . $bulan[(int) $dt->format('m') - 1] . $dt->format(' Y, H:i:s') . ' WIB';
}

function maskAccount($rek)
{
    if (strlen($rek) <= 4)
        return $rek;
    return substr($rek, 0, 4) . '****' . substr($rek, -2);
}

function getTransactionTypeLabel($jenis)
{
    switch ($jenis) {
        case 'transfer':
            return 'Transfer';
        case 'setor':
            return 'Setoran Tunai';
        case 'tarik':
            return 'Penarikan Tunai';
        case 'bayar':
            return 'Pembayaran Tagihan';
        default:
            return ucfirst($jenis);
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no" />
    <title>Verifikasi Transaksi | KASDIG</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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

            --bg-color: var(--gray-100);
            --card-bg: #ffffff;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);

            --success: #059669;
            --success-bg: #d1fae5;
            --error: #dc2626;
            --error-bg: #fee2e2;
            --warning: #d97706;
            --warning-bg: #fef3c7;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-primary);
        }

        .verify-container {
            background: var(--card-bg);
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .verify-header {
            text-align: center;
            padding: 30px 24px 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .verify-header .logo {
            width: 120px;
            margin-bottom: 15px;
        }

        .status-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 15px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.05);
                opacity: 0.9;
            }
        }

        .status-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-icon.error {
            background: var(--error-bg);
            color: var(--error);
        }

        .status-text {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .status-text.success {
            color: var(--success);
        }

        .status-text.error {
            color: var(--error);
        }

        .status-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .verify-body {
            padding: 24px;
        }

        .amount-section {
            text-align: center;
            padding: 20px;
            background: var(--gray-50);
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .amount-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .amount-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .info-section {
            margin-bottom: 20px;
        }

        .info-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            font-weight: 600;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed var(--gray-300);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            font-size: 13px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: var(--text-secondary);
            flex-shrink: 0;
        }

        .info-value {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
            max-width: 60%;
            word-wrap: break-word;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.success {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-badge.pending {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .verify-footer {
            padding: 20px 24px;
            background: var(--gray-50);
            border-top: 1px solid var(--gray-200);
            text-align: center;
        }

        .footer-note {
            font-size: 11px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .footer-note i {
            margin-right: 5px;
            color: var(--success);
        }

        .error-container {
            text-align: center;
            padding: 40px 24px;
        }

        .error-container p {
            color: var(--text-secondary);
            margin-top: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all 0.2s;
            margin-top: 20px;
        }

        .btn-primary {
            background: var(--gray-800);
            color: white;
        }

        .btn-primary:hover {
            background: var(--gray-900);
        }

        @media (max-width: 480px) {
            .verify-container {
                border-radius: 12px;
            }

            .amount-value {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="verify-container">
        <?php if ($is_valid && $transaksi): ?>
            <div class="verify-header">
                <img src="<?= $base_url ?>/assets/images/header.png" alt="KASDIG" class="logo">
                <div class="status-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="status-text success">Transaksi Terverifikasi</div>
                <div class="status-subtitle">Data transaksi valid dan tercatat dalam sistem</div>
            </div>

            <div class="verify-body">
                <div class="amount-section">
                    <div class="amount-label">Nominal Transaksi</div>
                    <div class="amount-value">
                        <?= formatRupiah($transaksi['jumlah']) ?>
                    </div>
                </div>

                <div class="info-section">
                    <div class="info-title">Detail Transaksi</div>
                    <div class="info-row">
                        <div class="info-label">No. Transaksi</div>
                        <div class="info-value">
                            <?= htmlspecialchars($transaksi['no_transaksi']) ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Jenis Transaksi</div>
                        <div class="info-value">
                            <?= getTransactionTypeLabel($transaksi['jenis_transaksi']) ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tanggal</div>
                        <div class="info-value">
                            <?= formatIndonesianDate($transaksi['created_at']) ?>
                        </div>
                    </div>
                </div>

                <?php if ($transaksi['jenis_transaksi'] === 'transfer'): ?>
                    <div class="info-section">
                        <div class="info-title">Informasi Pengirim</div>
                        <div class="info-row">
                            <div class="info-label">Nama</div>
                            <div class="info-value">
                                <?= strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A')) ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">No. Rekening</div>
                            <div class="info-value">
                                <?= maskAccount($transaksi['rekening_asal'] ?? '') ?>
                            </div>
                        </div>
                    </div>
                    <div class="info-section">
                        <div class="info-title">Informasi Penerima</div>
                        <div class="info-row">
                            <div class="info-label">Nama</div>
                            <div class="info-value">
                                <?= strtoupper(htmlspecialchars($transaksi['nama_penerima'] ?? 'N/A')) ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">No. Rekening</div>
                            <div class="info-value">
                                <?= maskAccount($transaksi['rekening_tujuan'] ?? '') ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($transaksi['jenis_transaksi'] === 'setor' || $transaksi['jenis_transaksi'] === 'tarik'): ?>
                    <div class="info-section">
                        <div class="info-title">Informasi Nasabah</div>
                        <div class="info-row">
                            <div class="info-label">Nama</div>
                            <div class="info-value">
                                <?= strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A')) ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">No. Rekening</div>
                            <div class="info-value">
                                <?= maskAccount($transaksi['rekening_asal'] ?? '') ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($transaksi['jenis_transaksi'] === 'bayar'): ?>
                    <div class="info-section">
                        <div class="info-title">Informasi Pembayaran</div>
                        <div class="info-row">
                            <div class="info-label">Nama</div>
                            <div class="info-value">
                                <?= strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A')) ?>
                            </div>
                        </div>
                        <?php if (!empty($transaksi['keterangan'])): ?>
                            <div class="info-row">
                                <div class="info-label">Keterangan</div>
                                <div class="info-value">
                                    <?= htmlspecialchars($transaksi['keterangan']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="info-section">
                    <div class="info-title">Status</div>
                    <div class="info-row">
                        <div class="info-label">Status Transaksi</div>
                        <div class="info-value">
                            <span class="status-badge success">
                                <i class="fas fa-check-circle"></i> Berhasil
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="verify-footer">
                <div class="footer-note">
                    <i class="fas fa-shield-alt"></i>
                    Transaksi ini telah diverifikasi dan tercatat dalam sistem KASDIG.<br>
                    Dokumen ini merupakan bukti sah yang dikeluarkan secara digital.
                </div>
            </div>
        <?php else: ?>
            <div class="verify-header">
                <img src="<?= $base_url ?>/assets/images/header.png" alt="KASDIG" class="logo">
                <div class="status-icon error">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="status-text error">Verifikasi Gagal</div>
                <div class="status-subtitle">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            </div>

            <div class="error-container">
                <p>Transaksi dengan nomor referensi tersebut tidak ditemukan dalam sistem kami. Pastikan QR code yang Anda
                    scan berasal dari struk transaksi yang sah.</p>
                <a href="<?= $base_url ?>" class="btn btn-primary">
                    <i class="fas fa-home"></i> Kembali ke Beranda
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
<?php $conn->close(); ?>