<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to admin only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai admin terlebih dahulu!'));
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

// Initialize variables
$formData = [];
$errors = [];
$post_handled = false;

// Function to generate unique username
function generateUsername($nama, $conn) {
    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
    $username = $base_username;
    $counter = 1;
    $check_query = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_query);
    if (!$check_stmt) {
        error_log("Error preparing username check: " . $conn->error);
        return $base_username . rand(100, 999);
    }
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    while ($result->num_rows > 0) {
        $username = $base_username . $counter;
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $counter++;
    }
    $check_stmt->close();
    return $username;
}

// Function to check unique field
function checkUniqueField($field, $value, $conn, $table = 'users') {
    if (empty($value)) {
        return null; // Skip uniqueness check for empty values
    }
    $query = "SELECT id FROM $table WHERE $field = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing unique check for $field: " . $conn->error);
        return "Terjadi kesalahan server.";
    }
    $stmt->bind_param("s", $value);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists ? ucfirst($field) . " sudah digunakan!" : null;
}

// Function to send email confirmation
function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name, $alamat_lengkap = null, $nis_nisn = null, $jenis_kelamin = null, $tanggal_lahir = null) {
    if (empty($email)) {
        error_log("Skipping email sending as email is empty");
        return ['success' => true, 'duration' => 0];
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'myschobank@gmail.com';
        $mail->Password = 'xpni zzju utfu mkth';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('myschobank@gmail.com', 'MY SCHOBANK');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
        $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
        $mail->MessageID = '<' . $unique_id . '>';
        $mail->addCustomHeader('X-Mailer', 'MY-SCHOBANK-System-v1.0');
        $mail->addCustomHeader('X-Priority', '1');
        $mail->addCustomHeader('Importance', 'High');
        $mail->isHTML(true);
        $mail->Subject = '[MY SCHOBANK] Pembukaan Rekening Berhasil - ' . date('Y-m-d H:i:s') . ' WIB';
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF'], 2);
        $login_link = $protocol . "://" . $host . $path . "/login.php";
        $logo_path = __DIR__ . '/../../assets/images/tab.png';
        if (file_exists($logo_path)) {
            $mail->addEmbeddedImage($logo_path, 'logo', 'tab.png', 'base64', 'image/png');
        }
        $bulan = [
            'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
            'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
            'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
        ];
        $tanggal_pembukaan = date('d M Y H:i:s');
        foreach ($bulan as $en => $id) {
            $tanggal_pembukaan = str_replace($en, $id, $tanggal_pembukaan);
        }
        $kelas_lengkap = trim($tingkatan_name . ' ' . $kelas_name);
        $alamat_display = $alamat_lengkap ? $alamat_lengkap : 'Tidak diisi';
        $nis_display = $nis_nisn ? $nis_nisn : 'Tidak diisi';
        $jenis_kelamin_display = $jenis_kelamin ? ($jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : 'Tidak diisi';
        $tanggal_lahir_display = $tanggal_lahir ? date('d F Y', strtotime($tanggal_lahir)) : 'Tidak diisi';
    
        // FORMAL EMAIL TEMPLATE
        $emailBody = "
        <!DOCTYPE html>
        <html lang='id'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Pembukaan Rekening Berhasil - MY SCHOBANK</title>
            <style>
                body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f4; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 5px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); }
                .header { background-color: #1e3a8a; color: #ffffff; padding: 30px; text-align: center; }
                .header img { width: 60px; height: auto; margin-bottom: 10px; }
                .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                .header p { margin: 5px 0 0; font-size: 14px; opacity: 0.9; }
                .content { padding: 30px; }
                .welcome { margin-bottom: 25px; }
                .welcome h2 { margin: 0 0 10px; font-size: 22px; font-weight: 600; color: #1e293b; }
                .welcome p { margin: 0; font-size: 14px; color: #64748b; }
                .account-card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; padding: 25px; margin-bottom: 25px; text-align: center; }
                .account-card h3 { margin: 0 0 15px; font-size: 14px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
                .account-number { margin: 0; font-size: 28px; font-weight: 700; color: #1e3a8a; font-family: 'Courier New', monospace; letter-spacing: 1px; }
                .account-note { margin: 15px 0 0; font-size: 12px; color: #94a3b8; }
                .details { background-color: #f8fafc; border-radius: 5px; padding: 20px; margin-bottom: 25px; border: 1px solid #e2e8f0; }
                .details h3 { margin: 0 0 20px; font-size: 16px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; }
                .details table { width: 100%; border-collapse: collapse; }
                .details td { padding: 12px 0; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
                .details td:first-child { font-weight: 500; color: #64748b; width: 40%; }
                .details td:last-child { color: #1e293b; text-align: right; }
                .credentials { background-color: #f8fafc; border-radius: 5px; padding: 20px; margin-bottom: 25px; border: 1px solid #e2e8f0; }
                .credentials h3 { margin: 0 0 20px; font-size: 16px; font-weight: 600; color: #92400e; display: flex; align-items: center; gap: 8px; }
                .credentials table { width: 100%; border-collapse: collapse; }
                .credentials td { padding: 12px 0; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
                .credentials td:first-child { font-weight: 500; color: #92400e; width: 40%; }
                .credentials td:last-child { color: #1e293b; text-align: right; font-family: 'Courier New', monospace; }
                .credentials td:last-child.warning { color: #dc2626; }
                .warning-text { margin-top: 15px; font-size: 12px; color: #dc2626; text-align: center; font-style: italic; }
                .login-link { text-align: center; margin-bottom: 25px; font-size: 14px; color: #1e3a8a; }
                .login-link a { color: #1e3a8a; text-decoration: underline; }
                .login-link p { margin: 10px 0 0; font-size: 12px; color: #64748b; }
                .login-link p span { color: #1e3a8a; font-weight: 500; }
                .support { background-color: #f8fafc; padding: 20px; text-align: center; border-top: 1px solid #e2e8f0; }
                .support h3 { margin: 0 0 15px; font-size: 14px; font-weight: 600; color: #1e293b; }
                .support p { margin: 0 0 5px; font-size: 13px; color: #64748b; }
                .support a { color: #1e3a8a; text-decoration: none; font-weight: 500; }
                .footer { background-color: #1e3a8a; color: #ffffff; padding: 20px; text-align: center; font-size: 12px; }
                .footer p { margin: 0 0 5px; }
                .footer .copyright { opacity: 0.8; }
                @media (max-width: 600px) { .container { margin: 10px; } .content { padding: 20px; } .header { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class='container'>
                <!-- Header -->
                <div class='header'>
                    <img src='cid:logo' alt='MY SCHOBANK Logo' style='width: 60px; height: auto;'>
                    <h1>MY SCHOBANK</h1>
                    <p>Perbankan Digital Sekolah Anda</p>
                </div>
              
                <!-- Content -->
                <div class='content'>
                    <!-- Welcome Message -->
                    <div class='welcome'>
                        <h2>Selamat Datang, {$nama}!</h2>
                        <p>Rekening digital Anda telah berhasil dibuka dan siap digunakan. Terima kasih telah mempercayakan layanan perbankan digital kami.</p>
                    </div>
                  
                    <!-- Account Number Card -->
                    <div class='account-card'>
                        <h3>Nomor Rekening Anda</h3>
                        <p class='account-number'>{$no_rekening}</p>
                        <p class='account-note'>Mohon simpan nomor rekening ini dengan aman untuk keperluan transaksi.</p>
                    </div>
                  
                    <!-- Account Details -->
                    <div class='details'>
                        <h3><i class='fas fa-info-circle' style='font-family: FontAwesome; color: #3b82f6;'></i> Detail Akun</h3>
                        <table>
                            <tr>
                                <td>Nama Lengkap</td>
                                <td>{$nama}</td>
                            </tr>
                            <tr>
                                <td>Jenis Kelamin</td>
                                <td>{$jenis_kelamin_display}</td>
                            </tr>
                            <tr>
                                <td>Tanggal Lahir</td>
                                <td>{$tanggal_lahir_display}</td>
                            </tr>
                            <tr>
                                <td>Alamat Lengkap</td>
                                <td>{$alamat_display}</td>
                            </tr>
                            <tr>
                                <td>NIS/NISN</td>
                                <td>{$nis_display}</td>
                            </tr>
                            <tr>
                                <td>Jurusan</td>
                                <td>{$jurusan_name}</td>
                            </tr>
                            <tr>
                                <td>Kelas</td>
                                <td>{$kelas_lengkap}</td>
                            </tr>
                            <tr>
                                <td>Tanggal Aktivasi</td>
                                <td>{$tanggal_pembukaan} WIB</td>
                            </tr>
                        </table>
                    </div>
                  
                    <!-- Login Credentials -->
                    <div class='credentials'>
                        <h3><i class='fas fa-lock' style='font-family: FontAwesome; color: #d97706;'></i> Kredensial Akses</h3>
                        <table>
                            <tr>
                                <td>Username</td>
                                <td>{$username}</td>
                            </tr>
                            <tr>
                                <td>Password Sementara</td>
                                <td class='warning'>{$password}</td>
                            </tr>
                        </table>
                        <div class='warning-text'>
                            <strong>⚠️ Penting:</strong> Segera ubah password sementara ini setelah login pertama untuk menjaga keamanan akun Anda.
                        </div>
                    </div>
                  
                    <!-- Login Link -->
                    <div class='login-link'>
                        <a href='{$login_link}'>Masuk ke Akun Saya</a>
                        <p>Atau kunjungi: <span>{$login_link}</span></p>
                    </div>
                </div>
              
                <!-- Support -->
                <div class='support'>
                    <h3>Butuh Bantuan?</h3>
                    <p>📧 Email: <a href='mailto:myschobank@gmail.com'>myschobank@gmail.com</a></p>
                    <p style='font-size: 11px; color: #94a3b8;'>Tim dukungan siap membantu Anda 24/7</p>
                </div>
              
                <!-- Footer -->
                <div class='footer'>
                    <p style='font-weight: 500;'>MY SCHOBANK</p>
                    <p style='opacity: 0.9;'>Perbankan Digital Terpercaya untuk Sekolah Anda</p>
                    <p class='copyright'>© " . date('Y') . " MY SCHOBANK. Semua hak dilindungi.</p>
                    <p class='copyright' style='opacity: 0.7; font-size: 11px;'>Email ini dikirim secara otomatis. Mohon tidak membalas.</p>
                </div>
            </div>
        </body>
        </html>";
        $mail->Body = $emailBody;
        $labels = [
            'Nama Lengkap' => $nama,
            'Jenis Kelamin' => $jenis_kelamin ? ($jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : 'Tidak diisi',
            'Tanggal Lahir' => $tanggal_lahir ? date('d F Y', strtotime($tanggal_lahir)) : 'Tidak diisi',
            'Alamat Lengkap' => $alamat_lengkap ?: 'Tidak diisi',
            'NIS/NISN' => $nis_nisn ?: 'Tidak diisi',
            'Nomor Rekening' => $no_rekening,
            'Username' => $username,
            'Kata Sandi Sementara' => $password,
            'Jurusan' => $jurusan_name,
            'Kelas' => $kelas_lengkap,
            'Tanggal Aktivasi' => $tanggal_pembukaan . ' WIB',
            'Saldo Awal' => 'Rp 0'
        ];
        $max_label_length = max(array_map('strlen', array_keys($labels)));
        $max_label_length = max($max_label_length, 20);
        $text_rows = [];
        foreach ($labels as $label => $value) {
            $text_rows[] = str_pad($label, $max_label_length, ' ') . " : " . $value;
        }
        $text_rows[] = "\nPENTING: Segera ganti kata sandi sementara dan atur PIN transaksi setelah login pertama.";
        $text_rows[] = "Masuk ke akun: {$login_link}";
        $text_rows[] = "Hubungi dukungan: myschobank@gmail.com";
        $text_rows[] = "\nHormat kami,\nTim MY SCHOBANK";
        $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";
        $altBody = implode("\n", $text_rows);
        $mail->AltBody = $altBody;
        error_log("Mengirim email ke: $email");
        $start_time = microtime(true);
        if ($mail->send()) {
            $end_time = microtime(true);
            $duration = round(($end_time - $start_time) * 1000);
            error_log("Email berhasil dikirim ke: $email (Durasi: {$duration}ms)");
            return ['success' => true, 'duration' => $duration];
        } else {
            throw new Exception($mail->ErrorInfo);
        }
    } catch (Exception $e) {
        error_log("Gagal mengirim email ke $email: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        if (isset($mail)) {
            $mail->smtpClose();
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $post_handled = true;
    error_log("Data POST diterima: " . print_r($_POST, true));
    
    // Handle cancel button
    if (isset($_POST['cancel'])) {
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        header('Location: buka_rekening.php');
        exit();
    }
    
    // Handle confirmation
    if (isset($_POST['confirmed'])) {
        $nama = trim($_POST['nama'] ?? '');
        $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
        $tingkatan_kelas_id = (int)($_POST['tingkatan_kelas_id'] ?? 0);
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);
        $no_rekening = $_POST['no_rekening'] ?? '';
        $tipe_rekening = trim($_POST['tipe_rekening'] ?? 'fisik');
        $email = trim($_POST['email'] ?? '') ?: null;
        $username = trim($_POST['username'] ?? '') ?: null;
        $password = ($tipe_rekening === 'digital') ? '12345' : null;
        $pin = trim($_POST['pin'] ?? '') ?: null;
        $confirm_pin = trim($_POST['confirm_pin'] ?? '') ?: null;
        $has_pin = ($tipe_rekening === 'fisik' || !empty($pin)) ? 1 : 0;
        
        if ($tipe_rekening === 'fisik') {
            $email = null;
            $username = null;
            $password = null;
            if (empty($pin) || !preg_match('/^\d{6}$/', $pin)) {
                $errors['pin'] = "PIN wajib 6 digit angka untuk rekening fisik!";
            }
            if (empty($confirm_pin) || !preg_match('/^\d{6}$/', $confirm_pin)) {
                $errors['confirm_pin'] = "Konfirmasi PIN wajib 6 digit angka untuk rekening fisik!";
            }
            if (!empty($pin) && !empty($confirm_pin) && $pin !== $confirm_pin) {
                $errors['confirm_pin'] = "PIN dan konfirmasi PIN tidak cocok!";
            }
        }
        
        if ($tipe_rekening === 'digital' && !empty($pin) && !preg_match('/^\d{6}$/', $pin)) {
            $errors['pin'] = "PIN harus 6 digit angka jika diisi!";
        }
        if ($tipe_rekening === 'digital' && !empty($confirm_pin) && !preg_match('/^\d{6}$/', $confirm_pin)) {
            $errors['confirm_pin'] = "Konfirmasi PIN harus 6 digit angka jika diisi!";
        }
        if ($tipe_rekening === 'digital' && !empty($pin) && !empty($confirm_pin) && $pin !== $confirm_pin) {
            $errors['confirm_pin'] = "PIN dan konfirmasi PIN tidak cocok!";
        }
        
        $alamat_lengkap = trim($_POST['alamat_lengkap'] ?? '') ?: null;
        $nis_nisn = trim($_POST['nis_nisn'] ?? '') ?: null;
        $jenis_kelamin = trim($_POST['jenis_kelamin'] ?? '') ?: null;
        $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '') ?: null;
        
        // Validate input
        if (empty($nama) || strlen($nama) < 3) {
            $errors['nama'] = "Nama harus minimal 3 karakter!";
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Format email tidak valid!";
        }
        if ($tipe_rekening === 'digital' && empty($email)) {
            $errors['email'] = "Email wajib diisi untuk rekening digital!";
        }
        if ($tipe_rekening === 'fisik') {
            if (empty($jenis_kelamin)) {
                $errors['jenis_kelamin'] = "Jenis kelamin wajib dipilih untuk rekening fisik!";
            }
            if (empty($tanggal_lahir) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_lahir)) {
                $errors['tanggal_lahir'] = "Tanggal lahir wajib dan format tidak valid untuk rekening fisik!";
            }
            if (empty($alamat_lengkap)) {
                $errors['alamat_lengkap'] = "Alamat lengkap wajib diisi untuk rekening fisik!";
            }
        } else {
            if (!empty($tanggal_lahir) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_lahir)) {
                $errors['tanggal_lahir'] = "Format tanggal lahir tidak valid!";
            }
        }
        if (!in_array($tipe_rekening, ['digital', 'fisik'])) {
            $errors['tipe_rekening'] = "Tipe rekening tidak valid!";
        }
        if ($jurusan_id <= 0) {
            $errors['jurusan_id'] = "Jurusan wajib dipilih!";
        }
        if ($tingkatan_kelas_id <= 0) {
            $errors['tingkatan_kelas_id'] = "Tingkatan kelas wajib dipilih!";
        }
        if ($kelas_id <= 0) {
            $errors['kelas_id'] = "Kelas wajib dipilih!";
        }
        if ($tipe_rekening === 'digital' && empty($username)) {
            $errors['username'] = "Username tidak valid!";
        }
        if (empty($no_rekening)) {
            $errors['no_rekening'] = "Nomor rekening tidak valid!";
        }
        
        // Validate unique fields
        if (!$errors) {
            if (!empty($email) && ($error = checkUniqueField('email', $email, $conn))) {
                $errors['email'] = $error;
            }
            if ($error = checkUniqueField('no_rekening', $no_rekening, $conn, 'rekening')) {
                header('Location: buka_rekening.php?error=' . urlencode('Nomor rekening duplikat, silakan coba lagi.'));
                exit();
            }
        }
        
        if (!$errors) {
            $conn->begin_transaction();
            try {
                $user_id = null;
                
                // Insert user for both digital and fisik
                error_log("Parameters for user insert: username=" . ($username ?? 'NULL') . ", nama=$nama, jurusan_id=$jurusan_id, kelas_id=$kelas_id, email=" . ($email ?? 'NULL') . ", email_status=" . ($email ? 'terisi' : 'kosong') . ", alamat=" . ($alamat_lengkap ?? 'NULL') . ", nis=" . ($nis_nisn ?? 'NULL') . ", jenis_kelamin=" . ($jenis_kelamin ?? 'NULL') . ", tanggal_lahir=" . ($tanggal_lahir ?? 'NULL') . ", password=" . ($password ?? 'NULL') . ", pin=" . ($pin ?? 'NULL') . ", has_pin=$has_pin");
                
                $hashed_password = $password ? hash('sha256', $password) : null;
                $hashed_pin = $pin ? hash('sha256', $pin) : null;
                
                $query = "INSERT INTO users (username, password, role, nama, jurusan_id, kelas_id, email, email_status, alamat_lengkap, nis_nisn, jenis_kelamin, tanggal_lahir, pin, has_pin)
                          VALUES (?, ?, 'siswa', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    error_log("Error preparing user insert: " . $conn->error);
                    throw new Exception("Gagal menyiapkan pernyataan untuk menyimpan user: " . $conn->error);
                }
                $email_status = $email ? 'terisi' : 'kosong';
                $stmt->bind_param("sssiisssssssi", $username, $hashed_password, $nama, $jurusan_id, $kelas_id, $email, $email_status, $alamat_lengkap, $nis_nisn, $jenis_kelamin, $tanggal_lahir, $hashed_pin, $has_pin);
                if (!$stmt->execute()) {
                    error_log("Error executing user insert: " . $stmt->error);
                    throw new Exception("Gagal menyimpan user: " . $stmt->error);
                }
                $user_id = $conn->insert_id;
                $stmt->close();
                
                // Insert rekening
                error_log("Mencoba insert rekening: tipe={$tipe_rekening}, no_rekening={$no_rekening}, user_id={$user_id}");
                $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
                $stmt_rekening = $conn->prepare($query_rekening);
                if (!$stmt_rekening) {
                    error_log("Error preparing rekening insert: " . $conn->error);
                    throw new Exception("Gagal menyiapkan pernyataan untuk menyimpan rekening.");
                }
                $stmt_rekening->bind_param("si", $no_rekening, $user_id);
                if (!$stmt_rekening->execute()) {
                    error_log("Error saat membuat rekening: " . $stmt_rekening->error);
                    throw new Exception("Gagal menyimpan rekening: " . $stmt_rekening->error);
                }
                $stmt_rekening->close();
                
                // Fetch jurusan, tingkatan kelas, and kelas names
                $query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
                $stmt_jurusan = $conn->prepare($query_jurusan_name);
                if (!$stmt_jurusan) {
                    error_log("Error preparing jurusan query: " . $conn->error);
                    throw new Exception("Gagal mengambil nama jurusan.");
                }
                $stmt_jurusan->bind_param("i", $jurusan_id);
                $stmt_jurusan->execute();
                $result_jurusan_name = $stmt_jurusan->get_result();
                if ($result_jurusan_name->num_rows === 0) {
                    throw new Exception("Jurusan tidak ditemukan!");
                }
                $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
                $stmt_jurusan->close();
                
                $query_tingkatan_name = "SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?";
                $stmt_tingkatan = $conn->prepare($query_tingkatan_name);
                if (!$stmt_tingkatan) {
                    error_log("Error preparing tingkatan kelas query: " . $conn->error);
                    throw new Exception("Gagal mengambil nama tingkatan kelas.");
                }
                $stmt_tingkatan->bind_param("i", $tingkatan_kelas_id);
                $stmt_tingkatan->execute();
                $result_tingkatan_name = $stmt_tingkatan->get_result();
                if ($result_tingkatan_name->num_rows === 0) {
                    throw new Exception("Tingkatan kelas tidak ditemukan!");
                }
                $tingkatan_name = $result_tingkatan_name->fetch_assoc()['nama_tingkatan'];
                $stmt_tingkatan->close();
                
                $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
                $stmt_kelas = $conn->prepare($query_kelas_name);
                if (!$stmt_kelas) {
                    error_log("Error preparing kelas query: " . $conn->error);
                    throw new Exception("Gagal mengambil nama kelas.");
                }
                $stmt_kelas->bind_param("i", $kelas_id);
                $stmt_kelas->execute();
                $result_kelas_name = $stmt_kelas->get_result();
                if ($result_kelas_name->num_rows === 0) {
                    throw new Exception("Kelas tidak ditemukan!");
                }
                $kelas_name = $result_kelas_name->fetch_assoc()['nama_kelas'];
                $stmt_kelas->close();
                
                // Send email notification only for digital accounts
                $email_result = ['success' => true, 'duration' => 0];
                if ($tipe_rekening === 'digital' && !empty($email)) {
                    $email_result = sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name, $alamat_lengkap, $nis_nisn, $jenis_kelamin, $tanggal_lahir);
                    if (!$email_result['success']) {
                        error_log("Peringatan: Gagal mengirim email ke: $email, tetapi data disimpan.");
                    }
                }
                
                $conn->commit();
                $_SESSION['form_token'] = bin2hex(random_bytes(32));
                header("Location: buka_rekening.php?success=1");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Transaksi gagal: " . $e->getMessage() . " | Stack: " . $e->getTraceAsString());
                header('Location: buka_rekening.php?error=' . urlencode("Gagal menambah nasabah: " . $e->getMessage()));
                exit();
            }
        } else {
            $error_msg = implode('; ', array_values($errors));
            header('Location: buka_rekening.php?error=' . urlencode($error_msg));
            exit();
        }
    } else {
        // Handle initial form submission
        $nama = strtoupper(trim($_POST['nama'] ?? '')); // Convert to uppercase
        $tipe_rekening = trim($_POST['tipe_rekening'] ?? 'fisik');
        $email = trim($_POST['email'] ?? '') ?: null;
        
        if ($tipe_rekening === 'fisik') {
            $email = null;
        }
        
        $pin = trim($_POST['pin'] ?? '') ?: null;
        $confirm_pin = trim($_POST['confirm_pin'] ?? '') ?: null;
        $alamat_lengkap = trim($_POST['alamat_lengkap'] ?? '') ?: null;
        $nis_nisn = trim($_POST['nis_nisn'] ?? '') ?: null;
        $jenis_kelamin = trim($_POST['jenis_kelamin'] ?? '') ?: null;
        $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '') ?: null;
        $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
        $tingkatan_kelas_id = (int)($_POST['tingkatan_kelas_id'] ?? 0);
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);
        
        // Validate input
        if (empty($nama) || strlen($nama) < 3) {
            $errors['nama'] = "Nama harus minimal 3 karakter!";
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Format email tidak valid!";
        }
        if ($tipe_rekening === 'digital' && empty(trim($_POST['email'] ?? ''))) {
            $errors['email'] = "Email wajib diisi untuk rekening digital!";
        }
        if ($tipe_rekening === 'fisik') {
            if (empty($jenis_kelamin)) {
                $errors['jenis_kelamin'] = "Jenis kelamin wajib dipilih untuk rekening fisik!";
            }
            if (empty($tanggal_lahir) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_lahir)) {
                $errors['tanggal_lahir'] = "Tanggal lahir wajib dan format tidak valid untuk rekening fisik!";
            }
            if (empty($alamat_lengkap)) {
                $errors['alamat_lengkap'] = "Alamat lengkap wajib diisi untuk rekening fisik!";
            }
            if (empty($pin) || !preg_match('/^\d{6}$/', $pin)) {
                $errors['pin'] = "PIN wajib 6 digit angka untuk rekening fisik!";
            }
            if (empty($confirm_pin) || !preg_match('/^\d{6}$/', $confirm_pin)) {
                $errors['confirm_pin'] = "Konfirmasi PIN wajib 6 digit angka untuk rekening fisik!";
            }
            if (!empty($pin) && !empty($confirm_pin) && $pin !== $confirm_pin) {
                $errors['confirm_pin'] = "PIN dan konfirmasi PIN tidak cocok!";
            }
        } else {
            if (!empty($tanggal_lahir) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_lahir)) {
                $errors['tanggal_lahir'] = "Format tanggal lahir tidak valid!";
            }
            if (!empty($pin) && !preg_match('/^\d{6}$/', $pin)) {
                $errors['pin'] = "PIN harus 6 digit angka jika diisi!";
            }
            if (!empty($confirm_pin) && !preg_match('/^\d{6}$/', $confirm_pin)) {
                $errors['confirm_pin'] = "Konfirmasi PIN harus 6 digit angka jika diisi!";
            }
            if (!empty($pin) && !empty($confirm_pin) && $pin !== $confirm_pin) {
                $errors['confirm_pin'] = "PIN dan konfirmasi PIN tidak cocok!";
            }
        }
        if (!in_array($tipe_rekening, ['digital', 'fisik'])) {
            $errors['tipe_rekening'] = "Tipe rekening tidak valid!";
        }
        if ($jurusan_id <= 0) {
            $errors['jurusan_id'] = "Jurusan wajib dipilih!";
        }
        if ($tingkatan_kelas_id <= 0) {
            $errors['tingkatan_kelas_id'] = "Tingkatan kelas wajib dipilih!";
        }
        if ($kelas_id <= 0) {
            $errors['kelas_id'] = "Kelas wajib dipilih!";
        }
        
        // Validate unique fields
        if (!$errors && !empty($email)) {
            if ($error = checkUniqueField('email', $email, $conn)) {
                $errors['email'] = $error;
            }
        }
        
        if (!$errors) {
            $username = ($tipe_rekening === 'digital') ? generateUsername($nama, $conn) : null;
            
            // Generate jurusan kode based on id
            $jurusan_kode = sprintf('%02d', $jurusan_id);
            $no_rekening = $jurusan_kode . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $counter = 1;
            while (checkUniqueField('no_rekening', $no_rekening, $conn, 'rekening')) {
                $no_rekening = $jurusan_kode . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                $counter++;
                if ($counter > 10) {
                    header('Location: buka_rekening.php?error=' . urlencode("Gagal generate nomor rekening unik."));
                    exit();
                }
            }
            error_log("No rekening generated: $no_rekening for jurusan id: $jurusan_id");
            
            // Fetch jurusan, tingkatan kelas, and kelas names
            $query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
            $stmt_jurusan = $conn->prepare($query_jurusan_name);
            if (!$stmt_jurusan) {
                error_log("Error preparing jurusan query (initial): " . $conn->error);
                header('Location: buka_rekening.php?error=' . urlencode("Terjadi kesalahan saat mengambil data jurusan."));
                exit();
            } else {
                $stmt_jurusan->bind_param("i", $jurusan_id);
                $stmt_jurusan->execute();
                $result_jurusan_name = $stmt_jurusan->get_result();
                if ($result_jurusan_name->num_rows === 0) {
                    header('Location: buka_rekening.php?error=' . urlencode("Jurusan tidak ditemukan!"));
                    exit();
                } else {
                    $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
                    $stmt_jurusan->close();
                    
                    $query_tingkatan_name = "SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?";
                    $stmt_tingkatan = $conn->prepare($query_tingkatan_name);
                    if (!$stmt_tingkatan) {
                        error_log("Error preparing tingkatan kelas query (initial): " . $conn->error);
                        header('Location: buka_rekening.php?error=' . urlencode("Terjadi kesalahan saat mengambil data tingkatan kelas."));
                        exit();
                    } else {
                        $stmt_tingkatan->bind_param("i", $tingkatan_kelas_id);
                        $stmt_tingkatan->execute();
                        $result_tingkatan_name = $stmt_tingkatan->get_result();
                        if ($result_tingkatan_name->num_rows === 0) {
                            header('Location: buka_rekening.php?error=' . urlencode("Tingkatan kelas tidak ditemukan!"));
                            exit();
                        } else {
                            $tingkatan_name = $result_tingkatan_name->fetch_assoc()['nama_tingkatan'];
                            $stmt_tingkatan->close();
                            
                            $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
                            $stmt_kelas = $conn->prepare($query_kelas_name);
                            if (!$stmt_kelas) {
                                error_log("Error preparing kelas query (initial): " . $conn->error);
                                header('Location: buka_rekening.php?error=' . urlencode("Terjadi kesalahan saat mengambil data kelas."));
                                exit();
                            } else {
                                $stmt_kelas->bind_param("i", $kelas_id);
                                $stmt_kelas->execute();
                                $result_kelas_name = $stmt_kelas->get_result();
                                $kelas_name = $result_kelas_name->num_rows > 0 ? $result_kelas_name->fetch_assoc()['nama_kelas'] : '';
                                $stmt_kelas->close();
                                
                                $formData = [
                                    'nama' => $nama,
                                    'username' => $username,
                                    'password' => ($tipe_rekening === 'digital') ? '12345' : null,
                                    'pin' => $pin,
                                    'confirm_pin' => $confirm_pin,
                                    'jurusan_id' => $jurusan_id,
                                    'tingkatan_kelas_id' => $tingkatan_kelas_id,
                                    'kelas_id' => $kelas_id,
                                    'no_rekening' => $no_rekening,
                                    'email' => $email,
                                    'tipe_rekening' => $tipe_rekening,
                                    'alamat_lengkap' => $alamat_lengkap,
                                    'nis_nisn' => $nis_nisn,
                                    'jenis_kelamin' => $jenis_kelamin,
                                    'tanggal_lahir' => $tanggal_lahir,
                                    'jurusan_name' => $jurusan_name,
                                    'tingkatan_name' => $tingkatan_name,
                                    'kelas_name' => $kelas_name
                                ];
                                
                                $encoded_data = base64_encode(json_encode($formData));
                                $_SESSION['form_token'] = bin2hex(random_bytes(32));
                                header('Location: buka_rekening.php?confirm=1&data=' . urlencode($encoded_data));
                                exit();
                            }
                        }
                    }
                }
            }
        } else {
            $form_values = [
                'nama' => $nama,
                'email' => $email ?? '',
                'alamat_lengkap' => $alamat_lengkap ?? '',
                'nis_nisn' => $nis_nisn ?? '',
                'jenis_kelamin' => $jenis_kelamin ?? '',
                'tanggal_lahir' => $tanggal_lahir ?? '',
                'pin' => $pin ?? '',
                'confirm_pin' => $confirm_pin ?? '',
                'jurusan_id' => $jurusan_id,
                'tingkatan_kelas_id' => $tingkatan_kelas_id,
                'kelas_id' => $kelas_id
            ];
        }
    }
}

// Handle confirmation from URL
if (isset($_GET['confirm']) && isset($_GET['data'])) {
    $decoded_data = base64_decode(urldecode($_GET['data']));
    $formData = json_decode($decoded_data, true);
    if (!$formData) {
        header('Location: buka_rekening.php?error=' . urlencode('Data konfirmasi tidak valid.'));
        exit();
    }
    if ($formData['tipe_rekening'] === 'fisik') {
        $formData['email'] = null;
    }
}

// Handle errors from URL for form display
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
        }
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
            font-size: 1.6rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-banner p {
            font-size: 0.9rem;
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
        /* ONLY #nama input will be uppercase */
        #nama {
            text-transform: uppercase;
        }
        #nama::placeholder {
            text-transform: uppercase;
        }
        input[type="date"] {
            padding-right: 40px;
            background-color: #fff;
            cursor: pointer;
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
        @supports (-webkit-touch-callout: none) {
            input[type="date"] {
                padding: 12px 40px 12px 12px;
                font-size: 16px;
                min-height: 44px;
                background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="%23666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>');
                background-repeat: no-repeat;
                background-position: right 12px center;
                background-size: 20px;
                -webkit-appearance: none;
                cursor: pointer;
            }
            input[type="date"]::-webkit-calendar-picker-indicator {
                display: none;
            }
            input[type="date"]:focus {
                border-color: var(--primary-color);
            }
        }
        @media screen and (-webkit-min-device-pixel-ratio: 0) {
            input[type="date"] {
                padding: 12px 40px 12px 12px;
                background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="%23666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>');
                background-repeat: no-repeat;
                background-position: right 12px center;
                background-size: 20px;
                cursor: pointer;
            }
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
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        .btn-secondary:hover {
            background: linear-gradient(135deg, #4b5563 0%, #6b7280 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
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
            .main-content {
                margin-left: 0;
                max-width: 100%;
                padding: 15px;
            }
            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }
            .menu-toggle {
                display: block;
            }
            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                align-items: center;
            }
            .welcome-banner h2 {
                font-size: 1.4rem;
            }
            .welcome-banner p {
                font-size: 0.85rem;
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
                font-size: 1.2rem;
            }
            .welcome-banner p {
                font-size: 0.8rem;
            }
            .form-card {
                padding: 15px;
            }
            input, select {
                min-height: 44px;
                font-size: 16px;
            }
            @supports (-webkit-touch-callout: none) {
                input[type="date"] {
                    padding: 12px 45px 12px 12px;
                    font-size: 16px;
                    min-height: 44px;
                    background-size: 18px;
                    background-position: right 12px center;
                }
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
    <?php include '../../includes/sidebar_admin.php'; ?>
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
            <form action="" method="POST" id="nasabahForm">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nama">Nama Lengkap <span style="color: red;">*</span></label>
                        <input type="text" id="nama" name="nama" required
                               value="<?php echo htmlspecialchars($_POST['nama'] ?? ($_GET['nama'] ?? '')); ?>"
                               placeholder="MASUKKAN NAMA LENGKAP"
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
                        <span class="error-message <?php echo isset($errors['confirm_pin']) ? 'show' : ''; ?>" id="confirm-pin-error">
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
            const pinLabel = pinRow.querySelector('label');
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
            
            // Input filtering for nama - make uppercase ONLY
            const namaInput = document.getElementById('nama');
            if (namaInput) {
                namaInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase().replace(/[^A-Z\s]/g, '');
                    if (this.value.length > 100) this.value = this.value.substring(0, 100);
                });
                namaInput.addEventListener('change', function() {
                    this.value = this.value.toUpperCase();
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
            
            <?php if (isset($_GET['confirm']) && $formData): ?>
            const formData = <?php echo json_encode($formData); ?>;
            const kelasLengkap = formData.tingkatan_name + ' ' + formData.kelas_name;
            const jenisKelaminDisplay = formData.jenis_kelamin ? (formData.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : '-';
            const tanggalLahirDisplay = formData.tanggal_lahir ? new Date(formData.tanggal_lahir).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
            const tipeDisplay = formData.tipe_rekening === 'digital' ? 'Digital (via Email)' : 'Fisik (Offline)';
            let detailText = '';
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
            if (formData.tipe_rekening === 'digital') {
                tableRows += `
                    <div style="display: table-row;">
                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Email:</div>
                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${formData.email || '-'}</div>
                    </div>
                `;
                detailText = 'Username, kata sandi, dan nomor rekening dapat dilihat di email terdaftar setelah konfirmasi.';
            } else {
                detailText = 'Harap catat nomor rekening di atas. PIN akan diberikan secara manual setelah konfirmasi.';
            }
            Swal.fire({
                title: 'Konfirmasi Data Nasabah',
                html: `
                    <div style="text-align:left; font-size:0.95rem; margin:15px 0;">
                        <div style="background: #f8fafc; border-radius: 5px; padding: 15px; margin-bottom: 10px;">
                            <div style="display: table; width: 100%; border-collapse: collapse;">
                                ${tableRows}
                            </div>
                        </div>
                        <div style="text-align: center; margin: 20px 0; padding: 15px; background: linear-gradient(135deg, #f0f5ff 0%, #e0e7ff 100%); border-radius: 5px;">
                            <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #1e3a8a;">${formData.no_rekening}</p>
                            <p style="margin: 5px 0 0; font-size: 0.85rem; color: #666; font-weight: 500;">Nomor Rekening</p>
                        </div>
                        <p style="font-size:0.9rem; color:#666;">${detailText}</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check"></i> Konfirmasi',
                cancelButtonText: '<i class="fas fa-times"></i> Batal',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#e74c3c',
                width: '600px'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Pendaftaran Sedang di proses.....',
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
                    hiddenForm.action = '';
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
