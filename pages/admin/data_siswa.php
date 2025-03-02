<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Ambil parameter dari URL
$jurusan_id = $_GET['jurusan'] ?? '';
$kelas_id = $_GET['kelas'] ?? '';
$format = $_GET['format'] ?? '';
$edit_id = $_GET['edit'] ?? '';

// Pagination
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$query_total = "SELECT COUNT(*) as total 
                FROM users u
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN rekening r ON u.id = r.user_id
                WHERE u.role = 'siswa'";

if (!empty($jurusan_id)) {
    $query_total .= " AND u.jurusan_id = $jurusan_id";
}

if (!empty($kelas_id)) {
    $query_total .= " AND u.kelas_id = $kelas_id";
}

$result_total = $conn->query($query_total);
$row_total = $result_total->fetch_assoc();
$total_records = $row_total['total'];
$total_pages = ceil($total_records / $limit);

// Query data jurusan untuk filter
$query_jurusan = "SELECT id, nama_jurusan FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);

// Query data siswa dengan filter dan nomor rekening
$query_siswa = "SELECT u.id, u.nama, j.nama_jurusan AS jurusan, k.nama_kelas AS kelas, r.no_rekening 
                FROM users u
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN rekening r ON u.id = r.user_id
                WHERE u.role = 'siswa'";

if (!empty($jurusan_id)) {
    $query_siswa .= " AND u.jurusan_id = $jurusan_id";
}

if (!empty($kelas_id)) {
    $query_siswa .= " AND u.kelas_id = $kelas_id";
}

$query_siswa .= " LIMIT $limit OFFSET $offset";
$result_siswa = $conn->query($query_siswa);

$result_siswa = $conn->query($query_siswa);

// Jika format PDF atau Excel dipilih
if ($format === 'pdf' || $format === 'excel') {
    // Data untuk PDF/Excel
    $data_siswa = [];
    while ($row = $result_siswa->fetch_assoc()) {
        $data_siswa[] = $row;
    }

    if ($format === 'pdf') {
        require_once '../../tcpdf/tcpdf.php';

        class MYPDF extends TCPDF {
            public function Header() {
                // Logo
                $image_file = '../../assets/images/logo.png';
                if (file_exists($image_file)) {
                    $this->Image($image_file, 15, 5, 20, '', 'PNG');
                }
                
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Mini Bank Sekolah', 0, false, 'C', 0);
                $this->Line(15, 25, 195, 25);
            }

            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'L');
                $this->Cell(90, 10, 'Halaman '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'R');
            }
        }

        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('SCHOBANK');
        $pdf->SetTitle('Data Nasabah');
        
        $pdf->SetMargins(15, 30, 15);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();

        // Judul
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'DATA NASABAH', 0, 1, 'C');

        $totalWidth = 180;
        
        // Tabel
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($totalWidth, 7, 'DETAIL NASABAH', 1, 1, 'C', true);
        
        $col_width = array(15, 50, 35, 30, 50);
        
        $pdf->SetFont('helvetica', 'B', 8);
        $header_heights = 6;
        $pdf->Cell($col_width[0], $header_heights, 'No', 1, 0, 'C', true);
        $pdf->Cell($col_width[1], $header_heights, 'Nama', 1, 0, 'C', true);
        $pdf->Cell($col_width[2], $header_heights, 'Jurusan', 1, 0, 'C', true);
        $pdf->Cell($col_width[3], $header_heights, 'Kelas', 1, 0, 'C', true);
        $pdf->Cell($col_width[4], $header_heights, 'No Rekening', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 8);
        $row_height = 5;
        $no = 1;
        foreach ($data_siswa as $row) {
            $pdf->Cell($col_width[0], $row_height, $no++, 1, 0, 'C');
            
            $nama = mb_strlen($row['nama']) > 25 ? mb_substr($row['nama'], 0, 23) . '...' : $row['nama'];
            $pdf->Cell($col_width[1], $row_height, $nama, 1, 0, 'L');
            $pdf->Cell($col_width[2], $row_height, $row['jurusan'], 1, 0, 'L');
            $pdf->Cell($col_width[3], $row_height, $row['kelas'], 1, 0, 'C');
            $pdf->Cell($col_width[4], $row_height, $row['no_rekening'], 1, 1, 'C');
        }

        // Output PDF
        $pdf->Output('data_nasabah.pdf', 'D');
    }

    elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="data_nasabah.xls"');
        
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
                
                /* Make sure all tables have the same width */
                table.main-table { 
                    width: 100%; 
                    table-layout: fixed; 
                    border-collapse: collapse; 
                    margin-top: 5px;
                }
                
                /* Column widths for detail table */
                th.no { width: 5%; }
                th.nama { width: 30%; }
                th.jurusan { width: 20%; }
                th.kelas { width: 15%; }
                th.no-rekening { width: 30%; }
              </style>';
        echo '</head>';
        echo '<body>';
        
        // Judul
        echo '<table class="main-table" border="0" cellpadding="3">';
        echo '<tr><td colspan="5" class="title">DATA NASABAH</td></tr>';
        echo '</table>';
        
        // Tabel
        echo '<table class="main-table" border="1" cellpadding="3">';
        echo '<tr><th colspan="5" class="section-header">DETAIL NASABAH</th></tr>';
        
        echo '<tr class="section-header">';
        echo '<th class="no">No</th>';
        echo '<th class="nama">Nama</th>';
        echo '<th class="jurusan">Jurusan</th>';
        echo '<th class="kelas">Kelas</th>';
        echo '<th class="no-rekening">No Rekening</th>';
        echo '</tr>';
        
        $no = 1;
        foreach ($data_siswa as $row) {
            echo '<tr>';
            echo '<td class="text-center">' . $no++ . '</td>';
            echo '<td class="text-left">' . $row['nama'] . '</td>';
            echo '<td class="text-left">' . $row['jurusan'] . '</td>';
            echo '<td class="text-center">' . $row['kelas'] . '</td>';
            echo '<td class="text-center">' . $row['no_rekening'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        echo '</body></html>';
    }
    exit();
}

