<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Mulai sesi jika belum aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Batasi akses hanya untuk petugas
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai petugas terlebih dahulu!'));
    exit();
}

// Aktifkan debugging untuk development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/laragon/www/schobank/logs/error.log');

// Ambil data jurusan
$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);
if (!$result_jurusan) {
    error_log("Error fetching jurusan: " . $conn->error);
    die("Terjadi kesalahan saat mengambil data jurusan.");
}

// Inisialisasi variabel
$showConfirmation = false;
$formData = [];
$show_success_popup = false;
$errors = [];

// Fungsi untuk menghasilkan username unik
function generateUsername($nama, $conn) {
    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
    $username = $base_username;
    $check_query = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_query);
    if (!$check_stmt) {
        error_log("Error preparing username check: " . $conn->error);
        return $base_username . rand(100, 999);
    }
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows > 0) {
        $username = $base_username . rand(100, 999);
    }
    $check_stmt->close();
    return $username;
}

// Fungsi untuk memeriksa keunikan field
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

// Fungsi untuk mengirim pesan WhatsApp menggunakan Fonnte API
function sendWhatsAppMessage($phone_number, $message) {
    $curl = curl_init();
    
    if (substr($phone_number, 0, 2) === '08') {
        $phone_number = '62' . substr($phone_number, 1);
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'target' => $phone_number,
            'message' => $message
        ),
        CURLOPT_HTTPHEADER => array(
            'Authorization: dCjq3fJVf9p2DAfVDVED'
        ),
    ));
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($http_code == 200) {
        error_log("WhatsApp message sent successfully to: $phone_number");
        return true;
    } else {
        error_log("Failed to send WhatsApp message to $phone_number: HTTP $http_code, Response: $response");
        return false;
    }
}

