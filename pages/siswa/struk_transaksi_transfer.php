<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
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
    header("Location: mutasi.php");
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
    header("Location: mutasi.php");
    exit();
}

function formatRupiah($amount)
{
    return 'Rp' . number_format($amount, 0, ',', '.');
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
    $keterangan = $transaksi['keterangan'] ?? '';
    $nama_tagihan = $keterangan;
    if (strpos($keterangan, 'Pembayaran - ') === 0) {
        $nama_tagihan = substr($keterangan, 13);
    } elseif (strpos($keterangan, 'Pembayaran ') === 0) {
        $nama_tagihan = substr($keterangan, 11);
    }
    $header_message = "Pembayaran Tagihan Berhasil";
    $trx_type_label = 'Pembayaran Tagihan ' . htmlspecialchars($nama_tagihan);
    $share_message = 'Pembayaran Tagihan ' . htmlspecialchars($nama_tagihan) . ' sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah berhasil!';
} else {
    $share_message = $header_message;
    $trx_type_label = 'Transaksi';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no" />
    <title>Bukti Transaksi - KASDIG</title>
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

            /* Changed Success to Deep Blue as requested */
            --success: #1e3a8a;
            /* Blue-900 like */
            --success-bg: #dbeafe;
            /* Blue-100 */

            --border-dashed: var(--gray-300);
            --primary: var(--gray-800);
            /* Match sidebar/admin primary */
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
            /* More paper-like */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .receipt-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('../../assets/images/header.png');
            background-repeat: repeat;
            background-size: 200px;
            /* Further increased for lower density */
            opacity: 0.05;
            /* Very subtle */
            z-index: -1;
            pointer-events: none;
        }

        /* Top jagged edge effect (optional, keeping it clean for now) */

        .receipt-header {
            text-align: center;
            padding: 30px 24px 20px;
            border-bottom: 2px dashed var(--border-dashed);
        }

        .logo {
            font-weight: 800;
            font-size: 20px;
            color: var(--primary);
            letter-spacing: -0.5px;
            margin-bottom: 20px;
            display: inline-block;
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
            font-family: 'Courier New', monospace;
            /* Receipt-like for IDs */
            letter-spacing: -0.5px;
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

        /* Print Specifics */
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
            <!-- Logo removed as requested -->
            <div class="status-icon"><i class="fas fa-check"></i></div>
            <div class="status-text">Transaksi Berhasil</div>
            <div class="timestamp"><?= formatIndonesianDate($transaksi['created_at']) ?></div>
        </div>

        <div class="amount-section">
            <div class="amount-label">Total Nominal</div>
            <div class="amount-value"><?= formatRupiah($transaksi['jumlah']) ?></div>
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
                    <div class="info-value mono"><?= showAccount($transaksi['rekening_asal']) ?></div>
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
                    <div class="info-value mono"><?= showAccount($transaksi['rekening_tujuan']) ?></div>
                </div>

            <?php elseif ($transaksi['jenis_transaksi'] === 'bayar'): ?>
                <div class="divider" style="margin: 20px 0;"></div>
                <div class="info-row">
                    <div class="info-label">Pembayaran</div>
                    <div class="info-value"><?= htmlspecialchars($nama_tagihan) ?></div>
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
                    <div class="info-value mono"><?= showAccount($user_data['no_rekening']) ?></div>
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
        </div>

        <div class="receipt-footer" data-html2canvas-ignore="true">
            <button class="btn btn-primary" onclick="shareReceipt()">
                <i class="fas fa-share-alt"></i> Bagikan
            </button>
            <button class="btn btn-secondary" onclick="window.location.href='transfer.php'">
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