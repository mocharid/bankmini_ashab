<?php
/**
 * Download Laporan Petugas - Portrait Version
 * File: pages/petugas/download_laporan.php
 * 
 * Style matching export_laporan_pdf.php (bendahara)
 */

// ============================================
// ADAPTIVE PATH DETECTION & INIT
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} else {
    $project_root = dirname(dirname($current_dir));
}

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Validate session
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Sesi tidak valid');
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
$date = $_GET['date'] ?? date('Y-m-d');

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die('Format tanggal tidak valid');
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

try {
    // Get petugas info from jadwal
    $officer1_name = 'Tidak Ada Jadwal';
    $officer2_name = 'Tidak Ada Jadwal';

    $jadwal_query = "SELECT p1.petugas1_nama AS petugas1_nama, p2.petugas2_nama AS petugas2_nama
                     FROM petugas_shift ps
                     LEFT JOIN petugas_profiles p1 ON ps.petugas1_id = p1.user_id
                     LEFT JOIN petugas_profiles p2 ON ps.petugas2_id = p2.user_id
                     WHERE ps.tanggal = ?
                     LIMIT 1";

    $stmt_jadwal = $conn->prepare($jadwal_query);
    $stmt_jadwal->bind_param("s", $date);
    $stmt_jadwal->execute();
    $jadwal_result = $stmt_jadwal->get_result();

    if ($jadwal_result && $jadwal_result->num_rows > 0) {
        $jadwal_data = $jadwal_result->fetch_assoc();
        $officer1_name = !empty($jadwal_data['petugas1_nama']) ? $jadwal_data['petugas1_nama'] : 'Tidak Ada Jadwal';
        $officer2_name = !empty($jadwal_data['petugas2_nama']) ? $jadwal_data['petugas2_nama'] : 'Tidak Ada Jadwal';
    }
    $stmt_jadwal->close();

    // Main Query - dengan jurusan
    $sql_data = "SELECT 
                    t.created_at,
                    t.jumlah,
                    t.jenis_transaksi,
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
                 WHERE DATE(t.created_at) = ?
                 AND t.jenis_transaksi IN ('setor', 'tarik')
                 AND t.petugas_id IS NOT NULL
                 AND (t.status = 'approved' OR t.status IS NULL)
                 ORDER BY t.created_at DESC";

    $stmt_data = $conn->prepare($sql_data);
    $stmt_data->bind_param("s", $date);
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
        die('Tidak ada data transaksi untuk tanggal ini.');
    }

    $total_bersih = $total_setor - $total_tarik;

    // ============================================
    // CUSTOM PDF CLASS
    // ============================================
    class MYPDF extends TCPDF
    {
        public function Header()
        {
            // Logo Header Image
            $headerImage = ASSETS_PATH . '/images/side.png';
            if (file_exists($headerImage)) {
                // Logo side.png - smaller size, positioned left
                $this->Image($headerImage, 15, 6, 25, 0, 'PNG', '', 'T', false, 300, 'L', false, false, 0);
            } else {
                $this->SetFont('helvetica', 'B', 14);
                $this->Cell(0, 8, 'KASDIG', 0, false, 'C', 0);
            }

            // Move cursor to right of logo for text
            $this->SetY(6);
            $this->SetX(45);

            $this->SetFont('helvetica', 'B', 12);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 5, 'Sistem Bank Mini Sekolah Digital', 0, 1, 'L');

            $this->SetX(45);
            $this->SetFont('helvetica', '', 9);
            $this->Cell(0, 4, 'SMK Plus Ashabulyamin Cianjur', 0, 1, 'L');

            $this->SetX(45);
            $this->SetFont('helvetica', '', 7);
            $this->Cell(0, 4, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, 1, 'L');

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
    $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Schobank SMK Plus Ashabulyamin');
    $pdf->SetAuthor('Petugas');
    $pdf->SetTitle('Laporan Transaksi Petugas');
    $pdf->SetMargins(12, 36, 12);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // === JUDUL LAPORAN ===
    $pdf->Ln(2); // Reduced top spacing
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 8, 'LAPORAN TRANSAKSI HARIAN', 0, 1, 'C');

    // Filter Info
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 6, 'Tanggal: ' . formatTanggalIndo($date), 0, 1, 'C');
    $pdf->Ln(6);

    // === INFORMASI PETUGAS ===
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220); // Light grey
    $pdf->SetTextColor(0, 0, 0); // Black text
    $pdf->Cell(186, 6, 'INFORMASI PETUGAS', 0, 1, 'C', true);

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(93, 6, 'Petugas 1', 1, 0, 'C', true);
    $pdf->Cell(93, 6, 'Petugas 2', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(93, 6, $officer1_name, 1, 0, 'C');
    $pdf->Cell(93, 6, $officer2_name, 1, 1, 'C');

    $pdf->Ln(6);

    // === TABLE HEADER ===
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220); // Light grey
    $pdf->SetTextColor(0, 0, 0); // Black text

    // Column widths (total ~186 for A4 Portrait with 12mm margins)
    // No, Nama Siswa, Kelas, Jurusan, Jenis, Jumlah
    $w = [10, 50, 28, 40, 18, 40];
    $headers = ['No', 'Nama Siswa', 'Kelas', 'Jurusan', 'Jenis', 'Jumlah'];

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

        // Nama Siswa
        $nama = $row['nama_siswa'];
        if (strlen($nama) > 28) {
            $nama = substr($nama, 0, 26) . '..';
        }
        $pdf->Cell($w[1], 5, $nama, 1, 0, 'L', true);

        // Kelas
        $kelas_text = trim(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? ''));
        if (strlen($kelas_text) > 14) {
            $kelas_text = substr($kelas_text, 0, 12) . '..';
        }
        $pdf->Cell($w[2], 5, $kelas_text ?: '-', 1, 0, 'C', true);

        // Jurusan
        $jurusan = $row['nama_jurusan'] ?? '-';
        // Auto-fit or just show full text (removed truncation)
        $pdf->Cell($w[3], 5, $jurusan, 1, 0, 'L', true);

        // Jenis
        $pdf->Cell($w[4], 5, ucfirst($row['jenis_transaksi']), 1, 0, 'C', true);

        // Jumlah
        $pdf->Cell($w[5], 5, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R', true);

        $no++;
    }

    // === TOTAL BERSIH ===
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220); // Light grey
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(array_sum($w) - $w[5], 6, 'TOTAL BERSIH', 1, 0, 'C', true);
    $pdf->Cell($w[5], 6, 'Rp ' . number_format($total_bersih, 0, ',', '.'), 1, 1, 'R', true);

    // === KETERANGAN ===
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(0, 5, 'Keterangan: Data ini merupakan transaksi yang dilakukan oleh petugas pada tanggal tersebut.', 0, 'L');

    // === OUTPUT PDF ===
    $filename = 'Laporan_Transaksi_Petugas_' . date('d-m-Y', strtotime($date)) . '.pdf';
    $pdf->Output($filename, 'I');
    exit;

} catch (Exception $e) {
    die('Gagal menghasilkan laporan: ' . $e->getMessage());
}

$conn->close();