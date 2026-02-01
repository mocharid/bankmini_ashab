<?php
/**
 * Download Data Kehadiran Petugas PDF
 * File: pages/admin/download_kehadiran_petugas.php
 * 
 * Export data kehadiran petugas ke format PDF menggunakan TCPDF
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

// Cek Role Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../login.php");
    exit;
}

// Set timezone
date_default_timezone_set('Asia/Jakarta');

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
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])
    ? $_GET['start_date']
    : '';

$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])
    ? $_GET['end_date']
    : '';

// Ensure start_date <= end_date
if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// ============================================
// GET DATA - SEMUA DATA (tanpa pagination)
// ============================================
$baseQuery = "SELECT 
                ps.tanggal, 
                COALESCE(pp1.petugas1_nama, pp1.petugas2_nama) AS petugas1_nama,
                COALESCE(pp2.petugas2_nama, pp2.petugas1_nama) AS petugas2_nama,
                ps1.status AS petugas1_status,
                ps1.waktu_masuk AS petugas1_masuk,
                ps1.waktu_keluar AS petugas1_keluar,
                ps2.status AS petugas2_status,
                ps2.waktu_masuk AS petugas2_masuk,
                ps2.waktu_keluar AS petugas2_keluar
             FROM petugas_shift ps
             LEFT JOIN petugas_profiles pp1 ON ps.petugas1_id = pp1.user_id
             LEFT JOIN petugas_profiles pp2 ON ps.petugas2_id = pp2.user_id
             LEFT JOIN petugas_status ps1 
                ON ps.id = ps1.petugas_shift_id AND ps1.petugas_type = 'petugas1'
             LEFT JOIN petugas_status ps2 
                ON ps.id = ps2.petugas_shift_id AND ps2.petugas_type = 'petugas2'";

// Add WHERE clauses
$whereClause = [];
$params = [];
$types = "";

// Filter hanya jadwal yang tanggalnya sudah lewat atau hari ini
$whereClause[] = "ps.tanggal <= CURDATE()";

if (!empty($start_date)) {
    $whereClause[] = "ps.tanggal >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if (!empty($end_date)) {
    $whereClause[] = "ps.tanggal <= ?";
    $params[] = $end_date;
    $types .= "s";
}

$whereString = " WHERE " . implode(" AND ", $whereClause);
$baseQuery .= $whereString;
$baseQuery .= " ORDER BY ps.tanggal ASC";

// Execute query
$stmt = $conn->prepare($baseQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$stmt->close();

if (empty($data)) {
    die('Tidak ada data kehadiran untuk di-export.');
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

// Map status untuk label
$status_map = [
    'hadir' => 'Hadir',
    'tidak_hadir' => 'Tidak Hadir',
    'sakit' => 'Sakit',
    'izin' => 'Izin',
    '-' => '-'
];

// ============================================
// CUSTOM PDF CLASS
// ============================================
class MYPDF extends TCPDF
{
    public function Header()
    {
        // Logo Header Image - centered on top
        $headerImage = ASSETS_PATH . '/images/side.png';
        if (file_exists($headerImage)) {
            // Logo side.png - centered, 25mm width
            $this->Image($headerImage, 92.5, 5, 25, 0, 'PNG', '', 'T', false, 300, 'C', false, false, 0);
        } else {
            $this->SetFont('helvetica', 'B', 14);
            $this->Cell(0, 8, 'KASDIG', 0, false, 'C', 0);
        }

        // Move cursor below logo for text
        $this->SetY(18);

        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 5, 'Sistem Bank Mini Sekolah Digital', 0, 1, 'C');

        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 4, 'SMK Plus Ashabulyamin Cianjur', 0, 1, 'C');

        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 4, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, 1, 'C');

        // Draw line at Y=32
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->Line(10, 32, 200, 32);
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
// CREATE PDF - PORTRAIT
// ============================================
$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('KASDIG SMK Plus Ashabulyamin');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Data Kehadiran Petugas');
$pdf->SetMargins(12, 36, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// === JUDUL LAPORAN ===
$pdf->Ln(2);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 8, 'DATA KEHADIRAN PETUGAS', 0, 1, 'C');

// Filter Info
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(90, 90, 90);
if (!empty($start_date) && !empty($end_date)) {
    $periode_text = formatTanggalIndo($start_date) . ' - ' . formatTanggalIndo($end_date);
} elseif (!empty($start_date)) {
    $periode_text = 'Dari ' . formatTanggalIndo($start_date);
} elseif (!empty($end_date)) {
    $periode_text = 'Sampai ' . formatTanggalIndo($end_date);
} else {
    $periode_text = 'Semua Data';
}
$pdf->Cell(0, 6, 'Periode: ' . $periode_text, 0, 1, 'C');
$pdf->Ln(6);

// === TABLE HEADER ===
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->SetTextColor(0, 0, 0);

// Column widths (total ~186 for A4 Portrait with 12mm margins)
// No, Tanggal, Nama Petugas, Status, Jam Masuk, Jam Keluar
$w = [10, 26, 60, 34, 28, 28];
$headers = ['No', 'Tanggal', 'Nama Petugas', 'Status', 'Jam Masuk', 'Jam Keluar'];

foreach ($headers as $i => $header) {
    $pdf->Cell($w[$i], 6, $header, 1, 0, 'C', true);
}
$pdf->Ln();

// === TABLE DATA ===
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$no = 1;
foreach ($data as $row) {
    // Simpan posisi Y awal
    $startY = $pdf->GetY();
    $startX = $pdf->GetX();

    // Row untuk Petugas 1 - No dan Tanggal akan di-render di akhir dengan tinggi penuh
    $pdf->SetXY($startX + $w[0] + $w[1], $startY); // Skip No dan Tanggal columns dulu

    // Nama Petugas 1
    $nama1 = $row['petugas1_nama'] ?? '-';
    if (strlen($nama1) > 32) {
        $nama1 = substr($nama1, 0, 30) . '..';
    }
    $pdf->Cell($w[2], 5, $nama1, 1, 0, 'L', true);

    // Status Petugas 1
    $s1 = $row['petugas1_status'] ?? '-';
    $s1_label = $status_map[$s1] ?? $s1;
    $pdf->Cell($w[3], 5, $s1_label, 1, 0, 'C', true);

    // Jam Masuk Petugas 1
    $jam_masuk1 = ($s1 === 'hadir' && $row['petugas1_masuk'])
        ? date('H:i', strtotime($row['petugas1_masuk']))
        : '-';
    $pdf->Cell($w[4], 5, $jam_masuk1, 1, 0, 'C', true);

    // Jam Keluar Petugas 1
    $jam_keluar1 = ($s1 === 'hadir' && $row['petugas1_keluar'])
        ? date('H:i', strtotime($row['petugas1_keluar']))
        : '-';
    $pdf->Cell($w[5], 5, $jam_keluar1, 1, 1, 'C', true);

    // Row untuk Petugas 2
    $pdf->SetX($startX + $w[0] + $w[1]); // Skip No dan Tanggal columns

    // Nama Petugas 2
    $nama2 = $row['petugas2_nama'] ?? '-';
    if (strlen($nama2) > 32) {
        $nama2 = substr($nama2, 0, 30) . '..';
    }
    $pdf->Cell($w[2], 5, $nama2, 1, 0, 'L', true);

    // Status Petugas 2
    $s2 = $row['petugas2_status'] ?? '-';
    $s2_label = $status_map[$s2] ?? $s2;
    $pdf->Cell($w[3], 5, $s2_label, 1, 0, 'C', true);

    // Jam Masuk Petugas 2
    $jam_masuk2 = ($s2 === 'hadir' && $row['petugas2_masuk'])
        ? date('H:i', strtotime($row['petugas2_masuk']))
        : '-';
    $pdf->Cell($w[4], 5, $jam_masuk2, 1, 0, 'C', true);

    // Jam Keluar Petugas 2
    $jam_keluar2 = ($s2 === 'hadir' && $row['petugas2_keluar'])
        ? date('H:i', strtotime($row['petugas2_keluar']))
        : '-';
    $pdf->Cell($w[5], 5, $jam_keluar2, 1, 1, 'C', true);

    // Simpan posisi Y akhir
    $endY = $pdf->GetY();

    // Kembali ke posisi awal untuk render No dan Tanggal dengan tinggi penuh (10mm)
    $pdf->SetXY($startX, $startY);

    // No - tinggi 10mm (2 baris), teks di tengah vertikal
    $pdf->Cell($w[0], 10, $no, 1, 0, 'C', true);

    // Tanggal - tinggi 10mm (2 baris), teks di tengah vertikal  
    $pdf->Cell($w[1], 10, date('d/m/Y', strtotime($row['tanggal'])), 1, 0, 'C', true);

    // Pindah ke baris berikutnya
    $pdf->SetY($endY);
    $no++;
}

// === OUTPUT PDF ===
$filename = 'Data_Kehadiran_Petugas_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'I'); // I = inline (browser), D = download
