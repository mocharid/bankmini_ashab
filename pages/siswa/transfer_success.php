<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$query = "SELECT u.nama, u.email, r.no_rekening, r.id AS rekening_id 
          FROM users u 
          JOIN rekening r ON u.id = r.user_id 
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data) {
    header("Location: dashboard.php");
    exit();
}

// Retrieve or set transaction details
$no_transaksi = isset($_SESSION['no_transaksi']) && !empty($_SESSION['no_transaksi']) ? $_SESSION['no_transaksi'] : null;
$transfer_amount = isset($_SESSION['transfer_amount']) && is_numeric($_SESSION['transfer_amount']) && $_SESSION['transfer_amount'] > 0 ? floatval($_SESSION['transfer_amount']) : 0;
$transfer_rekening = isset($_SESSION['transfer_rekening']) && !empty($_SESSION['transfer_rekening']) ? $_SESSION['transfer_rekening'] : null;
$transfer_name = isset($_SESSION['transfer_name']) && !empty($_SESSION['transfer_name']) ? $_SESSION['transfer_name'] : null;

// If session data is missing or page is refreshed, fetch from database
if (!$no_transaksi || !$transfer_rekening || !$transfer_name) {
    $query = "SELECT t.no_transaksi, t.jumlah, t.created_at, r.no_rekening AS transfer_rekening, u.nama AS transfer_name
              FROM transaksi t
              JOIN rekening r ON t.rekening_tujuan_id = r.id
              JOIN users u ON r.user_id = u.id
              WHERE t.rekening_id = ? AND t.jenis_transaksi = 'transfer' AND t.status = 'approved'
              ORDER BY t.created_at DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_data['rekening_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $transaction_data = $result->fetch_assoc();
    $stmt->close();

    if ($transaction_data) {
        $no_transaksi = $transaction_data['no_transaksi'];
        $transfer_amount = $transaction_data['jumlah'];
        $transfer_rekening = $transaction_data['transfer_rekening'];
        $transfer_name = $transaction_data['transfer_name'];
        $_SESSION['no_transaksi'] = $no_transaksi;
        $_SESSION['transfer_amount'] = $transfer_amount;
        $_SESSION['transfer_rekening'] = $transfer_rekening;
        $_SESSION['transfer_name'] = $transfer_name;
    } else {
        // Redirect if no valid transaction is found
        header("Location: transfer.php");
        exit();
    }
}

// Ensure no_rekening is set
$no_rekening = isset($user_data['no_rekening']) && !empty($user_data['no_rekening']) ? $user_data['no_rekening'] : 'Unknown';

function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, '.', '.');
}

