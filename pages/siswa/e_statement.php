<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../tcpdf/tcpdf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Pastikan user adalah siswa
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'siswa') {
    header("Location: ../../unauthorized.php");
    exit();
}
$user_id = $_SESSION['user_id'];

// Ambil info siswa dan rekening
$siswa_info = null;
$mutasi_data = [];
$error_message = '';

// Cek apakah sudah ada e-Statement (history)
$has_statement = false;
$stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM mutasi m LEFT JOIN rekening r ON m.rekening_id = r.id WHERE r.user_id = ?");
$stmt_check->bind_param("i", $user_id);
$stmt_check->execute();
$result_check = $stmt_check->get_result();
$check_data = $result_check->fetch_assoc();
$has_statement = ($check_data['count'] > 0);
$stmt_check->close();

// Ambil info siswa dan rekening
$stmt = $conn->prepare("
    SELECT u.id, u.nama, u.nis_nisn, r.no_rekening, r.saldo, r.id as rekening_id
    FROM users u
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

// Jika form di-submit untuk generate PDF
if (isset($_POST['generate_pdf']) && !empty($_POST['month'])) {
    $selected_month = $_POST['month'];
    $selected_rekening = $_POST['rekening_id'] ?? $siswa_info['rekening_id'];
    $document_type = $_POST['document_type'] ?? 'PDF';
    
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
        $row_number   = 1;
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
        if (!file_exists('../../tcpdf/tcpdf.php')) {
            throw new Exception("Pustaka TCPDF tidak ditemukan di ../../tcpdf/tcpdf.php");
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
        $pdf->Cell(0, 4, 'Periode: ' . formatMonthYear($filter_start_date), 0, 1, 'C');
        
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
            $saldo_akhir_periode = end($transactions)['saldo_akhir'];
            
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
            
            // Garis pemisah header
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
        
        $pdf->Output('e_statement_' . $selected_month . '.pdf', 'D');
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Gagal menghasilkan laporan: ' . $e->getMessage()]);
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
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
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
            padding-bottom: 80px;
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
            padding: 20px;
        }
        .sticky-header.stuck {
            box-shadow: var(--shadow-sm);
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
            margin-bottom: 24px;
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
        /* Info Card - e-Statement Explanation */
        .info-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .info-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .info-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--elegant-dark) 0%, var(--elegant-gray) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 24px;
            flex-shrink: 0;
        }
        .info-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }
        .info-description {
            font-size: 14px;
            line-height: 1.7;
            color: var(--gray-600);
            margin-bottom: 16px;
        }
        .info-features {
            display: grid;
            gap: 12px;
            margin-bottom: 20px;
        }
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px;
            background: var(--gray-50);
            border-radius: var(--radius-sm);
        }
        .feature-icon {
            width: 20px;
            height: 20px;
            background: var(--elegant-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 10px;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .feature-text {
            font-size: 13px;
            color: var(--gray-700);
            line-height: 1.5;
        }
        /* Generate Button - Inside Info Card */
        .generate-btn {
            width: 100%;
            background: var(--elegant-dark);
            border: none;
            border-radius: var(--radius);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 600;
            color: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-md);
        }
        .generate-btn:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .generate-btn:active {
            transform: translateY(0);
        }
        .generate-btn i {
            font-size: 16px;
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
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
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
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            padding: 12px 20px 20px;
            display: flex;
            justify-content: space-around;
            z-index: 100;
            transition: transform 0.3s ease, opacity 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }
        .bottom-nav.hidden {
            transform: translateY(100%);
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
            padding: 8px 16px;
        }
        .nav-item i {
            font-size: 22px;
            transition: color 0.2s ease;
        }
        .nav-item span {
            font-size: 11px;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        .nav-item:hover i,
        .nav-item:hover span,
        .nav-item.active i,
        .nav-item.active span {
            color: var(--elegant-dark);
        }
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            .page-title {
                font-size: 20px;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
            .info-card {
                padding: 20px;
            }
            .info-title {
                font-size: 16px;
            }
            .info-description {
                font-size: 13px;
            }
            .feature-text {
                font-size: 12px;
            }
            .generate-btn {
                padding: 12px 20px;
                font-size: 14px;
            }
        }
        @media (max-width: 480px) {
            .nav-logo {
                height: 32px;
                max-width: 120px;
            }
            .info-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="/schobank/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
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

        <!-- Main Container -->
        <div class="container">
            <?php if ($error_message): ?>
                <div class="empty-state">
                    <div class="empty-icon">❌</div>
                    <h3 class="empty-title">Error</h3>
                    <p class="empty-text"><?php echo $error_message; ?></p>
                </div>
            <?php else: ?>
                <!-- Info Card about e-Statement -->
                <div class="info-card">
                    <div class="info-header">
                        <div class="info-icon">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <h2 class="info-title">Apa itu e-Statement?</h2>
                    </div>
                    <p class="info-description">
                        e-Statement adalah laporan mutasi rekening digital yang menyajikan seluruh transaksi keuangan Anda dalam bentuk PDF. Dokumen ini dapat diunduh kapan saja dan berfungsi sebagai bukti resmi aktivitas rekening.
                    </p>
                    <div class="info-features">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Detail Transaksi Lengkap</strong> - Semua transaksi setor, tarik, dan transfer tercatat rapi
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Ringkasan Bulanan</strong> - Total debit, kredit, dan saldo akhir periode
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Format PDF Profesional</strong> - Dokumen dapat dicetak atau disimpan secara digital
                            </div>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Aman & Praktis</strong> - Dihasilkan otomatis oleh sistem untuk keakuratan data
                            </div>
                        </div>
                    </div>
                    <!-- Button inside the card -->
                    <button class="generate-btn" onclick="openFilterModal()">
                        <i class="fas fa-download"></i>
                        <span>Buat e-Statement</span>
                    </button>
                </div>

                <?php if (!$has_statement): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <h3 class="empty-title">Belum Ada Transaksi</h3>
                    <p class="empty-text">Akun Anda belum memiliki riwayat transaksi</p>
                </div>
                <?php endif; ?>
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
                    <input type="month" name="month" class="form-control" value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Jenis File</label>
                    <select name="document_type" class="form-select">
                        <option value="PDF" selected>PDF</option>
                    </select>
                </div>
                <input type="hidden" name="rekening_id" value="<?php echo htmlspecialchars($siswa_info['rekening_id']); ?>">
                <input type="hidden" name="generate_pdf" value="1">
                <div class="filter-actions">
                    <a href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="btn btn-secondary" onclick="closeFilterModal()">
                        <i class="fas fa-times"></i> Batal
                    </a>
                    <button type="button" class="btn btn-primary" onclick="generateStatement()">
                        <i class="fas fa-download"></i> Buat
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav" id="bottomNav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Beranda</span>
        </a>
        <a href="cek_mutasi.php" class="nav-item active">
            <i class="fas fa-list-alt"></i>
            <span>Mutasi</span>
        </a>
        <a href="aktivitas.php" class="nav-item">
            <i class="fas fa-history"></i>
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
        function showAlert(message) {
            Swal.fire({
                title: 'Info',
                text: message,
                icon: 'info',
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
            document.getElementById('filterModal').classList.add('active');
        }
        function closeFilterModal(event) {
            if (!event || event.target.id === 'filterModal') {
                document.getElementById('filterModal').classList.remove('active');
            }
        }

        // Generate Statement with Loading and AJAX
        function generateStatement() {
            Swal.fire({
                title: 'Sedang Membuat...',
                html: 'Mohon tunggu sementara kami membuat e-Statement Anda.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            setTimeout(() => {
                const form = document.getElementById('generateForm');
                const formData = new FormData(form);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = 'e_statement.pdf';
                    if (contentDisposition) {
                        const filenameMatch = contentDisposition.match(/filename="?(.+)"?/i);
                        if (filenameMatch) filename = filenameMatch[1];
                    }
                    return response.blob().then(blob => ({blob, filename}));
                })
                .then(({blob, filename}) => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    Swal.close();
                    closeFilterModal();
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: 'Terjadi kesalahan saat membuat e-Statement: ' + error.message
                    });
                });
            }, 3000);
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
    </script>
</body>
</html>
