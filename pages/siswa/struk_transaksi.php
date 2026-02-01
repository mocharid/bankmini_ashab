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

$query = "SELECT u.nama, r.no_rekening, r.id AS rekening_id
          FROM users u
          JOIN rekening r ON u.id = r.user_id
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
    header("Location: aktivitas.php");
    exit();
}

$query = "SELECT t.*, 
                 r1.no_rekening AS rekening_asal, u1.nama AS nama_pengirim,
                 r2.no_rekening AS rekening_tujuan, u2.nama AS nama_penerima
          FROM transaksi t
          LEFT JOIN rekening r1 ON t.rekening_id = r1.id
          LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
          LEFT JOIN users u1 ON r1.user_id = u1.id
          LEFT JOIN users u2 ON r2.user_id = u2.id
          WHERE t.no_transaksi = ? AND (r1.user_id = ? OR r2.user_id = ?)";
$stmt = $conn->prepare($query);
$stmt->bind_param("sii", $no_transaksi, $user_id, $user_id);
$stmt->execute();
$transaksi = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaksi) {
    header("Location: aktivitas.php");
    exit();
}

function formatRupiah($amount, $withSmallDecimal = false)
{
    $formatted = number_format($amount, 0, ',', '.');
    if ($withSmallDecimal) {
        return 'Rp.' . $formatted . '<span class="decimal">,00</span>';
    }
    return 'Rp.' . $formatted . ',00';
}
function formatIndonesianDate($date)
{
    $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $dt = new DateTime($date);
    return $dt->format('d ') . $bulan[(int) $dt->format('m') - 1] . $dt->format(' Y, H:i:s') . ' WIB';
}
function showAccount($rek)
{
    return htmlspecialchars(substr($rek, 0, 8));
}

$header_message = 'Transaksi Berhasil';
$trx_type_label = '';
$share_message = '';

if ($transaksi['jenis_transaksi'] === 'transfer') {
    if ($transaksi['rekening_tujuan'] && $transaksi['rekening_tujuan'] === $user_data['no_rekening']) {
        $header_message = 'Transfer Masuk Berhasil';
        $trx_type_label = 'Transfer Masuk';
        $share_message = 'Transfer Masuk dari ' . strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A'))
            . ' sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah diterima!';
    } else {
        $header_message = 'Transfer Keluar Berhasil';
        $trx_type_label = 'Transfer Keluar';
        $share_message = 'Transfer Keluar ke ' . strtoupper(htmlspecialchars($transaksi['nama_penerima'] ?? 'N/A'))
            . ' sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah terkirim!';
    }
} elseif ($transaksi['jenis_transaksi'] === 'setor') {
    $header_message = 'Setoran Tunai Berhasil';
    $trx_type_label = 'Setoran Tunai';
    $share_message = 'Setoran Tunai sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah berhasil!';
} elseif ($transaksi['jenis_transaksi'] === 'tarik') {
    $header_message = 'Penarikan Tunai Berhasil';
    $trx_type_label = 'Penarikan Tunai';
    $share_message = 'Penarikan Tunai sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah berhasil!';
} elseif ($transaksi['jenis_transaksi'] === 'bayar') {
    // Ambil nama tagihan dari keterangan - bersihkan prefix dan suffix
    $keterangan = $transaksi['keterangan'] ?? '';
    $nama_tagihan = $keterangan;

    // Hapus prefix
    if (strpos($nama_tagihan, 'Pembayaran Tagihan ') === 0) {
        $nama_tagihan = substr($nama_tagihan, 19);
    } elseif (strpos($nama_tagihan, 'Pembayaran - ') === 0) {
        $nama_tagihan = substr($nama_tagihan, 13);
    } elseif (strpos($nama_tagihan, 'Pembayaran ') === 0) {
        $nama_tagihan = substr($nama_tagihan, 11);
    }

    // Hapus suffix Via...
    $nama_tagihan = preg_replace('/\s*Via\s+(Bendahara|KASDIG|Schobank|Aplikasi).*$/i', '', $nama_tagihan);
    $nama_tagihan = trim($nama_tagihan) ?: 'Tagihan';

    // Determine payment method
    $is_mandiri = empty($transaksi['petugas_id']);
    $payment_method_text = $is_mandiri ? 'Pembayaran via aplikasi KASDIG' : 'Pembayaran via bendahara sekolah';

    $header_message = "Pembayaran Tagihan Berhasil";
    $trx_type_label = 'Pembayaran Tagihan';
    $share_message = 'Pembayaran Tagihan ' . htmlspecialchars($nama_tagihan) . ' sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah berhasil!';
} else {
    $share_message = $header_message;
    $trx_type_label = 'Transaksi';
}

