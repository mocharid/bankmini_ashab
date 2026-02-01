<?php
/**
 * e-Statement - Adaptive Path Version
 * File: pages/siswa/e_statement.php
 *
 * Compatible with:
 * - Local: schobank/pages/siswa/e_statement.php
 * - Hosting: public_html/pages/siswa/e_statement.php
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
// Strategy 1: jika di folder 'siswa' atau 'pages'
if (basename($current_dir) === 'siswa') {
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
    define('TCPDF_PATH', PROJECT_ROOT . '/TCPDF');
}
if (!defined('E_STATEMENT_PATH')) {
    define('E_STATEMENT_PATH', PROJECT_ROOT . '/e_statements');
}
// Create e_statements directory if not exists
if (!is_dir(E_STATEMENT_PATH)) {
    mkdir(E_STATEMENT_PATH, 0755, true);
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
require_once TCPDF_PATH . '/tcpdf.php';
// ============================================
// DETECT BASE URL FOR ASSETS
// ============================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));
// Deteksi base path (schobank atau public_html)
$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;
// ============================================
// SESSION VALIDATION
// ============================================
// Pastikan user adalah siswa
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'siswa') {
    header("Location: $base_url/unauthorized.php");
    exit();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: $base_url/login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

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
    $error_message = "Akun tidak ditemukan.";
}

$rekening_id = $siswa_info['rekening_id'] ?? null;
$no_rekening = $siswa_info['no_rekening'] ?? 'N/A';

// ============================================
// CLEANUP OLD E-STATEMENTS (7 days)
// ============================================
$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$stmt = $conn->prepare("
    SELECT id, file_name 
    FROM e_statements 
    WHERE created_at < ?
");
$stmt->bind_param("s", $seven_days_ago);
$stmt->execute();
$result = $stmt->get_result();
$old_statements = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($old_statements as $old) {
    // Delete file if exists
    if ($old['file_name'] && file_exists(E_STATEMENT_PATH . '/' . $old['file_name'])) {
        unlink(E_STATEMENT_PATH . '/' . $old['file_name']);
    }

    // Delete from database
    $stmt = $conn->prepare("DELETE FROM e_statements WHERE id = ?");
    $stmt->bind_param("i", $old['id']);
    $stmt->execute();
    $stmt->close();
}

// ============================================
// VARIABLES
// ============================================
$error_message = '';
$generated_statements = [];
$has_generated_statement = false;
$max_e_statements = 3; // Maksimal 3 e-statement
$current_count = 0;

// Function to format month name in Indonesian
function formatMonthYear($date)
{
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $timestamp = strtotime($date);
    $month = $months[date('n', $timestamp) - 1];
    $year = date('Y', $timestamp);
    return "$month $year";
}

// Function to format Rupiah
function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Get all e-statements for this user
if ($siswa_info) {
    $stmt = $conn->prepare("
        SELECT id, month_year, month_name, file_name, created_at 
        FROM e_statements 
        WHERE user_id = ? 
        ORDER BY month_year DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $generated_statements = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $current_count = count($generated_statements);
    $has_generated_statement = !empty($generated_statements);
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $statement_id = $_GET['delete'];

    // Verify ownership
    $stmt = $conn->prepare("SELECT file_name FROM e_statements WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $statement_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $statement = $result->fetch_assoc();
    $stmt->close();

    if ($statement) {
        // Delete file if exists
        if ($statement['file_name'] && file_exists(E_STATEMENT_PATH . '/' . $statement['file_name'])) {
            unlink(E_STATEMENT_PATH . '/' . $statement['file_name']);
        }

        // Delete from database
        $stmt = $conn->prepare("DELETE FROM e_statements WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $statement_id, $user_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "e-Statement berhasil dihapus.";
        } else {
            $_SESSION['error_message'] = "Gagal menghapus e-Statement.";
        }
        $stmt->close();
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Jika form di-submit untuk generate PDF
if (isset($_POST['generate_pdf']) && !empty($_POST['month']) && $rekening_id) {
    $selected_month = $_POST['month'];
    $selected_rekening = $_POST['rekening_id'] ?? $rekening_id;

    // Cek apakah sudah mencapai batas maksimal (3 e-statement)
    if ($current_count >= $max_e_statements) {
        $_SESSION['error_message'] = "Anda sudah mencapai batas maksimal $max_e_statements e-Statement. Harap hapus salah satu e-Statement yang ada untuk membuat yang baru.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Cek apakah sudah ada e-statement untuk bulan ini
    $stmt = $conn->prepare("SELECT id FROM e_statements WHERE user_id = ? AND month_year = ?");
    $stmt->bind_param("is", $user_id, $selected_month);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $_SESSION['error_message'] = "e-Statement untuk bulan " . formatMonthYear($selected_month . '-01') . " sudah ada.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $stmt->close();

    // Parse bulan (format: YYYY-MM)
    list($year, $month) = explode('-', $selected_month);
    $filter_start_date = "$year-$month-01";
    $filter_end_date = date("Y-m-t", strtotime($filter_start_date)); // Last day of month

    // ============================================
    // ✅ QUERY TRANSAKSI SAMA DENGAN DASHBOARD
    // ============================================
    $where_conditions = "WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)";
    $params = [$rekening_id, $rekening_id];
    $types = "ii";

    // Filter hanya untuk bulan yang dipilih
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

    // Query yang sama dengan dashboard + tambahan nama item pembayaran
    $query = "
        SELECT
            t.no_transaksi, t.jenis_transaksi, t.jumlah, t.created_at, t.status,
            t.rekening_tujuan_id, t.rekening_id, t.keterangan,
            r1.no_rekening as rekening_asal,
            r2.no_rekening as rekening_tujuan,
            u1.nama as nama_pengirim,
            u2.nama as nama_penerima,
            pi.nama_item as nama_tagihan
        FROM transaksi t
        LEFT JOIN rekening r1 ON t.rekening_id = r1.id
        LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
        LEFT JOIN users u1 ON r1.user_id = u1.id
        LEFT JOIN users u2 ON r2.user_id = u2.id
        LEFT JOIN pembayaran_tagihan pt ON t.no_transaksi = pt.no_transaksi
        LEFT JOIN pembayaran_item pi ON pt.item_id = pi.id
        $where_conditions
        ORDER BY t.created_at ASC
    ";

    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions_data = [];

    while ($row = $result->fetch_assoc()) {
        // ✅ Konversi status sama dengan dashboard
        $row['status'] = match ($row['status']) {
            'approved' => 'berhasil',
            'pending' => 'menunggu',
            'rejected' => 'gagal',
            default => $row['status']
        };
        $transactions_data[] = $row;
    }
    $stmt->close();

    // ============================================
    // ✅ HITUNG SALDO AWAL DENGAN BENAR
    // ============================================
    // Saldo saat ini
    $saldo_sekarang = $siswa_info['saldo'];

    // Hitung total perubahan saldo dari transaksi SETELAH periode yang dipilih (sampai sekarang)
    // untuk mendapatkan saldo akhir periode yang dipilih
    $stmt_after = $conn->prepare("
        SELECT 
            t.jenis_transaksi, t.jumlah, t.rekening_id, t.rekening_tujuan_id,
            r1.no_rekening as rekening_asal,
            r2.no_rekening as rekening_tujuan
        FROM transaksi t
        LEFT JOIN rekening r1 ON t.rekening_id = r1.id
        LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
        WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)
        AND DATE(t.created_at) > ?
        AND t.status = 'approved'
    ");
    $stmt_after->bind_param("iis", $rekening_id, $rekening_id, $filter_end_date);
    $stmt_after->execute();
    $result_after = $stmt_after->get_result();

    $adjustment_after = 0;
    while ($trx_after = $result_after->fetch_assoc()) {
        $is_incoming_after = ($trx_after['jenis_transaksi'] == 'setor' ||
            ($trx_after['jenis_transaksi'] == 'transfer' && $trx_after['rekening_tujuan'] == $no_rekening) ||
            ($trx_after['jenis_transaksi'] == 'transaksi_qr' && $trx_after['rekening_tujuan'] == $no_rekening) ||
            ($trx_after['jenis_transaksi'] == 'infaq' && $trx_after['rekening_tujuan'] == $no_rekening));

        if ($is_incoming_after) {
            $adjustment_after -= $trx_after['jumlah']; // kurangi karena ini terjadi setelah periode
        } else {
            $adjustment_after += $trx_after['jumlah']; // tambah karena ini terjadi setelah periode
        }
    }
    $stmt_after->close();

    // Saldo akhir periode = Saldo sekarang - adjustment transaksi setelah periode
    $saldo_akhir_periode = $saldo_sekarang + $adjustment_after;

    // Hitung mundur untuk mendapatkan saldo awal periode
    // dari semua transaksi BERHASIL (approved) dalam periode yang dipilih
    $total_debit = 0;
    $total_kredit = 0;

    foreach ($transactions_data as $transaction) {
        // PENTING: Hanya hitung transaksi yang berhasil (approved/berhasil) untuk perhitungan saldo
        if ($transaction['status'] !== 'berhasil') {
            continue;
        }

        $is_incoming = ($transaction['jenis_transaksi'] == 'setor' ||
            ($transaction['jenis_transaksi'] == 'transfer' && $transaction['rekening_tujuan'] == $no_rekening) ||
            ($transaction['jenis_transaksi'] == 'transaksi_qr' && $transaction['rekening_tujuan'] == $no_rekening) ||
            ($transaction['jenis_transaksi'] == 'infaq' && $transaction['rekening_tujuan'] == $no_rekening));

        if ($is_incoming) {
            $total_kredit += $transaction['jumlah'];
        } else {
            $total_debit += $transaction['jumlah'];
        }
    }

    // Saldo awal = Saldo akhir periode - kredit + debit
    // Ini artinya: saldo awal bulan adalah saldo akhir bulan sebelumnya
    $saldo_awal = $saldo_akhir_periode - $total_kredit + $total_debit;
    $running_balance = $saldo_awal;

    // Persiapkan transaksi untuk PDF
    // HANYA tampilkan transaksi yang BERHASIL untuk konsistensi perhitungan saldo
    $transactions_for_pdf = [];

    foreach ($transactions_data as $transaction) {
        // Skip transaksi yang belum berhasil (pending/gagal)
        if ($transaction['status'] !== 'berhasil') {
            continue;
        }

        $is_incoming = ($transaction['jenis_transaksi'] == 'setor' ||
            ($transaction['jenis_transaksi'] == 'transfer' && $transaction['rekening_tujuan'] == $no_rekening) ||
            ($transaction['jenis_transaksi'] == 'transaksi_qr' && $transaction['rekening_tujuan'] == $no_rekening) ||
            ($transaction['jenis_transaksi'] == 'infaq' && $transaction['rekening_tujuan'] == $no_rekening));

        $is_debit = !$is_incoming;

        // Tentukan deskripsi (sama seperti dashboard)
        $nama_penerima = $transaction['nama_penerima'] ?? '';
        $is_transfer_to_bendahar = (stripos($nama_penerima, 'bendahar') !== false);

        if ($is_transfer_to_bendahar && $transaction['jenis_transaksi'] == 'transfer' && !$is_incoming) {
            $description = "Pembayaran Infaq Bulanan";
        } elseif ($transaction['jenis_transaksi'] === 'infaq') {
            $description = $transaction['rekening_asal'] == $no_rekening ?
                'Infaq' : 'Penerimaan Infaq';
        } elseif ($transaction['jenis_transaksi'] === 'transaksi_qr') {
            $description = $transaction['rekening_asal'] == $no_rekening ?
                'Pembayaran QR' : 'Penerimaan QR';
        } elseif ($transaction['jenis_transaksi'] === 'transfer') {
            if ($is_incoming) {
                $description = 'Transfer Masuk';
            } else {
                $description = 'Transfer Keluar';
            }
        } elseif ($transaction['jenis_transaksi'] === 'setor') {
            $description = 'Setoran Tunai';
        } elseif ($transaction['jenis_transaksi'] === 'tarik') {
            $description = 'Penarikan Tunai';
        } elseif ($transaction['jenis_transaksi'] === 'bayar') {
            $nama_tagihan = $transaction['nama_tagihan'] ?? '';
            if (!empty($nama_tagihan)) {
                $description = 'Pembayaran ' . $nama_tagihan;
            } else {
                $description = 'Pembayaran Tagihan';
            }
        } else {
            $description = 'Pembayaran';
        }

        // Update running balance
        if ($is_debit) {
            $running_balance -= $transaction['jumlah'];
        } else {
            $running_balance += $transaction['jumlah'];
        }

        $transactions_for_pdf[] = [
            'tanggal' => $transaction['created_at'],
            'deskripsi' => $description,
            'no_transaksi' => $transaction['no_transaksi'],
            'jenis' => $transaction['jenis_transaksi'],
            'is_debit' => $is_debit,
            'jumlah' => $transaction['jumlah'],
            'saldo' => $running_balance
        ];
    }

    // Format month name for PDF and database
    $month_name = formatMonthYear($filter_start_date);

    try {
        // Generate PDF
        class MYPDF extends TCPDF
        {
            public function Header()
            {
                // Kosongkan header
            }

            public function Footer()
            {
                $this->SetY(-15);
                $this->SetFont('dejavusans', 'I', 7);
                $this->Cell(0, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0);
            }
        }

        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('KASDIG');
        $pdf->SetTitle('e-Statement');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(20);
        $pdf->SetAutoPageBreak(TRUE, 25);
        $pdf->SetCellPadding(0.8);
        $pdf->AddPage();

        // ============================================
        // HEADER - TITLE
        // ============================================
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor(0, 51, 102);
        $pdf->Cell(0, 8, 'e-Statement', 0, 1, 'C');

        $pdf->Ln(5);

        // ============================================
        // TWO BOXES SIDE BY SIDE
        // ============================================
        $start_y = $pdf->GetY();

        // Left Box - Nama & Alamat
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect(15, $start_y, 85, 28);

        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(17, $start_y + 3);
        $pdf->Cell(81, 4, strtoupper($siswa_info['nama']), 0, 1, 'L');
        $pdf->SetXY(17, $start_y + 8);
        $pdf->Cell(81, 4, 'SISWA SMK PLUS ASHABULYAMIN', 0, 1, 'L');
        $pdf->SetXY(17, $start_y + 13);
        $pdf->Cell(81, 4, 'CIANJUR', 0, 1, 'L');
        $pdf->SetXY(17, $start_y + 18);
        $pdf->Cell(81, 4, 'JAWA BARAT', 0, 1, 'L');
        $pdf->SetXY(17, $start_y + 23);
        $pdf->Cell(81, 4, 'INDONESIA', 0, 1, 'L');

        // Right Box - Info Rekening
        $pdf->Rect(105, $start_y, 90, 28);

        $pdf->SetXY(107, $start_y + 3);
        $pdf->Cell(25, 4, 'NO. REKENING', 0, 0, 'L');
        $pdf->Cell(3, 4, ':', 0, 0, 'L');
        $pdf->Cell(55, 4, $siswa_info['no_rekening'], 0, 1, 'L');

        $pdf->SetXY(107, $start_y + 9);
        $pdf->Cell(25, 4, 'HALAMAN', 0, 0, 'L');
        $pdf->Cell(3, 4, ':', 0, 0, 'L');
        $pdf->Cell(55, 4, '1/' . $pdf->getAliasNbPages(), 0, 1, 'L');

        $pdf->SetXY(107, $start_y + 15);
        $pdf->Cell(25, 4, 'PERIODE', 0, 0, 'L');
        $pdf->Cell(3, 4, ':', 0, 0, 'L');
        $pdf->Cell(55, 4, strtoupper($month_name), 0, 1, 'L');

        $pdf->SetXY(107, $start_y + 21);
        $pdf->Cell(25, 4, 'MATA UANG', 0, 0, 'L');
        $pdf->Cell(3, 4, ':', 0, 0, 'L');
        $pdf->Cell(55, 4, 'IDR', 0, 1, 'L');

        $pdf->SetY($start_y + 32);

        // ============================================
        // CATATAN BOX
        // ============================================
        $catatan_y = $pdf->GetY();
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Rect(15, $catatan_y, 180, 14);

        $pdf->SetFont('dejavusans', 'B', 6);
        $pdf->SetXY(17, $catatan_y + 2);
        $pdf->Cell(0, 3, 'CATATAN:', 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 5);
        $pdf->SetXY(17, $catatan_y + 5);
        $catatan_left = "Apabila nasabah tidak melakukan sanggahan atas Laporan Mutasi Rekening ini sampai dengan akhir bulan berikutnya, nasabah dianggap telah menyetujui segala data yang tercantum pada Laporan Mutasi Rekening ini.";
        $pdf->MultiCell(80, 2.5, $catatan_left, 0, 'L');

        $pdf->SetXY(100, $catatan_y + 5);
        $catatan_right = "KASDIG berhak setiap saat melakukan koreksi apabila ada kesalahan pada Laporan Mutasi Rekening.";
        $pdf->MultiCell(90, 2.5, $catatan_right, 0, 'L');

        $pdf->SetY($catatan_y + 16);

        // ============================================
        // TRANSACTION TABLE
        // ============================================
        // Header Tabel
        $pdf->SetFillColor(0, 51, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 7);

        // Kolom: Tanggal, Keterangan, No. Referensi, Debit, Kredit, Saldo
        $col_widths = [22, 42, 32, 28, 28, 28];
        $pdf->Cell($col_widths[0], 6, 'TANGGAL', 0, 0, 'C', true);
        $pdf->Cell($col_widths[1], 6, 'KETERANGAN', 0, 0, 'C', true);
        $pdf->Cell($col_widths[2], 6, 'NO. REFERENSI', 0, 0, 'C', true);
        $pdf->Cell($col_widths[3], 6, 'DEBIT', 0, 0, 'C', true);
        $pdf->Cell($col_widths[4], 6, 'KREDIT', 0, 0, 'C', true);
        $pdf->Cell($col_widths[5], 6, 'SALDO', 0, 1, 'C', true);

        // Data Tabel
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('dejavusans', '', 6);

        if (!empty($transactions_for_pdf)) {
            // Saldo Awal
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($col_widths[0], 5, '', 0, 0, 'C', true);
            $pdf->Cell($col_widths[1], 5, 'SALDO AWAL', 0, 0, 'L', true);
            $pdf->Cell($col_widths[2], 5, '', 0, 0, 'C', true);
            $pdf->Cell($col_widths[3], 5, '', 0, 0, 'R', true);
            $pdf->Cell($col_widths[4], 5, '', 0, 0, 'R', true);
            $pdf->Cell($col_widths[5], 5, number_format($saldo_awal, 2, ',', '.'), 0, 1, 'R', true);

            $row_count = 0;
            foreach ($transactions_for_pdf as $row) {
                // Alternating row colors
                if ($row_count % 2 == 0) {
                    $pdf->SetFillColor(255, 255, 255);
                } else {
                    $pdf->SetFillColor(248, 248, 248);
                }

                $tanggal = date('d/m/Y', strtotime($row['tanggal']));
                $debit_text = $row['is_debit'] ? number_format($row['jumlah'], 2, ',', '.') : '';
                $kredit_text = !$row['is_debit'] ? number_format($row['jumlah'], 2, ',', '.') : '';
                $saldo_text = number_format($row['saldo'], 2, ',', '.');
                $no_ref = $row['no_transaksi'] ?? '-';

                $pdf->Cell($col_widths[0], 5, $tanggal, 0, 0, 'C', true);
                $pdf->Cell($col_widths[1], 5, strtoupper($row['deskripsi']), 0, 0, 'L', true);
                $pdf->Cell($col_widths[2], 5, $no_ref, 0, 0, 'C', true);
                $pdf->Cell($col_widths[3], 5, $debit_text, 0, 0, 'R', true);
                $pdf->Cell($col_widths[4], 5, $kredit_text, 0, 0, 'R', true);
                $pdf->Cell($col_widths[5], 5, $saldo_text, 0, 1, 'R', true);

                $row_count++;
            }
        } else {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell(180, 8, 'Tidak ada transaksi pada periode ini', 0, 1, 'C', true);
        }

        // ============================================
        // FOOTER INFORMATION
        // ============================================
        $pdf->Ln(8);

        // Pernyataan Keabsahan Dokumen
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetTextColor(0, 51, 102);
        $pdf->Cell(0, 5, 'PERNYATAAN KEABSAHAN', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 4, 'Dokumen ini dicetak secara elektronik dan sah tanpa memerlukan tanda tangan basah. e-Statement ini merupakan dokumen resmi yang dikeluarkan oleh sistem KASDIG.', 0, 'L');

        $pdf->Ln(4);

        // Kontak Layanan Nasabah
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetTextColor(0, 51, 102);
        $pdf->Cell(0, 5, 'LAYANAN NASABAH', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 4, 'Untuk pertanyaan atau bantuan, silakan hubungi:' . "\n" .
            'Email: kasdig.ashab@gmail.com' . "\n" .
            'Alamat: SMK Plus Ashabulyamin, Cianjur, Jawa Barat', 0, 'L');

        $pdf->Ln(4);

        // Informasi Keamanan
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetTextColor(0, 51, 102);
        $pdf->Cell(0, 5, 'PERINGATAN KEAMANAN', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 245, 238);
        $pdf->MultiCell(0, 4, 'PENTING: Jangan pernah membagikan informasi rekening, PIN, atau password Anda kepada siapapun termasuk petugas bank. KASDIG tidak pernah meminta data rahasia melalui telepon, email, atau media sosial. Segera laporkan jika ada pihak yang mencurigakan meminta data Anda.', 0, 'L', true);

        // Generate unique filename
        $file_name = 'e_statement_' . $user_id . '_' . $selected_month . '_' . time() . '.pdf';
        $file_path = E_STATEMENT_PATH . '/' . $file_name;

        // Save PDF to file
        $pdf->Output($file_path, 'F');

        // Save to database
        $stmt = $conn->prepare("
            INSERT INTO e_statements (user_id, rekening_id, month_year, month_name, file_name) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisss", $user_id, $rekening_id, $selected_month, $month_name, $file_name);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "e-Statement untuk $month_name berhasil dibuat!";
        } else {
            $_SESSION['error_message'] = "Gagal menyimpan e-Statement ke database.";
        }
        $stmt->close();

        // Redirect back to page
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();

    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Gagal menghasilkan e-Statement: ' . $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle download request
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $statement_id = $_GET['download'];

    // Verify ownership and get file info
    $stmt = $conn->prepare("SELECT file_name, month_name FROM e_statements WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $statement_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $statement = $result->fetch_assoc();
    $stmt->close();

    if ($statement && $statement['file_name'] && file_exists(E_STATEMENT_PATH . '/' . $statement['file_name'])) {
        $file_path = E_STATEMENT_PATH . '/' . $statement['file_name'];
        $safe_filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $statement['month_name']);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="e-statement_' . $safe_filename . '.pdf"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit();
    } else {
        $_SESSION['error_message'] = "File e-Statement tidak ditemukan.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#2c3e50">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>e-Statement - KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        :root {
            /* Original Dark/Elegant Theme - Matching Transfer Page */
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;

            /* Neutral Colors */
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --white: #ffffff;

            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);

            /* Border Radius */
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
            --radius-full: 9999px;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 0;
            padding-bottom: 100px;
            min-height: 100vh;
        }

        /* ============================================ */
        /* HEADER / TOP NAVIGATION - DARK THEME */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
            padding: 20px 20px 120px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 5px 5px;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-info {
            color: var(--white);
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .page-subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
        }

        /* ============================================ */
        /* TABS IN HEADER - WHITE THEME */
        /* ============================================ */
        .header-tabs {
            display: flex;
            gap: 0;
            background: rgba(255, 255, 255, 0.15);
            border-radius: var(--radius-xl);
            padding: 4px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .header-tabs::before {
            content: '';
            position: absolute;
            top: 4px;
            bottom: 4px;
            width: calc(50% - 4px);
            background: var(--white);
            border-radius: calc(var(--radius-xl) - 2px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1;
        }

        .header-tabs.tab-right::before {
            left: calc(50% + 2px);
        }

        .header-tabs.tab-left::before {
            left: 4px;
        }

        .header-tab {
            flex: 1;
            padding: 12px 20px;
            border-radius: calc(var(--radius-xl) - 2px);
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.7);
            position: relative;
            z-index: 2;
        }

        .header-tab:hover:not(.active) {
            color: rgba(255, 255, 255, 0.9);
        }

        .header-tab.active {
            color: var(--primary);
        }

        .header-tab i {
            margin-right: 8px;
            font-size: 13px;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        /* ============================================ */
        /* EMPTY STATE */
        /* ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 350px);
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .empty-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray-700);
        }

        .empty-text {
            font-size: 14px;
        }

        /* ============================================ */
        /* STATEMENT LIST */
        /* ============================================ */
        .statement-list {
            display: grid;
            gap: 12px;
            margin-bottom: 24px;
        }

        .statement-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 18px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .statement-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }

        .statement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .statement-info {
            flex: 1;
        }

        .statement-month {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .statement-date {
            font-size: 11px;
            color: var(--gray-400);
            background: var(--gray-50);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            display: inline-block;
        }

        .statement-actions {
            display: flex;
            gap: 8px;
        }

        .btn-download,
        .btn-delete {
            flex: 1;
            border: none;
            padding: 10px 16px;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-download {
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
            color: var(--white);
        }

        .btn-download:hover {
            background: linear-gradient(135deg, #555555 0%, #1a1a1a 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .btn-delete {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .btn-delete:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
        }

        /* Expiry Warning */
        .expiry-warning {
            font-size: 11px;
            color: var(--warning);
            background: rgba(245, 158, 11, 0.1);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            margin-top: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ============================================ */
        /* INFO NOTE */
        /* ============================================ */
        .info-note {
            background: var(--gray-50);
            border-radius: var(--radius-xl);
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-200);
        }

        .info-note-content {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .info-note-icon {
            color: var(--gray-500);
            font-size: 14px;
            margin-top: 1px;
        }

        .info-note-text {
            flex: 1;
            font-size: 11px;
            color: var(--gray-600);
            line-height: 1.4;
        }

        .info-note-text strong {
            color: var(--gray-700);
            font-weight: 600;
        }

        /* ============================================ */
        /* CREATE BUTTON */
        /* ============================================ */
        .create-btn-container {
            margin-bottom: 24px;
        }

        .create-btn {
            width: 100%;
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
            border: none;
            border-radius: var(--radius-xl);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 15px;
            font-weight: 600;
            color: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .create-btn:hover {
            background: linear-gradient(135deg, #555555 0%, #1a1a1a 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }

        .create-btn:active {
            transform: translateY(0);
        }

        .create-btn i {
            font-size: 18px;
        }

        /* ============================================ */
        /* FILTER MODAL */
        /* ============================================ */
        .filter-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-end;
        }

        .filter-modal.active {
            display: flex;
        }

        .filter-content {
            background: var(--white);
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
            padding: 24px 20px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }

            to {
                transform: translateY(0);
            }
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .close-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: var(--gray-100);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .close-btn:hover {
            background: var(--gray-200);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 16px;
            transition: all 0.2s ease;
            background: var(--gray-50);
            color: var(--gray-900);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--gray-400);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }

        .filter-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            padding: 14px 20px;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #555555 0%, #1a1a1a 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .btn-secondary {
            background: transparent;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
            border-color: var(--gray-400);
            color: var(--gray-700);
        }

        /* ============================================ */
        /* ALERT MESSAGES */
        /* ============================================ */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-xl);
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* ============================================ */
        /* RESPONSIVE */
        /* ============================================ */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

            .main-container {
                padding: 0 16px;
            }

            .top-header {
                padding: 16px 16px 70px;
            }

            .page-title {
                font-size: 20px;
            }

            .page-subtitle {
                font-size: 12px;
            }

            .header-tab {
                padding: 10px 16px;
                font-size: 13px;
            }

            .statement-card {
                padding: 16px;
            }

            .btn-download,
            .btn-delete {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding-bottom: 115px;
            }

            .statement-month {
                font-size: 14px;
            }

            .statement-date {
                font-size: 10px;
            }

            .btn-download,
            .btn-delete {
                padding: 8px 10px;
                font-size: 12px;
                gap: 4px;
            }

            .create-btn {
                padding: 14px 20px;
                font-size: 14px;
            }

            .header-tab i {
                display: none;
            }
        }
    </style>
</head>

<body>
    <?php if ($siswa_info): ?>
        <!-- Top Header - Dark Theme -->
        <header class="top-header">
            <div class="header-content">
                <div class="header-top">
                    <div class="page-info">
                        <h1 class="page-title">e-Statement</h1>
                        <p class="page-subtitle">Buat laporan transaksi bulanan</p>
                    </div>
                </div>
                <!-- Tabs in Header -->
                <div class="header-tabs tab-right">
                    <button class="header-tab" onclick="switchTab('mutasi.php')"><i
                            class="fas fa-list-alt"></i>Mutasi</button>
                    <button class="header-tab active"><i class="fas fa-file-invoice"></i>e-Statement</button>
                </div>
            </div>
        </header>

        <!-- Main Container -->
        <div class="main-container">
            <!-- Messages handled by SweetAlert2 in JavaScript below -->

            <?php if ($error_message): ?>
                <div class="empty-state">
                    <div class="empty-icon">❌</div>
                    <h3 class="empty-title">Error</h3>
                    <p class="empty-text"><?php echo $error_message; ?></p>
                </div>
            <?php else: ?>
                <!-- Display generated statements or empty state -->
                <?php if ($has_generated_statement && !empty($generated_statements)): ?>
                    <div class="statement-list">
                        <?php foreach ($generated_statements as $statement):
                            // Calculate days remaining
                            $created_date = new DateTime($statement['created_at']);
                            $now = new DateTime();
                            $interval = $created_date->diff($now);
                            $days_old = $interval->days;
                            $days_remaining = 7 - $days_old;
                            ?>
                            <div class="statement-card">
                                <div class="statement-header">
                                    <div class="statement-info">
                                        <div class="statement-month">e-Statement
                                            <?php echo htmlspecialchars($statement['month_name']); ?>
                                        </div>
                                        <div class="statement-date">Dibuat:
                                            <?php echo date('d M Y', strtotime($statement['created_at'])); ?>
                                        </div>
                                        <?php if ($days_remaining <= 3): ?>
                                            <div class="expiry-warning">
                                                <i class="fas fa-clock"></i>
                                                <?php if ($days_remaining > 0): ?>
                                                    Akan dihapus dalam <?php echo $days_remaining; ?> hari
                                                <?php else: ?>
                                                    Akan dihapus segera
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="statement-actions">
                                    <a href="?download=<?php echo $statement['id']; ?>" class="btn-download"
                                        onclick="showDownloadLoading('<?php echo htmlspecialchars($statement['month_name']); ?>')">
                                        <i class="fas fa-download"></i>
                                        Unduh PDF
                                    </a>
                                    <button class="btn-delete"
                                        onclick="deleteStatement(<?php echo $statement['id']; ?>, '<?php echo htmlspecialchars($statement['month_name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Info Note about auto-deletion -->
                    <div class="info-note">
                        <div class="info-note-content">
                            <div class="info-note-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="info-note-text">
                                <strong>Catatan Penting:</strong> Semua e-Statement akan otomatis terhapus dari sistem setelah 7
                                hari. Pastikan Anda telah mengunduh e-Statement yang diperlukan sebelum waktu tersebut.
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <h3 class="empty-title">Belum ada e-Statement yang dibuat</h3>
                        <p class="empty-text">Buat e-Statement pertama Anda dengan menekan tombol di bawah</p>
                    </div>
                <?php endif; ?>

                <!-- Create Button -->
                <div class="create-btn-container">
                    <button class="create-btn" onclick="openFilterModal()" <?php echo ($current_count >= $max_e_statements) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                        <i class="fas fa-plus-circle"></i>
                        <span>
                            <?php if ($current_count >= $max_e_statements): ?>
                                Batas Maksimal Tercapai
                            <?php else: ?>
                                Buat e-Statement
                            <?php endif; ?>
                        </span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Modal -->
    <div class="filter-modal" id="filterModal" onclick="closeFilterModal(event)">
        <div class="filter-content" onclick="event.stopPropagation()">
            <div class="filter-header">
                <h3 class="filter-title">Buat e-Statement</h3>
                <button class="close-btn" onclick="closeFilterModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="generateForm">
                <div class="form-group">
                    <label class="form-label">Pilih Bulan</label>
                    <input type="month" name="month" class="form-control" id="monthInput"
                        value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" required>
                    <small style="font-size: 12px; color: var(--gray-500); margin-top: 4px; display: block;">
                        Pilih bulan untuk membuat e-Statement (akan otomatis dihapus setelah 7 hari)
                    </small>
                </div>
                <input type="hidden" name="rekening_id" value="<?php echo htmlspecialchars($rekening_id); ?>">
                <input type="hidden" name="generate_pdf" value="1">
                <div class="filter-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeFilterModal()">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="button" class="btn btn-primary" onclick="submitGenerateForm()">
                        <i class="fas fa-file-download"></i> Buat
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <?php
    $bottom_nav_path = __DIR__ . '/bottom_navbar.php';
    if (file_exists($bottom_nav_path)) {
        include $bottom_nav_path;
    }
    ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Smooth Tab Switch Function
        function switchTab(url) {
            const tabs = document.querySelector('.header-tabs');
            if (tabs) {
                tabs.classList.remove('tab-right');
                tabs.classList.add('tab-left');
            }

            // Wait for animation to complete before navigating
            setTimeout(() => {
                window.location.href = url;
            }, 300);
        }

        // Filter Modal Functions
        function openFilterModal() {
            // Check if limit reached
            const createBtn = document.querySelector('.create-btn');
            if (createBtn.disabled) {
                Swal.fire({
                    title: 'Batas Maksimal Tercapai',
                    text: 'Anda sudah mencapai batas maksimal 3 e-Statement. Harap hapus salah satu e-Statement yang ada terlebih dahulu untuk membuat yang baru.',
                    icon: 'warning',
                    confirmButtonText: 'OK',
                    confirmButtonColor: 'var(--primary)'
                });
                return;
            }

            document.getElementById('filterModal').classList.add('active');
            // Set max date to current month
            const today = new Date();
            const currentMonth = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
            document.getElementById('monthInput').max = currentMonth;
        }

        function closeFilterModal(event) {
            if (!event || event.target.id === 'filterModal') {
                document.getElementById('filterModal').classList.remove('active');
            }
        }

        // Submit Generate Form with 3 second loading
        function submitGenerateForm() {
            const form = document.getElementById('generateForm');
            const monthInput = form.querySelector('input[name="month"]');
            const selectedMonth = monthInput.value;

            if (!selectedMonth) {
                Swal.fire({
                    title: 'Peringatan',
                    text: 'Silakan pilih bulan terlebih dahulu',
                    icon: 'warning',
                    confirmButtonText: 'OK',
                    confirmButtonColor: 'var(--primary)'
                });
                return;
            }

            const monthName = formatMonth(selectedMonth);

            // Show loading for exactly 3 seconds
            Swal.fire({
                title: 'Memproses e-Statement',
                html: `Sedang membuat e-Statement untuk <b>${monthName}</b>...<br><br>
                      <div class="loading-spinner" style="width: 40px; height: 40px; margin: 0 auto; border: 3px solid #f3f3f3; border-top: 3px solid #2c3e50; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                      <style>@keyframes spin {0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); }}</style>`,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    // Auto submit after 3 seconds
                    setTimeout(() => {
                        form.submit();
                    }, 3000);
                }
            });
        }

        // Format month to Indonesian
        function formatMonth(monthString) {
            const months = {
                '01': 'Januari', '02': 'Februari', '03': 'Maret',
                '04': 'April', '05': 'Mei', '06': 'Juni',
                '07': 'Juli', '08': 'Agustus', '09': 'September',
                '10': 'Oktober', '11': 'November', '12': 'Desember'
            };

            const [year, month] = monthString.split('-');
            return `${months[month]} ${year}`;
        }

        // Show download loading for 3 seconds
        function showDownloadLoading(monthName) {
            Swal.fire({
                title: 'Mengunduh e-Statement',
                html: `Sedang mengunduh e-Statement untuk <b>${monthName}</b>...<br><br>
                      <div class="loading-spinner" style="width: 40px; height: 40px; margin: 0 auto; border: 3px solid #f3f3f3; border-top: 3px solid #2c3e50; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                      <style>@keyframes spin {0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); }}</style>`,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: () => {
                    // The actual download happens via the link
                }
            });

            // Return true to allow the link to proceed
            return true;
        }

        // Delete Statement with 3 second loading
        function deleteStatement(id, monthName) {
            Swal.fire({
                title: 'Hapus e-Statement?',
                html: `Apakah Anda yakin ingin menghapus e-Statement <b>${monthName}</b>?<br><br>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--primary)',
                cancelButtonColor: 'var(--gray-400)',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading for 3 seconds
                    Swal.fire({
                        title: 'Menghapus...',
                        html: `Sedang menghapus e-Statement...<br><br>
                              <div class="loading-spinner" style="width: 40px; height: 40px; margin: 0 auto; border: 3px solid #f3f3f3; border-top: 3px solid #2c3e50; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                              <style>@keyframes spin {0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); }}</style>`,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            // Redirect to delete URL after 3 seconds
                            setTimeout(() => {
                                window.location.href = `?delete=${id}`;
                            }, 3000);
                        }
                    });
                }
            });
        }

        // Show SweetAlert2 messages
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (isset($_SESSION['success_message'])): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: '<?php echo addslashes($_SESSION["success_message"]); ?>',
                    timer: 3000,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
                                                                                            <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: '<?php echo addslashes($_SESSION["error_message"]); ?>',
                        timer: 3000,
                        showConfirmButton: false,
                        toast: true,
                        position: 'top-end'
                    });
                                                                                            <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            // Set max date to today
            const today = new Date().toISOString().split('T')[0].substring(0, 7);
            const monthInputs = document.querySelectorAll('input[type="month"]');
            monthInputs.forEach(input => {
                input.max = today;
            });
        });
    </script>
</body>

</html>