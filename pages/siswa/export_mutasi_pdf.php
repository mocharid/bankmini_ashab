<?php
/**
 * Export Mutasi Rekening ke PDF (BCA Style)
 * File: pages/siswa/export_mutasi_pdf.php
 */

// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'siswa') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
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
    $project_root = $current_dir;
}

// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
if (!defined('TCPDF_FONTS')) {
    define('TCPDF_FONTS', PROJECT_ROOT . '/TCPDF/fonts/');
}

// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// ============================================
// LOAD TCPDF
// ============================================
$tcpdf_file = null;
$possible_tcpdf_paths = [
    PROJECT_ROOT . '/vendor/tecnickcom/tcpdf/tcpdf.php',
    PROJECT_ROOT . '/TCPDF/tcpdf.php',
    PROJECT_ROOT . '/tcpdf/tcpdf.php',
];

foreach ($possible_tcpdf_paths as $path) {
    if (file_exists($path)) {
        $tcpdf_file = $path;
        break;
    }
}

if (!$tcpdf_file && file_exists(PROJECT_ROOT . '/vendor/autoload.php')) {
    require_once PROJECT_ROOT . '/vendor/autoload.php';
    if (class_exists('TCPDF')) {
        $tcpdf_file = 'composer';
    }
}

if (!$tcpdf_file) {
    die('TCPDF library tidak ditemukan.');
}

if ($tcpdf_file !== 'composer' && !class_exists('TCPDF')) {
    require_once $tcpdf_file;
}

// ============================================
// SESSION VALIDATION
// ============================================
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'siswa') {
    die('Akses ditolak.');
}
if (!isset($_SESSION['user_id'])) {
    die('Session tidak valid.');
}
$user_id = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$filter_jenis = $_GET['jenis'] ?? '';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';

