<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama = $_POST['nama'];
    $username = $_POST['username'];
    $password = sha1($_POST['password']); // Hash password
    $jurusan_id = $_POST['jurusan'];
    $kelas_id = $_POST['kelas'];

    // Tambahkan user baru
    $query = "INSERT INTO users (username, password, role, nama, jurusan_id, kelas_id) 
              VALUES ('$username', '$password', 'siswa', '$nama', $jurusan_id, $kelas_id)";
    if ($conn->query($query)) {
        $user_id = $conn->insert_id;

        // Buat rekening baru
        $no_rekening = generateNoRekening();
        $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) 
                           VALUES ('$no_rekening', $user_id, 0)";
        if ($conn->query($query_rekening)) {
            header('Location: data_siswa.php?success=1');
            exit();
        } else {
            echo "Gagal membuat rekening: " . $conn->error;
        }
    } else {
        echo "Gagal menambahkan nasabah: " . $conn->error;
    }
}
?>