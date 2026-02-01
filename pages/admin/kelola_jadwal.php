<?php
/**
 * Kelola Jadwal Petugas (Shift) - Final Rapih Version
 * File: pages/admin/kelola_jadwal.php
 */



// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
if (!$project_root)
    $project_root = $current_dir;

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path))
        $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL'))
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// LOGIC & DATABASE
// ============================================
session_start();
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: " . BASE_URL . "/pages/login.php");
    exit;
}

// ============================================
// PRG PATTERN - POST/REDIRECT/GET
// ============================================
// Set flash messages untuk menghindari resubmit
if (!isset($_SESSION['flash_messages'])) {
    $_SESSION['flash_messages'] = [];
}

function setFlash($type, $message)
{
    $_SESSION['flash_messages'][$type] = $message;
}

function getFlash($type)
{
    if (isset($_SESSION['flash_messages'][$type])) {
        $message = $_SESSION['flash_messages'][$type];
        unset($_SESSION['flash_messages'][$type]);
        return $message;
    }
    return null;
}

// Init Variables
$error_message = '';
$success_message = '';
$tanggal = '';
$selected_petugas_id = '';
$petugas1_nama = '';
$petugas2_nama = '';
$id = null;
$show_confirm_modal = false;

// ============================================
// CSRF PROTECTION
// ============================================
if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['csrf_token'];

// Fetch Petugas
$petugas_query = "SELECT u.id, u.nama, u.username, pp.petugas1_nama, pp.petugas2_nama
                  FROM users u
                  INNER JOIN petugas_profiles pp ON u.id = pp.user_id
                  WHERE u.role = 'petugas'
                  AND pp.petugas1_nama IS NOT NULL AND pp.petugas1_nama != ''
                  ORDER BY u.id ASC";
$petugas_result = $conn->query($petugas_query);
$petugas_list = [];
if ($petugas_result) {
    while ($row = $petugas_result->fetch_assoc()) {
        $petugas_list[] = $row;
    }
}

// ============================================
// HANDLE REQUESTS (POST ONLY - PRG Pattern)
// ============================================

