<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] == 'siswa') {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';
$tanggal = '';
$petugas1_nama = '';
$petugas2_nama = '';
$id = null;

// Gunakan token CSRF untuk mencegah multiple submission saat refresh
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Handle Edit Request
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $query = "SELECT * FROM petugas_tugas WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $tanggal = $row['tanggal'];
        $petugas1_nama = $row['petugas1_nama'];
        $petugas2_nama = $row['petugas2_nama'];
    }
}

// Handle Delete Request
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $query = "DELETE FROM petugas_tugas WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $success = 'Jadwal berhasil dihapus!';
    } else {
        $error = 'Gagal menghapus jadwal. Silakan coba lagi.';
    }
}

// Handle Export Request with Date Filter
if (isset($_GET['export']) && !empty($_GET['export'])) {
    $export_type = $_GET['export'];
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    // Query untuk mendapatkan data jadwal berdasarkan filter tanggal
    $query = "SELECT * FROM petugas_tugas WHERE 1=1";
    if (!empty($start_date)) {
        $query .= " AND tanggal >= '$start_date'";
    }
    if (!empty($end_date)) {
        $query .= " AND tanggal <= '$end_date'";
    }
    $query .= " ORDER BY tanggal DESC";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        // Set header berdasarkan tipe export
        if ($export_type === 'pdf') {
            require_once '../../tcpdf/tcpdf.php';

            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor('SCHOBANK SYSTEM');
            $pdf->SetTitle('JADWAL PETUGAS');
            $pdf->SetSubject('JADWAL PETUGAS');
            $pdf->SetKeywords('TCPDF, PDF, jadwal, petugas');

            $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);
            $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
            $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
            $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
            $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
            $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
            $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 10, 'JADWAL PETUGAS', 0, 1, 'C');
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->Cell(0, 10, 'SCHOBANK SYSTEM', 0, 1, 'C');
            $pdf->Ln(5);

            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(50, 10, 'Tanggal', 1, 0, 'C');
            $pdf->Cell(70, 10, 'Petugas 1', 1, 0, 'C');
            $pdf->Cell(70, 10, 'Petugas 2', 1, 1, 'C');

            $pdf->SetFont('helvetica', '', 12);
            foreach ($data as $row) {
                $pdf->Cell(50, 10, date('d/m/Y', strtotime($row['tanggal'])), 1, 0, 'L');
                $pdf->Cell(70, 10, $row['petugas1_nama'], 1, 0, 'L');
                $pdf->Cell(70, 10, $row['petugas2_nama'], 1, 1, 'L');
            }

            $pdf->Output('jadwal_petugas.pdf', 'D');
            exit;
        } elseif ($export_type === 'excel') {
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="jadwal_petugas.xls"');
            header('Cache-Control: max-age=0');

            echo '<table border="1">';
            echo '<tr><th colspan="3" style="text-align:center;font-size:16px;font-weight:bold;">JADWAL PETUGAS</th></tr>';
            echo '<tr><th colspan="3" style="text-align:center;font-style:italic;">SCHOBANK SYSTEM</th></tr>';
            echo '<tr><th>Tanggal</th><th>Petugas 1</th><th>Petugas 2</th></tr>';

            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . date('d/m/Y', strtotime($row['tanggal'])) . '</td>';
                echo '<td>' . $row['petugas1_nama'] . '</td>';
                echo '<td>' . $row['petugas2_nama'] . '</td>';
                echo '</tr>';
            }

            echo '</table>';
            exit;
        }
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?error=nodata");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['form_token']) {
        // Token tidak valid atau tidak ada, abaikan submission
    } else {
        $id = $_POST['id'] ?? null;
        $tanggal = $_POST['tanggal'];
        $petugas1_nama = trim($_POST['petugas1_nama']);
        $petugas2_nama = trim($_POST['petugas2_nama']);

        if (empty($tanggal) || empty($petugas1_nama) || empty($petugas2_nama)) {
            $error = 'Semua field harus diisi!';
        } else {
            if ($id) {
                // Update existing record - FIX: Untuk edit, kita tidak perlu cek duplikat tanggal
                // atau tanggal sama dengan database tapi ID yang berbeda
                $query = "UPDATE petugas_tugas SET tanggal = ?, petugas1_nama = ?, petugas2_nama = ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sssi", $tanggal, $petugas1_nama, $petugas2_nama, $id);
                
                if ($stmt->execute()) {
                    $success = 'Jadwal berhasil diperbarui!';
                    $tanggal = '';
                    $petugas1_nama = '';
                    $petugas2_nama = '';
                    $id = null;
                    $_SESSION['form_token'] = bin2hex(random_bytes(32));
                    $token = $_SESSION['form_token'];

                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                    exit;
                } else {
                    $error = 'Terjadi kesalahan saat menyimpan jadwal. Silakan coba lagi.';
                }
            } else {
                // Insert new record - Check for duplicate date
                $query = "SELECT id FROM petugas_tugas WHERE tanggal = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $tanggal);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $error = 'Tanggal sudah ada dalam database!';
                } else {
                    $query = "INSERT INTO petugas_tugas (tanggal, petugas1_nama, petugas2_nama) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("sss", $tanggal, $petugas1_nama, $petugas2_nama);
                    
                    if ($stmt->execute()) {
                        $success = 'Jadwal berhasil dibuat!';
                        $tanggal = '';
                        $petugas1_nama = '';
                        $petugas2_nama = '';
                        $_SESSION['form_token'] = bin2hex(random_bytes(32));
                        $token = $_SESSION['form_token'];

                        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                        exit;
                    } else {
                        $error = 'Terjadi kesalahan saat menyimpan jadwal. Silakan coba lagi.';
                    }
                }
            }
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = 'Jadwal berhasil disimpan!';
}

