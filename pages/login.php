<?php
session_start();
require_once '../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// Function to detect mobile browser
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // List of mobile device indicators
    $mobileKeywords = array(
        'Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 
        'BlackBerry', 'IEMobile', 'Opera Mini', 'Opera Mobi',
        'webOS', 'Windows Phone', 'Windows CE', 'Symbian',
        'Samsung', 'Nokia', 'HTC', 'LG', 'Motorola', 'Sony'
    );
    
    foreach ($mobileKeywords as $keyword) {
        if (stripos($userAgent, $keyword) !== false) {
            return true;
        }
    }
    
    // Additional check for small screen devices
    if (preg_match('/(android|bb\\d+|meego).+mobile|avantgo|bada\\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $userAgent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\\-(n|u)|c55\\/|capi|ccwa|cdm\\-|cell|chtm|cldc|cmd\\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\\-s|devi|dica|dmob|do(c|p)o|ds(12|\\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\\-|_)|g1 u|g560|gene|gf\\-5|g\\-mo|go(\\.w|od)|gr(ad|un)|haie|hcit|hd\\-(m|p|t)|hei\\-|hi(pt|ta)|hp( i|ip)|hs\\-c|ht(c(\\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\\-(20|go|ma)|i230|iac( |\\-|\\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\\/)|klon|kpt |kwc\\-|kyo(c|k)|le(no|xi)|lg( g|\\/(k|l|u)|50|54|\\-[a-w])|libw|lynx|m1\\-w|m3ga|m50\\/|ma(te|ui|xo)|mc(01|21|ca)|m\\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\\-2|po(ck|rt|se)|prox|psio|pt\\-g|qa\\-a|qc(07|12|21|32|60|\\-[2-7]|i\\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\\-|oo|p\\-)|sdk\\/|se(c(\\-|0|1)|47|mc|nd|ri)|sgh\\-|shar|sie(\\-|m)|sk\\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\\-|v\\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\\-|tdg\\-|tel(i|m)|tim\\-|t\\-mo|to(pl|sh)|ts(70|m\\-|m3|m5)|tx\\-9|up(\\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\\-|your|zeto|zte\\-/i', substr($userAgent, 0, 4))) {
        return true;
    }
    
    return false;
}

// Handle AJAX request to check user role
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'check_role') {
    $username = $_POST['username'];
    $query = "SELECT role FROM users WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo json_encode(['role' => $user['role']]);
    } else {
        echo json_encode(['role' => null]);
    }

    $stmt->close();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $today = date('Y-m-d');

    // Query user credentials
    $query = "SELECT u.* FROM users u WHERE u.username = ? AND u.password = SHA2(?, 256)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Check if user is siswa and trying to login from desktop
        if ($user['role'] === 'siswa' && !isMobileDevice()) {
            $_SESSION['errors'] = ['general' => 'Akun siswa hanya dapat login melalui perangkat mobile/HP. Silakan gunakan smartphone Anda.'];
            $_SESSION['form_data'] = ['username' => $username, 'password' => ''];
            $stmt->close();
            header("Location: login.php");
            exit();
        }

        // Check if user is petugas
        if ($user['role'] === 'petugas') {
            $check_tugas_query = "SELECT COUNT(*) as tugas_count
                                 FROM petugas_tugas
                                 WHERE (petugas1_id = ? OR petugas2_id = ?)
                                 AND tanggal = ?";
            $check_tugas_stmt = $conn->prepare($check_tugas_query);
            $check_tugas_stmt->bind_param("iis", $user['id'], $user['id'], $today);
            $check_tugas_stmt->execute();
            $tugas_result = $check_tugas_stmt->get_result();
            $tugas_data = $tugas_result->fetch_assoc();

            if ($tugas_data['tugas_count'] == 0) {
                $_SESSION['errors'] = ['general' => 'Anda tidak bertugas hari ini. Silakan hubungi administrator.'];
                $_SESSION['form_data'] = ['username' => $username, 'password' => ''];
                $check_tugas_stmt->close();
                $stmt->close();
                header("Location: login.php");
                exit();
            }
            $check_tugas_stmt->close();
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['nama'] = $user['nama'];
        $_SESSION['username'] = $user['username'];

        // For petugas, store schedule
        if ($user['role'] === 'petugas') {
            $schedule_query = "SELECT * FROM petugas_tugas
                              WHERE (petugas1_id = ? OR petugas2_id = ?)
                              AND tanggal = ?";
            $schedule_stmt = $conn->prepare($schedule_query);
            $schedule_stmt->bind_param("iis", $user['id'], $user['id'], $today);
            $schedule_stmt->execute();
            $schedule_result = $schedule_stmt->get_result();

            if ($schedule_result->num_rows > 0) {
                $schedule = $schedule_result->fetch_assoc();
                $_SESSION['jadwal_id'] = $schedule['id'];
                $_SESSION['petugas1_nama'] = $schedule['petugas1_nama'];
                $_SESSION['petugas2_nama'] = $schedule['petugas2_nama'];
            }
            $schedule_stmt->close();
        }

        $stmt->close();
        header("Location: ../index.php");
        exit();
    } else {
        // Check if username exists
        $query_role = "SELECT role FROM users WHERE username = ?";
        $stmt_role = $conn->prepare($query_role);
        $stmt_role->bind_param("s", $username);
        $stmt_role->execute();
        $result_role = $stmt_role->get_result();

        if ($result_role->num_rows > 0) {
            $_SESSION['errors'] = ['password' => 'Kata sandi salah'];
            $_SESSION['form_data'] = ['username' => $username, 'password' => ''];
        } else {
            $_SESSION['errors'] = ['username' => 'Username tidak ditemukan'];
            $_SESSION['form_data'] = ['username' => '', 'password' => ''];
        }
        $stmt_role->close();
        header("Location: login.php");
        exit();
    }
    $stmt->close();
}

