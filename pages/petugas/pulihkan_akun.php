<?php
session_start();
require_once '../../includes/db_connection.php';

// Cek apakah pengguna adalah petugas
if ($_SESSION['role'] !== 'petugas') {
    header('Location: /path/to/login.php');
    exit;
}

$jurusan = [];
$kelas = [];
$siswa = null;
$success_message = "";
$error_message = "";
$petugas_logs = [];

// Ambil data petugas yang sedang login
$petugas_id = $_SESSION['user_id'];
$petugas_query = "SELECT nama FROM users WHERE id = ?";
$petugas_stmt = $conn->prepare($petugas_query);
$petugas_stmt->bind_param("i", $petugas_id);
$petugas_stmt->execute();
$petugas_result = $petugas_stmt->get_result();
$petugas_data = $petugas_result->fetch_assoc();

// Ambil data jurusan untuk dropdown
$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);
while ($row = $result_jurusan->fetch_assoc()) {
    $jurusan[] = $row;
}

// Jika form pencarian dikirim
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_siswa'])) {
    $no_rekening = $_POST['no_rekening'] ?? '';
    $nama = $_POST['nama'] ?? '';
    $jurusan_id = $_POST['jurusan_id'] ?? '';
    $kelas_id = $_POST['kelas_id'] ?? '';

    // Query pencarian siswa
    $query = "SELECT u.*, j.nama_jurusan, k.nama_kelas, r.no_rekening 
              FROM users u
              LEFT JOIN rekening r ON u.id = r.user_id
              LEFT JOIN jurusan j ON u.jurusan_id = j.id
              LEFT JOIN kelas k ON u.kelas_id = k.id
              WHERE u.role = 'siswa'";

    if (!empty($no_rekening)) {
        $query .= " AND r.no_rekening = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $no_rekening);
    } else {
        if (!empty($nama)) {
            $query .= " AND u.nama LIKE ?";
            $nama = "%$nama%";
        }
        if (!empty($jurusan_id)) {
            $query .= " AND u.jurusan_id = ?";
        }
        if (!empty($kelas_id)) {
            $query .= " AND u.kelas_id = ?";
        }
        $stmt = $conn->prepare($query);
        if (!empty($nama) && !empty($jurusan_id) && !empty($kelas_id)) {
            $stmt->bind_param("sii", $nama, $jurusan_id, $kelas_id);
        } elseif (!empty($nama) && !empty($jurusan_id)) {
            $stmt->bind_param("si", $nama, $jurusan_id);
        } elseif (!empty($nama)) {
            $stmt->bind_param("s", $nama);
        } elseif (!empty($jurusan_id)) {
            $stmt->bind_param("i", $jurusan_id);
        } elseif (!empty($kelas_id)) {
            $stmt->bind_param("i", $kelas_id);
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $siswa = $result->fetch_assoc();

    if (!$siswa) {
        $error_message = "Siswa tidak ditemukan.";
    }
}

// Fungsi untuk generate OTP
function generateOTP($length = 6) {
    $digits = '0123456789';
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= $digits[rand(0, 9)];
    }
    return $otp;
}