if (isset($_GET['error']) && $_GET['error'] == 'nodata') {
    $error = 'Tidak ada data jadwal untuk diekspor!';
}

// Get date filters if set
$filter_start = $_GET['filter_start'] ?? '';
$filter_end = $_GET['filter_end'] ?? '';

// Prepare query with filters
$jadwal_query = "SELECT * FROM petugas_tugas WHERE 1=1";
if (!empty($filter_start)) {
    $jadwal_query .= " AND tanggal >= '$filter_start'";
}
if (!empty($filter_end)) {
    $jadwal_query .= " AND tanggal <= '$filter_end'";
}
$jadwal_query .= " ORDER BY tanggal DESC";
$jadwal_result = $conn->query($jadwal_query);

date_default_timezone_set('Asia/Jakarta');

// Pagination Logic
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1; // Halaman aktif
$offset = ($page - 1) * $limit; // Hitung offset

// Query untuk menghitung total data
$total_query = "SELECT COUNT(*) as total FROM petugas_tugas";
$total_result = $conn->query($total_query);
$total_row = $total_result->fetch_assoc();
$total_data = $total_row['total'];
$total_pages = ceil($total_data / $limit); // Hitung total halaman

// Query dengan pagination
$jadwal_query = "SELECT * FROM petugas_tugas WHERE 1=1";
if (!empty($filter_start)) {
    $jadwal_query .= " AND tanggal >= '$filter_start'";
}
if (!empty($filter_end)) {
    $jadwal_query .= " AND tanggal <= '$filter_end'";
}
$jadwal_query .= " ORDER BY tanggal DESC LIMIT $limit OFFSET $offset";
$jadwal_result = $conn->query($jadwal_query);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Jadwal Petugas - SCHOBANK SYSTEM</title>
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

.cancel-btn {
    position: absolute;
    top: 25px;
    right: 25px;
    color: white;
    font-size: 18px;
    z-index: 2;
    text-decoration: none;
    transition: var(--transition);
}

