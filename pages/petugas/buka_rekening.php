<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/session_validator.php';

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to petugas only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai petugas terlebih dahulu!'));
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Fetch jurusan data
$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);
if (!$result_jurusan) {
    error_log("Error fetching jurusan: " . $conn->error);
    die("Terjadi kesalahan saat mengambil data jurusan.");
}

// Fetch tingkatan kelas data
$query_tingkatan = "SELECT * FROM tingkatan_kelas";
$result_tingkatan = $conn->query($query_tingkatan);
if (!$result_tingkatan) {
    error_log("Error fetching tingkatan kelas: " . $conn->error);
    die("Terjadi kesalahan saat mengambil data tingkatan kelas.");
}

// Handle errors from URL for form display
$errors = [];
if (isset($_GET['error'])) {
    $errors['general'] = urldecode($_GET['error']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Buka Rekening | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --success-color: #10b981;
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
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            touch-action: pan-y;
        }
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
        }
        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            transition: margin-left 0.3s ease;
        }
        
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }
        
        /* Welcome Banner - Updated */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .welcome-banner .content {
            flex: 1;
        }
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.8;
        }
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }
        
        .form-card {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
            position: relative;
        }
        .form-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
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
            position: relative;
        }
        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        input[type="text"],
        input[type="email"],
        input[type="date"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9rem;
            user-select: text;
            -webkit-user-select: text;
            transition: var(--transition);
        }
        
        /* NO uppercase placeholder for nama input */
        input[type="text"]::placeholder,
        input[type="email"]::placeholder {
            text-transform: none;
        }
        
        input[type="date"] {
            padding-right: 40px;
            background-color: #fff;
            cursor: pointer;
            text-transform: none;
        }
        
        input[type="date"]:invalid {
            color: #9ca3af;
        }
        input[type="date"]::-webkit-datelist-edit {
            color: #1f2937;
        }
        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }
        
        input[type="date"]::-webkit-calendar-picker-indicator {
            background: transparent;
            color: transparent;
            width: 20px;
            height: 20px;
            border: none;
            cursor: pointer;
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.6;
        }
        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 0.8;
        }
        
        input[type="date"] {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="%23666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>');
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 20px;
        }
        
        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            cursor: pointer;
            padding-right: 40px;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }
        input:disabled {
            background-color: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }
        .form-row {
            display: flex;
            gap: 20px;
            width: 100%;
        }
        .form-row .form-group {
            flex: 1;
        }
        .form-row .form-group.wide {
            flex: 2;
        }
        .error-message {
            color: var(--danger-color);
            font-size: 0.85rem;
            margin-top: 4px;
            display: none;
        }
        .error-message.show {
            display: block;
        }
        .pin-match-indicator {
            font-size: 0.8rem;
            margin-top: 4px;
            font-weight: 500;
        }
        .pin-match-indicator.match {
            color: var(--success-color);
        }
        .pin-match-indicator.mismatch {
            color: var(--danger-color);
        }
        .email-note {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-style: italic;
            margin-top: 4px;
        }
        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 200px;
            margin: 0 auto;
            transition: var(--transition);
        }
        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
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
            font-size: 0.9rem;
            position: absolute;
            animation: spin 1.5s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            
            .main-content {
                margin-left: 0;
                max-width: 100%;
                padding: 15px;
            }
            
            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                align-items: center;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.4rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }
            
            .form-card {
                padding: 20px;
                border-radius: 5px;
            }
            .form-row {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                max-width: 300px;
            }
            input[type="date"] {
                padding-right: 45px;
            }
        }
        
        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.8vw, 1.3rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }
            
            .form-card {
                padding: 15px;
            }
            input, select {
                min-height: 44px;
                font-size: 16px;
            }
        }
        
        .swal2-input {
            width: 100% !important;
            max-width: 400px !important;
            padding: 12px 16px !important;
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
            font-size: 1rem !important;
            margin: 10px auto !important;
            box-sizing: border-box !important;
            text-align: left !important;
        }
        .swal2-input:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15) !important;
        }
        .swal2-popup .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
        }
        .swal2-html-container {
            font-size: 1rem !important;
            margin: 15px 0 !important;
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_petugas.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2><i class="fas fa-user-plus"></i> Buka Rekening Baru</h2>
                <p>Form Pembukaan Rekening MY SCHOBANK</p>
            </div>
        </div>
        <div class="form-card">
            <form action="proses_buka_rekening.php" method="POST" id="nasabahForm">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nama">Nama Lengkap <span style="color: red;">*</span></label>
                        <input type="text" id="nama" name="nama" required
                               value="<?php echo htmlspecialchars($_POST['nama'] ?? ($_GET['nama'] ?? '')); ?>"
                               placeholder="Masukkan nama lengkap"
                               aria-invalid="<?php echo isset($errors['nama']) ? 'true' : 'false'; ?>">
                        <span class="error-message <?php echo isset($errors['nama']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['nama'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="tipe_rekening">Jenis Rekening <span style="color: red;">*</span></label>
                        <select id="tipe_rekening" name="tipe_rekening" required
                                aria-invalid="<?php echo isset($errors['tipe_rekening']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Jenis Rekening</option>
                            <option value="fisik" <?php echo (isset($_POST['tipe_rekening']) && $_POST['tipe_rekening'] === 'fisik') || (isset($_GET['tipe_rekening']) && $_GET['tipe_rekening'] === 'fisik') ? 'selected' : ''; ?>>Fisik (Offline)</option>
                            <option value="digital" <?php echo (isset($_POST['tipe_rekening']) && $_POST['tipe_rekening'] === 'digital') || (isset($_GET['tipe_rekening']) && $_GET['tipe_rekening'] === 'digital') ? 'selected' : ''; ?>>Digital (Online)</option>
                        </select>
                        <span class="error-message <?php echo isset($errors['tipe_rekening']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['tipe_rekening'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ($_GET['email'] ?? '')); ?>"
                               placeholder="Masukkan email valid"
                               aria-invalid="<?php echo isset($errors['email']) ? 'true' : 'false'; ?>">
                        <span class="email-note">Wajib untuk rekening digital</span>
                        <span class="error-message <?php echo isset($errors['email']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['email'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="jenis_kelamin">Jenis Kelamin</label>
                        <select id="jenis_kelamin" name="jenis_kelamin"
                                aria-invalid="<?php echo isset($errors['jenis_kelamin']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Jenis Kelamin</option>
                            <option value="L" <?php echo (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] === 'L') || (isset($_GET['jenis_kelamin']) && $_GET['jenis_kelamin'] === 'L') ? 'selected' : ''; ?>>Laki-laki</option>
                            <option value="P" <?php echo (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] === 'P') || (isset($_GET['jenis_kelamin']) && $_GET['jenis_kelamin'] === 'P') ? 'selected' : ''; ?>>Perempuan</option>
                        </select>
                        <span class="error-message <?php echo isset($errors['jenis_kelamin']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['jenis_kelamin'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir"
                               value="<?php echo htmlspecialchars($_POST['tanggal_lahir'] ?? ($_GET['tanggal_lahir'] ?? '')); ?>"
                               placeholder="Pilih tanggal lahir"
                               aria-invalid="<?php echo isset($errors['tanggal_lahir']) ? 'true' : 'false'; ?>">
                        <span class="error-message <?php echo isset($errors['tanggal_lahir']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['tanggal_lahir'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="alamat_lengkap">Alamat Lengkap</label>
                        <input type="text" id="alamat_lengkap" name="alamat_lengkap"
                               value="<?php echo htmlspecialchars($_POST['alamat_lengkap'] ?? ($_GET['alamat_lengkap'] ?? '')); ?>"
                               placeholder="Masukkan alamat lengkap jika ada">
                        <span class="error-message <?php echo isset($errors['alamat_lengkap']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['alamat_lengkap'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="nis_nisn">NIS/NISN (Opsional)</label>
                        <input type="text" id="nis_nisn" name="nis_nisn"
                               value="<?php echo htmlspecialchars($_POST['nis_nisn'] ?? ($_GET['nis_nisn'] ?? '')); ?>"
                               placeholder="Masukkan NIS atau NISN jika ada">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="jurusan_id">Jurusan <span style="color: red;">*</span></label>
                        <select id="jurusan_id" name="jurusan_id" required onchange="updateKelas()"
                                aria-invalid="<?php echo isset($errors['jurusan_id']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Jurusan</option>
                            <?php
                            if ($result_jurusan && $result_jurusan->num_rows > 0) {
                                $result_jurusan->data_seek(0);
                                while ($row = $result_jurusan->fetch_assoc()):
                            ?>
                                <option value="<?php echo $row['id']; ?>"
                                    <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) || (isset($_GET['jurusan_id']) && $_GET['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                </option>
                            <?php endwhile; ?>
                            <?php } else { ?>
                                <option value="">Tidak ada jurusan tersedia</option>
                            <?php } ?>
                        </select>
                        <span class="error-message <?php echo isset($errors['jurusan_id']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['jurusan_id'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group wide">
                        <label for="tingkatan_kelas_id">Tingkatan Kelas <span style="color: red;">*</span></label>
                        <select id="tingkatan_kelas_id" name="tingkatan_kelas_id" required onchange="updateKelas()"
                                aria-invalid="<?php echo isset($errors['tingkatan_kelas_id']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Tingkatan Kelas</option>
                            <?php
                            if ($result_tingkatan && $result_tingkatan->num_rows > 0) {
                                $result_tingkatan->data_seek(0);
                                while ($row = $result_tingkatan->fetch_assoc()):
                            ?>
                                <option value="<?php echo $row['id']; ?>"
                                    <?php echo (isset($_POST['tingkatan_kelas_id']) && $_POST['tingkatan_kelas_id'] == $row['id']) || (isset($_GET['tingkatan_kelas_id']) && $_GET['tingkatan_kelas_id'] == $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                                </option>
                            <?php endwhile; ?>
                            <?php } else { ?>
                                <option value="">Tidak ada tingkatan kelas tersedia</option>
                            <?php } ?>
                        </select>
                        <span class="error-message <?php echo isset($errors['tingkatan_kelas_id']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['tingkatan_kelas_id'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="kelas_id">Kelas <span style="color: red;">*</span></label>
                        <select id="kelas_id" name="kelas_id" required
                                aria-invalid="<?php echo isset($errors['kelas_id']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Kelas</option>
                        </select>
                        <span id="kelas_id-error" class="error-message <?php echo isset($errors['kelas_id']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['kelas_id'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <div class="form-row" id="pin-row" style="display: none;">
                    <div class="form-group">
                        <label for="pin">PIN (6 Digit Angka)</label>
                        <input type="password" id="pin" name="pin" maxlength="6"
                               value="<?php echo htmlspecialchars($_POST['pin'] ?? ($_GET['pin'] ?? '')); ?>"
                               placeholder="Masukkan PIN 6 digit"
                               aria-invalid="<?php echo isset($errors['pin']) ? 'true' : 'false'; ?>">
                        <span class="error-message <?php echo isset($errors['pin']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['pin'] ?? ''); ?>
                        </span>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Konfirmasi PIN</label>
                        <input type="password" id="confirm_pin" name="confirm_pin" maxlength="6"
                               value="<?php echo htmlspecialchars($_POST['confirm_pin'] ?? ($_GET['confirm_pin'] ?? '')); ?>"
                               placeholder="Konfirmasi PIN 6 digit"
                               aria-invalid="<?php echo isset($errors['confirm_pin']) ? 'true' : 'false'; ?>">
                        <span id="pin-match-indicator" class="pin-match-indicator"></span>
                        <span class="error-message <?php echo isset($errors['confirm_pin']) ? 'show' : ''; ?>'" id="confirm-pin-error">
                            <?php echo htmlspecialchars($errors['confirm_pin'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <button type="submit" id="submit-btn" class="btn">
                    <span class="btn-content"><i class="fas fa-plus-circle"></i> Tambah</span>
                </button>
            </form>
        </div>
    </div>
    <script>
        // Function to convert to Title Case (only first letter of each word capitalized) - REAL TIME
        function toTitleCase(str) {
            return str.replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            }).replace(/\B\w/g, function(char) {
                return char.toLowerCase();
            });
        }
        
        function updateKelas() {
            const jurusanId = document.getElementById('jurusan_id').value;
            const tingkatanId = document.getElementById('tingkatan_kelas_id').value;
            const kelasSelect = document.getElementById('kelas_id');
            const kelasError = document.getElementById('kelas_id-error');
            if (!jurusanId || !tingkatanId) {
                kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                kelasError.textContent = '';
                kelasError.classList.remove('show');
                kelasSelect.disabled = false;
                return;
            }
            kelasSelect.disabled = true;
            kelasSelect.innerHTML = '<option value="">Memuat kelas...</option>';
            kelasError.textContent = '';
            kelasError.classList.remove('show');
            fetch('../../includes/get_kelas_by_jurusan_dan_tingkatan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `jurusan_id=${encodeURIComponent(jurusanId)}&tingkatan_kelas_id=${encodeURIComponent(tingkatanId)}`,
                signal: AbortSignal.timeout(5000)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                if (data.error) {
                    kelasError.textContent = data.error || 'Gagal memuat data kelas. Silakan coba lagi.';
                    kelasError.classList.add('show');
                    kelasSelect.disabled = false;
                } else if (data.kelas && Array.isArray(data.kelas) && data.kelas.length > 0) {
                    data.kelas.forEach(k => {
                        const option = document.createElement('option');
                        option.value = k.id;
                        option.textContent = k.nama_kelas;
                        kelasSelect.appendChild(option);
                    });
                    kelasError.textContent = '';
                    kelasError.classList.remove('show');
                    const previousKelasId = <?php echo json_encode(isset($_POST['kelas_id']) ? $_POST['kelas_id'] : (isset($_GET['kelas_id']) ? $_GET['kelas_id'] : '')); ?>;
                    if (previousKelasId) {
                        kelasSelect.value = previousKelasId;
                    }
                    kelasSelect.disabled = false;
                } else {
                    kelasError.textContent = 'Tidak ada kelas tersedia untuk jurusan dan tingkatan ini.';
                    kelasError.classList.add('show');
                    kelasSelect.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error fetching kelas:', error);
                kelasSelect.innerHTML = '<option value="">Gagal memuat kelas</option>';
                kelasError.textContent = error.name === 'AbortError' ?
                    'Permintaan memuat kelas terlalu lama. Silakan coba lagi.' :
                    'Gagal memuat kelas. Periksa koneksi internet Anda.';
                kelasError.classList.add('show');
                kelasSelect.disabled = false;
            });
        }
        
        function toggleRequiredFields() {
            const tipeSelect = document.getElementById('tipe_rekening');
            const emailInput = document.getElementById('email');
            const emailNote = document.querySelector('.email-note');
            const jenisKelaminSelect = document.getElementById('jenis_kelamin');
            const tanggalLahirInput = document.getElementById('tanggal_lahir');
            const alamatInput = document.getElementById('alamat_lengkap');
            const pinRow = document.getElementById('pin-row');
            const jenisKelaminLabel = jenisKelaminSelect.parentElement.querySelector('label');
            const tanggalLahirLabel = tanggalLahirInput.parentElement.querySelector('label');
            const alamatLabel = alamatInput.parentElement.querySelector('label');
            const pinLabel = pinRow.querySelector('label[for="pin"]');
            const confirmPinLabel = pinRow.querySelector('label[for="confirm_pin"]');
            if (tipeSelect.value === 'digital') {
                emailInput.required = true;
                emailInput.disabled = false;
                emailInput.placeholder = 'Masukkan email valid';
                emailNote.textContent = 'Wajib untuk rekening digital';
                jenisKelaminSelect.required = false;
                tanggalLahirInput.required = false;
                alamatInput.required = false;
                pinRow.style.display = 'none';
                jenisKelaminLabel.innerHTML = 'Jenis Kelamin (Opsional)';
                tanggalLahirLabel.innerHTML = 'Tanggal Lahir (Opsional)';
                alamatLabel.innerHTML = 'Alamat Lengkap (Opsional)';
                pinLabel.innerHTML = 'PIN (6 Digit Angka) (Opsional)';
                confirmPinLabel.innerHTML = 'Konfirmasi PIN (Opsional)';
            } else if (tipeSelect.value === 'fisik') {
                emailInput.required = false;
                emailInput.disabled = true;
                emailInput.value = '';
                emailInput.placeholder = 'Tidak diperlukan untuk fisik';
                emailNote.textContent = 'Tidak diperlukan untuk rekening fisik';
                jenisKelaminSelect.required = true;
                tanggalLahirInput.required = true;
                alamatInput.required = true;
                pinRow.style.display = 'flex';
                jenisKelaminLabel.innerHTML = 'Jenis Kelamin <span style="color: red;">*</span>';
                tanggalLahirLabel.innerHTML = 'Tanggal Lahir <span style="color: red;">*</span>';
                alamatLabel.innerHTML = 'Alamat Lengkap <span style="color: red;">*</span>';
                pinLabel.innerHTML = 'PIN (6 Digit Angka) <span style="color: red;">*</span>';
                confirmPinLabel.innerHTML = 'Konfirmasi PIN <span style="color: red;">*</span>';
            } else {
                emailInput.required = false;
                emailInput.disabled = false;
                emailInput.placeholder = 'Masukkan email valid';
                emailNote.textContent = 'Wajib untuk rekening digital';
                jenisKelaminSelect.required = false;
                tanggalLahirInput.required = false;
                alamatInput.required = false;
                pinRow.style.display = 'none';
                jenisKelaminLabel.innerHTML = 'Jenis Kelamin (Opsional)';
                tanggalLahirLabel.innerHTML = 'Tanggal Lahir (Opsional)';
                alamatLabel.innerHTML = 'Alamat Lengkap (Opsional)';
                pinLabel.innerHTML = 'PIN (6 Digit Angka) (Opsional)';
                confirmPinLabel.innerHTML = 'Konfirmasi PIN (Opsional)';
            }
        }
        
        function checkPinMatch() {
            const pin = document.getElementById('pin').value;
            const confirmPin = document.getElementById('confirm_pin').value;
            const indicator = document.getElementById('pin-match-indicator');
            const confirmPinError = document.getElementById('confirm-pin-error');
            if (confirmPin.length === 6) {
                if (pin === confirmPin && pin.length === 6) {
                    indicator.textContent = 'PIN cocok!';
                    indicator.className = 'pin-match-indicator match';
                    confirmPinError.textContent = '';
                    confirmPinError.classList.remove('show');
                } else {
                    indicator.textContent = 'PIN tidak cocok!';
                    indicator.className = 'pin-match-indicator mismatch';
                    confirmPinError.textContent = 'PIN dan konfirmasi PIN tidak cocok!';
                    confirmPinError.classList.add('show');
                }
            } else {
                indicator.textContent = '';
                confirmPinError.textContent = '';
                confirmPinError.classList.remove('show');
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }
            document.addEventListener('click', function(e) {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
            const tipeSelect = document.getElementById('tipe_rekening');
            if (tipeSelect) {
                tipeSelect.addEventListener('change', toggleRequiredFields);
                toggleRequiredFields();
            }
            const pinInput = document.getElementById('pin');
            const confirmPinInput = document.getElementById('confirm_pin');
            if (pinInput && confirmPinInput) {
                pinInput.addEventListener('input', checkPinMatch);
                confirmPinInput.addEventListener('input', checkPinMatch);
            }
            
            // Input filtering for nama - REAL TIME TITLE CASE
            const namaInput = document.getElementById('nama');
            if (namaInput) {
                namaInput.addEventListener('input', function(e) {
                    // Get cursor position before transformation
                    const cursorPosition = this.selectionStart;
                    
                    // Allow letters and spaces only
                    let cleanedValue = this.value.replace(/[^a-zA-Z\s]/g, '');
                    
                    // Limit to 100 characters
                    if (cleanedValue.length > 100) {
                        cleanedValue = cleanedValue.substring(0, 100);
                    }
                    
                    // Convert to Title Case in real-time
                    this.value = toTitleCase(cleanedValue);
                    
                    // Restore cursor position (adjust for any character changes)
                    this.setSelectionRange(cursorPosition, cursorPosition);
                });
            }
            
            const alamatInput = document.getElementById('alamat_lengkap');
            if (alamatInput) {
                alamatInput.addEventListener('input', function() {
                    if (this.value.length > 200) this.value = this.value.substring(0, 200);
                });
            }
            const nisInput = document.getElementById('nis_nisn');
            if (nisInput) {
                nisInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                    if (this.value.length > 20) this.value = this.value.substring(0, 20);
                });
            }
            const form = document.getElementById('nasabahForm');
            const submitBtn = document.getElementById('submit-btn');
            if (form && submitBtn) {
                form.addEventListener('submit', (e) => {
                    const nama = document.getElementById('nama').value.trim();
                    const tipe = document.getElementById('tipe_rekening').value;
                    const jurusan = document.getElementById('jurusan_id').value;
                    const tingkatan = document.getElementById('tingkatan_kelas_id').value;
                    const kelas = document.getElementById('kelas_id').value;
                    const email = document.getElementById('email').value.trim();
                    const tanggalLahir = document.getElementById('tanggal_lahir').value;
                    const jenisKelamin = document.getElementById('jenis_kelamin').value;
                    const alamat = document.getElementById('alamat_lengkap').value.trim();
                    const pin = document.getElementById('pin').value.trim();
                    const confirmPin = document.getElementById('confirm_pin').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Nama minimal 3 karakter!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (!tipe) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Pilih jenis rekening!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'digital' && !email) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Email wajib untuk rekening digital!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'fisik' && !jenisKelamin) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Jenis kelamin wajib untuk rekening fisik!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'fisik' && !tanggalLahir) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Tanggal lahir wajib untuk rekening fisik!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'fisik' && !alamat) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Alamat lengkap wajib untuk rekening fisik!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'fisik' && (!pin || !/^\d{6}$/.test(pin))) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'PIN wajib 6 digit angka untuk rekening fisik!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'fisik' && (!confirmPin || !/^\d{6}$/.test(confirmPin))) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Konfirmasi PIN wajib 6 digit angka untuk rekening fisik!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if ((tipe === 'fisik' || (tipe === 'digital' && pin)) && pin !== confirmPin) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'PIN dan konfirmasi PIN tidak cocok!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'digital' && pin && !/^\d{6}$/.test(pin)) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'PIN harus 6 digit angka jika diisi!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (tipe === 'fisik' && tanggalLahir && !/^\d{4}-\d{2}-\d{2}$/.test(tanggalLahir)) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Format tanggal lahir tidak valid!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (!jurusan) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Pilih jurusan!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (!tingkatan) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Pilih tingkatan kelas!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (!kelas) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Pilih kelas!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Format email tidak valid!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else {
                        submitBtn.classList.add('loading');
                        setTimeout(() => form.submit(), 1500);
                    }
                });
            }
            if (document.getElementById('jurusan_id').value && document.getElementById('tingkatan_kelas_id').value) {
                updateKelas();
            }
            document.getElementById('pin').addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 6) this.value = this.value.substring(0, 6);
                checkPinMatch();
            });
            document.getElementById('confirm_pin').addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 6) this.value = this.value.substring(0, 6);
                checkPinMatch();
            });
            
            // SweetAlert from URL
            <?php if (isset($_GET['confirm']) && isset($_GET['data'])): ?>
            const decodedData = atob('<?php echo urldecode($_GET['data']); ?>');
            const formData = JSON.parse(decodedData);
            if (formData) {
                const kelasLengkap = formData.tingkatan_name + ' ' + formData.kelas_name;
                const jenisKelaminDisplay = formData.jenis_kelamin ? (formData.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : '-';
                const tanggalLahirDisplay = formData.tanggal_lahir ? new Date(formData.tanggal_lahir).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
                const tipeDisplay = formData.tipe_rekening === 'digital' ? 'Digital (via Email)' : 'Fisik (Offline)';
                
                let tableRows = `
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Tipe Rekening:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${tipeDisplay}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Nama:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${formData.nama}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Jenis Kelamin:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${jenisKelaminDisplay}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Tanggal Lahir:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${tanggalLahirDisplay}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Alamat:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${formData.alamat_lengkap || '-'}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">NIS/NISN:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${formData.nis_nisn || '-'}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Jurusan:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${formData.jurusan_name}</div>
                    </div>
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Kelas Lengkap:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${kelasLengkap}</div>
                    </div>
                `;
                
                // Build info section and account number section
                let infoSection = '';
                let accountNumberSection = '';
                
                if (formData.tipe_rekening === 'digital') {
                    tableRows += `
                        <div style="display: table-row;">
                            <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Email:</div>
                            <div style="display: table-cell; padding: 5px 0; text-align: right;">${formData.email || '-'}</div>
                        </div>
                    `;
                    
                    // NEW: Improved layout for digital account info
                    infoSection = `
                        <div style="text-align: center; margin: 25px 0; padding: 20px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-radius: 8px; border: 2px solid #93c5fd;">
                            <div style="margin-bottom: 12px;">
                                <i class="fas fa-envelope" style="font-size: 2.5rem; color: #1e3a8a;"></i>
                            </div>
                            <h4 style="margin: 0 0 15px 0; color: #1e3a8a; font-size: 1.1rem; font-weight: 600;">Rekening Digital</h4>
                            <p style="margin: 0; font-size: 0.95rem; color: #1e40af; line-height: 1.6; font-weight: 500;">
                                <strong>Username</strong>, <strong>password</strong>, dan <strong>nomor rekening</strong><br/>
                                akan dikirim ke email terdaftar setelah konfirmasi
                            </p>
                            <div style="margin-top: 15px; padding: 10px; background: rgba(255, 255, 255, 0.6); border-radius: 5px;">
                                <i class="fas fa-check-circle" style="color: #059669; margin-right: 5px;"></i>
                                <span style="color: #065f46; font-size: 0.85rem; font-weight: 600;">${formData.email}</span>
                            </div>
                        </div>
                    `;
                } else {
                    // Fisik: show account number and PIN note
                    accountNumberSection = `
                        <div style="text-align: center; margin: 20px 0; padding: 15px; background: linear-gradient(135deg, #f0f5ff 0%, #e0e7ff 100%); border-radius: 8px; border: 2px solid #c7d2fe;">
                            <p style="margin: 0 0 8px 0; font-size: 0.85rem; color: #666; font-weight: 500;">Nomor Rekening</p>
                            <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #1e3a8a;">${formData.no_rekening}</p>
                        </div>
                    `;
                    infoSection = `
                        <p style="text-align: center; font-size: 0.9rem; color: #666; margin: 15px 0 0 0;">
                            Harap catat nomor rekening di atas.<br/>PIN akan diberikan secara manual setelah konfirmasi.
                        </p>
                    `;
                }
                
                Swal.fire({
                    title: 'Konfirmasi Data Nasabah',
                    html: `
                        <div style="text-align:left; font-size:0.95rem; margin:15px 0;">
                            <div style="background: #f8fafc; border-radius: 8px; padding: 15px; margin-bottom: 10px;">
                                <div style="display: table; width: 100%; border-collapse: collapse;">
                                    ${tableRows}
                                </div>
                            </div>
                            ${accountNumberSection}
                            ${infoSection}
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Konfirmasi',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#e74c3c',
                    width: '650px',
                    customClass: {
                        popup: 'swal-custom-popup'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Pendaftaran Sedang diproses.....',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        const hiddenForm = document.createElement('form');
                        hiddenForm.method = 'POST';
                        hiddenForm.style.display = 'none';
                        hiddenForm.action = 'proses_buka_rekening.php';
                        Object.keys(formData).forEach(key => {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = formData[key] ?? '';
                            hiddenForm.appendChild(input);
                        });
                        const tokenInput = document.createElement('input');
                        tokenInput.type = 'hidden';
                        tokenInput.name = 'token';
                        tokenInput.value = '<?php echo $token; ?>';
                        hiddenForm.appendChild(tokenInput);
                        const confirmedInput = document.createElement('input');
                        confirmedInput.type = 'hidden';
                        confirmedInput.name = 'confirmed';
                        confirmedInput.value = '1';
                        hiddenForm.appendChild(confirmedInput);
                        document.body.appendChild(hiddenForm);
                        hiddenForm.submit();
                    } else {
                        window.location.href = 'buka_rekening.php';
                    }
                });
            }
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Nasabah berhasil ditambahkan!',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                window.location.href = 'buka_rekening.php';
            });
            <?php endif; ?>
            <?php if (isset($errors['general'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?php echo addslashes($errors['general']); ?>',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>
            let lastWindowWidth = window.innerWidth;
            window.addEventListener('resize', () => {
                if (window.innerWidth !== lastWindowWidth) {
                    lastWindowWidth = window.innerWidth;
                    if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                }
            });
        });
    </script>
</body>
</html>