// Retrieve session data
$errors = isset($_SESSION['errors']) ? $_SESSION['errors'] : [];
$form_data = isset($_SESSION['form_data']) ? $_SESSION['form_data'] : ['username' => '', 'password' => ''];
unset($_SESSION['errors'], $_SESSION['form_data']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Masuk | My Schobank</title>
    <!-- Favicon sama dengan logo utama -->
    <link rel="icon" type="image/jpeg" href="/schobank/assets/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1A237E;
            --primary-dark: #0d1452;
            --primary-light: #534bae;
            --secondary-color: #00B4D8;
            --danger-color: #dc2626;
            --success-color: #16a34a;
            --text-primary: #000000; /* Semua teks hitam */
            --text-secondary: #000000;
            --bg-white: #ffffff;
            --border-light: #e2e8f0;
            --border-medium: #cbd5e1;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --transition: all 0.3s ease;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        html, body {
            height: 100%;
            touch-action: manipulation;
        }
        body {
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            min-height: 100vh;
        }
        .login-container {
            background: var(--bg-white);
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--border-light);
            transition: var(--transition);
        }
        .header-section {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }
        .logo-container {
            margin-bottom: 1rem;
        }
        .logo {
            height: auto;
            max-height: 80px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            transition: var(--transition);
        }
        .logo:hover {
            transform: scale(1.03);
        }
        .subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 400;
        }
        .form-section {
            margin-bottom: 1.5rem;
        }
        .input-group {
            margin-bottom: 1.25rem;
            position: relative;
        }
        .input-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-size: 0.875rem;
            font-weight: 500;
        }
        .input-wrapper {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.1rem;
            z-index: 2;
        }
        .input-field {
            width: 100%;
            padding: 14px 14px 14px 46px;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-md);
            font-size: 15px;
            background: #fdfdfd;
            transition: var(--transition);
            color: var(--text-primary);
        }
        .input-field:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--bg-white);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.12);
        }
        .input-field.input-error {
            border-color: var(--danger-color);
            background: #fef2f2;
        }
        .input-field.input-error:focus {
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
        }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 4px;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }
        .toggle-password:hover {
            color: var(--primary-color);
            background: rgba(26, 35, 126, 0.08);
        }
        .error-message {
            color: var(--danger-color);
            font-size: 0.8rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .general-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.9rem;
            transition: opacity 0.4s ease;
        }
        .general-error.fade-out {
            opacity: 0;
        }
        .forgot-password {
            text-align: right;
            margin: -0.5rem 0 1.5rem 0;
        }
        .forgot-link {
            color: var(--text-primary);
            font-size: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition);
        }
        .forgot-link .link-here {
            color: #2563eb; /* Biru link */
            text-decoration: none; /* Tanpa underline */
        }
        .forgot-link:hover .link-here {
            color: #1d4ed8;
            text-decoration: none;
        }
        .submit-button {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }
        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .submit-button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsif */
        @media (max-width: 480px) {
            body { padding: 15px; }
            .login-container {
                padding: 2rem;
                border-radius: var(--radius-md);
            }
            .logo { max-height: 70px; }
            .input-field { font-size: 14px; padding: 12px 12px 12px 42px; }
            .submit-button { padding: 12px 18px; font-size: 14px; }
            .forgot-link { font-size: 0.7rem; }
        }
        @media (max-height: 600px) and (orientation: landscape) {
            body { align-items: flex-start; padding-top: 20px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header -->
        <div class="header-section">
            <div class="logo-container">
                <img src="/schobank/assets/images/header.png" alt="My Schobank Logo" class="logo">
            </div>
            <p class="subtitle">Silahkan masuk ke akun kamu!</p>
        </div>

        <!-- General Error (Auto-hide in 5 seconds) -->
        <?php if (isset($errors['general'])): ?>
            <div class="general-error" id="generalError">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" id="login-form">
            <div class="form-section">
                <!-- Username -->
                <div class="input-group">
                    <label class="input-label" for="username">
                        <i class="fas fa-user" style="margin-right: 6px; font-size: 0.8rem;"></i> Username
                    </label>
                    <div class="input-wrapper">
                        <input type="text" name="username" id="username" class="input-field <?= isset($errors['username']) ? 'input-error' : '' ?>"
                               placeholder="Masukkan username" value="<?= htmlspecialchars($form_data['username']) ?>" required autocomplete="username">
                        <i class="fas fa-user input-icon"></i>
                    </div>
                    <?php if (isset($errors['username'])): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($errors['username']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Password -->
                <div class="input-group">
                    <label class="input-label" for="password">
                        <i class="fas fa-lock" style="margin-right: 6px; font-size: 0.8rem;"></i> Password
                    </label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" class="input-field <?= isset($errors['password']) ? 'input-error' : '' ?>"
                               placeholder="Masukkan password" required autocomplete="current-password">
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($errors['password']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="forgot-password">
                <a href="forgot_password.php" class="forgot-link">
                    Lupa Password? Klik <span class="link-here">disini</span>
                </a>
            </div>

            <button type="submit" class="submit-button" id="submitButton">
                <i class="fas fa-sign-in-alt"></i>
                <span id="buttonText">Masuk ke Sistem</span>
                <div class="loading-spinner" id="loadingSpinner"></div>
            </button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('login-form');
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const toggleBtn = document.getElementById('togglePassword');
            const submitBtn = document.getElementById('submitButton');
            const btnText = document.getElementById('buttonText');
            const spinner = document.getElementById('loadingSpinner');

            // Auto-hide general error after 5 seconds
            const generalError = document.getElementById('generalError');
            if (generalError) {
                setTimeout(() => {
                    generalError.classList.add('fade-out');
                    setTimeout(() => generalError.remove(), 400);
                }, 5000);
            }

            // Toggle password
            toggleBtn?.addEventListener('click', () => {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                toggleBtn.querySelector('i').classList.toggle('fa-eye');
                toggleBtn.querySelector('i').classList.toggle('fa-eye-slash');
            });

            // Clear error on input
            [username, password].forEach(input => {
                input?.addEventListener('input', function() {
                    this.classList.remove('input-error');
                    const err = this.closest('.input-group').querySelector('.error-message');
                    if (err) err.style.display = 'none';
                });
            });

            // Submit loading
            form?.addEventListener('submit', function() {
                btnText.style.display = 'none';
                spinner.style.display = 'block';
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<div class="loading-spinner" style="display:block;"></div>';

                setTimeout(() => {
                    btnText.style.display = 'inline';
                    spinner.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i><span id="buttonText">Masuk ke Sistem</span>';
                }, 5000);
            });
        });
    </script>
</body>
</html>
