<?php
/**
 * Download Laporan Transaksi Petugas - Portrait Version
 * File: pages/admin/download_laporan_petugas.php
 * 
 * Style matching export_laporan_pdf.php (bendahara)
 * Data from data_riwayat_transaksi_petugas.php
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
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build WHERE clause
$where_clauses = ["1=1"];
$params = [];
$types = "";

if (!empty($start_date) && !empty($end_date)) {
    // Validate dates if provided
    if (!strtotime($start_date) || !strtotime($end_date)) {
        die('Tanggal tidak valid');
    }

    if (strtotime($start_date) > strtotime($end_date)) {
        die('Tanggal awal tidak boleh lebih dari tanggal akhir');
    }

    $where_clauses[] = "DATE(t.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

$where_sql = implode(" AND ", $where_clauses);

// Main Query - dengan jurusan
// Query harus sama dengan data_riwayat_transaksi_petugas.php
$sql_data = "SELECT 
                t.created_at,
                t.jumlah,
                t.jenis_transaksi,
                t.status,
                u.nama AS nama_siswa,
                tk.nama_tingkatan,
                k.nama_kelas,
                j.nama_jurusan
             FROM transaksi t
             JOIN rekening r ON t.rekening_id = r.id
             JOIN users u ON r.user_id = u.id
             LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
             LEFT JOIN kelas k ON sp.kelas_id = k.id
             LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
             LEFT JOIN jurusan j ON sp.jurusan_id = j.id
             JOIN users p ON t.petugas_id = p.id
             WHERE t.jenis_transaksi IN ('setor', 'tarik')
             AND p.role = 'petugas'
             AND $where_sql
             ORDER BY t.created_at DESC";

$stmt_data = $conn->prepare($sql_data);
if (!empty($params)) {
    $stmt_data->bind_param($types, ...$params);
}
$stmt_data->execute();
$result = $stmt_data->get_result();

$data = [];
$total_setor = 0;
$total_tarik = 0;
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
    if ($row['jenis_transaksi'] == 'setor') {
        $total_setor += $row['jumlah'];
    } elseif ($row['jenis_transaksi'] == 'tarik') {
        $total_tarik += $row['jumlah'];
    }
}

if (empty($data)) {
    die('Tidak ada data untuk di-export.');
}

$total_bersih = $total_setor - $total_tarik;

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
$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false); // Portrait
$pdf->SetCreator('KASDIG SMK Plus Ashabulyamin');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Laporan Transaksi Petugas');
$pdf->SetMargins(12, 36, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// === JUDUL LAPORAN ===
$pdf->Ln(2);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 8, 'LAPORAN TRANSAKSI PETUGAS', 0, 1, 'C');

// Filter Info
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(90, 90, 90);
if (!empty($start_date) && !empty($end_date)) {
    $periode_text = formatTanggalIndo($start_date) . ' - ' . formatTanggalIndo($end_date);
} else {
    $periode_text = 'Semua Riwayat';
}
$pdf->Cell(0, 6, 'Periode: ' . $periode_text, 0, 1, 'C');
$pdf->Ln(8);

// === TABLE HEADER ===
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220); // Light grey
$pdf->SetTextColor(0, 0, 0); // Black text

// Column widths (total ~186 for A4 Portrait with 12mm margins)
// No, Tanggal, Nama Siswa, Kelas, Jurusan, Jenis, Jumlah
$w = [10, 26, 38, 24, 50, 14, 24];
$headers = ['No', 'Tanggal', 'Nama Siswa', 'Kelas', 'Jurusan', 'Jenis', 'Jumlah'];

foreach ($headers as $i => $header) {
    $pdf->Cell($w[$i], 5, $header, 1, 0, 'C', true);
}
$pdf->Ln();

// === TABLE DATA ===
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$no = 1;

foreach ($data as $row) {
    // No
    $pdf->Cell($w[0], 5, $no, 1, 0, 'C', true);

    // Tanggal
    $pdf->Cell($w[1], 5, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C', true);

    // Nama Siswa
    $nama = $row['nama_siswa'];
    if (strlen($nama) > 24) {
        $nama = substr($nama, 0, 22) . '..';
    }
    $pdf->Cell($w[2], 5, $nama, 1, 0, 'L', true);

    // Kelas
    $kelas_text = trim(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? ''));
    if (strlen($kelas_text) > 14) {
        $kelas_text = substr($kelas_text, 0, 12) . '..';
    }
    $pdf->Cell($w[3], 5, $kelas_text ?: '-', 1, 0, 'C', true);

    // Jurusan - tampilkan lengkap
    $jurusan = $row['nama_jurusan'] ?? '-';
    $pdf->Cell($w[4], 5, $jurusan, 1, 0, 'L', true);

    // Jenis
    $pdf->Cell($w[5], 5, ucfirst($row['jenis_transaksi']), 1, 0, 'C', true);

    // Jumlah
    $pdf->Cell($w[6], 5, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R', true);

    $no++;
}

// === TOTAL BERSIH ===
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220); // Light grey sama dengan header
$pdf->SetTextColor(0, 0, 0); // Black text
$pdf->Cell(array_sum($w) - $w[6], 5, 'TOTAL BERSIH', 1, 0, 'C', true);
$pdf->Cell($w[6], 5, 'Rp ' . number_format($total_bersih, 0, ',', '.'), 1, 1, 'R', true);

// === KETERANGAN ===
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(80, 80, 80);
$pdf->MultiCell(0, 5, 'Keterangan: Data ini merupakan transaksi yang dilakukan oleh petugas untuk siswa di KASDIG.', 0, 'L');

// === OUTPUT PDF ===
$filename = 'Laporan_Transaksi_Petugas_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'I'); // I = inline (browser), D = download