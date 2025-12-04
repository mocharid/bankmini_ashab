<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

$admin_id = $_SESSION['user_id'] ?? 0;

// Validasi admin_id
if ($admin_id <= 0) {
    header('Location: ../login.php');
    exit();
}

// Fungsi untuk mengubah nama hari ke Bahasa Indonesia
function getHariIndonesia($date) {
    $hari = date('l', strtotime($date));
    $hariIndo = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    return $hariIndo[$hari];
}

// Fungsi untuk format tanggal Indonesia
function formatTanggalIndonesia($date) {
    $bulan = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $bulan[$dateObj->format('F')];
    $year = $dateObj->format('Y');
    return "$day $month $year";
}

// Get total students count
$query = "SELECT COUNT(id) as total_siswa FROM users WHERE role = 'siswa'";
$result = $conn->query($query);
$total_siswa = $result->fetch_assoc()['total_siswa'] ?? 0;

// Get total saldo (net setoran hingga kemarin + setoran admin hari ini - penarikan admin hari ini)
$query = "SELECT (
            COALESCE((
                SELECT SUM(net_setoran)
                FROM (
                    SELECT DATE(created_at) as tanggal,
                           SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                           SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                    FROM transaksi
                    WHERE status = 'approved' AND DATE(created_at) < CURDATE()
                    GROUP BY DATE(created_at)
                ) as daily_net
            ), 0) +
            COALESCE((
                SELECT SUM(jumlah)
                FROM transaksi
                WHERE jenis_transaksi = 'setor' AND status = 'approved'
                      AND DATE(created_at) = CURDATE() AND petugas_id IS NULL
            ), 0) -
            COALESCE((
                SELECT SUM(jumlah)
                FROM transaksi
                WHERE jenis_transaksi = 'tarik' AND status = 'approved'
                      AND DATE(created_at) = CURDATE() AND petugas_id IS NULL
            ), 0)
          ) as total_saldo";
$result = $conn->query($query);
$total_saldo = $result->fetch_assoc()['total_saldo'] ?? 0;

// Get today's transactions for Net Setoran Harian (hanya transaksi oleh petugas)
$query_saldo_harian = "
    SELECT (
        COALESCE(SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END), 0)
    ) as saldo_harian
    FROM transaksi
    WHERE status = 'approved' 
    AND DATE(created_at) = CURDATE()
    AND petugas_id IS NOT NULL
";
$stmt_saldo_harian = $conn->prepare($query_saldo_harian);
$stmt_saldo_harian->execute();
$result_saldo_harian = $stmt_saldo_harian->get_result();
$saldo_harian = floatval($result_saldo_harian->fetch_assoc()['saldo_harian'] ?? 0);

// Query untuk data chart line (7 hari terakhir, semua transaksi admin dan petugas)
$query_chart = "SELECT DATE(created_at) as tanggal, COUNT(id) as jumlah_transaksi 
                FROM transaksi 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                AND status = 'approved'
                GROUP BY DATE(created_at) 
                ORDER BY DATE(created_at) ASC";
$result_chart = $conn->query($query_chart);
$chart_data = $result_chart->fetch_all(MYSQLI_ASSOC);

// Query untuk data pie chart (komposisi setor vs tarik 7 hari terakhir)
$query_pie = "SELECT 
                SUM(CASE WHEN jenis_transaksi = 'setor' THEN 1 ELSE 0 END) as setor_count,
                SUM(CASE WHEN jenis_transaksi = 'tarik' THEN 1 ELSE 0 END) as tarik_count
              FROM transaksi 
              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
              AND status = 'approved'";
