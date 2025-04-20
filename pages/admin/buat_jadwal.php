<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to non-siswa users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';
$tanggal = '';
$petugas1_nama = '';
$petugas2_nama = '';
$id = null;

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
        $error = "Jadwal tidak ditemukan!";
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
        $error = 'Gagal menghapus jadwal: ' . $e->getMessage();
    }
}

// Handle Edit Submission
if (isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['form_token']) {
    $id = (int)$_POST['edit_id'];
    $tanggal = $_POST['edit_tanggal'];
    $petugas1_nama = trim($_POST['edit_petugas1_nama']);
    $petugas2_nama = trim($_POST['edit_petugas2_nama']);

    if (empty($tanggal) || empty($petugas1_nama) || empty($petugas2_nama)) {
        $error = "Semua field harus diisi!";
    } elseif (strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
        $error = "Nama petugas harus minimal 3 karakter!";
    } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
        $error = "Tanggal tidak boleh di masa lalu!";
    } else {
        $check_query = "SELECT id FROM petugas_tugas WHERE tanggal = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $tanggal, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Tanggal sudah digunakan untuk jadwal lain!";
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
                $error = 'Gagal mengupdate jadwal: ' . $e->getMessage();
            }
        }
    }
}

// Handle Export Request
if (isset($_GET['export']) && !empty($_GET['export'])) {
    $export_type = $_GET['export'];
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    $query = "SELECT * FROM petugas_tugas WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($start_date)) {
        $query .= " AND tanggal >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if (!empty($end_date)) {
        $query .= " AND tanggal <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    $query .= " ORDER BY tanggal DESC";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
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
            if (!empty($start_date) && !empty($end_date)) {
                $filter_text = "Periode: " . date('d/m/y', strtotime($start_date)) . " s/d " . date('d/m/y', strtotime($end_date));
            } elseif (!empty($start_date)) {
                $filter_text = "Periode: Dari " . date('d/m/y', strtotime($start_date));
            } elseif (!empty($end_date)) {
                $filter_text = "Periode: Sampai " . date('d/m/y', strtotime($end_date));
            }

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
            if (!empty($start_date) && !empty($end_date)) {
                $filter_text = "Periode: " . date('d/m/y', strtotime($start_date)) . " s/d " . date('d/m/y', strtotime($end_date));
            } elseif (!empty($start_date)) {
                $filter_text = "Periode: Dari " . date('d/m/y', strtotime($start_date));
            } elseif (!empty($end_date)) {
                $filter_text = "Periode: Sampai " . date('d/m/y', strtotime($end_date));
            }
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
        header("Location: " . $_SERVER['PHP_SELF'] . "?error=nodata");
        exit;
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
            $error = "Semua field harus diisi!";
        } elseif (strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
            $error = "Nama petugas harus minimal 3 karakter!";
        } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
            $error = "Tanggal tidak boleh di masa lalu!";
        } else {
            $conn->begin_transaction();
            try {
                if ($id) {
                    $error = "Operasi tidak valid!";
                } else {
                    $query_check = "SELECT id FROM petugas_tugas WHERE tanggal = ?";
                    $stmt_check = $conn->prepare($query_check);
                    $stmt_check->bind_param("s", $tanggal);
                    $stmt_check->execute();
                    $result_check = $stmt_check->get_result();

                    if ($result_check->num_rows > 0) {
                        throw new Exception('Tanggal sudah ada dalam database!');
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
                $error = $e->getMessage();
            }
        }
    } else {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $tanggal = $_POST['tanggal'];
        $petugas1_nama = trim($_POST['petugas1_nama']);
        $petugas2_nama = trim($_POST['petugas2_nama']);

        if (empty($tanggal) || empty($petugas1_nama) || empty($petugas2_nama)) {
            $error = 'Semua field harus diisi!';
        } elseif (strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
            $error = "Nama petugas harus minimal 3 karakter!";
        } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
            $error = "Tanggal tidak boleh di masa lalu!";
        } else {
            header('Location: buat_jadwal.php?confirm=1&tanggal=' . urlencode($tanggal) . '&petugas1_nama=' . urlencode($petugas1_nama) . '&petugas2_nama=' . urlencode($petugas2_nama));
            exit;
        }
    }
}