// Handle Delete
if (isset($_POST['delete_id']) && $_POST['token'] === $_SESSION['csrf_token']) {
    try {
        $stmt = $conn->prepare("DELETE FROM petugas_shift WHERE id = ?");
        $stmt->bind_param("i", $_POST['delete_id']);
        if ($stmt->execute()) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            setFlash('success', 'Jadwal berhasil dihapus!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
            exit;
        }
    } catch (Exception $e) {
        setFlash('error', 'Gagal hapus: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
        exit;
    }
}

// Handle Edit Confirm
if (isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $id = (int) $_POST['edit_id'];
    $tanggal = $_POST['edit_tanggal'];
    $selected_petugas_id = (int) $_POST['edit_petugas_id'];

    $check = $conn->query("SELECT id FROM petugas_shift WHERE tanggal = '$tanggal' AND id != $id");
    if ($check->num_rows > 0) {
        setFlash('error', "Tanggal sudah digunakan!");
        header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
        exit;
    } else {
        $stmt = $conn->prepare("UPDATE petugas_shift SET tanggal = ?, petugas1_id = ?, petugas2_id = ? WHERE id = ?");
        $stmt->bind_param("siii", $tanggal, $selected_petugas_id, $selected_petugas_id, $id);
        if ($stmt->execute()) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            setFlash('success', 'Jadwal berhasil diupdate!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
            exit;
        } else {
            setFlash('error', 'Gagal update jadwal!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
            exit;
        }
    }
}

// Handle Add Confirm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $tanggal = $_POST['tanggal'];
    $selected_petugas_id = (int) $_POST['petugas_id'];

    $check = $conn->query("SELECT id FROM petugas_shift WHERE tanggal = '$tanggal'");
    if ($check->num_rows > 0) {
        setFlash('error', "Tanggal sudah ada jadwalnya!");
        header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
        exit;
    } else {
        $stmt = $conn->prepare("INSERT INTO petugas_shift (tanggal, petugas1_id, petugas2_id) VALUES (?, ?, ?)");
        $stmt->bind_param("sii", $tanggal, $selected_petugas_id, $selected_petugas_id);
        if ($stmt->execute()) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            setFlash('success', 'Jadwal berhasil dibuat!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
            exit;
        } else {
            setFlash('error', 'Gagal membuat jadwal!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
            exit;
        }
    }
}

// Handle Form Validation (Validation Phase - Show Confirm Modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_confirm']) && !isset($_POST['confirm'])) {
    $tanggal = $_POST['tanggal'];
    $selected_petugas_id = $_POST['petugas_id'];

    if (empty($tanggal) || empty($selected_petugas_id)) {
        setFlash('error', 'Semua field harus diisi!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
        exit;
    } else {
        $res = $conn->query("SELECT petugas1_nama, petugas2_nama FROM petugas_profiles WHERE user_id = $selected_petugas_id");
        if ($res->num_rows > 0) {
            $d = $res->fetch_assoc();
            $petugas1_nama = $d['petugas1_nama'];
            $petugas2_nama = $d['petugas2_nama'];
            $show_confirm_modal = true;
        } else {
            setFlash('error', 'Data petugas tidak ditemukan!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
            exit;
        }
    }
}

// Get Flash Messages
$success_message = getFlash('success');
$error_message = getFlash('error');

// ============================================
// DATA FETCHING (GET Only)
// ============================================
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$total_res = $conn->query("SELECT COUNT(*) as total FROM petugas_shift");
$total_pages = ceil($total_res->fetch_assoc()['total'] / $limit);

$jadwal_res = $conn->query("SELECT ps.*, u.nama as nama_akun, pp.petugas1_nama, pp.petugas2_nama 
                            FROM petugas_shift ps 
                            LEFT JOIN users u ON ps.petugas1_id = u.id 
                            LEFT JOIN petugas_profiles pp ON ps.petugas1_id = pp.user_id 
                            ORDER BY ps.tanggal DESC LIMIT $limit OFFSET $offset");
$jadwal = [];
while ($row = $jadwal_res->fetch_assoc())
    $jadwal[] = $row;

// ============================================
// HANDLE EXPORT PDF
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get date filter parameters
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

    // Build query with optional date filter
    $query = "SELECT ps.*, u.nama as nama_akun, pp.petugas1_nama, pp.petugas2_nama 
              FROM petugas_shift ps 
              LEFT JOIN users u ON ps.petugas1_id = u.id 
              LEFT JOIN petugas_profiles pp ON ps.petugas1_id = pp.user_id";

    $params = [];
    $types = '';

    if (!empty($start_date) && !empty($end_date)) {
        $query .= " WHERE ps.tanggal BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types = 'ss';
    } elseif (!empty($start_date)) {
        $query .= " WHERE ps.tanggal >= ?";
        $params[] = $start_date;
        $types = 's';
    } elseif (!empty($end_date)) {
        $query .= " WHERE ps.tanggal <= ?";
        $params[] = $end_date;
        $types = 's';
    }

    $query .= " ORDER BY ps.tanggal ASC";

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

        // Store date range for PDF title
        $date_range_text = '';
        if (!empty($start_date) && !empty($end_date)) {
            $date_range_text = date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date));
        } elseif (!empty($start_date)) {
            $date_range_text = 'Mulai ' . date('d/m/Y', strtotime($start_date));
        } elseif (!empty($end_date)) {
            $date_range_text = 'Sampai ' . date('d/m/Y', strtotime($end_date));
        }

        require_once PROJECT_ROOT . '/tcpdf/tcpdf.php';

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
                $this->SetDrawColor(0, 0, 0);
                $this->SetLineWidth(0.2);
                $this->Line(10, 32, 200, 32);
            }

            public function Footer()
            {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/y H:i'), 0, 0, 'L');
                $this->Cell(0, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        }

        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('KASDIG');
        $pdf->SetTitle('Jadwal Petugas');
        $pdf->SetMargins(10, 36, 10);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'DAFTAR JADWAL PETUGAS BANK MINI', 0, 1, 'C');

        // Show date range if filtered
        if (!empty($date_range_text)) {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 5, 'Periode: ' . $date_range_text, 0, 1, 'C');
        }

        $pdf->Ln(5);

        $pageWidth = $pdf->getPageWidth() - 20;
        $colWidths = [
            'no' => 0.05 * $pageWidth,
            'tanggal' => 0.15 * $pageWidth,
            'petugas1' => 0.40 * $pageWidth,
            'petugas2' => 0.40 * $pageWidth
        ];

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220); // Light grey
        $pdf->SetTextColor(0, 0, 0); // Black text

        $pdf->Cell($colWidths['no'], 5, 'No', 1, 0, 'C', true);
        $pdf->Cell($colWidths['tanggal'], 5, 'Tanggal', 1, 0, 'C', true);
        $pdf->Cell($colWidths['petugas1'], 5, 'Petugas 1 (Kelas 10)', 1, 0, 'C', true);
        $pdf->Cell($colWidths['petugas2'], 5, 'Petugas 2 (Kelas 11)', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 7); // Font size 7
        $pdf->SetFillColor(255, 255, 255); // White background

        $no = 1;
        foreach ($data as $row) {
            $pdf->Cell($colWidths['no'], 5, $no++, 1, 0, 'C', true);
            $pdf->Cell($colWidths['tanggal'], 5, date('d/m/y', strtotime($row['tanggal'])), 1, 0, 'C', true);
            $pdf->Cell($colWidths['petugas1'], 5, $row['petugas1_nama'], 1, 0, 'L', true);
            $pdf->Cell($colWidths['petugas2'], 5, $row['petugas2_nama'], 1, 1, 'L', true);
        }

        $pdf->Output('jadwal_petugas.pdf', 'D');
    } else {
        setFlash('error', 'Tidak ada data untuk diekspor!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_jadwal.php');
        exit;
    }
    $stmt->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Kelola Jadwal | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />

    <style>
        :root {
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --primary-color: var(--gray-800);
            --primary-dark: var(--gray-900);
            --secondary-color: var(--gray-600);

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
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
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open {
            overflow: hidden;
        }

        body.sidebar-open::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            backdrop-filter: blur(5px);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100% - 280px);
            position: relative;
            z-index: 1;
        }

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content {
            flex: 1;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        .page-hamburger {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            align-items: center;
            justify-content: center;
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        /* Cards */
        .card,
        .form-card,
        .jadwal-list {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: var(--gray-100);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header-text {
            flex: 1;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        /* INPUT STYLING */
        input[type="text"],
        input[type="date"],
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
            height: 48px;
            color: var(--gray-800);
        }

        input:focus,
        select:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        /* Date Picker Wrapper */
        .date-input-wrapper {
            position: relative;
            width: 100%;
        }

        /* Input Date Specifics */
        input[type="date"] {
            appearance: none;
            -webkit-appearance: none;
            position: relative;
            padding-right: 40px;
        }

        /* HIDE Native Calendar Icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }

        /* Custom Icon */
        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            font-size: 1.1rem;
            z-index: 1;
            pointer-events: none;
        }

        /* Button */
        .btn {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-cancel {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
            box-shadow: none;
        }

        .btn-cancel:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
        }

        .btn-pdf {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }

        .btn-pdf:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
        }

        .btn-action {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            color: white;
        }

        .btn-action:hover {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table th {
            background: var(--gray-100);
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .data-table tbody tr {
            background: white;
        }

        .data-table tr:hover {
            background: var(--gray-100) !important;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .actions .btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .actions .btn i {
            margin-right: 4px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
            cursor: default;
        }

        .page-link.page-arrow {
            padding: 0.5rem 0.75rem;
        }

        .page-link.disabled {
            color: var(--gray-300);
            cursor: not-allowed;
            background: var(--gray-50);
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-title-section {
                padding: 1rem;
                margin: -1rem -1rem 1rem -1rem;
            }

            .page-hamburger {
                display: flex;
            }
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .card-body {
                padding: 1rem;
            }

            .card-header {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .data-table {
                min-width: auto;
            }

            .data-table th,
            .data-table td {
                padding: 0.5rem;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .actions {
                flex-direction: row;
                flex-wrap: nowrap;
                gap: 4px;
            }

            .actions .btn {
                padding: 6px 12px;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .actions .btn i {
                margin-right: 2px;
            }

            .pagination {
                flex-wrap: wrap;
                padding: 1rem;
            }

            .page-link {
                padding: 0.4rem 0.75rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .page-subtitle {
                font-size: 0.8rem;
            }
        }

        /* SweetAlert Custom Fixes */
        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }

        .swal-date-wrapper {
            position: relative;
            width: 80%;
            margin: 10px auto;
        }

        .swal-date-wrapper input {
            width: 100% !important;
            box-sizing: border-box !important;
            margin: 0 !important;
        }

        .swal-date-wrapper i {
            right: 15px;
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Kelola Data Jadwal Petugas</h1>
                <p class="page-subtitle">Atur shift dan penugasan petugas bank mini</p>
            </div>
        </div>

        <!-- Card Tambah Jadwal -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="card-header-text">
                    <h3><?= $id ? 'Edit Jadwal' : 'Tambah Jadwal' ?></h3>
                    <p>Atur jadwal penugasan petugas</p>
                </div>
            </div>
            <div class="card-body">
                <form action="" method="POST" id="jadwalForm">
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Tanggal Jadwal</label>
                            <div class="date-input-wrapper">
                                <input type="date" name="tanggal"
                                    value="<?= htmlspecialchars($tanggal ?: date('Y-m-d')) ?>" required
                                    min="<?= date('Y-m-d') ?>">
                                <i class="fas fa-calendar-day calendar-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Kode Petugas</label>
                            <select name="petugas_id" id="petugas_id" required>
                                <option value="">-- Pilih Akun Petugas --</option>
                                <?php foreach ($petugas_list as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-p1="<?= htmlspecialchars($p['petugas1_nama']) ?>"
                                        data-p2="<?= htmlspecialchars($p['petugas2_nama']) ?>"
                                        <?= ($selected_petugas_id == $p['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['nama']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Petugas 1 (Kelas 10)</label>
                            <input type="text" id="p1" name="petugas1_nama"
                                value="<?= htmlspecialchars($petugas1_nama) ?>" readonly placeholder="Otomatis terisi"
                                style="background: #f8fafc;">
                        </div>
                        <div class="form-group">
                            <label>Petugas 2 (Kelas 11)</label>
                            <input type="text" id="p2" name="petugas2_nama"
                                value="<?= htmlspecialchars($petugas2_nama) ?>" readonly placeholder="Otomatis terisi"
                                style="background: #f8fafc;">
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i> <?= $id ? 'Update Jadwal' : 'Simpan Jadwal' ?>
                        </button>
                        <?php if ($id): ?>
                            <a href="<?= BASE_URL ?>/pages/admin/kelola_jadwal.php" class="btn btn-cancel">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Daftar Jadwal -->
        <div class="card">
            <div class="card-header" style="flex-wrap: wrap; gap: 1rem;">
                <div class="card-header-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="card-header-text">
                    <h3>Daftar Jadwal</h3>
                    <p>Daftar jadwal penugasan petugas</p>
                </div>
            </div>

            <!-- Filter Tanggal untuk Cetak PDF -->
            <div class="card-body"
                style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--gray-100); background: var(--gray-100);">
                <form id="pdfFilterForm" class="pdf-filter-form" style=" display: flex; flex-wrap: wrap; gap: 1rem;
                align-items: flex-end;">
                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                        <label style="font-size: 0.8rem; margin-bottom: 4px;">Tanggal Mulai</label>
                        <div class="date-input-wrapper">
                            <input type="date" name="start_date" id="filterStartDate" style="height: 42px;">
                            <i class="fas fa-calendar-day calendar-icon"></i>
                        </div>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                        <label style="font-size: 0.8rem; margin-bottom: 4px;">Tanggal Akhir</label>
                        <div class="date-input-wrapper">
                            <input type="date" name="end_date" id="filterEndDate" style="height: 42px;">
                            <i class="fas fa-calendar-day calendar-icon"></i>
                        </div>
                    </div>
                    <button type="button" class="btn" id="filterBtn" style="height: 42px;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" class="btn btn-cancel" id="resetFilter" style="height: 42px;">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-pdf" style="height: 42px;">
                        <i class="fas fa-file-pdf"></i> Cetak PDF
                    </button>
                </form>
                <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 8px; margin-bottom: 0;">Kosongkan
                    tanggal untuk mencetak semua data</p>
            </div>

            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Kode Akun</th>
                            <th>Petugas 1</th>
                            <th>Petugas 2</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jadwal)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:30px; color:#999;">Belum ada data jadwal.
                                </td>
                            </tr>
                        <?php else:
                            $no = $offset + 1;
                            foreach ($jadwal as $row): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><span
                                            style="font-weight:500; color:var(--gray-800);"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['nama_akun'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['petugas1_nama'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['petugas2_nama'] ?? '-') ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-action"
                                                onclick="editJadwal(<?= $row['id'] ?>, '<?= $row['tanggal'] ?>', <?= $row['petugas1_id'] ?>)">
                                                <i class="fas fa-pencil-alt"></i> Edit
                                            </button>
                                            <button class="btn btn-action"
                                                onclick="hapusJadwal(<?= $row['id'] ?>, '<?= date('d/m/Y', strtotime($row['tanggal'])) ?>')">
                                                <i class="fas fa-trash-alt"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <!-- Previous button -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="page-link page-arrow">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Current page indicator -->
                    <span class="page-link active"><?= $page ?></span>

                    <!-- Next button -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="page-link page-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
            // ============================================
            // FIXED SIDEBAR MOBILE BEHAVIOR
            // ============================================
            const menuToggle = document.getElementById('pageHamburgerBtn');
            const sidebar = document.getElementById('sidebar');
            const body = document.body;

            function openSidebar() {
                body.classList.add('sidebar-open');
                if (sidebar) sidebar.classList.add('active');
            }

            function closeSidebar() {
                body.classList.remove('sidebar-open');
                if (sidebar) sidebar.classList.remove('active');
            }

            // Toggle Sidebar
            if (menuToggle) {
                menuToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (body.classList.contains('sidebar-open')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });
            }

            // Close sidebar on outside click
            document.addEventListener('click', function (e) {
                if (body.classList.contains('sidebar-open') &&
                    !sidebar?.contains(e.target) &&
                    !menuToggle?.contains(e.target)) {
                    closeSidebar();
                }
            });

            // Close sidebar on escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                    closeSidebar();
                }
            });

            // Prevent body scroll when sidebar open (mobile)
            let scrollTop = 0;
            body.addEventListener('scroll', function () {
                if (window.innerWidth <= 768 && body.classList.contains('sidebar-open')) {
                    scrollTop = body.scrollTop;
                }
            }, { passive: true });

            // ============================================
            // FORM FUNCTIONALITY
            // ============================================
            // Auto Fill Petugas Names
            const selectPetugas = document.getElementById('petugas_id');
            if (selectPetugas) {
                selectPetugas.addEventListener('change', function () {
                    const opt = this.options[this.selectedIndex];
                    document.getElementById('p1').value = opt.getAttribute('data-p1') || '';
                    document.getElementById('p2').value = opt.getAttribute('data-p2') || '';
                });
            }

            // PDF Export with Inline Date Filter
            const pdfFilterForm = document.getElementById('pdfFilterForm');
            const resetFilterBtn = document.getElementById('resetFilter');

            if (pdfFilterForm) {
                pdfFilterForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const startDate = document.getElementById('filterStartDate').value;
                    const endDate = document.getElementById('filterEndDate').value;

                    // Show loading
                    Swal.fire({
                        title: 'Sedang memproses...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // Build URL with date parameters
                    let exportUrl = '<?= BASE_URL ?>/pages/admin/kelola_jadwal.php?export=pdf';
                    if (startDate) {
                        exportUrl += '&start_date=' + startDate;
                    }
                    if (endDate) {
                        exportUrl += '&end_date=' + endDate;
                    }

                    setTimeout(() => {
                        window.location.href = exportUrl;
                        setTimeout(() => Swal.close(), 1000);
                    }, 500);
                });
            }

            // Reset filter button
            if (resetFilterBtn) {
                resetFilterBtn.addEventListener('click', function () {
                    document.getElementById('filterStartDate').value = '';
                    document.getElementById('filterEndDate').value = '';
                    // Reload halaman tanpa filter
                    window.location.href = '<?= BASE_URL ?>/pages/admin/kelola_jadwal.php';
                });
            }

            // Filter button - filter tabel berdasarkan tanggal
            const filterBtn = document.getElementById('filterBtn');
            if (filterBtn) {
                filterBtn.addEventListener('click', function () {
                    const startDate = document.getElementById('filterStartDate').value;
                    const endDate = document.getElementById('filterEndDate').value;

                    if (!startDate && !endDate) {
                        Swal.fire('Perhatian', 'Pilih minimal satu tanggal untuk memfilter', 'warning');
                        return;
                    }

                    // Filter dan sort tabel
                    const table = document.querySelector('.data-table tbody');
                    const rows = Array.from(table.querySelectorAll('tr'));

                    // Filter rows dan simpan yang visible
                    let filteredRows = [];

                    rows.forEach(row => {
                        const dateCell = row.querySelector('td:nth-child(2)');
                        if (dateCell) {
                            const dateText = dateCell.textContent.trim();
                            // Parse dd/mm/yyyy format
                            const parts = dateText.split('/');
                            if (parts.length === 3) {
                                const rowDate = new Date(parts[2], parts[1] - 1, parts[0]);
                                const start = startDate ? new Date(startDate) : null;
                                const end = endDate ? new Date(endDate) : null;

                                let show = true;
                                if (start && rowDate < start) show = false;
                                if (end && rowDate > end) show = false;

                                if (show) {
                                    filteredRows.push({ row: row, date: rowDate });
                                }
                                row.style.display = show ? '' : 'none';
                            }
                        }
                    });

                    // Sort filtered rows by date ascending (tanggal kecil dulu)
                    filteredRows.sort((a, b) => a.date - b.date);

                    // Reorder rows in table dan update nomor urut
                    let newNumber = 1;
                    filteredRows.forEach(item => {
                        // Pindahkan row ke akhir tbody (untuk reorder)
                        table.appendChild(item.row);
                        // Update nomor urut
                        const noCell = item.row.querySelector('td:first-child');
                        if (noCell) {
                            noCell.textContent = newNumber++;
                        }
                    });

                    // Hide/show pagination berdasarkan jumlah data
                    const pagination = document.querySelector('.pagination');
                    if (pagination) {
                        if (filteredRows.length < 10) {
                            pagination.style.display = 'none';
                        } else {
                            pagination.style.display = 'flex';
                        }
                    }

                    // Show message only if no results (tanpa popup sukses)
                    if (filteredRows.length === 0) {
                        Swal.fire('Info', 'Tidak ada data pada rentang tanggal yang dipilih', 'info');
                    }
                });
            }

            // ============================================
            // MODAL FUNCTIONS
            // ============================================
            window.editJadwal = function (id, tgl, pid) {
                Swal.fire({
                    title: 'Edit Jadwal',
                    html: `
                    <div class="swal-date-wrapper">
                        <input type="date" id="swal-tgl" value="${tgl}" class="swal2-input" style="padding-right:40px;">
                        <i class="fas fa-calendar-day" style="position:absolute;right:25px;top:50%;transform:translateY(-50%);color:#1e3a8a;font-size:1.1rem;z-index:1;pointer-events:none;"></i>
                    </div>
                    <select id="swal-pid" class="swal2-input" style="margin-top:10px;">
                        <?php foreach ($petugas_list as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                `,
                    didOpen: () => {
                        document.getElementById('swal-pid').value = pid;
                    },
                    showCancelButton: true,
                    confirmButtonText: 'Simpan',
                    confirmButtonColor: '#1e3a8a',
                    preConfirm: () => {
                        return {
                            tgl: document.getElementById('swal-tgl').value,
                            pid: document.getElementById('swal-pid').value
                        }
                    }
                }).then((res) => {
                    if (res.isConfirmed) {
                        const f = document.createElement('form');
                        f.method = 'POST';
                        f.innerHTML = `
                        <input type="hidden" name="token" value="<?= $token ?>">
                        <input type="hidden" name="edit_confirm" value="1">
                        <input type="hidden" name="edit_id" value="${id}">
                        <input type="hidden" name="edit_tanggal" value="${res.value.tgl}">
                        <input type="hidden" name="edit_petugas_id" value="${res.value.pid}">`;
                        document.body.appendChild(f);
                        f.submit();
                    }
                });
            }

            window.hapusJadwal = function (id, tgl) {
                Swal.fire({
                    title: 'Hapus?',
                    text: `Hapus jadwal tanggal ${tgl}?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'Ya, Hapus'
                }).then((res) => {
                    if (res.isConfirmed) {
                        const f = document.createElement('form');
                        f.method = 'POST';
                        f.innerHTML = `
                        <input type="hidden" name="token" value="<?= $token ?>">
                        <input type="hidden" name="delete_id" value="${id}">`;
                        document.body.appendChild(f);
                        f.submit();
                    }
                });
            }

            // ============================================
            // CONFIRM MODAL (untuk Add)
            // ============================================
            <?php if ($show_confirm_modal): ?>
                Swal.fire({
                    title: 'Konfirmasi',
                    html: `Simpan jadwal tanggal <b><?= htmlspecialchars($tanggal) ?></b>?<br>
                   <small>Petugas: <?= htmlspecialchars($petugas1_nama) ?> & <?= htmlspecialchars($petugas2_nama) ?></small>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Simpan',
                    confirmButtonColor: '#1e3a8a'
                }).then((res) => {
                    if (res.isConfirmed) {
                        const f = document.createElement('form');
                        f.method = 'POST';
                        f.innerHTML = `
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <input type="hidden" name="confirm" value="1">
                    <input type="hidden" name="tanggal" value="<?= $tanggal ?>">
                    <input type="hidden" name="petugas_id" value="<?= $selected_petugas_id ?>">`;
                        document.body.appendChild(f);
                        f.submit();
                    } else {
                        // Reset form jika cancel
                        document.getElementById('petugas_id').value = '';
                        document.getElementById('p1').value = '';
                        document.getElementById('p2').value = '';
                    }
                });
            <?php endif; ?>

            // Show Flash Messages
            <?php if ($success_message): ?>
                Swal.fire('Berhasil', '<?= $success_message ?>', 'success');
            <?php endif; ?>

            <?php if ($error_message): ?>
                Swal.fire('Gagal', '<?= $error_message ?>', 'error');
            <?php endif; ?>
        </script>
</body>

</html>