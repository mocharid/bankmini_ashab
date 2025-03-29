<?php
// Output buffering to prevent early output
ob_start();

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../tcpdf/tcpdf.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: ../../index.php');
    exit();
}

$jurusan_id = isset($_GET['jurusan_id']) ? intval($_GET['jurusan_id']) : 0;
$kelas_id = isset($_GET['kelas_id']) ? intval($_GET['kelas_id']) : 0;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$jurusan_options = $conn->query("SELECT * FROM jurusan")->fetch_all(MYSQLI_ASSOC);

// Modified query to include saldo from rekening table
$query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, k.nama_kelas, r.saldo 
          FROM users u
          LEFT JOIN rekening r ON u.id = r.user_id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          WHERE u.role = 'siswa'";

if ($jurusan_id > 0) {
    $query .= " AND u.jurusan_id = $jurusan_id";
}
if ($kelas_id > 0) {
    $query .= " AND u.kelas_id = $kelas_id";
}

// Add pagination for normal view
$query_with_limit = $query . " LIMIT $limit OFFSET $offset";
$siswa = $conn->query($query_with_limit)->fetch_all(MYSQLI_ASSOC);

$count_query = "SELECT COUNT(*) as total FROM users u WHERE u.role = 'siswa'";
if ($jurusan_id > 0) {
    $count_query .= " AND u.jurusan_id = $jurusan_id";
}
if ($kelas_id > 0) {
    $count_query .= " AND u.kelas_id = $kelas_id";
}

