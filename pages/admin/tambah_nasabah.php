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

// Enable debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/laragon/www/schobank/logs/error.log');

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
$showConfirmation = false;
$formData = [];
$show_success_popup = false;
$errors = [];

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

// Function to send WhatsApp message using Fonnte API
function sendWhatsAppMessage($phone_number, $message) {
    $curl = curl_init();
    
    if (substr($phone_number, 0, 2) === '08') {
        $phone_number = '62' . substr($phone_number, 1);
    }
    
    $post_fields = [
        'target' => $phone_number,
        'message' => $message
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_HTTPHEADER => [
            'Authorization: dCjq3fJVf9p2DAfVDVED'
        ],
    ]);
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);
    
    if ($http_code == 200) {
        error_log("WhatsApp message sent successfully to: $phone_number");
        return true;
    } else {
        error_log("Failed to send WhatsApp message to $phone_number: HTTP $http_code, Response: $response, Error: $curl_error");
        return false;
    }
}

// Function to send email confirmation
function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name, $no_wa = '') {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'schobanksystem@gmail.com';
        $mail->Password = 'dgry fzmc mfrd hzzq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('schobanksystem@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@schobank.com', 'No Reply');
        
        // Add unique Message-ID
        $unique_id = uniqid('schobank_', true) . '@schobank.com';
        $mail->MessageID = '<' . $unique_id . '>';
        
        // Add custom headers
        $mail->addCustomHeader('X-Mailer', 'SCHOBANK-System-v1.0');
        $mail->addCustomHeader('X-Priority', '1');
        $mail->addCustomHeader('Importance', 'High');

        $mail->isHTML(true);
        $mail->Subject = '[SCHOBANK] Pembukaan Rekening Berhasil - ' . date('Y-m-d H:i:s') . ' WIB';

        // Generate dynamic login link
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF'], 2);
        $login_link = $protocol . "://" . $host . $path . "/login.php";

        // Konversi bulan ke bahasa Indonesia
        $bulan = [
            'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
            'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
            'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
        ];
        $tanggal_pembukaan = date('d M Y H:i:s');
        foreach ($bulan as $en => $id) {
            $tanggal_pembukaan = str_replace($en, $id, $tanggal_pembukaan);
        }

        $banner_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/buka.png';
        if (file_exists($banner_path)) {
            $mail->addEmbeddedImage($banner_path, 'emailbanner', 'buka_Rekening.png');
        } else {
            error_log("Banner image not found at: $banner_path");
        }

        $emailBody = "
        <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f0f5ff; border-radius: 12px; overflow: hidden;'>
            <div style='background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px 20px; text-align: center; color: #ffffff; border-top-left-radius: 12px; border-top-right-radius: 12px;'>
                <h2 style='font-size: 24px; font-weight: 600; margin: 0;'>SCHOBANK SYSTEM</h2>
                <p style='font-size: 16px; opacity: 0.8; margin: 5px 0 0;'>Pembukaan Rekening Berhasil</p>
            </div>
            <div style='background: #ffffff; padding: 30px;'>
                <h3 style='color: #1e3a8a; font-size: 20px; font-weight: 600; margin-bottom: 20px;'>Halo, {$nama}</h3>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami dengan senang hati menginformasikan bahwa rekening digital Anda di SCHOBANK SYSTEM telah berhasil dibuka. Berikut adalah rincian akun Anda:</p>
                <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                    <table style='width: 100%; font-size: 15px; color: #333333;'>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Nama</td>
                            <td style='padding: 8px 0; text-align: right;'>{$nama}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>No. Rekening</td>
                            <td style='padding: 8px 0; text-align: right;'>{$no_rekening}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Username</td>
                            <td style='padding: 8px 0; text-align: right;'>{$username}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Password</td>
                            <td style='padding: 8px 0; text-align: right;'>{$password}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Jurusan</td>
                            <td style='padding: 8px 0; text-align: right;'>{$jurusan_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Tingkatan Kelas</td>
                            <td style='padding: 8px 0; text-align: right;'>{$tingkatan_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Kelas</td>
                            <td style='padding: 8px 0; text-align: right;'>{$kelas_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>No. WhatsApp</td>
                            <td style='padding: 8px 0; text-align: right;'>" . ($no_wa ? $no_wa : '-') . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 500;'>Tanggal Pembukaan</td>
                            <td style='padding: 8px 0; text-align: right;'>{$tanggal_pembukaan} WIB</td>
                        </tr>
                    </table>
                </div>
                <div style='text-align: center; margin: 20px 0;'>
                    <img src='cid:emailbanner' alt='SCHOBANK Banner' style='max-width: 100%; height: auto; border-radius: 8px; border: 2px solid #e2e8f0;' />
                </div>
                <div style='background-color: #fef2f2; border: 1px solid #f87171; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>
                    <p style='color: #b91c1c; font-size: 16px; font-weight: 600; margin: 0 0 10px; display: flex; align-items: center; gap: 8px;'>
                        <span style='font-size: 20px;'>⚠️</span> Peringatan Keamanan
                    </p>
                    <p style='color: #7f1d1d; font-size: 14px; line-height: 1.5; margin: 0;'>
                        Untuk menjaga keamanan akun Anda, <strong>segera ubah kata sandi</strong> default setelah login pertama. Selain itu, <strong>atur PIN transaksi</strong> Anda sesegera mungkin melalui pengaturan akun untuk perlindungan tambahan.
                    </p>
                </div>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Silakan akses akun Anda untuk mulai menggunakan layanan SCHOBANK SYSTEM:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$login_link}' style='display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%); color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500;'>Login Sekarang</a>
                </div>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Jika Anda memiliki pertanyaan atau membutuhkan bantuan, silakan hubungi kami melalui:</p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>
                    Email: <a href='mailto:schobanksystem@gmail.com' style='color: #1e3a8a; text-decoration: none; font-weight: 500;'>schobanksystem@gmail.com</a>
                </p>
                <p style='color: #e74c3c; font-size: 14px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;'>
                    <span style='font-size: 18px;'>🔒</span> Jangan bagikan informasi rekening, kata sandi, atau detail transaksi kepada pihak lain.
                </p>
            </div>
            <div style='background: #f0f5ff; padding: 15px; text-align: center; font-size: 12px; color: #666666; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
                <p style='margin: 5px 0 0;'>Email ini otomatis. Mohon tidak membalas.</p>
            </div>
        </div>";

        $mail->Body = $emailBody;

        $labels = [
            'Nama' => $nama,
            'No. Rekening' => $no_rekening,
            'Username' => $username,
            'Password' => $password,
            'Jurusan' => $jurusan_name,
            'Tingkatan Kelas' => $tingkatan_name,
            'Kelas' => $kelas_name,
            'No. WhatsApp' => ($no_wa ? $no_wa : '-'),
            'Tanggal Pembukaan' => $tanggal_pembukaan . ' WIB',
            'Saldo Awal' => 'Rp 0'
        ];
        $max_label_length = max(array_map('strlen', array_keys($labels)));
        $max_label_length = max($max_label_length, 20);
        $text_rows = [];
        foreach ($labels as $label => $value) {
            $text_rows[] = str_pad($label, $max_label_length, ' ') . " : " . $value;
        }
        $details_text = implode("\n", $text_rows);

        $altBody = "SCHOBANK SYSTEM\n\nYth. {$nama},\n\nKami menginformasikan bahwa rekening digital Anda telah berhasil dibuka. Rincian akun:\n\n" .
                   $details_text . "\n\n" .
                   "PERINGATAN: Untuk keamanan, segera ubah kata sandi default dan atur PIN transaksi Anda setelah login pertama.\n" .
                   "Akses akun: {$login_link}\n" .
                   "Hubungi kami di: 081234567890 atau schobanksystem@gmail.com\n\n" .
                   "Hormat kami,\nTim SCHOBANK SYSTEM\n\n" .
                   "Email otomatis, mohon tidak membalas.";
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

// Function to send notifications
function sendNotifications($email, $no_wa, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF'], 2);
    $login_link = $protocol . "://" . $host . $path . "/login.php";

    $success = true;
    $max_duration = 0;

    if (!empty($email)) {
        $email_result = sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name, $no_wa);
        if (!$email_result['success']) {
            error_log("Peringatan: Gagal mengirim email ke: $email, tetapi data disimpan.");
            $success = false;
        } else {
            $max_duration = max($max_duration, $email_result['duration']);
        }
    }

    if (!empty($no_wa)) {
        $start_time = microtime(true);
        $wa_message = "Yth. {$nama},\n\n" .
                      "Rekening SCHOBANK Anda telah aktif dengan rincian:\n" .
                      "Nomor Rekening: {$no_rekening}\n" .
                      "Username: {$username}\n" .
                      "Password: {$password}\n" .
                      "Jurusan: {$jurusan_name}\n" .
                      "Tingkatan Kelas: {$tingkatan_name}\n" .
                      "Kelas: {$kelas_name}\n\n" .
                      "Silakan login di {$login_link} untuk mengakses akun Anda. Segera ubah kata sandi dan atur PIN transaksi.\n\n" .
                      "Hubungi kami di 081234567890 atau schobanksystem@gmail.com jika perlu bantuan.\n\n" .
                      "Hormat kami,\nTim SCHOBANK";
        if (sendWhatsAppMessage($no_wa, $wa_message)) {
            $end_time = microtime(true);
            $duration = round(($end_time - $start_time) * 1000);
            $max_duration = max($max_duration, $duration);
        } else {
            error_log("Peringatan: Gagal mengirim WhatsApp ke: $no_wa");
            $success = false;
        }
    }

    return ['success' => $success, 'duration' => $max_duration];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("Data POST diterima: " . print_r($_POST, true));

    // Handle cancel button
    if (isset($_POST['cancel'])) {
        header('Location: tambah_nasabah.php');
        exit();
    }

    // Handle confirmation
    if (isset($_POST['confirmed'])) {
        $nama = trim($_POST['nama'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = '12345';
        $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
        $tingkatan_kelas_id = (int)($_POST['tingkatan_kelas_id'] ?? 0);
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);
        $no_rekening = $_POST['no_rekening'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $no_wa = trim($_POST['no_wa'] ?? '');

        // Validate input
        if (empty($nama) || strlen($nama) < 3) {
            $errors['nama'] = "Nama harus minimal 3 karakter!";
        }
        if (empty($email) && empty($no_wa)) {
            $errors['contact'] = "Setidaknya salah satu dari Email atau Nomor WhatsApp harus diisi!";
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Format email tidak valid!";
        }
        if (!empty($no_wa) && !preg_match('/^\+?\d{10,15}$/', $no_wa)) {
            $errors['no_wa'] = "Nomor WhatsApp tidak valid!";
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
        if (empty($username)) {
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
            if (!empty($no_wa) && ($error = checkUniqueField('no_wa', $no_wa, $conn))) {
                $errors['no_wa'] = $error;
            }
            if ($error = checkUniqueField('no_rekening', $no_rekening, $conn, 'rekening')) {
                $no_rekening = 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                error_log("No rekening duplikat, generate ulang: $no_rekening");
            }
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                // Insert user
                $query = "INSERT INTO users (username, password, role, nama, jurusan_id, kelas_id, email, no_wa) 
                          VALUES (?, SHA2(?, 256), 'siswa', ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    error_log("Error preparing user insert: " . $conn->error);
                    throw new Exception("Gagal menyiapkan pernyataan untuk menyimpan user.");
                }
                $stmt->bind_param("sssiiss", $username, $password, $nama, $jurusan_id, $kelas_id, $email, $no_wa);
                if (!$stmt->execute()) {
                    error_log("Error saat menambah user: " . $stmt->error);
                    throw new Exception("Gagal menyimpan user.");
                }
                $user_id = $conn->insert_id;
                $stmt->close();

                // Insert rekening
                $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
                $stmt_rekening = $conn->prepare($query_rekening);
                if (!$stmt_rekening) {
                    error_log("Error preparing rekening insert: " . $conn->error);
                    throw new Exception("Gagal menyiapkan pernyataan untuk menyimpan rekening.");
                }
                $stmt_rekening->bind_param("si", $no_rekening, $user_id);
                if (!$stmt_rekening->execute()) {
                    error_log("Error saat membuat rekening: " . $stmt_rekening->error);
                    throw new Exception("Gagal menyimpan rekening.");
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

                // Send notifications
                $notification_result = sendNotifications($email, $no_wa, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name);
                if (!$notification_result['success']) {
                    error_log("Peringatan: Gagal mengirim notifikasi, tetapi data disimpan.");
                }

                $conn->commit();
                $show_success_popup = true;
                $notification_duration = $notification_result['duration'];
                // Redirect after 5 seconds (3s popup + 2s buffer)
                header("refresh:5;url=tambah_nasabah.php");
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Transaksi gagal: " . $e->getMessage());
                $errors['general'] = "Gagal menambah nasabah: " . $e->getMessage();
            }
        }
    } else {
        // Handle initial form submission
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $no_wa = trim($_POST['no_wa'] ?? '');
        $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
        $tingkatan_kelas_id = (int)($_POST['tingkatan_kelas_id'] ?? 0);
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);

        // Validate input
        if (empty($nama) || strlen($nama) < 3) {
            $errors['nama'] = "Nama harus minimal 3 karakter!";
        }
        if (empty($email) && empty($no_wa)) {
            $errors['contact'] = "Setidaknya salah satu dari Email atau Nomor WhatsApp harus diisi!";
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Format email tidak valid!";
        }
        if (!empty($no_wa) && !preg_match('/^\+?\d{10,15}$/', $no_wa)) {
            $errors['no_wa'] = "Nomor WhatsApp tidak valid!";
        }
        if ($jurusan_id <= 0) {
            $errors['jurusan_id'] = "Jurusan wajib dipilih!";
        }
        if ($tingkatan_kelas_id <= 0) {
            $errors['tingkatan_kelas_id'] = "Tingkatan kelas wajib dipilih!";
        }
        if ($kelas_id <= 0 && isset($_POST['kelas_id'])) {
            $errors['kelas_id'] = "Kelas wajib dipilih!";
        }

        // Validate unique fields
        if (!$errors) {
            if (!empty($email) && ($error = checkUniqueField('email', $email, $conn))) {
                $errors['email'] = $error;
            }
            if (!empty($no_wa) && ($error = checkUniqueField('no_wa', $no_wa, $conn))) {
                $errors['no_wa'] = $error;
            }
        }

        if (!$errors) {
            $username = generateUsername($nama, $conn);
            $no_rekening = 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

            if ($error = checkUniqueField('no_rekening', $no_rekening, $conn, 'rekening')) {
                $no_rekening = 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                error_log("No rekening duplikat (initial), generate ulang: $no_rekening");
            }

            // Fetch jurusan, tingkatan kelas, and kelas names
            $query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
            $stmt_jurusan = $conn->prepare($query_jurusan_name);
            if (!$stmt_jurusan) {
                error_log("Error preparing jurusan query (initial): " . $conn->error);
                $errors['jurusan_id'] = "Terjadi kesalahan saat mengambil data jurusan.";
            } else {
                $stmt_jurusan->bind_param("i", $jurusan_id);
                $stmt_jurusan->execute();
                $result_jurusan_name = $stmt_jurusan->get_result();
                if ($result_jurusan_name->num_rows === 0) {
                    $errors['jurusan_id'] = "Jurusan tidak ditemukan!";
                } else {
                    $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
                    $stmt_jurusan->close();

                    $query_tingkatan_name = "SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?";
                    $stmt_tingkatan = $conn->prepare($query_tingkatan_name);
                    if (!$stmt_tingkatan) {
                        error_log("Error preparing tingkatan kelas query (initial): " . $conn->error);
                        $errors['tingkatan_kelas_id'] = "Terjadi kesalahan saat mengambil data tingkatan kelas.";
                    } else {
                        $stmt_tingkatan->bind_param("i", $tingkatan_kelas_id);
                        $stmt_tingkatan->execute();
                        $result_tingkatan_name = $stmt_tingkatan->get_result();
                        if ($result_tingkatan_name->num_rows === 0) {
                            $errors['tingkatan_kelas_id'] = "Tingkatan kelas tidak ditemukan!";
                        } else {
                            $tingkatan_name = $result_tingkatan_name->fetch_assoc()['nama_tingkatan'];
                            $stmt_tingkatan->close();

                            $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
                            $stmt_kelas = $conn->prepare($query_kelas_name);
                            if (!$stmt_kelas) {
                                error_log("Error preparing kelas query (initial): " . $conn->error);
                                $errors['kelas_id'] = "Terjadi kesalahan saat mengambil data kelas.";
                            } else {
                                $stmt_kelas->bind_param("i", $kelas_id);
                                $stmt_kelas->execute();
                                $result_kelas_name = $stmt_kelas->get_result();
                                $kelas_name = $result_kelas_name->num_rows > 0 ? $result_kelas_name->fetch_assoc()['nama_kelas'] : '';
                                $stmt_kelas->close();

                                $formData = [
                                    'nama' => $nama,
                                    'username' => $username,
                                    'password' => '12345',
                                    'jurusan_id' => $jurusan_id,
                                    'tingkatan_kelas_id' => $tingkatan_kelas_id,
                                    'kelas_id' => $kelas_id,
                                    'no_rekening' => $no_rekening,
                                    'email' => $email,
                                    'no_wa' => $no_wa,
                                    'jurusan_name' => $jurusan_name,
                                    'tingkatan_name' => $tingkatan_name,
                                    'kelas_name' => $kelas_name
                                ];
                                $showConfirmation = true;
                            }
                        }
                    }
                }
            }
        }
    }
}
// Function to mask account number
function maskNoRekening($no_rekening) {
    if (strlen($no_rekening) >= 9) {
        return substr($no_rekening, 0, 4) . '****' . substr($no_rekening, -2);
    }
    return $no_rekening;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tambah Nasabah - SCHOBANK SYSTEM</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        nav button {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        nav button:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        nav h1 {
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
        }

        nav div:last-child {
            width: 40px;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
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
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
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
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        input[aria-invalid="true"], select[aria-invalid="true"] {
            border-color: var(--danger-color);
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        select {
            appearance: none;
            position: relative;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--text-secondary);
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
            z-index: 10;
        }

        .form-group:hover .tooltip {
            opacity: 1;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        .btn:active {
            /* Removed transform: scale(0.95) to prevent size change */
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-confirm {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .btn-confirm:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-cancel {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #d32f2f 0%, var(--danger-color) 100%);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading:not(.btn-cancel)::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
            width: 100%;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.5s ease, visibility 0s 0.5s;
            cursor: default;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
            transition: opacity 0.5s ease;
        }

        .success-modal, .error-modal, .confirm-modal {
            position: relative;
            text-align: center;
            width: clamp(320px, 80vw, 450px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 20px;
            padding: clamp(15px, 2.5vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: default;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow-y: auto;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before, .confirm-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon, .confirm-icon {
            font-size: clamp(2.8rem, 5vw, 3.2rem);
            margin: 0 auto 10px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3, .confirm-modal h3 {
            color: white;
            margin: 0 0 10px;
            font-size: clamp(1.2rem, 2.2vw, 1.4rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p, .confirm-modal p {
            color: white;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin: 0;
            line-height: 1.5;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: white;
            font-size: clamp(0.8rem, 1.7vw, 0.9rem);
        }

        .modal-value {
            font-weight: 600;
            color: white;
            font-size: clamp(0.8rem, 1.7vw, 0.9rem);
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .modal-buttons .btn {
            max-width: 140px;
            padding: 10px 18px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            flex: 1;
            min-width: 100px;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 4rem;
        }

        .loading-spinner {
            font-size: 1.2rem;
            color: var(--primary-color);
            animation: spin 1s linear infinite;
        }

        @media (max-width: 768px) {
            nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .success-modal, .error-modal, .confirm-modal {
                width: clamp(300px, 85vw, 400px);
                padding: clamp(12px, 2vw, 15px);
                margin: 8px auto;
                max-height: 85vh;
            }

            .success-icon, .error-icon, .confirm-icon {
                font-size: clamp(2.5rem, 4.5vw, 2.8rem);
            }

            .success-modal h3, .error-modal h3, .confirm-modal h3 {
                font-size: clamp(1.1rem, 2vw, 1.2rem);
            }

            .success-modal p, .error-modal p, .confirm-modal p {
                font-size: clamp(0.8rem, 1.7vw, 0.9rem);
            }

            .modal-content {
                padding: 8px 10px;
                gap: 5px;
            }

            .modal-row {
                padding: 5px 0;
            }

            .modal-label, .modal-value {
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            }

            .modal-buttons .btn {
                max-width: 130px;
                padding: 8px 16px;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input, select pasaj {
                min-height: 40px;
            }

            .success-modal, .error-modal, .confirm-modal {
                width: clamp(280px, 90vw, 360px);
                padding: clamp(10px, 2vw, 12px);
                margin: 6px auto;
                max-height: 80vh;
            }

            .success-icon, .error-icon, .confirm-icon {
                font-size: clamp(2.2rem, 4vw, 2.5rem);
            }

            .success-modal h3, .error-modal h3, .confirm-modal h3 {
                font-size: clamp(1rem, 1.9vw, 1.1rem);
            }

            .success-modal p, .error-modal p, .confirm-modal p {
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            }

            .modal-content {
                padding: 6px 8px;
            }

            .modal-row {
                padding: 4px 0;
            }

            .modal-label, .modal-value {
                font-size: clamp(0.7rem, 1.5vw, 0.8rem);
            }

            .modal-buttons .btn {
                max-width: 120px;
                padding: 8px 14px;
                min-width: 90px;
            }
        }
    </style>
</head>
<body>
    <nav>
        <button onclick="window.location.href='dashboard.php'"><i class="fas fa-xmark"></i></button>
        <h1>SCHOBANK</h1>
        <div></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Tambah Nasabah</h2>
            <p>Kelola data nasabah untuk sistem SCHOBANK</p>
        </div>

<?php if (!$showConfirmation && !$show_success_popup && empty($errors['general'])): ?>
    <div class="form-section">
        <form action="" method="POST" id="nasabahForm">
            <div class="form-container">
                <div class="form-column">
                    <div class="form-group">
                        <label for="nama">Nama Lengkap</label>
                        <input type="text" id="nama" name="nama" required 
                               value="<?php echo htmlspecialchars($_POST['nama'] ?? ''); ?>" 
                               aria-invalid="<?php echo isset($errors['nama']) ? 'true' : 'false'; ?>">
                        <span class="error-message <?php echo isset($errors['nama']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['nama'] ?? ''); ?>
                        </span>
                        <span class="tooltip">Masukkan nama lengkap nasabah</span>
                    </div>

                    <div class="form-group">
                        <label for="email">Email (Bisa Isi Email/Whatsapp)</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                               aria-invalid="<?php echo isset($errors['email']) ? 'true' : 'false'; ?>">
                        <span class="error-message <?php echo isset($errors['email']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['email'] ?? ''); ?>
                        </span>
                        <span class="tooltip">Masukkan email yang valid</span>
                    </div>

                    <div class="form-group">
                        <label for="no_wa">Nomor WhatsApp (Bisa Isi Email/Whatsapp)</label>
                        <input type="tel" id="no_wa" name="no_wa" 
                               value="<?php echo htmlspecialchars($_POST['no_wa'] ?? ''); ?>" 
                               aria-invalid="<?php echo isset($errors['no_wa']) ? 'true' : 'false'; ?>">
                        <span class="error-message <?php echo isset($errors['no_wa']) ? 'show' : ''; ?>">
                            <?php echo htmlspecialchars($errors['no_wa'] ?? ''); ?>
                        </span>
                        <span class="tooltip">Masukkan nomor WhatsApp (contoh: +6281234567890)</span>
                    </div>

                    <span class="error-message <?php echo isset($errors['contact']) ? 'show' : ''; ?>">
                        <?php echo htmlspecialchars($errors['contact'] ?? ''); ?>
                    </span>
                </div>

                <div class="form-column">
                    <div class="form-group select-wrapper">
                        <label for="jurusan_id">Jurusan</label>
                        <select id="jurusan_id" name="jurusan_id" required onchange="updateKelas()" 
                                aria-invalid="<?php echo isset($errors['jurusan_id']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Jurusan</option>
                            <?php 
                            if ($result_jurusan && $result_jurusan->num_rows > 0) {
                                $result_jurusan->data_seek(0);
                                while ($row = $result_jurusan->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $row['id']; ?>" 
                                    <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
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
                        <span class="tooltip">Pilih jurusan nasabah</span>
                    </div>

                    <div class="form-group select-wrapper">
                        <label for="tingkatan_kelas_id">Tingkatan Kelas</label>
                        <select id="tingkatan_kelas_id" name="tingkatan_kelas_id" required onchange="updateKelas()" 
                                aria-invalid="<?php echo isset($errors['tingkatan_kelas_id']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Tingkatan Kelas</option>
                            <?php 
                            if ($result_tingkatan && $result_tingkatan->num_rows > 0) {
                                $result_tingkatan->data_seek(0);
                                while ($row = $result_tingkatan->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $row['id']; ?>" 
                                    <?php echo (isset($_POST['tingkatan_kelas_id']) && $_POST['tingkatan_kelas_id'] == $row['id']) ? 'selected' : ''; ?>>
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
                        <span class="tooltip">Pilih tingkatan kelas nasabah</span>
                    </div>

                    <div class="form-group select-wrapper">
                        <label for="kelas_id">Kelas</label>
                        <select id="kelas_id" name="kelas_id" required 
                                aria-invalid="<?php echo isset($errors['kelas_id']) ? 'true' : 'false'; ?>">
                            <option value="">Pilih Kelas</option>
                        </select>
                        <span class="error-message" id="kelas_id-error">
                            <?php echo htmlspecialchars($errors['kelas_id'] ?? ''); ?>
                        </span>
                        <span class="tooltip">Pilih kelas nasabah</span>
                    </div>
                </div>
            </div>

            <div class="form-buttons">
                <button type="submit" id="submit-btn" class="btn btn-confirm">
                    <span class="btn-content">Tambah</span>
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>


        <?php if ($showConfirmation && empty($errors['general'])): ?>
            <div class="modal-overlay active" id="confirmation-popup">
                <div class="confirm-modal">
                    <div class="confirm-icon"><i class="fas fa-user-check"></i></div>
                    <h3>Konfirmasi Data Nasabah</h3>
                    <div class="modal-content">
                        <div class="modal-row"><span class="modal-label">Nama:</span> <span class="modal-value"><?php echo htmlspecialchars($formData['nama']); ?></span></div>
                        <div class="modal-row"><span class="modal-label">Email:</span> <span class="modal-value"><?php echo htmlspecialchars($formData['email'] ?: '-'); ?></span></div>
                        <div class="modal-row"><span class="modal-label">WhatsApp:</span> <span class="modal-value"><?php echo $formData['no_wa'] ? htmlspecialchars($formData['no_wa']) : '-'; ?></span></div>
                        <div class="modal-row"><span class="modal-label">Jurusan:</span> <span class="modal-value"><?php echo htmlspecialchars($formData['jurusan_name']); ?></span></div>
                        <div class="modal-row"><span class="modal-label">Tingkatan:</span> <span class="modal-value"><?php echo htmlspecialchars($formData['tingkatan_name']); ?></span></div>
                        <div class="modal-row"><span class="modal-label">Kelas:</span> <span class="modal-value"><?php echo htmlspecialchars($formData['kelas_name']); ?></span></div>
                    </div>
                    <p>Username, kata sandi, dan nomor rekening akan dikirim melalui email/WhatsApp terdaftar setelah konfirmasi.</p>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama" value="<?php echo htmlspecialchars($formData['nama']); ?>">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($formData['jurusan_id']); ?>">
                        <input type="hidden" name="tingkatan_kelas_id" value="<?php echo htmlspecialchars($formData['tingkatan_kelas_id']); ?>">
                        <input type="hidden" name="kelas_id" value="<?php echo htmlspecialchars($formData['kelas_id']); ?>">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($formData['no_rekening']); ?>">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>">
                        <input type="hidden" name="no_wa" value="<?php echo htmlspecialchars($formData['no_wa']); ?>">
                        <input type="hidden" name="confirmed" value="1">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" id="confirm-btn" class="btn btn-confirm"> <span class="btn-content">Konfirmasi</span></button>
                            <button type="submit" name="cancel" id="cancel-btn" class="btn btn-cancel"> <span class="btn-content">Batal</span></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($show_success_popup): ?>
            <div class="modal-overlay active" id="success-popup">
                <div class="success-modal">
                    <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                    <h3>Pembukaan Rekening Digital Berhasil</h3>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="modal-overlay active" id="error-popup">
                <div class="error-modal">
                    <div class="error-icon"><i class="fas fa-exclamation-circle"></i></div>
                    <h3>Gagal</h3>
                    <p><?php echo htmlspecialchars($errors['general']); ?></p>
                    <div class="modal-buttons">
                        <button type="button" class="btn" onclick="window.location.reload()"><i class="fas fa-redo"></i> <span class="btn-content">Coba Lagi</span></button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateKelas() {
            const jurusanId = document.getElementById('jurusan_id').value;
            const tingkatanId = document.getElementById('tingkatan_kelas_id').value;
            const kelasSelect = document.getElementById('kelas_id');
            const kelasError = document.getElementById('kelas_id-error');

            if (!jurusanId || !tingkatanId) {
                kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                return;
            }

            kelasSelect.disabled = true;
            kelasSelect.innerHTML = '<option value="">Memuat kelas...</option>';

            fetch('get_kelas_by_jurusan_dan_tingkatan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `jurusan_id=${encodeURIComponent(jurusanId)}&tingkatan_kelas_id=${encodeURIComponent(tingkatanId)}`
            })
            .then(response => response.json())
            .then(data => {
                kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                if (data.error) {
                    kelasError.textContent = data.error;
                    kelasError.classList.add('show');
                } else if (data.kelas && data.kelas.length > 0) {
                    data.kelas.forEach(k => {
                        const option = document.createElement('option');
                        option.value = k.id;
                        option.textContent = k.nama_kelas;
                        kelasSelect.appendChild(option);
                    });
                    kelasError.textContent = '';
                    kelasError.classList.remove('show');
                } else {
                    kelasError.textContent = 'Tidak ada kelas tersedia untuk jurusan dan tingkatan ini.';
                    kelasError.classList.add('show');
                }
                kelasSelect.disabled = false;
            })
            .catch(error => {
                console.error('Error fetching kelas:', error);
                kelasSelect.innerHTML = '<option value="">Error memuat kelas</option>';
                kelasError.textContent = 'Gagal memuat kelas.';
                kelasError.classList.add('show');
                kelasSelect.disabled = false;
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('nasabahForm');
            const submitBtn = document.getElementById('submit-btn');
            if (form && submitBtn) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const nama = document.getElementById('nama').value.trim();
                    const jurusan = document.getElementById('jurusan_id').value;
                    const tingkatan = document.getElementById('tingkatan_kelas_id').value;
                    const kelas = document.getElementById('kelas_id').value;
                    if (nama.length < 3) {
                        alert('Nama minimal 3 karakter!');
                    } else if (!jurusan) {
                        alert('Pilih jurusan!');
                    } else if (!tingkatan) {
                        alert('Pilih tingkatan kelas!');
                    } else if (!kelas) {
                        alert('Pilih kelas!');
                    } else {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('loading');
                        setTimeout(() => form.submit(), 1000);
                    }
                });
            }

            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            const cancelBtn = document.getElementById('cancel-btn');
            if (confirmForm && confirmBtn && cancelBtn) {
                confirmForm.addEventListener('submit', (e) => {
                    const submitter = e.submitter;
                    if (submitter.id === 'confirm-btn') {
                        e.preventDefault();
                        confirmBtn.disabled = true;
                        confirmBtn.classList.add('loading');
                        setTimeout(() => confirmForm.submit(), 1000);
                    } else if (submitter.id === 'cancel-btn') {
                        // No loading spinner for cancel
                        confirmForm.submit();
                    }
                });
            }

            // Auto-close success popup after 3 seconds
            const successPopup = document.getElementById('success-popup');
            if (successPopup) {
                setTimeout(() => {
                    successPopup.classList.remove('active');
                    setTimeout(() => {
                        successPopup.remove();
                    }, 500); // Match transition duration
                }, 3000); // Display for 3 seconds
            }

            // Ensure kelas is updated if jurusan or tingkatan is pre-selected
            const jurusanId = document.getElementById('jurusan_id')?.value;
            const tingkatanId = document.getElementById('tingkatan_kelas_id')?.value;
            if (jurusanId && tingkatanId) {
                updateKelas();
            }
        });
    </script>
</body>
</html>