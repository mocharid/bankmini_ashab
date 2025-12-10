<?php
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
$current_dir  = dirname($current_file);
$project_root = null;

// Strategy 1: jika di folder 'pages'
if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: naik max 5 level cari includes/
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

// Fallback: pakai current dir
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
if (!defined('TCPDF_PATH')) {
    define('TCPDF_PATH', PROJECT_ROOT . '/tcpdf');
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/auth.php')) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'File auth.php tidak ditemukan.',
        'debug' => [
            'includes_path' => INCLUDES_PATH,
            'project_root'  => PROJECT_ROOT
        ]
    ]);
    exit;
}

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Set locale ke Indonesia untuk nama bulan
setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'id');

ob_start();

// === HAK AKSES ADMIN ===
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Akses ditolak. Hanya admin.']);
    exit;
}

// === VALIDASI TANGGAL ===
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   $end_date   = date('Y-m-d');

if (strtotime($start_date) > strtotime($end_date)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir']);
    exit;
}

// === TOTAL RINGKASAN ===
$total_query = "SELECT
    COUNT(*) AS total_transactions,
    SUM(CASE WHEN t.jenis_transaksi = 'setor' AND (t.status = 'approved' OR t.status IS NULL) THEN t.jumlah ELSE 0 END) AS total_setoran,
    SUM(CASE WHEN t.jenis_transaksi = 'tarik' AND t.status = 'approved' THEN t.jumlah ELSE 0 END) AS total_penarikan
FROM transaksi t
JOIN rekening r ON t.rekening_id = r.id
WHERE DATE(t.created_at) BETWEEN ? AND ?
  AND t.jenis_transaksi IN ('setor', 'tarik')
  AND (t.petugas_id IS NULL OR EXISTS(SELECT 1 FROM users p WHERE p.id = t.petugas_id AND p.role = 'petugas'))";

$stmt = $conn->prepare($total_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc() ?: [];
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_setoran']      = $totals['total_setoran'] ?? 0;
$totals['total_penarikan']    = $totals['total_penarikan'] ?? 0;
$totals['total_net']          = $totals['total_setoran'] - $totals['total_penarikan'];
$stmt->close();

// === DETAIL TRANSAKSI ===
$query = "SELECT
            t.jenis_transaksi, t.jumlah, t.created_at,
            u.nama AS nama_siswa,
            COALESCE(p.nama, 'Admin') AS nama_oleh,
            COALESCE(tk.nama_tingkatan, '-') AS nama_tingkatan,
            COALESCE(k.nama_kelas, '-') AS nama_kelas
          FROM transaksi t
          JOIN rekening r ON t.rekening_id = r.id
          JOIN users u ON r.user_id = u.id
          LEFT JOIN users p ON t.petugas_id = p.id
          LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
          LEFT JOIN kelas k ON sp.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          WHERE DATE(t.created_at) BETWEEN ? AND ?
            AND t.jenis_transaksi IN ('setor', 'tarik')
            AND ((t.jenis_transaksi = 'setor' AND (t.status = 'approved' OR t.status IS NULL))
                 OR (t.jenis_transaksi = 'tarik' AND t.status = 'approved'))
            AND (t.petugas_id IS NULL OR p.role = 'petugas')
          ORDER BY t.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    $kelas = '';
    if (trim($row['nama_tingkatan']) && $row['nama_tingkatan'] !== '-') $kelas .= $row['nama_tingkatan'];
    if ($kelas && trim($row['nama_kelas']) && $row['nama_kelas'] !== '-') $kelas .= ' ' . $row['nama_kelas'];
    if (!$kelas && trim($row['nama_kelas']) && $row['nama_kelas'] !== '-') $kelas = $row['nama_kelas'];
    $row['kelas_display'] = $kelas ?: 'N/A';
    $transactions[] = $row;
}
$stmt->close();

// Fungsi format tanggal Indonesia
function tgl_indo($date) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $split = explode('-', $date);
    return (int)$split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
}

// === TCPDF LOADING WITH ADAPTIVE PATHS ===
$tcpdf_file = null;
$possible_tcpdf_paths = [
    TCPDF_PATH . '/tcpdf.php',
    PROJECT_ROOT . '/vendor/tecnickcom/tcpdf/tcpdf.php',
    PROJECT_ROOT . '/lib/tcpdf/tcpdf.php',
    '/usr/share/php/TCPDF/tcpdf.php',
    dirname(PROJECT_ROOT) . '/tcpdf/tcpdf.php',
];

// Cari TCPDF di beberapa path
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

// Jika tetap tidak tersedia
if (!$tcpdf_file) {
    header('Content-Type: application/json');
    echo json_encode([
        'error'      => 'Modul PDF (TCPDF) tidak tersedia. Hubungi administrator.',
        'debug_info' => [
            'searched_paths' => $possible_tcpdf_paths,
            'project_root'   => PROJECT_ROOT,
            'tcpdf_path'     => TCPDF_PATH
        ]
    ]);
    exit;
}

// Load TCPDF jika belum lewat Composer
if ($tcpdf_file !== 'composer' && !class_exists('TCPDF')) {
    require_once $tcpdf_file;
}

// Pastikan class TCPDF ada
if (!class_exists('TCPDF')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Class TCPDF tidak ditemukan setelah include.']);
    exit;
}

