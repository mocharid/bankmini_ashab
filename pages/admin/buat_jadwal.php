<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to non-siswa users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: login.php");
    exit;
}

$error_message = '';
$success = '';
$tanggal = '';
$petugas1_nama = '';
$petugas2_nama = '';
$id = null;
$show_error_modal = false; // Flag to show error modal

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Handle Edit Request
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
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
    } else {
        $error_message = "Jadwal tidak ditemukan!";
        $show_error_modal = true;
    }
}

// Handle Delete Request
if (isset($_POST['delete_id']) && $_POST['token'] === $_SESSION['form_token']) {
    $id = (int)$_POST['delete_id'];
    $conn->begin_transaction();
    try {
        $query_absensi = "DELETE FROM absensi WHERE tanggal = (SELECT tanggal FROM petugas_tugas WHERE id = ?)";
        $stmt_absensi = $conn->prepare($query_absensi);
        $stmt_absensi->bind_param("i", $id);
        $stmt_absensi->execute();

        $query_petugas = "DELETE FROM petugas_tugas WHERE id = ?";
        $stmt_petugas = $conn->prepare($query_petugas);
        $stmt_petugas->bind_param("i", $id);
        $stmt_petugas->execute();

        $conn->commit();
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        header('Location: buat_jadwal.php?delete_success=1');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = 'Gagal menghapus jadwal: ' . $e->getMessage();
        $show_error_modal = true;
    }
}

// Handle Edit Submission
if (isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['form_token']) {
    $id = (int)$_POST['edit_id'];
    $tanggal = $_POST['edit_tanggal'];
    $petugas1_nama = trim($_POST['edit_petugas1_nama']);
    $petugas2_nama = trim($_POST['edit_petugas2_nama']);

    if (empty($tanggal) || empty($petugas1_nama) || empty($petugas2_nama)) {
        $error_message = "Semua field harus diisi!";
        $show_error_modal = true;
    } elseif (strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
        $error_message = "Nama petugas harus minimal 3 karakter!";
        $show_error_modal = true;
    } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
        $error_message = "Tanggal tidak boleh di masa lalu!";
        $show_error_modal = true;
    } else {
        $check_query = "SELECT id FROM petugas_tugas WHERE tanggal = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $tanggal, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            $error_message = "Tanggal ini sudah digunakan untuk jadwal lain!";
            $show_error_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                $query_original = "SELECT tanggal FROM petugas_tugas WHERE id = ?";
                $stmt_original = $conn->prepare($query_original);
                $stmt_original->bind_param("i", $id);
                $stmt_original->execute();
                $original_date = $stmt_original->get_result()->fetch_assoc()['tanggal'];

                $query = "UPDATE petugas_tugas SET tanggal = ?, petugas1_nama = ?, petugas2_nama = ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sssi", $tanggal, $petugas1_nama, $petugas2_nama, $id);
                $stmt->execute();

                if ($original_date !== $tanggal) {
                    $query_absensi = "UPDATE absensi SET tanggal = ? WHERE tanggal = ?";
                    $stmt_absensi = $conn->prepare($query_absensi);
                    $stmt_absensi->bind_param("ss", $tanggal, $original_date);
                    $stmt_absensi->execute();
                }

                $conn->commit();
                $_SESSION['form_token'] = bin2hex(random_bytes(32));
                header('Location: buat_jadwal.php?edit_success=1');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = 'Gagal mengupdate jadwal: ' . $e->getMessage();
                $show_error_modal = true;
            }
        }
    }
    // Preserve form inputs on error
    if ($show_error_modal) {
        $tanggal = $_POST['edit_tanggal'];
        $petugas1_nama = $_POST['edit_petugas1_nama'];
        $petugas2_nama = $_POST['edit_petugas2_nama'];
    }
}