// Generate QR Code URL for verification
$verification_url = $base_url . '/pages/verifikasi_pembayaran.php?trx=' . urlencode($no_transaksi);
$qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($verification_url);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no" />
    <meta name="format-detection" content="telephone=no, date=no, email=no, address=no" />
    <title>Bukti Transaksi | KASDIG</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root {
            /* Palette Modern (Gray/Slate) matching Admin/Petugas */
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

            /* Deep Blue Success */
            --success: #1e3a8a;
            --success-bg: #dbeafe;

            --border-dashed: var(--gray-300);
            --primary: var(--gray-800);
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

        .receipt-container {
            background: var(--card-bg);
            width: 100%;
            max-width: 400px;
            border-radius: 4px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .receipt-container::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background-image: url('<?php echo $base_url; ?>/assets/images/header.png');
            background-repeat: repeat;
            background-size: 150px;
            opacity: 0.05;
            z-index: -1;
            pointer-events: none;
            transform: rotate(-15deg);
        }

        .receipt-header {
            text-align: center;
            padding: 30px 24px 20px;
            border-bottom: 2px dashed var(--border-dashed);
            position: relative;
        }

        .qr-section {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed var(--border-dashed);
        }

        .qr-code {
            border: 1px solid var(--gray-200);
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
            color: var(--text-secondary);
            text-align: center;
            margin-top: 8px;
        }

        .status-icon {
            width: 48px;
            height: 48px;
            background: var(--success-bg);
            color: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 15px;
        }

        .status-text {
            font-size: 16px;
            font-weight: 600;
            color: var(--success);
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .timestamp {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .amount-section {
            padding: 25px 24px;
            text-align: center;
        }

        .amount-label {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .amount-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .amount-value .decimal {
            font-size: 18px;
            font-weight: 600;
        }

        .receipt-body {
            padding: 0 24px 30px;
        }

        .receipt-note {
            font-size: 11px;
            color: var(--text-secondary);
            text-align: center;
            margin-top: 24px;
            font-style: italic;
            opacity: 0.8;
            line-height: 1.4;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
            font-size: 13px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: var(--text-secondary);
            font-weight: 400;
            flex-shrink: 0;
        }

        .info-value {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
            max-width: 65%;
            word-wrap: break-word;
        }

        .info-value.mono {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600;
            letter-spacing: normal;
            text-decoration: none !important;
            -webkit-text-decoration: none !important;
            color: var(--text-primary) !important;
        }

        /* Prevent iOS/Android auto-detect links */
        a[href^="tel"],
        a[href^="mailto"],
        a[x-apple-data-detectors],
        .no-rekening a {
            color: inherit !important;
            text-decoration: none !important;
            -webkit-text-decoration: none !important;
            pointer-events: none;
            font-family: inherit !important;
            font-weight: inherit !important;
        }

        /* Force no rekening style override for mobile */
        .no-rekening,
        .no-rekening * {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            -webkit-text-decoration: none !important;
            color: var(--text-primary) !important;
            -webkit-text-fill-color: var(--text-primary) !important;
            border-bottom: none !important;
            -webkit-tap-highlight-color: transparent;
            pointer-events: none;
        }

        .divider {
            height: 1px;
            background: RepeatingLinearGradient(90deg, var(--border-dashed) 0, var(--border-dashed) 5px, transparent 5px, transparent 10px);
            border-bottom: 1px dashed var(--border-dashed);
            margin: 0 0 20px;
        }

        .section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-secondary);
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 15px;
            margin-top: 5px;
        }

        .receipt-footer {
            background: #f9fafb;
            padding: 20px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            border: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        .btn-secondary {
            background: white;
            border: 1px solid #d1d5db;
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background: #f3f4f6;
        }

        @media print {
            body {
                background: white;
            }

            .receipt-container {
                box-shadow: none;
                border: 1px solid #000;
            }

            .receipt-footer {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="receipt-container" id="receipt" data-share-text="<?= htmlspecialchars($share_message) ?>">
        <div class="receipt-header">
            <div class="status-icon"><i class="fas fa-check"></i></div>
            <div class="status-text">Transaksi Berhasil</div>
            <div class="timestamp"><?= formatIndonesianDate($transaksi['created_at']) ?></div>
        </div>

        <div class="amount-section">
            <div class="amount-label">Total Nominal</div>
            <div class="amount-value"><?= formatRupiah($transaksi['jumlah'], true) ?></div>
        </div>

        <div class="receipt-body">
            <div class="divider"></div>

            <div class="info-row">
                <div class="info-label">No. Referensi</div>
                <div class="info-value mono"><?= htmlspecialchars($no_transaksi) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tipe Transaksi</div>
                <div class="info-value"><?= htmlspecialchars($trx_type_label) ?></div>
            </div>

            <!-- Details based on Type -->
            <?php if ($transaksi['jenis_transaksi'] === 'transfer'): ?>
                <div class="divider" style="margin: 20px 0;"></div>
                <div class="section-title">Sumber Dana</div>
                <div class="info-row">
                    <div class="info-label">Pengirim</div>
                    <div class="info-value"><?= strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A')) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Bank</div>
                    <div class="info-value">KASDIG</div>
                </div>
                <div class="info-row">
                    <div class="info-label">No. Rekening</div>
                    <div class="info-value mono no-rekening">
                        <span aria-hidden="true"><?= showAccount($transaksi['rekening_asal']) ?></span>
                    </div>
                </div>

                <div class="divider" style="margin: 20px 0;"></div>
                <div class="section-title">Tujuan</div>
                <div class="info-row">
                    <div class="info-label">Penerima</div>
                    <div class="info-value"><?= strtoupper(htmlspecialchars($transaksi['nama_penerima'] ?? 'N/A')) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Bank</div>
                    <div class="info-value">KASDIG</div>
                </div>
                <div class="info-row">
                    <div class="info-label">No. Rekening</div>
                    <div class="info-value mono no-rekening"><span
                            aria-hidden="true"><?= showAccount($transaksi['rekening_tujuan']) ?></span></div>
                </div>

            <?php elseif ($transaksi['jenis_transaksi'] === 'bayar'): ?>
                <div class="divider" style="margin: 20px 0;"></div>
                <div class="info-row">
                    <div class="info-label">Pembayaran</div>
                    <div class="info-value"><?= htmlspecialchars($nama_tagihan) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Metode</div>
                    <div class="info-value" style="font-size: 11px;"><?= $payment_method_text ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Siswa</div>
                    <div class="info-value"><?= strtoupper(htmlspecialchars($user_data['nama'])) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Penerima</div>
                    <div class="info-value">BENDAHARA SEKOLAH</div>
                </div>

            <?php else: // Setor/Tarik ?>
                <div class="divider" style="margin: 20px 0;"></div>
                <div class="info-row">
                    <div class="info-label">Nasabah</div>
                    <div class="info-value"><?= strtoupper(htmlspecialchars($user_data['nama'])) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">No. Rekening</div>
                    <div class="info-value mono no-rekening"><span
                            aria-hidden="true"><?= showAccount($user_data['no_rekening']) ?></span></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($transaksi['keterangan'])): ?>
                <div class="divider" style="margin: 20px 0;"></div>
                <div class="info-row">
                    <div class="info-label">Catatan</div>
                    <div class="info-value"><?= htmlspecialchars($transaksi['keterangan']) ?></div>
                </div>
            <?php endif; ?>

            <div class="divider" style="margin: 20px 0;"></div>
            <div class="info-row">
                <div class="info-label">Status</div>
                <div class="info-value" style="color: var(--success);">BERHASIL</div>
            </div>

            <div class="receipt-note">
                Ini merupakan bukti transaksi sah yang dikeluarkan oleh sistem.
            </div>

            <!-- QR Code - Bottom (hidden from screenshot) -->
            <div class="qr-section" data-html2canvas-ignore="true">
                <div class="qr-code">
                    <img src="<?php echo $qr_api_url; ?>" alt="QR" width="80" height="80">
                </div>
                <div class="qr-label">Scan QR untuk verifikasi keaslian</div>
            </div>
        </div>

        <div class="receipt-footer" data-html2canvas-ignore="true">
            <button class="btn btn-primary" onclick="shareReceipt()">
                <i class="fas fa-share-alt"></i> Bagikan
            </button>
            <button class="btn btn-secondary" onclick="window.location.href='aktivitas.php'">
                <i class="fas fa-arrow-left"></i> Kembali
            </button>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        async function shareReceipt() {
            const el = document.getElementById('receipt');
            const footer = document.querySelector('.receipt-footer');
            const shareText = el.getAttribute('data-share-text') || 'Bukti Transaksi KASDIG';

            // Hide footer for screenshot
            footer.style.display = 'none';

            try {
                const canvas = await html2canvas(el, {
                    backgroundColor: '#ffffff',
                    scale: 2,
                    logging: false
                });

                // Restore footer
                footer.style.display = 'flex';

                canvas.toBlob(blob => {
                    const file = new File([blob], 'KASDIG_Struk.jpg', { type: 'image/jpeg' });
                    if (navigator.canShare && navigator.canShare({ files: [file] })) {
                        navigator.share({ files: [file], text: shareText });
                    } else {
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'KASDIG_Struk.jpg';
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(() => {
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        }, 500);
                    }
                }, 'image/jpeg', 0.95);
            } catch (err) {
                console.error('Error sharing:', err);
                footer.style.display = 'flex';
                alert('Gagal membagikan struk.');
            }
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>