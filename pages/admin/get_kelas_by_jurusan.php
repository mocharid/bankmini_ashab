<?php
require_once '../../includes/db_connection.php';

// Ambil jurusan_id dari parameter GET
$jurusan_id = filter_input(INPUT_GET, 'jurusan_id', FILTER_VALIDATE_INT);

if (!$jurusan_id) {
    echo "<option value=''>Pilih Kelas</option>";
    exit;
}

// Query untuk mendapatkan kelas berdasarkan jurusan
$query = $conn->prepare("SELECT id, nama_kelas FROM kelas WHERE jurusan_id = ?");
$query->bind_param("i", $jurusan_id);
$query->execute();
$result = $query->get_result();

// Buat dropdown options
echo "<option value=''>Pilih Kelas</option>";
while ($row = $result->fetch_assoc()) {
    echo "<option value='" . $row['id'] . "'>" . htmlspecialchars($row['nama_kelas']) . "</option>";
}
?>