// === GENERATE PDF ===
class MYPDF extends TCPDF {
    public function Header() {
        $this->SetY(8);

        // Logo pakai adaptive path ASSETS_PATH
        $logo = ASSETS_PATH . '/images/logo.png';
        if (file_exists($logo)) {
            $this->Image($logo, 15, 9, 24, 24, 'PNG');
        }

        // Kop Surat Abu-abu Elegan
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(70, 70, 70);
        $this->Cell(0, 8, 'SMK PLUS ASHABULYAMIN', 0, 1, 'C');

        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(50, 50, 50);
        $this->Cell(0, 7, 'SCHOBANK - BANK MINI SEKOLAH', 0, 1, 'C');

        $this->SetFont('helvetica', '', 9.5);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Jl. K.H. Saleh No.57A, Cianjur 43212, Jawa Barat', 0, 1, 'C');
        $this->Cell(0, 5, 'Website: schobank.my.id | Email: myschobank@gmail.com', 0, 1, 'C');

        // Garis abu-abu elegan
        $this->SetDrawColor(120, 120, 120);
        $this->SetLineWidth(1.2);
        $this->Line(15, 40, 195, 40);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(15, 41.5, 195, 41.5);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(130, 130, 130);
        $this->Cell(0, 10, 'Dicetak pada ' . date('d/m/Y H:i') . ' WIB • Halaman ' . $this->getAliasNumPage() . ' dari ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Schobank SMK Plus Ashabulyamin');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Rekapitulasi Transaksi');
$pdf->SetMargins(12, 48, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// === JUDUL LAPORAN ===
$pdf->Ln(6);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 8, 'REKAPITULASI TRANSAKSI TABUNGAN SISWA', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell(0, 7, 'Periode: ' . tgl_indo($start_date) . ' — ' . tgl_indo($end_date), 0, 1, 'C');
$pdf->Ln(10);

// === RINGKASAN (Warna Abu-abu) ===
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(110, 110, 110);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(186, 8, 'RINGKASAN TRANSAKSI', 0, 1, 'C', true);

$w = 46.5;
$pdf->SetFillColor(245, 245, 245);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell($w, 7, 'Total Transaksi', 1, 0, 'C', true);
$pdf->Cell($w, 7, 'Total Setoran', 1, 0, 'C', true);
$pdf->Cell($w, 7, 'Total Penarikan', 1, 0, 'C', true);
$pdf->Cell($w, 7, 'Saldo Bersih', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($w, 7, number_format($totals['total_transactions']), 1, 0, 'C');
$pdf->Cell($w, 7, 'Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'C');
$pdf->Cell($w, 7, 'Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 0, 'C');
$pdf->Cell($w, 7, 'Rp ' . number_format($totals['total_net'], 0, ',', '.'), 1, 1, 'C');

$pdf->Ln(10);

// === DETAIL TRANSAKSI ===
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(110, 110, 110);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(186, 8, 'DETAIL TRANSAKSI', 0, 1, 'C', true);

// Header tabel
$col = [8, 20, 52, 22, 34, 18, 32];
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->SetFillColor(230, 230, 230);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell($col[0], 7, 'No', 1, 0, 'C', true);
$pdf->Cell($col[1], 7, 'Tanggal', 1, 0, 'C', true);
$pdf->Cell($col[2], 7, 'Nama Siswa', 1, 0, 'C', true);
$pdf->Cell($col[3], 7, 'Kelas', 1, 0, 'C', true);
$pdf->Cell($col[4], 7, 'Oleh', 1, 0, 'C', true);
$pdf->Cell($col[5], 7, 'Jenis', 1, 0, 'C', true);
$pdf->Cell($col[6], 7, 'Jumlah', 1, 1, 'C', true);

// Isi tabel
$pdf->SetFont('helvetica', '', 8.5);
if (!empty($transactions)) {
    $no = 1;
    foreach ($transactions as $t) {
        $jenis = $t['jenis_transaksi'] === 'setor' ? 'Setor' : 'Tarik';
        $pdf->Cell($col[0], 6.5, $no++, 1, 0, 'C');
        $pdf->Cell($col[1], 6.5, date('d/m/Y', strtotime($t['created_at'])), 1, 0, 'C');
        $pdf->Cell($col[2], 6.5, $t['nama_siswa'], 1, 0, 'L');
        $pdf->Cell($col[3], 6.5, $t['kelas_display'], 1, 0, 'C');
        $pdf->Cell($col[4], 6.5, $t['nama_oleh'], 1, 0, 'C');
        $pdf->Cell($col[5], 6.5, $jenis, 1, 0, 'C');
        $pdf->Cell($col[6], 6.5, 'Rp ' . number_format($t['jumlah'], 0, ',', '.'), 1, 1, 'C');
    }

    // Total Bersih
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(110, 110, 110);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(array_sum($col) - $col[6], 8, 'TOTAL BERSIH', 1, 0, 'C', true);
    $pdf->Cell($col[6], 8, 'Rp ' . number_format($totals['total_net'], 0, ',', '.'), 1, 1, 'C', true);
} else {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(array_sum($col), 10, 'Tidak ada transaksi pada periode ini.', 1, 1, 'C');
}

// === OUTPUT ===
ob_end_clean();
$filename = "Rekap_Transaksi_Schobank_" . date('d-m-Y', strtotime($start_date)) . "_sd_" . date('d-m-Y', strtotime($end_date)) . ".pdf";
$pdf->Output($filename, 'D');
exit;
?>