// Handle Export Request
if (isset($_GET['export']) && !empty($_GET['export'])) {
    $export_type = $_GET['export'];

    $query = "SELECT * FROM petugas_tugas ORDER BY tanggal DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        if ($export_type === 'pdf') {
            require_once '../../tcpdf/tcpdf.php';

            class MYPDF extends TCPDF {
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
                    $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/y H:i'), 0, 0, 'L');
                    $this->Cell(90, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
                }
            }

            $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor('SCHOBANK');
            $pdf->SetTitle('Jadwal Petugas');
            $pdf->SetMargins(15, 40, 15);
            $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
            $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
            $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
            $pdf->AddPage();
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 7, 'JADWAL PETUGAS', 0, 1, 'C');

            $filter_text = "Tanggal: " . date('d/m/y');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 7, $filter_text, 0, 1, 'C');
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(190, 7, 'DAFTAR JADWAL', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(10, 6, 'No', 1, 0, 'C');
            $pdf->Cell(30, 6, 'Tanggal', 1, 0, 'C');
            $pdf->Cell(75, 6, 'Petugas 1', 1, 0, 'C');
            $pdf->Cell(75, 6, 'Petugas 2', 1, 1, 'C');
            $pdf->SetFont('helvetica', '', 8);
            $no = 1;
            foreach ($data as $row) {
                $pdf->Cell(10, 6, $no++, 1, 0, 'C');
                $pdf->Cell(30, 6, date('d/m/y', strtotime($row['tanggal'])), 1, 0, 'C');
                $pdf->Cell(75, 6, $row['petugas1_nama'], 1, 0, 'L');
                $pdf->Cell(75, 6, $row['petugas2_nama'], 1, 1, 'L');
            }
            $pdf->Output('jadwal_petugas.pdf', 'D');
            exit;
        } elseif ($export_type === 'excel') {
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="jadwal_petugas.xls"');
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
            echo '<tr><td colspan="4" class="title">JADWAL PETUGAS</td></tr>';
            $filter_text = "Tanggal: " . date('d/m/y');
            echo '<tr><td colspan="4" class="subtitle">' . htmlspecialchars($filter_text) . '</td></tr>';
            echo '</table>';
            echo '<table border="1" cellpadding="3">';
            echo '<tr><th colspan="4" class="section-header">DAFTAR JADWAL</th></tr>';
            echo '<tr>';
            echo '<th>No</th>';
            echo '<th>Tanggal</th>';
            echo '<th>Petugas 1</th>';
            echo '<th>Petugas 2</th>';
            echo '</tr>';
            $no = 1;
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td class="text-center">' . $no++ . '</td>';
                echo '<td class="text-center">' . date('d/m/y', strtotime($row['tanggal'])) . '</td>';
                echo '<td class="text-left">' . htmlspecialchars($row['petugas1_nama']) . '</td>';
                echo '<td class="text-left">' . htmlspecialchars($row['petugas2_nama']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</body></html>';
            exit;
        }
    } else {
        $error_message = 'Tidak ada data untuk diekspor!';
        $show_error_modal = true;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['form_token']) {
    if (isset($_POST['confirm'])) {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $tanggal = $_POST['tanggal'];
        $petugas1_nama = trim($_POST['petugas1_nama']);
        $petugas2_nama = trim($_POST['petugas2_nama']);

        if (empty($tanggal) || empty($petugas1_nama) || empty($petugas2_nama)) {
            $error_message = "Semua field harus diisi!";
            $show_error_modal = true;
        } elseif (strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
            $error_message = "Nama petugas harus minimal 3 karakter!";
            $show_error_modal = true;
        } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
            $error_message = "Tanggal tidak boleh di masa lalu!";
            $show_error_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                if ($id) {
                    $error_message = "Operasi tidak valid!";
                    $show_error_modal = true;
                } else {
                    $query_check = "SELECT id FROM petugas_tugas WHERE tanggal = ?";
                    $stmt_check = $conn->prepare($query_check);
                    $stmt_check->bind_param("s", $tanggal);
                    $stmt_check->execute();
                    $result_check = $stmt_check->get_result();

                    if ($result_check->num_rows > 0) {
                        $error_message = "Tanggal ini sudah digunakan untuk jadwal lain!";
                        $show_error_modal = true;
                        throw new Exception($error_message);
                    }

                    $query = "INSERT INTO petugas_tugas (tanggal, petugas1_nama, petugas2_nama) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("sss", $tanggal, $petugas1_nama, $petugas2_nama);
                    $stmt->execute();

                    $conn->commit();
                    $_SESSION['form_token'] = bin2hex(random_bytes(32));
                    header('Location: buat_jadwal.php?success=1');
                    exit;
                }
            } catch (Exception $e) {
                $conn->rollback();
                if (!$show_error_modal) {
                    $error_message = $e->getMessage();
                    $show_error_modal = true;
                }
            }
        }
        // Preserve form inputs on error
        if ($show_error_modal) {
            $tanggal = $_POST['tanggal'];
            $petugas1_nama = $_POST['petugas1_nama'];
            $petugas2_nama = $_POST['petugas2_nama'];
        }
    } else {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $tanggal = $_POST['tanggal'];
        $petugas1_nama = trim($_POST['petugas1_nama']);
        $petugas2_nama = trim($_POST['petugas2_nama']);

        if (empty($tanggal) || empty($petugas1_nama) || empty($petugas2_nama)) {
            $error_message = 'Semua field harus diisi!';
            $show_error_modal = true;
        } elseif (strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
            $error_message = "Nama petugas harus minimal 3 karakter!";
            $show_error_modal = true;
        } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
            $error_message = "Tanggal tidak boleh di masa lalu!";
            $show_error_modal = true;
        } else {
            if (!$show_error_modal) {
                header('Location: buat_jadwal.php?confirm=1&tanggal=' . urlencode($tanggal) . '&petugas1_nama=' . urlencode($petugas1_nama) . '&petugas2_nama=' . urlencode($petugas2_nama));
                exit;
            }
        }
        // Preserve form inputs on error
        if ($show_error_modal) {
            $tanggal = $_POST['tanggal'];
            $petugas1_nama = $_POST['petugas1_nama'];
            $petugas2_nama = $_POST['petugas2_nama'];
        }
    }
}

