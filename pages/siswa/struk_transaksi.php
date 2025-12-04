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
    header("Location: cek_mutasi.php");
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
    header("Location: cek_mutasi.php");
    exit();
}

function formatRupiah($amount) {
    return 'Rp' . number_format($amount, 0, ',', '.');
}
function formatIndonesianDate($date) {
    $bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $dt = new DateTime($date);
    return $dt->format('d ') . $bulan[(int)$dt->format('m')-1] . $dt->format(' Y, H:i:s') . ' WIB';
}
function showAccount($rek) {
    return htmlspecialchars(substr($rek, 0, 8));
}

$header_message = 'Transaksi Berhasil';
if ($transaksi['jenis_transaksi'] === 'transfer') {
    if ($transaksi['rekening_tujuan'] && $transaksi['rekening_tujuan'] === $user_data['no_rekening']) {
        $share_message = 'Transaksi Transfer Masuk Dari ' . strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A')) 
            . ' sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah diterima!';
    } else {
        $share_message = 'Transaksi Transfer Keluar Ke ' . strtoupper(htmlspecialchars($transaksi['nama_penerima'] ?? 'N/A')) 
            . ' sebesar ' . formatRupiah($transaksi['jumlah']) . ' telah terkirim!';
    }
} else {
    $share_message = $header_message;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no" />
<title>Transaksi Berhasil - Schobank</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
    body {
        font-family:'Poppins', sans-serif;
        background: #fff;
        margin: 0; 
        padding: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .container {
        background: #fff; /* container utama warna putih */
        border-radius: 0;
        box-shadow: none;
        max-width: 360px;
        width: 100%;
        padding-bottom: 20px;
        color: #222;
    }
    .header {
        background: transparent;
        padding: 28px 28px 24px 28px;
        text-align: center;
        border-top-left-radius: 0;
        border-top-right-radius: 0;
        min-height: 130px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 8px;
        border-bottom: 1px solid #ddd;
        color: #222;
    }
    .header .icon {
        background: #2a5298;
        border-radius: 50%;
        width: 62px;
        height: 62px;
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 0 auto 0 auto;
        box-shadow: 0 5px 15px rgba(0,0,0,0.13);
        flex-shrink: 0;
    }
    .header .icon i {
        font-size: 30px;
        color: #fff;
    }
    .header h2 {
        font-weight: 600;
        font-size: 18px;
        letter-spacing: 0.02em;
        margin: 0;
        white-space: pre-line;
    }
    .header .date {
        font-size: 13px;
        opacity: 0.7;
        font-weight: 500;
        margin-top: 4px;
        color: #444;
    }
    .trx-amount {
        text-align: center;
        margin: 26px 20px 14px 20px;
        color: #222;
    }
    .trx-amount .label {
        color: #555;
        font-weight: 600;
        font-size: 14px;
        margin-bottom: 6px;
    }
    .trx-amount .value {
        font-size: 32px;
        font-weight: 700;
        color: #2a5298;
        letter-spacing: 0.04em;
    }
    .trx-amount .ref {
        margin-top: 18px;
        font-size: 13px;
        color: #666;
        font-weight: 500;
    }
    .trx-amount .ref span {
        color: #2a5298;
        letter-spacing: 0.02em;
    }
    /* Samakan warna semua container seperti container utama */
    .card, .detail-transaksi {
        background: #fff; /* samakan dengan .container */
        border-radius: 0;
        box-shadow: none;
        margin: 14px 20px 0 20px;
        padding: 16px 15px;
        color: #222;
        transition: all 0.3s ease;
    }
    .label-small {
        font-size: 13px;
        color: #333;
        margin-bottom: 8px;
        font-weight: 700;
    }
    .row {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .circle {
        background: #2a5298;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(42,82,152,0.4);
    }
    .circle i {
        color: #fff;
        font-size: 20px;
    }
    .konten {
        flex: 1;
    }
    .nama {
        font-weight: 700;
        font-size: 15.5px;
        color: #222;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .bank {
        font-size: 12.5px;
        color: #444;
        font-weight: 600;
        margin-top: 2px;
    }
    .rek {
        font-size: 13px;
        color: #666;
        text-decoration: none;
        margin-top: 2px;
        letter-spacing: 0.03em;
    }
    .detail-row {
        display: flex;
        justify-content: space-between;
        font-size: 14px;
        font-weight: 600;
        color: #222;
        margin-bottom: 8px;
    }
    .detail-row .label {
        font-weight: 500;
        color: #555;
    }
    .detail-row .value {
        color: #222;
        max-width: 55%;
        text-align: right;
        word-break: break-word;
        letter-spacing: 0.02em;
    }
    .see-more-wrapper {
        text-align:center;
        margin: 18px 0 0 0;
    }
    .see-more-btn {
        color: #2a5298;
        font-weight: 700;
        cursor: pointer;
        background: none;
        border: none;
        font-size: 14.5px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        letter-spacing: 0.02em;
    }
    .see-more-btn i {
        transition: transform 0.3s ease;
        font-size: 14px;
    }
    .see-more-btn.active i {
        transform: rotate(180deg);
    }
    .aksi {
        display: flex;
        margin: 24px 20px 20px 20px;
        gap: 14px;
        background: #fff;
        box-shadow: none;
        border-radius: 0;
        padding: 0;
        color: #222;
    }
    .aksi button {
        flex: 1;
        padding: 14px;
        border-radius: 12px;
        font-size: 15.5px;
        font-weight: 700;
        border: none;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        transition: background-color 0.2s ease;
        letter-spacing: 0.03em;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .aksi .primary {
        background: #fff;
        color: #2a5298;
        border: 2px solid #2a5298;
    }
    .aksi .primary:hover {
        background: #2a5298;
        color: #fff;
    }
    .aksi .secondary {
        background: #2a5298;
        color: #fff;
    }
    /* Hide buttons when capturing */
    .aksi, .see-more-wrapper {
        user-select: none;
    }
    @media (max-width: 500px) {
        body {
            align-items: flex-start;
        }
        .container {
            max-width: 100vw;
            border-radius: 0;
        }
    }
    a[href^='tel:'], .rek {
        color: inherit !important;
        text-decoration: none !important;
        pointer-events: none !important;
    }
</style>
</head>
<body>
<div class="container" id="receipt" data-share-text="<?= htmlspecialchars($share_message) ?>">
    <div class="header">
        <div class="icon"><i class="fas fa-check"></i></div>
        <h2 id="headerMessage"><?= nl2br(htmlspecialchars($header_message)) ?></h2>
        <div class="date"><?= formatIndonesianDate($transaksi['created_at']) ?></div>
    </div>
    <div class="trx-amount">
        <div class="label">Total Transaksi</div>
        <div class="value"><?= formatRupiah($transaksi['jumlah']) ?></div>
        <div class="ref">No. Ref <span><?= htmlspecialchars($no_transaksi) ?></span></div>
    </div>
    <?php if ($transaksi['jenis_transaksi'] === 'transfer'): ?>
        <div class="card">
            <div class="label-small">Rekening Sumber</div>
            <div class="row">
                <div class="circle"><i class="fas fa-university"></i></div>
                <div class="konten">
                    <div class="nama"><?= strtoupper(htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A')) ?></div>
                    <div class="bank">SCHOBANK</div>
                    <div class="rek"><?= showAccount($transaksi['rekening_asal']) ?></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="label-small">Rekening Tujuan</div>
            <div class="row">
                <div class="circle"><i class="fas fa-arrow-right"></i></div>
                <div class="konten">
                    <div class="nama"><?= strtoupper(htmlspecialchars($transaksi['nama_penerima'] ?? 'N/A')) ?></div>
                    <div class="bank">SCHOBANK</div>
                    <div class="rek"><?= showAccount($transaksi['rekening_tujuan']) ?></div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="label-small">Rekening Sumber</div>
            <div class="row">
                <div class="circle"><i class="fas fa-user"></i></div>
                <div class="konten">
                    <div class="nama"><?= strtoupper(htmlspecialchars($user_data['nama'])) ?></div>
                    <div class="bank">SCHOBANK</div>
                    <div class="rek"><?= showAccount($user_data['no_rekening']) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="see-more-wrapper" id="seeMoreParent">
        <button class="see-more-btn" type="button" onclick="toggleDetailTransaksi(this)">
            Lihat selengkapnya <i class="fas fa-chevron-down"></i>
        </button>
    </div>
    <div class="detail-transaksi" id="detailTransaksi" style="display:none;">
        <div class="detail-row">
            <div class="label">Nominal</div>
            <div class="value"><?= formatRupiah($transaksi['jumlah']) ?></div>
        </div>
        <div class="detail-row">
            <div class="label">Biaya Admin</div>
            <div class="value">Rp0</div>
        </div>
        <?php if (!empty($transaksi['keterangan'])): ?>
        <div class="detail-row">
            <div class="label">Keterangan</div>
            <div class="value"><?= htmlspecialchars($transaksi['keterangan']) ?></div>
        </div>
        <?php endif; ?>
        <div class="see-more-wrapper">
            <button class="see-more-btn active" type="button" onclick="toggleDetailTransaksi(this)">
                Sembunyikan <i class="fas fa-chevron-up"></i>
            </button>
        </div>
    </div>
    <div class="aksi">
        <button class="primary" onclick="shareReceipt()">
            <i class="fas fa-share-alt"></i> Bagikan
        </button>
        <button class="secondary" onclick="window.location.href='aktivitas.php'">
            <i class="fas fa-check"></i> Selesai
        </button>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    function toggleDetailTransaksi(btn) {
        var detail = document.getElementById('detailTransaksi');
        var parent = document.getElementById('seeMoreParent');

        if (detail.style.display !== 'block') {
            detail.style.display = 'block';
            parent.style.display = 'none';
            btn.innerHTML = 'Sembunyikan <i class="fas fa-chevron-up"></i>';
            btn.classList.add('active');
        } else {
            detail.style.display = 'none';
            parent.style.display = 'block';
            btn.innerHTML = 'Lihat selengkapnya <i class="fas fa-chevron-down"></i>';
            btn.classList.remove('active');
        }
    }

    async function shareReceipt() {
        const el = document.getElementById('receipt');
        const seeMoreParent = document.getElementById('seeMoreParent');
        const detailTransaksi = document.getElementById('detailTransaksi');
        const aksiButtons = document.querySelector('.aksi');
        const shareText = el.getAttribute('data-share-text') || 'Transaksi Berhasil';

        detailTransaksi.style.display = 'block';
        seeMoreParent.style.display = 'none';
        aksiButtons.style.display = 'none';

        const canvas = await html2canvas(el, {backgroundColor: '#fff', scale: 2});

        detailTransaksi.style.display = 'none';
        seeMoreParent.style.display = 'block';
        aksiButtons.style.display = 'flex';

        canvas.toBlob(blob => {
            const file = new File([blob], 'Struk_Transaksi.jpg', {type: 'image/jpeg'});
            if (navigator.canShare && navigator.canShare({files: [file]})) {
                navigator.share({files: [file], text: shareText});
            } else {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'Struk_Transaksi.jpg';
                document.body.appendChild(a);
                a.click();
                setTimeout(() => {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, 500);
            }
        }, 'image/jpeg', 0.96);
    }
</script>
</body>
</html>
<?php $conn->close(); ?>
