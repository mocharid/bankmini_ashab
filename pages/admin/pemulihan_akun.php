<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../../index.php");
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $siswa_id = intval($_POST['siswa_id']);
    $jenis_pemulihan = $_POST['jenis_pemulihan'];
    
    // Generate unique token
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour', time()));
    
    // Insert into password_reset table
    $query = "INSERT INTO password_reset (user_id, token, expiry, recovery_type) VALUES (?, ?, ?, ?) 
              ON DUPLICATE KEY UPDATE token = VALUES(token), expiry = VALUES(expiry), recovery_type = VALUES(recovery_type)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isss", $siswa_id, $token, $expiry, $jenis_pemulihan);
    
    if ($stmt->execute()) {
        // Get student details with proper rekening information
        $query = "SELECT u.*, j.nama_jurusan, k.nama_kelas, r.no_rekening as no_rekening_siswa
                 FROM users u
                 LEFT JOIN jurusan j ON u.jurusan_id = j.id
                 LEFT JOIN kelas k ON u.kelas_id = k.id
                 LEFT JOIN rekening r ON u.id = r.user_id
                 WHERE u.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $siswa_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $siswa = $result->fetch_assoc();
        
        if (!$siswa || empty($siswa['email'])) {
            $_SESSION['error'] = "Siswa tidak memiliki email yang valid.";
            header("Location: pemulihan_akun.php");
            exit();
        }
        
        // Send email with reset link
        $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/bankmini/pages/siswa/reset_akun.php?token=$token";
        
        // Email template
        $email_template = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #0a2e5c; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .button { 
                        display: inline-block; 
                        background-color: #0a2e5c; 
                        color: white; 
                        padding: 10px 20px; 
                        text-decoration: none; 
                        border-radius: 5px; 
                        margin: 20px 0;
                    }
                    .footer { 
                        margin-top: 20px; 
                        padding: 10px; 
                        text-align: center; 
                        font-size: 12px; 
                        color: #777;
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>SCHOBANK SYSTEM</h2>
                    </div>
                    <div class='content'>
                        <p>Halo ".htmlspecialchars($siswa['nama']).",</p>
                        <p>Anda menerima email ini karena ada permintaan untuk pemulihan ".getPemulihanType($jenis_pemulihan)." akun Anda.</p>
                        <p>Silakan klik tombol di bawah ini untuk melanjutkan proses pemulihan:</p>
                        <a href='$reset_link' class='button'>Pulihkan ".getPemulihanType($jenis_pemulihan)."</a>
                        <p>Link ini akan kadaluarsa dalam 1 jam. Jika Anda tidak meminta pemulihan ini, silakan abaikan email ini.</p>
                    </div>
                    <div class='footer'>
                        <p>© ".date('Y')." SCHOBANK SYSTEM. Semua hak dilindungi.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send email using PHPMailer
        require '../../vendor/autoload.php';
        
        $mail = new PHPMailer(true);
        
        try {
            // SMTP configuration
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'mocharid.ip@gmail.com';
            $mail->Password = 'spjs plkg ktuu lcxh';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
            $mail->addAddress($siswa['email'], $siswa['nama']);
            $mail->addBCC('admin@schoolbank.com');
            
            $mail->isHTML(true);
            $mail->Subject = "Pemulihan " . getPemulihanType($jenis_pemulihan) . " - SCHOBANK SYSTEM";
            $mail->Body = $email_template;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $email_template));
            
            if ($mail->send()) {
                // Log activity
                $nilai_baru = "Reset link sent: " . $token;
                $alasan = "Permintaan pemulihan oleh admin";
                
                $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan) 
                             VALUES (?, ?, ?, ?, NOW(), ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("iisss", $_SESSION['user_id'], $siswa_id, $jenis_pemulihan, $nilai_baru, $alasan);
                $log_stmt->execute();
                
                $_SESSION['success'] = "Link pemulihan telah dikirim ke email siswa.";
                $_SESSION['show_modal'] = true;
                $_SESSION['modal_title'] = "Berhasil!";
                $_SESSION['modal_message'] = "Link pemulihan telah dikirim ke email siswa.";
            } else {
                $_SESSION['error'] = "Gagal mengirim email pemulihan.";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Error saat mengirim email: " . $mail->ErrorInfo;
        }
    } else {
        $_SESSION['error'] = "Gagal membuat token pemulihan.";
    }
    
    header("Location: pemulihan_akun.php");
    exit();
}

function getPemulihanType($type) {
    switch ($type) {
        case 'password': return 'Password';
        case 'pin': return 'PIN';
        case 'username': return 'Username';
        case 'email': return 'Email';
        default: return 'Akun';
    }
}

// Pagination for recovery logs
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Count total records
$count_query = "SELECT COUNT(*) as total FROM log_aktivitas";
$count_result = $conn->query($count_query);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Fetch recovery logs with pagination and proper rekening information
$log_query = "SELECT l.*, s.nama as siswa_nama, r.no_rekening
              FROM log_aktivitas l
              JOIN users s ON l.siswa_id = s.id
              LEFT JOIN rekening r ON s.id = r.user_id
              ORDER BY l.waktu DESC
              LIMIT $offset, $per_page";
$log_result = $conn->query($log_query);

// Fetch students with proper rekening information
$query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, k.nama_kelas 
          FROM users u
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN rekening r ON u.id = r.user_id
          WHERE u.role = 'siswa' 
          ORDER BY u.nama";
