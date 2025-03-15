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
    $query = "SELECT u.*, j.nama_jurusan, k.nama_kelas 
              FROM users u
              LEFT JOIN rekening r ON u.id = r.user_id
              LEFT JOIN jurusan j ON u.jurusan_id = j.id
              LEFT JOIN kelas k ON u.kelas_id = k.id
              WHERE u.role = 'siswa'";

    if (!empty($no_rekening)) {
        $query .= " AND r.no_rekening = '$no_rekening'";
    } else {
        if (!empty($nama)) {
            $query .= " AND u.nama LIKE '%$nama%'";
        }
        if (!empty($jurusan_id)) {
            $query .= " AND u.jurusan_id = $jurusan_id";
        }
        if (!empty($kelas_id)) {
            $query .= " AND u.kelas_id = $kelas_id";
        }
    }

    $result = $conn->query($query);
    $siswa = $result->fetch_assoc();
}

// Jika form pemulihan dikirim
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pulihkan_akun'])) {
    $siswa_id = $_POST['siswa_id'];
    $jenis_pemulihan = $_POST['jenis_pemulihan'];
    $nilai_baru = $_POST['nilai_baru'];

    // Lakukan perubahan berdasarkan jenis pemulihan
    switch ($jenis_pemulihan) {
        case 'password':
            $hashed_password = password_hash($nilai_baru, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password = ? WHERE id = ?";
            break;
        case 'pin':
            $query = "UPDATE users SET pin = ? WHERE id = ?";
            break;
        case 'username':
            $query = "UPDATE users SET username = ? WHERE id = ?";
            break;
        case 'email':
            $query = "UPDATE users SET email = ? WHERE id = ?";
            break;
        default:
            die("Jenis pemulihan tidak valid.");
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $nilai_baru, $siswa_id);
    $stmt->execute();

    // Catat aktivitas dalam log
    $petugas_id = $_SESSION['user_id'];
    $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu) VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bind_param("iiss", $petugas_id, $siswa_id, $jenis_pemulihan, $nilai_baru);
    $log_stmt->execute();

    // Kirim notifikasi ke siswa
    $to = $siswa['email'];
    $subject = "Pemulihan Akun";
    $message = "Akun Anda telah dipulihkan oleh petugas. Berikut detail perubahan:\n\nJenis Pemulihan: $jenis_pemulihan\nNilai Baru: $nilai_baru";
    mail($to, $subject, $message);

    echo "Pemulihan akun berhasil dilakukan.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pulihkan Akun</title>
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
        });
    </script>
</head>
<body>
    <h1>Pulihkan Akun Siswa</h1>
    <form method="POST">
        <h2>Cari Siswa</h2>
        <label for="no_rekening">No Rekening:</label>
        <input type="text" id="no_rekening" name="no_rekening">
        <br>
        <label for="nama">Nama:</label>
        <input type="text" id="nama" name="nama">
        <br>
        <label for="jurusan_id">Jurusan:</label>
        <select id="jurusan_id" name="jurusan_id">
            <option value="">Pilih Jurusan</option>
            <?php foreach ($jurusan as $j) : ?>
                <option value="<?= $j['id'] ?>"><?= $j['nama_jurusan'] ?></option>
            <?php endforeach; ?>
        </select>
        <br>
        <label for="kelas_id">Kelas:</label>
        <select id="kelas_id" name="kelas_id">
            <option value="">Pilih Kelas</option>
        </select>
        <br>
        <button type="submit" name="cari_siswa">Cari Siswa</button>
    </form>

    <?php if ($siswa) : ?>
        <h2>Hasil Pencarian</h2>
        <p>Nama: <?= $siswa['nama'] ?></p>
        <p>Jurusan: <?= $siswa['nama_jurusan'] ?></p>
        <p>Kelas: <?= $siswa['nama_kelas'] ?></p>
        <p>No Rekening: <?= $siswa['no_rekening'] ?? '-' ?></p>

        <h2>Pulihkan Akun</h2>
        <form method="POST">
            <input type="hidden" name="siswa_id" value="<?= $siswa['id'] ?>">
            <label for="jenis_pemulihan">Jenis Pemulihan:</label>
            <select id="jenis_pemulihan" name="jenis_pemulihan" required>
                <option value="password">Password</option>
                <option value="pin">PIN</option>
                <option value="username">Username</option>
                <option value="email">Email</option>
            </select>
            <br>
            <label for="nilai_baru">Nilai Baru:</label>
            <input type="text" id="nilai_baru" name="nilai_baru" required>
            <br>
            <button type="submit" name="pulihkan_akun">Pulihkan Akun</button>
        </form>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST') : ?>
        <p>Siswa tidak ditemukan.</p>
    <?php endif; ?>
</body>
</html>