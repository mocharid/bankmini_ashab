<?php
/**
 * Halaman Buat Tagihan Pembayaran
 * File: pages/bendahara/tagihan_pembayaran.php
 * 
 * Fitur:
 * - Input Nama Tagihan (Text Input - Auto Find/Create)
 * - Input Nominal
 * - Input Jatuh Tempo
 * - Pilih Siswa (Pilih / Per Kelas / Semua / Semua Kecuali)
 */

// ============================================
// ADAPTIVE PATH DETECTION & INIT
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} else {
    $project_root = dirname(dirname($current_dir)); // Fallback
}

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

// ================= BASE URL =================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script));
    $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL'))
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
if (!defined('ASSETS_URL'))
    define('ASSETS_URL', BASE_URL . '/assets');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Cek Role Bendahara
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bendahara') {
    header("Location: ../../login.php");
    exit;
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_tagihan = trim($_POST['nama_tagihan'] ?? '');
    $nominal = str_replace(['.', ','], '', $_POST['nominal'] ?? '0');
    $jatuh_tempo = $_POST['jatuh_tempo'] ?? null;

    $target_siswa = $_POST['target_siswa'] ?? 'selected'; // selected / class / all / except
    $siswa_ids = $_POST['siswa_ids'] ?? []; // For 'selected'
    $class_ids = $_POST['class_ids'] ?? []; // For 'class'
    $excluded_ids = $_POST['excluded_ids'] ?? []; // For 'except'

    $conn->begin_transaction();

    try {
        // 1. VALIDASI & PROSES ITEM
        if (empty($nama_tagihan))
            throw new Exception("Nama tagihan wajib diisi.");

        // Generate Kode Item Unik from Name
        $kode_item = strtoupper(str_replace(' ', '_', $nama_tagihan));

        // Cek apakah item sudah ada
        $check_stmt = $conn->prepare("SELECT id FROM pembayaran_item WHERE kode_item = ? OR nama_item = ? LIMIT 1");
        $check_stmt->bind_param("ss", $kode_item, $nama_tagihan);
        $check_stmt->execute();
        $res_check = $check_stmt->get_result();

        if ($res_check->num_rows > 0) {
            throw new Exception("Nama tagihan pembayaran sudah ada. Silahkan gunakan nama lain.");
        }

        // Create New Item
        $stmt_new_item = $conn->prepare("INSERT INTO pembayaran_item (kode_item, nama_item, nominal_default, is_aktif, is_wajib) VALUES (?, ?, ?, 1, 1)");
        $stmt_new_item->bind_param("ssd", $kode_item, $nama_tagihan, $nominal);
        $stmt_new_item->execute();
        $item_id = $conn->insert_id;

        // 2. VALIDASI INPUT LAIN
        if ($nominal <= 0)
            throw new Exception("Nominal harus lebih dari 0.");
        if (empty($jatuh_tempo))
            throw new Exception("Tanggal jatuh tempo wajib diisi.");

        // 3. TENTUKAN DAFTAR SISWA
        $final_siswa_ids = [];

        if ($target_siswa === 'all') {
            // ALL STUDENTS
            $res_siswa = $conn->query("SELECT id FROM users WHERE role = 'siswa'");
            while ($row = $res_siswa->fetch_assoc()) {
                $final_siswa_ids[] = $row['id'];
            }

        } elseif ($target_siswa === 'class') {
            // BASED ON CLASS
            if (empty($class_ids))
                throw new Exception("Pilih minimal satu kelas.");

            // Convert array to comma-separated string for IN clause
            $ids_string = implode(',', array_map('intval', $class_ids));

            $sql_class = "SELECT user_id FROM siswa_profiles WHERE kelas_id IN ($ids_string)";
            $res_class = $conn->query($sql_class);
            while ($row = $res_class->fetch_assoc()) {
                $final_siswa_ids[] = $row['user_id'];
            }

            if (empty($final_siswa_ids))
                throw new Exception("Tidak ada siswa ditemukan di kelas yang dipilih.");

        } elseif ($target_siswa === 'except') {
            // ALL EXCEPT
            $all_students = [];
            $res_siswa = $conn->query("SELECT id FROM users WHERE role = 'siswa'");
            while ($row = $res_siswa->fetch_assoc()) {
                $all_students[] = $row['id'];
            }

            $final_siswa_ids = array_diff($all_students, $excluded_ids);
            if (empty($final_siswa_ids))
                throw new Exception("Tidak ada siswa yang dipilih (Semua dikecualikan).");

        } else {
            // SPECIFIC STUDENTS
            if (empty($siswa_ids))
                throw new Exception("Pilih minimal satu siswa.");
            $final_siswa_ids = $siswa_ids;
        }

        // 4. GENERATE TAGIHAN
        $count_success = 0;
        $tanggal_buat = date('Y-m-d');

        $sql_insert = "INSERT INTO pembayaran_tagihan 
                      (no_tagihan, item_id, siswa_id, nominal, status, tanggal_buat, tanggal_jatuh_tempo) 
                      VALUES (?, ?, ?, ?, 'belum_bayar', ?, ?)
                      ON DUPLICATE KEY UPDATE 
                        nominal = VALUES(nominal), 
                        tanggal_jatuh_tempo = VALUES(tanggal_jatuh_tempo),
                        status = 'belum_bayar',
                        updated_at = NOW()";

        $stmt_insert = $conn->prepare($sql_insert);

        // Create notification message
        $notif_message = "Ada Tagihan baru buat kamu nih, segera bayar tepat waktu ya. Klik notifikasi ini untuk membayar.";

        foreach ($final_siswa_ids as $sid) {
            $no_tagihan = 'TAG/' . date('Ymd') . '/' . str_pad($item_id, 3, '0', STR_PAD_LEFT) . '/' . str_pad($sid, 4, '0', STR_PAD_LEFT);

            $stmt_insert->bind_param("siidss", $no_tagihan, $item_id, $sid, $nominal, $tanggal_buat, $jatuh_tempo);

            if ($stmt_insert->execute()) {
                $count_success++;
                // Update status payment status table
                $conn->query("INSERT INTO pembayaran_siswa_status (item_id, siswa_id, status) VALUES ($item_id, $sid, 'wajib') ON DUPLICATE KEY UPDATE status='wajib'");

                // Insert notification for each student - use direct INSERT to avoid bind_param reference issues
                $student_user_id = intval($sid);
                $escaped_message = $conn->real_escape_string($notif_message);
                $notif_sql = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES ($student_user_id, '$escaped_message', 0, NOW())";
                if (!$conn->query($notif_sql)) {
                    error_log("Failed to insert notification for siswa_id: $student_user_id - Error: " . $conn->error);
                }
            }
        }
        
        // Close prepared statement
        $stmt_insert->close();

        $conn->commit();
        $success_msg = "Berhasil membuat tagihan untuk $count_success siswa.";

    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}