// Proses edit data siswa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_siswa'])) {
    $id = $_POST['id'];
    $nama = $_POST['nama'];
    $jurusan_id = $_POST['jurusan'];
    $kelas_id = $_POST['kelas'];
    $no_rekening = $_POST['no_rekening'];

    // Update data siswa
    $query_update_siswa = "UPDATE users SET nama = '$nama', jurusan_id = $jurusan_id, kelas_id = $kelas_id WHERE id = $id";
    $conn->query($query_update_siswa);

    // Check if rekening record exists
    $query_check_rekening = "SELECT user_id FROM rekening WHERE user_id = $id";
    $result_check_rekening = $conn->query($query_check_rekening);

    if ($result_check_rekening->num_rows > 0) {
        // Update existing rekening record
        $query_update_rekening = "UPDATE rekening SET no_rekening = '$no_rekening' WHERE user_id = $id";
        $conn->query($query_update_rekening);
    } else {
        // Insert new rekening record
        $query_insert_rekening = "INSERT INTO rekening (user_id, no_rekening) VALUES ($id, '$no_rekening')";
        $conn->query($query_insert_rekening);
    }

    // Redirect kembali ke halaman data siswa
    header('Location: data_siswa.php');
    exit();
}

// Ambil data siswa yang akan diedit
if (!empty($edit_id)) {
    $query_edit_siswa = "SELECT u.id, u.nama, u.jurusan_id, u.kelas_id, r.no_rekening 
                         FROM users u
                         LEFT JOIN rekening r ON u.id = r.user_id
                         WHERE u.id = $edit_id";
    $result_edit_siswa = $conn->query($query_edit_siswa);
    $row_edit_siswa = $result_edit_siswa->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Nasabah - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --warning-color: #f8961e;
            --info-color: #4895ef;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fd;
            color: #333;
            line-height: 1.6;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 20px;
            border-radius: var(--border-radius);
            color: white;
            box-shadow: var(--shadow);
        }

        .header h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            color: white;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: var(--transition);
            background-color: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 6px;
        }

        .back-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .back-btn i {
            margin-right: 8px;
        }

        /* Actions bar */
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 16px;
            border-radius: var(--border-radius);
            font-weight: 500;
            text-decoration: none;
            color: white;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 14px;
            box-shadow: var(--shadow);
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-pdf {
            background-color: var(--danger-color);
        }

        .btn-excel {
            background-color: #2e7d32;
        }

        .btn-pdf:hover {
            background-color: #e5105e;
        }

        .btn-excel:hover {
            background-color: #1b5e20;
        }

        .btn-edit {
            background-color: var(--info-color);
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-edit:hover {
            background-color: #3a7fc9;
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            background-color: white;
            padding: 15px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .filters select {
            flex: 1;
            min-width: 150px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23333' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: calc(100% - 10px) center;
            background-size: 12px;
            cursor: pointer;
            transition: var(--transition);
        }

        .filters select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .filters button {
            background-color: var(--primary-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            transition: var(--transition);
        }

        .filters button:hover {
            background-color: var(--secondary-color);
        }

        /* Table styles */
        .table-container {
            overflow-x: auto;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        thead {
            background-color: var(--primary-color);
            color: white;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
        }

        th {
            font-weight: 500;
            letter-spacing: 0.5px;
            font-size: 14px;
        }

        tbody tr {
            border-bottom: 1px solid #eee;
            transition: var(--transition);
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: #f5f7fd;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }

        .page-link {
            padding: 8px 12px;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--primary-color);
            background-color: white;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .page-link:hover {
            background-color: #f0f4ff;
        }

        .page-link.active {
            background-color: var(--primary-color);
            color: white;
        }

        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal {
            background-color: white;
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 500px;
            position: relative;
            animation: modalOpen 0.3s ease;
        }

        @keyframes modalOpen {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #777;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--danger-color);
        }

        .edit-form {
            padding: 20px;
        }

        .edit-form h2 {
            margin-bottom: 20px;
            color: var(--primary-color);
            font-size: 20px;
            font-weight: 600;
        }

        .edit-form label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 14px;
        }

        .edit-form input, .edit-form select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .edit-form input[readonly] {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }

        .edit-form input:focus, .edit-form select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .edit-form button {
            width: 100%;
            padding: 12px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .edit-form button:hover {
            background-color: var(--secondary-color);
        }

        /* Loading indicator */
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            display: none;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(67, 97, 238, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s infinite linear;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty state */
        .empty-state {
            padding: 30px;
            text-align: center;
            color: #777;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .btn {
                font-size: 13px;
                padding: 8px 12px;
            }

            .actions {
                flex-direction: column;
                align-items: stretch;
            }

            .export-buttons {
                justify-content: space-between;
            }

            th, td {
                padding: 10px;
                font-size: 13px;
            }
        }

        /* Toast notifications */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: white;
            color: #333;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 9999;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            border-left: 4px solid var(--success-color);
        }

        .toast.error {
            border-left: 4px solid var(--danger-color);
        }

        .toast i {
            margin-right: 10px;
            font-size: 20px;
        }

        .toast.success i {
            color: var(--success-color);
        }

        .toast.error i {
            color: var(--danger-color);
        }
    </style>
</head>
<body>
    <!-- Loading Indicator -->
    <div class="loading">
        <div class="spinner"></div>
    </div>

    <!-- Toast Notifications -->
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toast-message">Operation successful!</span>
    </div>

    <div class="container">
        <!-- Header with Title and Back Button -->
        <div class="header">
            <h1><i class="fas fa-users"></i> Data Siswa</h1>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>

        <!-- Action Buttons -->
        <div class="actions">
            <div class="export-buttons">
                <a href="data_siswa.php?jurusan=<?= $jurusan_id ?>&kelas=<?= $kelas_id ?>&format=pdf" class="btn btn-pdf">
                    <i class="fas fa-file-pdf"></i> Cetak PDF
                </a>
                <a href="data_siswa.php?jurusan=<?= $jurusan_id ?>&kelas=<?= $kelas_id ?>&format=excel" class="btn btn-excel">
                    <i class="fas fa-file-excel"></i> Cetak Excel
                </a>
            </div>

            <!-- Filter Form -->
            <form method="GET" action="data_siswa.php" class="filters">
                <select name="jurusan" id="jurusan">
                    <option value="">Semua Jurusan</option>
                    <?php 
                    $result_jurusan->data_seek(0);
                    while ($row = $result_jurusan->fetch_assoc()): 
                    ?>
                        <option value="<?= $row['id'] ?>" <?= $jurusan_id == $row['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($row['nama_jurusan']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select name="kelas" id="kelas">
                    <option value="">Semua Kelas</option>
                    <?php
                    if (!empty($jurusan_id)) {
                        $query_kelas = "SELECT id, nama_kelas FROM kelas WHERE jurusan_id = $jurusan_id";
                        $result_kelas = $conn->query($query_kelas);
                        while ($row = $result_kelas->fetch_assoc()) {
                            $selected = ($kelas_id == $row['id']) ? 'selected' : '';
                            echo "<option value='" . $row['id'] . "' $selected>" . htmlspecialchars($row['nama_kelas']) . "</option>";
                        }
                    }
                    ?>
                </select>

                <button type="submit"><i class="fas fa-filter"></i> Filter</button>
            </form>
        </div>

        <!-- Student Data Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="5%">No</th>
                        <th width="25%">Nama</th>
                        <th width="25%">Jurusan</th>
                        <th width="15%">Kelas</th>
                        <th width="20%">No Rekening</th>
                        <th width="10%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result_siswa->num_rows > 0) {
                        $no = ($page - 1) * $limit + 1;
                        while ($row = $result_siswa->fetch_assoc()): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($row['nama']) ?></td>
                            <td><?= htmlspecialchars($row['jurusan']) ?></td>
                            <td><?= htmlspecialchars($row['kelas']) ?></td>
                            <td><?= htmlspecialchars($row['no_rekening']) ?></td>
                            <td>
                                <a href="javascript:void(0);" class="btn btn-edit" onclick="openEditModal(<?= $row['id'] ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                        <?php endwhile;
                    } else { ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="fas fa-search"></i>
                                <p>Tidak ada data siswa yang ditemukan</p>
                                <p>Coba ubah filter atau tambahkan data baru</p>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?jurusan=<?= $jurusan_id ?>&kelas=<?= $kelas_id ?>&page=<?= $page - 1 ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>

            <?php 
            // Show limited page numbers with ellipsis
            $startPage = max(1, min($page - 1, $total_pages - 4));
            $endPage = min($total_pages, max(5, $page + 2));
            
            if ($startPage > 1) {
                echo '<a href="?jurusan=' . $jurusan_id . '&kelas=' . $kelas_id . '&page=1" class="page-link">1</a>';
                if ($startPage > 2) {
                    echo '<span class="page-link">...</span>';
                }
            }
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                echo '<a href="?jurusan=' . $jurusan_id . '&kelas=' . $kelas_id . '&page=' . $i . '" class="page-link ' . ($i == $page ? 'active' : '') . '">' . $i . '</a>';
            }
            
            if ($endPage < $total_pages) {
                if ($endPage < $total_pages - 1) {
                    echo '<span class="page-link">...</span>';
                }
                echo '<a href="?jurusan=' . $jurusan_id . '&kelas=' . $kelas_id . '&page=' . $total_pages . '" class="page-link">' . $total_pages . '</a>';
            }
            ?>

            <?php if ($page < $total_pages): ?>
                <a href="?jurusan=<?= $jurusan_id ?>&kelas=<?= $kelas_id ?>&page=<?= $page + 1 ?>" class="page-link">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Edit Modal -->
        <div id="editModal" class="modal-overlay">
            <div class="modal">
                <span class="modal-close" onclick="closeEditModal()">&times;</span>
                <div class="edit-form">
                    <h2><i class="fas fa-user-edit"></i> Edit Data Siswa</h2>
                    <form method="POST" action="data_siswa.php" id="editForm">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <label for="nama">Nama:</label>
                        <input type="text" id="nama" name="nama" required>
                        
                        <label for="jurusan">Jurusan:</label>
                        <select id="jurusan_edit" name="jurusan" required>
                            <option value="">Pilih Jurusan</option>
                            <?php
                            $result_jurusan->data_seek(0);
                            while ($row = $result_jurusan->fetch_assoc()): ?>
                                <option value="<?= $row['id'] ?>">
                                    <?= htmlspecialchars($row['nama_jurusan']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        
                        <label for="kelas">Kelas:</label>
                        <select id="kelas_edit" name="kelas" required>
                            <option value="">Pilih Kelas</option>
                        </select>
                        
                        <label for="no_rekening">No Rekening:</label>
                        <input type="text" id="no_rekening" name="no_rekening" readonly required>
                        
                        <button type="submit" name="edit_siswa">
                            <i class="fas fa-save"></i> Simpan Perubahan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Script for AJAX and Interactivity -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Function to update kelas dropdown
            function updateKelasDropdown(jurusanId, targetSelect) {
                // Show loading spinner
                $('.loading').css('display', 'flex');
                
                $.ajax({
                    url: 'get_kelas_by_jurusan.php',
                    type: 'GET',
                    data: { jurusan_id: jurusanId },
                    success: function(response) {
                        targetSelect.html(response);
                        $('.loading').css('display', 'none');
                    },
                    error: function() {
                        showToast('Gagal memuat data kelas', 'error');
                        $('.loading').css('display', 'none');
                    }
                });
            }

            // Event handler for main filter
            $('#jurusan').change(function() {
                updateKelasDropdown($(this).val(), $('#kelas'));
            });

            // Event handler for edit form
            $('#jurusan_edit').change(function() {
                updateKelasDropdown($(this).val(), $('#kelas_edit'));
            });
            
            <?php if (!empty($edit_id)): ?>
            // Auto open edit modal if edit parameter exists
            openEditModal(<?= $edit_id ?>);
            <?php endif; ?>
            
            // Form submission with AJAX
            $('#editForm').submit(function(e) {
                e.preventDefault();
                
                // Show loading spinner
                $('.loading').css('display', 'flex');
                
                $.ajax({
                    url: 'data_siswa.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    success: function(response) {
                        closeEditModal();
                        showToast('Data berhasil disimpan', 'success');
                        
                        // Redirect after a short delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    },
                    error: function() {
                        showToast('Gagal menyimpan data', 'error');
                        $('.loading').css('display', 'none');
                    }
                });
            });
            
            // Pagination loading effect
            $('.page-link').on('click', function(e) {
                e.preventDefault();
                const url = $(this).attr('href');
                
                // Skip loading for ellipsis
                if ($(this).text() === '...') {
                    return;
                }
                
                // Show loading effects
                $('.loading').css('display', 'flex');
                
                // Redirect after a short delay
                setTimeout(function() {
                    window.location.href = url;
                }, 500);
            });
            
            // Filter submission loading
            $('.filters').submit(function() {
                $('.loading').css('display', 'flex');
            });
            
            // Export buttons loading
            $('.btn-pdf, .btn-excel').click(function() {
                showToast('Menyiapkan dokumen...', 'success');
            });
            
            // Add initial animation for page load
            $('body').css('opacity', '0').animate({opacity: 1}, 300);
        });
        
        // Function to open edit modal
        function openEditModal(studentId) {
            // Show loading spinner
            $('.loading').css('display', 'flex');
            
            // Fetch student data with AJAX
            $.ajax({
                url: 'get_student_data.php',
                type: 'GET',
                data: { id: studentId },
                dataType: 'json',
                success: function(data) {
                    // Fill form fields with student data
                    $('#edit_id').val(data.id);
                    $('#nama').val(data.nama);
                    $('#jurusan_edit').val(data.jurusan_id);
                    $('#no_rekening').val(data.no_rekening);
                    
                    // Update kelas dropdown first, then set the selected value
                    $.ajax({
                        url: 'get_kelas_by_jurusan.php',
                        type: 'GET',
                        data: { jurusan_id: data.jurusan_id },
                        success: function(response) {
                            $('#kelas_edit').html(response);
                            $('#kelas_edit').val(data.kelas_id);
                            
                            // Show the modal
                            $('#editModal').fadeIn(300);
                            $('.loading').css('display', 'none');
                        },
                        error: function() {
                            showToast('Gagal memuat data kelas', 'error');
                            $('.loading').css('display', 'none');
                        }
                    });
                },
                error: function() {
                    showToast('Gagal memuat data siswa', 'error');
                    $('.loading').css('display', 'none');
                }
            });
        }
        
        // Function to close edit modal
        function closeEditModal() {
            $('#editModal').fadeOut(300);
            
            // Reset form fields
            setTimeout(function() {
                $('#editForm')[0].reset();
            }, 300);
        }
        
        // Function to show toast notifications
        function showToast(message, type) {
            const toast = $('#toast');
            const icon = toast.find('i');
            
            // Set message
            $('#toast-message').text(message);
            
            // Set type
            toast.removeClass('success error').addClass(type);
            
            // Set icon
            if (type === 'success') {
                icon.removeClass().addClass('fas fa-check-circle');
            } else {
                icon.removeClass().addClass('fas fa-exclamation-circle');
            }
            
            // Show toast
            toast.addClass('show');
            
            // Hide after 3 seconds
            setTimeout(function() {
                toast.removeClass('show');
            }, 3000);
        }
        
        // Close modal when clicking outside of it
        $(window).click(function(event) {
            if ($(event.target).is('.modal-overlay')) {
                closeEditModal();
            }
        });
        
        // Escape key to close modal
        $(document).keydown(function(e) {
            if (e.key === "Escape") {
                closeEditModal();
            }
        });
    </script>
</body>
</html>