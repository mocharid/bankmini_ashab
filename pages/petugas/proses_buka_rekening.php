<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/session_validator.php';
require_once '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');
// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Restrict access to petugas only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai petugas terlebih dahulu!'));
    exit();
}
// CSRF token check
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['form_token'])) {
    header('Location: buka_rekening.php?error=' . urlencode('Token tidak valid!'));
    exit();
}
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
        $mail->setFrom('myschobank@gmail.com', 'Schobank Student Digital Banking');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
        $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
        $mail->MessageID = '<' . $unique_id . '>';
        $mail->addCustomHeader('X-Mailer', 'MY-SCHOBANK-System-v1.0');
        $mail->addCustomHeader('X-Priority', '1');
        $mail->addCustomHeader('Importance', 'High');
        $mail->isHTML(true);
        $mail->Subject = 'Pembukaan Rekening';
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF'], 3); // Adjust path to login.php
        $login_link = $protocol . "://" . $host . $path . "/pages/login.php";
        $kelas_lengkap = trim($tingkatan_name . ' ' . $kelas_name);
        $alamat_display = $alamat_lengkap ? $alamat_lengkap : 'Tidak diisi';
        $nis_display = $nis_nisn ? $nis_nisn : 'Tidak diisi';
        $jenis_kelamin_display = $jenis_kelamin ? ($jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : 'Tidak diisi';
        $tanggal_lahir_display = $tanggal_lahir ? date('d F Y', strtotime($tanggal_lahir)) : 'Tidak diisi';
        $tanggal_pembukaan = date('d F Y H:i:s') . ' WIB';
        // Template Email Sederhana & Rapi (disamakan dengan style di ubah rekening)
        $emailBody = "
        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
           
            <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Pembukaan Rekening Berhasil</h2>
           
            <p>Halo <strong>{$nama}</strong>,</p>
           
            <p>Rekening Anda telah berhasil dibuka. Terima kasih telah mempercayakan layanan perbankan digital kami.</p>
           
            <div style='margin: 30px 0; border-top: 1px solid #eeeeee; border-bottom: 1px solid #eeeeee; padding: 20px 0;'>
                <h3 style='font-size: 18px; margin-top: 0; color: #444;'>Detail Akun</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 5px 0; width: 140px; color: #666;'>No. Rekening</td>
                        <td style='font-weight: bold;'>{$no_rekening}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Nama Lengkap</td>
                        <td>{$nama}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Jenis Kelamin</td>
                        <td>{$jenis_kelamin_display}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Tanggal Lahir</td>
                        <td>{$tanggal_lahir_display}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Alamat Lengkap</td>
                        <td>{$alamat_display}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>NIS/NISN</td>
                        <td>{$nis_display}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Kelas/Jurusan</td>
                        <td>{$kelas_lengkap} - {$jurusan_name}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Tanggal Aktivasi</td>
                        <td>{$tanggal_pembukaan}</td>
                    </tr>
                </table>
            </div>
            <div style='margin-bottom: 30px;'>
                <h3 style='font-size: 18px; margin-top: 0; color: #444;'>Kredensial Login</h3>
                <p style='margin-bottom: 5px;'>Gunakan data berikut untuk masuk ke aplikasi:</p>
                <table style='width: 100%; background-color: #f9fafb; border-radius: 6px; padding: 15px;'>
                    <tr>
                        <td style='padding: 5px 0; width: 100px; color: #666;'>Username</td>
                        <td style='font-family: monospace; font-size: 16px; font-weight: bold;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Password</td>
                        <td style='font-family: monospace; font-size: 16px; font-weight: bold; color: #dc2626;'>{$password}</td>
                    </tr>
                </table>
                <p style='font-size: 13px; color: #666; margin-top: 10px;'>*Harap segera ganti password Anda setelah login pertama kali demi keamanan.</p>
            </div>
            <div style='text-align: left; margin-bottom: 40px;'>
                <a href='{$login_link}' style='background-color: #1e3a8a; color: white; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px;'>Login Sekarang</a>
            </div>
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
           
            <p style='font-size: 12px; color: #999;'>
                Ini adalah pesan otomatis dari sistem Schobank Student Digital Banking.<br>
                Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
            </p>
        </div>";
        $mail->Body = $emailBody;
        $labels = [
            'Nama Lengkap' => $nama,
            'Jenis Kelamin' => $jenis_kelamin_display,
            'Tanggal Lahir' => $tanggal_lahir_display,
            'Alamat Lengkap' => $alamat_display,
            'NIS/NISN' => $nis_display,
            'Nomor Rekening' => $no_rekening,
            'Username' => $username,
            'Kata Sandi Sementara' => $password,
            'Jurusan' => $jurusan_name,
            'Kelas' => $kelas_lengkap,
            'Tanggal Aktivasi' => $tanggal_pembukaan,
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
        $text_rows[] = "\nHormat kami,\nTim Schobank Student Digital Banking";
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
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("Data POST diterima: " . print_r($_POST, true));
    // Handle cancel button
    if (isset($_POST['cancel'])) {
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        header('Location: buka_rekening.php');
        exit();
    }
    $nama = trim($_POST['nama'] ?? '');
    $tipe_rekening = trim($_POST['tipe_rekening'] ?? 'fisik');
    $email = trim($_POST['email'] ?? '') ?: null; // Convert empty email to NULL
    $pin = trim($_POST['pin'] ?? '') ?: null;
    $confirm_pin = trim($_POST['confirm_pin'] ?? '') ?: null;
    $alamat_lengkap = trim($_POST['alamat_lengkap'] ?? '') ?: null;
    $nis_nisn = trim($_POST['nis_nisn'] ?? '') ?: null;
    $jenis_kelamin = trim($_POST['jenis_kelamin'] ?? '') ?: null;
    $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '') ?: null;
    $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
    $tingkatan_kelas_id = (int)($_POST['tingkatan_kelas_id'] ?? 0);
    $kelas_id = (int)($_POST['kelas_id'] ?? 0);
    $errors = [];
    // Common validation
    if (empty($nama) || strlen($nama) < 3) {
        $errors['nama'] = "Nama harus minimal 3 karakter!";
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Format email tidak valid!";
    }
    if ($tipe_rekening === 'digital' && empty($email)) {
        $errors['email'] = "Email wajib diisi untuk rekening digital!";
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
    // Tipe-specific validation
    if ($tipe_rekening === 'fisik') {
        $email = null;
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
    // Validate unique fields
    if (!$errors) {
        if (!empty($email) && ($error = checkUniqueField('email', $email, $conn))) {
            $errors['email'] = $error;
        }
    }
    if ($errors) {
        $error_msg = implode('; ', array_values($errors));
        header('Location: buka_rekening.php?error=' . urlencode($error_msg));
        exit();
    }
    if (isset($_POST['confirmed'])) {
        // Confirmation logic: insert to DB
        $username = ($tipe_rekening === 'digital') ? $_POST['username'] : null;
        $no_rekening = $_POST['no_rekening'];
        $password = ($tipe_rekening === 'digital') ? '12345' : null;
        $has_pin = ($tipe_rekening === 'fisik' || !empty($pin)) ? 1 : 0;
        $jurusan_name = $_POST['jurusan_name'];
        $tingkatan_name = $_POST['tingkatan_name'];
        $kelas_name = $_POST['kelas_name'];
        // Insert logic
        $conn->begin_transaction();
        try {
            $user_id = null;
            // Debug: Log all parameters before insertion
            error_log("Parameters for user insert: username=" . ($username ?? 'NULL') . ", nama=$nama, jurusan_id=$jurusan_id, kelas_id=$kelas_id, email=" . ($email ?? 'NULL') . ", email_status=" . ($email ? 'terisi' : 'kosong') . ", alamat=" . ($alamat_lengkap ?? 'NULL') . ", nis=" . ($nis_nisn ?? 'NULL') . ", jenis_kelamin=" . ($jenis_kelamin ?? 'NULL') . ", tanggal_lahir=" . ($tanggal_lahir ?? 'NULL') . ", password=" . ($password ?? 'NULL') . ", pin=" . ($pin ?? 'NULL') . ", has_pin=$has_pin");
            // Insert user - Updated with alamat_lengkap, nis_nisn, jenis_kelamin, tanggal_lahir, pin, has_pin
            // Use conditional hashing: if password is not null, hash it; else insert NULL
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
            // Insert rekening (user_id always set now)
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
        // Initial submission: generate data and redirect for confirmation
        $username = ($tipe_rekening === 'digital') ? generateUsername($nama, $conn) : null;
        // Generate jurusan kode based on id
        $jurusan_kode = sprintf('%02d', $jurusan_id);
        $no_rekening = $jurusan_kode . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $counter = 1;
        while (checkUniqueField('no_rekening', $no_rekening, $conn, 'rekening')) {
            $no_rekening = $jurusan_kode . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $counter++;
            if ($counter > 10) { // Prevent infinite loop
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
        }
        $stmt_jurusan->bind_param("i", $jurusan_id);
        $stmt_jurusan->execute();
        $result_jurusan_name = $stmt_jurusan->get_result();
        if ($result_jurusan_name->num_rows === 0) {
            header('Location: buka_rekening.php?error=' . urlencode("Jurusan tidak ditemukan!"));
            exit();
        }
        $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
        $stmt_jurusan->close();
        $query_tingkatan_name = "SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?";
        $stmt_tingkatan = $conn->prepare($query_tingkatan_name);
        if (!$stmt_tingkatan) {
            error_log("Error preparing tingkatan kelas query (initial): " . $conn->error);
            header('Location: buka_rekening.php?error=' . urlencode("Terjadi kesalahan saat mengambil data tingkatan kelas."));
            exit();
        }
        $stmt_tingkatan->bind_param("i", $tingkatan_kelas_id);
        $stmt_tingkatan->execute();
        $result_tingkatan_name = $stmt_tingkatan->get_result();
        if ($result_tingkatan_name->num_rows === 0) {
            header('Location: buka_rekening.php?error=' . urlencode("Tingkatan kelas tidak ditemukan!"));
            exit();
        }
        $tingkatan_name = $result_tingkatan_name->fetch_assoc()['nama_tingkatan'];
        $stmt_tingkatan->close();
        $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
        $stmt_kelas = $conn->prepare($query_kelas_name);
        if (!$stmt_kelas) {
            error_log("Error preparing kelas query (initial): " . $conn->error);
            header('Location: buka_rekening.php?error=' . urlencode("Terjadi kesalahan saat mengambil data kelas."));
            exit();
        }
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
        // Redirect for confirmation
        $encoded_data = base64_encode(json_encode($formData));
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        header('Location: buka_rekening.php?confirm=1&data=' . urlencode($encoded_data));
        exit();
    }
} else {
    header('Location: buka_rekening.php');
    exit();
}