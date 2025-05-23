<?php
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';

    if (!$field || !$value) {
        error_log("check_unique.php: Missing field or value - Field: '$field', Value: '$value'");
        echo json_encode(['error' => 'Field atau value tidak valid!']);
        exit;
    }

    $table = ($field === 'no_rekening') ? 'rekening' : 'users';

    $query = "SELECT id FROM $table WHERE $field = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("check_unique.php: Error preparing query - Query: '$query', Error: " . $conn->error);
        echo json_encode(['error' => 'Terjadi kesalahan server: Gagal menyiapkan query.']);
        exit;
    }

    $stmt->bind_param("s", $value);
    if (!$stmt->execute()) {
        error_log("check_unique.php: Error executing query - Query: '$query', Error: " . $stmt->error);
        echo json_encode(['error' => 'Terjadi kesalahan server: Gagal menjalankan query.']);
        exit;
    }

    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        echo json_encode(['error' => ucfirst($field) . ' sudah digunakan!']);
    } else {
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    error_log("check_unique.php: Exception - Message: " . $e->getMessage());
    echo json_encode(['error' => 'Terjadi kesalahan server: ' . $e->getMessage()]);
}
?>