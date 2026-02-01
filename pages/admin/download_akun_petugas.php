<?php
/**
 * Download Data Akun Petugas
 * File: pages/admin/download_akun_petugas.php
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
// GET DATA
// ============================================

// Search Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_query = "";
$search_params = [];
$search_types = "";

if (!empty($search)) {
    $search_query = " AND (u.username LIKE ? OR pp.petugas1_nama LIKE ? OR pp.petugas2_nama LIKE ?)";
    $search_term = "%" . $search . "%";
    $search_params = [$search_term, $search_term, $search_term];
    $search_types = "sss";
}

$query = "SELECT u.username, pp.petugas1_nama, pp.petugas2_nama
          FROM users u 
          LEFT JOIN petugas_profiles pp ON u.id = pp.user_id
          WHERE u.role = 'petugas'" . $search_query . "
          ORDER BY u.nama ASC";

$stmt = $conn->prepare($query);
if (!empty($search_params)) {
    $stmt->bind_param($search_types, ...$search_params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

if (empty($data)) {
    die('Tidak ada data akun petugas untuk di-export.');
}

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
        $this->SetDrawColor(0, 0, 0); // Reset draw color to black
        $this->SetLineWidth(0.2); // Standard line width
        $this->Line(10, 32, 200, 32);
    }

    public function Footer()
    {
        $this->SetY(-20);

        // Keterangan Password removed from Footer

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
$pdf->SetTitle('Data Akun Petugas');
$pdf->SetMargins(12, 36, 12);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

// === JUDUL LAPORAN ===
$pdf->Ln(2); // Reduced top spacing since margin is adjusted
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 8, 'DATA AKUN PETUGAS', 0, 1, 'C');

$bulan_indonesia = [
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
$tanggal_pdf = 'Data Per ' . date('j') . ' ' . $bulan_indonesia[date('n')] . ' ' . date('Y') . ' ' . date('H:i') . ' WIB';

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 5, $tanggal_pdf, 0, 1, 'C');
$pdf->Ln(6);

// === TABLE HEADER ===
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220); // Light grey like student PDF
$pdf->SetTextColor(0, 0, 0); // Black text

// Column widths
// Total width for A4 portrait with 12mm margins (210 - 24 = 186mm)
// Previous widths: [15, 50, 60, 60] = 185mm. Close enough.
$w = [15, 50, 60, 60];
$headers = ['No', 'Username', 'Petugas 1', 'Petugas 2'];

foreach ($headers as $i => $header) {
    $pdf->Cell($w[$i], 5, $header, 1, 0, 'C', true); // Height 5 to match
}
$pdf->Ln();

// === TABLE DATA ===
$pdf->SetFont('helvetica', '', 7); // Font size 7
$pdf->SetFillColor(255, 255, 255); // White background

$no = 1;
foreach ($data as $row) {
    // No
    $pdf->Cell($w[0], 5, $no, 1, 0, 'C', true);

    // Username
    $pdf->Cell($w[1], 5, $row['username'], 1, 0, 'L', true);

    // Petugas 1
    $pdf->Cell($w[2], 5, $row['petugas1_nama'], 1, 0, 'L', true);

    // Petugas 2
    $pdf->Cell($w[3], 5, $row['petugas2_nama'], 1, 1, 'L', true);

    $no++;
}

// === KETERANGAN ===
$pdf->Ln(8);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(220, 38, 38);
$pdf->Cell(20, 5, 'PENTING:', 0, 0, 'L');
$pdf->SetFont('helvetica', 'I', 9);
$pdf->MultiCell(0, 5, 'Seluruh akun di atas menggunakan password default "12345678". Demi keamanan, dimohon untuk segera melakukan penggantian password saat pertama kali login atau memulai tugas.', 0, 'L');


// === OUTPUT PDF ===
$filename = 'Data_Akun_Petugas_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'I');