// ============================================
// LOAD DATA
// ============================================
// 1. Load Siswa for Select2
$students = $conn->query("SELECT u.id, u.nama, k.nama_kelas, j.nama_jurusan 
                          FROM users u 
                          LEFT JOIN siswa_profiles sd ON u.id = sd.user_id 
                          LEFT JOIN kelas k ON sd.kelas_id = k.id 
                          LEFT JOIN jurusan j ON k.jurusan_id = j.id
                          WHERE u.role = 'siswa' 
                          ORDER BY k.nama_kelas ASC, u.nama ASC");

// 2. Load Classes for Class Selection
$classes = $conn->query("SELECT k.id, k.nama_kelas, tk.nama_tingkatan 
                         FROM kelas k
                         LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                         ORDER BY tk.nama_tingkatan ASC, k.nama_kelas ASC");
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Tagihan - KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">

    <!-- CSS Dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        :root {
            --primary: #0c4a6e;
            --primary-light: #0369a1;
            --primary-dark: #082f49;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --radius-sm: 6px;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }

        /* Page Title Section */
        .page-title-section {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            padding: 1.5rem 0;
            margin: -2rem -2rem 1.5rem -2rem;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-title-content {
            flex: 1;
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

        .page-hamburger:active {
            transform: scale(0.95);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--gray-600);
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: linear-gradient(to right, var(--gray-50), white);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
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

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--gray-700);
        }

        .form-label .required {
            color: var(--danger);
            margin-left: 2px;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s ease;
            background: white;
        }

        .form-control:hover {
            border-color: var(--gray-300);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(12, 74, 110, 0.1);
        }

        .form-control::placeholder {
            color: var(--gray-400);
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.35rem;
        }

        .form-hint i {
            color: var(--primary-light);
            margin-top: 2px;
        }

        /* Form Row */
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        /* Radio Options */
        .radio-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .radio-option {
            position: relative;
        }

        .radio-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .radio-option label {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }

        .radio-option label:hover {
            border-color: var(--primary-light);
            background: var(--gray-50);
        }

        .radio-option input[type="radio"]:checked+label {
            border-color: var(--primary);
            background: linear-gradient(to right, rgba(12, 74, 110, 0.05), rgba(3, 105, 161, 0.05));
            box-shadow: 0 0 0 4px rgba(12, 74, 110, 0.1);
        }

        .radio-option .radio-icon {
            width: 36px;
            height: 36px;
            background: var(--gray-100);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: all 0.2s ease;
        }

        .radio-option input[type="radio"]:checked+label .radio-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }

        .radio-option .radio-text {
            flex: 1;
        }

        .radio-option .radio-text strong {
            display: block;
            font-size: 0.9rem;
            color: var(--gray-800);
            font-weight: 600;
        }

        .radio-option .radio-text span {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        /* Student Select Wrapper */
        .student-select-wrapper {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 1px dashed var(--gray-300);
        }

        /* Info Box */
        .info-box {
            padding: 1rem 1.25rem;
            background: linear-gradient(to right, #eff6ff, #dbeafe);
            color: #1e40af;
            border-radius: var(--radius);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .info-box i {
            font-size: 1.1rem;
        }

        .info-box.success {
            background: linear-gradient(to right, #ecfdf5, #d1fae5);
            color: #065f46;
        }

        .info-box.warning {
            background: linear-gradient(to right, #fef3c7, #fde68a);
            color: #92400e;
        }

        /* Select2 Styling */
        .select2-container .select2-selection--multiple {
            min-height: 48px !important;
            padding: 0.5rem;
            border: 2px solid var(--gray-200) !important;
            border-radius: var(--radius) !important;
            background: white;
        }

        .select2-container--default .select2-selection--multiple:focus,
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 4px rgba(12, 74, 110, 0.1);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 0.5rem;
        }

        .select2-dropdown {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
        }

        .select2-results__option--highlighted[aria-selected] {
            background: var(--primary) !important;
        }

        /* Submit Section */
        .submit-section {
            background: linear-gradient(to right, var(--gray-50), white);
            padding: 1.5rem;
            border-top: 1px solid var(--gray-100);
            display: flex;
            justify-content: flex-start;
            gap: 1rem;
        }

        .btn {
            padding: 0.875rem 1.75rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(12, 74, 110, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(12, 74, 110, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: white;
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
        }

        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 75px;
            }

            .student-select-wrapper {
                max-height: 400px;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-section {
                margin: -1rem -1rem 1rem -1rem;
                padding: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .radio-options {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.25rem;
            }
        }

        @media (max-width: 640px) {
            .page-title-section {
                margin: -1rem -1rem 1rem -1rem;
                padding: 0.75rem 1rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .card-body {
                padding: 1rem;
            }

            .submit-section {
                flex-direction: column;
                padding: 1rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .student-select-wrapper {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
                padding-top: 70px;
            }

            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .card-header {
                padding: 1rem;
            }

            .form-control {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .radio-option label {
                padding: 0.75rem 1rem;
            }
        }

        /* Hide Hamburger Button as requested */
        .mobile-navbar {
            display: none !important;
        }

        @media (max-width: 1024px) {
            .main-content {
                padding-top: 1rem !important;
            }
        }

        /* Hide Hamburger Button as requested */
        .mobile-navbar {
            display: none !important;
        }

        @media (max-width: 1024px) {
            .main-content {
                padding-top: 1rem !important;
            }
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/sidebar_bendahara.php'; ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Buat Tagihan Pembayaran</h1>
                <p class="page-subtitle">Kelola dan buat tagihan pembayaran untuk siswa</p>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: '<?php echo addslashes($success_msg); ?>',
                    confirmButtonColor: '#64748b'
                });
            </script>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '<?php echo addslashes($error_msg); ?>',
                    confirmButtonColor: '#ef4444'
                });
            </script>
        <?php endif; ?>

        <form action="" method="POST" id="billForm">
            <!-- Card 1: Detail Tagihan -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="card-header-text">
                        <h3>Detail Tagihan Pembayaran</h3>
                        <p>Masukkan informasi detail tagihan pembayaran yang akan dibuat</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Nama Tagihan Pembayaran <span class="required">*</span></label>
                        <input type="text" name="nama_tagihan" id="nama_tagihan" class="form-control"
                            placeholder="Contoh: SPP Januari 2024, Uang Gedung, Seragam, dll" required
                            oninput="capitalizeWords(this)">
                        <div class="form-hint">
                            <i class="fas fa-info-circle"></i>
                            <span>Jika nama tagihan pembayaran sama dengan yang sudah ada, sistem akan otomatis
                                menggunakan Item ID yang sudah tersimpan.</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nominal (Rp) <span class="required">*</span></label>
                            <input type="text" name="nominal" id="nominal" class="form-control"
                                placeholder="Masukkan nominal tagihan pembayaran" onkeyup="formatRupiah(this)" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tanggal Jatuh Tempo <span class="required">*</span></label>
                            <input type="date" name="jatuh_tempo" class="form-control" required
                                value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Target Siswa -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-header-text">
                        <h3>Target Siswa</h3>
                        <p>Pilih siswa yang akan menerima tagihan pembayaran ini</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Metode Pemilihan Siswa</label>
                        <select name="target_siswa" id="target_siswa" class="form-control"
                            onchange="toggleStudentMode()" required>
                            <option value="" disabled selected>-- Pilih Tujuan Siswa --</option>
                            <option value="selected">Pilih Siswa - Pilih siswa tertentu secara manual</option>
                            <option value="class">Berdasarkan Kelas - Semua siswa di kelas terpilih</option>
                            <option value="all">Semua Siswa - Tagihan untuk seluruh siswa aktif</option>
                            <option value="except">Semua Kecuali... - Kecualikan siswa tertentu</option>
                        </select>
                    </div>

                    <!-- Mode: Select Specific Students -->
                    <div id="wrapper_selected" class="student-select-wrapper" style="display: none;">
                        <label class="form-label"><i class="fas fa-search"></i> Cari & Pilih Siswa</label>
                        <select name="siswa_ids[]" class="form-select select2-multiple" multiple="multiple"
                            style="width: 100%;">
                            <?php
                            $students->data_seek(0);
                            while ($student = $students->fetch_assoc()):
                                ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['nama']); ?>
                                    (
                                    <?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?>)
                                </option> <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Mode: Select Class -->
                    <div id="wrapper_class" class="student-select-wrapper" style="display: none;">
                        <label class="form-label"><i class="fas fa-chalkboard-teacher"></i> Pilih Kelas</label>
                        <select name="class_ids[]" class="form-select select2-multiple" multiple="multiple"
                            style="width: 100%;">
                            <?php while ($cls = $classes->fetch_assoc()): ?>
                                <option value="<?php echo $cls['id']; ?>">
                                    <?php echo htmlspecialchars($cls['nama_tingkatan'] . ' ' . $cls['nama_kelas']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            <span>Tagihan akan dibuat untuk <strong>semua siswa</strong> di kelas yang dipilih.</span>
                        </div>
                    </div>

                    <!-- Mode: Select All Except -->
                    <div id="wrapper_except" class="student-select-wrapper" style="display: none;">
                        <label class="form-label" style="color: var(--danger);"><i class="fas fa-user-times"></i> Pilih
                            Siswa yang DIKECUALIKAN</label>
                        <select name="excluded_ids[]" class="form-select select2-multiple" multiple="multiple"
                            style="width: 100%;">
                            <?php
                            $students->data_seek(0);
                            while ($student = $students->fetch_assoc()):
                                ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['nama']); ?>
                                    (
                                    <?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?>)
                                </option> <?php endwhile; ?>
                        </select>
                        <div class="info-box warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Tagihan akan dibuat untuk semua siswa <strong>KECUALI</strong> yang dipilih di
                                atas.</span>
                        </div>
                    </div>

                    <!-- Mode: All Students -->
                    <div id="wrapper_all" style="display: none; margin-top: 1.5rem;">
                        <div class="info-box success">
                            <i class="fas fa-check-circle"></i>
                            <span>Tagihan akan dibuat untuk <strong>SEMUA SISWA AKTIF</strong> dalam sistem.</span>
                        </div>
                    </div>
                </div>

                <!-- Submit Section -->
                <div class="submit-section">
                    <button type="button" id="btnBuatTagihan" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Buat Tagihan
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        $(document).ready(function () {
            // Init Select2
            $('.select2-multiple').select2({
                placeholder: "Ketik untuk mencari...",
                allowClear: true
            });
        });

        // Toggle Student Mode
        function toggleStudentMode() {
            const target = $('#target_siswa').val();

            // Hide all wrappers with animation
            $('.student-select-wrapper, #wrapper_all').slideUp(200);

            // Show selected wrapper
            setTimeout(() => {
                if (target === 'selected') {
                    $('#wrapper_selected').slideDown(300);
                } else if (target === 'class') {
                    $('#wrapper_class').slideDown(300);
                } else if (target === 'except') {
                    $('#wrapper_except').slideDown(300);
                } else if (target === 'all') {
                    $('#wrapper_all').slideDown(300);
                }
            }, 200);
        }

        // Auto-capitalize first letter of each word
        function capitalizeWords(input) {
            let cursorPos = input.selectionStart;
            let value = input.value;
            let words = value.split(' ');
            let capitalizedWords = words.map(function (word) {
                if (word.length > 0) {
                    return word.charAt(0).toUpperCase() + word.slice(1);
                }
                return word;
            });
            input.value = capitalizedWords.join(' ');
            input.setSelectionRange(cursorPos, cursorPos);
        }

        // Format Rupiah Input
        function formatRupiah(input) {
            let value = input.value.replace(/\D/g, '');
            input.value = new Intl.NumberFormat('id-ID').format(value);
        }

        // Konfirmasi sebelum submit
        $('#btnBuatTagihan').on('click', function () {
            Swal.fire({
                title: 'Konfirmasi Buat Tagihan',
                text: 'Apakah Anda yakin ingin membuat tagihan pembayaran ini?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#64748b',
                cancelButtonColor: '#ef4444',
                confirmButtonText: '<i class="fas fa-check"></i> Ya, Buat Tagihan',
                cancelButtonText: '<i class="fas fa-times"></i> Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    $('form').submit();
                }
            });
        });
    </script>
    <script>
        // Page Hamburger Button functionality
        document.addEventListener('DOMContentLoaded', function () {
            var pageHamburgerBtn = document.getElementById('pageHamburgerBtn');

            if (pageHamburgerBtn) {
                pageHamburgerBtn.addEventListener('click', function () {
                    var sidebar = document.getElementById('sidebar');
                    var overlay = document.getElementById('sidebarOverlay');

                    if (sidebar && overlay) {
                        sidebar.classList.add('active');
                        overlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                });
            }
        });
    </script>
</body>

</html>