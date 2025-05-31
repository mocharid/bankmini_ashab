<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../index.php");
    exit();
}

date_default_timezone_set('Asia/Jakarta');

// Get the current admin's user_id from session
$admin_id = $_SESSION['user_id'] ?? 0;

// Validate admin_id
if ($admin_id === 0) {
    error_log("Invalid admin_id: Session user_id not set");
    $error_message = 'Sesi tidak valid. Silakan login kembali.';
    $_SESSION['error_message'] = $error_message;
    header("Location: ../../index.php");
    exit();
}

// Check if admin_id exists in users table
$query_admin = "SELECT id FROM users WHERE id = ? AND role = 'admin'";
$stmt_admin = $conn->prepare($query_admin);
$stmt_admin->bind_param("i", $admin_id);
$stmt_admin->execute();
$result_admin = $stmt_admin->get_result();
if ($result_admin->num_rows === 0) {
    error_log("Invalid admin_id: $admin_id not found in users table or not an admin");
    $error_message = 'Akun admin tidak valid. Silakan hubungi administrator.';
    $_SESSION['error_message'] = $error_message;
    header("Location: ../../index.php");
    exit();
}
$stmt_admin->close();

// Fetch current block status
$query_block = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
$result_block = $conn->query($query_block);
if (!$result_block) {
    error_log("Database query failed: " . $conn->error);
    $is_blocked = 0; // Fallback to default
} else {
    $row = $result_block->fetch_assoc();
    if (!$row) {
        // No record exists, insert default
        $query_insert = "INSERT INTO petugas_block_status (id, is_blocked, updated_by, updated_at) VALUES (1, 0, ?, NOW())";
        $stmt_insert = $conn->prepare($query_insert);
        $stmt_insert->bind_param("i", $admin_id);
        if (!$stmt_insert->execute()) {
            error_log("Failed to insert default petugas_block_status: " . $stmt_insert->error);
        }
        $stmt_insert->close();
        $is_blocked = 0;
    } else {
        $is_blocked = $row['is_blocked'] ?? 0;
    }
}

// Handle form submission to toggle block status
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block'])) {
    $new_status = $is_blocked ? 0 : 1; // Toggle between 0 and 1
    $query_update = "UPDATE petugas_block_status SET is_blocked = ?, updated_by = ?, updated_at = NOW() WHERE id = 1";
    $stmt = $conn->prepare($query_update);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $error_message = 'Gagal memperbarui status. Silakan coba lagi.';
    } else {
        $stmt->bind_param("ii", $new_status, $admin_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success_message = $new_status ? 'Aktivitas petugas berhasil diblokir!' : 'Blokir aktivitas petugas berhasil dibuka!';
                $_SESSION['success_message'] = $success_message;
            } else {
                error_log("No rows affected in UPDATE petugas_block_status");
                $error_message = 'Gagal memperbarui status: Tidak ada perubahan.';
            }
        } else {
            error_log("Execute failed: " . $stmt->error);
            $error_message = 'Gagal memperbarui status. Silakan coba lagi.';
        }
        $stmt->close();
    }
    // Redirect to prevent form resubmission with no-cache headers
    header("Location: manage_petugas_status.php", true, 303);
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    exit();
}

