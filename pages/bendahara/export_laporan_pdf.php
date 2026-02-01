<?php
/**
 * Export Laporan Pembayaran ke PDF
 * File: pages/bendahara/export_laporan_pdf.php
 */

// ============================================
// ADAPTIVE PATH DETECTION & INIT
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} else {
    $project_root = dirname(dirname($current_dir));
}

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Cek Role Bendahara
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bendahara') {
    header("Location: ../../login.php");
    exit;
}

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

// Coba Composer autoloader jika belum ketemu
if (!$tcpdf_file && file_exists(PROJECT_ROOT . '/vendor/autoload.php')) {
    require_once PROJECT_ROOT . '/vendor/autoload.php';
    if (class_exists('TCPDF')) {
        $tcpdf_file = 'composer';
    }
}

if (!$tcpdf_file) {
    die('TCPDF library tidak ditemukan. Hubungi administrator.');
}

if ($tcpdf_file !== 'composer' && !class_exists('TCPDF')) {
    require_once $tcpdf_file;
}

// ============================================
// GET FILTERS
// ============================================
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');
$item_id_filter = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

// Get tagihan item name if filtered
$item_name = 'Semua Tagihan';
if ($item_id_filter > 0) {
    $stmt_item = $conn->prepare("SELECT nama_item FROM pembayaran_item WHERE id = ?");
    $stmt_item->bind_param("i", $item_id_filter);
    $stmt_item->execute();
    $item_result = $stmt_item->get_result();
    if ($row_item = $item_result->fetch_assoc()) {
        $item_name = $row_item['nama_item'];
    }
}

// Build Where Clause
$where_clauses = ["1=1"];
$params = [];
$types = "";

if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "DATE(ptr.tanggal_bayar) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

if ($item_id_filter > 0) {
    $where_clauses[] = "pi.id = ?";
    $params[] = $item_id_filter;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Main Query
$sql_data = "SELECT 
                ptr.no_pembayaran, 
                ptr.tanggal_bayar, 
                ptr.nominal_bayar, 
                u.nama AS nama_siswa, 
                tk.nama_tingkatan, 
                k.nama_kelas,
                j.nama_jurusan,
                pi.nama_item
             FROM pembayaran_transaksi ptr
             JOIN users u ON ptr.siswa_id = u.id
             LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
             LEFT JOIN kelas k ON sp.kelas_id = k.id
             LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
             LEFT JOIN jurusan j ON sp.jurusan_id = j.id
             JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
             JOIN pembayaran_item pi ON pt.item_id = pi.id
             WHERE $where_sql
             ORDER BY ptr.tanggal_bayar DESC";

$stmt_data = $conn->prepare($sql_data);
if (!empty($params)) {
    $stmt_data->bind_param($types, ...$params);
}
$stmt_data->execute();
$result = $stmt_data->get_result();

$data = [];
$total_nominal = 0;
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
    $total_nominal += $row['nominal_bayar'];
}

if (empty($data)) {
    die('Tidak ada data untuk di-export.');
}

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
    return $day . ' ' . $bulan[$month] . ' ' . $year;
}

// ============================================
// CUSTOM PDF CLASS
// ============================================
class MYPDF extends TCPDF
{
    public function Header()
    {
        $this->SetY(8);

        // Logo
        $logo = ASSETS_PATH . '/images/logo.png';
        if (file_exists($logo)) {
            $this->Image($logo, 15, 9, 24, 24, 'PNG');
        }

        // Header Text
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(70, 70, 70);
        $this->Cell(0, 8, 'KASDIG', 0, 1, 'C');

        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(50, 50, 50);
        $this->Cell(0, 6, 'SMK Plus Ashabulyamin Cianjur', 0, 1, 'C');

        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, 1, 'C');

        // Garis (A4 Landscape: 297mm width, margin 12mm)
        $this->SetDrawColor(120, 120, 120);
        $this->SetLineWidth(1.2);
        $this->Line(12, 40, 285, 40);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(12, 41.5, 285, 41.5);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(130, 130, 130);
        $this->Cell(0, 10, 'Dicetak pada ' . date('d/m/Y H:i') . ' WIB • Halaman ' . $this->getAliasNumPage() . ' dari ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// ============================================
// CREATE PDF
// ============================================
$pdf = new MYPDF('L', 'mm', 'A4', true, 'UTF-8', false); // Landscape
$pdf->SetCreator('Schobank SMK Plus Ashabulyamin');
$pdf->SetAuthor('Bendahara');
$pdf->SetTitle('Laporan Pembayaran');
$pdf->SetMargins(12, 48, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// === JUDUL LAPORAN ===
$pdf->Ln(6);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 8, 'LAPORAN PEMBAYARAN', 0, 1, 'C');

// Filter Info
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(90, 90, 90);
$periode_text = formatTanggalIndo($start_date) . ' - ' . formatTanggalIndo($end_date);
$pdf->Cell(0, 6, 'Periode: ' . $periode_text, 0, 1, 'C');
$pdf->Cell(0, 6, 'Tagihan Pembayaran: ' . $item_name, 0, 1, 'C');
$pdf->Ln(8);

// === TABLE HEADER ===
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(100, 100, 100);
$pdf->SetTextColor(255, 255, 255);

// Column widths (total ~273 for A4 Landscape with margins)
$w = [12, 42, 45, 50, 42, 45, 36];
$headers = ['No', 'Tgl Pembayaran', 'No. Pembayaran', 'Nama Siswa', 'Kelas', 'Jurusan', 'Nominal'];

foreach ($headers as $i => $header) {
    $pdf->Cell($w[$i], 8, $header, 1, 0, 'C', true);
}
$pdf->Ln();

// === TABLE DATA ===
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(245, 245, 245);

$no = 1;
$fill = false;
foreach ($data as $row) {
    $pdf->Cell($w[0], 7, $no, 1, 0, 'C', $fill);
    $pdf->Cell($w[1], 7, formatTanggalIndo($row['tanggal_bayar']), 1, 0, 'C', $fill);
    $pdf->Cell($w[2], 7, $row['no_pembayaran'], 1, 0, 'L', $fill);
    $pdf->Cell($w[3], 7, $row['nama_siswa'], 1, 0, 'L', $fill);

    // Kelas
    $kelas_text = trim(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? ''));
    $pdf->Cell($w[4], 7, $kelas_text ?: '-', 1, 0, 'C', $fill);

    // Jurusan
    $pdf->Cell($w[5], 7, $row['nama_jurusan'] ?? '-', 1, 0, 'L', $fill);

    // Nominal
    $pdf->Cell($w[6], 7, 'Rp ' . number_format($row['nominal_bayar'], 0, ',', '.'), 1, 1, 'R', $fill);

    $fill = !$fill;
    $no++;
}

// === KETERANGAN ===
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->MultiCell(0, 5, 'Keterangan: Data ini merupakan siswa yang membayar tagihan ' . $item_name . ' lewat KASDIG.', 0, 'L');

// === OUTPUT PDF ===
$filename = 'Laporan_Pembayaran_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'I'); // I = inline (browser), D = download
