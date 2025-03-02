<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Handle delete request
if (isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    
    // Check if kelas has students
    $check_query = "SELECT COUNT(*) as count FROM users WHERE kelas_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        $error = "Tidak dapat menghapus kelas karena masih memiliki siswa!";
    } else {
        $delete_query = "DELETE FROM kelas WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            header('Location: tambah_kelas.php?delete_success=1');
            exit();
        } else {
            $error = "Gagal menghapus kelas: " . $conn->error;
        }
    }
}

// Handle edit request
if (isset($_POST['edit_id']) && isset($_POST['edit_nama']) && isset($_POST['edit_jurusan'])) {
    $id = intval($_POST['edit_id']);
    $nama = trim($_POST['edit_nama']);
    $jurusan_id = intval($_POST['edit_jurusan']);
    
    if (empty($nama)) {
        $error = "Nama kelas tidak boleh kosong!";
    } else {
        // Check if new name already exists for different id in the same jurusan
        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("sii", $nama, $jurusan_id, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Kelas dengan nama tersebut sudah ada di jurusan ini!";
        } else {
            $update_query = "UPDATE kelas SET nama_kelas = ?, jurusan_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sii", $nama, $jurusan_id, $id);
            
            if ($update_stmt->execute()) {
                header('Location: tambah_kelas.php?edit_success=1');
                exit();
            } else {
                $error = "Gagal mengupdate kelas: " . $conn->error;
            }
        }
    }
}

// Handle form submission for new kelas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_id'])) {
    $nama_kelas = trim($_POST['nama_kelas']);
    $jurusan_id = intval($_POST['jurusan_id']);
    
    if (empty($nama_kelas)) {
        $error = "Nama kelas tidak boleh kosong!";
    } elseif (empty($jurusan_id)) {
        $error = "Silakan pilih jurusan!";
    } else {
        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $nama_kelas, $jurusan_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Kelas sudah ada di jurusan ini!";
        } else {
            $query = "INSERT INTO kelas (nama_kelas, jurusan_id) VALUES (?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $nama_kelas, $jurusan_id);
            
            if ($stmt->execute()) {
                header('Location: tambah_kelas.php?success=1');
                exit();
            } else {
                $error = "Gagal menambahkan kelas: " . $conn->error;
            }
        }
    }
}

// Ambil data jurusan for dropdowns
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan ASC";
$result_jurusan = $conn->query($query_jurusan);

// Fetch existing kelas with jurusan info and total students
$query_list = "SELECT k.*, j.nama_jurusan, COUNT(u.id) as total_siswa 
               FROM kelas k 
               LEFT JOIN jurusan j ON k.jurusan_id = j.id
               LEFT JOIN users u ON k.id = u.kelas_id 
               GROUP BY k.id 
               ORDER BY j.nama_jurusan ASC, k.nama_kelas ASC";
