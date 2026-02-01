<?php
/**
 * Login Page - Clean Banking Design
 * File: pages/login.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

if (!$project_root) {
    $project_root = $current_dir;
}

// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// DEFINE WEB BASE URL
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = preg_replace('#/pages$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}

define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// START SESSION & LOAD DB
// ============================================
session_start();
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// ============================================
// MOBILE DETECTION FUNCTION
// ============================================
function isMobileDevice()
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobileKeywords = array(
        'Mobile',
        'Android',
        'iPhone',
        'iPad',
        'iPod',
        'BlackBerry',
        'IEMobile',
        'Opera Mini',
        'Opera Mobi',
        'webOS',
        'Windows Phone',
        'Windows CE',
        'Symbian',
        'Samsung',
        'Nokia',
        'HTC',
        'LG',
        'Motorola',
        'Sony'
    );

    foreach ($mobileKeywords as $keyword) {
        if (stripos($userAgent, $keyword) !== false) {
            return true;
        }
    }

    if (preg_match('/(android|bb\\d+|meego).+mobile|avantgo|bada\\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $userAgent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\\-(n|u)|c55\\/|capi|ccwa|cdm\\-|cell|chtm|cldc|cmd\\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\\-s|devi|dica|dmob|do(c|p)o|ds(12|\\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\\-|_)|g1 u|g560|gene|gf\\-5|g\\-mo|go(\\.w|od)|gr(ad|un)|haie|hcit|hd\\-(m|p|t)|hei\\-|hi(pt|ta)|hp( i|ip)|hs\\-c|ht(c(\\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\\-(20|go|ma)|i230|iac( |\\-|\\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\\/)|klon|kpt |kwc\\-|kyo(c|k)|le(no|xi)|lg( g|\\/(k|l|u)|50|54|\\-[a-w])|libw|lynx|m1\\-w|m3ga|m50\\/|ma(te|ui|xo)|mc(01|21|ca)|m\\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\\-2|po(ck|rt|se)|prox|psio|pt\\-g|qa\\-a|qc(07|12|21|32|60|\\-[2-7]|i\\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\\-|oo|p\\-)|sdk\\/|se(c(\\-|0|1)|47|mc|nd|ri)|sgh\\-|shar|sie(\\-|m)|sk\\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\\-|v\\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\\-|tdg\\-|tel(i|m)|tim\\-|t\\-mo|to(pl|sh)|ts(70|m\\-|m3|m5)|tx\\-9|up(\\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\\-|your|zeto|zte\\-/i', substr($userAgent, 0, 4))) {
        return true;
    }
    return false;
}

// ============================================
// ANTI-CSRF TOKEN GENERATION
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// AJAX HANDLER - CHECK ROLE
// ============================================
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

// ============================================
// LOGIN HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['errors'] = ['general' => 'Invalid request. Silakan coba lagi.'];
        header("Location: login.php");
        exit();
    }

    $recaptcha_secret = '6LeATy0sAAAAAOtTMXrBrjxRSe0K1l98wIC0tW49';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

    if (empty($recaptcha_response)) {
        $_SESSION['errors'] = ['general' => 'Silakan verifikasi bahwa Anda bukan robot.'];
        $_SESSION['form_data'] = ['username' => $_POST['username'] ?? '', 'password' => ''];
        header("Location: login.php");
        exit();
    }

    $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
    $verify_data = [
        'secret' => $recaptcha_secret,
        'response' => $recaptcha_response,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($verify_data)
        ]
    ];

    $context = stream_context_create($options);
    $verify_response = file_get_contents($verify_url, false, $context);
    $captcha_result = json_decode($verify_response, true);

    if (!$captcha_result['success']) {
        $_SESSION['errors'] = ['general' => 'Verifikasi captcha gagal. Silakan coba lagi.'];
        $_SESSION['form_data'] = ['username' => $_POST['username'] ?? '', 'password' => ''];
        header("Location: login.php");
        exit();
    }

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $today = date('Y-m-d');

    $query = "SELECT u.id, u.username, u.password, u.role, u.nama, us.is_frozen, us.is_blocked 
              FROM users u 
              LEFT JOIN user_security us ON u.id = us.user_id
              WHERE u.username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if ($user['is_frozen'] || $user['is_blocked']) {
            $_SESSION['errors'] = ['general' => 'Akun Anda dibekukan atau diblokir. Hubungi administrator.'];
            $_SESSION['form_data'] = ['username' => $username, 'password' => ''];
            $stmt->close();
            header("Location: login.php");
            exit();
        }

        if (password_verify($password, $user['password'])) {
            if ($user['role'] === 'siswa' && !isMobileDevice()) {
                $_SESSION['errors'] = ['general' => 'Akun siswa hanya dapat login melalui perangkat mobile/HP.'];
                $_SESSION['form_data'] = ['username' => $username, 'password' => ''];
                $stmt->close();
                header("Location: login.php");
                exit();
            }

            if ($user['role'] === 'petugas') {
                $check_tugas_query = "SELECT COUNT(*) as tugas_count FROM petugas_shift
                                     WHERE (petugas1_id = ? OR petugas2_id = ?) AND tanggal = ?";
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

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['nama'] = $user['nama'];
            $_SESSION['username'] = $user['username'];

            // Remember me functionality
            if (isset($_POST['remember']) && $_POST['remember'] == 'on') {
                setcookie('remember_username', $username, time() + (30 * 24 * 60 * 60), '/'); // 30 days
            } else {
                setcookie('remember_username', '', time() - 3600, '/'); // Delete cookie
            }

            if ($user['role'] === 'petugas') {
                $schedule_query = "SELECT * FROM petugas_shift
                                  WHERE (petugas1_id = ? OR petugas2_id = ?) AND tanggal = ?";
                $schedule_stmt = $conn->prepare($schedule_query);
                $schedule_stmt->bind_param("iis", $user['id'], $user['id'], $today);
                $schedule_stmt->execute();
                $schedule_result = $schedule_stmt->get_result();

                if ($schedule_result->num_rows > 0) {
                    $schedule = $schedule_result->fetch_assoc();
                    $_SESSION['jadwal_id'] = $schedule['id'];
                }
                $schedule_stmt->close();
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $stmt->close();

            $redirect_url = match ($user['role']) {
                'admin' => BASE_URL . '/pages/admin/dashboard.php',
                'petugas' => BASE_URL . '/pages/petugas/dashboard.php',
                'siswa' => BASE_URL . '/pages/siswa/dashboard.php',
                default => BASE_URL . '/index.php'
            };

            header("Location: " . $redirect_url);
            exit();

        } else {
            $_SESSION['errors'] = ['password' => 'Kata sandi salah'];
            $_SESSION['form_data'] = ['username' => $username, 'password' => ''];
        }
    } else {
        $_SESSION['errors'] = ['username' => 'Username tidak ditemukan'];
        $_SESSION['form_data'] = ['username' => '', 'password' => ''];
    }

    $stmt->close();
    header("Location: login.php");
    exit();
}

$errors = isset($_SESSION['errors']) ? $_SESSION['errors'] : [];
$form_data = isset($_SESSION['form_data']) ? $_SESSION['form_data'] : ['username' => '', 'password' => ''];
unset($_SESSION['errors'], $_SESSION['form_data']);

// Check for remembered username
$remembered_username = isset($_COOKIE['remember_username']) ? $_COOKIE['remember_username'] : '';
if (empty($form_data['username']) && !empty($remembered_username)) {
    $form_data['username'] = $remembered_username;
}

// Check for logout/timeout message
$logout_message = isset($_SESSION['logout_message']) ? $_SESSION['logout_message'] : '';
unset($_SESSION['logout_message']);

// Check for timeout query parameter (alternative method)
if (empty($logout_message) && isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $logout_message = 'Sesi Anda telah berakhir karena tidak aktif. Silakan login kembali.';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <title>Masuk | KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background: #ffffff;
            overflow-x: hidden;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            padding-bottom: 100px; /* Space for fixed footer */
            position: relative;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 24px;
        }

        .logo-icon img {
            height: 150px;
            width: auto;
        }

        /* Header Title */
        .header-title {
            text-align: left;
            margin-bottom: 32px;
        }

        .header-title h1 {
            font-size: 1.2rem;
            font-weight: 400;
            color: #1a1a2e;
            line-height: 1.5;
        }

        .header-title h1 .login-text {
            font-weight: 400;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            color: #888;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 14px 40px 14px 0;
            border: none;
            border-bottom: 1.5px solid #e0e0e0;
            font-size: 1rem;
            color: #1a1a2e;
            background: transparent;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-bottom-color: #1a1a2e;
        }

        .form-input::placeholder {
            color: #c0c0c0;
        }

        .form-input.input-error {
            border-bottom-color: #e74c3c;
        }

        .input-icon {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            color: #3498db;
            font-size: 1rem;
        }

        .toggle-password {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8e8e8e;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
        }

        .toggle-password:hover {
            color: #1a1a2e;
        }

        .error-text {
            color: #e74c3c;
            font-size: 0.75rem;
            margin-top: 6px;
        }

        /* General Error */
        .general-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .general-error i {
            color: #e74c3c;
        }

        .general-error span {
            color: #991b1b;
            font-size: 0.85rem;
        }

        /* Remember & Forgot Row */
        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            margin-top: -5px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .remember-me input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #1a1a2e;
            cursor: pointer;
        }

        .remember-me span {
            font-size: 0.8rem;
            color: #8e8e8e;
        }

        .forgot-link {
            font-size: 0.8rem;
            color: #8e8e8e;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .forgot-link:hover {
            color: #1a1a2e;
        }

        /* Captcha */
        .captcha-container {
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
        }

        .captcha-container>div {
            transform: scale(0.92);
            transform-origin: center;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: #1a1a2e;
            color: #ffffff;
            border: none;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .submit-btn:hover {
            background: #2d2d4a;
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(26, 26, 46, 0.3);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 20px;
            }

            .login-container {
                padding: 20px;
            }

            .header-title {
                margin-top: -30px;
                margin-bottom: 45px;
            }

            .header-title h1 {
                font-size: 1.35rem;
            }

            .logo-section {
                margin-bottom: 20px;
            }

            .logo-icon img {
                height: 120px;
            }

            .captcha-container>div {
                transform: scale(0.85);
            }
        }

        /* Footer */
        .page-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            padding: 12px 20px;
            background: #ffffff;
        }

        .page-footer .footer-title {
            color: #1a1a2e;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .page-footer .footer-school {
            color: #888;
            font-size: 0.6rem;
            margin-bottom: 6px;
        }

        .page-footer .footer-links {
            font-size: 0.55rem;
        }

        .page-footer .footer-links a {
            color: #666;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .page-footer .footer-links a:hover {
            color: #1a1a2e;
        }

        .page-footer .footer-links .separator {
            color: #ccc;
            margin: 0 6px;
        }

        @media (max-width: 480px) {
            .page-footer {
                padding: 10px 15px;
            }

            .page-footer .footer-title {
                font-size: 0.65rem;
            }

            .page-footer .footer-school {
                font-size: 0.55rem;
            }

            .page-footer .footer-links {
                font-size: 0.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <div class="logo-icon">
                <img src="<?= ASSETS_URL ?>/images/header.png" alt="KASDIG" draggable="false">
            </div>
        </div>

        <!-- Logout/Timeout Message -->
        <?php if (!empty($logout_message)): ?>
            <p style="color: #64748b; font-size: 0.85rem; text-align: center; margin-bottom: 15px;">
                <i class="fas fa-clock" style="margin-right: 6px;"></i>Sesi berakhir. Silakan login kembali.
            </p>
        <?php endif; ?>

        <!-- General Error -->
        <?php if (isset($errors['general'])): ?>
            <div class="general-error" id="generalError">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" id="login-form">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <!-- Username -->
            <div class="form-group">
                <label class="form-label">Username</label>
                <div class="input-wrapper">
                    <input type="text" name="username" id="username"
                        class="form-input <?= isset($errors['username']) ? 'input-error' : '' ?>"
                        placeholder="Masukkan username" value="<?= htmlspecialchars($form_data['username']) ?>" required
                        autocomplete="username">
                    <i class="fas fa-check-circle input-icon"
                        style="<?= empty($form_data['username']) ? 'display:none' : '' ?>"></i>
                </div>
                <?php if (isset($errors['username'])): ?>
                    <div class="error-text"><?= htmlspecialchars($errors['username']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label class="form-label">Kata Sandi</label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password"
                        class="form-input <?= isset($errors['password']) ? 'input-error' : '' ?>"
                        placeholder="Masukkan kata sandi" required autocomplete="current-password">
                    <button type="button" class="toggle-password" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="error-text"><?= htmlspecialchars($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="options-row">
                <label class="remember-me">
                    <input type="checkbox" name="remember" <?= !empty($remembered_username) ? 'checked' : '' ?>>
                    <span>Ingat saya</span>
                </label>
                <a href="<?= BASE_URL ?>/pages/forgot_password.php" class="forgot-link">Lupa kata sandi?</a>
            </div>

            <!-- Google reCAPTCHA -->
            <div class="captcha-container">
                <div class="g-recaptcha" data-sitekey="6LeATy0sAAAAABYMMELwZu0mKYWgwWyevdLPfJhq" data-theme="light">
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="submit-btn" id="submitBtn">
                <span id="btnText">Masuk</span>
                <div class="loading-spinner" id="loadingSpinner"></div>
            </button>
        </form>

    </div>

    <!-- Footer -->
    <footer class="page-footer">
        <p class="footer-title">KASDIG</p>
        <p class="footer-school">SMK PLUS Ashabulyamin Cianjur</p>
        <p class="footer-links">
            <a href="<?= BASE_URL ?>/pages/syarat_ketentuan.php">Syarat & Ketentuan</a>
            <span class="separator">|</span>
            <a href="<?= BASE_URL ?>/pages/kebijakan_privasi.php">Kebijakan Privasi</a>
        </p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('login-form');
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const toggleBtn = document.getElementById('togglePassword');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('loadingSpinner');
            const usernameIcon = document.querySelector('.input-icon');

            // Auto-hide general error
            const generalError = document.getElementById('generalError');
            if (generalError) {
                setTimeout(() => {
                    generalError.style.opacity = '0';
                    generalError.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => generalError.remove(), 400);
                }, 5000);
            }

            // Toggle password visibility
            toggleBtn?.addEventListener('click', () => {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                toggleBtn.querySelector('i').classList.toggle('fa-eye');
                toggleBtn.querySelector('i').classList.toggle('fa-eye-slash');
            });

            // Show check icon when username has value
            username?.addEventListener('input', function () {
                this.classList.remove('input-error');
                if (usernameIcon) {
                    usernameIcon.style.display = this.value.length > 0 ? 'block' : 'none';
                }
                const err = this.parentElement.parentElement.querySelector('.error-text');
                if (err) err.style.display = 'none';
            });

            password?.addEventListener('input', function () {
                this.classList.remove('input-error');
                const err = this.parentElement.parentElement.querySelector('.error-text');
                if (err) err.style.display = 'none';
            });

            // Form submit with loading
            form?.addEventListener('submit', function () {
                btnText.textContent = 'Signing in...';
                spinner.style.display = 'block';
                submitBtn.disabled = true;

                setTimeout(() => {
                    btnText.textContent = 'Sign in';
                    spinner.style.display = 'none';
                    submitBtn.disabled = false;
                }, 10000);
            });
        });
    </script>
</body>

</html>