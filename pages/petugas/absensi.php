<?php
session_start();
require_once '../../includes/db_connection.php';

// Cek apakah user sudah login dan role-nya adalah petugas
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    header("Location: login.php");
    exit;
}

// Set timezone ke WIB
date_default_timezone_set('Asia/Jakarta');

$user_id = $_SESSION['user_id'];
$nama = $_SESSION['nama'];
$message = "";
$today = date('Y-m-d');

// Cek apakah sudah absen masuk dan keluar hari ini
$checkQuery = "SELECT * FROM absensi WHERE user_id = ? AND tanggal = ?";
$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$result = $stmt->get_result();

$checkedIn = false;
$checkedOut = false;
$checkInTime = '';
$checkOutTime = '';

if ($result->num_rows > 0) {
    $attendanceData = $result->fetch_assoc();
    if ($attendanceData['waktu_masuk'] != null) {
        $checkedIn = true;
        $checkInTime = $attendanceData['waktu_masuk'];
    }
    if ($attendanceData['waktu_keluar'] != null) {
        $checkedOut = true;
        $checkOutTime = $attendanceData['waktu_keluar'];
    }
}

// Proses tombol absen masuk
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['absen_masuk']) && !$checkedIn) {
    $currentTime = date('H:i:s');
    
    if ($result->num_rows > 0) {
        // Update record yang sudah ada
        $updateQuery = "UPDATE absensi SET waktu_masuk = ?, updated_at = NOW() WHERE user_id = ? AND tanggal = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("sis", $currentTime, $user_id, $today);
    } else {
        // Buat record baru
        $insertQuery = "INSERT INTO absensi (user_id, tanggal, waktu_masuk) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("iss", $user_id, $today, $currentTime);
    }
    
    if ($stmt->execute()) {
        $checkedIn = true;
        $checkInTime = $currentTime;
        $message = "Absen masuk berhasil dicatat pada " . date('H:i:s');
        
        // Refresh halaman untuk menampilkan status terbaru
        header("Refresh:2");
    } else {
        $message = "Error: " . $stmt->error;
    }
}