$result = $conn->query($query_list);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Kelas - SCHOBANK SYSTEM</title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navigation Styles */
        .top-nav {
            background: var(--primary-dark);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .nav-brand {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .form-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        input[type="text"],
        select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
            transition: var(--transition);
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        button[type="submit"] {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            align-self: flex-start;
        }

        button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 5px solid #4ade80;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 5px solid #f87171;
        }

        /* Kelas List Card */
        .kelas-list {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .kelas-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .kelas-list h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--primary-color);
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .total-siswa {
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .jurusan-badge {
            background: #f0fdfa;
            color: #0f766e;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-edit, .btn-delete {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .btn-edit {
            background: #0ea5e9;
            color: white;
        }

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .btn-edit:hover, .btn-delete:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        /* Modal Styles */
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
            padding: 2rem;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-md);
        }

        .modal-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #666;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background: #e5e7eb;
        }

        .btn-confirm {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .btn-save {
            background: #0ea5e9;
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: var(--transition);
        }

        .btn-confirm:hover, .btn-save:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .no-data {
            padding: 1.5rem;
            background: #f9fafb;
            border-radius: 8px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Loading Spinner */
        .loading {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.8);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .nav-buttons {
                width: 100%;
                justify-content: center;
            }

            .main-content {
                padding: 1rem;
            }

            button[type="submit"] {
                width: 100%;
                justify-content: center;
            }

            .welcome-banner,
            .form-card,
            .kelas-list {
                padding: 1.5rem;
            }

            .table-responsive {
                overflow-x: auto;
            }

            table {
                min-width: 500px;
            }

            th, td {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .modal-content {
                width: calc(100% - 2rem);
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading">
        <div class="spinner"></div>
    </div>

    <!-- Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">SCHOBANK</div>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-banner">
            <h2>
                <i class="fas fa-plus-circle"></i>
                <span>Tambah Kelas</span>
            </h2>
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
                    Kelas berhasil ditambahkan!
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['edit_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Kelas berhasil diupdate!
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['delete_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Kelas berhasil dihapus!
                </div>
            <?php endif; ?>

            <form action="" method="POST">
            <div class="form-group">
                    <label for="jurusan_id">Jurusan:</label>
                    <select id="jurusan_id" name="jurusan_id" required>
                        <option value="">Pilih Jurusan</option>
                        <?php 
                        // Reset the result pointer
                        $result_jurusan->data_seek(0);
                        while ($row = $result_jurusan->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nama_kelas">Nama Kelas:</label>
                    <input type="text" id="nama_kelas" name="nama_kelas" required 
                           placeholder="Masukkan nama kelas" 
                           value="<?php echo isset($_POST['nama_kelas']) ? htmlspecialchars($_POST['nama_kelas']) : ''; ?>">
                </div>
                
                <button type="submit">
                    <i class="fas fa-plus"></i>
                    <span>Tambah Kelas</span>
                </button>
            </form>
        </div>

        <div class="kelas-list">
            <h3>
                <i class="fas fa-list"></i>
                <span>Daftar Kelas</span>
            </h3>
            
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Kelas</th>
                                <th>Jurusan</th>
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
                                    <td><?php echo htmlspecialchars($row['nama_kelas']); ?></td>
                                    <td>
                                        <span class="jurusan-badge">
                                            <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="total-siswa">
                                            <?php echo $row['total_siswa']; ?> Siswa
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn-edit" 
                                                    onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_kelas'], ENT_QUOTES); ?>', <?php echo $row['jurusan_id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                <span>Edit</span>
                                            </button>
                                            <button type="button" class="btn-delete" 
                                                    onclick="showDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_kelas'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i>
                                                <span>Hapus</span>
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
                    <span>Belum ada data kelas</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-edit"></i>
                <span>Edit Kelas</span>
            </div>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label for="edit_nama">Nama Kelas:</label>
                    <input type="text" id="edit_nama" name="edit_nama" required>
                </div>
                <div class="form-group">
                    <label for="edit_jurusan">Jurusan:</label>
                    <select id="edit_jurusan" name="edit_jurusan" required>
                        <option value="">Pilih Jurusan</option>
                        <?php 
                        // Reset the result pointer
                        $result_jurusan->data_seek(0);
                        while ($row = $result_jurusan->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $row['id']; ?>">
                                <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideEditModal()">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i>
                        <span>Simpan</span>
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
                <span>Hapus Kelas</span>
            </div>
            <p>Apakah Anda yakin ingin menghapus kelas <strong id="delete_nama"></strong>?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="delete_id" id="delete_id">
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Batal</button>
                    <button type="submit" class="btn-confirm">
                        <i class="fas fa-trash"></i>
                        <span>Hapus</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Form validation and loading effects
            $('form').on('submit', function(e) {
                $('.loading').css('display', 'flex');
                
                // Hide loading after a short delay
                setTimeout(function() {
                    $('.loading').hide();
                }, 800);
            });

            // Table row hover effect
            $('table tbody tr').hover(
                function() { $(this).css('background-color', '#f8fafc'); },
                function() { $(this).css('background-color', ''); }
            );

            // Remove loading on page load
            $(window).on('load', function() {
                $('.loading').hide();
            });
        });

        // Edit Modal Functions
        function showEditModal(id, nama, jurusanId) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('edit_jurusan').value = jurusanId;
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