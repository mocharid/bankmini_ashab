<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Fetch jurusan for dropdown
$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);

// Kelas will be loaded via AJAX based on jurusan selection
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Nasabah - SCHOBANK SYSTEM</title>
    
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-brand i {
            font-size: 1.2rem;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255,255,255,0.15);
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
            backdrop-filter: blur(5px);
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.25);
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
            gap: 0.75rem;
            font-size: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .search-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-dark));
            transform: scaleX(0);
            transform-origin: right;
            transition: transform 0.3s ease;
        }

        .search-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .search-card:hover::after {
            transform: scaleX(1);
            transform-origin: left;
        }

        /* Form Styles */
        .search-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            max-width: 600px;
        }

        .search-options {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .search-option {
            flex: 1;
            text-align: center;
        }

        .search-option input[type="radio"] {
            display: none;
        }

        .search-option label {
            display: block;
            padding: 0.75rem;
            background: var(--bg-light);
            border-radius: 10px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--text-secondary);
            box-shadow: var(--shadow-sm);
        }

        .search-option input[type="radio"]:checked + label {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 8px rgba(10, 46, 92, 0.2);
        }

        .search-fields {
            display: none;
        }

        .search-fields.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .form-group {
            margin-bottom: 1rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #ddd;
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background-color: #fcfcfc;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            background-color: white;
        }

        .search-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            padding: 0.85rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            font-weight: 500;
            box-shadow: 0 4px 10px rgba(12, 77, 162, 0.2);
            justify-content: center;
            width: 100%;
            margin-top: 1rem;
        }

        .search-btn:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(12, 77, 162, 0.25);
        }

        /* Results Card */
        .results-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.5s ease-out;
        }
        
        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Profile Info */
        .profile-info {
            padding: 1.5rem;
            background: var(--bg-light);
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
        }

        .profile-info p {
            margin: 0.75rem 0;
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .profile-info p:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .profile-info p span:first-child {
            font-weight: 500;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-info p span:last-child {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Alert Styles */
        .alert {
            padding: 1.25rem;
            border-radius: 12px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: fadeIn 0.5s ease-out;
        }

        .alert-info {
            background: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Loading Spinner */
        .loading {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }

        .spinner-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(12, 77, 162, 0.1);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .spinner-text {
            color: var(--primary-dark);
            font-weight: 500;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Form error animations */
        .form-error {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 0.75rem 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .welcome-banner, 
            .search-card,
            .results-card {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .search-options {
                flex-direction: column;
            }

            .search-btn {
                width: 100%;
                justify-content: center;
            }

            .profile-info p {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .profile-info p span:last-child {
                padding-left: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading" id="loadingSpinner">
        <div class="spinner-container">
            <div class="spinner"></div>
            <div class="spinner-text">Memuat data...</div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <i class="fas fa-university"></i>
            SCHOBANK
        </div>
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
                <i class="fas fa-user-circle"></i>
                <span>Profil Nasabah</span>
            </h2>
            <p>Cari profil nasabah berdasarkan No. Rekening atau filter lainnya</p>
        </div>

        <div class="search-card">
            <form id="searchForm" action="" method="GET" class="search-form">
                <div class="search-options">
                    <div class="search-option">
                        <input type="radio" id="search_rekening" name="search_type" value="rekening" checked>
                        <label for="search_rekening">Cari dengan No. Rekening</label>
                    </div>
                    <div class="search-option">
                        <input type="radio" id="search_filter" name="search_type" value="filter">
                        <label for="search_filter">Cari dengan Filter</label>
                    </div>
                </div>

                <div id="rekening_search" class="search-fields active">
                    <div class="form-group">
                        <label for="no_rekening">No Rekening:</label>
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan nomor rekening (contoh: REKK234455)" pattern="[A-Za-z0-9]*">
                    </div>
                </div>

                <div id="filter_search" class="search-fields">
                    <div class="form-group">
                        <label for="nama">Nama:</label>
                        <input type="text" id="nama" name="nama" placeholder="Masukkan nama nasabah">
                    </div>
                    <div class="form-group">
                        <label for="jurusan">Jurusan:</label>
                        <select id="jurusan" name="jurusan">
                            <option value="">Pilih Jurusan</option>
                            <?php while ($row = $result_jurusan->fetch_assoc()): ?>
                                <option value="<?= $row['id'] ?>"><?= $row['nama_jurusan'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="kelas">Kelas:</label>
                        <select id="kelas" name="kelas">
                            <option value="">Pilih Jurusan Terlebih Dahulu</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="search-btn" id="searchBtn">
                    <i class="fas fa-search"></i>
                    <span>Cari Nasabah</span>
                </button>
            </form>
        </div>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $where_clause = "";
            
            if (isset($_GET['no_rekening']) && !empty($_GET['no_rekening'])) {
                $no_rekening = $conn->real_escape_string($_GET['no_rekening']);
                $where_clause = "WHERE rekening.no_rekening = '$no_rekening'";
            } elseif (isset($_GET['nama']) || isset($_GET['jurusan']) || isset($_GET['kelas'])) {
                $conditions = [];
                
                if (!empty($_GET['nama'])) {
                    $nama = $conn->real_escape_string($_GET['nama']);
                    $conditions[] = "users.nama LIKE '%$nama%'";
                }
                if (!empty($_GET['jurusan'])) {
                    $jurusan = $conn->real_escape_string($_GET['jurusan']);
                    $conditions[] = "users.jurusan_id = '$jurusan'";
                }
                if (!empty($_GET['kelas'])) {
                    $kelas = $conn->real_escape_string($_GET['kelas']);
                    $conditions[] = "users.kelas_id = '$kelas'";
                }
                
                if (!empty($conditions)) {
                    $where_clause = "WHERE " . implode(" AND ", $conditions);
                }
            }

            if (!empty($where_clause)) {
                $query = "SELECT users.nama, users.username, jurusan.nama_jurusan, kelas.nama_kelas, 
                         rekening.saldo, rekening.no_rekening, rekening.created_at 
                         FROM users 
                         JOIN rekening ON users.id = rekening.user_id 
                         JOIN jurusan ON users.jurusan_id = jurusan.id 
                         JOIN kelas ON users.kelas_id = kelas.id 
                         $where_clause 
                         ORDER BY users.nama ASC";
                $result = $conn->query($query);

                if ($result && $result->num_rows > 0) {
                    echo '<div class="results-card">';
                    while ($row = $result->fetch_assoc()) {
                        echo '<div class="profile-info">';
                        echo "<p><span><i class='fas fa-credit-card'></i> No. Rekening</span> <span>{$row['no_rekening']}</span></p>";
                        echo "<p><span><i class='fas fa-user'></i> Nama</span> <span>{$row['nama']}</span></p>";
                        echo "<p><span><i class='fas fa-user-tag'></i> Username</span> <span>{$row['username']}</span></p>";
                        echo "<p><span><i class='fas fa-graduation-cap'></i> Jurusan</span> <span>{$row['nama_jurusan']}</span></p>";
                        echo "<p><span><i class='fas fa-users'></i> Kelas</span> <span>{$row['nama_kelas']}</span></p>";
                        echo "<p><span><i class='fas fa-wallet'></i> Saldo</span> <span>Rp " . number_format($row['saldo'], 2, ',', '.') . "</span></p>";
                        echo "<p><span><i class='fas fa-calendar-alt'></i> Tanggal Pembuatan</span> <span>" . date('d/m/Y', strtotime($row['created_at'] ?? 'now')) . "</span></p>";
                        echo '</div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="results-card">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Nasabah tidak ditemukan</strong><br>
                                Silakan periksa kembali data yang dimasukkan.
                            </div>
                        </div>
                    </div>';
                }
            }
        }
        ?>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle search fields based on selected option
            $('input[name="search_type"]').on('change', function() {
                $('.search-fields').removeClass('active');
                if (this.value === 'rekening') {
                    $('#rekening_search').addClass('active');
                } else {
                    $('#filter_search').addClass('active');
                }
            });

            // Form validation
            $('#searchForm').on('submit', function(e) {
                const searchType = $('input[name="search_type"]:checked').val();
                let isValid = true;

                if (searchType === 'rekening') {
                    const noRekening = $('#no_rekening').val().trim();
                    if (!noRekening) {
                        $('#no_rekening').addClass('form-error');
                        setTimeout(() => $('#no_rekening').removeClass('form-error'), 1000);
                        isValid = false;
                    }
                } else {
                    const nama = $('#nama').val().trim();
                    const jurusan = $('#jurusan').val();
                    const kelas = $('#kelas').val();
                    if (!nama && !jurusan && !kelas) {
                        $('.form-group input, .form-group select').addClass('form-error');
                        setTimeout(() => $('.form-group input, .form-group select').removeClass('form-error'), 1000);
                        isValid = false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading spinner
                $('#loadingSpinner').css('display', 'flex');
                
                // Simulating a loading delay for better UX
                setTimeout(function() {
                    $('#loadingSpinner').fadeOut(300);
                }, 800);
            });

            // Clear form when switching search types
            $('input[name="search_type"]').on('change', function() {
                // Clear all inputs
                $('#no_rekening').val('');
                $('#nama').val('');
                $('#jurusan').val('');
                $('#kelas').val('');
            });

            // Account number formatting
            $('#no_rekening').on('input', function() {
                this.value = this.value.toUpperCase().replace(/[^\dA-Z]/g, '');
                
                if (this.value.length > 20) {
                    this.value = this.value.slice(0, 20);
                }
            });

            // Load kelas based on selected jurusan
            $('#jurusan').on('change', function() {
                const jurusanId = this.value;
                const kelasDropdown = $('#kelas');
                
                // Reset kelas dropdown
                kelasDropdown.html('<option value="">Pilih Kelas</option>');
                
                if (!jurusanId) {
                    kelasDropdown.html('<option value="">Pilih Jurusan Terlebih Dahulu</option>');
                    return;
                }
                
                // Show loading spinner
                $('#loadingSpinner').css('display', 'flex');
                
                // Fetch kelas based on selected jurusan using AJAX
                fetch(`get_kelas.php?jurusan_id=${jurusanId}`)
                    .then(response => response.json())
                    .then(data => {
                        $('#loadingSpinner').fadeOut(300);
                        if (data.length > 0) {
                            data.forEach(kelas => {
                                const option = document.createElement('option');
                                option.value = kelas.id;
                                option.textContent = kelas.nama_kelas;
                                kelasDropdown.append(option);
                            });
                        } else {
                            kelasDropdown.html('<option value="">Tidak ada kelas untuk jurusan ini</option>');
                        }
                    })
                    .catch(error => {
                        $('#loadingSpinner').fadeOut(300);
                        console.error('Error fetching kelas:', error);
                        kelasDropdown.html('<option value="">Error loading kelas</option>');
                    });
            });
            
            // Remove loading on page load
            $(window).on('load', function() {
                $('#loadingSpinner').fadeOut(300);
            });
        });
    </script>
</body>
</html>