// Fetch Data with Filters
$filter_start = isset($_GET['filter_start']) && !empty($_GET['filter_start']) ? $_GET['filter_start'] : date('Y-m-d');
$filter_end = isset($_GET['filter_end']) && !empty($_GET['filter_end']) ? $_GET['filter_end'] : date('Y-m-d');
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_query = "SELECT COUNT(*) as total FROM petugas_tugas WHERE 1=1";
$total_params = [];
$total_types = "";

if (!empty($filter_start)) {
    $total_query .= " AND tanggal >= ?";
    $total_params[] = $filter_start;
    $total_types .= "s";
}
if (!empty($filter_end)) {
    $total_query .= " AND tanggal <= ?";
    $total_params[] = $filter_end;
    $total_types .= "s";
}

$total_stmt = $conn->prepare($total_query);
if (!empty($total_params)) {
    $total_stmt->bind_param($total_types, ...$total_params);
}
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_row = $total_result->fetch_assoc();
$total_data = $total_row['total'];
$total_pages = ceil($total_data / $limit);

$jadwal_query = "SELECT * FROM petugas_tugas WHERE 1=1";
$jadwal_params = [];
$jadwal_types = "";

if (!empty($filter_start)) {
    $jadwal_query .= " AND tanggal >= ?";
    $jadwal_params[] = $filter_start;
    $jadwal_types .= "s";
}
if (!empty($filter_end)) {
    $jadwal_query .= " AND tanggal <= ?";
    $jadwal_params[] = $filter_end;
    $jadwal_types .= "s";
}

$jadwal_query .= " ORDER BY tanggal DESC LIMIT ? OFFSET ?";
$jadwal_params[] = $limit;
$jadwal_params[] = $offset;
$jadwal_types .= "ii";

