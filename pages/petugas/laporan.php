<?php
// Atur zona waktu ke Asia/Jakarta untuk konsistensi fungsi tanggal
date_default_timezone_set('Asia/Jakarta');

// Sertakan file autentikasi dan koneksi database
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Ambil informasi pengguna dari sesi
$username = $_SESSION['username'] ?? 'Petugas';
$user_id = $_SESSION['user_id'] ?? 0; // ID petugas saat ini

// Dapatkan tanggal hari ini
$today = date('Y-m-d');

// Pengaturan paginasi
$limit = 10; // Jumlah data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Hitung statistik ringkasan untuk hari ini, kecuali transaksi transfer
$total_query = "SELECT 
    COUNT(id) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi 
    WHERE DATE(created_at) = ? 
    AND jenis_transaksi != 'transfer'";

$stmt = $conn->prepare($total_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();

// Hitung total penambahan saldo dari admin untuk petugas ini hari ini
$admin_transfers_query = "SELECT SUM(jumlah) as total_admin_transfers 
                         FROM saldo_transfers 
                         WHERE petugas_id = ? AND DATE(tanggal) = ?";
$stmt_admin = $conn->prepare($admin_transfers_query);
$stmt_admin->bind_param("is", $user_id, $today);
$stmt_admin->execute();
$total_admin_transfers = $stmt_admin->get_result()->fetch_assoc()['total_admin_transfers'] ?? 0;

// Hitung saldo bersih: total_setoran - total_penarikan (izinkan negatif)
$saldo_bersih = ($totals['total_setoran'] ?? 0) - ($totals['total_penarikan'] ?? 0);

// Hitung total jumlah data untuk paginasi
$total_records_query = "SELECT COUNT(*) as total 
                       FROM transaksi 
                       WHERE DATE(created_at) = ? 
                       AND jenis_transaksi != 'transfer'";
$stmt_total = $conn->prepare($total_records_query);
$stmt_total->bind_param("s", $today);
$stmt_total->execute();
$total_records = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Fungsi untuk memformat jumlah ke format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
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

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .report-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .report-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: var(--primary-light);
            padding: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: var(--transition);
        }

        .stat-box:hover {
            transform: scale(1.03);
        }

        .stat-box .stat-icon {
            font-size: 2rem;
            color: var(--primary-dark);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-box .stat-content {
            flex: 1;
        }

        .stat-box .stat-title {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            margin-bottom: 5px;
        }

        .stat-box .stat-value {
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            font-weight: 600;
        }

        .stat-box .stat-note {
            color: var(--text-secondary);
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            margin-top: 5px;
        }

        .summary-box {
            background: var(--primary-light);
            padding: 20px;
            border-radius: 10px;
            transition: var(--transition);
        }

        .summary-box:hover {
            transform: scale(1.03);
        }

        .summary-box h3 {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .summary-box .amount {
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            font-weight: 600;
        }

        .amount-total {
            color: var(--primary-dark);
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .download-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
        }

        .download-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .download-btn:active {
            transform: scale(0.95);
        }

        .download-btn.btn-loading {
            position: relative;
            pointer-events: none;
        }

        .download-btn.btn-loading span,
        .download-btn.btn-loading i {
            visibility: hidden;
        }

        .download-btn.btn-loading::after {
            content: '. . .';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: bold;
            animation: dots 1.5s infinite;
        }

        @keyframes dots {
            0% { content: '. . .'; }
            33% { content: '.. .'; }
            66% { content: '...'; }
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            white-space: nowrap;
        }

        th {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 600;
            text-transform: uppercase;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        td {
            color: var(--text-primary);
        }

        tr:hover {
            background-color: var(--primary-light);
        }

        .transaction-type {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            min-width: 90px;
        }

        .type-setoran {
            background: #e0f2fe;
            color: #0369a1;
        }

        .type-penarikan {
            background: #fee2e2;
            color: #b91c1c;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
        }

        .pagination-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .pagination-btn:active {
            transform: scale(0.95);
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Penyesuaian lebar kolom untuk keseimbangan */
        th:nth-child(1), td:nth-child(1) { width: 10%; } /* No */
        th:nth-child(2), td:nth-child(2) { width: 20%; } /* No Transaksi */
        th:nth-child(3), td:nth-child(3) { width: 30%; } /* Nama Siswa */
        th:nth-child(4), td:nth-child(4) { width: 15%; } /* Jenis */
        th:nth-child(5), td:nth-child(5) { width: 15%; } /* Jumlah */
        th:nth-child(6), td:nth-child(6) { width: 10%; } /* Waktu */

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .report-card {
                padding: 20px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .download-btn {
                width: 100%;
                justify-content: center;
            }

            th, td {
                padding: 10px;
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            }

            .transaction-type {
                min-width: 80px;
                padding: 5px 10px;
            }

            .summary-boxes {
                grid-template-columns: 1fr;
            }

            .pagination-btn {
                width: 100%;
                text-align: center;
            }

            .stat-box {
                flex-direction: column;
                align-items: flex-start;
            }

            .stat-box .stat-icon {
                margin-bottom: 10px;
            }

            /* Nonaktifkan white-space nowrap untuk responsivitas */
            th, td {
                white-space: normal;
            }
        }
    </style>
</head>
<body>
    <!-- Navigasi atas -->
    <nav class="top-nav">
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-xmark"></i>
        </a>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <!-- Konten utama -->
    <div class="main-content">
        <!-- Banner sambutan -->
        <div class="welcome-banner">
            <h2><i class="fas fa-chart-bar"></i> Laporan Harian</h2>
            <p>Transaksi pada <?= date('d/m/Y') ?></p>
        </div>

        <!-- Kartu laporan -->
        <div class="report-card">
            <h3 class="section-title"><i class="fas fa-list"></i> Ringkasan Transaksi</h3>
            <div class="summary-boxes">
                <!-- Total Transaksi -->
                <div class="summary-box">
                    <h3>Total Transaksi</h3>
                    <div class="amount"><?= $totals['total_transactions'] ?? 0 ?></div>
                </div>
                <!-- Total Setoran -->
                <div class="summary-box">
                    <h3>Total Setoran</h3>
                    <div class="amount"><?= formatRupiah($totals['total_setoran'] ?? 0) ?></div>
                </div>
                <!-- Total Penarikan -->
                <div class="summary-box">
                    <h3>Total Penarikan</h3>
                    <div class="amount"><?= formatRupiah($totals['total_penarikan'] ?? 0) ?></div>
                </div>
                <!-- Total Penambahan dari Admin -->
                <div class="summary-box">
                    <h3>Total Penambahan oleh Admin</h3>
                    <div class="amount"><?= formatRupiah($total_admin_transfers) ?></div>
                </div>
                <!-- Saldo Bersih -->
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Bersih</div>
                        <div class="stat-value"><?= formatRupiah($saldo_bersih) ?></div>
                        <div class="stat-note">Selisih setoran dan penarikan</div>
                    </div>
                </div>
            </div>

            <!-- Tombol aksi -->
            <div class="action-buttons">
                <a href="download_laporan.php?date=<?= $today ?>&format=pdf" class="download-btn" id="pdf-btn">
                    <i class="fas fa-file-pdf"></i>
                    <span>Unduh PDF</span>
                </a>
            </div>

            <!-- Tabel transaksi -->
            <div class="table-responsive" id="transaction-table">
                <!-- Data akan diisi oleh JavaScript -->
            </div>

            <!-- Paginasi -->
            <div class="pagination">
                <button id="prev-page" class="pagination-btn" style="display: none;">Kembali</button>
                <button id="next-page" class="pagination-btn" style="display: none;">Lanjut</button>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        const totalPages = <?= $total_pages ?>;
        const limit = <?= $limit ?>;

        // Fungsi untuk mengambil data transaksi dari server
        async function fetchTransactions(page) {
            try {
                const response = await fetch(`fetch_transactions.php?page=${page}`);
                if (!response.ok) throw new Error('Respon jaringan tidak ok');
                const transactions = await response.json();
                updateTable(transactions, page);
                updatePagination(page);
            } catch (error) {
                console.error('Gagal mengambil transaksi:', error);
                document.getElementById('transaction-table').innerHTML = `
                    <div class="alert">
                        <i class="fas fa-info-circle"></i>
                        <span>Gagal memuat transaksi. Silakan coba lagi.</span>
                    </div>`;
            }
        }

        // Fungsi untuk memperbarui tabel transaksi
        function updateTable(transactions, page) {
            const tableContainer = document.getElementById('transaction-table');
            
            if (transactions.length > 0) {
                let tableHTML = `
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>`;

                transactions.forEach((transaction, index) => {
                    const typeClass = transaction.jenis_transaksi === 'setor' ? 'type-setoran' : 'type-penarikan';
                    const displayType = transaction.jenis_transaksi === 'setor' ? 'Setoran' : 'Penarikan';
                    const noUrut = ((page - 1) * limit) + index + 1;
                    tableHTML += `
                        <tr>
                            <td>${noUrut}</td>
                            <td>${transaction.no_transaksi}</td>
                            <td>${transaction.nama_siswa}</td>
                            <td><span class="transaction-type ${typeClass}">${displayType}</span></td>
                            <td class="amount-total">Rp ${new Intl.NumberFormat('id-ID').format(transaction.jumlah)}</td>
                            <td>${new Date(transaction.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</td>
                        </tr>`;
                });

                tableHTML += `</tbody></table>`;
                tableContainer.innerHTML = tableHTML;
            } else {
                tableContainer.innerHTML = `
                    <div class="alert">
                        <i class="fas fa-info-circle"></i>
                        <span>TIDAK ADA TRANSAKSI HARI INI</span>
                    </div>`;
            }
        }

        // Fungsi untuk memperbarui tombol paginasi
        function updatePagination(page) {
            const prevButton = document.getElementById('prev-page');
            const nextButton = document.getElementById('next-page');

            prevButton.style.display = page > 1 ? 'inline-block' : 'none';
            nextButton.style.display = page < totalPages ? 'inline-block' : 'none';
        }

        // Event listener untuk tombol paginasi sebelumnya
        document.getElementById('prev-page').addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                fetchTransactions(currentPage);
            }
        });

        // Event listener untuk tombol paginasi berikutnya
        document.getElementById('next-page').addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                fetchTransactions(currentPage);
            }
        });

        // Fungsi untuk menangani proses unduh dengan efek loading
        function handleDownload(buttonId, url) {
            const button = document.getElementById(buttonId);
            button.classList.add('btn-loading');
            setTimeout(() => {
                button.classList.remove('btn-loading');
                window.location.href = url;
            }, 500);
        }

        // Event listener untuk tombol unduh PDF
        document.getElementById('pdf-btn').addEventListener('click', (e) => {
            e.preventDefault();
            handleDownload('pdf-btn', e.target.href);
        });

        // Panggil fungsi untuk mengambil transaksi saat halaman dimuat
        fetchTransactions(currentPage);
    </script>
</body>
</html>