// Jika form pemulihan dikirim dengan verifikasi dua faktor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pulihkan_akun'])) {
    $siswa_id = $_POST['siswa_id'];
    $jenis_pemulihan = $_POST['jenis_pemulihan'];
    $nilai_baru = $_POST['nilai_baru'];
    $alasan_pemulihan = $_POST['alasan_pemulihan'] ?? '';

    if (empty($siswa_id) || empty($jenis_pemulihan) || empty($nilai_baru) || empty($alasan_pemulihan)) {
        $error_message = "Semua field harus diisi, termasuk alasan pemulihan.";
    } else {
        // Buat OTP untuk verifikasi
        $recovery_otp = generateOTP();
        $expiry_time = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Simpan OTP dan expiry ke dalam session untuk digunakan nanti
        $_SESSION['recovery_otp'] = $recovery_otp;
        $_SESSION['recovery_otp_expiry'] = $expiry_time;
        $_SESSION['recovery_siswa_id'] = $siswa_id;
        $_SESSION['recovery_jenis'] = $jenis_pemulihan;
        $_SESSION['recovery_nilai'] = $nilai_baru;
        $_SESSION['recovery_alasan'] = $alasan_pemulihan;
        
        // Kirim OTP ke nomor admin yang terdaftar (simulasi)
        // Dalam implementasi nyata, gunakan layanan SMS atau email ke admin
        // Untuk sementara, kita hanya tampilkan di layar
        $success_message = "Kode OTP verifikasi $recovery_otp telah dikirim ke Admin. Masukkan kode untuk melanjutkan pemulihan.";
        
        // Catat attempt di log_recovery_attempts
        $attempt_query = "INSERT INTO log_recovery_attempts 
                         (petugas_id, siswa_id, jenis_pemulihan, alasan, status, created_at) 
                         VALUES (?, ?, ?, ?, 'pending', NOW())";
        $attempt_stmt = $conn->prepare($attempt_query);
        $attempt_stmt->bind_param("iiss", $petugas_id, $siswa_id, $jenis_pemulihan, $alasan_pemulihan);
        $attempt_stmt->execute();
        
        // Get siswa details for admin notification
        $get_siswa = "SELECT nama FROM users WHERE id = ?";
        $stmt_siswa = $conn->prepare($get_siswa);
        $stmt_siswa->bind_param("i", $siswa_id);
        $stmt_siswa->execute();
        $result_siswa = $stmt_siswa->get_result();
        $data_siswa = $result_siswa->fetch_assoc();
        
        // Kirim notifikasi ke semua admin
        $admin_notification = "Petugas {$petugas_data['nama']} meminta pemulihan {$jenis_pemulihan} untuk siswa {$data_siswa['nama']}. Alasan: {$alasan_pemulihan}. Gunakan kode: {$recovery_otp}";
        $notify_admin_query = "INSERT INTO notifications (user_id, message, is_read, created_at) 
                              SELECT id, ?, 0, NOW() FROM users WHERE role = 'admin'";
        $notify_stmt = $conn->prepare($notify_admin_query);
        $notify_stmt->bind_param("s", $admin_notification);
        $notify_stmt->execute();
    }
}

