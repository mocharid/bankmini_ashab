<?php
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// Inisialisasi variabel
$token = $_GET['token'] ?? '';
$error_message = '';
$success_message = '';
$user_data = null;
$recovery_type = '';

// Verifikasi token
if (empty($token)) {
    $error_message = "Token tidak valid atau telah kedaluarsa.";
} elseif (strlen($token) !== 64) {
    $error_message = "Format token tidak valid.";
} else {
    // Cek token di database
    $query = "SELECT pr.*, u.nama, u.username, u.email, u.role, pr.recovery_type 
              FROM password_reset pr
              JOIN users u ON pr.user_id = u.id
              WHERE pr.token = ? AND pr.expiry > UTC_TIMESTAMP()";
    $stmt = $conn->prepare($query);
    
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
    } else {
        $stmt->bind_param("s", $token);
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
        } else {
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                error_log("Invalid or expired token: $token");
                $error_message = "Token tidak valid atau telah kedaluarsa.";
            } else {
                $user_data = $result->fetch_assoc();
                $recovery_type = $user_data['recovery_type'] ?? 'password';
            }
        }
    }
}

// Proses form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($user_data)) {
    $user_id = $user_data['user_id'];
    $recovery_type = $user_data['recovery_type'];
    $new_value = $_POST['new_value'] ?? '';
    $confirm_value = $_POST['confirm_value'] ?? '';
    
    // Validasi input
    if (empty($new_value) || empty($confirm_value)) {
        $error_message = "Semua field harus diisi.";
    } elseif ($new_value !== $confirm_value) {
        $error_message = "Konfirmasi tidak cocok.";
    } else {
        // Update berdasarkan jenis recovery
        switch ($recovery_type) {
            case 'password':
                // Menggunakan SHA2(256) seperti di login.php
                $hashed_password = hash('sha256', $new_value);
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("si", $hashed_password, $user_id);
                break;
                
            case 'pin':
                if (!preg_match('/^[0-9]{6}$/', $new_value)) {
                    $error_message = "PIN harus berupa 6 digit angka.";
                    break;
                }
                // PIN disimpan sebagai plaintext (sesuai dengan reset_pin.php)
                $update_query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("si", $new_value, $user_id);
                break;
                
            case 'username':
                $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("si", $new_value, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "Username sudah digunakan. Silakan pilih username lain.";
                    break;
                }
                
                $update_query = "UPDATE users SET username = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("si", $new_value, $user_id);
                break;
                
            case 'email':
                if (!filter_var($new_value, FILTER_VALIDATE_EMAIL)) {
                    $error_message = "Format email tidak valid.";
                    break;
                }
                
                $check_query = "SELECT id FROM users WHERE email = ? AND id != ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("si", $new_value, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "Email sudah digunakan. Silakan gunakan email lain.";
                    break;
                }
                
                $update_query = "UPDATE users SET email = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("si", $new_value, $user_id);
                break;
                
            default:
                $error_message = "Jenis pemulihan tidak valid.";
                break;
        }
        
        // Eksekusi update jika tidak ada error
        if (empty($error_message)) {
            if ($stmt->execute()) {
                // Hapus token setelah reset berhasil
                $delete_query = "DELETE FROM password_reset WHERE token = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("s", $token);
                $delete_stmt->execute();
                
                // Log perubahan di tabel log_aktivitas
                $alasan = "Reset melalui link pemulihan";
                $petugas_id = $user_id; // Menggunakan ID user yang melakukan reset
                
                // Cek apakah petugas_id valid
                $check_petugas = $conn->prepare("SELECT id FROM users WHERE id = ?");
                $check_petugas->bind_param("i", $petugas_id);
                $check_petugas->execute();
                
                if ($check_petugas->get_result()->num_rows > 0) {
                    $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan) 
                                 VALUES (?, ?, ?, ?, NOW(), ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $masked_value = ($recovery_type === 'password' || $recovery_type === 'pin') ? '********' : $new_value;
                    $log_stmt->bind_param("iisss", $petugas_id, $user_id, $recovery_type, $masked_value, $alasan);
                    
                    if (!$log_stmt->execute()) {
                        error_log("Gagal mencatat log aktivitas: " . $log_stmt->error);
                    }
                } else {
                    error_log("Petugas ID tidak valid: $petugas_id");
                }
                
                $success_message = ucfirst($recovery_type) . " berhasil diubah. Silakan login dengan " . $recovery_type . " baru Anda.";
                $user_data = null; // Bersihkan data user untuk menyembunyikan form
            } else {
                $error_message = "Gagal mengubah " . $recovery_type . ". Silakan coba lagi.";
            }
        }
    }
}

