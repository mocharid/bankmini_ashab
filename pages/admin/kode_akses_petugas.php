<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

// Validate admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');
$error = null;
$success = null;
$existing_code = null;

// Check if a code exists for today
try {
    $code_query = "SELECT code FROM petugas_login_codes WHERE valid_date = ? AND admin_id = ?";
    $code_stmt = $conn->prepare($code_query);
    if (!$code_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $code_stmt->bind_param("si", $current_date, $admin_id);
    $code_stmt->execute();
    $code_result = $code_stmt->get_result();
    if ($code_result->num_rows > 0) {
        $existing_code = $code_result->fetch_assoc()['code'];
    }
    $code_stmt->close();
} catch (Exception $e) {
    $error = "Gagal memeriksa kode: " . $e->getMessage();
    error_log("Code check error: " . $e->getMessage(), 3, 'error.log');
}

// Handle code generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    try {
        $code = sprintf("%04d", mt_rand(0, 9999)); // Generate 4-digit code

        // Insert or update the code
        $query = "INSERT INTO petugas_login_codes (code, valid_date, admin_id) VALUES (?, ?, ?) 
                  ON DUPLICATE KEY UPDATE code = ?, updated_at = CURRENT_TIMESTAMP";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ssis", $code, $current_date, $admin_id, $code);
        if ($stmt->execute()) {
            $success = $stmt->affected_rows > 0 ? "Kode berhasil dibuat atau diperbarui!" : "Tidak ada perubahan pada kode.";
            $existing_code = $code;
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        $error = "Gagal membuat kode: " . $e->getMessage();
        error_log("Code generation error: " . $e->getMessage(), 3, 'error.log');
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kode Akses Petugas - SCHOBANK SYSTEM</title>
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
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.1rem, 2vw, 1.25rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 15px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
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
            margin-bottom: 8px;
            font-size: clamp(1.3rem, 2.5vw, 1.6rem);
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 25px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            width: auto;
            max-width: 180px;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }

        .success-overlay.visible {
            opacity: 1;
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            max-width: 90%;
            width: clamp(300px, 80%, 400px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.8);
            opacity: 0;
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }

        .success-modal.visible {
            transform: scale(1);
            opacity: 1;
        }

        .success-icon {
            font-size: clamp(3rem, 6vw, 4rem);
            color: var(--secondary-color);
            margin-bottom: 20px;
            transition: transform 0.3s ease-out;
        }

        .success-modal.visible .success-icon {
            transform: scale(1.1);
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 12px;
            font-size: clamp(1.25rem, 2.5vw, 1.4rem);
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .code-display {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: bold;
            color: var(--primary-dark);
            text-align: center;
            margin: 15px 0;
            padding: 10px;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 8px;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 15px;
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            opacity: 0.8;
            animation: confettiFall 4s ease-out forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(1rem, 2vw, 1.15rem);
            }

            .main-content {
                padding: 12px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            }

            .form-card {
                padding: 15px;
            }

            .btn {
                width: 100%;
                max-width: none;
                justify-content: center;
                padding: 8px 15px;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            .success-modal {
                padding: 20px;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .code-display {
                font-size: clamp(1.25rem, 3.5vw, 1.75rem);
                padding: 8px;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
            }

            .success-modal h3 {
                font-size: clamp(1.15rem, 2.5vw, 1.25rem);
            }

            .success-modal p {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .code-display {
                font-size: clamp(1rem, 3vw, 1.5rem);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>Kode Akses Petugas</h2>
            <p>Kode Untuk Petugas Mengakses Halaman Dashboard</p>
        </div>

        <!-- Code Generation Section -->
        <div class="form-card">
            <form action="" method="POST" id="code-form">
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" name="generate" class="btn" id="generate-btn">
                        <i class="fas fa-plus"></i> <?php echo $existing_code ? 'Ganti Kode' : 'Buat Kode Baru'; ?>
                    </button>
                    <?php if ($existing_code): ?>
                        <button type="button" class="btn btn-confirm" id="view-code-btn">
                            <i class="fas fa-eye"></i> Lihat Kode
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Confirmation Modal for Generating Code -->
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate']) && !isset($_POST['confirm'])): ?>
            <div class="success-overlay visible" id="confirmModal">
                <div class="success-modal visible">
                    <div class="success-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    <h3>Konfirmasi Pembuatan Kode</h3>
                    <p>Apakah Anda yakin ingin <?php echo $existing_code ? 'mengganti kode' : 'membuat kode baru'; ?> untuk hari ini? <?php echo $existing_code ? 'Kode sebelumnya akan diganti.' : ''; ?></p>
                    <form action="" method="POST" id="confirm-form">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='kode_akses_petugas.php'">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <?php if ($success): ?>
            <div class="success-overlay visible" id="successModal">
                <div class="success-modal visible">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p><?php echo htmlspecialchars($success); ?></p>
                    <div class="code-display"><?php echo htmlspecialchars($existing_code); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Modal -->
        <?php if ($error): ?>
            <div class="success-overlay visible" id="errorModal">
                <div class="success-modal visible">
                    <div class="success-icon" style="color: var(--danger-color);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>GAGAL</h3>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- View Code Modal -->
        <div class="success-overlay" id="viewCodeModal" style="display: none;">
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-key"></i>
                </div>
                <h3>Kode Akses Hari Ini</h3>
                <div class="code-display" id="codeValue"></div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="hideCodeModal()">
                        <i class="fas fa-times"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Handle success and error modals
            const modals = document.querySelectorAll('#successModal, #errorModal');
            modals.forEach(modal => {
                setTimeout(() => {
                    modal.classList.remove('visible');
                    setTimeout(() => {
                        modal.remove();
                        window.location.href = 'kode_akses_petugas.php';
                    }, 300);
                }, 3000);
                modal.addEventListener('click', () => {
                    modal.classList.remove('visible');
                    setTimeout(() => {
                        modal.remove();
                        window.location.href = 'kode_akses_petugas.php';
                    }, 300);
                });

                // Add confetti to success/error modals
                const successModal = modal.querySelector('.success-modal');
                if (successModal) {
                    for (let i = 0; i < 10; i++) {
                        const confetti = document.createElement('div');
                        confetti.className = 'confetti';
                        confetti.style.left = Math.random() * 100 + '%';
                        confetti.style.animationDelay = Math.random() * 1 + 's';
                        confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                        successModal.appendChild(confetti);
                    }
                }
            });

            // Handle confirmation modal
            const confirmModal = document.getElementById('confirmModal');
            if (confirmModal) {
                const successModal = confirmModal.querySelector('.success-modal');
                for (let i = 0; i < 10; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    successModal.appendChild(confetti);
                }
            }

            // Handle view code button
            const viewCodeBtn = document.getElementById('view-code-btn');
            if (viewCodeBtn) {
                viewCodeBtn.addEventListener('click', () => {
                    showCodeModal('<?php echo htmlspecialchars($existing_code); ?>');
                });
            }
        });

        // View Code Modal Functions
        let isModalOpening = false;

        function showCodeModal(code) {
            if (isModalOpening) return;
            isModalOpening = true;

            const modal = document.getElementById('viewCodeModal');
            const successModal = modal.querySelector('.success-modal');
            const codeValue = document.getElementById('codeValue');

            // Reset modal state
            modal.style.display = 'none';
            modal.classList.remove('visible');
            successModal.classList.remove('visible');
            codeValue.textContent = code;

            // Force reflow to prevent Safari rendering issues
            modal.offsetHeight;

            // Show modal
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('visible');
                successModal.classList.add('visible');
            }, 10);

            // Reset debounce flag
            setTimeout(() => {
                isModalOpening = false;
            }, 600);
        }

        function hideCodeModal() {
            const modal = document.getElementById('viewCodeModal');
            const successModal = modal.querySelector('.success-modal');
            modal.classList.remove('visible');
            successModal.classList.remove('visible');
            setTimeout(() => {
                modal.style.display = 'none';
                isModalOpening = false;
            }, 300);
        }

        // Prevent pinch zooming
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-tap zooming
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        // Prevent zoom on wheel with ctrl
        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-click zooming
        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });
    </script>
</body>
</html>