// Verifikasi OTP dan lakukan pemulihan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $input_otp = $_POST['input_otp'];
    
    if (empty($input_otp)) {
        $error_message = "OTP harus diisi.";
    } elseif (!isset($_SESSION['recovery_otp']) || !isset($_SESSION['recovery_otp_expiry'])) {
        $error_message = "Sesi verifikasi telah berakhir. Silakan coba lagi.";
    } elseif ($_SESSION['recovery_otp'] != $input_otp) {
        $error_message = "Kode OTP tidak valid.";
        
        // Catat kegagalan verifikasi
        $fail_query = "UPDATE log_recovery_attempts SET 
                       failed_attempts = failed_attempts + 1, 
                       last_attempt = NOW() 
                       WHERE petugas_id = ? AND siswa_id = ? AND status = 'pending' 
                       ORDER BY created_at DESC LIMIT 1";
        $fail_stmt = $conn->prepare($fail_query);
        $fail_stmt->bind_param("ii", $petugas_id, $_SESSION['recovery_siswa_id']);
        $fail_stmt->execute();
    } elseif (strtotime($_SESSION['recovery_otp_expiry']) < time()) {
        $error_message = "Kode OTP telah kedaluwarsa. Silakan coba lagi.";
    } else {
        // OTP valid, lakukan pemulihan
        $siswa_id = $_SESSION['recovery_siswa_id'];
        $jenis_pemulihan = $_SESSION['recovery_jenis'];
        $nilai_baru = $_SESSION['recovery_nilai'];
        $alasan_pemulihan = $_SESSION['recovery_alasan'];
        
        // Get siswa data for notification
        $get_siswa = "SELECT nama, email FROM users WHERE id = ?";
        $stmt_siswa = $conn->prepare($get_siswa);
        $stmt_siswa->bind_param("i", $siswa_id);
        $stmt_siswa->execute();
        $result_siswa = $stmt_siswa->get_result();
        $siswa_data = $result_siswa->fetch_assoc();
        
        // Lakukan perubahan berdasarkan jenis pemulihan
        switch ($jenis_pemulihan) {
            case 'password':
                $hashed_password = hash('sha256', $nilai_baru); // Enkripsi menggunakan SHA-256
                $query = "UPDATE users SET password = ? WHERE id = ?";
                $secure_nilai = $hashed_password; // Gunakan nilai yang sudah dienkripsi
                $nilai_log = '[PASSWORD TERENKRIPSI]'; // Untuk log, tidak menyimpan plaintext
                break;
            case 'pin':
                $hashed_pin = hash('sha256', $nilai_baru); // Enkripsi menggunakan SHA-256
                $query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
                $secure_nilai = $hashed_pin; // Gunakan nilai yang sudah dienkripsi
                $nilai_log = '[PIN TERENKRIPSI]'; // Untuk log, tidak menyimpan plaintext
                break;
            case 'username':
                // Cek apakah username sudah digunakan
                $check_username = "SELECT id FROM users WHERE username = ? AND id != ?";
                $check_stmt = $conn->prepare($check_username);
                $check_stmt->bind_param("si", $nilai_baru, $siswa_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "Username sudah digunakan. Silakan pilih username lain.";
                    break;
                }
                
                $query = "UPDATE users SET username = ? WHERE id = ?";
                $secure_nilai = $nilai_baru;
                $nilai_log = $nilai_baru;
                break;
            case 'email':
                // Validasi format email
                if (!filter_var($nilai_baru, FILTER_VALIDATE_EMAIL)) {
                    $error_message = "Format email tidak valid.";
                    break;
                }
                
                $query = "UPDATE users SET email = ? WHERE id = ?";
                $secure_nilai = $nilai_baru;
                $nilai_log = $nilai_baru;
                break;
            default:
                $error_message = "Jenis pemulihan tidak valid.";
                break;
        }

        if (empty($error_message)) {
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $secure_nilai, $siswa_id);
            if ($stmt->execute()) {
                // Catat aktivitas dalam log
                $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan_pemulihan, verified_by_admin) 
                             VALUES (?, ?, ?, ?, NOW(), ?, 1)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("iisss", $petugas_id, $siswa_id, $jenis_pemulihan, $nilai_log, $alasan_pemulihan);
                $log_stmt->execute();
                
                // Update status di log_recovery_attempts
                $update_attempt = "UPDATE log_recovery_attempts SET 
                                  status = 'completed', completed_at = NOW() 
                                  WHERE petugas_id = ? AND siswa_id = ? AND status = 'pending' 
                                  ORDER BY created_at DESC LIMIT 1";
                $update_stmt = $conn->prepare($update_attempt);
                $update_stmt->bind_param("ii", $petugas_id, $siswa_id);
                $update_stmt->execute();

                // Kirim notifikasi ke siswa
                if (!empty($siswa_data['email'])) {
                    $to = $siswa_data['email'];
                    $subject = "Pemulihan Akun";
                    
                    // Buat pesan sesuai dengan jenis pemulihan
                    switch ($jenis_pemulihan) {
                        case 'password':
                            $message = "Akun Anda telah dipulihkan oleh petugas.\n\n" .
                                      "Jenis Pemulihan: Password\n" .
                                      "Password baru: $nilai_baru\n\n" .
                                      "Harap segera ubah password Anda setelah login untuk keamanan.";
                            break;
                        case 'pin':
                            $message = "Akun Anda telah dipulihkan oleh petugas.\n\n" .
                                      "Jenis Pemulihan: PIN\n" .
                                      "PIN baru: $nilai_baru\n\n" .
                                      "Harap segera ubah PIN Anda setelah login untuk keamanan.";
                            break;
                        case 'username':
                            $message = "Akun Anda telah dipulihkan oleh petugas.\n\n" .
                                      "Jenis Pemulihan: Username\n" .
                                      "Username baru: $nilai_baru";
                            break;
                        case 'email':
                            $message = "Akun Anda telah dipulihkan oleh petugas.\n\n" .
                                      "Jenis Pemulihan: Email\n" .
                                      "Email baru: $nilai_baru";
                            break;
                    }
                    
                    // Tambahkan log siapa yang melakukan pemulihan
                    $message .= "\n\nPemulihan dilakukan oleh: {$petugas_data['nama']}\n" .
                               "Alasan: $alasan_pemulihan\n" .
                               "Waktu: " . date('Y-m-d H:i:s') . "\n\n" .
                               "Jika Anda tidak mengetahui tentang hal ini, silakan hubungi admin segera.";
                    
                    if (mail($to, $subject, $message)) {
                        $success_message = "Pemulihan akun berhasil dilakukan dan notifikasi email telah dikirim.";
                    } else {
                        $success_message = "Pemulihan akun berhasil dilakukan, tetapi gagal mengirim notifikasi email.";
                    }
                } else {
                    $success_message = "Pemulihan akun berhasil dilakukan.";
                }
                
                // Kirim notifikasi ke siswa melalui sistem
                $siswa_notification = "Akun Anda telah dipulihkan untuk $jenis_pemulihan oleh petugas {$petugas_data['nama']}. Alasan: $alasan_pemulihan";
                $notify_siswa_query = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
                $notify_siswa_stmt = $conn->prepare($notify_siswa_query);
                $notify_siswa_stmt->bind_param("is", $siswa_id, $siswa_notification);
                $notify_siswa_stmt->execute();
                
                // Bersihkan session OTP
                unset($_SESSION['recovery_otp']);
                unset($_SESSION['recovery_otp_expiry']);
                unset($_SESSION['recovery_siswa_id']);
                unset($_SESSION['recovery_jenis']);
                unset($_SESSION['recovery_nilai']);
                unset($_SESSION['recovery_alasan']);
            } else {
                $error_message = "Terjadi kesalahan saat memproses pemulihan akun.";
            }
        }
    }
}

