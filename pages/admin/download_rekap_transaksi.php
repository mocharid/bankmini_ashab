<?php
/**
 * Download Rekap Transaksi - Portrait Version
 * File: pages/admin/download_rekap_transaksi.php
 * 
 * Style matching export_laporan_pdf.php (bendahara)
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

// Main Query - all transactions (admin + petugas)
$sql_data = "SELECT 
                t.no_transaksi,
                t.created_at,
                t.jumlah,
                t.jenis_transaksi,
                t.status,
                u.nama AS nama_siswa,
                r.no_rekening,
                tk.nama_tingkatan,
                tk.nama_tingkatan,
                k.nama_kelas,
                COALESCE(p.nama, 'Admin') AS nama_oleh,
                t.petugas_id,
                p.role AS role_petugas
             FROM transaksi t
             JOIN rekening r ON t.rekening_id = r.id
             JOIN users u ON r.user_id = u.id
             LEFT JOIN users p ON t.petugas_id = p.id
             LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
             LEFT JOIN kelas k ON sp.kelas_id = k.id
             LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
             WHERE t.jenis_transaksi IN ('setor', 'tarik')
             AND ((t.jenis_transaksi = 'setor' AND (t.status = 'approved' OR t.status IS NULL OR t.status = 'pending'))
                  OR (t.jenis_transaksi = 'tarik' AND t.status = 'approved'))
             AND $where_sql
             ORDER BY t.created_at DESC";

$stmt_data = $conn->prepare($sql_data);
if (!empty($params)) {
    $stmt_data->bind_param($types, ...$params);
}
$stmt_data->execute();
$result = $stmt_data->get_result();

$data = [];
// Stats containers
$stats = [
    'admin' => ['count' => 0, 'setor' => 0, 'tarik' => 0],
    'petugas' => ['count' => 0, 'setor' => 0, 'tarik' => 0],
    'total' => ['count' => 0, 'setor' => 0, 'tarik' => 0]
];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;

    // Determine type (Admin vs Petugas)
    // Admin: petugas_id NULL OR role='admin'
    // Petugas: role='petugas'
    $is_admin = is_null($row['petugas_id']) || $row['role_petugas'] === 'admin';
    $type = $is_admin ? 'admin' : 'petugas';

    $amount = (float) $row['jumlah'];

    // Update Stats
    $stats[$type]['count']++;
    $stats['total']['count']++;

    if ($row['jenis_transaksi'] == 'setor') {
        $stats[$type]['setor'] += $amount;
        $stats['total']['setor'] += $amount;
    } elseif ($row['jenis_transaksi'] == 'tarik') {
        $stats[$type]['tarik'] += $amount;
        $stats['total']['tarik'] += $amount;
    }
}

if (empty($data)) {
    die('Tidak ada data untuk di-export.');
}

$saldo_bersih = $stats['total']['setor'] - $stats['total']['tarik'];
$saldo_admin = $stats['admin']['setor'] - $stats['admin']['tarik'];
$saldo_petugas = $stats['petugas']['setor'] - $stats['petugas']['tarik'];

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

function maskAccountNumber($accountNumber)
{
    $length = strlen($accountNumber);
    if ($length <= 5) {
        return $accountNumber;
    }
    $first3 = substr($accountNumber, 0, 3);
    $last2 = substr($accountNumber, -2);
    $maskLength = $length - 5;
    $mask = str_repeat('*', $maskLength);
    return $first3 . $mask . $last2;
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
$pdf->SetTitle('Rekapitulasi Transaksi');
$pdf->SetMargins(12, 36, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// === JUDUL LAPORAN ===
$pdf->Ln(2); // Reduced top spacing
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 8, 'REKAPITULASI TRANSAKSI', 0, 1, 'C');

// Filter Info
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(90, 90, 90);
if (!empty($start_date) && !empty($end_date)) {
    $periode_text = formatTanggalIndo($start_date) . ' - ' . formatTanggalIndo($end_date);
} else {
    $periode_text = 'Semua Riwayat';
}
$pdf->Cell(0, 6, 'Periode: ' . $periode_text, 0, 1, 'C');
$pdf->Ln(4);

// === SUMMARY ===
// Layout: 3 Columns for Breakdown (Admin, Petugas, Total)
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetTextColor(0, 0, 0);

// Header Summary
$pdf->Cell(40, 6, 'KATEGORI', 1, 0, 'L', true);
$pdf->Cell(25, 6, 'TOTAL TRX', 1, 0, 'C', true);
$pdf->Cell(40, 6, 'DEBIT (SETOR)', 1, 0, 'R', true);
$pdf->Cell(40, 6, 'KREDIT (TARIK)', 1, 0, 'R', true);
$pdf->Cell(41, 6, 'SALDO', 1, 1, 'R', true); // Total width 186

$pdf->SetFont('helvetica', '', 8);

// Row 1: Admin
$pdf->Cell(40, 6, 'Transaksi Admin', 1, 0, 'L');
$pdf->Cell(25, 6, $stats['admin']['count'], 1, 0, 'C');
$pdf->Cell(40, 6, 'Rp ' . number_format($stats['admin']['setor'], 0, ',', '.'), 1, 0, 'R');
$pdf->Cell(40, 6, 'Rp ' . number_format($stats['admin']['tarik'], 0, ',', '.'), 1, 0, 'R');
$pdf->Cell(41, 6, 'Rp ' . number_format($saldo_admin, 0, ',', '.'), 1, 1, 'R');

// Row 2: Petugas
$pdf->Cell(40, 6, 'Transaksi Petugas', 1, 0, 'L');
$pdf->Cell(25, 6, $stats['petugas']['count'], 1, 0, 'C');
$pdf->Cell(40, 6, 'Rp ' . number_format($stats['petugas']['setor'], 0, ',', '.'), 1, 0, 'R');
$pdf->Cell(40, 6, 'Rp ' . number_format($stats['petugas']['tarik'], 0, ',', '.'), 1, 0, 'R');
$pdf->Cell(41, 6, 'Rp ' . number_format($saldo_petugas, 0, ',', '.'), 1, 1, 'R');

// Row 3: Total
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(40, 6, 'TOTAL GABUNGAN', 1, 0, 'L', true);
$pdf->Cell(25, 6, $stats['total']['count'], 1, 0, 'C', true);
$pdf->Cell(40, 6, 'Rp ' . number_format($stats['total']['setor'], 0, ',', '.'), 1, 0, 'R', true);
$pdf->Cell(40, 6, 'Rp ' . number_format($stats['total']['tarik'], 0, ',', '.'), 1, 0, 'R', true);
$pdf->Cell(41, 6, 'Rp ' . number_format($saldo_bersih, 0, ',', '.'), 1, 1, 'R', true);

$pdf->Ln(6);

// === TABLE HEADER ===
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220); // Light grey
$pdf->SetTextColor(0, 0, 0); // Black text

// Column widths (total ~186 for A4 Portrait with 12mm margins)
// No, Tanggal, Nama Siswa, Kelas, Oleh, Jenis, Jumlah
$w = [8, 22, 48, 28, 30, 18, 32];
$headers = ['No', 'Tanggal', 'Nama Siswa', 'Kelas', 'Oleh', 'Jenis', 'Jumlah'];

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
    if (strlen($nama) > 28) {
        $nama = substr($nama, 0, 26) . '..';
    }
    $pdf->Cell($w[2], 5, $nama, 1, 0, 'L', true);

    // Kelas
    $kelas_text = trim(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? ''));
    if (strlen($kelas_text) > 14) {
        $kelas_text = substr($kelas_text, 0, 12) . '..';
    }
    $pdf->Cell($w[3], 5, $kelas_text ?: '-', 1, 0, 'C', true);

    // Oleh
    $oleh = $row['nama_oleh'];
    if (strlen($oleh) > 16) {
        $oleh = substr($oleh, 0, 14) . '..';
    }
    $pdf->Cell($w[4], 5, $oleh, 1, 0, 'C', true);

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
$pdf->Cell($w[6], 5, 'Rp ' . number_format($saldo_bersih, 0, ',', '.'), 1, 1, 'R', true);

// === KETERANGAN ===
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(80, 80, 80);
$pdf->MultiCell(0, 5, 'Keterangan: Data ini merupakan rekapitulasi seluruh transaksi (admin dan petugas) di KASDIG.', 0, 'L');

// === OUTPUT PDF ===
$filename = 'Rekapitulasi_Transaksi_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'I'); // I = inline (browser), D = download