// Fungsi untuk mengirim email konfirmasi
function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $kelas_name, $no_wa = '') {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email, $nama);

        $mail->isHTML(true);
        $mail->Subject = 'Pembukaan Rekening SCHOBANK Berhasil';

        // Generate dynamic login link
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF'], 2); // Navigate two levels up to reach /pages/
        $login_link = $protocol . "://" . $host . $path . "/login.php";

        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e0e0e0;'>
            <div style='background-color: #1e3a8a; padding: 20px; text-align: center;'>
                <h2 style='color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;'>SCHOBANK SYSTEM</h2>
            </div>
            <div style='padding: 30px;'>
                <h3 style='color: #1e3a8a; font-size: 20px; margin-bottom: 20px; font-weight: 600;'>Pembukaan Rekening Berhasil</h3>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Yth. {$nama},</p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami dengan senang hati menginformasikan bahwa rekening digital Anda di SCHOBANK SYSTEM telah berhasil dibuka. Berikut adalah rincian akun Anda:</p>
                <table style='width: 100%; border-collapse: collapse; font-size: 14px; color: #333333; margin-bottom: 20px;'>
                    <tbody>
                        <tr style='background-color: #f8fafc;'>
                            <td style='padding: 12px 15px; font-weight: 500; width: 150px;'>Nama</td>
                            <td style='padding: 12px 15px;'>{$nama}</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 15px; font-weight: 500;'>No. Rekening</td>
                            <td style='padding: 12px 15px;'>{$no_rekening}</td>
                        </tr>
                        <tr style='background-color: #f8fafc;'>
                            <td style='padding: 12px 15px; font-weight: 500;'>Username</td>
                            <td style='padding: 12px 15px;'>{$username}</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 15px; font-weight: 500;'>Password</td>
                            <td style='padding: 12px 15px;'>{$password}</td>
                        </tr>
                        <tr style='background-color: #f8fafc;'>
                            <td style='padding: 12px 15px; font-weight: 500;'>Jurusan</td>
                            <td style='padding: 12px 15px;'>{$jurusan_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 15px; font-weight: 500;'>Kelas</td>
                            <td style='padding: 12px 15px;'>{$kelas_name}</td>
                        </tr>
                        <tr style='background-color: #f8fafc;'>
                            <td style='padding: 12px 15px; font-weight: 500;'>No. WhatsApp</td>
                            <td style='padding: 12px 15px;'>" . ($no_wa ? $no_wa : '-') . "</td>
                        </tr>
                    </tbody>
                </table>
                <div style='background-color: #fef2f2; border: 1px solid #f87171; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>
                    <p style='color: #b91c1c; font-size: 16px; font-weight: 600; margin: 0 0 10px; display: flex; align-items: center; gap: 8px;'>
                        <span style='font-size: 20px;'>⚠️</span> Peringatan Keamanan
                    </p>
                    <p style='color: #7f1d1d; font-size: 14px; line-height: 1.5; margin: 0;'>
                        Untuk menjaga keamanan akun Anda, <strong>segera ubah kata sandi</strong> default setelah login pertama. Selain itu, <strong>atur PIN transaksi</strong> Anda sesegera mungkin melalui pengaturan akun untuk perlindungan tambahan.
                    </p>
                </div>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Silakan akses akun Anda untuk mulai menggunakan layanan SCHOBANK SYSTEM:</p>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <a href='{$login_link}' style='display: inline-block; background-color: #1e3a8a; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 16px;'>Login Sekarang</a>
                </div>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Jika Anda memiliki pertanyaan atau membutuhkan bantuan, silakan hubungi kami melalui:</p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>
                    Email: <a href='mailto:bantuan.schobank@gmail.com' style='color: #1e3a8a; text-decoration: none; font-weight: 500;'>bantuan.schobank@gmail.com</a>
                </p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6;'>Hormat kami,<br><strong>Tim SCHOBANK SYSTEM</strong></p>
            </div>
            <div style='text-align: center; padding: 15px; background-color: #f8fafc; color: #666666; font-size: 12px; border-top: 1px solid #e0e0e0;'>
                <p style='margin: 0;'>Email ini bersifat otomatis. Mohon untuk tidak membalas email ini.</p>
                <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
            </div>
        </div>";

        $mail->Body = $emailBody;

        $labels = [
            'Nama' => $nama,
            'No. Rekening' => $no_rekening,
            'Username' => $username,
            'Password' => $password,
            'Jurusan' => $jurusan_name,
            'Kelas' => $kelas_name,
            'No. WhatsApp' => ($no_wa ? $no_wa : '-'),
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
                   "Hubungi kami di: 081234567890 atau support@schobank.xai\n\n" .
                   "Hormat kami,\nTim SCHOBANK SYSTEM\n\n" .
                   "Email otomatis, mohon tidak membalas.";
        $mail->AltBody = $altBody;

        error_log("Mengirim email ke: $email");
        $mail->send();
        error_log("Email berhasil dikirim ke: $email");
        return true;
    } catch (Exception $e) {
        error_log("Gagal mengirim email ke $email: " . $mail->ErrorInfo);
        return false;
    }
}