// Ambil log aktivitas pemulihan oleh petugas ini untuk audit
$log_query = "SELECT la.id, u.nama AS siswa_nama, la.jenis_pemulihan, la.waktu, la.alasan_pemulihan, 
             (SELECT COUNT(*) FROM log_recovery_attempts 
              WHERE petugas_id = ? AND siswa_id = u.id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS attempt_count 
             FROM log_aktivitas la 
             JOIN users u ON la.siswa_id = u.id 
             WHERE la.petugas_id = ?
             ORDER BY la.waktu DESC LIMIT 10";
$log_stmt = $conn->prepare($log_query);
$log_stmt->bind_param("ii", $petugas_id, $petugas_id);
$log_stmt->execute();
$log_result = $log_stmt->get_result();
while ($log = $log_result->fetch_assoc()) {
    $petugas_logs[] = $log;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pulihkan Akun</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Ambil kelas berdasarkan jurusan yang dipilih
            $('#jurusan_id').change(function() {
                var jurusan_id = $(this).val();
                if (jurusan_id) {
                    $.ajax({
                        url: 'get_kelas_by_jurusan.php',
                        type: 'GET',
                        data: { jurusan_id: jurusan_id },
                        success: function(response) {
                            $('#kelas_id').html(response);
                        }
                    });
                } else {
                    $('#kelas_id').html('<option value="">Pilih Kelas</option>');
                }
            });
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                $(".alert").fadeOut("slow");
            }, 5000);
            
            // Tambahkan password meter
            $('#nilai_baru').on('input', function() {
                var password = $(this).val();
                var strength = 0;
                
                if ($('#jenis_pemulihan').val() === 'password' || $('#jenis_pemulihan').val() === 'pin') {
                    if (password.length >= 8) strength += 1;
                    if (password.match(/[0-9]+/)) strength += 1;
                    if (password.match(/[a-z]+/)) strength += 1;
                    if (password.match(/[A-Z]+/)) strength += 1;
                    if (password.match(/[^a-zA-Z0-9]+/)) strength += 1;
                    
                    var strengthBar = $('.password-strength');
                    switch(strength) {
                        case 0:
                        case 1:
                            strengthBar.css('width', '20%').removeClass().addClass('password-strength progress-bar bg-danger');
                            $('#strength-text').text('Sangat Lemah');
                            break;
                        case 2:
                            strengthBar.css('width', '40%').removeClass().addClass('password-strength progress-bar bg-warning');
                            $('#strength-text').text('Lemah');
                            break;
                        case 3:
                            strengthBar.css('width', '60%').removeClass().addClass('password-strength progress-bar bg-info');
                            $('#strength-text').text('Sedang');
                            break;
                        case 4:
                            strengthBar.css('width', '80%').removeClass().addClass('password-strength progress-bar bg-primary');
                            $('#strength-text').text('Kuat');
                            break;
                        case 5:
                            strengthBar.css('width', '100%').removeClass().addClass('password-strength progress-bar bg-success');
                            $('#strength-text').text('Sangat Kuat');
                            break;
                    }
                }
            });
            
            // Toggle password visibility
            $('#toggle-password').click(function() {
                var input = $('#nilai_baru');
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    $(this).text('Sembunyikan');
                } else {
                    input.attr('type', 'password');
                    $(this).text('Tampilkan');
                }
            });
            
            // Update form berdasarkan jenis pemulihan
            $('#jenis_pemulihan').change(function() {
                var jenis = $(this).val();
                var input = $('#nilai_baru');
                var passwordContainer = $('#password-strength-container');
                
                if (jenis === 'password') {
                    input.attr('type', 'password');
                    $('#toggle-password').show();
                    passwordContainer.show();
                    $('.password-requirements').show();
                } else if (jenis === 'pin') {
                    input.attr('type', 'password');
                    $('#toggle-password').show();
                    passwordContainer.show();
                    $('.password-requirements').hide();
                } else {
                    input.attr('type', 'text');
                    $('#toggle-password').hide();
                    passwordContainer.hide();
                    $('.password-requirements').hide();
                }
                
                // Reset nilai
                input.val('');
                
                // Update label dan placeholder
                $('label[for="nilai_baru"]').text(jenis.charAt(0).toUpperCase() + jenis.slice(1) + ' Baru:');
                
                if (jenis === 'email') {
                    input.attr('placeholder', 'Masukkan email baru');
                } else if (jenis === 'pin') {
                    input.attr('placeholder', 'Masukkan PIN baru (6 digit)');
                    input.attr('maxlength', '6');
                } else if (jenis === 'password') {
                    input.attr('placeholder', 'Masukkan password baru');
                    input.removeAttr('maxlength');
                } else {
                    input.attr('placeholder', 'Masukkan username baru');
                    input.removeAttr('maxlength');
                }
            });
        });
    </script>
    <style>
        .password-requirements {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        .audit-log {
            margin-top: 20px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3>Pulihkan Akun Siswa</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($success_message)) : ?>
                            <div class="alert alert-success"><?= $success_message ?></div>
                        <?php endif; ?>
                        <?php if (!empty($error_message)) : ?>
                            <div class="alert alert-danger"><?= $error_message ?></div>
                        <?php endif; ?>
                        
                        <!-- Form Pencarian Siswa -->
                        <form method="POST" class="mb-4">
                            <div class="card mb-4">
                                <div class="card-header bg-secondary text-white">
                                    <h5>Cari Siswa</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="no_rekening" class="form-label">No Rekening:</label>
                                            <input type="text" id="no_rekening" name="no_rekening" class="form-control">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="nama" class="form-label">Nama:</label>
                                            <input type="text" id="nama" name="nama" class="form-control">
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="jurusan_id" class="form-label">Jurusan:</label>
                                            <select id="jurusan_id" name="jurusan_id" class="form-select">
                                                <option value="">Pilih Jurusan</option>
                                                <?php foreach ($jurusan as $j) : ?>
                                                    <option value="<?= $j['id'] ?>"><?= $j['nama_jurusan'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="kelas_id" class="form-label">Kelas:</label>
                                            <select id="kelas_id" name="kelas_id" class="form-select">
                                                <option value="">Pilih Kelas</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" name="cari_siswa" class="btn btn-primary">Cari Siswa</button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <?php if ($siswa) : ?>
                            <!-- Hasil Pencarian -->
                            <div class="card mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5>Hasil Pencarian</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Nama:</strong> <?= $siswa['nama'] ?></p>
                                            <p><strong>Jurusan:</strong> <?= $siswa['nama_jurusan'] ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Kelas:</strong> <?= $siswa['nama_kelas'] ?></p>
                                            <p><strong>No Rekening:</strong> <?= $siswa['no_rekening'] ?? '-' ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Pemulihan Akun -->
                            <?php if (isset($_SESSION['recovery_otp'])) : ?>
                                <!-- Form Verifikasi OTP -->
                                <div class="card mb-4">
                                    <div class="card-header bg-warning text-dark">
                                        <h5>Verifikasi OTP</h5>
                                    </div>
                                    <div class="card-body">
                                        <p>Kode OTP telah dikirim ke Admin. Masukkan kode untuk melanjutkan.</p>
                                        <form method="POST">
                                            <div class="mb-3">
                                                <label for="input_otp" class="form-label">Kode OTP:</label>
                                                <input type="text" id="input_otp" name="input_otp" class="form-control" required>
                                            </div>
                                            <div class="d-grid">
                                                <button type="submit" name="verify_otp" class="btn btn-warning">Verifikasi & Pulihkan</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php else : ?>
                                <!-- Form Permintaan Pemulihan -->
                                <div class="card mb-4">
                                    <div class="card-header bg-info text-white">
                                        <h5>Pulihkan Akun</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST">
                                            <input type="hidden" name="siswa_id" value="<?= $siswa['id'] ?>">
                                            <div class="mb-3">
                                                <label for="jenis_pemulihan" class="form-label">Jenis Pemulihan:</label>
                                                <select id="jenis_pemulihan" name="jenis_pemulihan" class="form-select" required>
                                                    <option value="password">Password</option>
                                                    <option value="pin">PIN</option>
                                                    <option value="username">Username</option>
                                                    <option value="email">Email</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">                                                <label for="nilai_baru" class="form-label">Nilai Baru:</label>
                                                <input type="password" id="nilai_baru" name="nilai_baru" class="form-control" required>
                                                <div id="password-strength-container" class="mt-2">
                                                    <div class="progress">
                                                        <div class="password-strength progress-bar" role="progressbar" style="width: 0%"></div>
                                                    </div>
                                                    <small id="strength-text" class="form-text text-muted"></small>
                                                </div>
                                                <div class="password-requirements mt-2">
                                                    <small class="form-text text-muted">
                                                        Password harus mengandung:
                                                        <ul>
                                                            <li>Minimal 8 karakter</li>
                                                            <li>Setidaknya satu angka</li>
                                                            <li>Setidaknya satu huruf kecil</li>
                                                            <li>Setidaknya satu huruf besar</li>
                                                            <li>Setidaknya satu karakter khusus</li>
                                                        </ul>
                                                    </small>
                                                </div>
                                                <button type="button" id="toggle-password" class="btn btn-secondary btn-sm mt-2">Tampilkan</button>
                                            </div>
                                            <div class="mb-3">
                                                <label for="alasan_pemulihan" class="form-label">Alasan Pemulihan:</label>
                                                <textarea id="alasan_pemulihan" name="alasan_pemulihan" class="form-control" rows="3" required></textarea>
                                            </div>
                                            <div class="d-grid">
                                                <button type="submit" name="pulihkan_akun" class="btn btn-info">Ajukan Pemulihan</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST') : ?>
                            <div class="alert alert-warning">Siswa tidak ditemukan.</div>
                        <?php endif; ?>

                        <!-- Log Aktivitas Pemulihan -->
                        <div class="card audit-log">
                            <div class="card-header bg-light">
                                <h5>Log Aktivitas Pemulihan</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($petugas_logs)) : ?>
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Siswa</th>
                                                <th>Jenis Pemulihan</th>
                                                <th>Waktu</th>
                                                <th>Alasan</th>
                                                <th>Attempt Count (30 Hari)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($petugas_logs as $index => $log) : ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= $log['siswa_nama'] ?></td>
                                                    <td><?= ucfirst($log['jenis_pemulihan']) ?></td>
                                                    <td><?= date('d M Y H:i', strtotime($log['waktu'])) ?></td>
                                                    <td><?= $log['alasan_pemulihan'] ?></td>
                                                    <td><?= $log['attempt_count'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else : ?>
                                    <p>Tidak ada log aktivitas pemulihan.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>