// Retrieve and clear success/error message from session (PRG pattern)
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manajemen Status Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
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
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            padding: 1rem 2rem;
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
            padding: 0.625rem;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.25rem;
            width: 100%;
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 28rem;
            text-align: center;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .card-title {
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
        }

        .status-container {
            background: rgba(59, 130, 246, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .status-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .status-icon-container {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: <?= $is_blocked ? 'linear-gradient(135deg, var(--danger-color), #d32f2f)' : 'linear-gradient(135deg, var(--primary-dark), var(--secondary-color))' ?>;
            transition: var(--transition);
            cursor: pointer;
        }

        .status-icon-container:hover {
            transform: scale(1.1);
            filter: brightness(1.1);
        }

        .status-icon {
            font-size: clamp(1.8rem, 4vw, 2rem);
            color: white;
            animation: lockTransition 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes lockTransition {
            0% { transform: scale(0.8) rotate(-45deg); opacity: 0; }
            50% { transform: scale(1.1); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }

        .status-text {
            font-size: clamp(0.95rem, 2vw, 1rem);
            font-weight: 500;
            color: var(--text-primary);
        }

        .status-description {
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            color: var(--text-secondary);
            max-width: 24rem;
            margin: 0 auto;
        }

        .status-description.status-blocked {
            color: var(--danger-color);
        }

        .instruction-text {
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            color: var(--text-secondary);
            margin-top: 1rem;
            font-style: italic;
        }

        .status-icon-container.disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Modal Styling */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            backdrop-filter: blur(5px);
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal, .confirm-modal {
            position: relative;
            text-align: center;
            width: clamp(320px, 85vw, 420px);
            border-radius: 18px;
            padding: clamp(25px, 5vw, 35px);
            box-shadow: var(--shadow-md);
            transform: scale(0.9);
            opacity: 0;
            animation: popInModal 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
            margin: 0 15px;
        }

        .success-modal, .confirm-modal {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color), #d32f2f);
        }

        .success-modal::before, .error-modal::before, .confirm-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.9); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon, .confirm-icon {
            font-size: clamp(3.5rem, 8vw, 4.5rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.15); }
            80% { transform: scale(0.95); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3, .confirm-modal h3 {
            color: white;
            margin: 0 0 15px;
            font-size: clamp(1.3rem, 3vw, 1.5rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p, .confirm-modal p {
            color: white;
            font-size: clamp(0.9rem, 2.3vw, 1rem);
            margin: 0 0 20px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            box-shadow: 0 4px 8px rgba(30, 58, 138, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(30, 58, 138, 0.25);
        }

        .btn:active {
            transform: translateY(1px);
            box-shadow: 0 2px 5px rgba(30, 58, 138, 0.2);
        }

        .btn-cancel {
            background: var(--danger-color);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.2);
        }

        .btn-cancel:hover {
            background: #d32f2f;
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.25);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 0.75rem 1rem;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 0.75rem;
            }

            .card {
                padding: 1rem;
            }

            .card-title {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .status-icon-container {
                width: 60px;
                height: 60px;
            }

            .status-icon {
                font-size: clamp(1.5rem, 3.5vw, 1.8rem);
            }

            .status-text {
                font-size: clamp(0.85rem, 2vw, 0.9rem);
            }

            .status-description, .instruction-text {
                font-size: clamp(0.8rem, 1.7vw, 0.85rem);
            }

            .success-modal, .error-modal, .confirm-modal {
                width: clamp(300px, 90vw, 380px);
                padding: clamp(20px, 5vw, 30px);
            }

            .success-icon, .error-icon, .confirm-icon {
                font-size: clamp(3.2rem, 7vw, 4rem);
            }

            .success-modal h3, .error-modal h3, .confirm-modal h3 {
                font-size: clamp(1.2rem, 2.8vw, 1.4rem);
            }

            .success-modal p, .error-modal p, .confirm-modal p {
                font-size: clamp(0.85rem, 2.2vw, 0.95rem);
            }

            .btn {
                padding: 8px 16px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 0.5rem;
            }

            .card-title {
                font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            }

            .status-icon-container {
                width: 50px;
                height: 50px;
            }

            .status-icon {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .status-text {
                font-size: clamp(0.8rem, 1.8vw, 0.85rem);
            }

            .status-description, .instruction-text {
                font-size: clamp(0.75rem, 1.6vw, 0.8rem);
            }

            .success-modal, .error-modal, .confirm-modal {
                width: clamp(280px, 92vw, 350px);
                padding: clamp(18px, 4vw, 25px);
                border-radius: 16px;
            }

            .success-icon, .error-icon, .confirm-icon {
                font-size: clamp(2.8rem, 6.5vw, 3.5rem);
                margin-bottom: 15px;
            }

            .success-modal h3, .error-modal h3, .confirm-modal h3 {
                font-size: clamp(1.1rem, 2.7vw, 1.3rem);
                margin-bottom: 12px;
            }

            .success-modal p, .error-modal p, .confirm-modal p {
                font-size: clamp(0.8rem, 2.1vw, 0.9rem);
                margin-bottom: 18px;
            }

            .btn {
                padding: 8px 14px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }
        }
    </style>
    <script>
        // Prevent zooming and double-tap issues
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });

        // Modal and toggle handling
        document.addEventListener('DOMContentLoaded', function() {
            const notificationModal = document.getElementById('notificationModal');
            const confirmModal = document.getElementById('confirmModal');
            const statusIconContainer = document.querySelector('.status-icon-container');
            const statusIcon = document.querySelector('.status-icon');
            const statusText = document.querySelector('.status-text strong');
            const statusDescription = document.querySelector('.status-description');
            const confirmBtn = document.getElementById('confirmBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            let isSubmitting = false;

            // Handle notification modal (success/error)
            if (notificationModal) {
                // Auto-dismiss after 3 seconds
                setTimeout(() => {
                    notificationModal.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    setTimeout(() => {
                        notificationModal.remove();
                    }, 400);
                }, 3000);

                // Dismiss on click outside
                notificationModal.addEventListener('click', function(event) {
                    if (event.target.classList.contains('success-overlay')) {
                        notificationModal.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                        setTimeout(() => {
                            notificationModal.remove();
                        }, 400);
                    }
                });
            }

            // Show confirmation modal
            function showConfirmModal() {
                if (isSubmitting) return;
                isSubmitting = true;
                statusIconContainer.classList.add('disabled');

                const isBlocked = statusText.textContent === 'Diblokir';
                const actionText = isBlocked ? 'mengaktifkan' : 'menonaktifkan';
                document.getElementById('confirmMessage').textContent = `Apakah Anda yakin ingin ${actionText} aktivitas petugas?`;
                confirmModal.style.display = 'flex';
                confirmModal.style.opacity = '0';
                confirmModal.style.animation = 'fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            }

            // Close confirmation modal
            function closeConfirmModal() {
                confirmModal.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                setTimeout(() => {
                    confirmModal.style.display = 'none';
                    confirmModal.style.animation = '';
                    statusIconContainer.classList.remove('disabled');
                    isSubmitting = false;
                }, 400);
            }

            // Update UI after confirmation
            function updateUI() {
                const isBlocked = statusText.textContent === 'Diblokir';
                statusIcon.className = 'fas status-icon ' + (!isBlocked ? 'fa-lock' : 'fa-unlock');
                statusIconContainer.style.background = !isBlocked ? 'linear-gradient(135deg, var(--danger-color), #d32f2f)' : 'linear-gradient(135deg, var(--primary-dark), var(--secondary-color))';
                statusText.textContent = !isBlocked ? 'Diblokir' : 'Aktif';
                statusDescription.textContent = !isBlocked ? 'Petugas tidak dapat melakukan transaksi saat ini' : 'Petugas dapat melakukan transaksi secara normal';
                statusDescription.classList.toggle('status-blocked', !isBlocked);
            }

            // Handle icon click
            statusIconContainer.addEventListener('click', showConfirmModal);

            // Handle confirm button
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    updateUI();
                    closeConfirmModal();
                    setTimeout(() => {
                        document.getElementById('toggleForm').submit();
                    }, 500); // Match lock animation duration
                });
            }

            // Handle cancel button
            if (cancelBtn) {
                cancelBtn.addEventListener('click', closeConfirmModal);
            }

            // Close confirmation modal on click outside
            if (confirmModal) {
                confirmModal.addEventListener('click', function(event) {
                    if (event.target.classList.contains('success-overlay')) {
                        closeConfirmModal();
                    }
                });
            }
        });
    </script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Status Aktivitas Petugas</h2>
            
            <div class="status-container">
                <div class="status-info">
                    <div class="status-icon-container">
                        <i class="fas <?= $is_blocked ? 'fa-lock' : 'fa-unlock' ?> status-icon"></i>
                    </div>
                    <span class="status-text">
                        Aktivitas petugas saat ini: 
                        <strong><?= $is_blocked ? 'Diblokir' : 'Aktif' ?></strong>
                    </span>
                    <p class="status-description <?= $is_blocked ? 'status-blocked' : '' ?>">
                        <?= $is_blocked ? 'Petugas tidak dapat melakukan transaksi saat ini' : 'Petugas dapat melakukan transaksi secara normal' ?>
                    </p>
                    <p class="instruction-text">
                        Tekan ikon gembok untuk mengaktifkan atau menonaktifkan aktivitas petugas
                    </p>
                </div>
            </div>
            
            <form action="" method="POST" id="toggleForm" style="display: none;">
                <input type="hidden" name="toggle_block" value="1">
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="success-overlay" id="confirmModal" style="display: none;">
        <div class="confirm-modal">
            <div class="confirm-icon">
                <i class="fas fa-question-circle"></i>
            </div>
            <h3>Konfirmasi</h3>
            <p id="confirmMessage"></p>
            <div class="modal-buttons">
                <button type="button" class="btn" id="confirmBtn">Konfirmasi</button>
                <button type="button" class="btn btn-cancel" id="cancelBtn">Batal</button>
            </div>
        </div>
    </div>

    <!-- Success/Error Modal -->
    <?php if ($success_message): ?>
        <div class="success-overlay" id="notificationModal">
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>Berhasil</h3>
                <p><?= htmlspecialchars($success_message) ?></p>
            </div>
        </div>
    <?php elseif ($error_message): ?>
        <div class="success-overlay" id="notificationModal">
            <div class="error-modal">
                <div class="error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h3>Gagal</h3>
                <p><?= htmlspecialchars($error_message) ?></p>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>