function formatIndonesianDate($date)
{
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $months[(int) $dateObj->format('m')];
    $year = $dateObj->format('Y');
    $time = $dateObj->format('H:i:s');
    return "$day $month $year $time";
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <title>Transfer Berhasil - KASDIG</title>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.15);
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', Arial, sans-serif;
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

        .main-content {
            flex: 1;
            padding: 15px;
            width: 100%;
            max-width: 1200px;
            margin: 15px auto;
            text-align: center;
        }

        .success-container {
            text-align: center;
            animation: slideIn 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            max-width: 650px;
            margin: 0 auto;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-icon {
            font-size: 56px;
            color: #4CAF50;
            margin-bottom: 15px;
            animation: pulse 2s infinite ease-in-out;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.08);
            }
        }

        .success-title {
            color: var(--primary-dark);
            font-size: clamp(1.4rem, 2.5vw, 1.6rem);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .success-message {
            font-size: clamp(0.85rem, 2vw, 0.95rem);
            color: var(--text-primary);
            margin-bottom: 20px;
        }

        .receipt-card {
            background: #ffffff !important;
            width: 300px;
            /* 80mm at ~96 DPI */
            margin: 15px auto;
            padding: 0;
            box-shadow: var(--shadow-md);
            border-radius: 0;
            text-align: left;
            position: relative;
            font-family: 'Courier New', monospace;
            border: 1px solid #ddd;
        }

        .receipt-card::before,
        .receipt-card::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            height: 5px;
            background: repeating-linear-gradient(to right,
                    #ddd 0px,
                    #ddd 6px,
                    transparent 6px,
                    transparent 12px);
        }

        .receipt-card::before {
            top: 0;
        }

        .receipt-card::after {
            bottom: 0;
        }

        .receipt-card.no-pseudo::before,
        .receipt-card.no-pseudo::after {
            display: none;
        }

        .receipt-content {
            padding: 12px 10px;
            background: #ffffff !important;
            color: #333333 !important;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 1px dashed #888;
            padding: 15px 0 10px;
            /* Increased top padding for spacing */
            margin-bottom: 10px;
        }

        .receipt-header .bank-name {
            font-size: 16px;
            font-weight: bold;
            color: var(--primary-dark);
            margin-bottom: 4px;
            letter-spacing: 1.5px;
        }

        .receipt-header .address {
            font-size: 9px;
            color: #666;
            margin-bottom: 6px;
            line-height: 1.4;
            text-align: center;
        }

        .receipt-header .email {
            font-size: 9px;
            color: #888;
            word-break: break-all;
        }

        .receipt-section {
            margin-bottom: 10px;
        }

        .receipt-section-title {
            font-size: 10px;
            font-weight: bold;
            color: var(--primary-dark);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 5px;
            font-size: 9px;
            line-height: 1.3;
        }

        .receipt-label {
            color: #666;
            flex: 1;
            font-weight: 500;
        }

        .receipt-value {
            color: #333;
            font-weight: 600;
            text-align: right;
            flex: 1;
            word-break: break-word;
        }

        .receipt-divider {
            border-top: 1px dashed #888;
            margin: 10px 0;
        }

        .receipt-total {
            padding: 8px 0;
            margin: 10px 0;
            color: #333333 !important;
        }

        .receipt-total .receipt-row {
            font-size: 10px;
            font-weight: bold;
            color: var(--primary-dark);
        }

        .receipt-footer {
            text-align: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #888;
            padding-bottom: 12px;
        }

        .qr-code-container {
            text-align: center;
            margin: 5px auto;
            width: 80px;
        }

        .qr-code-container canvas {
            width: 80px;
            height: 80px;
            display: block;
            margin: 0 auto;
        }

        .receipt-footer .status {
            font-size: 10px;
            font-weight: bold;
            color: #4CAF50 !important;
            background: #e8f5e8 !important;
            padding: 3px 6px;
            border-radius: 3px;
            display: inline-block;
            margin-bottom: 8px;
        }

        .receipt-footer .disclaimer {
            font-size: 8px;
            color: #666;
            font-style: italic;
        }

        .button-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 12px;
        }

        button {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Poppins', Arial, sans-serif;
        }

        button:hover {
            background-color: var(--secondary-dark);
            transform: translateY(-2px);
        }

        button:active {
            opacity: 0.8;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .btn-download {
            background-color: var(--accent-color);
        }

        .btn-download:hover {
            background-color: #e65100;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--secondary-color);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 8px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 10px;
                margin-top: 10px;
            }

            .success-icon {
                font-size: 48px;
            }

            .receipt-card {
                width: 280px;
            }

            .receipt-content {
                padding: 10px 8px;
            }

            .receipt-header .bank-name {
                font-size: 14px;
            }

            .qr-code-container {
                width: 70px;
            }

            .qr-code-container canvas {
                width: 70px;
                height: 70px;
            }

            .button-container {
                flex-direction: column;
                align-items: center;
            }

            button {
                width: 100%;
                max-width: 260px;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .success-icon {
                font-size: 40px;
            }

            .receipt-card {
                width: 260px;
            }

            .receipt-content {
                padding: 8px 6px;
            }

            .receipt-header .bank-name {
                font-size: 13px;
            }

            .receipt-row {
                font-size: 8px;
            }

            .receipt-total .receipt-row {
                font-size: 9px;
            }

            .qr-code-container {
                width: 60px;
            }

            .qr-code-container canvas {
                width: 60px;
                height: 60px;
            }
        }
    </style>
</head>

<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Menyimpan struk...</p>
        </div>
    </div>

    <div class="main-content">
        <div class="success-container">
            <i class="fas fa-check-circle success-icon"></i>
            <h2 class="success-title">Transfer Berhasil!</h2>
            <p class="success-message">Yey, kamu berhasil transfer <?= formatRupiah($transfer_amount) ?> ke
                <?= htmlspecialchars($transfer_name) ?>!
            </p>

            <div class="receipt-card" id="receipt">
                <div class="receipt-content">
                    <div class="receipt-header">
                        <div class="bank-name">KASDIG</div>
                        <div class="address">
                            <div>Jl. K.H. Saleh No.57A</div>
                            <div>Sayang, Kec. Cianjur, Kabupaten Cianjur</div>
                        </div>
                        <div class="email">info@kasdig.web.id</div>
                    </div>

                    <div class="receipt-section">
                        <div class="receipt-section-title">Detail Transaksi</div>
                        <div class="receipt-row">
                            <div class="receipt-label">No. Referensi</div>
                            <div class="receipt-value"><?= htmlspecialchars($no_transaksi) ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Tanggal & Waktu</div>
                            <div class="receipt-value"><?= formatIndonesianDate(date('Y-m-d H:i:s')) ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Jenis Transaksi</div>
                            <div class="receipt-value">Transfer Antar KASDIG</div>
                        </div>
                    </div>

                    <div class="receipt-divider"></div>

                    <div class="receipt-section">
                        <div class="receipt-section-title">Informasi Pengirim</div>
                        <div class="receipt-row">
                            <div class="receipt-label">No. Rekening</div>
                            <div class="receipt-value"><?= htmlspecialchars($no_rekening) ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Nama</div>
                            <div class="receipt-value"><?= htmlspecialchars($user_data['nama']) ?></div>
                        </div>
                    </div>

                    <div class="receipt-section">
                        <div class="receipt-section-title">Informasi Penerima</div>
                        <div class="receipt-row">
                            <div class="receipt-label">No. Rekening</div>
                            <div class="receipt-value"><?= htmlspecialchars($transfer_rekening) ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Nama</div>
                            <div class="receipt-value"><?= htmlspecialchars($transfer_name) ?></div>
                        </div>
                    </div>

                    <div class="receipt-divider"></div>

                    <div class="receipt-total">
                        <div class="receipt-row">
                            <div class="receipt-label">Jumlah Transfer</div>
                            <div class="receipt-value"><?= formatRupiah($transfer_amount) ?></div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Biaya Admin</div>
                            <div class="receipt-value">Rp 0</div>
                        </div>
                        <div class="receipt-row">
                            <div class="receipt-label">Total</div>
                            <div class="receipt-value"><?= formatRupiah($transfer_amount) ?></div>
                        </div>
                    </div>

                    <div class="receipt-footer">
                        <div class="qr-code-container" id="qrcode"></div>
                        <div class="status">TRANSAKSI BERHASIL</div>
                        <div class="disclaimer">Struk ini merupakan bukti transaksi yang sah yang dikeluarkan oleh
                            KASDIG SYSTEM</div>
                    </div>
                </div>
            </div>

            <div class="button-container">
                <button onclick="downloadReceipt()" class="btn-download">
                    <i class="fas fa-download"></i> Download Struk
                </button>
                <button onclick="window.location.href='transfer.php'" class="btn-primary">
                    <i class="fas fa-home"></i> Kembali
                </button>
            </div>
        </div>
    </div>

    <script>
        // Preload Font Awesome to ensure icons are available
        const preloadLink = document.createElement('link');
        preloadLink.rel = 'preload';
        preloadLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2';
        preloadLink.as = 'font';
        preloadLink.crossOrigin = 'anonymous';
        document.head.appendChild(preloadLink);

        // Generate QR Code with retry mechanism
        function generateQRCode(containerId, retryCount = 0) {
            const qrContainer = document.getElementById(containerId);
            qrContainer.innerHTML = ''; // Clear any existing QR code
            try {
                new QRCode(qrContainer, {
                    text: "<?= htmlspecialchars($no_transaksi) ?>",
                    width: 80,
                    height: 80,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            } catch (error) {
                if (retryCount < 3) {
                    setTimeout(() => generateQRCode(containerId, retryCount + 1), 500);
                } else {
                    console.error('Failed to generate QR code after retries:', error);
                }
            }
        }

        // Download receipt as JPG
        function downloadReceipt() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            const receiptElement = document.getElementById('receipt');

            // Show loading overlay
            loadingOverlay.style.display = 'flex';

            // Create a temporary container for precise capture
            const tempContainer = document.createElement('div');
            tempContainer.style.width = '300px';
            tempContainer.style.position = 'absolute';
            tempContainer.style.left = '-9999px';
            tempContainer.style.background = '#ffffff';
            const clonedReceipt = receiptElement.cloneNode(true);
            clonedReceipt.classList.add('no-pseudo');
            tempContainer.appendChild(clonedReceipt);

            // Clear original QR code
            const originalQrCode = document.getElementById('qrcode');
            originalQrCode.innerHTML = '';

            // Generate QR code in the cloned element
            const clonedQrCode = clonedReceipt.querySelector('#qrcode');
            generateQRCode(clonedQrCode.id);

            document.body.appendChild(tempContainer);

            // Wait for QR code to render
            setTimeout(() => {
                html2canvas(tempContainer, {
                    backgroundColor: '#ffffff',
                    scale: 4,
                    useCORS: true,
                    allowTaint: true,
                    width: 300,
                    height: tempContainer.offsetHeight,
                    scrollX: 0,
                    scrollY: 0,
                    windowWidth: 300,
                    windowHeight: tempContainer.offsetHeight
                }).then(function (canvas) {
                    canvas.toBlob(function (blob) {
                        const url = URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = 'Struk_Transfer_<?= htmlspecialchars($no_transaksi) ?>.jpg';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        URL.revokeObjectURL(url);
                        document.body.removeChild(tempContainer);
                        loadingOverlay.style.display = 'none';
                        generateQRCode('qrcode');
                    }, 'image/jpeg', 0.95);
                }).catch(function (error) {
                    console.error('Error capturing receipt:', error);
                    alert('Gagal mengunduh struk. Silakan coba lagi.');
                    document.body.removeChild(tempContainer);
                    loadingOverlay.style.display = 'none';
                    generateQRCode('qrcode');
                });
            }, 1000); // Increased delay to ensure QR code renders
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(() => generateQRCode('qrcode'), 100);
        });

        // Prevent pinch-to-zoom and double-tap zoom
        document.addEventListener('touchstart', (event) => {
            if (event.touches.length > 1) event.preventDefault();
        }, { passive: false });

        document.addEventListener('gesturestart', (event) => event.preventDefault());
        document.addEventListener('wheel', (event) => {
            if (event.ctrlKey) event.preventDefault();
        }, { passive: false });

        // Clear session data after displaying
        window.addEventListener('load', () => {
            <?php
            unset($_SESSION['no_transaksi']);
            unset($_SESSION['transfer_amount']);
            unset($_SESSION['transfer_rekening']);
            unset($_SESSION['transfer_name']);
            unset($_SESSION['show_popup']);
            $_SESSION['current_step'] = 1;
            ?>
        });

        // Prevent back button issues
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>

<?php
$conn->close();
?>