$result_pie = $conn->query($query_pie);
$pie_data = $result_pie->fetch_assoc();
$setor_count = $pie_data['setor_count'] ?? 0;
$tarik_count = $pie_data['tarik_count'] ?? 0;
$total_transaksi = $setor_count + $tarik_count;
$setor_percentage = $total_transaksi > 0 ? round(($setor_count / $total_transaksi) * 100, 1) : 0;
$tarik_percentage = $total_transaksi > 0 ? round(($tarik_count / $total_transaksi) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Administrator | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            touch-action: pan-y;
        }

        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            zoom: 1;
            -webkit-text-size-adjust: 100%;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            transition: background-color 0.3s ease;
            overscroll-behavior: none;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: var(--transition);
            max-width: calc(100% - 280px);
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            animation: fadeIn 1s ease-in-out;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .welcome-banner .date {
            font-size: 0.9rem;
            font-weight: 400;
            opacity: 0.8;
            display: flex;
            align-items: center;
        }

        .welcome-banner .date i {
            margin-right: 8px;
        }

        /* Summary Section */
        .summary-section {
            padding: 10px 0 30px;
        }

        .summary-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .summary-header h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-right: 15px;
            position: relative;
        }

        .summary-header h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--secondary-color);
            border-radius: 2px;
        }

        /* Stats Grid Layout */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-box {
            background: white;
            border-radius: 5px;
            padding: 25px;
            position: relative;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: slideIn 0.5s ease-in-out;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 20px;
            transition: var(--transition);
            color: white;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        }

        .stat-box:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--primary-dark);
            letter-spacing: 0.5px;
        }

        .stat-value.counter {
            display: inline-block;
            font-family: 'Poppins', monospace;
            transition: var(--transition);
        }

        .stat-trend {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: auto;
            padding-top: 10px;
            font-weight: 400;
        }

        .stat-trend i {
            margin-right: 8px;
        }

        /* Chart Section */
        .chart-section {
            display: flex;
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-container {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            animation: fadeIn 1s ease-in-out;
            flex: 1;
            max-width: none;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
        }

        #transactionChart {
            max-width: 100%;
            height: auto;
        }

        #pieChart {
            max-width: 100%;
            height: 250px;
            max-height: 250px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
            padding: 20px;
            background: #f8fafc;
            border-radius: 5px;
            border: 1px solid #e2e8f0;
            font-weight: 400;
        }

        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .menu-toggle:hover {
            transform: scale(1.1);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive Design for Tablets (max-width: 768px) */
        @media (max-width: 768px) {
            .menu-toggle {
                display: flex;
                position: absolute;
                top: 50%;
                left: 20px;
                transform: translateY(-50%);
                z-index: 1000;
            }

            body.sidebar-active::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 998;
                transition: opacity 0.3s ease;
                opacity: 1;
            }

            body:not(.sidebar-active)::before {
                opacity: 0;
                pointer-events: none;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
                transition: opacity 0.3s ease;
            }

            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }

            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                box-shadow: var(--shadow-md);
                position: relative;
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .welcome-banner .content {
                flex: 1;
                padding-left: 50px;
            }

            .welcome-banner h2 {
                font-size: 1.4rem;
                font-weight: 600;
            }

            .welcome-banner .date {
                font-size: 0.85rem;
                font-weight: 400;
            }

            .summary-header h2 {
                font-size: 1.2rem;
            }

            .summary-header h2::after {
                width: 30px;
                height: 2px;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-box {
                padding: 18px;
                border-radius: 5px;
                box-shadow: var(--shadow-sm);
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.3rem;
                border-radius: 5px;
            }

            .stat-title {
                font-size: 0.85rem;
                font-weight: 500;
            }

            .stat-value {
                font-size: 1.4rem;
                font-weight: 600;
            }

            .stat-trend {
                font-size: 0.8rem;
            }

            .chart-section {
                flex-direction: column;
                gap: 20px;
            }

            .chart-container {
                padding: 15px;
                border-radius: 5px;
            }

            .chart-title {
                font-size: 1.1rem;
                margin-bottom: 15px;
            }

            #transactionChart {
                max-height: 200px;
            }

            #pieChart {
                height: 200px;
                max-height: 200px;
            }
        }

        /* Responsive Design for Small Phones (max-width: 480px) */
        @media (max-width: 480px) {
            .menu-toggle {
                font-size: 1.3rem;
                left: 15px;
                top: 50%;
                transform: translateY(-50%);
            }

            .main-content {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }

            .welcome-banner .content {
                padding-left: 45px;
            }

            .welcome-banner h2 {
                font-size: 1.2rem;
            }

            .welcome-banner .date {
                font-size: 0.8rem;
            }

            .summary-header h2 {
                font-size: 1.1rem;
            }

            .summary-header h2::after {
                width: 25px;
            }

            .stat-box {
                padding: 15px;
                border-radius: 5px;
            }

            .stat-icon {
                width: 42px;
                height: 42px;
                font-size: 1.2rem;
            }

            .stat-title {
                font-size: 0.8rem;
            }

            .stat-value {
                font-size: 1.3rem;
            }

            .stat-trend {
                font-size: 0.75rem;
            }

            .chart-section {
                gap: 15px;
            }

            .chart-container {
                padding: 12px;
            }

            .chart-title {
                font-size: 1rem;
            }

            #transactionChart {
                max-height: 180px;
            }

            #pieChart {
                height: 180px;
                max-height: 180px;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Selamat Datang, Administrator</h2>
                <div class="date">
                    <i class="far fa-calendar-alt"></i> <?= getHariIndonesia(date('Y-m-d')) . ', ' . formatTanggalIndonesia(date('Y-m-d')) ?>
                </div>
            </div>
        </div>

        <div class="summary-section">
            <div class="summary-header">
                <h2>Ikhtisar Keuangan</h2>
            </div>
            
            <div class="stats-container">
                <div class="stat-box students">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Rekening Nasabah</div>
                        <div class="stat-value counter" data-target="<?= $total_siswa ?>">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-user-graduate"></i>
                            <span>Rekening Aktif Terdaftar</span>
                        </div>
                    </div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Proyeksi Total Kas</div>
                        <div class="stat-value counter" data-target="<?= $total_saldo + $saldo_harian ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Estimasi Saldo Setelah Setoran Teller</span>
                        </div>
                    </div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Kas Administrator</div>
                        <div class="stat-value counter" data-target="<?= $total_saldo ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Posisi Kas Terkini</span>
                        </div>
                    </div>
                </div>
                <div class="stat-box setor">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Proyeksi Setoran Teller</div>
                        <div class="stat-value counter" data-target="<?= $saldo_harian ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar-day"></i>
                            <span>Saldo Bersih Teller Hari Ini</span>
                        </div>
                    </div>
                </div>
            </div>
        
            <div class="chart-section">
                <div class="chart-container">
                    <h3 class="chart-title">Grafik Transaksi 7 Hari Terakhir</h3>
                    <canvas id="transactionChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title">Komposisi Jenis Transaksi 7 Hari Terakhir</h3>
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        // Prevent zooming via JavaScript
        document.addEventListener('wheel', function(e) {
            if (e.ctrlKey) {
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('gesturestart', function(e) {
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('touchstart', function(e) {
            if (e.touches.length > 1) {
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '0')) {
                e.preventDefault();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Line Chart Configuration
            const ctxLine = document.getElementById('transactionChart').getContext('2d');
            const transactionChart = new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($chart_data, 'tanggal')) ?>,
                    datasets: [{
                        label: 'Jumlah Transaksi',
                        data: <?= json_encode(array_column($chart_data, 'jumlah_transaksi')) ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 3,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                        pointBorderColor: '#fff',
                        pointStyle: 'circle',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Jumlah Transaksi',
                                font: {
                                    family: 'Poppins',
                                    size: 14,
                                    weight: '500'
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Tanggal',
                                font: {
                                    family: 'Poppins',
                                    size: 14,
                                    weight: '500'
                                }
                            }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 2,
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuad'
                    }
                }
            });

            // Pie Chart Configuration
            const ctxPie = document.getElementById('pieChart').getContext('2d');
            const pieChart = new Chart(ctxPie, {
                type: 'pie',
                data: {
                    labels: ['Setoran', 'Penarikan'],
                    datasets: [{
                        data: [<?= $setor_count ?>, <?= $tarik_count ?>],
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.8)',
                            'rgba(30, 58, 138, 0.8)'
                        ],
                        borderColor: [
                            'rgba(59, 130, 246, 1)',
                            'rgba(30, 58, 138, 1)'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    family: 'Poppins',
                                    size: 12,
                                    weight: '500'
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuad'
                    }
                }
            });

            // Sidebar toggle for mobile
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            document.addEventListener('click', function(e) {
                if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Animated Counter for Numbers
            const counters = document.querySelectorAll('.counter');
            counters.forEach(counter => {
                const updateCount = () => {
                    const target = +counter.getAttribute('data-target');
                    const prefix = counter.getAttribute('data-prefix') || '';
                    const count = +counter.innerText.replace(/[^0-9]/g, '') || 0;
                    const increment = target / 100;

                    if (count < target) {
                        let newCount = Math.ceil(count + increment);
                        if (newCount > target) newCount = target;
                        counter.innerText = prefix + newCount.toLocaleString('id-ID');
                        setTimeout(updateCount, 20);
                    } else {
                        counter.innerText = prefix + target.toLocaleString('id-ID');
                    }
                };
                updateCount();
            });
        });
    </script>
</body>
</html>