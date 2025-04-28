<?php
session_start();
require_once '../../includes/db_connection.php';
header('Content-Type: application/json');

// Temporary debug logging (remove after testing)
function debugLog($message) {
    error_log("[DEBUG check_duplicate.php] " . $message);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debugLog("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan']);
    exit();
}

if (!isset($_POST['value']) || !isset($_POST['type']) || !isset($_POST['siswa_id'])) {
    debugLog("Missing required POST parameters");
    echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap']);
    exit();
}

$value = trim($_POST['value']);
$type = trim($_POST['type']);
$siswa_id = intval($_POST['siswa_id']);

debugLog("Checking duplicate: type=$type, value=$value, siswa_id=$siswa_id");

if (empty($value) || empty($type) || empty($siswa_id)) {
    debugLog("Empty parameters: type=$type, value=$value, siswa_id=$siswa_id");
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit();
}

if (!in_array($type, ['username', 'email'])) {
    debugLog("Invalid type: $type");
    echo json_encode(['status' => 'error', 'message' => 'Tipe tidak valid']);
    exit();
}

try {
    $query = "SELECT id FROM users WHERE $type = ? AND id != ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        debugLog("Prepare failed: " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Kesalahan server']);
        exit();
    }
    $stmt->bind_param("si", $value, $siswa_id);
    if (!$stmt->execute()) {
        debugLog("Execute failed: " . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Gagal memeriksa data']);
        exit();
    }
    $result = $stmt->get_result();
    $isDuplicate = $result->num_rows > 0;
    $stmt->close();

    debugLog("Duplicate check result: isDuplicate=" . ($isDuplicate ? 'true' : 'false'));
    echo json_encode([
        'status' => 'success',
        'isDuplicate' => $isDuplicate,
        'message' => $isDuplicate ? ($type === 'username' ? 'Username sudah digunakan.' : 'Email sudah digunakan.') : ($type === 'username' ? 'Username tersedia.' : 'Email tersedia.')
    ]);
} catch (Exception $e) {
    debugLog("Exception: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Kesalahan server']);
}
?>