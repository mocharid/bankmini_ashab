<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';


// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');


// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];


// Validate session and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}


// Get user information from session
$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;


// Function to mask no_rekening (e.g., 01234567 -> 012****7)
function maskNoRekening($no_rekening) {
    if (empty($no_rekening) || strlen($no_rekening) < 5) {
        return '-';
    }
    return substr($no_rekening, 0, 3) . '****' . substr($no_rekening, -1);
}


// Function to mask username (e.g., abcd -> ab**d)
function maskUsername($username) {
    if (empty($username) || strlen($username) < 3) {
        return $username ?: '-';
    }
    $masked = substr($username, 0, 2);
    $masked .= str_repeat('*', strlen($username) - 3);
    $masked .= substr($username, -1);
    return $masked;
}


// Indonesian month names
$bulan_indonesia = [
    'January' => 'Januari',
    'February' => 'Februari',
    'March' => 'Maret',
    'April' => 'April',
    'May' => 'Mei',
    'June' => 'Juni',
    'July' => 'Juli',
    'August' => 'Agustus',
    'September' => 'September',
    'October' => 'Oktober',
    'November' => 'November',
    'December' => 'Desember'
];


// Handle AJAX check rekening
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_rekening' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    
    if (empty($no_rekening) || !preg_match('/^\d{8}$/', $no_rekening)) {
        echo json_encode(['success' => false, 'message' => 'Nomor rekening harus 8 digit angka.']);
        exit();
    }


    // Fetch rekening and user details
    $stmt = $conn->prepare("
        SELECT u.id AS user_id, u.nama, u.username, u.email, u.alamat_lengkap, u.nis_nisn, 
               u.jenis_kelamin, u.tanggal_lahir, u.password, u.is_frozen,
               r.id AS rekening_id, r.no_rekening, r.saldo,
               j.nama_jurusan,
               CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas
        FROM rekening r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN jurusan j ON u.jurusan_id = j.id
        LEFT JOIN kelas k ON u.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        WHERE r.no_rekening = ? AND u.role = 'siswa'
    ");
    $stmt->bind_param("s", $no_rekening);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Rekening tidak ditemukan!']);
        $stmt->close();
        exit();
    }
    
    $data = $result->fetch_assoc();
    $stmt->close();
    
    // Format additional fields
    $data['jenis_kelamin_display'] = $data['jenis_kelamin'] ? ($data['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan') : '-';
    $data['tanggal_lahir_display'] = $data['tanggal_lahir'] ? strtr(date('d F Y', strtotime($data['tanggal_lahir'])), $bulan_indonesia) : '-';
    $data['alamat_lengkap'] = $data['alamat_lengkap'] ?: '-';
    $data['nis_nisn'] = $data['nis_nisn'] ?: '-';
    $data['email'] = $data['email'] ?: '-';
    $data['username_display'] = maskUsername($data['username']);
    $data['nama_jurusan'] = $data['nama_jurusan'] ?? '-';
    $data['nama_kelas'] = $data['nama_kelas'] ?? '-';
    $data['no_rekening_display'] = $data['no_rekening'];
    
    // Check conditions
    $errors = [];
    $has_saldo = false;
    if ($data['saldo'] != 0) {
        $has_saldo = true;
        $errors[] = 'Saldo masih tersisa Rp ' . number_format($data['saldo'], 0, ',', '.') . '. Silakan tarik sisa saldo terlebih dahulu.';
    }
    if ($data['is_frozen']) {
        $errors[] = 'Akun sedang dibekukan.';
    }
    
    // Additional check: no pending transaksi
    $stmt_pending = $conn->prepare("SELECT COUNT(*) as count FROM transaksi WHERE (rekening_id = ? OR rekening_tujuan_id = ?) AND status = 'pending'");
    $stmt_pending->bind_param("ii", $data['rekening_id'], $data['rekening_id']);
    $stmt_pending->execute();
    $pending = $stmt_pending->get_result()->fetch_assoc()['count'];
    $stmt_pending->close();
    
    if ($pending > 0) {
        $errors[] = 'Ada ' . $pending . ' transaksi pending.';
    }
    
    $can_close = empty($errors);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => $data['user_id'],
            'nama' => $data['nama'],
            'username' => $data['username_display'],
            'email' => $data['email'],
            'alamat_lengkap' => $data['alamat_lengkap'],
            'nis_nisn' => $data['nis_nisn'],
            'jenis_kelamin_display' => $data['jenis_kelamin_display'],
            'tanggal_lahir_display' => $data['tanggal_lahir_display'],
            'no_rekening' => $data['no_rekening'],
            'no_rekening_display' => $data['no_rekening_display'],
            'nama_jurusan' => $data['nama_jurusan'],
            'nama_kelas' => $data['nama_kelas'],
            'saldo' => $data['saldo'],
            'is_frozen' => $data['is_frozen']
        ],
        'can_close' => $can_close,
        'has_saldo' => $has_saldo,
        'errors' => $errors,
        'rekening_id' => $data['rekening_id']
    ]);
    exit();
}


