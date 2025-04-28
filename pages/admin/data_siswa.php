<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Ensure TCPDF is included safely
if (!file_exists('../../tcpdf/tcpdf.php')) {
    die('[DEBUG] TCPDF library not found at ../../tcpdf/tcpdf.php');
}
require_once '../../tcpdf/tcpdf.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: ../../index.php');
    exit();
}

// Function to mask no_rekening (e.g., REK789456 -> REK7****6)
function maskNoRekening($no_rekening) {
    if (empty($no_rekening) || strlen($no_rekening) < 5) {
        return '-';
    }
    return substr($no_rekening, 0, 4) . '****' . substr($no_rekening, -1);
}

// Initialize filter parameters
$jurusan_id = isset($_GET['jurusan_id']) ? intval($_GET['jurusan_id']) : 0;
$kelas_id = isset($_GET['kelas_id']) ? intval($_GET['kelas_id']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Fetch jurusan options
$stmt_jurusan = $conn->prepare("SELECT id, nama_jurusan FROM jurusan ORDER BY nama_jurusan");
if (!$stmt_jurusan->execute()) {
    error_log('[DEBUG] Error fetching jurusan: ' . $conn->error);
    die("Error fetching jurusan: " . $conn->error);
}
$jurusan_options = $stmt_jurusan->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_jurusan->close();

// Base query for fetching students
$query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, k.nama_kelas, r.saldo 
          FROM users u
          LEFT JOIN rekening r ON u.id = r.user_id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          WHERE u.role = 'siswa'";
$count_query = "SELECT COUNT(*) as total FROM users u WHERE u.role = 'siswa'";
$params = [];
$types = '';

// Apply filters
if ($jurusan_id > 0) {
    $query .= " AND u.jurusan_id = ?";
    $count_query .= " AND u.jurusan_id = ?";
    $params[] = $jurusan_id;
    $types .= 'i';
}
if ($kelas_id > 0) {
    $query .= " AND u.kelas_id = ?";
    $count_query .= " AND u.kelas_id = ?";
    $params[] = $kelas_id;
    $types .= 'i';
}

// Add pagination for display query
$display_query = $query . " ORDER BY u.nama LIMIT ? OFFSET ?";
$params_display = array_merge($params, [$limit, $offset]);
$types_display = $types . 'ii';

// Fetch students for display
$stmt_siswa = $conn->prepare($display_query);
if (!empty($params_display)) {
    $stmt_siswa->bind_param($types_display, ...$params_display);
}
if (!$stmt_siswa->execute()) {
    error_log('[DEBUG] Error fetching siswa: ' . $conn->error);
    die("Error fetching siswa: " . $conn->error);
}
$siswa = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_siswa->close();

// Count total records
$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
if (!$stmt_count->execute()) {
    error_log('[DEBUG] Error counting records: ' . $conn->error);
    die("Error counting records: " . $conn->error);
}
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$stmt_count->close();

// Fetch filter names
$filter_jurusan = 'Semua Jurusan';
if ($jurusan_id > 0) {
    $stmt_jurusan_name = $conn->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
    $stmt_jurusan_name->bind_param("i", $jurusan_id);
    if ($stmt_jurusan_name->execute()) {
        $filter_jurusan = $stmt_jurusan_name->get_result()->fetch_assoc()['nama_jurusan'] ?? 'Semua Jurusan';
    }
    $stmt_jurusan_name->close();
}

$filter_kelas = 'Semua Kelas';
if ($kelas_id > 0) {
    $stmt_kelas_name = $conn->prepare("SELECT nama_kelas FROM kelas WHERE id = ?");
    $stmt_kelas_name->bind_param("i", $kelas_id);
    if ($stmt_kelas_name->execute()) {
        $filter_kelas = $stmt_kelas_name->get_result()->fetch_assoc()['nama_kelas'] ?? 'Semua Kelas';
    }
    $stmt_kelas_name->close();
}

// Handle exports
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    try {
        // Increase memory and execution time for PDF generation
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 60);

        // Clean output buffer to prevent corruption
        ob_end_clean();

        class MYPDF extends TCPDF {
            public function trimText($text, $maxLength) {
                return strlen($text) > $maxLength ? substr($text, 0, $maxLength - 3) . '...' : $text;
            }
            
            public function formatCurrency($amount) {
                return 'Rp ' . number_format($amount, 2, ',', '.');
            }

            public function maskNoRekening($no_rekening) {
                if (empty($no_rekening) || strlen($no_rekening) < 5) {
                    return '-';
                }
                return substr($no_rekening, 0, 4) . '****' . substr($no_rekening, -1);
            }

            public function Header() {
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
                $this->Line(15, 35, 195, 35);
            }

            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'L');
                $this->Cell(90, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        }

        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('SCHOBANK');
        $pdf->SetTitle('Data Siswa');
        $pdf->SetMargins(15, 40, 15);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'DATA SISWA', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 7, 'Tanggal: ' . date('d/m/Y'), 0, 1, 'C');

        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(180, 7, 'KETERANGAN', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(90, 6, 'Jurusan: ' . $filter_jurusan, 1, 0, 'L');
        $pdf->Cell(90, 6, 'Kelas: ' . $filter_kelas, 1, 1, 'L');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(180, 7, 'DAFTAR SISWA', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C');
        $pdf->Cell(50, 6, 'Nama', 1, 0, 'C');
        $pdf->Cell(30, 6, 'No Rekening', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jurusan', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Kelas', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Saldo', 1, 1, 'C');

        // Fetch all students for export
        $stmt_export = $conn->prepare($query . " ORDER BY u.nama");
        if (!empty($params)) {
            $stmt_export->bind_param($types, ...$params);
        }
        if (!$stmt_export->execute()) {
            error_log('[DEBUG] Error exporting to PDF: ' . $conn->error);
            throw new Exception("Error exporting to PDF: " . $conn->error);
        }
        $all_siswa = $stmt_export->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_export->close();

        $pdf->SetFont('helvetica', '', 8);
        $no = 1;
        foreach ($all_siswa as $row) {
            if (($no - 1) % 30 == 0 && $no > 1) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->Cell(10, 6, 'No', 1, 0, 'C');
                $pdf->Cell(50, 6, 'Nama', 1, 0, 'C');
                $pdf->Cell(30, 6, 'No Rekening', 1, 0, 'C');
                $pdf->Cell(35, 6, 'Jurusan', 1, 0, 'C');
                $pdf->Cell(25, 6, 'Kelas', 1, 0, 'C');
                $pdf->Cell(30, 6, 'Saldo', 1, 1, 'C');
                $pdf->SetFont('helvetica', '', 8);
            }
            
            $pdf->Cell(10, 6, $no++, 1, 0, 'C');
            $pdf->Cell(50, 6, $pdf->trimText($row['nama'] ?? '-', 25), 1, 0, 'L');
            $pdf->Cell(30, 6, $pdf->maskNoRekening($row['no_rekening']), 1, 0, 'L');
            $pdf->Cell(35, 6, $pdf->trimText($row['nama_jurusan'] ?? '-', 20), 1, 0, 'L');
            $pdf->Cell(25, 6, $pdf->trimText($row['nama_kelas'] ?? '-', 15), 1, 0, 'L');
            $pdf->Cell(30, 6, $pdf->formatCurrency($row['saldo'] ?? 0), 1, 1, 'R');
        }

        // Output PDF
        $pdf->Output('data_siswa.pdf', 'D');
        exit();
    } catch (Exception $e) {
        error_log('[DEBUG] PDF Generation Error: ' . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to generate PDF: ' . $e->getMessage()]);
        exit();
    }
} elseif (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="data_siswa.xls"');

    // Fetch all students for export
    $stmt_export = $conn->prepare($query . " ORDER BY u.nama");
    if (!empty($params)) {
        $stmt_export->bind_param($types, ...$params);
    }
    if (!$stmt_export->execute()) {
        error_log('[DEBUG] Error exporting to Excel: ' . $conn->error);
        die("Error exporting to Excel: " . $conn->error);
    }
    $all_siswa = $stmt_export->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_export->close();

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>
            body { font-family: Poppins, sans-serif; }
            .text-left { text-align: left; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            td, th { padding: 6px; border: 1px solid #ddd; }
            .title { font-size: 16pt; font-weight: bold; text-align: center; }
            .subtitle { font-size: 12pt; text-align: center; }
            .section-header { background-color: #f0f4ff; font-weight: bold; text-align: center; }
          </style></head><body>';

    echo '<table border="0" cellpadding="5">';
    echo '<tr><td colspan="6" class="title">DATA SISWA</td></tr>';
    echo '<tr><td colspan="6" class="subtitle">Tanggal: ' . date('d/m/Y') . '</td></tr>';
    echo '</table>';

    echo '<table border="1" cellpadding="5">';
    echo '<tr><th colspan="2" class="section-header">FILTER YANG DIPILIH</th></tr>';
    echo '<tr><td class="text-left">Jurusan</td><td class="text-left">' . htmlspecialchars($filter_jurusan) . '</td></tr>';
    echo '<tr><td class="text-left">Kelas</td><td class="text-left">' . htmlspecialchars($filter_kelas) . '</td></tr>';
    echo '</table>';

    echo '<table border="1" cellpadding="5">';
    echo '<tr><th colspan="6" class="section-header">DAFTAR SISWA</th></tr>';
    echo '<tr><th>No</th><th>Nama</th><th>No Rekening</th><th>Jurusan</th><th>Kelas</th><th>Saldo</th></tr>';

    $no = 1;
    foreach ($all_siswa as $row) {
        echo '<tr>';
        echo '<td class="text-center">' . $no++ . '</td>';
        echo '<td class="text-left">' . htmlspecialchars($row['nama'] ?? '-') . '</td>';
        echo '<td class="text-left">' . htmlspecialchars(maskNoRekening($row['no_rekening'])) . '</td>';
        echo '<td class="text-left">' . htmlspecialchars($row['nama_jurusan'] ?? '-') . '</td>';
        echo '<td class="text-left">' . htmlspecialchars($row['nama_kelas'] ?? '-') . '</td>';
        echo '<td class="text-right">Rp ' . number_format($row['saldo'] ?? 0, 2, ',', '.') . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Data Siswa - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        select {
            width: 100%;
            padding: 12px 35px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            background-color: white;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%230c4da2%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 12px;
            appearance: none;
        }

        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        .loading-spinner {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(25%);
            color: var(--primary-color);
            font-size: clamp(0.9rem, 2vw, 1rem);
            animation: spin 1s linear infinite;
            display: none;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
        }

        .btn-secondary:hover {
            background-color: var(--secondary-dark);
        }

        .transactions-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .transactions-title {
            background: var(--primary-dark);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        th {
            background: #f0f4ff;
            font-weight: 600;
            color: var(--text-primary);
        }

        td {
            background: white;
        }

        th:last-child, td:last-child {
            text-align: right;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: clamp(2rem, 4vw, 2.5rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 20px 0;
        }

        .pagination-link {
            background-color: var(--primary-light);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover:not(.disabled) {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination-link.disabled {
            background-color: #f0f0f0;
            color: #aaa;
            cursor: not-allowed;
            pointer-events: none;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border-left: 5px solid #81c784;
        }

        .loading .btn {
            pointer-events: none;
            background-color: #cccccc;
        }

        .loading .btn i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .top-nav { padding: 15px; font-size: clamp(1rem, 2.5vw, 1.2rem); }
            .main-content { padding: 15px; }
            .welcome-banner h2 { font-size: clamp(1.3rem, 3vw, 1.6rem); }
            .welcome-banner p { font-size: clamp(0.8rem, 2vw, 0.9rem); }
            .filter-form { flex-direction: column; gap: 15px; }
            .form-group { min-width: 100%; }
            .filter-buttons { flex-direction: column; width: 100%; }
            .btn { width: 100%; justify-content: center; }
            .transactions-container { padding: 20px; }
            table { display: block; overflow-x: auto; white-space: nowrap; }
            th, td { padding: 10px; font-size: clamp(0.8rem, 1.8vw, 0.9rem); }
            .pagination { flex-wrap: wrap; }
        }

        @media (max-width: 480px) {
            body { font-size: clamp(0.85rem, 2vw, 0.95rem); }
            .top-nav { padding: 10px; }
            .welcome-banner { padding: 20px; }
            .transactions-title { font-size: clamp(1rem, 2.5vw, 1.2rem); }
            .empty-state i { font-size: clamp(1.8rem, 4vw, 2rem); }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-users"></i> Data Siswa</h2>
            <p>Kelola data siswa dengan mudah</p>
        </div>

        <div id="alertContainer"></div>

        <div class="filter-section">
            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="jurusan_id">Jurusan</label>
                    <select id="jurusan_id" name="jurusan_id">
                        <option value="0">Semua Jurusan</option>
                        <?php foreach ($jurusan_options as $jurusan): ?>
                            <option value="<?= $jurusan['id'] ?>" <?= $jurusan_id == $jurusan['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($jurusan['nama_jurusan']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="kelas_id">Kelas</label>
                    <select id="kelas_id" name="kelas_id">
                        <option value="0">Semua Kelas</option>
                        <?php if ($jurusan_id > 0):
                            $stmt_kelas = $conn->prepare("SELECT id, nama_kelas FROM kelas WHERE jurusan_id = ? ORDER BY nama_kelas");
                            $stmt_kelas->bind_param("i", $jurusan_id);
                            if ($stmt_kelas->execute()) {
                                $kelas_options = $stmt_kelas->get_result()->fetch_all(MYSQLI_ASSOC);
                                foreach ($kelas_options as $kelas): ?>
                                    <option value="<?= $kelas['id'] ?>" <?= $kelas_id == $kelas['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($kelas['nama_kelas']) ?>
                                    </option>
                                <?php endforeach;
                            }
                            $stmt_kelas->close();
                        endif; ?>
                    </select>
                    <i class="fas fa-spinner loading-spinner" id="kelas-loading"></i>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" id="exportPdfButton" class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> Ekspor PDF
                    </button>
                    <button type="button" id="exportExcelButton" class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Ekspor Excel
                    </button>
                </div>
            </form>
        </div>

        <div class="transactions-container">
            <h3 class="transactions-title">Daftar Siswa</h3>
            <div id="tableContainer">
                <?php if (empty($siswa)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>Tidak ada data siswa yang ditemukan</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama</th>
                                <th>No Rekening</th>
                                <th>Jurusan</th>
                                <th>Kelas</th>
                                <th>Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = $offset + 1; foreach ($siswa as $row): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($row['nama'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars(maskNoRekening($row['no_rekening'])) ?></td>
                                    <td><?= htmlspecialchars($row['nama_jurusan'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['nama_kelas'] ?? '-') ?></td>
                                    <td>Rp <?= number_format($row['saldo'] ?? 0, 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?page=<?= max(1, $page - 1) ?>&jurusan_id=<?= htmlspecialchars($jurusan_id) ?>&kelas_id=<?= htmlspecialchars($kelas_id) ?>" 
                           class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>">« Sebelumnya</a>
                        <a href="?page=<?= min($total_pages, $page + 1) ?>&jurusan_id=<?= htmlspecialchars($jurusan_id) ?>&kelas_id=<?= htmlspecialchars($kelas_id) ?>" 
                           class="pagination-link <?= $page >= $total_pages ? 'disabled' : '' ?>">Selanjutnya »</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) event.preventDefault();
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) event.preventDefault();
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) event.preventDefault();
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            // Alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.alert');
                existingAlerts.forEach(alert => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                let icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
                alertDiv.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => {
                    alertDiv.classList.add('hide');
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
            }

            // Show loading spinner
            function showLoadingSpinner(button) {
                console.log('[DEBUG] Showing loading spinner for button:', button.id); // Debug log (remove after testing)
                const originalContent = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                button.classList.add('loading');
                return {
                    restore: () => {
                        button.innerHTML = originalContent;
                        button.classList.remove('loading');
                    }
                };
            }

            // Filter form handling
            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const exportPdfButton = document.getElementById('exportPdfButton');
            const exportExcelButton = document.getElementById('exportExcelButton');
            const jurusanSelect = document.getElementById('jurusan_id');
            const kelasSelect = document.getElementById('kelas_id');
            const kelasLoading = document.getElementById('kelas-loading');

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const spinner = showLoadingSpinner(filterButton);
                    setTimeout(() => {
                        spinner.restore();
                        filterForm.submit();
                    }, 3000);
                });
            }

            if (exportPdfButton) {
                exportPdfButton.addEventListener('click', function() {
                    const spinner = showLoadingSpinner(exportPdfButton);
                    const jurusan_id = jurusanSelect.value;
                    const kelas_id = kelasSelect.value;
                    let url = '?export=pdf';
                    if (jurusan_id) url += `&jurusan_id=${encodeURIComponent(jurusan_id)}`;
                    if (kelas_id) url += `&kelas_id=${encodeURIComponent(kelas_id)}`;
                    console.log('[DEBUG] PDF Export URL:', url); // Debug log (remove after testing)
                    setTimeout(() => {
                        try {
                            const xhr = new XMLHttpRequest();
                            xhr.open('GET', url, true);
                            xhr.responseType = 'blob';
                            xhr.onload = function() {
                                spinner.restore();
                                if (xhr.status === 200) {
                                    const blob = new Blob([xhr.response], { type: 'application/pdf' });
                                    const link = document.createElement('a');
                                    link.href = window.URL.createObjectURL(blob);
                                    link.download = 'data_siswa.pdf';
                                    link.click();
                                    window.URL.revokeObjectURL(link.href);
                                } else {
                                    xhr.response.text().then(text => {
                                        console.error('[DEBUG] PDF Export Error:', text); // Debug log (remove after testing)
                                        showAlert('Gagal mengekspor PDF: ' + (text || 'Unknown error'), 'error');
                                    });
                                }
                            };
                            xhr.onerror = function() {
                                spinner.restore();
                                console.error('[DEBUG] PDF Export Network Error'); // Debug log (remove after testing)
                                showAlert('Gagal mengekspor PDF: Network error', 'error');
                            };
                            xhr.send();
                        } catch (e) {
                            spinner.restore();
                            console.error('[DEBUG] PDF Export Exception:', e.message); // Debug log (remove after testing)
                            showAlert('Gagal mengekspor PDF: ' + e.message, 'error');
                        }
                    }, 3000);
                });
            }

            if (exportExcelButton) {
                exportExcelButton.addEventListener('click', function() {
                    const spinner = showLoadingSpinner(exportExcelButton);
                    const jurusan_id = jurusanSelect.value;
                    const kelas_id = kelasSelect.value;
                    let url = '?export=excel';
                    if (jurusan_id) url += `&jurusan_id=${encodeURIComponent(jurusan_id)}`;
                    if (kelas_id) url += `&kelas_id=${encodeURIComponent(kelas_id)}`;
                    console.log('[DEBUG] Excel Export URL:', url); // Debug log (remove after testing)
                    setTimeout(() => {
                        spinner.restore();
                        window.location.href = url;
                    }, 3000);
                });
            }

            if (jurusanSelect && kelasSelect) {
                jurusanSelect.addEventListener('change', function() {
                    const jurusan_id = this.value;
                    kelasLoading.style.display = 'block';
                    $.ajax({
                        url: 'get_kelas_by_jurusan.php',
                        type: 'GET',
                        data: { jurusan_id: jurusan_id },
                        success: function(response) {
                            kelasSelect.innerHTML = '<option value="0">Semua Kelas</option>' + response;
                            kelasLoading.style.display = 'none';
                        },
                        error: function(xhr, status, error) {
                            console.error('[DEBUG] Error fetching kelas:', status, error, xhr.responseText); // Debug log (remove after testing)
                            showAlert('Gagal memuat data kelas', 'error');
                            kelasSelect.innerHTML = '<option value="0">Semua Kelas</option>';
                            kelasLoading.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>