.cancel-btn:hover {
    transform: translateY(-3px);
    color: var(--accent-color);
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
    padding: 8px 12px;  /* Reduced padding */
    border-radius: 6px;  /* Smaller border radius */
    cursor: pointer;
    font-size: 13px;     /* Slightly smaller font */
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;            /* Reduced gap */
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

/* Button styles for form submissions - keep them larger */
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

/* Table action button styles */
.data-table td .btn {
    margin-right: 2px;  /* Very small margin between buttons */
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

/* Make the action column narrower to bring buttons closer */
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

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s;
}

.modal-content {
    background-color: white;
    border-radius: 15px;
    width: 90%;
    max-width: 600px;
    box-shadow: var(--shadow-md);
    animation: slideIn 0.4s;
    padding: 0 0 25px 0;
    margin: 0 auto;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translate(-50%, -60%);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    border-bottom: 1px solid #eee;
}

.modal-header h3 {
    color: var(--primary-dark);
    font-size: 20px;
}

.modal form {
    padding: 0 25px;
}

@media (max-width: 768px) {
    .main-content {
        padding: 10px;
    }

    .form-card {
        padding: 15px;
    }

    /* Add space around form elements on mobile */
    .form-control {
        width: 94%;
        padding: 12px;
    }
    
    /* Add better spacing for the filter container */
    .filter-container {
        padding: 12px;
    }
    
    /* Adjust modal width and padding for mobile */
    .modal-content {
        width: 85%;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal form {
        padding: 0 15px;
    }
    
    /* Ensure form elements have proper spacing */
    form .form-group {
        margin-bottom: 15px;
    }
}

@media (max-width: 480px) {
    .main-content {
        padding: 8px;
    }
    
    .form-card {
        padding: 12px;
    }
    
    .form-control {
        width: 92%;
    }
    
    .modal-content {
        width: 80%;
    }
}

.close {
    color: #999;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    transition: var(--transition);
}

.close:hover {
    color: #333;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 15px;
    }

    .form-card {
        padding: 20px;
    }

    .export-buttons {
        margin-top: 10px;
    }

    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .filter-group {
        flex-direction: column;
        align-items: flex-start;
    }

    .filter-group label {
        margin-bottom: 5px;
    }

    .filter-btn, .reset-btn {
        width: 100%;
    }

    .btn {
        padding: 8px 10px; /* Even smaller for mobile */
    }

    .data-table th, .data-table td {
        padding: 10px;
    }
    
    /* Keep buttons tighter on mobile */
    .data-table td:last-child {
        width: auto;
        display: flex;
        justify-content: center;
        gap: 2px;
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
            <h2><i class="fas fa-calendar-alt"></i> Buat Jadwal Petugas</h2>
            <p>Input jadwal petugas secara manual</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>

        <div class="form-card">
            <div class="card-header">
                <h3>Form Jadwal Petugas</h3>
            </div>
            
            <div id="notifications">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" id="errorAlert">
                        <div class="alert-icon">
                            <i class="fas fa-circle-exclamation"></i>
                        </div>
                        <div class="alert-message">
                            <?php echo $error; ?>
                        </div>
                        <button class="alert-close" onclick="closeAlert('errorAlert')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php elseif (!empty($success)): ?>
                    <div class="alert alert-success" id="successAlert">
                        <div class="alert-icon">
                            <i class="fas fa-circle-check"></i>
                        </div>
                        <div class="alert-message">
                            <?php echo $success; ?>
                        </div>
                        <button class="alert-close" onclick="closeAlert('successAlert')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <form action="" method="POST" id="jadwalForm">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                
                <div class="form-group">
                    <label for="tanggal"><i class="fas fa-calendar"></i> Tanggal:</label>
                    <input type="date" id="tanggal" name="tanggal" class="form-control" required value="<?php echo $tanggal; ?>">
                </div>

                <div class="form-group">
                    <label for="petugas1_nama"><i class="fas fa-user"></i> Nama Petugas 1:</label>
                    <input type="text" id="petugas1_nama" name="petugas1_nama" class="form-control" required placeholder="Masukkan nama petugas pertama" value="<?php echo $petugas1_nama; ?>">
                </div>

                <div class="form-group">
                    <label for="petugas2_nama"><i class="fas fa-user"></i> Nama Petugas 2:</label>
                    <input type="text" id="petugas2_nama" name="petugas2_nama" class="form-control" required placeholder="Masukkan nama petugas kedua" value="<?php echo $petugas2_nama; ?>">
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Simpan Jadwal
                </button>
                
                <?php if ($id): ?>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Batal Edit
                </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="form-card">
            <div class="card-header">
                <h3>Daftar Jadwal Petugas</h3>
                <div class="export-buttons">
                    <a href="?export=pdf<?php echo (!empty($filter_start) ? '&start_date=' . $filter_start : ''); ?><?php echo (!empty($filter_end) ? '&end_date=' . $filter_end : ''); ?>" class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> Ekspor PDF
                    </a>
                    <a href="?export=excel<?php echo (!empty($filter_start) ? '&start_date=' . $filter_start : ''); ?><?php echo (!empty($filter_end) ? '&end_date=' . $filter_end : ''); ?>" class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Ekspor Excel
                    </a>
                </div>
            </div>
            
            <!-- Filter tanggal -->
            <div class="filter-container">
                <form action="" method="GET" id="filterForm">
                    <div class="filter-group">
                        <label for="filter_start"><i class="fas fa-calendar"></i> Dari:</label>
                        <input type="date" id="filter_start" name="filter_start" class="form-control" value="<?php echo $filter_start; ?>">
                        
                        <label for="filter_end"><i class="fas fa-calendar"></i> Sampai:</label>
                        <input type="date" id="filter_end" name="filter_end" class="form-control" value="<?php echo $filter_end; ?>">
                        
                        <button type="submit" class="filter-btn">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="reset-btn">
                            <i class="fas fa-sync"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <?php if ($jadwal_result && $jadwal_result->num_rows > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Petugas 1</th>
                                <th>Petugas 2</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            while ($row = $jadwal_result->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                                    <td><?php echo $row['petugas1_nama']; ?></td>
                                    <td><?php echo $row['petugas2_nama']; ?></td>
                                    <td>
                                        <button class="btn btn-secondary edit-btn" 
                                                data-id="<?php echo $row['id']; ?>"
                                                data-tanggal="<?php echo $row['tanggal']; ?>"
                                                data-petugas1="<?php echo $row['petugas1_nama']; ?>"
                                                data-petugas2="<?php echo $row['petugas2_nama']; ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus jadwal ini?');">
                                            <i class="fas fa-trash"></i> Hapus
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                    <?php if ($total_pages > 1): ?>
                        <ul>
                            <!-- Tombol Previous -->
                            <?php if ($page > 1): ?>
                                <li><a href="?page=<?php echo $page - 1; ?><?php echo !empty($filter_start) ? '&filter_start=' . $filter_start : ''; ?><?php echo !empty($filter_end) ? '&filter_end=' . $filter_end : ''; ?>">&laquo; Previous</a></li>
                            <?php endif; ?>

                            <!-- Nomor Halaman -->
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="<?php echo ($page == $i) ? 'active' : ''; ?>">
                                    <a href="?page=<?php echo $i; ?><?php echo !empty($filter_start) ? '&filter_start=' . $filter_start : ''; ?><?php echo !empty($filter_end) ? '&filter_end=' . $filter_end : ''; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <!-- Tombol Next -->
                            <?php if ($page < $total_pages): ?>
                                <li><a href="?page=<?php echo $page + 1; ?><?php echo !empty($filter_start) ? '&filter_start=' . $filter_start : ''; ?><?php echo !empty($filter_end) ? '&filter_end=' . $filter_end : ''; ?>">Next &raquo;</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-message">
                        <i class="fas fa-info-circle"></i> Belum ada jadwal yang dibuat.
                    </div>
                <?php endif; ?>
            </div>
        </div> <!-- Ditambahkan tag penutup untuk div form-card Daftar Jadwal Petugas -->
    
        <!-- Modal Edit Jadwal (dipindahkan ke luar dari div form-card) -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Edit Jadwal Petugas</h3>
                    <span class="close">&times;</span>
                </div>
                <form action="" method="POST" id="editForm">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="form-group">
                        <label for="edit_tanggal"><i class="fas fa-calendar"></i> Tanggal:</label>
                        <input type="date" id="edit_tanggal" name="tanggal" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_petugas1_nama"><i class="fas fa-user"></i> Nama Petugas 1:</label>
                        <input type="text" id="edit_petugas1_nama" name="petugas1_nama" class="form-control" required placeholder="Masukkan nama petugas pertama">
                    </div>

                    <div class="form-group">
                        <label for="edit_petugas2_nama"><i class="fas fa-user"></i> Nama Petugas 2:</label>
                        <input type="text" id="edit_petugas2_nama" name="petugas2_nama" class="form-control" required placeholder="Masukkan nama petugas kedua">
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </form>
            </div>
        </div>
    </div> <!-- Penutup untuk div main-content -->
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Original alert handling code
        function closeAlert(id) {
            const alert = document.getElementById(id);
            if (alert) {
                alert.classList.add('fade-out');
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 500);
            }
        }

        const alerts = document.querySelectorAll('.alert');
        
        if (alerts.length > 0) {
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('fade-out');
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            });
        }
        
        // Modal functionality
        const modal = document.getElementById("editModal");
        const closeBtn = document.querySelector(".close");
        
        // Close modal when clicking X
        if (closeBtn) {
            closeBtn.onclick = function() {
                modal.style.display = "none";
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        // Initialize edit buttons functionality
        initEditButtons();
        
        // Initialize AJAX pagination
        initAjaxPagination();
        
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        window.addEventListener('beforeunload', function() {
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });

        // Function to handle edit buttons
        function initEditButtons() {
            const editButtons = document.querySelectorAll('.edit-btn');
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const tanggal = this.getAttribute('data-tanggal');
                    const petugas1 = this.getAttribute('data-petugas1');
                    const petugas2 = this.getAttribute('data-petugas2');
                    
                    document.getElementById('edit_id').value = id;
                    document.getElementById('edit_tanggal').value = tanggal;
                    document.getElementById('edit_petugas1_nama').value = petugas1;
                    document.getElementById('edit_petugas2_nama').value = petugas2;
                    
                    modal.style.display = "block";
                });
            });
        }

        // Function to initialize AJAX pagination
        function initAjaxPagination() {
            const paginationLinks = document.querySelectorAll('.pagination a');
            
            paginationLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const url = this.getAttribute('href');
                    const tableContainer = document.querySelector('.table-container');
                    
                    // Store current scroll position
                    const scrollPosition = window.scrollY;
                    
                    // Show loading indicator
                    tableContainer.innerHTML = '<div class="empty-message"><i class="fas fa-spinner fa-spin"></i> Loading data...</div>';
                    
                    // Update URL in browser without refreshing
                    window.history.pushState({path: url}, '', url);
                    
                    // Fetch the data with AJAX
                    fetch(url)
                        .then(response => response.text())
                        .then(html => {
                            // Create a temporary element to parse the HTML
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            
                            // Extract the table container content
                            const newTableContainer = doc.querySelector('.table-container');
                            
                            if (newTableContainer) {
                                tableContainer.innerHTML = newTableContainer.innerHTML;
                                
                                // Reinitialize edit buttons for the new content
                                initEditButtons();
                                
                                // Reinitialize pagination for the new content
                                initAjaxPagination();
                                
                                // Restore scroll position
                                window.scrollTo(0, scrollPosition);
                            } else {
                                tableContainer.innerHTML = '<div class="empty-message"><i class="fas fa-exclamation-circle"></i> Error loading data.</div>';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            tableContainer.innerHTML = '<div class="empty-message"><i class="fas fa-exclamation-circle"></i> Error loading data.</div>';
                        });
                });
            });
        }
        
        // Expose functions to window for use in other scripts if needed
        window.closeAlert = closeAlert;
        window.initEditButtons = initEditButtons;
        window.initAjaxPagination = initAjaxPagination;
    });
</script>
</body>
</html>