// Handle AJAX close rekening (logic tetap sama)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_rekening' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $password = trim($_POST['password'] ?? '');
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    
    if ($user_id <= 0 || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit();
    }
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'siswa'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("User tidak ditemukan.");
        }
        
        $user = $result->fetch_assoc();
        $stmt->close();
        
        $hashed_password = hash('sha256', $password);
        
        if (empty($user['password'])) {
            throw new Exception("Gagal menutup rekening. Password siswa belum diatur.");
        } elseif ($user['password'] !== $hashed_password) {
            throw new Exception("Gagal menutup rekening. Password tidak sesuai.");
        }
        
        $stmt = $conn->prepare("SELECT r.id AS rekening_id, r.saldo, u.is_frozen FROM users u JOIN rekening r ON u.id = r.user_id WHERE u.id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($data['saldo'] != 0) {
            throw new Exception("Gagal menutup rekening. Saldo masih tersisa Rp " . number_format($data['saldo'], 0, ',', '.') . ". Silakan tarik saldo terlebih dahulu.");
        }
        
        if ($data['is_frozen']) {
            throw new Exception("Gagal menutup rekening. Akun sedang dibekukan.");
        }
        
        $rekening_id = $data['rekening_id'];
        
        $stmt_pending = $conn->prepare("SELECT COUNT(*) as count FROM transaksi WHERE (rekening_id = ? OR rekening_tujuan_id = ?) AND status = 'pending'");
        $stmt_pending->bind_param("ii", $rekening_id, $rekening_id);
        $stmt_pending->execute();
        $pending_result = $stmt_pending->get_result()->fetch_assoc();
        $stmt_pending->close();
        
        if ($pending_result['count'] > 0) {
            throw new Exception("Gagal menutup rekening. Ada transaksi pending.");
        }
        
        $stmt = $conn->prepare("DELETE m FROM mutasi m JOIN transaksi t ON m.transaksi_id = t.id WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?");
        $stmt->bind_param("ii", $rekening_id, $rekening_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?");
        $stmt->bind_param("ii", $rekening_id, $rekening_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("DELETE FROM rekening WHERE id = ?");
        $stmt->bind_param("i", $rekening_id);
        $stmt->execute();
        $stmt->close();
        
        $tables = [
            'account_freeze_log' => 'siswa_id',
            'log_aktivitas' => 'siswa_id',
            'notifications' => 'user_id',
            'reset_request_cooldown' => 'user_id',
            'absensi' => 'user_id',
            'password_reset' => 'user_id',
        ];
        
        foreach ($tables as $table => $column) {
            if ($conn->query("SHOW TABLES LIKE '$table'")->num_rows > 0) {
                $stmt = $conn->prepare("DELETE FROM $table WHERE $column = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            throw new Exception("Gagal menghapus user.");
        }
        $stmt->close();
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => "Rekening $no_rekening berhasil ditutup."]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tutup Rekening | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
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
        }
        
        body { 
            background-color: var(--bg-light); 
            color: var(--text-primary); 
            display: flex;
            min-height: 100vh;
        }
        
        .main-content { 
            flex: 1; 
            margin-left: 280px; 
            padding: clamp(15px, 4vw, 30px);
            max-width: calc(100% - 280px);
            width: 100%;
        }
        
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%); 
            color: white; 
            padding: clamp(20px, 4vw, 30px);
            border-radius: 5px;
            margin-bottom: clamp(20px, 4vw, 35px);
            box-shadow: var(--shadow-md); 
            display: flex; 
            align-items: center; 
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .welcome-banner .content {
            flex: 1;
            min-width: 200px;
        }
        
        .welcome-banner h2 { 
            margin-bottom: 8px;
            font-size: clamp(1.2rem, 3.5vw, 1.6rem);
            font-weight: 600; 
            display: flex; 
            align-items: center; 
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .welcome-banner p { 
            font-size: clamp(0.8rem, 2vw, 0.9rem);
            font-weight: 400; 
            opacity: 0.9;
            line-height: 1.5;
        }
        
        .menu-toggle { 
            font-size: clamp(1.3rem, 3vw, 1.5rem);
            cursor: pointer; 
            color: white; 
            display: none; 
            align-self: center;
            padding: 8px;
            -webkit-tap-highlight-color: transparent;
        }
        
        .form-card { 
            background: white; 
            border-radius: 5px;
            padding: clamp(20px, 4vw, 25px);
            box-shadow: var(--shadow-sm); 
            margin-bottom: clamp(20px, 4vw, 35px);
        }
        
        .form-group { 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
            margin-bottom: 15px; 
        }
        
        label { 
            font-weight: 500; 
            color: var(--text-secondary); 
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        input[type="text"], input[type="password"] { 
            width: 100%; 
            padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 15px);
            border: 1px solid #ddd; 
            border-radius: 5px;
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            transition: var(--transition);
        }
        
        input:focus { 
            outline: none; 
            border-color: var(--primary-color); 
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1); 
        }
        
        .error-message { 
            color: var(--danger-color); 
            font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            margin-top: 4px; 
        }
        
        .details-wrapper {
            display: none;
            gap: clamp(15px, 3vw, 20px);
            margin: clamp(15px, 3vw, 20px) 0;
        }
        
        .details-wrapper.show {
            display: grid;
            grid-template-columns: 1fr;
        }
        
        .user-details { 
            background: #f8fafc; 
            border: 1px solid #e2e8f0; 
            border-radius: 5px;
            padding: clamp(15px, 3vw, 20px);
        }
        
        .user-details h3 { 
            color: var(--primary-color); 
            margin-bottom: clamp(12px, 2.5vw, 15px);
            font-size: clamp(0.95rem, 2.2vw, 1.05rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-row { 
            display: flex; 
            justify-content: space-between;
            align-items: flex-start;
            padding: clamp(6px, 1.5vw, 8px) 0;
            border-bottom: 1px solid #e2e8f0; 
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            gap: 10px;
        }
        
        .detail-row:last-child { border-bottom: none; }
        
        .detail-row .label { 
            color: var(--text-secondary); 
            font-weight: 500;
            flex-shrink: 0;
        }
        
        .detail-row .value { 
            color: var(--text-primary); 
            font-weight: 600; 
            text-align: right;
            word-break: break-word;
        }
        
        .btn { 
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%); 
            color: white; 
            border: none; 
            padding: clamp(10px, 2vw, 12px) clamp(20px, 4vw, 25px);
            border-radius: 5px;
            cursor: pointer; 
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 500; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 8px; 
            width: 100%;
            max-width: 200px;
            margin: clamp(15px, 3vw, 20px) auto;
            transition: var(--transition);
            -webkit-tap-highlight-color: transparent;
        }
        
        .btn:hover { 
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%); 
            transform: translateY(-2px); 
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn:disabled { 
            opacity: 0.6; 
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c0392b 100%);
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c0392b 0%, var(--danger-color) 100%);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        }
        
        .step-hidden {
            display: none;
        }
        
        @media (max-width: 1024px) and (min-width: 769px) {
            .main-content {
                margin-left: 250px;
                max-width: calc(100% - 250px);
            }
        }
        
        @media (max-width: 768px) {
            .main-content { 
                margin-left: 0; 
                padding: 15px;
                max-width: 100%;
            }
            
            .menu-toggle { 
                display: block; 
            }
            
            .detail-row { 
                flex-direction: column;
                gap: 4px;
                align-items: flex-start;
            }
            
            .detail-row .value { 
                text-align: left;
                width: 100%;
            }
            
            .btn {
                max-width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
            }
            
            .welcome-banner h2 {
                font-size: 1.1rem;
            }
            
            .form-card {
                padding: 15px;
            }
            
            .user-details {
                padding: 12px;
            }
            
            input[type="text"], input[type="password"] {
                font-size: 16px;
            }
        }
        
        @media (hover: none) and (pointer: coarse) {
            input[type="text"], 
            input[type="password"],
            .btn {
                min-height: 44px;
            }
            
            .menu-toggle {
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2><i class="fas fa-user-times"></i> Tutup Rekening Siswa</h2>
                <p>Masukkan nomor rekening untuk menutup akun siswa secara permanen</p>
            </div>
        </div>
        
        <div class="form-card">
            <div id="step1" class="step-container">
                <form id="rekeningForm">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Nomor Rekening (8 Digit)</label>
                        <input 
                            type="text" 
                            id="no_rekening" 
                            name="no_rekening" 
                            maxlength="8" 
                            pattern="\d{8}" 
                            placeholder="Masukkan 8 digit angka" 
                            autocomplete="off" 
                            required
                        />
                    </div>
                    <button type="submit" id="checkButton" class="btn">
                        <span><i class="fas fa-search"></i> Cek Rekening</span>
                    </button>
                </form>
            </div>
            
            <div id="step2" class="step-container step-hidden">
                <div id="details-wrapper" class="details-wrapper">
                    <div class="user-details">
                        <h3><i class="fas fa-user-circle"></i> Detail Nasabah</h3>
                        <div id="detail-content"></div>
                    </div>
                </div>
                
                <div id="password-section" class="step-hidden" style="margin-top: 20px;">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password Siswa untuk Verifikasi</label>
                        <input 
                            type="password" 
                            id="passwordInput" 
                            placeholder="Masukkan password siswa" 
                            autocomplete="off"
                        />
                    </div>
                    <button type="button" id="closeButton" class="btn btn-danger">
                        <span><i class="fas fa-user-times"></i> Tutup Rekening</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let searchTimeout = null;
        let currentUserId = 0;
        let fullNoRekening = '';
        
        function resetForm() {
            $('#no_rekening').val('');
            $('#passwordInput').val('');
            $('#step1').removeClass('step-hidden');
            $('#step2').addClass('step-hidden');
            $('#details-wrapper').removeClass('show');
            $('#password-section').addClass('step-hidden');
            currentUserId = 0;
            fullNoRekening = '';
            
            setTimeout(() => {
                $('#no_rekening').focus();
            }, 300);
        }
        
        $('#no_rekening').on('input', function() {
            const noRek = $(this).val().trim();
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            if (noRek.length < 8) {
                return;
            }
            
            searchTimeout = setTimeout(function() {
                if (noRek.length === 8 && /^\d{8}$/.test(noRek)) {
                    $('#rekeningForm').trigger('submit');
                }
            }, 800);
        });
        
        $('#rekeningForm').on('submit', function(e) {
            e.preventDefault();
            
            const noRek = $('#no_rekening').val().trim();
            
            if (noRek.length !== 8 || !/^\d{8}$/.test(noRek)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Input Tidak Valid',
                    text: 'Nomor rekening harus 8 digit angka.',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            Swal.fire({
                title: 'Memeriksa Rekening...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.ajax({
                url: '',
                type: 'POST',
                data: {
                    action: 'check_rekening',
                    no_rekening: noRek,
                    token: '<?php echo $token; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    
                    if (response.success) {
                        const data = response.data;
                        fullNoRekening = data.no_rekening;
                        currentUserId = data.user_id;
                        
                        // Check if has saldo - redirect immediately
                        if (response.has_saldo) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Saldo Masih Ada!',
                                html: 'Saldo masih tersisa <strong>Rp ' + response.data.saldo.toLocaleString('id-ID') + '</strong>.<br>Akan dialihkan ke halaman Tarik Saldo.',
                                confirmButtonColor: '#f59e0b',
                                confirmButtonText: 'Lanjut ke Tarik Saldo',
                                timer: 3000,
                                timerProgressBar: true
                            }).then(() => {
                                window.location.href = 'tarik_saldo_admin.php?no_rekening=' + fullNoRekening;
                            });
                            return;
                        }
                        
                        // Build detail HTML
                        let detailHtml = '';
                        detailHtml += `<div class="detail-row"><span class="label">No. Rekening:</span><span class="value">${data.no_rekening_display}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Nama:</span><span class="value">${data.nama}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Kelas:</span><span class="value">${data.nama_kelas}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Jurusan:</span><span class="value">${data.nama_jurusan}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Email:</span><span class="value">${data.email}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Saldo:</span><span class="value">Rp ${data.saldo.toLocaleString('id-ID')}</span></div>`;
                        
                        $('#detail-content').html(detailHtml);
                        $('#details-wrapper').addClass('show');
                        $('#step1').addClass('step-hidden');
                        $('#step2').removeClass('step-hidden');
                        
                        if (response.can_close) {
                            $('#password-section').removeClass('step-hidden');
                            $('#passwordInput').focus();
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Rekening Siap Ditutup',
                                text: 'Semua ketentuan terpenuhi. Masukkan password siswa untuk melanjutkan.',
                                confirmButtonColor: '#10b981'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Rekening Tidak Dapat Ditutup',
                                html: response.errors.join('<br>'),
                                confirmButtonColor: '#e74c3c'
                            }).then(() => {
                                resetForm();
                            });
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message,
                            confirmButtonColor: '#e74c3c'
                        }).then(() => {
                            resetForm();
                        });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Gagal memeriksa rekening. Silakan coba lagi.',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        });
        
        $('#closeButton').on('click', function() {
            const password = $('#passwordInput').val().trim();
            
            if (password.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Password Kosong',
                    text: 'Silakan masukkan password siswa untuk verifikasi.',
                    confirmButtonColor: '#e74c3c'
                });
                $('#passwordInput').focus();
                return;
            }
            
            Swal.fire({
                title: 'Konfirmasi Akhir',
                text: 'Apakah Anda yakin ingin menutup rekening ini secara permanen?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check"></i> Ya, Tutup Rekening',
                cancelButtonText: '<i class="fas fa-times"></i> Batal',
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#6b7280',
                width: window.innerWidth < 600 ? '95%' : '600px'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Menutup Rekening...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    $.ajax({
                        url: '',
                        type: 'POST',
                        data: {
                            action: 'close_rekening',
                            user_id: currentUserId,
                            password: password,
                            no_rekening: fullNoRekening,
                            token: '<?php echo $token; ?>'
                        },
                        dataType: 'json',
                        success: function(response) {
                            Swal.close();
                            
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: response.message,
                                    confirmButtonColor: '#10b981',
                                    timer: 4000,
                                    timerProgressBar: true
                                }).then(() => {
                                    resetForm();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal',
                                    text: response.message,
                                    confirmButtonColor: '#e74c3c'
                                });
                                $('#passwordInput').val('').focus();
                            }
                        },
                        error: function() {
                            Swal.close();
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Gagal menutup rekening. Silakan coba lagi.',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                }
            });
        });
        
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }
            
            document.addEventListener('click', (e) => {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
            
            $('#no_rekening').focus();
        });
    </script>
</body>
</html>