// Fungsi untuk mengirim notifikasi berdasarkan input
function sendNotifications($email, $no_wa, $nama, $username, $no_rekening, $password, $jurusan_name, $kelas_name) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF'], 2);
    $login_link = $protocol . "://" . $host . $path . "/login.php";

    $success = true;

    $labels = [
        'Nama' => $nama,
        'No. Rekening' => $no_rekening,
        'Username' => $username,
        'Password' => $password,
        'Jurusan' => $jurusan_name,
        'Kelas' => $kelas_name,
        'No. WhatsApp' => ($no_wa ? $no_wa : '-'),
        'Saldo Awal' => 'Rp 0'
    ];
    $max_label_length = max(array_map('strlen', array_keys($labels)));
    $max_label_length = max($max_label_length, 20);
    $text_rows = [];
    foreach ($labels as $label => $value) {
        $text_rows[] = str_pad($label, $max_label_length, ' ') . " : " . $value;
    }
    $details_text = implode("\n", $text_rows);

    if (!empty($email)) {
        if (!sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $kelas_name, $no_wa)) {
            error_log("Peringatan: Gagal mengirim email ke: $email, tetapi data disimpan.");
            $success = false;
        }
    }

    if (!empty($no_wa)) {
        $wa_message = "SCHOBANK SYSTEM\n\nYth. {$nama},\n\nRekening digital Anda telah berhasil dibuka. Rincian:\n\n" .
                      $details_text . "\n\n" .
                      "PERINGATAN: Segera ubah kata sandi dan atur PIN transaksi setelah login pertama.\n" .
                      "Login: {$login_link}\n" .
                      "Hubungi: 081234567890 atau support@schobank.xai\n\n" .
                      "Pesan otomatis, jangan balas.";
        if (!sendWhatsAppMessage($no_wa, $wa_message)) {
            error_log("Peringatan: Gagal mengirim WhatsApp ke: $no_wa");
            $success = false;
        }
    }

    return $success;
}

// Penanganan form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("Data POST diterima: " . print_r($_POST, true));

    // Penanganan tombol batal
    if (isset($_POST['cancel'])) {
        header('Location: tambah_nasabah.php');
        exit();
    }

    // Penanganan konfirmasi
    if (isset($_POST['confirmed'])) {
        $nama = trim($_POST['nama'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = '12345';
        $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);
        $no_rekening = $_POST['no_rekening'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $no_wa = trim($_POST['no_wa'] ?? '');

        // Validasi input
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
        if ($kelas_id <= 0) {
            $errors['kelas_id'] = "Kelas wajib dipilih!";
        }
        if (empty($username)) {
            $errors['username'] = "Username tidak valid!";
        }
        if (empty($no_rekening)) {
            $errors['no_rekening'] = "Nomor rekening tidak valid!";
        }

        // Validasi unik
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

                // Ambil nama jurusan dan kelas
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

                // Kirim notifikasi
                $notifications_sent = sendNotifications($email, $no_wa, $nama, $username, $no_rekening, $password, $jurusan_name, $kelas_name);
                if (!$notifications_sent) {
                    error_log("Peringatan: Gagal mengirim notifikasi, tetapi data disimpan.");
                }

                $conn->commit();
                $show_success_popup = true;
                header('refresh:3;url=tambah_nasabah.php');
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Transaksi gagal: " . $e->getMessage());
                $errors['general'] = "Gagal menambah nasabah: " . $e->getMessage();
            }
        }
    } else {
        // Penanganan pengisian form awal
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $no_wa = trim($_POST['no_wa'] ?? '');
        $jurusan_id = (int)($_POST['jurusan_id'] ?? 0);
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);

        // Validasi input
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
        if ($kelas_id <= 0) {
            $errors['kelas_id'] = "Kelas wajib dipilih!";
        }

        // Validasi unik
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

            // Ambil nama jurusan dan kelas
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
                    $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
                    $stmt_kelas = $conn->prepare($query_kelas_name);
                    if (!$stmt_kelas) {
                        error_log("Error preparing kelas query (initial): " . $conn->error);
                        $errors['kelas_id'] = "Terjadi kesalahan saat mengambil data kelas.";
                    } else {
                        $stmt_kelas->bind_param("i", $kelas_id);
                        $stmt_kelas->execute();
                        $result_kelas_name = $stmt_kelas->get_result();
                        if ($result_kelas_name->num_rows === 0) {
                            $errors['kelas_id'] = "Kelas tidak ditemukan!";
                        } else {
                            $kelas_name = $result_kelas_name->fetch_assoc()['nama_kelas'];
                            $formData = [
                                'nama' => $nama,
                                'username' => $username,
                                'password' => '12345',
                                'jurusan_id' => $jurusan_id,
                                'kelas_id' => $kelas_id,
                                'no_rekening' => $no_rekening,
                                'email' => $email,
                                'no_wa' => $no_wa,
                                'jurusan_name' => $jurusan_name,
                                'kelas_name' => $kelas_name
                            ];
                            $showConfirmation = true;
                        }
                        $stmt_kelas->close();
                    }
                }
                $stmt_jurusan->close();
            }
        }
    }
}

