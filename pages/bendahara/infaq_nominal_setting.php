<?php
/**
 * Bendahara Infaq Nominal Setting - Adaptive Path Version (Normalized DB) - Redesigned
 * File: pages/bendahara/infaq_nominal_setting.php
 * Features: Modern layout with navbar matching dashboard
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages/bendahara' folder
if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: Search upward for includes/ folder (max 5 levels)
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

// Fallback
if (!$project_root) {
    $project_root = dirname(dirname($current_dir));
}

// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once INCLUDES_PATH . '/db_connection.php';

$username = $_SESSION['username'] ?? 'Bendahara';
$bendahara_id = $_SESSION['user_id'] ?? 0;
$nama_bendahara = $_SESSION['nama'] ?? 'Bendahara';
date_default_timezone_set('Asia/Jakarta');

$is_blocked = false;

// Pastikan role adalah bendahara
if ($_SESSION['role'] !== 'bendahara') {
    header("Location: /pages/login.php");
    exit;
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$message = '';
$message_type = ''; // success, error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil nominal dan hilangkan titik
    $nominal_str = str_replace('.', '', $_POST['nominal']);
    $nominal = filter_var($nominal_str, FILTER_VALIDATE_FLOAT);
    $keterangan = trim(htmlspecialchars(filter_input(INPUT_POST, 'keterangan', FILTER_DEFAULT), ENT_QUOTES, 'UTF-8'));

    if ($nominal !== false && $nominal > 0) {
        // Cek jika sudah ada record
        $query_check = "SELECT id FROM infaq_nominal_config LIMIT 1";
        $stmt_check = $conn->prepare($query_check);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            // Update existing
            $row = $result_check->fetch_assoc();
            $config_id = $row['id'];
            $query_update = "UPDATE infaq_nominal_config SET nominal_per_siswa = ?, keterangan = ? WHERE id = ?";
            $stmt_update = $conn->prepare($query_update);
            $stmt_update->bind_param("dsi", $nominal, $keterangan, $config_id);
            if ($stmt_update->execute()) {
                $message = 'Nominal infaq berhasil diperbarui.';
                $message_type = 'success';
            } else {
                $message = 'Gagal memperbarui nominal infaq: ' . $conn->error;
                $message_type = 'error';
            }
        } else {
            // Insert new
            $query_insert = "INSERT INTO infaq_nominal_config (nominal_per_siswa, keterangan) VALUES (?, ?)";
            $stmt_insert = $conn->prepare($query_insert);
            $stmt_insert->bind_param("ds", $nominal, $keterangan);
            if ($stmt_insert->execute()) {
                $message = 'Nominal infaq berhasil diset.';
                $message_type = 'success';
            } else {
                $message = 'Gagal menyet nominal infaq: ' . $conn->error;
                $message_type = 'error';
            }
        }
    } else {
        $message = 'Nominal harus berupa angka positif.';
        $message_type = 'error';
    }
}

// ============================================
// GET CURRENT CONFIG
// ============================================
$nominal_current = 0;
$keterangan_current = '';
$query_config = "SELECT nominal_per_siswa, keterangan FROM infaq_nominal_config LIMIT 1";
$stmt_config = $conn->prepare($query_config);
$stmt_config->execute();
$result_config = $stmt_config->get_result();
if ($config = $result_config->fetch_assoc()) {
    $nominal_current = $config['nominal_per_siswa'];
    $keterangan_current = $config['keterangan'];
}

// Format nominal untuk display
$nominal_formatted = number_format($nominal_current, 0, ',', '.');

// BASE URL
$protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts  = explode('/', trim(dirname($script_name), '/'));
$base_path   = in_array('schobank', $path_parts) ? '/schobank' : '';
$base_url    = $protocol . '://' . $host . $base_path;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <title>Setting Nominal Infaq | SchoBank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
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
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --radius-sm: 6px;
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 3rem;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        html { font-size:16px; }
        body {
            font-family: var(--font-sans);
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            min-height: 100vh;
        }
        /* NAVBAR */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #fff;
            border-bottom: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }
        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 var(--space-lg);
            display:flex;
            align-items:center;
            justify-content:space-between;
            height:64px;
        }
        .navbar-brand {
            font-size:1.25rem;
            font-weight:700;
            color:var(--primary);
            text-decoration:none;
            letter-spacing:.02em;
        }
        .navbar-toggle{
            display:none;
            background:none;
            border:none;
            font-size:1.5rem;
            color:var(--gray-700);
            cursor:pointer;
            padding:var(--space-xs);
        }
        .navbar-menu{
            display:flex;
            align-items:center;
            gap:var(--space-xs);
            list-style:none;
        }
        .navbar-menu a{
            display:flex;
            align-items:center;
            gap:var(--space-xs);
            padding:var(--space-xs) var(--space-md);
            color:var(--gray-600);
            text-decoration:none;
            font-size:0.9rem;
            font-weight:500;
            border-radius:var(--radius);
            transition:color .2s ease;
        }
        .navbar-menu a i{
            font-size:0.95rem;
        }
        .navbar-menu a:hover,
        .navbar-menu a.active{
            color:var(--primary);
        }
        .navbar-user{
            display:flex;
            align-items:center;
            gap:var(--space-md);
            position: relative;
        }
        .user-info{
            display:flex;
            align-items:center;
            gap:var(--space-sm);
            cursor: pointer;
            padding: var(--space-xs);
            border-radius: var(--radius);
            transition: background 0.2s ease;
        }
        .user-info:hover {
            background: var(--gray-100);
        }
        .user-avatar{
            width:36px;
            height:36px;
            border-radius:50%;
            background:var(--primary);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-weight:600;
            font-size:0.9rem;
        }
        .user-details{
            display:flex;
            flex-direction:column;
        }
        .user-name{
            font-size:0.875rem;
            font-weight:600;
            color:var(--gray-900);
        }
        .user-role{
            font-size:0.75rem;
            color:var(--gray-500);
        }

        /* Profile Dropdown Menu */
        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
        }
        .profile-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .dropdown-header {
            padding: var(--space-md);
            border-bottom: 1px solid var(--gray-200);
        }
        .dropdown-header-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        .dropdown-header-role {
            font-size: 0.8rem;
            color: var(--gray-500);
        }
        .dropdown-menu-items {
            padding: var(--space-xs);
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            color: var(--gray-700);
            text-decoration: none;
            border-radius: var(--radius);
            transition: all 0.2s ease;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .dropdown-item:hover {
            background: var(--gray-100);
            color: var(--primary);
        }
        .dropdown-item i {
            font-size: 1rem;
            width: 20px;
            text-align: center;
        }
        .dropdown-item.logout {
            color: var(--danger);
            border-top: 1px solid var(--gray-200);
            margin-top: var(--space-xs);
        }
        .dropdown-item.logout:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* MAIN */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--space-xl) var(--space-lg);
        }

        .page-header {
            margin-bottom: var(--space-xl);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: var(--space-xs);
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: var(--gray-600);
        }

        /* Section Card */
        .section-card {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: var(--space-xl);
            transition: all 0.3s ease;
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
        }

        .section-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: 1.25rem var(--space-lg);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .section-header.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .section-header.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .section-body {
            padding: var(--space-lg);
        }

        .form-group {
            margin-bottom: var(--space-lg);
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: var(--space-xs);
            display: block;
        }

        .form-control {
            width: 100%;
            padding: var(--space-sm) var(--space-md);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--gray-900);
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            padding: var(--space-sm) var(--space-xl);
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .navbar-menu {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .navbar-container {
                padding: 0 var(--space-md);
            }

            .navbar-toggle {
                display: block;
            }

            .navbar-menu {
                position: fixed;
                top: 64px;
                left: 0;
                right: 0;
                background: #fff;
                flex-direction: column;
                padding: var(--space-md);
                box-shadow: var(--shadow-lg);
                border-top: 1px solid var(--gray-200);
                max-height: 0;
                overflow: hidden;
                transition: max-height .3s ease;
            }

            .navbar-menu.active {
                display: flex;
                max-height: 400px;
            }

            .navbar-menu a {
                width: 100%;
                padding: var(--space-md);
            }

            .user-details {
                display: none;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .main-container {
                padding: var(--space-lg) var(--space-md);
            }

            .section-body {
                padding: 1rem;
            }

            .profile-dropdown {
                right: -10px;
                min-width: 200px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-card {
            animation: fadeIn 0.4s ease;
        }
    </style>
</head>
<body>
    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="navbar-brand">SchoBank</a>
            <button class="navbar-toggle" id="navbarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="navbar-menu" id="navbarMenu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="infaq_nominal_setting.php" class="active"><i class="fas fa-cog"></i> Set Nominal Infaq</a></li>
                <li><a href="infaq_siswa_status.php"><i class="fas fa-users"></i> Status Infaq Siswa</a></li>
                <li><a href="infaq_generate_tagihan.php"><i class="fas fa-plus-circle"></i> Buat Tagihan Infaq</a></li>
                <li><a href="infaq_laporan.php"><i class="fas fa-chart-line"></i> Laporan</a></li>
            </ul>
            <div class="navbar-user">
                <div class="user-info" id="userProfileBtn">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($nama_bendahara, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($nama_bendahara); ?></div>
                        <div class="user-role">Bendahara</div>
                    </div>
                </div>
                
                <!-- Profile Dropdown Menu -->
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-header-name"><?php echo htmlspecialchars($nama_bendahara); ?></div>
                        <div class="dropdown-header-role">Bendahara</div>
                    </div>
                    <div class="dropdown-menu-items">
                        <a href="pengaturan.php" class="dropdown-item">
                            <i class="fas fa-wrench"></i>
                            <span>Pengaturan</span>
                        </a>
                        <div class="dropdown-item logout" onclick="logout()">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Keluar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-cog"></i> Setting Nominal Infaq</h1>
            <p class="page-subtitle">Atur nominal default infaq per siswa untuk tagihan bulanan</p>
        </div>

        <!-- Form Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-edit"></i>
                <h5>Form Pengaturan Nominal</h5>
            </div>
            <div class="section-body">
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="nominal" class="form-label">Nominal Per Siswa (Rp)</label>
                        <input type="text" class="form-control" id="nominal" name="nominal" value="<?php echo htmlspecialchars($nominal_formatted); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="keterangan" class="form-label">Keterangan (Opsional)</label>
                        <textarea class="form-control" id="keterangan" name="keterangan" rows="3"><?php echo htmlspecialchars($keterangan_current); ?></textarea>
                    </div>
                    <button type="submit" class="btn-primary">Simpan Perubahan</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Navbar Toggle
        $(function() {
            $('#navbarToggle').on('click', function() {
                $('#navbarMenu').toggleClass('active');
                $(this).find('i').toggleClass('fa-bars fa-times');
            });
            
            // Close mobile menu when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.navbar-container').length) {
                    $('#navbarMenu').removeClass('active');
                    $('#navbarToggle i').removeClass('fa-times').addClass('fa-bars');
                }
            });
        });

        // Profile Dropdown Toggle
        $(function() {
            const userProfileBtn = $('#userProfileBtn');
            const profileDropdown = $('#profileDropdown');

            // Toggle dropdown when clicking profile button
            userProfileBtn.on('click', function(e) {
                e.stopPropagation();
                $('.profile-dropdown').removeClass('active');
                userProfileBtn.removeClass('active');
                
                $(this).toggleClass('active');
                profileDropdown.toggleClass('active');
            });

            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.navbar-user').length) {
                    userProfileBtn.removeClass('active');
                    profileDropdown.removeClass('active');
                }
            });

            // Prevent dropdown from closing when clicking inside it
            profileDropdown.on('click', function(e) {
                e.stopPropagation();
            });
        });

        // Logout Function
        function logout() {
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Yakin ingin keluar?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Keluar',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
        }

        // Rupiah Format Function
        function formatRupiah(angka) {
            let number_string = angka.replace(/[^,\d]/g, '').toString(),
                split = number_string.split(','),
                sisa = split[0].length % 3,
                rupiah = split[0].substr(0, sisa),
                ribuan = split[0].substr(sisa).match(/\d{3}/gi);

            if (ribuan) {
                separator = sisa ? '.' : '';
                rupiah += separator + ribuan.join('.');
            }

            rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
            return rupiah;
        }

        // Apply format on input
        $(function() {
            const nominalInput = $('#nominal');
            nominalInput.on('keyup', function() {
                let val = $(this).val();
                $(this).val(formatRupiah(val));
            });
        });

        // Show Message if any
        <?php if ($message): ?>
        Swal.fire({
            title: '<?php echo $message_type === 'success' ? 'Berhasil!' : 'Error!'; ?>',
            text: '<?php echo htmlspecialchars($message); ?>',
            icon: '<?php echo $message_type; ?>',
            confirmButtonColor: '<?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>'
        });
        <?php endif; ?>
    </script>
</body>
</html>