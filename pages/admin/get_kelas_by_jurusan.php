<?php
require_once '../../includes/db_connection.php';

$jurusan_id = isset($_GET['jurusan_id']) ? intval($_GET['jurusan_id']) : 0;

if ($jurusan_id > 0) {
    $stmt = $conn->prepare("SELECT k.id, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas 
                           FROM kelas k 
                           JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                           WHERE k.jurusan_id = ? 
                           ORDER BY tk.nama_tingkatan, k.nama_kelas");
    $stmt->bind_param("i", $jurusan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        echo "<option value='{$row['id']}'>" . htmlspecialchars($row['nama_kelas']) . "</option>";
    }
    $stmt->close();
}
?>