// Fungsi untuk memformat nomor rekening
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
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

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
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
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
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

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
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
            animation: fadeInOverlay 0.5s ease-in-out forwards;
            cursor: default;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal, .confirm-modal {
            position: relative;
            text-align: center;
            width: clamp(320px, 80vw, 450px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 20px;
            padding: clamp(20px, 3vw, 25px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: default;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
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
            margin: 0 auto 15px;
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
            margin: 0 0 15px;
            font-size: clamp(1.2rem, 2.2vw, 1.4rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p, .confirm-modal p {
            color: white;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin: 0 0 20px;
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
            padding: 12px 15px;
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-grow: 1;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
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
            gap: 15px;
            justify-content: center;
            margin-top: 15px;
        }

        .modal-buttons .btn {
            flex: 1;
            max-width: 200px;
            padding: 12px 20px;
            font-size: clamp(0.9rem, 2vw, 1rem);
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
            animation: spin 1.5s linear infinite;
        }

        @media (max-width: 768px) {
            .top-nav {
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

            .modal-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .success-modal, .error-modal, .confirm-modal {
                width: clamp(300px, 85vw, 400px);
                padding: clamp(15px, 3vw, 20px);
                margin: 10px auto;
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
                padding: 10px 12px;
                gap: 6px;
            }

            .modal-row {
                padding: 6px 0;
            }

            .modal-label, .modal-value {
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input, select {
                min-height: 40px;
            }

            .success-modal, .error-modal, .confirm-modal {
                width: clamp(280px, 90vw, 360px);
                padding: clamp(12px, 2.5vw, 15px);
                margin: 8px auto;
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
                padding: 8px 10px;
            }

            .modal-row {
                padding: 5px 0;
            }

            .modal-label, .modal-value {
                font-size: clamp(0.7rem, 1.5vw, 0.8rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Buka Rekening Digital</h2>
            <p>Layanan pembukaan rekening digital untuk siswa baru</p>
        </div>

        <div id="alertContainer"></div>

        <?php if (!$showConfirmation && !$show_success_popup && empty($errors['general'])): ?>
            <div class="form-section">
                <form action="" method="POST" id="nasabahForm" class="form-container">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="nama">Nama Lengkap</label>
                            <input type="text" id="nama" name="nama" required value="<?php echo htmlspecialchars($_POST['nama'] ?? ''); ?>" placeholder="Masukkan nama lengkap">
                            <span class="tooltip">Masukkan nama lengkap siswa</span>
                            <span class="error-message" id="nama-error"><?php echo htmlspecialchars($errors['nama'] ?? ''); ?></span>
                        </div>
                        <div class="form-group">
                            <label for="email">Email (Opsional)</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="Masukkan email">
                            <span class="tooltip">Masukkan alamat email aktif</span>
                            <span class="error-message" id="email-error"><?php echo htmlspecialchars($errors['email'] ?? ''); ?></span>
                        </div>
                        <div class="form-group">
                            <label for="no_wa">Nomor WhatsApp (Opsional)</label>
                            <input type="tel" id="no_wa" name="no_wa" value="<?php echo htmlspecialchars($_POST['no_wa'] ?? ''); ?>" placeholder="Masukkan nomor WhatsApp (contoh: 081234567890)">
                            <span class="tooltip">Masukkan nomor WhatsApp aktif</span>
                            <span class="error-message" id="no_wa-error"><?php echo htmlspecialchars($errors['no_wa'] ?? ''); ?></span>
                        </div>
                        <span class="error-message" id="contact-error"><?php echo htmlspecialchars($errors['contact'] ?? ''); ?></span>
                    </div>
                    <div class="form-column">
                        <div class="form-group">
                            <label for="jurusan_id">Jurusan</label>
                            <div class="select-wrapper">
                                <select id="jurusan_id" name="jurusan_id" required onchange="getKelasByJurusan(this.value)">
                                    <option value="">Pilih Jurusan</option>
                                    <?php 
                                    if ($result_jurusan && $result_jurusan->num_rows > 0) {
                                        $result_jurusan->data_seek(0);
                                        while ($row = $result_jurusan->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                    <?php } else { ?>
                                        <option value="">Tidak ada jurusan tersedia</option>
                                    <?php } ?>
                                </select>
                                <span class="tooltip">Pilih jurusan siswa</span>
                            </div>
                            <span class="error-message" id="jurusan_id-error"><?php echo htmlspecialchars($errors['jurusan_id'] ?? ''); ?></span>
                        </div>
                        <div class="form-group">
                            <label for="kelas_id">Kelas</label>
                            <div class="select-wrapper">
                                <select id="kelas_id" name="kelas_id" required>
                                    <option value="">Pilih Kelas</option>
                                </select>
                                <span class="tooltip">Pilih kelas siswa</span>
                            </div>
                            <div id="kelas-loading" class="loading" style="display: none;">
                                <div class="loading-spinner"><i class="fas fa-spinner"></i></div>
                            </div>
                            <span class="error-message" id="kelas_id-error"><?php echo htmlspecialchars($errors['kelas_id'] ?? ''); ?></span>
                        </div>
                    </div>
                    <div class="form-buttons">
                        <button type="submit" class="btn" id="submit-btn">
                            <span class="btn-content"><i class="fas fa-user-plus"></i> Lanjutkan</span>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($showConfirmation && empty($errors['general'])): ?>
            <div class="modal-overlay" id="confirmModal">
                <div class="confirm-modal">
                    <div class="confirm-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3>Konfirmasi Data Nasabah</h3>
                    <div class="modal-content">
                        <div class="modal-row">
                            <span class="modal-label">Nama</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['nama']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Username</span>
                            <span class="modal-value">***</span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Password</span>
                            <span class="modal-value">***</span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Email</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['email'] ?: '-'); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No WhatsApp</span>
                            <span class="modal-value"><?php echo $formData['no_wa'] ? htmlspecialchars($formData['no_wa']) : '-'; ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['jurusan_name']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['kelas_name']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No Rekening</span>
                            <span class="modal-value"><?php echo htmlspecialchars(maskNoRekening($formData['no_rekening'])); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama" value="<?php echo htmlspecialchars($formData['nama']); ?>">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($formData['jurusan_id']); ?>">
                        <input type="hidden" name="kelas_id" value="<?php echo htmlspecialchars($formData['kelas_id']); ?>">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($formData['no_rekening']); ?>">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>">
                        <input type="hidden" name="no_wa" value="<?php echo htmlspecialchars($formData['no_wa']); ?>">
                        <input type="hidden" name="confirmed" value="1">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                            </button>
                            <button type="submit" name="cancel" class="btn btn-cancel">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($show_success_popup): ?>
            <div class="modal-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Pembukaan Rekening Berhasil</h3>
                    <div class="modal-content">
                        <div class="modal-row">
                            <span class="modal-label">Nama</span>
                            <span class="modal-value"><?php echo htmlspecialchars($nama); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Username</span>
                            <span class="modal-value">***</span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Email</span>
                            <span class="modal-value"><?php echo htmlspecialchars($email ?: '-'); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No WhatsApp</span>
                            <span class="modal-value"><?php echo $no_wa ? htmlspecialchars($no_wa) : '-'; ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($jurusan_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars($kelas_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No Rekening</span>
                            <span class="modal-value"><?php echo htmlspecialchars(maskNoRekening($no_rekening)); ?></span>
                        </div>
                    </div>
                    <div class="modal-buttons">
                        <button class="btn btn-confirm" onclick="window.location.href='dashboard.php'">
                            <span class="btn-content">Kembali</span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="modal-overlay" id="errorModal">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p><?php echo htmlspecialchars($errors['general']); ?></p>
                    <div class="modal-buttons">
                        <button class="btn btn-confirm" onclick="window.location.reload()">
                            <span class="btn-content"><i class="fas fa-redo"></i> Coba Lagi</span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming and double-tap issues
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            // Modal close handling
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                }
            }

            // Only success and error modals close on overlay click
            ['successModal', 'errorModal'].forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('click', (e) => {
                        if (e.target.classList.contains('modal-overlay')) {
                            closeModal(modalId);
                        }
                    });
                }
            });

            // Auto-close success modal
            const successModal = document.getElementById('successModal');
            if (successModal) {
                setTimeout(() => closeModal('successModal'), 3000);
            }

            // Show error messages
            <?php foreach ($errors as $field => $message): ?>
                if (document.getElementById('<?php echo $field; ?>-error')) {
                    document.getElementById('<?php echo $field; ?>-error').classList.add('show');
                }
            <?php endforeach; ?>

            // Alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'modal-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => closeModal(alertDiv.id), 5000);
                alertDiv.addEventListener('click', (e) => {
                    if (e.target.classList.contains('modal-overlay')) {
                        closeModal(alertDiv.id);
                    }
                });
                alertDiv.id = 'alert-' + Date.now();
            }

            // Form submission handling
            const nasabahForm = document.getElementById('nasabahForm');
            const submitBtn = document.getElementById('submit-btn');
            
            if (nasabahForm && submitBtn) {
                nasabahForm.addEventListener('submit', function(e) {
                    if (nasabahForm.classList.contains('submitting')) return;
                    nasabahForm.classList.add('submitting');

                    const nama = document.getElementById('nama').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        document.getElementById('nama-error').textContent = 'Nama harus minimal 3 karakter!';
                        document.getElementById('nama-error').classList.add('show');
                        document.getElementById('nama').focus();
                        nasabahForm.classList.remove('submitting');
                        return;
                    } else {
                        document.getElementById('nama-error').classList.remove('show');
                    }

                    const email = document.getElementById('email').value.trim();
                    const no_wa = document.getElementById('no_wa').value.trim();
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!email && !no_wa) {
                        e.preventDefault();
                        document.getElementById('contact-error').textContent = 'Setidaknya salah satu dari Email atau Nomor WhatsApp harus diisi!';
                        document.getElementById('contact-error').classList.add('show');
                        document.getElementById('email').focus();
                        nasabahForm.classList.remove('submitting');
                        return;
                    } else {
                        document.getElementById('contact-error').classList.remove('show');
                    }

                    if (email && !emailPattern.test(email)) {
                        e.preventDefault();
                        document.getElementById('email-error').textContent = 'Format email tidak valid!';
                        document.getElementById('email-error').classList.add('show');
                        document.getElementById('email').focus();
                        nasabahForm.classList.remove('submitting');
                        return;
                    } else {
                        document.getElementById('email-error').classList.remove('show');
                    }

                    if (no_wa && !/^\+?\d{10,15}$/.test(no_wa)) {
                        e.preventDefault();
                        document.getElementById('no_wa-error').textContent = 'Nomor WhatsApp tidak valid!';
                        document.getElementById('no_wa-error').classList.add('show');
                        document.getElementById('no_wa').focus();
                        nasabahForm.classList.remove('submitting');
                        return;
                    } else {
                        document.getElementById('no_wa-error').classList.remove('show');
                    }

                    const jurusan_id = document.getElementById('jurusan_id').value;
                    if (!jurusan_id) {
                        e.preventDefault();
                        document.getElementById('jurusan_id-error').textContent = 'Jurusan wajib dipilih!';
                        document.getElementById('jurusan_id-error').classList.add('show');
                        document.getElementById('jurusan_id').focus();
                        nasabahForm.classList.remove('submitting');
                        return;
                    } else {
                        document.getElementById('jurusan_id-error').classList.remove('show');
                    }

                    const kelas_id = document.getElementById('kelas_id').value;
                    if (!kelas_id) {
                        e.preventDefault();
                        document.getElementById('kelas_id-error').textContent = 'Kelas wajib dipilih!';
                        document.getElementById('kelas_id-error').classList.add('show');
                        document.getElementById('kelas_id').focus();
                        nasabahForm.classList.remove('submitting');
                        return;
                    } else {
                        document.getElementById('kelas_id-error').classList.remove('show');
                    }

                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';
                });
            }

            // Real-time email and WhatsApp validation
            function checkFieldUniqueness(field, value, errorElementId) {
                if (!value) return;
                $.ajax({
                    url: 'check_unique.php',
                    type: 'POST',
                    data: { field: field, value: value },
                    success: function(response) {
                        const errorElement = document.getElementById(errorElementId);
                        if (response.error) {
                            errorElement.textContent = response.error;
                            errorElement.classList.add('show');
                        } else {
                            errorElement.textContent = '';
                            errorElement.classList.remove('show');
                        }
                    },
                    error: function() {
                        document.getElementById(errorElementId).textContent = 'Terjadi kesalahan saat memeriksa.';
                        document.getElementById(errorElementId).classList.add('show');
                    }
                });
            }

            const emailInput = document.getElementById('email');
            const noWaInput = document.getElementById('no_wa');

            if (emailInput) {
                emailInput.addEventListener('blur', function() {
                    checkFieldUniqueness('email', this.value.trim(), 'email-error');
                });
            }

            if (noWaInput) {
                noWaInput.addEventListener('blur', function() {
                    if (this.value.trim()) {
                        checkFieldUniqueness('no_wa', this.value.trim(), 'no_wa-error');
                    } else {
                        document.getElementById('no_wa-error').classList.remove('show');
                    }
                });
            }

            // Confirmation form handling
            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function(e) {
                    if (confirmForm.classList.contains('submitting')) return;
                    confirmForm.classList.add('submitting');

                    if (e.submitter.name === 'confirm') {
                        confirmBtn.classList.add('loading');
                        confirmBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Menyimpan...</span>';
                    }
                });
            }

            // Fetch kelas by jurusan
            function getKelasByJurusan(jurusan_id) {
                if (jurusan_id) {
                    const kelasLoading = document.getElementById('kelas-loading');
                    const kelasSelect = document.getElementById('kelas_id');
                    kelasLoading.style.display = 'flex';
                    kelasSelect.style.display = 'none';
                    
                    $.ajax({
                        url: 'get_kelas_by_jurusan.php',
                        type: 'GET',
                        data: { jurusan_id: jurusan_id },
                        success: function(response) {
                            kelasSelect.innerHTML = response;
                            kelasLoading.style.display = 'none';
                            kelasSelect.style.display = 'block';
                        },
                        error: function() {
                            showAlert('Terjadi kesalahan saat mengambil data kelas!', 'error');
                            kelasLoading.style.display = 'none';
                            kelasSelect.style.display = 'block';
                        }
                    });
                } else {
                    document.getElementById('kelas_id').innerHTML = '<option value="">Pilih Kelas</option>';
                }
            }

            <?php if (isset($_POST['jurusan_id']) && !$showConfirmation && empty($errors['general'])): ?>
                getKelasByJurusan(<?php echo $_POST['jurusan_id']; ?>);
            <?php endif; ?>

            const jurusanSelect = document.getElementById('jurusan_id');
            if (jurusanSelect) {
                jurusanSelect.addEventListener('change', function() {
                    getKelasByJurusan(this.value);
                });
            }

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Keyboard accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && document.activeElement.tagName !== 'BUTTON') {
                    nasabahForm.dispatchEvent(new Event('submit'));
                }
            });

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });
        });
    </script>
</body>
</html>