<?php
require_once '../../includes/db_connection.php';

if (isset($_GET['jurusan_id'])) {
    $jurusan_id = $_GET['jurusan_id'];

    $query = "SELECT * FROM kelas WHERE jurusan_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $jurusan_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $options = '<option value="">Pilih Kelas</option>';
    while ($row = $result->fetch_assoc()) {
        $options .= '<option value="' . $row['id'] . '">' . $row['nama_kelas'] . '</option>';
    }

    echo $options;
}
?>