<?php
/**
 * Download Laporan - Adaptive Path Version
 * File: pages/petugas/download_laporan.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/download_laporan.php
 * - Hosting: public_html/pages/petugas/download_laporan.php
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
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
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
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
if (!defined('TCPDF_PATH')) {
    define('TCPDF_PATH', PROJECT_ROOT . '/tcpdf');
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
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
// Validate session
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Sesi tidak valid']));
}
// Get report date & format
$date   = $_GET['date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'pdf';
// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    error_log("Invalid date format: $date");
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'Format tanggal tidak valid']));
}
// Function to format date in Indonesian
function formatIndonesianDate($date) {
    $days   = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $timestamp = strtotime($date);
    $day_name  = $days[date('w', $timestamp)];
    $day       = date('d', $timestamp);
    $month     = $months[date('n', $timestamp) - 1];
    $year      = date('Y', $timestamp);
    return "$day_name, $day $month $year";
}
// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
try {
    /* ============================================================= */
    /* 1. AMBIL NAMA PETUGAS BERDASARKAN JADWAL PADA TANGGAL TERSEBUT */
    /* ============================================================= */
    $officer1_name = 'Tidak Ada Jadwal';
    $officer2_name = 'Tidak Ada Jadwal';

    $jadwal_query = "SELECT p1.petugas1_nama AS petugas1_nama, p2.petugas2_nama AS petugas2_nama
                     FROM petugas_shift ps
                     LEFT JOIN petugas_profiles p1 ON ps.petugas1_id = p1.user_id
                     LEFT JOIN petugas_profiles p2 ON ps.petugas2_id = p2.user_id
                     WHERE ps.tanggal = ?
                     LIMIT 1";

    $stmt_jadwal = $conn->prepare($jadwal_query);
    if (!$stmt_jadwal) {
        throw new Exception("Gagal menyiapkan query jadwal: " . $conn->error);
    }
    $stmt_jadwal->bind_param("s", $date);
    $stmt_jadwal->execute();
    $jadwal_result = $stmt_jadwal->get_result();

    if ($jadwal_result && $jadwal_result->num_rows > 0) {
        $jadwal_data   = $jadwal_result->fetch_assoc();
        $officer1_name = !empty($jadwal_data['petugas1_nama']) ? $jadwal_data['petugas1_nama'] : 'Tidak Ada Jadwal';
        $officer2_name = !empty($jadwal_data['petugas2_nama']) ? $jadwal_data['petugas2_nama'] : 'Tidak Ada Jadwal';
    }
    $stmt_jadwal->close();
    /* ============================================================= */

    /* 2. AMBIL DATA TRANSAKSI */
    $query = "SELECT
                t.no_transaksi,
                t.jenis_transaksi,
                t.jumlah,
                t.created_at,
                u.nama AS nama_siswa,
                CONCAT_WS(' ', tk.nama_tingkatan, k.nama_kelas) AS nama_kelas
              FROM transaksi t
              JOIN rekening r ON t.rekening_id = r.id
              JOIN users u ON r.user_id = u.id
              JOIN siswa_profiles sp ON u.id = sp.user_id
              LEFT JOIN kelas k ON sp.kelas_id = k.id
              LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
              WHERE DATE(t.created_at) = ?
                AND t.jenis_transaksi != 'transfer'
                AND t.petugas_id IS NOT NULL
                AND (t.status = 'approved' OR t.status IS NULL)
              ORDER BY t.created_at DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Gagal menyiapkan kueri transaksi: " . $conn->error);
    }
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();

    /* 3. HITUNG TOTAL */
    $total_query = "SELECT
                      COUNT(id) as total_transactions,
                      SUM(CASE WHEN jenis_transaksi = 'setor'  AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
                      SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
                    FROM transaksi
                    WHERE DATE(created_at) = ?
                      AND jenis_transaksi != 'transfer'
                      AND petugas_id IS NOT NULL";

    $stmt_total = $conn->prepare($total_query);
    if (!$stmt_total) {
        throw new Exception("Gagal menyiapkan kueri total: " . $conn->error);
    }
    $stmt_total->bind_param("s", $date);
    $stmt_total->execute();
    $totals = $stmt_total->get_result()->fetch_assoc();
    $stmt_total->close();

    $saldo_bersih = ($totals['total_debit'] ?? 0) - ($totals['total_kredit'] ?? 0);
    $saldo_bersih = max(0, $saldo_bersih);

    /* 4. SIAPKAN DATA TRANSAKSI UNTUK PDF */
    $transactions = [];
    $row_number   = 1;
    $has_transactions = false;

    while ($row = $result->fetch_assoc()) {
        $has_transactions = true;
        $row['no']            = $row_number++;
        $row['display_jenis'] = $row['jenis_transaksi'] == 'setor' ? 'Setor' : 'Tarik';
        $row['display_nama']  = $row['nama_siswa'] ?? 'N/A';
        $row['display_kelas'] = trim($row['nama_kelas'] ?? '') ?: 'N/A';
        $transactions[]       = $row;
    }
    $stmt->close();

    /* 5. GENERATE PDF */
    if ($format !== 'pdf') {
        throw new Exception("Format tidak valid. Hanya 'pdf' yang didukung.");
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

    // ---- CUSTOM PDF CLASS ----
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
    $pdf->SetTitle('Rekapan Transaksi Petugas');
    $pdf->SetMargins(12, 48, 12);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // === JUDUL LAPORAN ===
    $pdf->Ln(6);
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 8, 'LAPORAN TRANSAKSI PETUGAS', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 7, 'Tanggal: ' . formatIndonesianDate($date), 0, 1, 'C');
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
    $pdf->Cell($w, 7, formatRupiah($totals['total_debit']), 1, 0, 'C');
    $pdf->Cell($w, 7, formatRupiah($totals['total_kredit']), 1, 0, 'C');
    $pdf->Cell($w, 7, formatRupiah($saldo_bersih), 1, 1, 'C');

    $pdf->Ln(10);

    // === INFORMASI PETUGAS ===
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(110, 110, 110);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(186, 8, 'INFORMASI PETUGAS', 0, 1, 'C', true);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(93, 7, 'Petugas 1', 1, 0, 'C', true);
    $pdf->Cell(93, 7, 'Petugas 2', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(93, 7, $officer1_name, 1, 0, 'C');
    $pdf->Cell(93, 7, $officer2_name, 1, 1, 'C');

    $pdf->Ln(10);

    // === DETAIL TRANSAKSI ===
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(110, 110, 110);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(186, 8, 'DETAIL TRANSAKSI', 0, 1, 'C', true);

    // Header tabel - Kolom disesuaikan agar seimbang (total 186mm, tanpa kolom Tanggal)
    $col = [8, 30, 60, 24, 30, 34]; // No, No Transaksi, Nama Siswa, Kelas, Jenis, Jumlah
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($col[0], 7, 'No', 1, 0, 'C', true);
    $pdf->Cell($col[1], 7, 'No Transaksi', 1, 0, 'C', true);
    $pdf->Cell($col[2], 7, 'Nama Siswa', 1, 0, 'C', true);
    $pdf->Cell($col[3], 7, 'Kelas', 1, 0, 'C', true);
    $pdf->Cell($col[4], 7, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col[5], 7, 'Jumlah', 1, 1, 'C', true);

    // Isi tabel
    $pdf->SetFont('helvetica', '', 8);
    $total_setoran = 0;
    $total_penarikan = 0;

    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $displayType = '';
            if ($row['jenis_transaksi'] == 'setor') {
                $displayType = 'Setor';
                $total_setoran += $row['jumlah'];
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $displayType = 'Tarik';
                $total_penarikan += $row['jumlah'];
            }
            
            $nama_siswa = $row['display_nama'] ?? 'N/A';
            
            // Truncate long names if needed
            if (mb_strlen($nama_siswa) > 30) {
                $nama_siswa = mb_substr($nama_siswa, 0, 28) . '...';
            }

            $pdf->Cell($col[0], 6.5, $no++, 1, 0, 'C');
            $pdf->Cell($col[1], 6.5, $row['no_transaksi'] ?? 'N/A', 1, 0, 'C');
            $pdf->Cell($col[2], 6.5, $nama_siswa, 1, 0, 'L');
            $pdf->Cell($col[3], 6.5, $row['display_kelas'], 1, 0, 'C');
            $pdf->Cell($col[4], 6.5, $displayType, 1, 0, 'C');
            $pdf->Cell($col[5], 6.5, formatRupiah($row['jumlah']), 1, 1, 'C');
        }

        // Total Bersih
        $total = $total_setoran - $total_penarikan;
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(110, 110, 110);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(array_sum($col) - $col[5], 8, 'TOTAL BERSIH', 1, 0, 'C', true);
        $pdf->Cell($col[5], 8, formatRupiah($total), 1, 1, 'C', true);
    } else {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(array_sum($col), 10, 'Tidak ada transaksi petugas pada periode ini.', 1, 1, 'C');
    }

    // === OUTPUT ===
    $filename = "Laporan_Transaksi_" . date('d-m-Y', strtotime($date)) . ".pdf";
    $pdf->Output($filename, 'D');
    exit;

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gagal menghasilkan laporan: ' . $e->getMessage()]);
}

$conn->close();
?>