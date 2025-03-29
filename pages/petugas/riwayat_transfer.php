<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Connection handling
if (!isset($conn) && isset($koneksi)) {
    $conn = $koneksi;
} else if (!isset($conn) && !isset($koneksi)) {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "bankmini";
    
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Get filter parameter - default to today's date
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');

// Prepare the base query - Only show transfers processed by staff
$sql = "SELECT t.*, 
        r_asal.no_rekening as rekening_asal, u_asal.nama as nama_asal,
        r_tujuan.no_rekening as rekening_tujuan, u_tujuan.nama as nama_tujuan
        FROM transaksi t
        JOIN rekening r_asal ON t.rekening_id = r_asal.id
        JOIN users u_asal ON r_asal.user_id = u_asal.id
        JOIN rekening r_tujuan ON t.rekening_tujuan_id = r_tujuan.id
        JOIN users u_tujuan ON r_tujuan.user_id = u_tujuan.id
        WHERE t.jenis_transaksi = 'transfer'
        AND t.petugas_id IS NOT NULL"; // Only show transfers processed by staff

// Add conditions based on role
if ($role == 'siswa') {
    $sql .= " AND (r_asal.user_id = ? OR r_tujuan.user_id = ?)";
}

// Add date condition
$sql .= " AND DATE(t.created_at) = ?";
$params = [];
$types = '';

if ($role == 'siswa') {
    array_push($params, $user_id, $user_id);
    $types = 'ii';
}

array_push($params, $filter_date);
$types .= 's';

$sql .= " ORDER BY t.created_at DESC";

// Prepare and execute the query
$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$transfers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Transfer oleh Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

/* Header Banner Styles */
.welcome-banner {
  background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
  color: white;
  padding: 30px;
  border-radius: 15px;
  margin-bottom: 30px;
  text-align: center;
  position: relative;
  box-shadow: var(--shadow-md);
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
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.welcome-banner h2 {
  font-size: 28px;
  font-weight: 600;
  margin-bottom: 10px;
  position: relative;
  z-index: 1;
}

.welcome-banner p {
  font-size: 16px;
  opacity: 0.9;
  position: relative;
  z-index: 1;
}

.cancel-btn {
  position: absolute;
  top: 20px;
  left: 20px;
  background: rgba(255, 255, 255, 0.1);
  color: white;
  border: none;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  cursor: pointer;
  font-size: 16px;
  transition: var(--transition);
  text-decoration: none;
  z-index: 2;
}

.cancel-btn:hover {
  background: rgba(255, 255, 255, 0.2);
  transform: rotate(-90deg);
}

/* Search Form Styles */
.search-container {
  margin-bottom: 25px;
  width: 100%;
}

.search-form {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: 10px;
  background: white;
  padding: 8px 15px;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  flex: 1 0 auto;
}

.filter-group label {
  font-weight: 500;
  color: var(--primary-dark);
  white-space: nowrap;
  font-size: 14px;
}

.filter-group input[type="date"] {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 6px;
  background-color: white;
  font-family: 'Poppins', sans-serif;
  transition: var(--transition);
  min-width: 160px;
}

.filter-group input[type="date"]:focus {
  outline: none;
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
}

.search-form button {
  padding: 10px 20px;
  background-color: var(--primary-color);
  color: white;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: var(--transition);
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
  box-shadow: var(--shadow-sm);
  white-space: nowrap;
}

.search-form button:hover {
  background-color: var(--primary-dark);
  transform: translateY(-2px);
}

.search-form button i {
  font-size: 14px;
}

.reset-btn {
  padding: 10px;
  background-color: #f5f5f5;
  color: var(--text-secondary);
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: var(--transition);
  display: flex;
  align-items: center;
  justify-content: center;
}

.reset-btn:hover {
  background-color: #eee;
  color: var(--primary-dark);
}

/* Table Container Styles */
.transaction-card {
  background: white;
  border-radius: 15px;
  padding: 25px;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
  margin-bottom: 30px;
}

.table-container {
  position: relative;
  width: 100%;
  overflow: hidden;
}

.table-scroll-container {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 15px;
  min-width: 800px;
}

th, td {
  padding: 12px 15px;
  text-align: left;
  border-bottom: 1px solid #eee;
}

th {
  background-color: var(--primary-light);
  font-weight: 600;
  color: var(--primary-dark);
  position: sticky;
  top: 0;
}

tr:hover {
  background-color: rgba(12, 77, 162, 0.03);
}

/* Status Badges */
.status-badge {
  padding: 6px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  display: inline-block;
}

.status-approved {
  background-color: #dcfce7;
  color: #166534;
}

.status-pending {
  background-color: #fef3c7;
  color: #92400e;
}

.status-rejected {
  background-color: #fee2e2;
  color: #b91c1c;
}

/* Action Buttons */
.detail-btn {
  background-color: var(--primary-color);
  color: white;
  border: none;
  padding: 8px 12px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: var(--transition);
  text-decoration: none;
}

.detail-btn:hover {
  background-color: var(--primary-dark);
  transform: translateY(-2px);
}

/* Account Info Styles */
.account-info {
  font-weight: 500;
  color: var(--primary-dark);
}

.account-name {
  font-size: 13px;
  color: var(--text-secondary);
}

.amount {
  font-weight: 500;
  color: var(--text-primary);
  white-space: nowrap;
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 40px;
  color: var(--text-secondary);
}

.empty-state i {
  font-size: 48px;
  color: #ccc;
  margin-bottom: 15px;
}

/* Responsive Styles */
@media (max-width: 768px) {
  .main-content {
    padding: 15px;
  }
  
  .welcome-banner {
    padding: 25px 15px;
  }
  
  .welcome-banner h2 {
    font-size: 22px;
  }
  
  .search-form {
    flex-direction: column;
    gap: 10px;
  align-items: stretch;
  }
  
  .filter-group {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
    padding: 15px;
  }
  
  .filter-group label {
    margin-bottom: 5px;
  }
  
  .search-form button,
  .reset-btn {
    width: 100%;
    justify-content: center;
  }
  
  .transaction-card {
    padding: 15px;
  }
  
  th, td {
    padding: 10px 12px;
    font-size: 14px;
  }
}

@media (max-width: 480px) {
  .welcome-banner h2 {
    font-size: 20px;
    padding-top: 10px;
  }
  
  .filter-group input[type="date"] {
    width: 100%;
  }
  
  .account-info, .account-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 120px;
    display: block;
  }
}
        </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) include '../../includes/header.php'; ?>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-history"></i> Riwayat Transfer oleh Petugas</h2>
            <p>Transaksi Transfer yang diproses oleh petugas</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <div class="transaction-card">
            <div class="search-container">
                <form method="GET" class="search-form">
                    <div class="filter-group">
                        <label for="filter_date">Tanggal:</label>
                        <input type="date" name="filter_date" id="filter_date" 
                               value="<?= htmlspecialchars($filter_date) ?>">
                        <button type="submit"><i class="fas fa-search"></i> Cari</button>
                        <?php if ($filter_date != date('Y-m-d')): ?>
                            <a href="riwayat_transfer.php" class="cancel-btn" style="margin-left: 10px;" title="Reset Filter">
                                <i class="fas fa-undo"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (empty($transfers)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Tidak ada transaksi transfer yang diproses petugas pada tanggal <?= date('d/m/Y', strtotime($filter_date)) ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <div class="table-scroll-container" id="tableScrollContainer">
                        <table>
                            <thead>
                                <tr>
                                    <th>No. Transaksi</th>
                                    <th>Tanggal</th>
                                    <th>Dari</th>
                                    <th>Ke</th>
                                    <th>Jumlah</th>
                                    <th>Status</th>
                                    <?php if ($role != 'siswa'): ?>
                                        <th>Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfers as $transfer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($transfer['no_transaksi']) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($transfer['created_at'])) ?> WIB</td>
                                        <td>
                                            <div class="account-info"><?= htmlspecialchars($transfer['rekening_asal']) ?></div>
                                            <div class="account-name"><?= htmlspecialchars($transfer['nama_asal']) ?></div>
                                        </td>
                                        <td>
                                            <div class="account-info"><?= htmlspecialchars($transfer['rekening_tujuan']) ?></div>
                                            <div class="account-name"><?= htmlspecialchars($transfer['nama_tujuan']) ?></div>
                                        </td>
                                        <td class="amount">Rp <?= number_format($transfer['jumlah'], 2, ',', '.') ?></td>
                                        <td>
                                            <?php if ($transfer['status'] == 'approved'): ?>
                                                <span class="status-badge status-approved">Berhasil</span>
                                            <?php elseif ($transfer['status'] == 'pending'): ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php else: ?>
                                                <span class="status-badge status-rejected">Ditolak</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($role != 'siswa'): ?>
                                            <td>
                                                <a href="detail_transfer.php?id=<?= $transfer['id'] ?>" class="detail-btn">
                                                    <i class="fas fa-eye"></i> Detail
                                                </a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tableContainer = document.getElementById('tableScrollContainer');
            const scrollHint = document.querySelector('.scroll-hint');
            
            if (!tableContainer) return;
            
            // Check if table is wider than its container
            function checkTableWidth() {
                if (tableContainer.scrollWidth > tableContainer.clientWidth) {
                    scrollHint.style.display = 'block';
                    
                    // Hide hint after 3 seconds
                    setTimeout(() => {
                        scrollHint.style.opacity = '0';
                        setTimeout(() => {
                            scrollHint.style.display = 'none';
                        }, 500);
                    }, 3000);
                } else {
                    scrollHint.style.display = 'none';
                }
            }
            
            // Initial check
            checkTableWidth();
            
            // Check on window resize
            window.addEventListener('resize', checkTableWidth);
            
            // Implement grab-to-scroll functionality
            let isDown = false;
            let startX;
            let scrollLeft;

            tableContainer.addEventListener('mousedown', (e) => {
                isDown = true;
                tableContainer.classList.add('grabbing-scroll');
                startX = e.pageX - tableContainer.offsetLeft;
                scrollLeft = tableContainer.scrollLeft;
            });

            tableContainer.addEventListener('mouseleave', () => {
                isDown = false;
                tableContainer.classList.remove('grabbing-scroll');
            });

            tableContainer.addEventListener('mouseup', () => {
                isDown = false;
                tableContainer.classList.remove('grabbing-scroll');
            });

            tableContainer.addEventListener('mousemove', (e) => {
                if(!isDown) return;
                e.preventDefault();
                const x = e.pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2; // Scroll speed multiplier
                tableContainer.scrollLeft = scrollLeft - walk;
            });
            
            // Add grab class for visual feedback
            tableContainer.classList.add('grab-scroll');
            
            // Add touch events for mobile devices
            tableContainer.addEventListener('touchstart', (e) => {
                startX = e.touches[0].pageX - tableContainer.offsetLeft;
                scrollLeft = tableContainer.scrollLeft;
            }, { passive: true });

            tableContainer.addEventListener('touchmove', (e) => {
                if (!startX) return;
                const x = e.touches[0].pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2;
                tableContainer.scrollLeft = scrollLeft - walk;
            }, { passive: true });
            
            // Hide scroll hint when user scrolls
            tableContainer.addEventListener('scroll', () => {
                if (scrollHint.style.opacity !== '0') {
                    scrollHint.style.opacity = '0';
                    setTimeout(() => {
                        scrollHint.style.display = 'none';
                    }, 500);
                }
            });
            function adjustTableLayout() {
            const tableContainer = document.querySelector('.table-container');
            const table = document.querySelector('table');
            
            if (window.innerWidth < 768) {
                tableContainer.style.overflowX = 'auto';
                table.style.minWidth = '600px';
            } else {
                tableContainer.style.overflowX = 'hidden';
                table.style.minWidth = '100%';
            }
        }

        // Initial adjustment
        adjustTableLayout();
        
        // Adjust on window resize
        window.addEventListener('resize', adjustTableLayout);

        // Set today's date as default if no date is selected
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('filter_date');
            if (dateInput && !dateInput.value) {
                dateInput.valueAsDate = new Date();
            }
        });
        });
    </script>
</body>
</html>