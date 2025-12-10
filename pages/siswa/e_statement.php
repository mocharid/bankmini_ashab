<?php
/**
 * e-Statement - Adaptive Path Version
 * File: pages/user/e_statement.php or similar
 *
 * Compatible with:
 * - Local: schobank/pages/user/e_statement.php
 * - Hosting: public_html/pages/user/e_statement.php
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
// Strategy 1: jika di folder 'pages' atau 'user'
if (basename($current_dir) === 'user') {
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
// Ambil info siswa dan rekening
// ============================================
$siswa_info = null;
$error_message = '';
$generated_statements = [];
$has_generated_statement = false;
$max_e_statements = 3; // Maksimal 3 e-statement
$current_count = 0;

// Ambil info siswa dan rekening
$stmt = $conn->prepare("
    SELECT u.id, u.nama, sp.nis_nisn, r.no_rekening, r.saldo, r.id as rekening_id
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

// Function to format month name in Indonesian
function formatMonthYear($date) {
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $timestamp = strtotime($date);
    $month = $months[date('n', $timestamp) - 1];
    $year = date('Y', $timestamp);
    return "$month $year";
}

// Function to format Rupiah
function formatRupiah($amount) {
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
if (isset($_POST['generate_pdf']) && !empty($_POST['month'])) {
    $selected_month = $_POST['month'];
    $selected_rekening = $_POST['rekening_id'] ?? $siswa_info['rekening_id'];
    $document_type = $_POST['document_type'] ?? 'PDF';
    
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
   
    $where_conditions = "WHERE m.rekening_id = ?";
    $params = [$selected_rekening];
    $types = "i";
   
    if (!empty($filter_start_date)) {
        $where_conditions .= " AND m.created_at >= ?";
        $params[] = $filter_start_date . ' 00:00:00';
        $types .= "s";
    }
    if (!empty($filter_end_date)) {
        $where_conditions .= " AND m.created_at <= ?";
        $params[] = $filter_end_date . ' 23:59:59';
        $types .= "s";
    }
   
    // Ambil mutasi dengan filter (tanpa exclude transaksi_qr)
    $query = "
        SELECT m.id, m.jumlah, m.saldo_akhir, m.created_at, m.rekening_id,
               t.jenis_transaksi, t.no_transaksi, t.status, t.keterangan, t.rekening_id as transaksi_rekening_id, t.rekening_tujuan_id,
               u_petugas.nama as petugas_nama
        FROM mutasi m
        LEFT JOIN transaksi t ON m.transaksi_id = t.id
        LEFT JOIN users u_petugas ON t.petugas_id = u_petugas.id
        $where_conditions
        ORDER BY m.created_at ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $mutasi_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
   
    try {
        // Hitung total
        $total_query = "SELECT
                          COUNT(m.id) as total_transactions,
                          SUM(CASE WHEN (t.jenis_transaksi = 'setor' OR (t.jenis_transaksi = 'transfer' AND m.rekening_id = t.rekening_tujuan_id) OR t.jenis_transaksi IS NULL OR t.jenis_transaksi = 'transaksi_qr') THEN ABS(m.jumlah) ELSE 0 END) as total_debit,
                          SUM(CASE WHEN (t.jenis_transaksi = 'tarik' OR (t.jenis_transaksi = 'transfer' AND m.rekening_id = t.rekening_id)) THEN ABS(m.jumlah) ELSE 0 END) as total_kredit
                        FROM mutasi m
                        LEFT JOIN transaksi t ON m.transaksi_id = t.id
                        WHERE m.rekening_id = ?
                          AND m.created_at >= ?
                          AND m.created_at <= ?";
       
        $stmt_total = $conn->prepare($total_query);
        if (!$stmt_total) {
            throw new Exception("Gagal menyiapkan kueri total: " . $conn->error);
        }
        $start_datetime = $filter_start_date . ' 00:00:00';
        $end_datetime = $filter_end_date . ' 23:59:59';
        $stmt_total->bind_param("iss", $selected_rekening, $start_datetime, $end_datetime);
        $stmt_total->execute();
        $totals = $stmt_total->get_result()->fetch_assoc();
        $stmt_total->close();
       
        // Siapkan data transaksi untuk PDF
        $transactions = [];
        $row_number = 1;
        $has_transactions = false;
        $running_balance = $siswa_info['saldo']; // Mulai dari saldo awal
       
        foreach ($mutasi_data as $mutasi) {
            $has_transactions = true;
            $jenis_text = '';
            $is_debit = false;
           
            if ($mutasi['jenis_transaksi'] === 'transfer') {
                $is_debit = ($mutasi['rekening_id'] == $mutasi['transaksi_rekening_id']);
                $jenis_text = $is_debit ? 'Transfer Keluar' : 'Transfer Masuk';
            } elseif ($mutasi['jenis_transaksi'] === null) {
                $jenis_text = 'Transfer Masuk';
                $is_debit = false;
            } elseif ($mutasi['jenis_transaksi'] === 'setor') {
                $jenis_text = 'Setor';
                $is_debit = false;
            } elseif ($mutasi['jenis_transaksi'] === 'transaksi_qr') {
                $jenis_text = 'Transaksi QR';
                $is_debit = true; // Asumsi debit untuk QR, sesuaikan jika perlu
            } else {
                $jenis_text = 'Tarik';
                $is_debit = true;
            }
           
            $jumlah_abs = abs($mutasi['jumlah'] ?? 0);
            $display_jumlah = formatRupiah($jumlah_abs);
            $display_jenis = $jenis_text;
            $display_tanggal = date('d M Y H:i', strtotime($mutasi['created_at'] ?? 'now'));
           
            // Hitung saldo akhir dari saldo_akhir di database
            $saldo_akhir = $mutasi['saldo_akhir'] ?? $running_balance;
           
            $transactions[] = [
                'no' => $row_number++,
                'display_tanggal' => $display_tanggal,
                'display_jenis' => $display_jenis,
                'display_debit' => $is_debit ? '' : $display_jumlah,
                'display_kredit' => $is_debit ? $display_jumlah : '',
                'saldo_akhir' => $saldo_akhir
            ];
           
            $running_balance = $saldo_akhir;
        }
       
        // Generate PDF
        if (!file_exists(TCPDF_PATH . '/tcpdf.php')) {
            throw new Exception("Pustaka TCPDF tidak ditemukan.");
        }
       
        class MYPDF extends TCPDF {
            public function Header() {
                // Kosongkan header
            }
           
            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('times', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0);
            }
        }
       
        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('MY SCHOBANK');
        $pdf->SetTitle('e-Statement');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(20);
        $pdf->SetAutoPageBreak(TRUE, 25);
        $pdf->SetCellPadding(0.8);
        $pdf->AddPage();
       
        // Title
        $pdf->Ln(2);
        $pdf->SetFont('times', 'B', 13);
        $pdf->Cell(0, 5, 'e-STATEMENT REKENING', 0, 1, 'C');
        $pdf->SetFont('times', '', 10);
        // Format periode: Nama Bulan Tahun
        $month_name = formatMonthYear($filter_start_date);
        $pdf->Cell(0, 4, 'Periode: ' . $month_name, 0, 1, 'C');
       
        // Siswa Information
        $pdf->Ln(6);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(190, 4, 'INFORMASI NASABAH', 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('times', '', 9);
        $pdf->Cell(40, 4, 'Nama', 0, 0, 'L');
        $pdf->Cell(5, 4, ':', 0, 0, 'C');
        $pdf->Cell(145, 4, $siswa_info['nama'], 0, 1, 'L');
        $pdf->Cell(40, 4, 'NIS/NISN', 0, 0, 'L');
        $pdf->Cell(5, 4, ':', 0, 0, 'C');
        $pdf->Cell(145, 4, $siswa_info['nis_nisn'], 0, 1, 'L');
        $pdf->Cell(40, 4, 'No Rekening', 0, 0, 'L');
        $pdf->Cell(5, 4, ':', 0, 0, 'C');
        $pdf->Cell(145, 4, $siswa_info['no_rekening'], 0, 1, 'L');
        $pdf->Cell(40, 4, 'Saldo Awal', 0, 0, 'L');
        $pdf->Cell(5, 4, ':', 0, 0, 'C');
        $pdf->Cell(145, 4, formatRupiah($siswa_info['saldo'] ?? 0), 0, 1, 'L');
       
        // Transaction Summary - SATU BARIS HORIZONTAL
        $pdf->Ln(6);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(190, 4, 'RINGKASAN TRANSAKSI', 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('times', '', 9);
       
        if ($has_transactions) {
            // Satu baris: Total Transaksi | Debit | Kredit | Saldo Akhir
            $saldo_akhir_periode = !empty($transactions) ? end($transactions)['saldo_akhir'] : $siswa_info['saldo'];
           
            $pdf->Cell(47.5, 4, 'Total Transaksi: ' . ($totals['total_transactions'] ?? 0), 0, 0, 'L');
            $pdf->Cell(47.5, 4, 'Debit: ' . formatRupiah($totals['total_debit'] ?? 0), 0, 0, 'L');
            $pdf->Cell(47.5, 4, 'Kredit: ' . formatRupiah($totals['total_kredit'] ?? 0), 0, 0, 'L');
            $pdf->SetFont('times', 'B', 9);
            $pdf->Cell(47.5, 4, 'Saldo Akhir: ' . formatRupiah($saldo_akhir_periode), 0, 1, 'L');
            $pdf->SetFont('times', '', 9);
        } else {
            $pdf->Cell(190, 4, 'Tidak ada aktivitas transaksi pada periode ini', 0, 1, 'L');
        }
       
        // Transaction Details - TANPA GARIS
        $pdf->Ln(6);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(190, 4, 'DETAIL TRANSAKSI', 0, 1, 'L');
        $pdf->Ln(1);
       
        if ($has_transactions) {
            // Header tabel: Tanggal | Jenis | Debit | Kredit | Saldo Akhir
            $pdf->SetFont('times', 'B', 9);
            $pdf->Cell(35, 4, 'Tanggal', 0, 0, 'L');
            $pdf->Cell(45, 4, 'Jenis', 0, 0, 'L');
            $pdf->Cell(37, 4, 'Debit', 0, 0, 'R');
            $pdf->Cell(37, 4, 'Kredit', 0, 0, 'R');
            $pdf->Cell(36, 4, 'Saldo Akhir', 0, 1, 'R');
           
            // Header tabel: Tanggal | Jenis | Debit | Kredit | Saldo Akhir
            $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
            $pdf->Ln(1);
           
            $pdf->SetFont('times', '', 8);
            $row_count = 0;
            $total_debit_column = 0;
            $total_kredit_column = 0;
           
            foreach ($transactions as $row) {
                $bg_color = $row_count % 2 == 0 ? [250, 250, 250] : [255, 255, 255];
                $pdf->SetFillColor($bg_color[0], $bg_color[1], $bg_color[2]);
               
                $pdf->Cell(35, 4, $row['display_tanggal'], 0, 0, 'L', true);
                $pdf->Cell(45, 4, $row['display_jenis'], 0, 0, 'L', true);
                $pdf->Cell(37, 4, $row['display_debit'], 0, 0, 'R', true);
                $pdf->Cell(37, 4, $row['display_kredit'], 0, 0, 'R', true);
                $pdf->Cell(36, 4, formatRupiah($row['saldo_akhir']), 0, 1, 'R', true);
               
                // Hitung total untuk kolom
                if (!empty($row['display_debit'])) {
                    $total_debit_column += abs(str_replace(['Rp ', '.', ','], '', $row['display_debit']));
                }
                if (!empty($row['display_kredit'])) {
                    $total_kredit_column += abs(str_replace(['Rp ', '.', ','], '', $row['display_kredit']));
                }
               
                $row_count++;
            }
           
            // Garis pemisah sebelum total
            $pdf->Ln(1);
            $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
            $pdf->Ln(1);
           
            // Baris Total
            $pdf->SetFont('times', 'B', 9);
            $pdf->Cell(80, 4, 'Total', 0, 0, 'R');
            $pdf->Cell(37, 4, formatRupiah($total_debit_column), 0, 0, 'R');
            $pdf->Cell(37, 4, formatRupiah($total_kredit_column), 0, 0, 'R');
            $pdf->Cell(36, 4, formatRupiah($saldo_akhir_periode), 0, 1, 'R');
        } else {
            $pdf->SetFont('times', '', 9);
            $pdf->Cell(190, 4, 'Tidak ada transaksi pada periode ini', 0, 1, 'C');
        }
       
        // Notes
        $pdf->Ln(6);
        $pdf->SetFont('times', 'I', 8);
        $pdf->MultiCell(190, 8,
            "Catatan:\n- Laporan ini dihasilkan secara otomatis oleh sistem.\n- Harap verifikasi transaksi dengan catatan pribadi Anda.\n- Untuk pertanyaan, hubungi layanan nasabah MY SCHOBANK.",
            0, 'L', false);
        
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
        $stmt->bind_param("iisss", $user_id, $selected_rekening, $selected_month, $month_name, $file_name);
        
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
    <title>e-Statement - SCHOBANK</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html {
            scroll-padding-top: 70px;
            scroll-behavior: smooth;
        }
        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
            --secondary: #00C2FF;
            --success: #00D084;
            --warning: #FFB020;
            --danger: #FF3B30;
            --dark: #1C1C1E;
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --orange-accent: #FF8C42;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --shadow-float: 0 8px 20px rgba(0, 0, 0, 0.2);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 70px;
            padding-bottom: 100px;
        }
        /* Top Navigation */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-bottom: none;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: 70px;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            height: 100%;
        }
        .nav-logo {
            height: 45px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            object-position: left center;
            display: block;
        }
        /* Sticky Header */
        .sticky-header {
            position: -webkit-sticky;
            position: sticky;
            top: 70px;
            left: 0;
            right: 0;
            background: var(--gray-50);
            z-index: 99;
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }
        .sticky-header.stuck {
            box-shadow: var(--shadow-md);
        }
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 20px;
            background: var(--gray-50);
        }
        /* Page Header */
        .page-header {
            margin-bottom: 20px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }
        /* Tabs */
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .tab {
            flex: 1;
            padding: 12px 20px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: var(--white);
            color: var(--gray-600);
            box-shadow: var(--shadow-sm);
        }
        .tab.active {
            background: var(--elegant-dark);
            color: var(--white);
        }
        /* Create Button - Inside Container but at the bottom */
        .create-btn-container {
            margin-top: 40px;
            padding: 0 20px;
        }
        .create-btn {
            width: 100%;
            background: var(--elegant-dark);
            border: none;
            border-radius: var(--radius-lg);
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 16px;
            font-weight: 600;
            color: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        .create-btn:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-float);
        }
        .create-btn:active {
            transform: translateY(0);
        }
        .create-btn i {
            font-size: 18px;
        }
        .create-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        .create-btn:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
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
        /* Statement List */
        .statement-list {
            display: grid;
            gap: 16px;
            margin-bottom: 20px;
        }
        .statement-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            position: relative;
        }
        .statement-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        .statement-date {
            font-size: 12px;
            color: var(--gray-500);
            background: var(--gray-100);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            display: inline-block;
        }
        .statement-actions {
            display: flex;
            gap: 10px;
        }
        .btn-download {
            flex: 1;
            background: var(--elegant-dark);
            color: var(--white);
            border: none;
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-download:hover {
            background: var(--elegant-gray);
            transform: translateY(-1px);
        }
        .btn-delete {
            flex: 1;
            background: var(--gray-200);
            color: var(--gray-700);
            border: none;
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-delete:hover {
            background: var(--gray-300);
            transform: translateY(-1px);
        }
        /* Expiry Warning */
        .expiry-warning {
            font-size: 11px;
            color: var(--warning);
            background: rgba(255, 176, 32, 0.1);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        /* Info Note */
        .info-note {
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin: 0 20px 30px;
            border: 1px solid var(--gray-200);
        }
        .info-note-content {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .info-note-icon {
            color: var(--gray-600);
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
        /* Limit Warning */
        .limit-warning {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin: 0 20px 20px;
            border: 1px solid var(--warning);
            background: rgba(255, 176, 32, 0.05);
        }
        .limit-warning-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .limit-warning-icon {
            color: var(--warning);
            font-size: 18px;
            margin-top: 1px;
        }
        .limit-warning-text {
            flex: 1;
            font-size: 13px;
            color: var(--gray-800);
            line-height: 1.5;
        }
        .limit-warning-text strong {
            color: var(--warning);
            font-weight: 700;
        }
        /* Counter Info */
        .counter-info {
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 10px 14px;
            margin: 0 20px 20px;
            border: 1px solid var(--gray-300);
            text-align: center;
            font-size: 13px;
            color: var(--gray-700);
        }
        .counter-info strong {
            color: var(--elegant-dark);
            font-weight: 700;
        }
        /* Filter Modal */
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
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
        }
        .form-control, .form-select {
            padding: 12px 14px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 15px;
            transition: all 0.2s ease;
            background: var(--white);
            color: var(--gray-900);
            width: 100%;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            height: 48px;
        }
        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
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
            height: 48px;
        }
        .btn-primary {
            background: var(--elegant-dark);
            color: var(--white);
        }
        .btn-primary:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        .btn-secondary:hover {
            background: var(--gray-300);
        }
        /* Bottom Navigation - Fully Rounded */
        .bottom-nav {
            position: fixed;
            bottom: 12px;
            left: 12px;
            right: 12px;
            background: var(--white);
            padding: 16px 20px 24px;
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            z-index: 100;
            box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.15);
            border-radius: 32px;
            transition: all 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }
        .bottom-nav.hidden {
            transform: translateY(120%);
            opacity: 0;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            color: var(--gray-400);
            transition: all 0.2s ease;
            padding: 8px 12px;
            flex: 1;
            position: relative;
        }
        .nav-item.active {
            color: var(--elegant-dark);
        }
        .nav-item i {
            font-size: 22px;
            transition: all 0.2s ease;
        }
        .nav-item span {
            font-size: 10px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .nav-item:hover i,
        .nav-item:hover span {
            color: var(--elegant-dark);
            transform: scale(1.1);
        }
        .nav-item.active i,
        .nav-item.active span {
            color: var(--elegant-dark);
        }
        /* Center Transfer Button - Larger & Higher */
        .nav-center {
            position: relative;
            flex: 0 0 auto;
            margin: 0 8px;
        }
        .nav-center-btn {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: var(--elegant-dark);
            border: 4px solid var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 12px 32px rgba(44, 62, 80, 0.4);
            position: relative;
            bottom: 24px;
            text-decoration: none;
            color: var(--white);
        }
        .nav-center-btn:hover {
            transform: translateY(-6px) scale(1.08);
            box-shadow: 0 16px 40px rgba(44, 62, 80, 0.5);
        }
        .nav-center-btn:active {
            transform: translateY(-3px) scale(1.02);
        }
        .nav-center-btn i {
            font-size: 28px;
        }
        /* Message Alert */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin: 16px 20px;
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
        /* Responsive - Button on mobile: satu baris */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }
            .container {
                padding: 16px;
            }
            .page-title {
                font-size: 20px;
            }
            .filter-content {
                max-height: 90vh;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
            .create-btn {
                padding: 16px 20px;
                font-size: 15px;
            }
            .nav-center-btn {
                width: 64px;
                height: 64px;
                bottom: 22px;
            }
            .nav-center-btn i {
                font-size: 26px;
            }
            .nav-item i {
                font-size: 20px;
            }
            .bottom-nav {
                padding: 14px 18px 22px;
                bottom: 10px;
                left: 10px;
                right: 10px;
                border-radius: 28px;
            }
            /* Mobile: buttons in one line */
            .statement-actions {
                flex-direction: row;
                gap: 8px;
            }
            .btn-download, .btn-delete {
                flex: 1;
                padding: 10px 12px;
                font-size: 13px;
                gap: 6px;
            }
            .btn-download i, .btn-delete i {
                font-size: 12px;
            }
            .info-note, .limit-warning, .counter-info {
                margin: 0 16px 20px;
                padding: 10px 14px;
            }
            .info-note-icon {
                font-size: 12px;
            }
            .info-note-text {
                font-size: 10px;
                line-height: 1.3;
            }
            .limit-warning-text {
                font-size: 12px;
            }
            .counter-info {
                font-size: 12px;
                padding: 8px 12px;
            }
        }
        @media (max-width: 480px) {
            body {
                padding-bottom: 115px;
            }
            .nav-logo {
                height: 32px;
                max-width: 120px;
            }
            .create-btn {
                padding: 14px 18px;
                font-size: 14px;
            }
            .nav-center-btn {
                width: 62px;
                height: 62px;
                bottom: 20px;
            }
            .nav-center-btn i {
                font-size: 24px;
            }
            .nav-item {
                padding: 6px 8px;
            }
            .nav-item i {
                font-size: 19px;
            }
            .nav-item span {
                font-size: 9px;
            }
            .bottom-nav {
                border-radius: 24px;
                padding: 12px 16px 20px;
                bottom: 8px;
                left: 8px;
                right: 8px;
            }
            /* Extra small screens */
            .statement-actions {
                gap: 6px;
            }
            .btn-download, .btn-delete {
                padding: 8px 10px;
                font-size: 12px;
                gap: 4px;
            }
            .btn-download i, .btn-delete i {
                font-size: 11px;
            }
            .info-note, .limit-warning, .counter-info {
                padding: 8px 12px;
                margin: 0 12px 20px;
            }
            .info-note-icon {
                font-size: 11px;
            }
            .info-note-text {
                font-size: 9px;
            }
            .limit-warning-text {
                font-size: 11px;
            }
            .counter-info {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="<?php echo $base_url; ?>/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
    </nav>
    <!-- Sticky Header -->
    <?php if ($siswa_info): ?>
        <div class="sticky-header" id="stickyHeader">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Mutasi</h1>
            </div>
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab" onclick="window.location.href = 'cek_mutasi.php'">Mutasi Transaksi</button>
                <button class="tab active">e-Statement</button>
            </div>
        </div>
        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>
        <!-- Main Container -->
        <div class="container">
            <?php if ($error_message): ?>
                <div class="empty-state">
                    <div class="empty-icon">❌</div>
                    <h3 class="empty-title">Error</h3>
                    <p class="empty-text"><?php echo $error_message; ?></p>
                </div>
            <?php else: ?>

                
                <!-- Warning if limit reached -->
                <?php if ($current_count >= $max_e_statements): ?>
                    <div class="limit-warning">
                        <div class="limit-warning-content">
                            <div class="limit-warning-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="limit-warning-text">
                                <strong>Batas Maksimal Tercapai!</strong> Anda sudah mencapai batas maksimal <?php echo $max_e_statements; ?> e-Statement. Harap hapus salah satu e-Statement yang ada terlebih dahulu untuk membuat yang baru.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
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
                                        <div class="statement-month">e-Statement <?php echo htmlspecialchars($statement['month_name']); ?></div>
                                        <div class="statement-date">Dibuat: <?php echo date('d M Y', strtotime($statement['created_at'])); ?></div>
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
                                    <a href="?download=<?php echo $statement['id']; ?>" class="btn-download" onclick="showDownloadLoading('<?php echo htmlspecialchars($statement['month_name']); ?>')">
                                        <i class="fas fa-download"></i>
                                        Unduh PDF
                                    </a>
                                    <button class="btn-delete" onclick="deleteStatement(<?php echo $statement['id']; ?>, '<?php echo htmlspecialchars($statement['month_name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Info Note about auto-deletion - tanpa border kuning, teks kecil -->
                    <div class="info-note">
                        <div class="info-note-content">
                            <div class="info-note-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="info-note-text">
                                <strong>Catatan Penting:</strong> Semua e-Statement akan otomatis terhapus dari sistem setelah 7 hari. Pastikan Anda telah mengunduh e-Statement yang diperlukan sebelum waktu tersebut.
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
                
                <!-- Create Button - At the bottom of container -->
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
                    <input type="month" name="month" class="form-control" id="monthInput" value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" required>
                    <small style="font-size: 12px; color: var(--gray-500); margin-top: 4px; display: block;">
                        Pilih bulan untuk membuat e-Statement (akan otomatis dihapus setelah 7 hari)
                    </small>
                </div>
                <div class="form-group" style="display: none;">
                    <label class="form-label">Jenis File</label>
                    <select name="document_type" class="form-select">
                        <option value="PDF" selected>PDF</option>
                    </select>
                </div>
                <input type="hidden" name="rekening_id" value="<?php echo htmlspecialchars($siswa_info['rekening_id']); ?>">
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
    <!-- Bottom Navigation - Fully Rounded with New Icons -->
    <div class="bottom-nav" id="bottomNav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Beranda</span>
        </a>
        <a href="cek_mutasi.php" class="nav-item active">
            <i class="fas fa-list-alt"></i>
            <span>Mutasi</span>
        </a>
        <!-- Center Transfer Button - Paper Airplane -->
        <div class="nav-center">
            <a href="transfer_pribadi.php" class="nav-center-btn" title="Transfer">
                <i class="fas fa-paper-plane"></i>
            </a>
        </div>
        <a href="aktivitas.php" class="nav-item">
            <i class="fas fa-exchange-alt"></i>
            <span>Aktivitas</span>
        </a>
        <a href="profil.php" class="nav-item">
            <i class="fas fa-user"></i>
            <span>Profil</span>
        </a>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // SweetAlert Function
        function showAlert(message, type = 'info') {
            Swal.fire({
                title: type === 'success' ? 'Sukses!' : 'Info',
                text: message,
                icon: type,
                confirmButtonText: 'OK',
                confirmButtonColor: 'var(--elegant-dark)'
            });
        }
        
        // Detect Sticky Header
        let stickyHeader = document.getElementById('stickyHeader');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 70) {
                stickyHeader.classList.add('stuck');
            } else {
                stickyHeader.classList.remove('stuck');
            }
        });
        
        // Filter Modal Functions
        function openFilterModal() {
            // Check if limit reached
            const createBtn = document.querySelector('.create-btn');
            if (createBtn.disabled) {
                showAlert('Anda sudah mencapai batas maksimal 3 e-Statement. Harap hapus salah satu e-Statement yang ada terlebih dahulu untuk membuat yang baru.', 'warning');
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
                showAlert('Silakan pilih bulan terlebih dahulu', 'warning');
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
                confirmButtonColor: 'var(--elegant-dark)',
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
        
        // Bottom Bar Auto Hide/Show on Scroll
        let scrollTimer;
        let lastScrollTop = 0;
        const bottomNav = document.getElementById('bottomNav');
        
        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            if (currentScroll > lastScrollTop || currentScroll < lastScrollTop) {
                bottomNav.classList.add('hidden');
            }
            lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;
            
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => {
                bottomNav.classList.remove('hidden');
            }, 500);
        }, { passive: true });
        
        // Auto hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>