$jadwal_stmt = $conn->prepare($jadwal_query);
if (!empty($jadwal_types)) {
    $jadwal_stmt->bind_param($jadwal_types, ...$jadwal_params);
}
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Buat Jadwal Petugas - SCHOBANK SYSTEM</title>
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
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 15px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
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
            margin-bottom: 8px;
            font-size: clamp(1.3rem, 2.5vw, 1.5rem);
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        input[type="text"],
        input[type="date"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            line-height: 1.5;
            min-height: 40px;
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

        /* Ensure date picker icon is visible in Safari */
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
            padding-right: 30px; /* Space for calendar icon */
        }

        input[type="text"]:focus,
        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(12, 77, 162, 0.1);
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            width: fit-content;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-edit {
            background-color: var(--secondary-color);
        }

        .btn-edit:hover {
            background-color: var(--secondary-dark);
        }

        .btn-delete {
            background-color: var(--danger-color);
        }

        .btn-delete:hover {
            background-color: #d32f2f;
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
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
            border-left: 4px solid #fecaca;
        }

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 4px solid var(--primary-color);
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .date-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            flex: 1;
        }

        .filter-group {
            flex: 1;
            min-width: 140px;
        }

        .filter-buttons {
            display: flex;
            gap: 12px;
        }

        .jadwal-list {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .jadwal-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-3px);
        }

        .jadwal-list h3 {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.1rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px 12px;
            text-align: left;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        tr {
            transition: opacity 0.3s ease;
        }

        tr.deleting {
            animation: fadeOutRow 0.5s ease forwards;
        }

        @keyframes fadeOutRow {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(-20px); }
        }

        tr:hover {
            background-color: #f8faff;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .pagination a {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            color: var(--text-primary);
            background-color: #f0f0f0;
            transition: var(--transition);
        }

        .pagination a:hover:not(.disabled) {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .pagination a.disabled {
            color: var(--text-secondary);
            background-color: #e0e0e0;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination .current-page {
            padding: 8px 12px;
            border-radius: 8px;
            background-color: var(--primary-color);
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
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }

        .modal.visible {
            opacity: 1;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: var(--shadow-md);
            transform: scale(0.8);
            opacity: 0;
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }

        .modal-content.visible {
            transform: scale(1);
            opacity: 1;
        }

        .modal-title {
            font-size: clamp(1rem, 2vw, 1.1rem);
            color: var(--primary-dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal p {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }

        .success-overlay.visible {
            opacity: 1;
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            max-width: 90%;
            width: 400px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.8);
            opacity: 0;
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }

        .success-modal.visible {
            transform: scale(1);
            opacity: 1;
        }

        .success-icon {
            font-size: clamp(3rem, 6vw, 3.5rem);
            color: var(--secondary-color);
            margin-bottom: 20px;
            transition: transform 0.3s ease-out;
        }

        .success-modal.visible .success-icon {
            transform: scale(1.1);
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-size: clamp(1.2rem, 2.5vw, 1.3rem);
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            opacity: 0.8;
            animation: confettiFall 4s ease-out forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 15px;
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-data {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            color: #d1d5db;
            margin-bottom: 10px;
        }

        .no-data p {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 10px;
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .main-content {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            }

            .form-card, .filter-card, .jadwal-list {
                padding: 15px;
            }

            form {
                gap: 12px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .filter-card {
                flex-direction: column;
            }

            .date-group {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .filter-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: column;
                width: 100%;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 8px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }

            .modal-content {
                margin: 10px;
                width: calc(100% - 20px);
                padding: 15px;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .success-modal {
                width: 90%;
                padding: 20px;
            }

            .pagination {
                gap: 6px;
            }

            .pagination a, .pagination .current-page {
                padding: 6px 10px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .top-nav {
                padding: 8px;
            }

            .welcome-banner {
                padding: 12px;
            }

            .jadwal-list h3 {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            .no-data i {
                font-size: clamp(1.2rem, 2.5vw, 1.5rem);
            }

            .success-modal h3 {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .success-modal p {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            input[type="text"],
            input[type="date"] {
                min-height: 36px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 36px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>Buat Jadwal Petugas</h2>
            <p>Kelola Jadwal Petugas disini</p>
        </div>

        <!-- Alerts -->
        <div id="alertContainer">
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Jadwal berhasil ditambahkan!
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['edit_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Jadwal berhasil diupdate!
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['delete_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Jadwal berhasil dihapus!
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] == 'nodata'): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    Tidak ada data untuk diekspor!
                </div>
            <?php endif; ?>
        </div>

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
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn" id="submit-btn">
                        <i class="fas fa-plus-circle"></i> <?php echo $id ? 'Update Jadwal' : 'Tambah Jadwal'; ?>
                    </button>
                    <?php if ($id): ?>
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='buat_jadwal.php'">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Filter Section -->
        <div class="filter-card">
            <div class="date-group">
                <div class="filter-group">
                    <label for="filter_start">Dari Tanggal</label>
                    <input type="date" id="filter_start" name="filter_start" value="<?php echo htmlspecialchars($filter_start); ?>">
                </div>
                <div class="filter-group">
                    <label for="filter_end">Sampai Tanggal</label>
                    <input type="date" id="filter_end" name="filter_end" value="<?php echo htmlspecialchars($filter_end); ?>">
                </div>
            </div>
            <div class="filter-buttons">
                <button id="filterButton" class="btn"><i class="fas fa-filter"></i> Filter</button>
                <button id="printButton" class="btn btn-edit"><i class="fas fa-print"></i> Cetak</button>
            </div>
        </div>

        <!-- Jadwal List -->
        <div class="jadwal-list">
            <h3><i class="fas fa-list"></i> Daftar Jadwal</h3>
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
                                <td><?php echo date('d/m/y', strtotime($row['tanggal'])); ?></td>
                                <td><?php echo htmlspecialchars($row['petugas1_nama']); ?></td>
                                <td><?php echo htmlspecialchars($row['petugas2_nama']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-edit" 
                                                onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['tanggal'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['petugas1_nama'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['petugas2_nama'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-delete" 
                                                onclick="deleteJadwal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(date('d/m/y', strtotime($row['tanggal'])), ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo !empty($filter_start) ? '&filter_start=' . urlencode($filter_start) : ''; ?><?php echo !empty($filter_end) ? '&filter_end=' . urlencode($filter_end) : ''; ?>" class="pagination-link">« Previous</a>
                    <?php else: ?>
                        <a class="pagination-link disabled">« Previous</a>
                    <?php endif; ?>
                    <span class="current-page"><?php echo $page; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo !empty($filter_start) ? '&filter_start=' . urlencode($filter_start) : ''; ?><?php echo !empty($filter_end) ? '&filter_end=' . urlencode($filter_end) : ''; ?>" class="pagination-link">Next »</a>
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

        <!-- Confirmation Modal -->
        <?php if (isset($_GET['confirm']) && isset($_GET['tanggal']) && isset($_GET['petugas1_nama']) && isset($_GET['petugas2_nama'])): ?>
            <div class="success-overlay visible" id="confirmModal">
                <div class="success-modal visible">
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
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='buat_jadwal.php'">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-overlay visible" id="successModal">
                <div class="success-modal visible">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Jadwal berhasil ditambahkan!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Edit Success Modal -->
        <?php if (isset($_GET['edit_success'])): ?>
            <div class="success-overlay visible" id="editSuccessModal">
                <div class="success-modal visible">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Jadwal berhasil diupdate!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Delete Success Modal -->
        <?php if (isset($_GET['delete_success'])): ?>
            <div class="success-overlay visible" id="deleteSuccessModal">
                <div class="success-modal visible">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Jadwal berhasil dihapus!</p>
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
                        <button type="button" class="btn-cancel" onclick="hideEditModal()">Batal</button>
                        <button type="submit" class="btn btn-edit" id="edit-submit-btn">
                            <i class="fas fa-save"></i> Simpan
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
                        <button type="submit" class="btn btn-confirm" id="edit-confirm-btn">
                            <i class="fas fa-check"></i> Konfirmasi
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="hideEditConfirmModal()">
                            <i class="fas fa-times"></i> Batal
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
                        <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Batal</button>
                        <button type="submit" class="btn btn-delete" id="delete-submit-btn">
                            <i class="fas fa-trash"></i> Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Format tanggal ke dd/mm/yy
        function formatDate(date) {
            const d = new Date(date);
            return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getFullYear()).slice(-2)}`;
        }

        // Menampilkan alert
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => {
                alert.classList.add('hide');
                setTimeout(() => alert.remove(), 500);
            });

            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            alertDiv.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            alertContainer.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.classList.add('hide');
                setTimeout(() => alertDiv.remove(), 500);
            }, 5000);
        }

        // Menerapkan filter
        function applyFilters() {
            const startDate = document.getElementById('filter_start').value;
            const endDate = document.getElementById('filter_end').value;
            const today = new Date().toISOString().split('T')[0];

            if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                return;
            }

            if (startDate && new Date(startDate) > new Date(today)) {
                showAlert('Tanggal awal tidak boleh melebihi tanggal hari ini', 'error');
                return;
            }

            if (endDate && new Date(endDate) > new Date(today)) {
                showAlert('Tanggal akhir tidak boleh melebihi tanggal hari ini', 'error');
                return;
            }

            const url = new URL(window.location);
            if (startDate) url.searchParams.set('filter_start', startDate);
            else url.searchParams.delete('filter_start');
            if (endDate) url.searchParams.set('filter_end', endDate);
            else url.searchParams.delete('filter_end');
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        // Edit Modal Functions
        let isModalOpening = false;

        function showEditModal(id, tanggal, petugas1_nama, petugas2_nama) {
            if (isModalOpening) return;
            isModalOpening = true;

            const modal = document.getElementById('editModal');
            const modalContent = modal.querySelector('.modal-content');

            document.getElementById('edit_id').value = id;
            document.getElementById('edit_tanggal').value = tanggal;
            document.getElementById('edit_petugas1_nama').value = petugas1_nama;
            document.getElementById('edit_petugas2_nama').value = petugas2_nama;

            modal.style.display = 'none';
            modal.classList.remove('visible');
            modalContent.classList.remove('visible');

            modal.offsetHeight; // Force reflow

            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('visible');
                modalContent.classList.add('visible');
            }, 10);

            setTimeout(() => {
                isModalOpening = false;
            }, 600);
        }

        function hideEditModal() {
            const modal = document.getElementById('editModal');
            const modalContent = modal.querySelector('.modal-content');
            modal.classList.remove('visible');
            modalContent.classList.remove('visible');
            setTimeout(() => {
                modal.style.display = 'none';
                isModalOpening = false;
            }, 300);
        }

        function showEditConfirmation() {
            if (isModalOpening) return false;
            isModalOpening = true;

            const tanggal = document.getElementById('edit_tanggal').value;
            const petugas1_nama = document.getElementById('edit_petugas1_nama').value.trim();
            const petugas2_nama = document.getElementById('edit_petugas2_nama').value.trim();
            
            if (!tanggal || petugas1_nama.length < 3 || petugas2_nama.length < 3) {
                showAlert('Semua field harus diisi dan nama petugas minimal 3 karakter!', 'error');
                isModalOpening = false;
                return false;
            }
            
            const modal = document.getElementById('editConfirmModal');
            const modalContent = modal.querySelector('.success-modal');

            document.getElementById('editConfirmId').value = document.getElementById('edit_id').value;
            document.getElementById('editConfirmTanggal').textContent = formatDate(tanggal);
            document.getElementById('editConfirmTanggalInput').value = tanggal;
            document.getElementById('editConfirmPetugas1').textContent = petugas1_nama;
            document.getElementById('editConfirmPetugas1Input').value = petugas1_nama;
            document.getElementById('editConfirmPetugas2').textContent = petugas2_nama;
            document.getElementById('editConfirmPetugas2Input').value = petugas2_nama;

            modal.style.display = 'none';
            modal.classList.remove('visible');
            modalContent.classList.remove('visible');

            modal.offsetHeight; // Force reflow

            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('visible');
                modalContent.classList.add('visible');
            }, 10);

            hideEditModal();

            setTimeout(() => {
                isModalOpening = false;
            }, 600);
            return false;
        }

        function hideEditConfirmModal() {
            const modal = document.getElementById('editConfirmModal');
            const modalContent = modal.querySelector('.success-modal');
            modal.classList.remove('visible');
            modalContent.classList.remove('visible');
            setTimeout(() => {
                modal.style.display = 'none';
                isModalOpening = false;
            }, 300);
        }

        // Delete Modal Functions
        function deleteJadwal(id, tanggal) {
            if (isModalOpening) return;
            isModalOpening = true;

            const modal = document.getElementById('deleteModal');
            const modalContent = modal.querySelector('.modal-content');

            document.getElementById('delete_id').value = id;
            document.getElementById('delete_tanggal').textContent = tanggal;

            modal.style.display = 'none';
            modal.classList.remove('visible');
            modalContent.classList.remove('visible');

            modal.offsetHeight; // Force reflow

            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('visible');
                modalContent.classList.add('visible');
            }, 10);

            const deleteForm = document.getElementById('deleteForm');
            deleteForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const row = document.getElementById(`row-${id}`);
                if (row) {
                    row.classList.add('deleting');
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 500);
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
            const modalContent = modal.querySelector('.modal-content');
            modal.classList.remove('visible');
            modalContent.classList.remove('visible');
            setTimeout(() => {
                modal.style.display = 'none';
                isModalOpening = false;
            }, 300);
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', () => {
            // Dynamic Date Constraints
            const startDateInput = document.getElementById('filter_start');
            const endDateInput = document.getElementById('filter_end');
            const today = new Date().toISOString().split('T')[0]; // Current date in YYYY-MM-DD

            // Set max date to today for both inputs
            startDateInput.setAttribute('max', today);
            endDateInput.setAttribute('max', today);

            // Update constraints when filter_start changes
            startDateInput.addEventListener('change', function() {
                if (this.value) {
                    endDateInput.setAttribute('min', this.value);
                    // If end_date is before start_date, reset it
                    if (endDateInput.value && endDateInput.value < this.value) {
                        endDateInput.value = this.value;
                    }
                } else {
                    endDateInput.removeAttribute('min');
                }
            });

            // Update constraints when filter_end changes
            endDateInput.addEventListener('change', function() {
                if (this.value) {
                    startDateInput.setAttribute('max', this.value);
                    // If start_date is after end_date, reset it
                    if (startDateInput.value && startDateInput.value > this.value) {
                        startDateInput.value = this.value;
                    }
                } else {
                    startDateInput.setAttribute('max', today);
                }
            });

            // Filter Button
            const filterButton = document.getElementById('filterButton');
            if (filterButton) {
                filterButton.addEventListener('click', function() {
                    if (filterButton.classList.contains('loading')) return;
                    filterButton.classList.add('loading');
                    filterButton.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                    applyFilters();
                    setTimeout(() => {
                        filterButton.classList.remove('loading');
                        filterButton.innerHTML = '<i class="fas fa-filter"></i> Filter';
                    }, 1000);
                });
            }

            // Print Button
            const printButton = document.getElementById('printButton');
            if (printButton) {
                printButton.addEventListener('click', function() {
                    if (printButton.classList.contains('loading')) return;
                    printButton.classList.add('loading');
                    printButton.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        printButton.classList.remove('loading');
                        printButton.innerHTML = '<i class="fas fa-print"></i> Cetak';
                        return;
                    }

                    if (startDate && new Date(startDate) > new Date(today)) {
                        showAlert('Tanggal awal tidak boleh melebihi tanggal hari ini', 'error');
                        printButton.classList.remove('loading');
                        printButton.innerHTML = '<i class="fas fa-print"></i> Cetak';
                        return;
                    }

                    if (endDate && new Date(endDate) > new Date(today)) {
                        showAlert('Tanggal akhir tidak boleh melebihi tanggal hari ini', 'error');
                        printButton.classList.remove('loading');
                        printButton.innerHTML = '<i class="fas fa-print"></i> Cetak';
                        return;
                    }

                    // Construct the PDF export URL
                    const params = new URLSearchParams();
                    params.set('export', 'pdf');
                    if (startDate) params.set('start_date', startDate);
                    if (endDate) params.set('end_date', endDate);

                    const exportUrl = `${window.location.pathname}?${params.toString()}`;
                    console.log('Export URL:', exportUrl); // Debugging

                    // Attempt to open the PDF
                    try {
                        const pdfWindow = window.open(exportUrl);
                        if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed === 'undefined') {
                            showAlert('Gagal membuka PDF. Pastikan popup tidak diblokir oleh browser.', 'error');
                        } else {
                            // Monitor if the window loads successfully
                            setTimeout(() => {
                                if (pdfWindow.document.readyState === 'complete') {
                                    console.log('PDF window loaded successfully');
                                } else {
                                    showAlert('Gagal memuat PDF. Silakan coba lagi.', 'error');
                                }
                            }, 1000);
                        }
                    } catch (e) {
                        console.error('Error opening PDF:', e);
                        showAlert('Terjadi kesalahan saat membuka PDF.', 'error');
                    }

                    // Reset button state
                    setTimeout(() => {
                        printButton.classList.remove('loading');
                        printButton.innerHTML = '<i class="fas fa-print"></i> Cetak';
                    }, 1000);
                });
            }

            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Auto-dismiss success modals after 3 seconds
            const successModals = document.querySelectorAll('.success-overlay');
            successModals.forEach(modal => {
                setTimeout(() => {
                    modal.classList.remove('visible');
                    modal.querySelector('.success-modal').classList.remove('visible');
                    setTimeout(() => {
                        modal.style.display = 'none';
                        // Redirect to clean URL after success
                        if (modal.id === 'successModal' || modal.id === 'editSuccessModal' || modal.id === 'deleteSuccessModal') {
                            const url = new URL(window.location);
                            url.searchParams.delete('success');
                            url.searchParams.delete('edit_success');
                            url.searchParams.delete('delete_success');
                            url.searchParams.delete('error');
                            history.replaceState(null, '', url.toString());
                        }
                    }, 300);
                }, 3000);
            });

            // Add confetti effect to success modals
            const successModalContents = document.querySelectorAll('.success-modal');
            successModalContents.forEach(content => {
                for (let i = 0; i < 20; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = `${Math.random() * 100}%`;
                    confetti.style.animationDelay = `${Math.random() * 2}s`;
                    confetti.style.animationDuration = `${3 + Math.random() * 2}s`;
                    content.appendChild(confetti);
                }
            });

            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            // Ensure modals close when clicking outside
            document.querySelectorAll('.modal, .success-overlay').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        if (modal.id === 'editModal') hideEditModal();
                        else if (modal.id === 'deleteModal') hideDeleteModal();
                        else if (modal.id === 'editConfirmModal') hideEditConfirmModal();
                        else if (modal.classList.contains('success-overlay')) {
                            modal.classList.remove('visible');
                            modal.querySelector('.success-modal').classList.remove('visible');
                            setTimeout(() => {
                                modal.style.display = 'none';
                            }, 300);
                        }
                    }
                });
            });

            // Handle keyboard events for accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideEditModal();
                    hideDeleteModal();
                    hideEditConfirmModal();
                    document.querySelectorAll('.success-overlay.visible').forEach(modal => {
                        modal.classList.remove('visible');
                        modal.querySelector('.success-modal').classList.remove('visible');
                        setTimeout(() => {
                            modal.style.display = 'none';
                        }, 300);
                    });
                }
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
        });
    </script>
</body>
</html>