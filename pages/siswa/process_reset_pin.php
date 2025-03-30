<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reset_pin.php');
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Location: reset_pin.php?token=' . $_POST['token'] . '&error=' . urlencode('Token keamanan tidak valid'));
    exit;
}

// Validate token and get user ID
$token = $_POST['token'];
$new_pin = $_POST['new_pin'];
$confirm_pin = $_POST['confirm_pin'];
$user_id = $_POST['user_id'];

// Double check user ID from session
if (!isset($_SESSION['reset_pin_user_id']) || $_SESSION['reset_pin_user_id'] != $user_id) {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('Sesi tidak valid, silakan coba lagi'));
    exit;
}

// Basic validation
if (empty($new_pin) || empty($confirm_pin)) {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('PIN tidak boleh kosong'));
    exit;
}

if ($new_pin !== $confirm_pin) {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('PIN baru tidak cocok'));
    exit;
}

if (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('PIN harus terdiri dari 6 digit angka'));
    exit;
}

// Verify token again for extra security
$query = "SELECT id, reset_token_expiry FROM users WHERE reset_token = ? AND id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("si", $token, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('Token reset tidak valid'));
    exit;
}

if (strtotime($user['reset_token_expiry']) < time()) {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('Token reset sudah kadaluarsa'));
    exit;
}

// Update PIN, set has_pin to 1, and clear reset token
$update_query = "UPDATE users SET pin = ?, has_pin = 1, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("si", $new_pin, $user_id);

if ($update_stmt->execute()) {
    // Log the PIN recovery activity
    $petugas_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : $user_id; // Use user's own ID if no petugas logged in
    
    $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu) 
                  VALUES (?, ?, 'pin', 'HIDDEN', NOW())";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bind_param("ii", $petugas_id, $user_id);
    $log_stmt->execute();
    
    // Create notification for user
    $message = "PIN Anda berhasil diperbarui pada " . date('d/m/Y H:i');
    $notify_query = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
    $notify_stmt = $conn->prepare($notify_query);
    $notify_stmt->bind_param("is", $user_id, $message);
    $notify_stmt->execute();
    
    // Clear session variables related to PIN reset
    unset($_SESSION['reset_pin_user_id']);
    unset($_SESSION['csrf_token']);
    
    // Direct to profile page
    header('Location: /bankmini/pages/siswa/profil.php');
    exit;
} else {
    header('Location: reset_pin.php?token=' . $token . '&error=' . urlencode('Gagal mengupdate PIN: ' . $conn->error));
    exit;
}
?>