$total_records = $conn->query($count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Ambil nama jurusan dan kelas yang difilter
$filter_jurusan = $conn->query("SELECT nama_jurusan FROM jurusan WHERE id = $jurusan_id")->fetch_assoc()['nama_jurusan'] ?? 'Semua Jurusan';
$filter_kelas = $conn->query("SELECT nama_kelas FROM kelas WHERE id = $kelas_id")->fetch_assoc()['nama_kelas'] ?? 'Semua Kelas';

if (isset($_GET['export'])) {
    // Clear any buffered output before setting headers
    ob_end_clean();
    
    if ($_GET['export'] == 'pdf') {
        class MYPDF extends TCPDF {
            // Fungsi untuk memotong teks agar sesuai dengan lebar kolom
            public function trimText($text, $maxLength) {
                if (strlen($text) > $maxLength) {
                    return substr($text, 0, $maxLength - 3) . '...';
                }
                return $text;
            }
            
            // Format number to currency
            public function formatCurrency($amount) {
                return 'Rp ' . number_format($amount, 2, ',', '.');
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

        // Tampilkan filter jurusan dan kelas dalam kotak
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(180, 7, 'KETERANGAN', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(90, 6, 'Jurusan: ' . $filter_jurusan, 1, 0, 'L');
        $pdf->Cell(90, 6, 'Kelas: ' . $filter_kelas, 1, 1, 'L');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(180, 7, 'DAFTAR SISWA', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C');
        $pdf->Cell(50, 6, 'Nama', 1, 0, 'C');
        $pdf->Cell(30, 6, 'No Rekening', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jurusan', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Kelas', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Saldo', 1, 1, 'C');  // Added Saldo column

        // Get all students data without pagination limit for PDF
        $export_query = $query;  // Use the filter query without LIMIT
        $all_siswa = $conn->query($export_query)->fetch_all(MYSQLI_ASSOC);

        $pdf->SetFont('helvetica', '', 8);
        $no = 1;
        foreach ($all_siswa as $row) {
            // Check if we need a new page (every 30 rows)
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
            $pdf->Cell(50, 6, $pdf->trimText($row['nama'], 25), 1, 0, 'L');
            $pdf->Cell(30, 6, $pdf->trimText($row['no_rekening'] ?? '-', 15), 1, 0, 'L');
            $pdf->Cell(35, 6, $pdf->trimText($row['nama_jurusan'], 20), 1, 0, 'L');
            $pdf->Cell(25, 6, $pdf->trimText($row['nama_kelas'], 15), 1, 0, 'L');
            $pdf->Cell(30, 6, $pdf->formatCurrency($row['saldo'] ?? 0), 1, 1, 'R');  // Added Saldo value
        }

        $pdf->Output('data_siswa.pdf', 'D');
        exit();
    } elseif ($_GET['export'] == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="data_siswa.xls"');

        // Get all data without pagination for Excel export
        $export_query = $query;  // Use the filter query without LIMIT
        $all_siswa = $conn->query($export_query)->fetch_all(MYSQLI_ASSOC);

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
        echo '<style>
                body { font-family: Arial, sans-serif; }
                .text-left { text-align: left; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                td, th { padding: 4px; }
                .title { font-size: 14pt; font-weight: bold; text-align: center; }
                .subtitle { font-size: 10pt; text-align: center; }
                .section-header { background-color: #f0f0f0; font-weight: bold; text-align: center; }
              </style>';
        echo '</head>';
        echo '<body>';

        echo '<table border="0" cellpadding="3">';
        echo '<tr><td colspan="6" class="title">DATA SISWA</td></tr>';
        echo '<tr><td colspan="6" class="subtitle">Tanggal: ' . date('d/m/Y') . '</td></tr>';
        echo '</table>';

        // Tampilkan filter jurusan dan kelas dalam tabel
        echo '<table border="1" cellpadding="3">';
        echo '<tr><th colspan="2" class="section-header">FILTER YANG DIPILIH</th></tr>';
        echo '<tr>';
        echo '<td class="text-left">Jurusan</td>';
        echo '<td class="text-left">' . $filter_jurusan . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td class="text-left">Kelas</td>';
        echo '<td class="text-left">' . $filter_kelas . '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<table border="1" cellpadding="3">';
        echo '<tr><th colspan="6" class="section-header">DAFTAR SISWA</th></tr>';
        echo '<tr>';
        echo '<th>No</th>';
        echo '<th>Nama</th>';
        echo '<th>No Rekening</th>';
        echo '<th>Jurusan</th>';
        echo '<th>Kelas</th>';
        echo '<th>Saldo</th>';  // Added Saldo column
        echo '</tr>';

        $no = 1;
        foreach ($all_siswa as $row) {
            echo '<tr>';
            echo '<td class="text-center">' . $no++ . '</td>';
            echo '<td class="text-left">' . $row['nama'] . '</td>';
            echo '<td class="text-left">' . ($row['no_rekening'] ?? '-') . '</td>';
            echo '<td class="text-left">' . $row['nama_jurusan'] . '</td>';
            echo '<td class="text-left">' . $row['nama_kelas'] . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['saldo'] ?? 0, 2, ',', '.') . '</td>';  // Added Saldo value
            echo '</tr>';
        }
        echo '</table>';

        echo '</body></html>';
        exit();
    }
}

// HTML content starts here
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Siswa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
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
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
        }

        .back-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: transparent;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block; /* Hanya seukuran ikon */
            padding: 0; /* Hilangkan padding */
            margin: 0; /* Hilangkan margin */
            line-height: 1; /* Pastikan tidak ada ruang tambahan */
        }

        .back-btn i {
            display: block; /* Ikon sebagai block element */
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            width: 100%;
            transition: var(--transition);
        }

        .form-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .card-header h3 {
            color: var(--primary-dark);
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .filter-info {
            background-color: var(--primary-light);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-info-item {
            background-color: white;
            border-radius: 8px;
            padding: 10px;
            box-shadow: var(--shadow-sm);
            flex: 1;
            min-width: 200px;
        }

        .filter-info-item h4 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .filter-info-item p {
            font-size: 16px;
            color: var(--text-primary);
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 14px;
        }

        label i {
            margin-right: 5px;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
            letter-spacing: 0.5px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn i {
            font-size: 13px;
        }

        form > .btn {
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            gap: 8px;
        }

        form > .btn i {
            font-size: 14px;
        }

        .btn-primary {
            background-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: #7b7b7b;
        }

        .btn-secondary:hover {
            background-color: #5a5a5a;
        }

        .btn-danger {
            background-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #d32f2f;
        }

        .data-table td .btn {
            margin-right: 2px;
        }

        .data-table td .btn:last-child {
            margin-right: 0;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.5s ease-out;
            position: relative;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-icon {
            font-size: 20px;
        }

        .alert-message {
            flex: 1;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            opacity: 0.7;
            transition: var(--transition);
        }

        .alert-close:hover {
            opacity: 1;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 5px solid #bbf7d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .filter-container {
            margin-bottom: 20px;
            padding: 15px;
            background-color: var(--primary-light);
            border-radius: 10px;
        }

        .filter-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .filter-group label {
            margin-bottom: 0;
            white-space: nowrap;
        }

        .filter-btn, .reset-btn {
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
            text-decoration: none;
        }

        .filter-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }

        .filter-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .reset-btn {
            background-color: #f1f1f1;
            color: var(--text-secondary);
            border: 1px solid #ddd;
        }

        .reset-btn:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 10px;
            overflow: hidden;
        }

        .data-table th {
            background-color: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 500;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:nth-child(even) {
            background-color: var(--primary-light);
        }

        .data-table tr:hover {
            background-color: rgba(12, 77, 162, 0.05);
        }

        .data-table th:last-child,
        .data-table td:last-child {
            width: 150px;
            white-space: nowrap;
            text-align: center;
        }

        .empty-message {
            padding: 30px;
            text-align: center;
            color: var(--text-secondary);
            font-size: 16px;
            background-color: #f9f9f9;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .empty-message i {
            font-size: 30px;
            color: var(--primary-color);
            margin-bottom: 15px;
            display: block;
        }

        .pagination {
            margin-top: 25px;
            display: flex;
            justify-content: center;
        }

        .pagination ul {
            display: flex;
            list-style: none;
            gap: 5px;
        }

        .pagination li {
            margin: 0 2px;
        }

        .pagination li a {
            display: block;
            padding: 8px 15px;
            border-radius: 8px;
            background-color: white;
            color: var(--text-primary);
            text-decoration: none;
            border: 1px solid #eee;
            transition: var(--transition);
        }

        .pagination li a:hover {
            background-color: var(--primary-light);
        }

        .pagination li.active a {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Dropdown styling */
        .form-select {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
            letter-spacing: 0.5px;
            appearance: none;
            background-color: white;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%230c4da2%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 12px;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        /* Dropdown animation */
        .form-select {
            transition: all 0.3s ease;
        }

        .form-select:hover {
            border-color: var(--primary-color);
        }

        /* Dropdown scrollable */
        .form-select option {
            padding: 10px;
        }

        /* Dropdown max height */
        .form-select {
            max-height: 200px;
            overflow-y: auto;
        }

        /* Responsive layout for filter form */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: 20px;
            }

            .welcome-banner p {
                font-size: 14px;
            }

            .form-card {
                padding: 15px;
            }

            .card-header h3 {
                font-size: 18px;
            }

            .filter-info {
                flex-direction: column;
            }

            .filter-info-item {
                min-width: 100%;
            }

            .form-control {
                font-size: 14px;
            }

            .btn {
                font-size: 12px;
                padding: 6px 10px;
            }

            .data-table th, .data-table td {
                padding: 10px;
                font-size: 12px;
            }

            .data-table th:last-child,
            .data-table td:last-child {
                width: auto;
            }

            .pagination li a {
                padding: 6px 12px;
                font-size: 12px;
            }

            /* Responsive filter form */
            .filter-form .col-md-4 {
                margin-bottom: 15px;
            }

            .filter-form .col-md-4:last-child {
                margin-bottom: 0;
            }

            .filter-form .btn {
                width: 100%;
            }
        }
        </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-users"></i> Data Nasabah</h2>
            <p>Kelola Data Nasabah dengan Mudah</p>
            <a href="../admin/dashboard.php" class="back-btn">
                <i class="fas fa-times"></i> <!-- Hanya ikon "x" -->
            </a>
        </div>

        <div class="form-card">
            <div class="card-header">
                <h3>Filter Data Siswa</h3>
            </div>

            <form method="GET" action="" class="filter-form">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="jurusan_id" class="form-label">Jurusan</label>
                        <select class="form-select" id="jurusan_id" name="jurusan_id" onchange="getKelasByJurusan(this.value)">
                            <option value="0">Semua Jurusan</option>
                            <?php foreach ($jurusan_options as $jurusan): ?>
                                <option value="<?php echo $jurusan['id']; ?>" <?php echo $jurusan_id == $jurusan['id'] ? 'selected' : ''; ?>>
                                    <?php echo $jurusan['nama_jurusan']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="kelas_id" class="form-label">Kelas</label>
                        <select class="form-select" id="kelas_id" name="kelas_id">
                            <option value="0">Semua Kelas</option>
                            <?php if ($jurusan_id > 0): ?>
                                <?php
                                $kelas_options = $conn->query("SELECT id, nama_kelas FROM kelas WHERE jurusan_id = $jurusan_id")->fetch_all(MYSQLI_ASSOC);
                                foreach ($kelas_options as $kelas): ?>
                                    <option value="<?php echo $kelas['id']; ?>" <?php echo $kelas_id == $kelas['id'] ? 'selected' : ''; ?>>
                                        <?php echo $kelas['nama_kelas']; ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tampilkan filter yang dipilih dalam kotak -->
        <div class="form-card">
            <div class="card-header">
                <h3>Daftar Siswa</h3>
                <div class="export-buttons">
                    <a href="?export=pdf&jurusan_id=<?php echo $jurusan_id; ?>&kelas_id=<?php echo $kelas_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> Ekspor PDF
                    </a>
                    <a href="?export=excel&jurusan_id=<?php echo $jurusan_id; ?>&kelas_id=<?php echo $kelas_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Ekspor Excel
                    </a>
                </div>
            </div>

            <div class="table-container">
                <?php if (count($siswa) > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama</th>
                                <th>No Rekening</th>
                                <th>Jurusan</th>
                                <th>Kelas</th>
                                <th>Saldo</th>  <!-- Added Saldo column -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = $offset + 1; ?>
                            <?php foreach ($siswa as $row): ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo $row['nama']; ?></td>
                                    <td><?php echo $row['no_rekening'] ?? '-'; ?></td>
                                    <td><?php echo $row['nama_jurusan']; ?></td>
                                    <td><?php echo $row['nama_kelas']; ?></td>
                                    <td class="text-end">Rp <?php echo number_format($row['saldo'] ?? 0, 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="pagination">
                        <?php if ($total_pages > 1): ?>
                            <ul>
                                <?php if ($page > 1): ?>
                                    <li><a href="?jurusan_id=<?php echo $jurusan_id; ?>&kelas_id=<?php echo $kelas_id; ?>&page=<?php echo $page - 1; ?>">&laquo; Previous</a></li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="<?php echo ($page == $i) ? 'active' : ''; ?>">
                                        <a href="?jurusan_id=<?php echo $jurusan_id; ?>&kelas_id=<?php echo $kelas_id; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li><a href="?jurusan_id=<?php echo $jurusan_id; ?>&kelas_id=<?php echo $kelas_id; ?>&page=<?php echo $page + 1; ?>">Next &raquo;</a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-message">
                        <i class="fas fa-info-circle"></i> Tidak ada data siswa yang ditemukan.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script>
        function getKelasByJurusan(jurusan_id) {
            if (jurusan_id) {
                fetch(`get_kelas_by_jurusan.php?jurusan_id=${jurusan_id}`)
                    .then(response => response.text())
                    .then(data => {
                        document.getElementById('kelas_id').innerHTML = data;
                    });
            } else {
                document.getElementById('kelas_id').innerHTML = '<option value="0">Semua Kelas</option>';
            }
        }
    </script>
</body>
</html>
<?php
// End the output buffer and flush it
ob_end_flush();
?>