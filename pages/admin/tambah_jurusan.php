<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Handle delete request
if (isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    
    // Check if jurusan has students
    $check_query = "SELECT COUNT(*) as count FROM users WHERE jurusan_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        $error = "Tidak dapat menghapus jurusan karena masih memiliki siswa!";
    } else {
        $delete_query = "DELETE FROM jurusan WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            header('Location: tambah_jurusan.php?delete_success=1');
            exit();
        } else {
            $error = "Gagal menghapus jurusan: " . $conn->error;
        }
    }
}

// Handle edit request
if (isset($_POST['edit_id']) && isset($_POST['edit_nama'])) {
    $id = intval($_POST['edit_id']);
    $nama = trim($_POST['edit_nama']);
    
    if (empty($nama)) {
        $error = "Nama jurusan tidak boleh kosong!";
    } else {
        // Check if new name already exists for different id
        $check_query = "SELECT id FROM jurusan WHERE nama_jurusan = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $nama, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Jurusan dengan nama tersebut sudah ada!";
        } else {
            $update_query = "UPDATE jurusan SET nama_jurusan = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $nama, $id);
            
            if ($update_stmt->execute()) {
                header('Location: tambah_jurusan.php?edit_success=1');
                exit();
            } else {
                $error = "Gagal mengupdate jurusan: " . $conn->error;
            }
        }
    }
}

// Handle form submission for new jurusan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_id'])) {
    $nama_jurusan = trim($_POST['nama_jurusan']);
    
    if (empty($nama_jurusan)) {
        $error = "Nama jurusan tidak boleh kosong!";
    } else {
        $check_query = "SELECT id FROM jurusan WHERE nama_jurusan = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $nama_jurusan);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Jurusan sudah ada!";
        } else {
            $query = "INSERT INTO jurusan (nama_jurusan) VALUES (?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $nama_jurusan);
            
            if ($stmt->execute()) {
                header('Location: tambah_jurusan.php?success=1');
                exit();
            } else {
                $error = "Gagal menambahkan jurusan: " . $conn->error;
            }
        }
    }
}

// Fetch existing jurusan with total students
$query_list = "SELECT j.*, COUNT(u.id) as total_siswa 
               FROM jurusan j 
               LEFT JOIN users u ON j.id = u.jurusan_id 
               GROUP BY j.id 
               ORDER BY j.nama_jurusan ASC";
$result = $conn->query($query_list);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Tambah Jurusan - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(10, 46, 92, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-banner h2 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            width: 36px;
            height: 36px;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: #0a2e5c;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus {
            border-color: #0a2e5c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
        }

        button[type="submit"] {
            background: #0a2e5c;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            align-self: flex-start;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        button[type="submit"]:hover {
            background: #154785;
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .jurusan-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .jurusan-list h3 {
            color: #0a2e5c;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #f8fafc;
            color: #0a2e5c;
            font-weight: 600;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .total-siswa {
            background: #e0e7ff;
            color: #3730a3;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-edit, .btn-delete {
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-edit {
            background: #0ea5e9;
            color: white;
        }

        .btn-delete {
            background: #ef4444;
            color: white;
        }

        .btn-edit:hover, .btn-delete:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            position: relative;
        }

        .modal-title {
            font-size: 20px;
            color: #0a2e5c;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #666;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-confirm {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-save {
            background: #0ea5e9;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        .no-data {
            color: #666;
            padding: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            button[type="submit"] {
                width: 100%;
                justify-content: center;
            }

            .table-responsive {
                overflow-x: auto;
            }

            table {
                min-width: 500px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .modal-content {
                margin: 15px;
                width: calc(100% - 30px);
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-plus-circle"></i> Tambah Jurusan</h2>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-times"></i>
            </a>
        </div>

        <div class="form-card">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Jurusan berhasil ditambahkan!
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['edit_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Jurusan berhasil diupdate!
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['delete_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Jurusan berhasil dihapus!
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label for="nama_jurusan">Nama Jurusan:</label>
                    <input type="text" id="nama_jurusan" name="nama_jurusan" required 
                           placeholder="Masukkan nama jurusan" 
                           value="<?php echo isset($_POST['nama_jurusan']) ? htmlspecialchars($_POST['nama_jurusan']) : ''; ?>">
                </div>
                <button type="submit">
                    <i class="fas fa-plus"></i>
                    Tambah Jurusan
                </button>
            </form>
        </div>

        <div class="jurusan-list">
            <h3><i class="fas fa-list"></i> Daftar Jurusan</h3>
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Jurusan</th>
                                <th>Jumlah Siswa</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            while ($row = $result->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo htmlspecialchars($row['nama_jurusan']); ?></td>
                                    <td>
                                        <span class="total-siswa">
                                            <?php echo $row['total_siswa']; ?> Siswa
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn-edit" 
                                                    onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_jurusan'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </button>
                                            <button type="button" class="btn-delete" 
                                                    onclick="showDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_jurusan'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i>
                                                Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    Belum ada data jurusan
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-edit"></i>
                Edit Jurusan
            </div>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label for="edit_nama">Nama Jurusan:</label>
                    <input type="text" id="edit_nama" name="edit_nama" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideEditModal()">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i>
                        Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-trash"></i>
                Hapus Jurusan
            </div>
            <p>Apakah Anda yakin ingin menghapus jurusan <strong id="delete_nama"></strong>?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="delete_id" id="delete_id">
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Batal</button>
                    <button type="submit" class="btn-confirm">
                        <i class="fas fa-trash"></i>
                        Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Edit Modal Functions
        function showEditModal(id, nama) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Delete Modal Functions
        function showDeleteModal(id, nama) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_nama').textContent = nama;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>