// Fungsi helper
function getFieldLabel($type) {
    switch ($type) {
        case 'password': return 'Password';
        case 'pin': return 'PIN';
        case 'username': return 'Username';
        case 'email': return 'Email';
        default: return 'Nilai';
    }
}

function getFieldType($type) {
    switch ($type) {
        case 'password': return 'password';
        case 'pin': return 'password';
        case 'username': return 'text';
        case 'email': return 'email';
        default: return 'text';
    }
}

function getPlaceholder($type) {
    switch ($type) {
        case 'password': return 'Masukkan password baru';
        case 'pin': return 'Masukkan 6 digit PIN baru';
        case 'username': return 'Masukkan username baru';
        case 'email': return 'Masukkan email baru';
        default: return 'Masukkan nilai baru';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset <?= ucfirst($recovery_type ?? 'Akun') ?> - SCHOBANK SYSTEM</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 50px;
        }
        .reset-container {
            max-width: 500px;
            margin: 0 auto;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            color: #0a2e5c;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .form-group label {
            font-weight: 500;
        }
        .btn-primary {
            background-color: #0a2e5c;
            border-color: #0a2e5c;
        }
        .btn-primary:hover {
            background-color: #08203e;
            border-color: #08203e;
        }
        .alert {
            margin-bottom: 20px;
        }
        .pin-strength {
            height: 5px;
            background: #e1e5ee;
            margin-top: 8px;
            border-radius: 5px;
            overflow: hidden;
        }
        .pin-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
        }
        .pin-message {
            font-size: 0.8rem;
            margin-top: 5px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="reset-container">
            <div class="header">
                <div class="logo">SCHOBANK SYSTEM</div>
                <h4>Reset <?= ucfirst($recovery_type ?? 'Akun') ?></h4>
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger"><?= $error_message ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><?= $success_message ?></div>
                <div class="text-center mt-4">
                    <a href="../login.php" class="btn btn-primary">Login</a>
                </div>
            <?php endif; ?>
            
            <?php if ($user_data): ?>
                <div class="mb-4">
                    <p>Halo <strong><?= htmlspecialchars($user_data['nama']) ?></strong>,</p>
                    <p>Silakan masukkan <?= strtolower(getFieldLabel($recovery_type)) ?> baru Anda di bawah ini:</p>
                </div>
                
                <form method="POST" action="<?= $_SERVER['PHP_SELF'] . '?token=' . $token ?>">
                    <div class="form-group">
                        <label for="new_value"><?= getFieldLabel($recovery_type) ?> Baru:</label>
                        <input 
                            type="<?= getFieldType($recovery_type) ?>" 
                            class="form-control" 
                            id="new_value" 
                            name="new_value" 
                            placeholder="<?= getPlaceholder($recovery_type) ?>" 
                            required
                            <?php if ($recovery_type === 'pin'): ?>
                                maxlength="6"
                                pattern="[0-9]{6}"
                                inputmode="numeric"
                            <?php endif; ?>
                        >
                        <?php if ($recovery_type === 'pin'): ?>
                            <div class="pin-strength">
                                <div class="pin-strength-bar" id="pinStrengthBar"></div>
                            </div>
                            <div class="pin-message" id="pinMessage"></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="confirm_value">Konfirmasi <?= getFieldLabel($recovery_type) ?>:</label>
                        <input 
                            type="<?= getFieldType($recovery_type) ?>" 
                            class="form-control" 
                            id="confirm_value" 
                            name="confirm_value" 
                            placeholder="Konfirmasi <?= strtolower(getFieldLabel($recovery_type)) ?> baru" 
                            required
                            <?php if ($recovery_type === 'pin'): ?>
                                maxlength="6"
                                pattern="[0-9]{6}"
                                inputmode="numeric"
                            <?php endif; ?>
                        >
                    </div>
                    <?php if ($recovery_type === 'password' || $recovery_type === 'pin'): ?>
                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="show_password">
                            <label class="custom-control-label" for="show_password">Tampilkan <?= strtolower(getFieldLabel($recovery_type)) ?></label>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-block">Reset <?= getFieldLabel($recovery_type) ?></button>
                </form>
            <?php elseif (empty($success_message)): ?>
                <div class="alert alert-warning">
                    <p>Link reset tidak valid atau telah kedaluarsa.</p>
                    <p>Silakan hubungi administrator untuk mendapatkan link pemulihan baru.</p>
                </div>
                <div class="text-center mt-4">
                    <a href="../../index.php" class="btn btn-primary">Kembali ke Beranda</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle password/PIN visibility
            $('#show_password').change(function() {
                var type = $(this).prop('checked') ? 'text' : '<?= getFieldType($recovery_type) ?>';
                $('#new_value, #confirm_value').attr('type', type);
            });
            
            <?php if ($recovery_type === 'pin'): ?>
                // PIN strength checker
                $('#new_value').on('input', function() {
                    const pin = $(this).val();
                    let strength = 0;
                    let message = '';
                    
                    // Allow only numbers
                    $(this).val(pin.replace(/[^0-9]/g, ''));
                    
                    if (pin.length === 0) {
                        strength = 0;
                        message = '';
                    } else if (pin.length < 6) {
                        strength = 25;
                        message = 'Terlalu pendek';
                        $('#pinStrengthBar').css('background-color', '#ff4d4d');
                    } else {
                        // Check for repeated digits
                        const hasRepeatedDigits = /(\d)\1{2,}/.test(pin);
                        
                        // Check for sequential digits
                        const isSequential = 
                            /012|123|234|345|456|567|678|789/.test(pin) || 
                            /987|876|765|654|543|432|321|210/.test(pin);
                        
                        if (hasRepeatedDigits && isSequential) {
                            strength = 25;
                            message = 'PIN terlalu lemah';
                            $('#pinStrengthBar').css('background-color', '#ff4d4d');
                        } else if (hasRepeatedDigits || isSequential) {
                            strength = 50;
                            message = 'PIN cukup lemah';
                            $('#pinStrengthBar').css('background-color', '#ffaa00');
                        } else {
                            strength = 100;
                            message = 'PIN kuat';
                            $('#pinStrengthBar').css('background-color', '#00cc44');
                        }
                    }
                    
                    $('#pinStrengthBar').css('width', strength + '%');
                    $('#pinMessage').text(message);
                    
                    // Also check confirm PIN if it has a value
                    if ($('#confirm_value').val().length > 0) {
                        checkPinMatch();
                    }
                });
                
                // Check if PINs match
                $('#confirm_value').on('input', function() {
                    // Allow only numbers
                    $(this).val($(this).val().replace(/[^0-9]/g, ''));
                    checkPinMatch();
                });
                
                function checkPinMatch() {
                    if ($('#confirm_value').val() !== $('#new_value').val()) {
                        $('#confirm_value').css({
                            'border-color': '#ff4d4d',
                            'box-shadow': '0 0 0 3px rgba(255, 77, 77, 0.1)'
                        });
                    } else {
                        $('#confirm_value').css({
                            'border-color': '#00cc44',
                            'box-shadow': '0 0 0 3px rgba(0, 204, 68, 0.1)'
                        });
                    }
                }
                
                // Allow only numeric input for PIN
                $('input[name="new_value"], input[name="confirm_value"]').on('keypress', function(e) {
                    if (!/[0-9]/.test(e.key)) {
                        e.preventDefault();
                    }
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>