// ============================================
// AMBIL DATA USER DAN REKENING
// ============================================
$stmt = $conn->prepare("
    SELECT u.id, u.nama, sp.nis_nisn, r.saldo, r.id as rekening_id, r.no_rekening
    FROM users u
    LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN rekening r ON u.id = r.user_id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$siswa_info = $result->fetch_assoc();
$stmt->close();

if (!$siswa_info) {
    die('Akun tidak ditemukan.');
}

$rekening_id = $siswa_info['rekening_id'];
$no_rekening = $siswa_info['no_rekening'];

// ============================================
// QUERY TRANSAKSI
// ============================================
$where_conditions = "WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)";
$params = [$rekening_id, $rekening_id];
$types = "ii";

// Filter jenis transaksi
if (!empty($filter_jenis)) {
    if ($filter_jenis === 'transfer_masuk') {
        $where_conditions .= " AND t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ?";
        $params[] = $rekening_id;
        $types .= "i";
    } elseif ($filter_jenis === 'transfer_keluar') {
        $where_conditions .= " AND t.jenis_transaksi = 'transfer' AND t.rekening_id = ?";
        $params[] = $rekening_id;
        $types .= "i";
    } else {
        $where_conditions .= " AND t.jenis_transaksi = ?";
        $params[] = $filter_jenis;
        $types .= "s";
    }
}

// Filter tanggal
if (!empty($filter_start_date)) {
    $where_conditions .= " AND DATE(t.created_at) >= ?";
    $params[] = $filter_start_date;
    $types .= "s";
}
if (!empty($filter_end_date)) {
    $where_conditions .= " AND DATE(t.created_at) <= ?";
    $params[] = $filter_end_date;
    $types .= "s";
}

$query = "
    SELECT
        t.no_transaksi, t.jenis_transaksi, t.jumlah, t.created_at, t.status,
        t.rekening_tujuan_id, t.rekening_id,
        r1.no_rekening as rekening_asal,
        r2.no_rekening as rekening_tujuan,
        u1.nama as nama_pengirim,
        u2.nama as nama_penerima
    FROM transaksi t
    LEFT JOIN rekening r1 ON t.rekening_id = r1.id
    LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
    LEFT JOIN users u1 ON r1.user_id = u1.id
    LEFT JOIN users u2 ON r2.user_id = u2.id
    $where_conditions
    ORDER BY t.created_at DESC
";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$mutasi_data = [];

while ($row = $result->fetch_assoc()) {
    $mutasi_data[] = $row;
}
$stmt->close();
$conn->close();

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatTanggalIndo($date)
{
    $bulan = [
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
    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = date('Y', $timestamp);
    return $day . '/' . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . $year;
}

function formatRupiah($amount)
{
    return number_format($amount, 2, ',', '.');
}

// ============================================
// CUSTOM PDF CLASS
// ============================================
class MYPDF extends TCPDF
{
    public function Header()
    {
        // Kosong - kita buat header sendiri di body
    }

    public function Footer()
    {
        // Kosong
    }
}

// ============================================
// CREATE PDF
// ============================================
$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('KASDIG');
$pdf->SetAuthor('KASDIG');
$pdf->SetTitle('Mutasi Rekening');
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ============================================
// HEADER - TITLE ONLY (tanpa logo)
// ============================================

// Title (rata tengah halaman penuh)
$pdf->SetFont('dejavusans', 'B', 18);
$pdf->SetTextColor(0, 51, 102); // Biru BCA
$pdf->SetXY(15, 15);
$pdf->Cell(180, 10, 'MUTASI REKENING', 0, 1, 'C');

$pdf->Ln(5);

// ============================================
// INFORMASI REKENING (BOX) - Layout 1 kolom ke bawah, lebih pendek
// ============================================
$start_y = $pdf->GetY();

// Border box - lebih pendek (100mm width)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.3);
$pdf->Rect(15, $start_y, 100, 30);

$pdf->SetFont('dejavusans', '', 8);
$pdf->SetTextColor(0, 0, 0);

$line_height = 4.5;
$label_width = 32;
$colon_width = 3;

// NO. REKENING
$pdf->SetXY(17, $start_y + 2);
$pdf->Cell($label_width, $line_height, 'NO. REKENING', 0, 0, 'L');
$pdf->Cell($colon_width, $line_height, ':', 0, 0, 'L');
$pdf->Cell(100, $line_height, $no_rekening, 0, 1, 'L');

// NAMA
$pdf->SetXY(17, $start_y + 2 + $line_height);
$pdf->Cell($label_width, $line_height, 'NAMA', 0, 0, 'L');
$pdf->Cell($colon_width, $line_height, ':', 0, 0, 'L');
$pdf->Cell(100, $line_height, strtoupper($siswa_info['nama']), 0, 1, 'L');

// HALAMAN
$pdf->SetXY(17, $start_y + 2 + ($line_height * 2));
$pdf->Cell($label_width, $line_height, 'HALAMAN', 0, 0, 'L');
$pdf->Cell($colon_width, $line_height, ':', 0, 0, 'L');
$pdf->Cell(100, $line_height, '1/1', 0, 1, 'L');

// JENIS TRANSAKSI
$pdf->SetXY(17, $start_y + 2 + ($line_height * 3));
$pdf->Cell($label_width, $line_height, 'JENIS TRANSAKSI', 0, 0, 'L');
$pdf->Cell($colon_width, $line_height, ':', 0, 0, 'L');
$jenis_label = empty($filter_jenis) ? 'SEMUA' : strtoupper(str_replace('_', ' ', $filter_jenis));
$pdf->Cell(100, $line_height, $jenis_label, 0, 1, 'L');

// PERIODE
$pdf->SetXY(17, $start_y + 2 + ($line_height * 4));
$pdf->Cell($label_width, $line_height, 'PERIODE', 0, 0, 'L');
$pdf->Cell($colon_width, $line_height, ':', 0, 0, 'L');
$periode_start = !empty($filter_start_date) ? formatTanggalIndo($filter_start_date) : date('d/m/Y', strtotime('-30 days'));
$periode_end = !empty($filter_end_date) ? formatTanggalIndo($filter_end_date) : date('d/m/Y');
$pdf->Cell(100, $line_height, $periode_start . ' - ' . $periode_end, 0, 1, 'L');

// MATA UANG
$pdf->SetXY(17, $start_y + 2 + ($line_height * 5));
$pdf->Cell($label_width, $line_height, 'MATA UANG', 0, 0, 'L');
$pdf->Cell($colon_width, $line_height, ':', 0, 0, 'L');
$pdf->Cell(100, $line_height, 'IDR', 0, 1, 'L');

$pdf->SetY($start_y + 32);

// ============================================
// CATATAN BOX
// ============================================
$catatan_y = $pdf->GetY();
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect(15, $catatan_y, 180, 16);

$pdf->SetFont('dejavusans', 'B', 6);
$pdf->SetXY(17, $catatan_y + 2);
$pdf->Cell(0, 3, 'CATATAN:', 0, 1, 'L');

$pdf->SetFont('dejavusans', '', 5.5);
$pdf->SetXY(17, $catatan_y + 5);
$catatan_text = "Apabila nasabah tidak melakukan komplain terhadap Laporan Mutasi Rekening ini sampai dengan akhir bulan, KASDIG beritikad baik untuk melakukan koreksi apabila ada kesalahan pada Laporan Mutasi Rekening berikutnya, nasabah dianggap telah menyetujui segala data yang tercantum pada Laporan Mutasi Rekening ini.";
$pdf->MultiCell(175, 3, $catatan_text, 0, 'L');

$pdf->SetY($catatan_y + 18);

// ============================================
// TABEL TRANSAKSI (TANPA BORDER)
// ============================================
// Header Tabel
$pdf->SetFillColor(0, 51, 102); // Biru BCA
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 8);

$col_widths = [25, 95, 45, 15]; // Tanggal, Keterangan, Mutasi, DB/CR
$pdf->Cell($col_widths[0], 7, 'TANGGAL', 0, 0, 'C', true);
$pdf->Cell($col_widths[1], 7, 'KETERANGAN', 0, 0, 'C', true);
$pdf->Cell($col_widths[2], 7, 'MUTASI', 0, 0, 'C', true);
$pdf->Cell($col_widths[3], 7, '', 0, 1, 'C', true);

// Data Tabel
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('dejavusans', '', 7);

if (empty($mutasi_data)) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(180, 10, 'Tidak ada transaksi untuk periode ini', 0, 1, 'C', true);
} else {
    $row_count = 0;
    foreach ($mutasi_data as $mutasi) {
        // Alternating row colors
        if ($row_count % 2 == 0) {
            $pdf->SetFillColor(255, 255, 255);
        } else {
            $pdf->SetFillColor(248, 248, 248);
        }

        // Determine transaction type
        $is_incoming = ($mutasi['jenis_transaksi'] == 'setor' ||
            ($mutasi['jenis_transaksi'] == 'transfer' && $mutasi['rekening_tujuan'] == $no_rekening) ||
            ($mutasi['jenis_transaksi'] == 'transaksi_qr' && $mutasi['rekening_tujuan'] == $no_rekening) ||
            ($mutasi['jenis_transaksi'] == 'infaq' && $mutasi['rekening_tujuan'] == $no_rekening));

        $is_debit = !$is_incoming;

        // Determine description
        $nama_penerima = $mutasi['nama_penerima'] ?? '';
        $is_transfer_to_bendahar = (stripos($nama_penerima, 'bendahar') !== false);

        if ($is_transfer_to_bendahar && $mutasi['jenis_transaksi'] == 'transfer' && !$is_incoming) {
            $description = "PEMBAYARAN INFAQ BULANAN";
        } elseif ($mutasi['jenis_transaksi'] === 'infaq') {
            $description = $is_debit ? 'INFAQ' : 'PENERIMAAN INFAQ';
        } elseif ($mutasi['jenis_transaksi'] === 'transaksi_qr') {
            $description = $is_debit ? 'PEMBAYARAN QR' : 'PENERIMAAN QR';
        } elseif ($mutasi['jenis_transaksi'] === 'transfer') {
            if ($is_incoming) {
                $description = 'TRANSFER MASUK DARI ' . strtoupper($mutasi['nama_pengirim'] ?? 'UNKNOWN');
            } else {
                $description = 'TRANSFER KELUAR KE ' . strtoupper($mutasi['nama_penerima'] ?? 'UNKNOWN');
            }
        } elseif ($mutasi['jenis_transaksi'] === 'setor') {
            $description = 'SETORAN TUNAI';
        } elseif ($mutasi['jenis_transaksi'] === 'tarik') {
            $description = 'PENARIKAN TUNAI';
        } elseif ($mutasi['jenis_transaksi'] === 'bayar') {
            $description = 'PEMBAYARAN TAGIHAN';
        } else {
            $description = 'TRANSAKSI';
        }

        // Add rekening info on same line
        if ($mutasi['jenis_transaksi'] === 'transfer') {
            if ($is_incoming) {
                $description .= ' ' . ($mutasi['rekening_asal'] ?? '');
            } else {
                $description .= ' ' . ($mutasi['rekening_tujuan'] ?? '');
            }
        }

        // Row height
        $row_height = 7;

        // Date
        $tanggal = date('d/m/Y', strtotime($mutasi['created_at']));

        // Amount
        $jumlah = formatRupiah(abs($mutasi['jumlah']));

        // DB/CR
        $db_cr = $is_debit ? 'DB' : 'CR';

        // Draw cells without borders
        $pdf->Cell($col_widths[0], $row_height, $tanggal, 0, 0, 'C', true);
        $pdf->Cell($col_widths[1], $row_height, $description, 0, 0, 'L', true);
        $pdf->Cell($col_widths[2], $row_height, $jumlah, 0, 0, 'R', true);
        $pdf->Cell($col_widths[3], $row_height, $db_cr, 0, 1, 'C', true);

        $row_count++;
    }
}

// ============================================
// OUTPUT PDF
// ============================================
$filename = 'Mutasi_Rekening_' . $no_rekening . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'I'); // I = inline (browser), D = download