// Proses tombol absen keluar
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['absen_keluar']) && $checkedIn && !$checkedOut) {
    $currentTime = date('H:i:s');
    
    $updateQuery = "UPDATE absensi SET waktu_keluar = ?, updated_at = NOW() WHERE user_id = ? AND tanggal = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("sis", $currentTime, $user_id, $today);
    
    if ($stmt->execute()) {
        $checkedOut = true;
        $checkOutTime = $currentTime;
        $message = "Absen keluar berhasil dicatat pada " . date('H:i:s');
        
        // Refresh halaman untuk menampilkan status terbaru
        header("Refresh:2");
    } else {
        $message = "Error: " . $stmt->error;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Absensi - SCHOBANK SYSTEM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1e88e5;
            --primary-hover: #1565c0;
            --success-color: #4caf50;
            --danger-color: #f44336;
            --text-color: #333;
            --bg-color: #f5f7fa;
            --card-bg: #ffffff;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        .container {
            width: 100%;
            max-width: 480px;
            padding: 0 15px;
        }

        .content {
            text-align: center;
            animation: fadeIn 0.8s ease;
        }

        h1 {
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            font-size: 1.8rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .attendance-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 8px 20px var(--shadow-color);
            padding: 2rem;
            position: relative;
            transition: all var(--transition-speed) ease;
            overflow: hidden;
            background-image: radial-gradient(circle at top right, rgba(30, 136, 229, 0.05), transparent 300px);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        .attendance-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), #64b5f6);
        }

        .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            color: #999;
            text-decoration: none;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
        }

        .close-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--danger-color);
            transform: rotate(90deg);
        }

        .user-info {
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 1rem;
        }

        .user-info::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background-color: var(--primary-color);
            border-radius: 3px;
        }

        .user-info h2 {
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
            color: var(--primary-color);
        }

        .timezone {
            color: #777;
            font-size: 0.9rem;
        }

        .date-container, .clock-container {
            margin-bottom: 1.5rem;
            background-color: rgba(245, 247, 250, 0.6);
            border-radius: var(--border-radius);
            padding: 1rem;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .clock-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .date {
            font-size: 1.1rem;
            font-weight: 500;
        }

        .clock {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-color);
            letter-spacing: 2px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .time-unit {
            width: 50px;
            height: 50px;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: var(--primary-color);
            color: white;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .time-separator {
            margin: 0 5px;
            animation: blink 1.5s infinite;
        }

        button {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            transition: all var(--transition-speed) ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease-out, height 0.6s ease-out;
        }

        button:hover::before {
            width: 300px;
            height: 300px;
        }

        button i {
            transition: transform var(--transition-speed) ease;
        }

        button:hover i {
            transform: rotate(360deg);
        }

        .check-in-btn {
            background-color: var(--primary-color);
            color: white;
        }

        .check-in-btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-3px);
        }

        .check-out-btn {
            background-color: var(--danger-color);
            color: white;
            margin-top: 15px;
        }

        .check-out-btn:hover {
            background-color: #d32f2f;
            transform: translateY(-3px);
        }

        .disabled-btn {
            background-color: var(--success-color);
            cursor: not-allowed;
            opacity: 0.9;
        }

        .disabled-btn:hover {
            transform: none;
        }

        .attendance-info {
            background-color: rgba(245, 247, 250, 0.6);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: left;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .attendance-info p {
            margin: 0.5rem 0;
            display: flex;
            justify-content: space-between;
        }

        .attendance-info p strong {
            color: var(--primary-color);
        }

        .success-message {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
            border-radius: var(--border-radius);
            padding: 0.8rem;
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            border-left: 4px solid var(--success-color);
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        .notification {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--success-color);
            color: white;
            padding: 15px 25px;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none;
            animation: slideUp 0.5s ease;
            max-width: 90%;
            text-align: center;
        }

        .notification .close-btn {
            color: white;
            position: absolute;
            top: 8px;
            right: 10px;
            cursor: pointer;
            opacity: 0.8;
        }

        .notification .close-btn:hover {
            opacity: 1;
        }

        .ripple {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.4);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }

        .submitting {
            opacity: 0.8;
            pointer-events: none;
        }

        .fade-out {
            animation: fadeOut 0.3s ease-out forwards;
        }

        /* Animations */
        @keyframes fadeIn {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }

        @keyframes fadeOut {
            0% { opacity: 1; }
            100% { opacity: 0; }
        }

        @keyframes slideUp {
            0% { transform: translate(-50%, 50px); opacity: 0; }
            100% { transform: translate(-50%, 0); opacity: 1; }
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .attendance-card {
                padding: 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .user-info h2 {
                font-size: 1.1rem;
            }
            
            .clock {
                font-size: 1.5rem;
            }
            
            .time-unit {
                width: 40px;
                height: 40px;
            }
        }

        @media (max-width: 360px) {
            .attendance-card {
                padding: 1rem;
            }
            
            h1 {
                font-size: 1.3rem;
            }
            
            button {
                padding: 0.8rem;
            }
            
            .time-unit {
                width: 35px;
                height: 35px;
                font-size: 1.3rem;
            }
        }

        /* Dark Mode Support */
        @media (prefers-color-scheme: dark) {
            :root {
                --bg-color: #121212;
                --card-bg: #1e1e1e;
                --text-color: #e0e0e0;
                --shadow-color: rgba(0, 0, 0, 0.4);
            }
            
            body {
                background-image: linear-gradient(135deg, #121212 0%, #2d3748 100%);
            }
            
            .attendance-card {
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .date-container, .clock-container, .attendance-info {
                background-color: rgba(30, 30, 30, 0.6);
            }
            
            .timezone {
                color: #aaa;
            }
            
            .close-btn:hover {
                background-color: rgba(255, 255, 255, 0.1);
            }
        }
    </style>
</head>
<body>
    <?php
    // Before the HTML output starts, make sure these variables are defined with default values
    $checkedIn = isset($checkedIn) ? $checkedIn : false;
    $checkedOut = isset($checkedOut) ? $checkedOut : false;
    $nama = isset($nama) ? $nama : 'Pengguna';
    $checkInTime = isset($checkInTime) ? $checkInTime : '';
    $checkOutTime = isset($checkOutTime) ? $checkOutTime : '';
    $message = isset($message) ? $message : '';
    $today = isset($today) ? $today : date('Y-m-d');
    ?>

    <div class="container">
        <div class="content">
            <h1>Absensi Digital Petugas</h1>
            
            <div class="attendance-card">
                <a href="dashboard.php" class="close-btn" title="Tutup">
                    <i class="fas fa-times"></i>
                </a>
                
                <div class="user-info">
                    <h2>Selamat <?php echo (!$checkedIn) ? 'Datang' : 'Bekerja'; ?>, <?php echo htmlspecialchars($nama); ?></h2>
                    <div class="timezone">Waktu Indonesia Barat (WIB)</div>
                </div>
                
                <div class="date-container">
                    <div class="clock-label">Tanggal</div>
                    <div class="date" id="current-date"></div>
                </div>
                
                <div class="clock-container">
                    <div class="clock-label">Waktu Saat Ini</div>
                    <div class="clock" id="current-time">
                        <span class="time-unit" id="hours">00</span>
                        <span class="time-separator">:</span>
                        <span class="time-unit" id="minutes">00</span>
                        <span class="time-separator">:</span>
                        <span class="time-unit" id="seconds">00</span>
                    </div>
                </div>
                
                <?php if (!$checkedIn && !$checkedOut): ?>
                    <!-- Tampilkan tombol absen masuk -->
                    <form method="post">
                        <button type="submit" name="absen_masuk" class="check-in-btn">
                            <i class="fas fa-sign-in-alt"></i> Absen Masuk Sekarang
                        </button>
                    </form>
                    <?php if (!empty($message)): ?>
                        <div class="success-message fade-in">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $message; ?> WIB
                        </div>
                    <?php endif; ?>
                <?php elseif ($checkedIn && !$checkedOut): ?>
                    <!-- Jika sudah absen masuk tapi belum absen keluar -->
                    <div class="attendance-info">
                        <p><strong>Absen Masuk</strong> <span><?php echo htmlspecialchars($checkInTime); ?> WIB</span></p>
                    </div>
                    <form method="post">
                        <button type="submit" name="absen_keluar" class="check-out-btn">
                            <i class="fas fa-sign-out-alt"></i> Absen Keluar Sekarang
                        </button>
                    </form>
                    <?php if (!empty($message)): ?>
                        <div class="success-message fade-in">
                            <i class="fas fa-check-circle"></i>
                            <?php echo $message; ?> WIB
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Jika sudah absen masuk dan keluar -->
                    <div class="attendance-info">
                        <p><strong>Absen Masuk</strong> <span><?php echo htmlspecialchars($checkInTime); ?> WIB</span></p>
                        <p><strong>Absen Keluar</strong> <span><?php echo htmlspecialchars($checkOutTime); ?> WIB</span></p>
                    </div>
                    <div class="success-message fade-in">
                        <i class="fas fa-check-circle"></i>
                        Anda sudah melengkapi absensi hari ini. Terima kasih atas kerja kerasnya!
                    </div>
                    <button class="check-out-btn disabled-btn" disabled>
                        <i class="fas fa-check"></i> Absensi Selesai
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notifikasi setelah absen keluar -->
    <div class="notification" id="checkout-notification">
        Terima kasih telah bekerja keras hari ini! 🎉
        <span class="close-btn" onclick="closeNotification('checkout-notification')">&times;</span>
    </div>

    <script>
        // Initial server time (akan sesuai dengan WIB karena server sudah diset ke timezone Asia/Jakarta)
        const serverTime = <?php echo json_encode(date('Y-m-d H:i:s')); ?>;
        const serverDate = new Date(serverTime);
        
        // Hitung offset antara waktu lokal dan waktu server (WIB)
        const localTime = new Date();
        const timeOffset = serverDate - localTime;
        
        // Update jam dan tanggal berdasarkan waktu WIB
        function updateClock() {
            // Dapatkan waktu lokal saat ini
            const now = new Date();
            
            // Tambahkan offset untuk mendapatkan waktu WIB yang akurat
            const wibTime = new Date(now.getTime() + timeOffset);
            
            const hoursElement = document.getElementById('hours');
            const minutesElement = document.getElementById('minutes');
            const secondsElement = document.getElementById('seconds');
            const dateElement = document.getElementById('current-date');
            
            // Format waktu sebagai HH:MM:SS dengan WIB
            const hours = String(wibTime.getHours()).padStart(2, '0');
            const minutes = String(wibTime.getMinutes()).padStart(2, '0');
            const seconds = String(wibTime.getSeconds()).padStart(2, '0');
            
            hoursElement.textContent = hours;
            minutesElement.textContent = minutes;
            secondsElement.textContent = seconds;
            
            // Format tanggal Indonesia
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            
            // Gunakan toLocaleDateString dengan locale 'id-ID' untuk format Indonesia
            const wibDate = new Date(wibTime.getTime());
            dateElement.textContent = wibDate.toLocaleDateString('id-ID', options);
        }
        
        // Update setiap detik
        updateClock();
        setInterval(updateClock, 1000);
        
        // Tampilkan notifikasi setelah absen keluar
        <?php if (isset($checkedOut) && $checkedOut && !empty($checkOutTime)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const notification = document.getElementById('checkout-notification');
                notification.style.display = 'block';
                setTimeout(() => {
                    notification.classList.add('fade-out');
                    setTimeout(() => {
                        notification.style.display = 'none';
                        notification.classList.remove('fade-out');
                    }, 500);
                }, 5000); // Notifikasi hilang setelah 5 detik
            });
        <?php endif; ?>
        
        // Fungsi untuk menutup notifikasi
        function closeNotification(id) {
            const notification = document.getElementById(id);
            notification.classList.add('fade-out');
            setTimeout(() => {
                notification.style.display = 'none';
                notification.classList.remove('fade-out');
            }, 300);
        }
        
        // Efek ripple pada tombol
        const buttons = document.querySelectorAll('button:not(.disabled-btn)');
        
        buttons.forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.classList.contains('disabled-btn')) return;
                
                const x = e.clientX - e.target.getBoundingClientRect().left;
                const y = e.clientY - e.target.getBoundingClientRect().top;
                
                const ripple = document.createElement('span');
                ripple.classList.add('ripple');
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });
        
        // Animasi pada form submission
        const forms = document.querySelectorAll('form');
        
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const button = this.querySelector('button');
                if (button) {
                    button.classList.add('submitting');
                    button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Memproses...`;
                }
            });
        });
        
        // 3D tilt effect untuk card
        const card = document.querySelector('.attendance-card');
        
        card.addEventListener('mousemove', function(e) {
            const { left, top, width, height } = this.getBoundingClientRect();
            const x = (e.clientX - left) / width;
            const y = (e.clientY - top) / height;
            
            const tiltX = (y - 0.5) * 5;
            const tiltY = (0.5 - x) * 5;
            
            this.style.transform = `perspective(1000px) rotateX(${tiltX}deg) rotateY(${tiltY}deg) scale3d(1.01, 1.01, 1.01)`;
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale3d(1, 1, 1)';
            this.style.boxShadow = '0 8px 20px rgba(0, 0, 0, 0.1)';
        });
        
        card.addEventListener('mouseenter', function() {
            this.style.boxShadow = '0 15px 30px rgba(0, 0, 0, 0.2)';
        });
        
        // Tambahkan kelas untuk animasi pada elemen saat page load
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.attendance-card, .date-container, .clock-container, button');
            elements.forEach((el, index) => {
                setTimeout(() => {
                    el.classList.add('fade-in');
                }, 100 * index);
            });
        });
    </script>
</body>
</html>