$result = $conn->query($query);
$students = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pemulihan Akun Siswa - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
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
            background: linear-gradient(135deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(10, 46, 92, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-banner h2 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            width: 36px;
            height: 36px;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: flex;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: #0a2e5c;
        }

        select, input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        select:focus, input[type="text"]:focus {
            border-color: #0a2e5c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
        }

        button[type="submit"] {
            background: #0a2e5c;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            align-self: flex-start;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        button[type="submit"]:hover {
            background: #154785;
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .jurusan-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .jurusan-list h3 {
            color: #0a2e5c;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #f8fafc;
            color: #0a2e5c;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .badge-password {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-pin {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .badge-username {
            background: #f0fdf4;
            color: #166534;
        }

        .badge-email {
            background: #ffedd5;
            color: #9a3412;
        }

        .no-data {
            color: #666;
            padding: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            text-align: center;
            margin: 20px 0;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }

        .pagination a, .pagination span {
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .pagination a {
            color: #0a2e5c;
            border: 1px solid #ddd;
        }

        .pagination a:hover {
            background-color: #f0f5ff;
        }

        .pagination .active {
            background-color: #0a2e5c;
            color: white;
            border: 1px solid #0a2e5c;
        }

        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
        }

        /* Select2 Custom Styles */
        .select2-container--default .select2-selection--single {
            height: 46px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 44px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 24px;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            button[type="submit"] {
                width: 100%;
                justify-content: center;
            }

            .table-responsive {
                overflow-x: auto;
            }

            table {
                min-width: 600px;
            }
        }
        /* Add this to your existing CSS */
        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-left: 10px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        button[type="submit"]:disabled {
            background: #7a8ca8;
            cursor: not-allowed;
            transform: none;
        }
        </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-user-shield"></i> Pemulihan Akun Siswa</h2>
            <a href="../admin/dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form action="" method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="siswa_id">Pilih Siswa:</label>
                        <select id="siswa_id" name="siswa_id" class="select2" required>
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($students as $row): ?>
                                <option value="<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['nama']); ?> 
                                    (<?php echo htmlspecialchars($row['no_rekening'] ?? 'Belum memiliki rekening'); ?>)
                                    - <?php echo htmlspecialchars($row['nama_jurusan'] ?? 'N/A'); ?> 
                                    <?php echo htmlspecialchars($row['nama_kelas'] ?? 'N/A'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="jenis_pemulihan">Jenis Pemulihan:</label>
                        <select id="jenis_pemulihan" name="jenis_pemulihan" required>
                            <option value="">-- Pilih Jenis --</option>
                            <option value="password">Password</option>
                            <option value="pin">PIN</option>
                            <option value="username">Username</option>
                            <option value="email">Email</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" id="submitBtn">
                    <i class="fas fa-paper-plane"></i>
                    Kirim Link Pemulihan
                    <div class="loading-spinner" id="loadingSpinner"></div>
                </button>
            </form>
        </div>

        <div class="jurusan-list">
            <h3><i class="fas fa-history"></i> Riwayat Pemulihan Akun</h3>
            <?php if ($log_result && $log_result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Waktu</th>
                                <th>Siswa</th>
                                <th>No Rekening</th>
                                <th>Jenis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = $offset + 1;
                            while ($row = $log_result->fetch_assoc()): 
                                $badge_class = 'badge-' . $row['jenis_pemulihan'];
                            ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d M Y H:i', strtotime($row['waktu'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['siswa_nama']); ?></td>
                                    <td><?php echo htmlspecialchars($row['no_rekening'] ?? 'Belum memiliki rekening'); ?></td>
                                    <td><span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($row['jenis_pemulihan']); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Simplified pagination with only Next and Previous buttons -->
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" title="Previous Page"><i class="fas fa-chevron-left"></i> </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> </span>
                    <?php endif; ?>
                    
                    <span class="page-info"> <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" title="Next Page"> <i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="disabled"> <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    Belum ada riwayat pemulihan
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        <?php if (isset($_SESSION['show_modal']) && $_SESSION['show_modal']): ?>
            Swal.fire({
                title: '<?php echo $_SESSION['modal_title']; ?>',
                text: '<?php echo $_SESSION['modal_message']; ?>',
                icon: 'success',
                confirmButtonColor: '#0a2e5c',
                confirmButtonText: 'OK'
            });
            <?php unset($_SESSION['show_modal']); ?>
            <?php unset($_SESSION['modal_title']); ?>
            <?php unset($_SESSION['modal_message']); ?>
        <?php endif; ?>

        // Initialize Select2
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: "Cari siswa...",
                allowClear: true,
                width: '100%'
            });
        });

        // Auto-refresh the page every 60 seconds to update logs
        setTimeout(function() {
            window.location.reload();
        }, 60000);

        // Add this inside your existing <script> tag
$(document).ready(function() {
    $('form').on('submit', function(e) {
        // Only show spinner if form is valid
        if (this.checkValidity()) {
            const submitBtn = $('#submitBtn');
            const spinner = $('#loadingSpinner');
            
            // Disable button and show spinner
            submitBtn.prop('disabled', true);
            spinner.show();
            
            // Change button text (optional)
            submitBtn.html('<i class="fas fa-paper-plane"></i> Mengirim...');
        }
    });
    
    // Initialize Select2
    $('.select2').select2({
        placeholder: "Cari siswa...",
        allowClear: true,
        width: '100%'
    });

    // Auto-refresh the page every 60 seconds to update logs
    setTimeout(function() {
        window.location.reload();
    }, 60000);
});
// Add this after the existing JavaScript
document.addEventListener('invalid', (function(){
    return function(e) {
        e.preventDefault();
        // Re-enable button if form is invalid
        $('#submitBtn').prop('disabled', false);
        $('#loadingSpinner').hide();
        $('#submitBtn').html('<i class="fas fa-paper-plane"></i> Kirim Link Pemulihan');
    };
})(), true);
    </script>
</body>
</html>