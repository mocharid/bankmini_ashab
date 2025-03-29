<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Ambil data transaksi berdasarkan filter tanggal
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default: awal bulan ini
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default: hari ini

// Pagination
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Query untuk mengambil total data
$query_total = "SELECT COUNT(*) as total 
                FROM transaksi t 
                JOIN rekening r ON t.rekening_id = r.id 
                JOIN users u ON r.user_id = u.id 
                WHERE DATE(t.created_at) BETWEEN ? AND ?";
$stmt_total = $conn->prepare($query_total);
if (!$stmt_total) {
    die("Error preparing total query: " . $conn->error);
}
$stmt_total->bind_param("ss", $start_date, $end_date);
$stmt_total->execute();
$result_total = $stmt_total->get_result();
$row_total = $result_total->fetch_assoc();
$total_records = $row_total['total']; // Total data yang ada
$total_pages = ceil($total_records / $limit); // Total halaman

// Query untuk mengambil data dengan pagination
$query = "SELECT t.*, 
          u.nama as nama_siswa,
          CASE 
              WHEN t.jenis_transaksi = 'transfer' THEN 
                  (SELECT u2.nama FROM users u2 
                   JOIN rekening r2 ON u2.id = r2.user_id 
                   WHERE r2.id = t.rekening_tujuan_id)
              ELSE NULL
          END as nama_penerima
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id
          JOIN users u ON r.user_id = u.id
          WHERE DATE(t.created_at) BETWEEN ? AND ?
          ORDER BY t.created_at DESC
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Error preparing query: " . $conn->error);
}
$stmt->bind_param("ssii", $start_date, $end_date, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - SCHOBANK SYSTEM</title>
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

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .filter-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        button.filter-btn {
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
        }

        button.filter-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .download-btn {
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            flex: 1;
            justify-content: center;
            min-width: 200px;
        }
        
        .download-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Results Card */
        .results-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            overflow-x: auto;
        }
        
        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
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

        .amount {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        /* Type Label */
        .type-label {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 500;
            text-align: center;
        }

        .type-setor {
            background-color: #e0f7ea;
            color: #0f766e;
        }

        .type-tarik {
            background-color: #ffedd5;
            color: #9a3412;
        }

        .type-transfer {
            background-color: #e0e9f5;
            color: #1e40af;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-info {
            background: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
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

        /* Button loading animation */
        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading span {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to {
                transform: rotate(360deg);
            }
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

            .filter-form {
                flex-direction: column;
                gap: 0.5rem;
            }

            .form-group {
                width: 100%;
            }

            .filter-btn, .download-btn {
                width: 100%;
                justify-content: center;
            }

            .welcome-banner,
            .filter-card,
            .results-card {
                padding: 1rem;
            }

            th, td {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
        /* Pagination Styles */
        .pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-top: 2rem;
    flex-wrap: wrap;
}

.page-link {
    padding: 0.5rem 1rem;
    border: 1px solid #ddd;
    border-radius: 10px;
    text-decoration: none;
    color: var(--primary-color);
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.page-link:hover {
    background: var(--primary-light);
    border-color: var(--primary-color);
}

.page-link.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
    pointer-events: none; /* Non-clickable */
}

/* Responsive Pagination */
@media (max-width: 768px) {
    .pagination {
        gap: 0.5rem;
    }

    .page-link {
        padding: 0.5rem;
        font-size: 0.9rem;
    }
}
        /* Tambahan CSS untuk tombol close */
        .close-btn {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            transition: var(--transition);
            z-index: 10; /* Pastikan tombol berada di atas elemen lain */
        }

        .close-btn:hover {
            color: var(--primary-light);
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading">
        <div class="spinner"></div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-banner">
            <h2>
                <i class="fas fa-chart-line"></i>
                <span>Laporan Transaksi</span>
            </h2>
            <!-- Tombol Close -->
            <button class="close-btn" id="closeBtn">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="filter-card">
            <form id="filterForm" action="" method="GET" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal:</label>
                    <input type="date" id="start_date" name="start_date" value="<?= $start_date ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal:</label>
                    <input type="date" id="end_date" name="end_date" value="<?= $end_date ?>" required>
                </div>
                
                <button type="submit" class="filter-btn" id="filterBtn">
                    <i class="fas fa-filter"></i>
                    <span>Filter</span>
                </button>
            </form>
            
            <div class="action-buttons">
                <a href="download_laporan.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&format=pdf" target="_blank" class="download-btn" id="pdfBtn">
                    <i class="fas fa-file-pdf"></i>
                    <span>Download PDF</span>
                </a>
                <a href="download_laporan.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&format=excel" target="_blank" class="download-btn" id="excelBtn">
                    <i class="fas fa-file-excel"></i>
                    <span>Download Excel</span>
                </a>
            </div>
        </div>

        <div class="results-card">
            <?php if ($result->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>No Transaksi</th>
                            <th>Nama Siswa</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                            <th>Keterangan</th>
                            <th>Tanggal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['no_transaksi']) ?></td>
                            <td><?= htmlspecialchars($row['nama_siswa']) ?></td>
                            <td>
                                <?php
                                $typeClass = '';
                                switch ($row['jenis_transaksi']) {
                                    case 'setor':
                                        $typeClass = 'type-setor';
                                        break;
                                    case 'tarik':
                                        $typeClass = 'type-tarik';
                                        break;
                                    case 'transfer':
                                        $typeClass = 'type-transfer';
                                        break;
                                }
                                ?>
                                <span class="type-label <?= $typeClass ?>">
                                    <?= ucfirst(htmlspecialchars($row['jenis_transaksi'])) ?>
                                </span>
                            </td>
                            <td class="amount">Rp <?= number_format($row['jumlah'], 2, ',', '.') ?></td>
                            <td>
                                <?php if ($row['jenis_transaksi'] == 'transfer' && !empty($row['nama_penerima'])): ?>
                                    Ke: <?= htmlspecialchars($row['nama_penerima']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=<?= $page - 1 ?>#pagination-anchor" class="page-link">
                        <i class="fas fa-chevron-left"></i>Back
                    </a>
                <?php endif; ?>

                <span class="page-link active"><?= $page ?></span>

                <?php if ($page < $total_pages): ?>
                    <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=<?= $page + 1 ?>#pagination-anchor" class="page-link">
                        Next<i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <span>Tidak ada transaksi dalam rentang tanggal ini.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Fungsi untuk tombol close
            $('#closeBtn').on('click', function() {
                window.location.href = 'dashboard.php'; // Redirect ke dashboard
            });

            // Form validation and loading effects
            $('#filterForm').on('submit', function(e) {
                const startDate = $('#start_date');
                const endDate = $('#end_date');
                const filterBtn = $('#filterBtn');
                
                if (!startDate.val() || !endDate.val()) {
                    e.preventDefault();
                    alert('Silakan pilih rentang tanggal yang valid');
                    return false;
                }
                
                if (new Date(startDate.val()) > new Date(endDate.val())) {
                    e.preventDefault();
                    alert('Tanggal awal tidak boleh lebih dari tanggal akhir');
                    return false;
                }

                // Show loading effects
                filterBtn.addClass('btn-loading');
                $('.loading').css('display', 'flex');
                
                // Hide loading after a short delay
                setTimeout(function() {
                    $('.loading').hide();
                }, 800);
            });

            // Download button loading effects
            $('#pdfBtn, #excelBtn').on('click', function() {
                $(this).addClass('btn-loading');
                $('.loading').css('display', 'flex');
                
                setTimeout(function() {
                    $('.loading').hide();
                    $('.download-btn').removeClass('btn-loading');
                }, 1000);
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

            // Keyboard navigation
            $('#start_date, #end_date').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#filterForm').submit();
                }
            });
            
            // Pagination loading effect
            $('.page-link').on('click', function(e) {
                e.preventDefault();
                const url = $(this).attr('href');
                
                // Show loading effects
                $('.loading').css('display', 'flex');
                
                // Redirect after a short delay
                setTimeout(function() {
                    window.location.href = url;
                }, 500);
            });
        });
    </script>
</body>
</html>