// Fetch Data
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_query = "SELECT COUNT(*) as total FROM petugas_tugas";
$total_stmt = $conn->prepare($total_query);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_row = $total_result->fetch_assoc();
$total_data = $total_row['total'];
$total_pages = ceil($total_data / $limit);

$jadwal_query = "SELECT * FROM petugas_tugas ORDER BY tanggal DESC LIMIT ? OFFSET ?";
$jadwal_stmt = $conn->prepare($jadwal_query);
$jadwal_stmt->bind_param("ii", $limit, $offset);
$jadwal_stmt->execute();
$jadwal_result = $jadwal_stmt->get_result();

$jadwal = [];
if ($jadwal_result && $jadwal_result->num_rows > 0) {
    while ($row = $jadwal_result->fetch_assoc()) {
        $jadwal[] = $row;
    }
}

date_default_timezone_set('Asia/Jakarta');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Buat Jadwal Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
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
            max-width: 1000px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
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

        .form-card {
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

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="text"],
        input[type="date"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #fff;
        }

        input[type="date"] {
            position: relative;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            display: block;
            width: 20px;
            height: 20px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23666"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>') no-repeat center;
            background-size: 20px;
            cursor: pointer;
            opacity: 0.7;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }

        input[type="date"]::-webkit-datetime-edit {
            padding-right: 30px;
        }

        input[type="text"]:focus,
        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            width: fit-content;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-cancel {
            background: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .jadwal-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .jadwal-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .jadwal-list h3 {
            color: var(--primary-dark);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        td {
            background: white;
        }

        tr:hover {
            background-color: #f8faff;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .pagination a {
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 10px;
            color: var(--text-primary);
            background-color: #f0f0f0;
            transition: var(--transition);
        }

        .pagination a:hover:not(.disabled) {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination a.disabled {
            color: var(--text-secondary);
            background-color: #e0e0e0;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination .current-page {
            padding: 10px 15px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            font-weight: 500;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 15px;
            width: clamp(350px, 85vw, 450px);
            box-shadow: var(--shadow-md);
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(350px, 85vw, 450px);
            border-radius: 15px;
            padding: clamp(30px, 5vw, 40px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(3.8rem, 8vw, 4.8rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 20px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.95rem, 2.3vw, 1.05rem);
            margin: 0 0 25px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-title {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 20px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: #fff; 
        }

        .modal-value {
            font-weight: 600;
            color: #fff; 
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(2rem, 4vw, 2.2rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .no-data p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-card, .jadwal-list {
                padding: 20px;
            }

            form {
                gap: 15px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .jadwal-list-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }

            .modal-content {
                width: clamp(330px, 90vw, 440px);
                padding: 20px;
            }

            .success-modal, .error-modal {
                width: clamp(330px, 90vw, 440px);
                padding: clamp(25px, 5vw, 35px);
                margin: 15px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.5rem, 7vw, 4.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.3rem, 2.8vw, 1.5rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }

            .pagination {
                gap: 8px;
            }

            .pagination a, .pagination .current-page {
                padding: 8px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .jadwal-list h3 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .no-data i {
                font-size: clamp(1.8rem, 3.5vw, 2rem);
            }

            input[type="text"],
            input[type="date"] {
                min-height: 40px;
            }

            .success-modal, .error-modal {
                width: clamp(310px, 92vw, 380px);
                padding: clamp(20px, 4vw, 30px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.2rem, 6.5vw, 4rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.2rem, 2.7vw, 1.4rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.85rem, 2.1vw, 0.95rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2> Buat Jadwal Petugas</h2>
            <p>Kelola Jadwal Petugas di sini</p>
        </div>

        <div id="alertContainer"></div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="jadwal-form">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <div class="form-group">
                    <label for="tanggal">Tanggal</label>
                    <input type="date" id="tanggal" name="tanggal" value="<?php echo htmlspecialchars($tanggal ?: date('Y-m-d')); ?>" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label for="petugas1_nama">Nama Petugas 1</label>
                    <input type="text" id="petugas1_nama" name="petugas1_nama" value="<?php echo htmlspecialchars($petugas1_nama); ?>" placeholder="Masukkan nama petugas pertama" required>
                </div>
                <div class="form-group">
                    <label for="petugas2_nama">Nama Petugas 2</label>
                    <input type="text" id="petugas2_nama" name="petugas2_nama" value="<?php echo htmlspecialchars($petugas2_nama); ?>" placeholder="Masukkan nama petugas kedua" required>
                </div>
                <div style="display: flex; gap: 15px;">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content"><i class="fas fa-plus-circle"></i> <?php echo $id ? 'Update Jadwal' : 'Tambah Jadwal'; ?></span>
                    </button>
                    <?php if ($id): ?>
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='buat_jadwal.php'">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Jadwal List -->
        <div class="jadwal-list">
            <div class="jadwal-list-header">
                <h3><i class="fas fa-list"></i> Daftar Jadwal</h3>
                <button id="printButton" class="btn">
                    <span class="btn-content"><i class="fas fa-print"></i> Cetak</span>
                </button>
            </div>
            <?php if (!empty($jadwal)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Petugas 1</th>
                            <th>Petugas 2</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="jadwal-table">
                        <?php 
                        $no = ($page - 1) * $limit + 1;
                        foreach ($jadwal as $row): 
                        ?>
                            <tr id="row-<?php echo $row['id']; ?>">
                                <td><?php echo $no++; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                                <td><?php echo htmlspecialchars($row['petugas1_nama']); ?></td>
                                <td><?php echo htmlspecialchars($row['petugas2_nama']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn" 
                                                onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['tanggal'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['petugas1_nama'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['petugas2_nama'], ENT_QUOTES); ?>')">
                                            <span class="btn-content"><i class="fas fa-edit"></i> Edit</span>
                                        </button>
                                        <button class="btn" 
                                                onclick="deleteJadwal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(date('d/m/Y', strtotime($row['tanggal'])), ENT_QUOTES); ?>')">
                                            <span class="btn-content"><i class="fas fa-trash"></i> Hapus</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="pagination-link">« Previous</a>
                    <?php else: ?>
                        <a class="pagination-link disabled">« Previous</a>
                    <?php endif; ?>
                    <span class="current-page"><?php echo $page; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="pagination-link">Next »</a>
                    <?php else: ?>
                        <a class="pagination-link disabled">Next »</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>Belum ada jadwal yang dibuat</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Hidden input to pass jadwal count to JavaScript -->
        <input type="hidden" id="jadwalCount" value="<?php echo count($jadwal); ?>">

        <!-- Confirmation Modal -->
        <?php if (isset($_GET['confirm']) && isset($_GET['tanggal']) && isset($_GET['petugas1_nama']) && isset($_GET['petugas2_nama']) && !$show_error_modal): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>Konfirmasi Jadwal Baru</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-label">Tanggal</span>
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['tanggal'])); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Petugas 1</span>
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['petugas1_nama'])); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Petugas 2</span>
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['petugas2_nama'])); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="token" value="<?php echo $token; ?>">
                        <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars(urldecode($_GET['tanggal'])); ?>">
                        <input type="hidden" name="petugas1_nama" value="<?php echo htmlspecialchars(urldecode($_GET['petugas1_nama'])); ?>">
                        <input type="hidden" name="petugas2_nama" value="<?php echo htmlspecialchars(urldecode($_GET['petugas2_nama'])); ?>">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn" id="confirm-btn">
                                <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="hideConfirmModal()">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Jadwal berhasil ditambahkan!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Edit Success Modal -->
        <?php if (isset($_GET['edit_success'])): ?>
            <div class="success-overlay" id="editSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Jadwal berhasil diupdate!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Delete Success Modal -->
        <?php if (isset($_GET['delete_success'])): ?>
            <div class="success-overlay" id="deleteSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Jadwal berhasil dihapus!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Modal -->
        <?php if ($show_error_modal): ?>
            <div class="success-overlay" id="errorModal">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideErrorModal()">
                            <span class="btn-content"><i class="fas fa-times"></i> OK</span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-title">
                    <i class="fas fa-edit"></i>
                    Edit Jadwal
                </div>
                <form id="editForm" method="POST" action="" onsubmit="return showEditConfirmation()">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <div class="form-group">
                        <label for="edit_tanggal">Tanggal</label>
                        <input type="date" id="edit_tanggal" name="edit_tanggal" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit_petugas1_nama">Nama Petugas 1</label>
                        <input type="text" id="edit_petugas1_nama" name="edit_petugas1_nama" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_petugas2_nama">Nama Petugas 2</label>
                        <input type="text" id="edit_petugas2_nama" name="edit_petugas2_nama" required>
                    </div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideEditModal()">
                            <span class="btn-content">Batal</span>
                        </button>
                        <button type="submit" class="btn" id="edit-submit-btn">
                            <span class="btn-content"><i class="fas fa-save"></i> Simpan</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Confirmation Modal -->
        <div class="success-overlay" id="editConfirmModal" style="display: none;">
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3>Konfirmasi Edit Jadwal</h3>
                <div class="modal-content-confirm">
                    <div class="modal-row">
                        <span class="modal-label">Tanggal</span>
                        <span class="modal-value" id="editConfirmTanggal"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Petugas 1</span>
                        <span class="modal-value" id="editConfirmPetugas1"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Petugas 2</span>
                        <span class="modal-value" id="editConfirmPetugas2"></span>
                    </div>
                </div>
                <form action="" method="POST" id="editConfirmForm">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <input type="hidden" name="edit_id" id="editConfirmId">
                    <input type="hidden" name="edit_tanggal" id="editConfirmTanggalInput">
                    <input type="hidden" name="edit_petugas1_nama" id="editConfirmPetugas1Input">
                    <input type="hidden" name="edit_petugas2_nama" id="editConfirmPetugas2Input">
                    <input type="hidden" name="edit_confirm" value="1">
                    <div class="modal-buttons">
                        <button type="submit" class="btn" id="edit-confirm-btn">
                            <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="hideEditConfirmModal()">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <div class="modal-title">
                    <i class="fas fa-trash"></i>
                    Hapus Jadwal
                </div>
                <p>Apakah Anda yakin ingin menghapus jadwal pada tanggal <strong id="delete_tanggal"></strong>?</p>
                <form id="deleteForm" method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <input type="hidden" name="delete_id" id="delete_id">
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideDeleteModal()">
                            <span class="btn-content">Batal</span>
                        </button>
                        <button type="submit" class="btn" id="delete-submit-btn">
                            <span class="btn-content"><i class="fas fa-trash"></i> Hapus</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Format tanggal ke dd/mm/yyyy
        function formatDate(date) {
            const d = new Date(date);
            return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
        }

        // Show modal alert
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const existingAlerts = alertContainer.querySelectorAll('.success-overlay');
            existingAlerts.forEach(alert => {
                alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => alert.remove(), 500);
            });
            const alertDiv = document.createElement('div');
            alertDiv.className = `success-overlay`;
            alertDiv.innerHTML = `
                <div class="${type}-modal">
                    <div class="${type}-icon">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    </div>
                    <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                    <p>${message}</p>
                </div>
            `;
            alertContainer.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => alertDiv.remove(), 500);
            }, 5000);
            alertDiv.addEventListener('click', () => {
                alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => alertDiv.remove(), 500);
            });
        }

        // Add loading state to button
        function setButtonLoading(button, text, icon) {
            button.classList.add('loading');
            button.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';
        }

        // Remove loading state from button
        function resetButton(button, text, icon) {
            button.classList.remove('loading');
            button.innerHTML = `<span class="btn-content"><i class="fas ${icon}"></i> ${text}</span>`;
        }

        // Close modal and clean up URL
        function closeModal(modal, modalId) {
            modal.style.display = 'none';
            if (['successModal', 'editSuccessModal', 'deleteSuccessModal', 'confirmModal', 'errorModal'].includes(modalId)) {
                const url = new URL(window.location);
                url.searchParams.delete('success');
                url.searchParams.delete('edit_success');
                url.searchParams.delete('delete_success');
                url.searchParams.delete('confirm');
                url.searchParams.delete('tanggal');
                url.searchParams.delete('petugas1_nama');
                url.searchParams.delete('petugas2_nama');
                url.searchParams.delete('error');
                history.replaceState(null, '', url.toString());
            }
        }

        // Edit Modal Functions
        let isModalOpening = false;

        function showEditModal(id, tanggal, petugas1_nama, petugas2_nama) {
            if (isModalOpening) return;
            isModalOpening = true;

            const modal = document.getElementById('editModal');
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_tanggal').value = tanggal;
            document.getElementById('edit_petugas1_nama').value = petugas1_nama;
            document.getElementById('edit_petugas2_nama').value = petugas2_nama;

            modal.style.display = 'flex';
            setTimeout(() => {
                isModalOpening = false;
            }, 600);
        }

        function hideEditModal() {
            const modal = document.getElementById('editModal');
            closeModal(modal, 'editModal');
            isModalOpening = false;
        }

        function showEditConfirmation() {
            if (isModalOpening) return false;
            isModalOpening = true;

            const tanggal = document.getElementById('edit_tanggal').value;
            const petugas1_nama = document.getElementById('edit_petugas1_nama').value.trim();
            const petugas2_nama = document.getElementById('edit_petugas2_nama').value.trim();
            const today = new Date().toISOString().split('T')[0];

            if (!tanggal || petugas1_nama.length < 3 || petugas2_nama.length < 3) {
                showAlert('Semua field harus diisi dan nama petugas minimal 3 karakter!', 'error');
                isModalOpening = false;
                return false;
            }

            if (new Date(tanggal) < new Date(today)) {
                showAlert('Tanggal tidak boleh di masa lalu!', 'error');
                isModalOpening = false;
                return false;
            }

            const modal = document.getElementById('editConfirmModal');
            document.getElementById('editConfirmId').value = document.getElementById('edit_id').value;
            document.getElementById('editConfirmTanggal').textContent = formatDate(tanggal);
            document.getElementById('editConfirmTanggalInput').value = tanggal;
            document.getElementById('editConfirmPetugas1').textContent = petugas1_nama;
            document.getElementById('editConfirmPetugas1Input').value = petugas1_nama;
            document.getElementById('editConfirmPetugas2').textContent = petugas2_nama;
            document.getElementById('editConfirmPetugas2Input').value = petugas2_nama;

            modal.style.display = 'flex';
            hideEditModal();

            setTimeout(() => {
                isModalOpening = false;
            }, 600);
            return false;
        }

        function hideEditConfirmModal() {
            const modal = document.getElementById('editConfirmModal');
            closeModal(modal, 'editConfirmModal');
            isModalOpening = false;
        }

        // Delete Modal Functions
        function deleteJadwal(id, tanggal) {
            if (isModalOpening) return;
            isModalOpening = true;

            const modal = document.getElementById('deleteModal');
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_tanggal').textContent = tanggal;

            modal.style.display = 'flex';

            const deleteForm = document.getElementById('deleteForm');
            deleteForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const row = document.getElementById(`row-${id}`);
                if (row) {
                    setButtonLoading(document.getElementById('delete-submit-btn'), 'Hapus', 'fa-trash');
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 1000);
                } else {
                    deleteForm.submit();
                }
            }, { once: true });

            setTimeout(() => {
                isModalOpening = false;
            }, 600);
        }

        function hideDeleteModal() {
            const modal = document.getElementById('deleteModal');
            closeModal(modal, 'deleteModal');
            isModalOpening = false;
        }

        // Confirmation Modal Functions
        function hideConfirmModal() {
            const modal = document.getElementById('confirmModal');
            if (modal) {
                closeModal(modal, 'confirmModal');
            }
            isModalOpening = false;
        }

        // Error Modal Functions
        function hideErrorModal() {
            const modal = document.getElementById('errorModal');
            if (modal) {
                closeModal(modal, 'errorModal');
                hideConfirmModal();
                hideEditConfirmModal();
            }
            isModalOpening = false;
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', () => {
            // Prevent zooming and double-tap issues
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            // Print Button
            const printButton = document.getElementById('printButton');
            const jadwalCount = parseInt(document.getElementById('jadwalCount').value);
            if (printButton) {
                printButton.addEventListener('click', function() {
                    if (printButton.classList.contains('loading')) return;

                    if (jadwalCount === 0) {
                        showAlert('Tidak ada jadwal untuk dicetak!', 'error');
                        return;
                    }

                    setButtonLoading(printButton, 'Cetak', 'fa-print');
                    const exportUrl = `${window.location.pathname}?export=pdf`;
                    console.log('Attempting to open print URL:', exportUrl);
                    const pdfWindow = window.open(exportUrl, '_blank');
                    if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed === 'undefined') {
                        showAlert('Gagal membuka PDF. Pastikan popup tidak diblokir oleh browser.', 'error');
                    }

                    setTimeout(() => {
                        resetButton(printButton, 'Cetak', 'fa-print');
                    }, 1000);
                });
            }

            // Auto-dismiss success modals after 5 seconds
            const successModals = document.querySelectorAll('.success-overlay');
            successModals.forEach(modal => {
                if (modal.id !== 'confirmModal' && modal.id !== 'editConfirmModal' && modal.id !== 'errorModal') {
                    setTimeout(() => {
                        closeModal(modal, modal.id);
                    }, 5000);
                }
            });

            // Handle form submission buttons
            const submitBtn = document.getElementById('submit-btn');
            if (submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    if (submitBtn.classList.contains('loading')) return;
                    setButtonLoading(submitBtn, submitBtn.textContent.trim(), 'fa-plus-circle');
                    setTimeout(() => {
                        resetButton(submitBtn, submitBtn.textContent.trim(), 'fa-plus-circle');
                    }, 1000);
                });
            }

            const confirmBtn = document.getElementById('confirm-btn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function(e) {
                    if (confirmBtn.classList.contains('loading')) return;
                    setButtonLoading(confirmBtn, 'Konfirmasi', 'fa-check');
                    setTimeout(() => {
                        resetButton(confirmBtn, 'Konfirmasi', 'fa-check');
                        document.getElementById('confirm-form').submit();
                    }, 1000);
                });
            }

            const editSubmitBtn = document.getElementById('edit-submit-btn');
            if (editSubmitBtn) {
                editSubmitBtn.addEventListener('click', function(e) {
                    if (editSubmitBtn.classList.contains('loading')) return;
                    setButtonLoading(editSubmitBtn, 'Simpan', 'fa-save');
                    setTimeout(() => {
                        resetButton(editSubmitBtn, 'Simpan', 'fa-save');
                    }, 1000);
                });
            }

            const editConfirmBtn = document.getElementById('edit-confirm-btn');
            if (editConfirmBtn) {
                editConfirmBtn.addEventListener('click', function(e) {
                    if (editConfirmBtn.classList.contains('loading')) return;
                    setButtonLoading(editConfirmBtn, 'Konfirmasi', 'fa-check');
                    setTimeout(() => {
                        resetButton(editConfirmBtn, 'Konfirmasi', 'fa-check');
                        document.getElementById('editConfirmForm').submit();
                    }, 1000);
                });
            }

            const deleteSubmitBtn = document.getElementById('delete-submit-btn');
            if (deleteSubmitBtn) {
                deleteSubmitBtn.addEventListener('click', function(e) {
                    if (deleteSubmitBtn.classList.contains('loading')) return;
                    setButtonLoading(deleteSubmitBtn, 'Hapus', 'fa-trash');
                    setTimeout(() => {
                        resetButton(deleteSubmitBtn, 'Hapus', 'fa-trash');
                    }, 1000);
                });
            }

            // Handle cancel buttons
            document.querySelectorAll('.btn-cancel').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (btn.classList.contains('loading')) return;
                    setButtonLoading(btn, btn.textContent.trim(), 'fa-times');
                    setTimeout(() => {
                        resetButton(btn, btn.textContent.trim(), 'fa-times');
                    }, 1000);
                });
            });

            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            // Handle click outside to close modals
            document.querySelectorAll('.modal, .success-overlay').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        if (modal.id === 'editModal') hideEditModal();
                        else if (modal.id === 'deleteModal') hideDeleteModal();
                        else if (modal.id === 'editConfirmModal') hideEditConfirmModal();
                        else if (modal.id === 'confirmModal') hideConfirmModal();
                        else if (modal.id === 'errorModal') hideErrorModal();
                        else closeModal(modal, modal.id);
                    }
                });
            });

            // Handle keyboard events for accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideEditModal();
                    hideDeleteModal();
                    hideEditConfirmModal();
                    hideConfirmModal();
                    hideErrorModal();
                    document.querySelectorAll('.success-overlay').forEach(modal => {
                        if (modal.id !== 'confirmModal' && modal.id !== 'editConfirmModal' && modal.id !== 'errorModal') {
                            closeModal(modal, modal.id);
                        }
                    });
                }
            });

            // Ensure date input consistency in Safari
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.style.webkitAppearance = 'none';
                    this.style.appearance = 'none';
                });
                input.addEventListener('blur', function() {
                    this.style.webkitAppearance = 'none';
                    this.style.appearance = 'none';
                });
            });

            // Validate form inputs
            const jadwalForm = document.getElementById('jadwal-form');
            if (jadwalForm) {
                jadwalForm.addEventListener('submit', function(e) {
                    const tanggal = document.getElementById('tanggal').value;
                    const petugas1 = document.getElementById('petugas1_nama').value.trim();
                    const petugas2 = document.getElementById('petugas2_nama').value.trim();
                    const today = new Date().toISOString().split('T')[0];

                    if (!tanggal || petugas1.length < 3 || petugas2.length < 3) {
                        e.preventDefault();
                        showAlert('Semua field harus diisi dan nama petugas minimal 3 karakter!', 'error');
                        return;
                    }

                    if (new Date(tanggal) < new Date(today)) {
                        e.preventDefault();
                        showAlert('Tanggal tidak boleh di masa lalu!', 'error');
                    }
                });
            }

            // Validate edit form
            const editForm = document.getElementById('editForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const tanggal = document.getElementById('edit_tanggal').value;
                    const petugas1 = document.getElementById('edit_petugas1_nama').value.trim();
                    const petugas2 = document.getElementById('edit_petugas2_nama').value.trim();
                    const today = new Date().toISOString().split('T')[0];

                    if (!tanggal || petugas1.length < 3 || petugas2.length < 3) {
                        e.preventDefault();
                        showAlert('Semua field harus diisi dan nama petugas minimal 3 karakter!', 'error');
                        return;
                    }

                    if (new Date(tanggal) < new Date(today)) {
                        e.preventDefault();
                        showAlert('Tanggal tidak boleh di masa lalu!', 'error');
                    }
                });
            }

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                const target = e.target;
                if (target.tagName === 'INPUT' || target.tagName === 'BUTTON') {
                    target.focus();
                }
            }, { passive: true });

            // Handle print button focus for accessibility
            if (printButton) {
                printButton.addEventListener('focus', function() {
                    printButton.style.outline = '2px solid var(--secondary-color)';
                });
                printButton.addEventListener('blur', function() {
                    printButton.style.outline = 'none';
                });
            }

            // Ensure modals are accessible via keyboard
            const modals = document.querySelectorAll('.modal, .success-overlay');
            modals.forEach(modal => {
                const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                const firstFocusable = focusableElements[0];
                const lastFocusable = focusableElements[focusableElements.length - 1];

                modal.addEventListener('keydown', function(e) {
                    if (e.key === 'Tab') {
                        if (e.shiftKey && document.activeElement === firstFocusable) {
                            e.preventDefault();
                            lastFocusable.focus();
                        } else if (!e.shiftKey && document.activeElement === lastFocusable) {
                            e.preventDefault();
                            firstFocusable.focus();
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>