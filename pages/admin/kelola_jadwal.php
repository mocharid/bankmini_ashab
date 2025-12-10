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
if (!$project_root) $project_root = $current_dir;

if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH')) define('ASSETS_PATH', PROJECT_ROOT . '/assets');

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) define('BASE_URL', rtrim(getBaseUrl(), '/'));
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

function setFlash($type, $message) {
    $_SESSION['flash_messages'][$type] = $message;
}

function getFlash($type) {
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
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['csrf_token'];

// Fetch Petugas
$petugas_query = "SELECT u.id, u.nama, u.username, pp.petugas1_nama, pp.petugas2_nama
                  FROM users u
                  INNER JOIN petugas_profiles pp ON u.id = pp.user_id
                  WHERE u.role = 'petugas'
                  AND pp.petugas1_nama IS NOT NULL AND pp.petugas1_nama != ''
                  ORDER BY u.nama ASC";
$petugas_result = $conn->query($petugas_query);
$petugas_list = [];
if ($petugas_result) {
    while ($row = $petugas_result->fetch_assoc()) { $petugas_list[] = $row; }
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
    $id = (int)$_POST['edit_id'];
    $tanggal = $_POST['edit_tanggal'];
    $selected_petugas_id = (int)$_POST['edit_petugas_id'];
    
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
    $selected_petugas_id = (int)$_POST['petugas_id'];
    
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
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$total_res = $conn->query("SELECT COUNT(*) as total FROM petugas_shift");
$total_pages = ceil($total_res->fetch_assoc()['total'] / $limit);

$jadwal_res = $conn->query("SELECT ps.*, u.nama as nama_akun, pp.petugas1_nama, pp.petugas2_nama 
                            FROM petugas_shift ps 
                            LEFT JOIN users u ON ps.petugas1_id = u.id 
                            LEFT JOIN petugas_profiles pp ON ps.petugas1_id = pp.user_id 
                            ORDER BY ps.tanggal DESC LIMIT $limit OFFSET $offset");
$jadwal = [];
while ($row = $jadwal_res->fetch_assoc()) $jadwal[] = $row;

// ============================================
// HANDLE EXPORT PDF
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = "SELECT ps.*, u.nama as nama_akun, pp.petugas1_nama, pp.petugas2_nama 
              FROM petugas_shift ps 
              LEFT JOIN users u ON ps.petugas1_id = u.id 
              LEFT JOIN petugas_profiles pp ON ps.petugas1_id = pp.user_id 
              ORDER BY ps.tanggal ASC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        require_once PROJECT_ROOT . '/tcpdf/tcpdf.php';
        
        class MYPDF extends TCPDF {
            public function Header() {
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'MY SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
                $this->Line(10, 35, $this->getPageWidth() - 10, 35);
            }
            
            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/y H:i'), 0, 0, 'L');
                $this->Cell(0, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        }
        
        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('MY SCHOBANK');
        $pdf->SetTitle('Jadwal Petugas');
        $pdf->SetMargins(10, 40, 10);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();
        $pdf->Ln(5);
        
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'DAFTAR JADWAL PETUGAS BANK MINI', 0, 1, 'C');
        $pdf->Ln(5);
        
        $pageWidth = $pdf->getPageWidth() - 20;
        $colWidths = [
            'no' => 0.05 * $pageWidth,
            'tanggal' => 0.15 * $pageWidth,
            'petugas1' => 0.40 * $pageWidth,
            'petugas2' => 0.40 * $pageWidth
        ];
        
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($colWidths['no'], 6, 'No', 1, 0, 'C', true);
        $pdf->Cell($colWidths['tanggal'], 6, 'Tanggal', 1, 0, 'C', true);
        $pdf->Cell($colWidths['petugas1'], 6, 'Petugas 1 (Kelas 10)', 1, 0, 'C', true);
        $pdf->Cell($colWidths['petugas2'], 6, 'Petugas 2 (Kelas 11)', 1, 1, 'C', true);
        
        $pdf->SetFont('helvetica', '', 8);
        $no = 1;
        foreach ($data as $row) {
            $pdf->Cell($colWidths['no'], 6, $no++, 1, 0, 'C');
            $pdf->Cell($colWidths['tanggal'], 6, date('d/m/y', strtotime($row['tanggal'])), 1, 0, 'C');
            $pdf->Cell($colWidths['petugas1'], 6, $row['petugas1_nama'], 1, 0, 'L');
            $pdf->Cell($colWidths['petugas2'], 6, $row['petugas2_nama'], 1, 1, 'L');
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
    <title>Kelola Jadwal | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a; --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6; --bg-light: #f0f5ff;
            --text-primary: #333; --text-secondary: #666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.05);
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-primary); display: flex; min-height: 100vh; overflow-x: hidden; }
        
        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open { overflow: hidden; }
        body.sidebar-open::before {
            content: ''; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5); z-index: 998; backdrop-filter: blur(5px);
        }
        
        .main-content { flex: 1; margin-left: 280px; padding: 30px; max-width: calc(100% - 280px); position: relative; z-index: 1; }
        
        /* Banner */
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color)); 
            color: white; 
            padding: 30px; 
            border-radius: 8px; 
            margin-bottom: 30px; 
            box-shadow: var(--shadow-sm); 
            display: flex; 
            align-items: center; 
            gap: 20px; 
        }
        .welcome-banner h2 { font-size: 1.5rem; margin: 0; }
        .welcome-banner p { margin: 0; opacity: 0.9; }
        .menu-toggle { 
            display: none; 
            font-size: 1.5rem; 
            cursor: pointer; 
            align-self: center;
            margin-right: auto;
        }

        /* Form & Container */
        .form-card, .jadwal-list { background: white; border-radius: 8px; padding: 25px; box-shadow: var(--shadow-sm); margin-bottom: 30px; }
        .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .form-group { flex: 1; display: flex; flex-direction: column; gap: 8px; }
        label { font-weight: 500; font-size: 0.9rem; color: var(--text-secondary); }

        /* INPUT STYLING */
        input[type="text"], input[type="date"], select {
            width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 0.95rem; transition: all 0.3s; background: #fff;
            height: 48px;
        }
        input:focus, select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1); outline: none; }
        
        /* Date Picker Wrapper */
        .date-input-wrapper { position: relative; width: 100%; }
        
        /* Input Date Specifics */
        input[type="date"] {
            appearance: none; -webkit-appearance: none;
            position: relative; padding-right: 40px;
        }
        
        /* HIDE Native Calendar Icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 2;
        }
        
        /* Custom Icon */
        .calendar-icon {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--primary-color); font-size: 1.1rem; z-index: 1; pointer-events: none;
        }

        /* Button */
        .btn { background: var(--primary-color); color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; text-decoration: none; }
        .btn:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-cancel { background: #e2e8f0; color: #475569; }
        .btn-cancel:hover { background: #cbd5e1; }
        
        /* Table */
        .table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid #f1f5f9; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-weight: 600; color: var(--text-secondary); border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: var(--text-primary); }
        
        .action-buttons button { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; margin-right: 5px; color: white; }
        .btn-edit { background: var(--secondary-color); }
        .btn-delete { background: #ef4444; }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; max-width: 100%; }
            .menu-toggle { display: block; }
            .welcome-banner { 
                flex-direction: row; 
                align-items: center; 
                gap: 15px; 
                padding: 20px; 
            }
            .welcome-banner h2 { 
                font-size: 1.2rem;
                margin: 0; 
            }
            .welcome-banner p { 
                font-size: 0.9rem;
                margin: 0; 
                opacity: 0.9; 
            }
            .form-row { flex-direction: column; gap: 15px; }
        }

        /* SweetAlert Custom Fixes */
        .swal2-input { height: 48px !important; margin: 10px auto !important; }
        .swal-date-wrapper { position: relative; width: 80%; margin: 10px auto; }
        .swal-date-wrapper input { width: 100% !important; box-sizing: border-box !important; margin: 0 !important; }
        .swal-date-wrapper i { right: 15px; }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div>
                <h2>Kelola Data Jadwal Petugas</h2>
                <p style="opacity:0.9">Atur shift dan penugasan petugas bank mini</p>
            </div>
        </div>

        <div class="form-card">
            <form action="" method="POST" id="jadwalForm">
                <input type="hidden" name="token" value="<?= $token ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Tanggal Jadwal</label>
                        <div class="date-input-wrapper">
                            <input type="date" name="tanggal" value="<?= htmlspecialchars($tanggal ?: date('Y-m-d')) ?>" required min="<?= date('Y-m-d') ?>">
                            <i class="fas fa-calendar-day calendar-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Kode Petugas</label>
                        <select name="petugas_id" id="petugas_id" required>
                            <option value="">-- Pilih Akun Petugas --</option>
                            <?php foreach ($petugas_list as $p): ?>
                                <option value="<?= $p['id'] ?>" 
                                        data-p1="<?= htmlspecialchars($p['petugas1_nama']) ?>" 
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
                        <input type="text" id="p1" name="petugas1_nama" value="<?= htmlspecialchars($petugas1_nama) ?>" readonly placeholder="Otomatis terisi" style="background: #f8fafc;">
                    </div>
                    <div class="form-group">
                        <label>Petugas 2 (Kelas 11)</label>
                        <input type="text" id="p2" name="petugas2_nama" value="<?= htmlspecialchars($petugas2_nama) ?>" readonly placeholder="Otomatis terisi" style="background: #f8fafc;">
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

        <div class="jadwal-list">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3><i class="fas fa-clock"></i> Daftar Jadwal</h3>
                <a href="<?= BASE_URL ?>/pages/admin/kelola_jadwal.php?export=pdf" class="btn" target="_blank" style="padding: 8px 15px; font-size: 0.9rem;">
                    <i class="fas fa-print"></i> Cetak PDF
                </a>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th>Tanggal</th>
                            <th>Kode Akun</th>
                            <th>Petugas 1</th>
                            <th>Petugas 2</th>
                            <th width="15%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jadwal)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">Belum ada data jadwal.</td></tr>
                        <?php else: $no = $offset + 1; foreach ($jadwal as $row): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><span style="font-weight:500; color:var(--primary-color);"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></span></td>
                                <td><?= htmlspecialchars($row['nama_akun'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['petugas1_nama'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['petugas2_nama'] ?? '-') ?></td>
                                <td class="action-buttons">
                                    <button class="btn-edit" onclick="editJadwal(<?= $row['id'] ?>, '<?= $row['tanggal'] ?>', <?= $row['petugas1_id'] ?>)">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                    <button class="btn-delete" onclick="hapusJadwal(<?= $row['id'] ?>, '<?= date('d/m/Y', strtotime($row['tanggal'])) ?>')">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div style="margin-top:20px; display:flex; justify-content:center; gap:5px;">
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="btn" style="padding:8px 12px; <?= $i==$page ? 'background:var(--primary-dark);' : 'opacity:0.7;' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // FIXED SIDEBAR MOBILE BEHAVIOR
        // ============================================
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;
        const mainContent = document.getElementById('mainContent');

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
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Close sidebar on outside click
        document.addEventListener('click', function(e) {
            if (body.classList.contains('sidebar-open') && 
                !sidebar?.contains(e.target) && 
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Prevent body scroll when sidebar open (mobile)
        let scrollTop = 0;
        body.addEventListener('scroll', function() {
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
            selectPetugas.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                document.getElementById('p1').value = opt.getAttribute('data-p1') || '';
                document.getElementById('p2').value = opt.getAttribute('data-p2') || '';
            });
        }

        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        window.editJadwal = function(id, tgl, pid) {
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

        window.hapusJadwal = function(id, tgl) {
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