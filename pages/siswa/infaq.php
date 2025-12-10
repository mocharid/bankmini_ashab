<?php
/**
 * Siswa Infaq Tagihan Viewer & Payment - Modern Tagihan Page (Running + History)
 * File: pages/siswa/infaq.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir  = dirname($current_file);
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
// SESSION & TIMEZONE
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once INCLUDES_PATH . '/db_connection.php';

$username = $_SESSION['username'] ?? 'Siswa';
$siswa_id = $_SESSION['user_id'] ?? 0;

if ($_SESSION['role'] !== 'siswa') {
    header("Location: /pages/login.php");
    exit;
}

// ============================================
// DETECT BASE URL
// ============================================
$protocol    = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts  = explode('/', trim(dirname($script_name), '/'));

$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;

// ============================================
// SWEETALERT MESSAGE HOLDER
// ============================================
$swal_type    = '';
$swal_title   = '';
$swal_message = '';

// ============================================
// HANDLE PAYMENT SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar_tagihan'])) {
    $tagihan_id = filter_input(INPUT_POST, 'tagihan_id', FILTER_VALIDATE_INT);

    if ($tagihan_id) {
        $query_tagihan = "SELECT nominal_tagihan, status_pembayaran 
                          FROM infaq_tagihan_bulanan 
                          WHERE id = ? AND siswa_id = ?";
        $stmt_tagihan = $conn->prepare($query_tagihan);
        $stmt_tagihan->bind_param("ii", $tagihan_id, $siswa_id);
        $stmt_tagihan->execute();
        $result_tagihan = $stmt_tagihan->get_result();

        if ($row_tagihan = $result_tagihan->fetch_assoc()) {
            if ($row_tagihan['status_pembayaran'] === 'sudah_bayar') {
                $swal_type    = 'error';
                $swal_title   = 'Tagihan sudah dibayar';
                $swal_message = 'Tagihan ini sudah tercatat sebagai lunas.';
            } else {
                $nominal_bayar = $row_tagihan['nominal_tagihan'];

                $query_rek_siswa = "SELECT id, saldo FROM rekening WHERE user_id = ?";
                $stmt_rek_siswa  = $conn->prepare($query_rek_siswa);
                $stmt_rek_siswa->bind_param("i", $siswa_id);
                $stmt_rek_siswa->execute();
                $result_rek_siswa = $stmt_rek_siswa->get_result();
                $row_rek_siswa    = $result_rek_siswa->fetch_assoc();

                if ($row_rek_siswa['saldo'] < $nominal_bayar) {
                    $swal_type    = 'error';
                    $swal_title   = 'Saldo tidak cukup';
                    $swal_message = 'Saldo rekening Anda tidak mencukupi untuk membayar tagihan ini.';
                } else {
                    $query_bendahara = "SELECT u.id AS bendahara_id, r.id AS rekening_bendahara_id 
                                        FROM users u 
                                        JOIN rekening r ON u.id = r.user_id 
                                        WHERE u.role = 'bendahara' LIMIT 1";
                    $result_bendahara = $conn->query($query_bendahara);
                    $row_bendahara    = $result_bendahara->fetch_assoc();

                    if ($row_bendahara) {
                        $bendahara_id          = $row_bendahara['bendahara_id'];
                        $rekening_bendahara_id = $row_bendahara['rekening_bendahara_id'];
                        $rekening_siswa_id     = $row_rek_siswa['id'];

                        $no_transaksi = 'INFAQ-' . date('Ymd') . '-' . rand(1000, 9999);

                        $query_bulan_tahun = "SELECT bulan, tahun FROM infaq_tagihan_bulanan WHERE id = ?";
                        $stmt_bulan_tahun  = $conn->prepare($query_bulan_tahun);
                        $stmt_bulan_tahun->bind_param("i", $tagihan_id);
                        $stmt_bulan_tahun->execute();
                        $result_bulan_tahun = $stmt_bulan_tahun->get_result();
                        $row_bulan_tahun    = $result_bulan_tahun->fetch_assoc();
                        $bulan              = $row_bulan_tahun['bulan'];
                        $tahun              = $row_bulan_tahun['tahun'];

                        $query_insert = "INSERT INTO infaq_pembayaran 
                                         (tagihan_id, siswa_id, bendahara_id, rekening_siswa_id, rekening_bendahara_id, 
                                          nominal_bayar, no_transaksi, bulan, tahun, tanggal_bayar, keterangan) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Pembayaran infaq oleh siswa')";
                        $stmt_insert = $conn->prepare($query_insert);
                        $stmt_insert->bind_param(
                            "iiiiidsii",
                            $tagihan_id,
                            $siswa_id,
                            $bendahara_id,
                            $rekening_siswa_id,
                            $rekening_bendahara_id,
                            $nominal_bayar,
                            $no_transaksi,
                            $bulan,
                            $tahun
                        );

                        if ($stmt_insert->execute()) {
                            $swal_type    = 'success';
                            $swal_title   = 'Pembayaran berhasil';
                            $swal_message = 'Infaq berhasil dibayar. Nomor transaksi: ' . $no_transaksi;
                        } else {
                            $swal_type    = 'error';
                            $swal_title   = 'Gagal membayar';
                            $swal_message = 'Terjadi kesalahan saat memproses pembayaran.';
                        }
                    } else {
                        $swal_type    = 'error';
                        $swal_title   = 'Bendahara tidak ditemukan';
                        $swal_message = 'Data bendahara tidak tersedia. Silakan hubungi admin.';
                    }
                }
            }
        } else {
            $swal_type    = 'error';
            $swal_title   = 'Tagihan tidak ditemukan';
            $swal_message = 'Tagihan yang dipilih tidak ditemukan.';
        }
    } else {
        $swal_type    = 'error';
        $swal_title   = 'Data tidak valid';
        $swal_message = 'Permintaan pembayaran tidak valid.';
    }
}

// ============================================
// GET TAGIHAN LIST & SPLIT
// ============================================
$tagihan_list = [];
$query_tagihan_list = "
    SELECT 
        id AS tagihan_id,
        bulan,
        tahun,
        nominal_tagihan,
        status_pembayaran,
        tanggal_bayar,
        no_transaksi
    FROM infaq_tagihan_bulanan
    WHERE siswa_id = ?
    ORDER BY tahun DESC, bulan DESC
";
$stmt_tagihan_list = $conn->prepare($query_tagihan_list);
$stmt_tagihan_list->bind_param("i", $siswa_id);
$stmt_tagihan_list->execute();
$result_tagihan_list = $stmt_tagihan_list->get_result();
while ($row = $result_tagihan_list->fetch_assoc()) {
    $tagihan_list[] = $row;
}
$conn->close();

$tagihan_berjalan = [];
$tagihan_riwayat  = [];
foreach ($tagihan_list as $t) {
    if ($t['status_pembayaran'] === 'belum_bayar') {
        $tagihan_berjalan[] = $t;
    } else {
        $tagihan_riwayat[] = $t;
    }
}

// Nama bulan Indonesia
$bulan_indonesia = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Tagihan Infaq - SCHOBANK</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        html { scroll-padding-top:70px; scroll-behavior:smooth; }
        :root {
            --success:#00D084;
            --warning:#FFB020;
            --elegant-dark:#2c3e50;
            --gray-50:#F9FAFB;
            --gray-200:#E5E7EB;
            --gray-300:#D1D5DB;
            --gray-400:#9CA3AF;
            --gray-500:#6B7280;
            --gray-600:#4B5563;
            --gray-700:#374151;
            --gray-900:#111827;
            --white:#FFFFFF;
            --shadow-sm:0 1px 2px rgba(0,0,0,0.05);
            --shadow-md:0 6px 15px rgba(0,0,0,0.1);
            --radius:12px;
            --radius-lg:16px;
        }
        body {
            font-family:'Poppins',sans-serif;
            background:var(--gray-50);
            color:var(--gray-900);
            line-height:1.6;
            padding-top:70px;
            padding-bottom:100px;
        }
        .top-nav {
            position:fixed; top:0; left:0; right:0;
            background:var(--gray-50);
            padding:8px 20px;
            display:flex; align-items:center;
            height:70px; z-index:100;
        }
        .nav-logo { height:45px; max-width:180px; object-fit:contain; }
        .container { max-width:1200px; margin:0 auto; padding:20px; }
        .page-header { margin-bottom:24px; }
        .page-title { font-size:24px; font-weight:700; margin-bottom:6px; }
        .page-subtitle { font-size:14px; color:var(--gray-600); }

        .section-card {
            background:var(--white);
            border-radius:var(--radius-lg);
            box-shadow:var(--shadow-sm);
            overflow:hidden;
            margin-bottom:24px;
        }
        .section-header {
            padding:16px 20px;
            background:var(--elegant-dark);
            color:var(--white);
            display:flex; align-items:center; gap:8px;
        }
        .section-title { font-size:16px; font-weight:600; }
        .section-body { padding:20px; }

        .tagihan-grid { display:grid; gap:16px; }
        .tagihan-card {
            background:#F3F4F6;
            border-radius:var(--radius-lg);
            padding:16px 18px;
            border:2px solid var(--gray-200);
            transition:all 0.2s ease;
        }
        .tagihan-card:hover {
            border-color:var(--elegant-dark);
            box-shadow:var(--shadow-md);
        }
        .tagihan-header {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            margin-bottom:12px;
        }
        .tagihan-month { font-size:16px; font-weight:700; }
        .tagihan-year { font-size:12px; color:var(--gray-500); }
        .tagihan-amount { font-size:18px; font-weight:700; color:var(--gray-900); }
        .badge {
            display:inline-block;
            padding:3px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:600;
        }
        .badge-warning {
            background:#FEF3C7;
            color:#92400E;
        }
        .badge-success {
            background:#D1FAE5;
            color:#065F46;
        }
        .tagihan-body { font-size:12px; color:var(--gray-600); margin-bottom:12px; }
        .tagihan-body strong { display:block; font-size:13px; color:var(--gray-900); margin-top:2px; }
        .tagihan-footer {
            display:flex;
            justify-content:space-between;
            align-items:center;
            border-top:1px dashed var(--gray-300);
            padding-top:10px;
            margin-top:4px;
        }
        .tagihan-no {
            font-size:11px;
            font-family:'Courier New',monospace;
            color:var(--gray-500);
        }
        .btn {
            padding:8px 16px;
            border-radius:var(--radius);
            font-size:13px;
            font-weight:600;
            border:none;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:6px;
        }
        .btn-success { background:var(--success); color:#fff; }
        .btn-success:hover { background:#00B872; }
        .btn-disabled { background:var(--gray-300); color:var(--gray-500); cursor:not-allowed; }
        .empty-state { text-align:center; padding:40px 10px; color:var(--gray-500); }
        .empty-icon { font-size:48px; margin-bottom:10px; opacity:0.3; }
        .empty-title { font-size:16px; font-weight:600; color:var(--gray-700); margin-bottom:4px; }
        .empty-text { font-size:13px; }

        @media (max-width:768px){
            body{padding-bottom:110px;}
            .container{padding:16px;}
            .page-title{font-size:20px;}
            .nav-logo{height:38px;max-width:140px;}
        }
        @media (max-width:480px){
            body{padding-bottom:115px;}
            .page-title{font-size:18px;}
            .nav-logo{height:32px;max-width:120px;}
        }
    </style>
</head>
<body>
<nav class="top-nav">
    <img src="<?php echo $base_url; ?>/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
</nav>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">Tagihan Infaq</h1>
        <p class="page-subtitle">Tagihan berjalan dan riwayat infaq Anda.</p>
    </div>

    <!-- Tagihan Berjalan -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-hourglass-half"></i>
            <div class="section-title">Tagihan Berjalan</div>
        </div>
        <div class="section-body">
            <?php if (empty($tagihan_berjalan)): ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <div class="empty-title">Tidak Ada Tagihan Berjalan</div>
                    <div class="empty-text">Semua tagihan infaq sudah terselesaikan.</div>
                </div>
            <?php else: ?>
                <div class="tagihan-grid">
                    <?php foreach ($tagihan_berjalan as $tagihan): ?>
                        <?php $bulan_nama = $bulan_indonesia[$tagihan['bulan']] ?? $tagihan['bulan']; ?>
                        <div class="tagihan-card">
                            <div class="tagihan-header">
                                <div>
                                    <div class="tagihan-month"><?php echo $bulan_nama; ?></div>
                                    <div class="tagihan-year"><?php echo $tagihan['tahun']; ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div class="tagihan-amount">
                                        Rp<?php echo number_format($tagihan['nominal_tagihan'], 0, ',', '.'); ?>
                                    </div>
                                    <span class="badge badge-warning">Belum Bayar</span>
                                </div>
                            </div>
                            <div class="tagihan-body">
                                Status
                                <strong>Menunggu pembayaran</strong>
                            </div>
                            <div class="tagihan-footer">
                                <div class="tagihan-no">Belum ada transaksi</div>
                                <!-- tombol pakai data-attribute untuk SweetAlert confirm -->
                                <button type="button"
                                        class="btn btn-success btn-bayar"
                                        data-tagihan-id="<?php echo $tagihan['tagihan_id']; ?>"
                                        data-nominal="<?php echo number_format($tagihan['nominal_tagihan'], 0, ',', '.'); ?>">
                                    <i class="fas fa-check-circle"></i> Bayar
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Riwayat Tagihan -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-history"></i>
            <div class="section-title">Riwayat Tagihan</div>
        </div>
        <div class="section-body">
            <?php if (empty($tagihan_riwayat)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📄</div>
                    <div class="empty-title">Belum Ada Riwayat</div>
                    <div class="empty-text">Belum ada tagihan infaq yang tercatat sebagai lunas.</div>
                </div>
            <?php else: ?>
                <div class="tagihan-grid">
                    <?php foreach ($tagihan_riwayat as $tagihan): ?>
                        <?php
                        $bulan_nama      = $bulan_indonesia[$tagihan['bulan']] ?? $tagihan['bulan'];
                        $tgl_timestamp   = $tagihan['tanggal_bayar'] ? strtotime($tagihan['tanggal_bayar']) : null;
                        $tgl_hari        = $tgl_timestamp ? date('d ', $tgl_timestamp) : '';
                        $tgl_bulan_index = $tgl_timestamp ? (int)date('m', $tgl_timestamp) : 0;
                        $tgl_bulan_nama  = $tgl_timestamp ? ($bulan_indonesia[$tgl_bulan_index] ?? date('m', $tgl_timestamp)) : '';
                        $tgl_tahun_jam   = $tgl_timestamp ? date(' Y, H:i', $tgl_timestamp) : '';
                        ?>
                        <div class="tagihan-card">
                            <div class="tagihan-header">
                                <div>
                                    <div class="tagihan-month"><?php echo $bulan_nama; ?></div>
                                    <div class="tagihan-year"><?php echo $tagihan['tahun']; ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div class="tagihan-amount">
                                        Rp<?php echo number_format($tagihan['nominal_tagihan'], 0, ',', '.'); ?>
                                    </div>
                                    <span class="badge badge-success">Sudah Bayar</span>
                                </div>
                            </div>
                            <div class="tagihan-body">
                                Tanggal Bayar
                                <strong>
                                    <?php
                                    if ($tgl_timestamp) {
                                        echo $tgl_hari . $tgl_bulan_nama . $tgl_tahun_jam . ' WIB';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </strong>
                            </div>
                            <div class="tagihan-footer">
                                <div class="tagihan-no">
                                    <?php echo $tagihan['no_transaksi'] ? htmlspecialchars($tagihan['no_transaksi']) : '-'; ?>
                                </div>
                                <button class="btn btn-disabled" disabled>
                                    <i class="fas fa-check"></i> Lunas
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$bottom_nav_path = __DIR__ . '/bottom_navbar.php';
if (file_exists($bottom_nav_path)) {
    include $bottom_nav_path;
}
?>

<!-- Form hidden untuk submit bayar lewat JS + SweetAlert -->
<form id="formBayarTagihan" method="POST" style="display:none;">
    <input type="hidden" name="tagihan_id" id="inputTagihanId">
    <input type="hidden" name="bayar_tagihan" value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Konfirmasi bayar pakai SweetAlert2
    document.querySelectorAll('.btn-bayar').forEach(function(btn) {
        btn.addEventListener('click', function () {
            const tagihanId = this.getAttribute('data-tagihan-id');
            const nominal   = this.getAttribute('data-nominal');

            Swal.fire({
                title: 'Konfirmasi Pembayaran',
                html: 'Apakah Anda yakin ingin membayar tagihan ini?<br><b>Nominal: Rp' + nominal + '</b>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Bayar',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form  = document.getElementById('formBayarTagihan');
                    const input = document.getElementById('inputTagihanId');
                    input.value = tagihanId;
                    form.submit();
                }
            });
        });
    });

<?php if (!empty($swal_type)): ?>
    // Notifikasi hasil pembayaran
    Swal.fire({
        icon: '<?php echo $swal_type; ?>',
        title: '<?php echo addslashes($swal_title); ?>',
        text: '<?php echo addslashes($swal_message); ?>',
        confirmButtonText: 'OK'
    }).then(() => {
        window.location.href = window.location.pathname + window.location.search;
    });
<?php endif; ?>
</script>
</body>
</html>
