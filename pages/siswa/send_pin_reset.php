<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Anda harus login terlebih dahulu']);
    exit;
}

// Get base URL and path
$baseUrl = "http://" . $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['PHP_SELF'], 3); // Sesuaikan depth folder

// Get user data
$query = "SELECT u.email, u.nama FROM users u WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan']);
    exit;
}

// Generate reset token (valid for 1 hour)
$token = bin2hex(random_bytes(32));
$expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Save token to database
$update_query = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("ssi", $token, $expiry, $_SESSION['user_id']);

if (!$update_stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan token reset']);
    exit;
}

// Buat reset link yang konsisten
$reset_link = $baseUrl . $path . "/pages/siswa/reset_pin.php?token=" . $token;

// Untuk debugging, bisa uncomment ini:
// echo json_encode(['debug_link' => $reset_link]); exit;

// Send email with reset link
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
    $mail->addAddress($user['email'], $user['nama']);

    $mail->isHTML(true);
    $mail->Subject = 'Reset PIN - SCHOBANK SYSTEM';
    $mail->Body = "
    <html>
    <body>
        <h2>Reset PIN SCHOBANK</h2>
        <p>Halo, <strong>{$user['nama']}</strong>!</p>
        <p>Kami menerima permintaan reset PIN untuk akun Anda. Silakan klik link di bawah ini untuk mengatur ulang PIN Anda:</p>
        <p><a href='$reset_link' style='background-color: #0c4da2; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset PIN</a></p>
        <p>Link ini akan kadaluarsa dalam 1 jam. Jika Anda tidak meminta reset PIN, abaikan email ini.</p>
        <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
    </body>
    </html>
    ";
    $mail->AltBody = "Reset PIN SCHOBANK\n\nHalo {$user['nama']},\n\nSilakan kunjungi link berikut untuk reset PIN: $reset_link\n\nLink ini kadaluarsa dalam 1 jam.";

    $mail->send();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    error_log("Mail error: " . $mail->ErrorInfo);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengirim email reset']);
}
?>