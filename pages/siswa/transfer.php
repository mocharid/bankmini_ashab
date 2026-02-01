<?php
/**
 * Transfer Pribadi Page - Adaptive Path Version (BCRYPT PIN + Normalized DB)
 * File: pages/siswa/transfer.php
 *
 * Compatible with:
 * - Local: localhost/schobank/pages/siswa/transfer.php
 * - Hosting: domain.com/pages/siswa/transfer.php
 */
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: Check if we're in 'pages/siswa' folder
if (basename(dirname($current_dir)) === 'pages' && basename($current_dir) === 'siswa') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: Check if includes/ exists in current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 3: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 4: Search upward for includes/ folder (max 5 levels)
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
// Fallback: Use current directory
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
// DEFINE WEB BASE URL (for browser access)
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    // Remove filename and get directory
    $base_path = dirname($script);
    // Remove '/pages/siswa' if exists
    $base_path = preg_replace('#/pages/siswa$#', '', $base_path);
    // Ensure base_path starts with /
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}
// Asset URLs for browser
define('ASSETS_URL', BASE_URL . '/assets');
// ============================================
// START SESSION & LOAD REQUIREMENTS
// ============================================
session_start();
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
date_default_timezone_set('Asia/Jakarta');
// ============================================
// CHECK SESSION & REDIRECT IF NOT LOGGED IN
// ============================================
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/pages/login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
// ============================================
// CHECK ACCOUNT STATUS FROM user_security
// ============================================
$query = "SELECT us.is_frozen, us.failed_pin_attempts, us.pin_block_until, us.pin
          FROM user_security us
          WHERE us.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

// Check if user_data is null or PIN is not set
if ($user_data === null || empty($user_data['pin'])) {
    $error = "Harap atur PIN terlebih dahulu di menu Profil. Transaksi Transfer membutuhkan PIN untuk verifikasi keamanan.";
    ?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <meta name="format-detection" content="telephone=no">
        <title>Transfer - KASDIG</title>
        <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/lbank.png">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
            rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            :root {
                --primary: #2c3e50;
                --primary-dark: #1a252f;
                --primary-light: #34495e;
                --secondary: #3498db;
                --success: #10b981;
                --warning: #f59e0b;
                --danger: #ef4444;
                --elegant-dark: #2c3e50;
                --elegant-gray: #434343;
                --gray-50: #f8fafc;
                --gray-100: #f1f5f9;
                --gray-200: #e2e8f0;
                --gray-300: #cbd5e1;
                --gray-400: #94a3b8;
                --gray-500: #64748b;
                --gray-600: #475569;
                --gray-700: #334155;
                --gray-800: #1e293b;
                --gray-900: #0f172a;
                --white: #ffffff;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
                --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
                --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
                --radius-sm: 8px;
                --radius: 12px;
                --radius-lg: 16px;
                --radius-xl: 20px;
                --radius-2xl: 24px;
                --radius-full: 9999px;
            }

            body {
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
                background: var(--gray-50);
                color: var(--gray-900);
                line-height: 1.6;
                padding-top: 0;
                padding-bottom: 100px;
                min-height: 100vh;
            }

            /* Header Dark Theme */
            .top-header {
                background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
                padding: 20px 20px 120px;
                position: relative;
                overflow: hidden;
                border-radius: 0 0 5px 5px;
            }

            .header-content {
                position: relative;
                z-index: 2;
            }

            .branding {
                color: var(--white);
                font-size: 18px;
                font-weight: 800;
                letter-spacing: 1px;
                margin-bottom: 24px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .page-title {
                font-size: 24px;
                font-weight: 700;
                color: var(--white);
                margin-bottom: 4px;
                letter-spacing: -0.5px;
            }

            .page-subtitle {
                font-size: 14px;
                color: rgba(255, 255, 255, 0.8);
                font-weight: 400;
            }

            /* Main Container */
            .main-container {
                padding: 0 20px;
                margin-top: -50px;
                position: relative;
                z-index: 10;
            }

            /* Error Card */
            .error-card {
                background: var(--white);
                border-radius: var(--radius-2xl);
                padding: 32px 24px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
                text-align: center;
                border: 1px solid rgba(0, 0, 0, 0.02);
            }

            .error-icon {
                color: var(--secondary);
                margin: 0 auto 20px;
                font-size: 64px;
                display: flex;
                justify-content: center;
            }

            .error-title {
                font-size: 18px;
                font-weight: 700;
                color: var(--gray-800);
                margin-bottom: 12px;
            }

            .error-text {
                font-size: 14px;
                color: var(--gray-600);
                margin-bottom: 30px;
                line-height: 1.6;
                max-width: 300px;
                margin-left: auto;
                margin-right: auto;
            }

            /* Buttons */
            .btn {
                padding: 14px 24px;
                border-radius: var(--radius-lg);
                font-size: 14px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-decoration: none;
                width: 100%;
            }

            .btn-primary {
                background: var(--primary);
                color: var(--white);
                box-shadow: 0 4px 12px rgba(44, 62, 80, 0.2);
            }

            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-2px);
            }

            .btn-secondary {
                background: var(--gray-100);
                color: var(--gray-700);
            }

            .btn-secondary:hover {
                background: var(--gray-200);
                transform: translateY(-2px);
            }

            .btn-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
        </style>
    </head>

    <body>
        <header class="top-header">
            <div class="header-content">
                <div>
                    <h1 class="page-title">Transfer</h1>
                    <p class="page-subtitle">PIN Belum Diatur</p>
                </div>
            </div>
        </header>

        <div class="main-container">
            <div class="error-card">
                <div class="error-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 class="error-title">PIN Belum Diatur</h2>
                <p class="error-text"><?= htmlspecialchars($error); ?></p>

                <div class=" btn-actions">
                    <a href="profil.php?open_pin=true" class="btn btn-primary">
                        Atur PIN
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        Kembali
                    </a>
                </div>
            </div>
        </div>

        <?php include 'bottom_navbar.php'; ?>
    </body>

    </html>
    <?php
    exit();
}

if ($user_data['is_frozen']) {
    $error = "Akun Anda sedang dinonaktifkan. Anda tidak dapat melakukan transfer. Silakan hubungi admin untuk bantuan!";
    // Render error page
    ?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <meta name="format-detection" content="telephone=no">
        <title>Transfer - KASDIG</title>
        <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/lbank.png">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
            rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            :root {
                --primary: #2c3e50;
                --primary-dark: #1a252f;
                --primary-light: #34495e;
                --secondary: #3498db;
                --success: #10b981;
                --warning: #f59e0b;
                --danger: #ef4444;
                --elegant-dark: #2c3e50;
                --elegant-gray: #434343;
                --gray-50: #f8fafc;
                --gray-100: #f1f5f9;
                --gray-200: #e2e8f0;
                --gray-300: #cbd5e1;
                --gray-400: #94a3b8;
                --gray-500: #64748b;
                --gray-600: #475569;
                --gray-700: #334155;
                --gray-800: #1e293b;
                --gray-900: #0f172a;
                --white: #ffffff;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
                --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
                --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
                --radius-sm: 8px;
                --radius: 12px;
                --radius-lg: 16px;
                --radius-xl: 20px;
                --radius-2xl: 24px;
                --radius-full: 9999px;
            }

            body {
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
                background: var(--gray-50);
                color: var(--gray-900);
                line-height: 1.6;
                padding-top: 0;
                padding-bottom: 100px;
                min-height: 100vh;
            }

            /* Header Dark Theme */
            .top-header {
                background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
                padding: 20px 20px 120px;
                position: relative;
                overflow: hidden;
                border-radius: 0 0 5px 5px;
            }

            .header-content {
                position: relative;
                z-index: 2;
            }

            .branding {
                color: var(--white);
                font-size: 18px;
                font-weight: 800;
                letter-spacing: 1px;
                margin-bottom: 24px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .page-title {
                font-size: 24px;
                font-weight: 700;
                color: var(--white);
                margin-bottom: 4px;
                letter-spacing: -0.5px;
            }

            .page-subtitle {
                font-size: 14px;
                color: rgba(255, 255, 255, 0.8);
                font-weight: 400;
            }

            /* Main Container */
            .main-container {
                padding: 0 20px;
                margin-top: -50px;
                position: relative;
                z-index: 10;
            }

            /* Error Card */
            .error-card {
                background: var(--white);
                border-radius: var(--radius-2xl);
                padding: 32px 24px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
                text-align: center;
                border: 1px solid rgba(0, 0, 0, 0.02);
            }

            .error-icon {
                color: var(--secondary);
                margin: 0 auto 20px;
                font-size: 64px;
                display: flex;
                justify-content: center;
            }

            .error-title {
                font-size: 18px;
                font-weight: 700;
                color: var(--gray-800);
                margin-bottom: 12px;
            }

            .error-text {
                font-size: 14px;
                color: var(--gray-600);
                margin-bottom: 30px;
                line-height: 1.6;
                max-width: 300px;
                margin-left: auto;
                margin-right: auto;
            }

            /* Buttons */
            .btn {
                padding: 14px 24px;
                border-radius: var(--radius-lg);
                font-size: 14px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-decoration: none;
                width: 100%;
            }

            .btn-primary {
                background: var(--primary);
                color: var(--white);
                box-shadow: 0 4px 12px rgba(44, 62, 80, 0.2);
            }

            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-2px);
            }

            .btn-secondary {
                background: var(--gray-100);
                color: var(--gray-700);
            }

            .btn-secondary:hover {
                background: var(--gray-200);
                transform: translateY(-2px);
            }

            .btn-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
        </style>
    </head>

    <body>
        <header class="top-header">
            <div class="header-content">
                <div>
                    <h1 class="page-title">Transfer</h1>
                    <p class="page-subtitle">Akun Dinonaktifkan</p>
                </div>
            </div>
        </header>

        <div class="main-container">
            <div class="error-card">
                <div class="error-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 class="error-title">Akun Dinonaktifkan</h2>
                <p class="error-text"><?= htmlspecialchars($error); ?></p>

                <div class=" btn-actions">
                    <button class="btn btn-primary"
                        onclick="Swal.fire({title: 'Hubungi Admin', text: 'Silakan hubungi administrator melalui email, telepon, atau datang langsung ke kantor untuk mendapatkan bantuan mengaktifkan kembali akun Anda.', icon: 'info', confirmButtonText: 'OK'})">
                        <i class="fas fa-headset"></i> Hubungi Admin
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        Kembali
                    </a>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <?php include 'bottom_navbar.php'; ?>
    </body>

    </html>
    <?php

    exit();
}
// Check if account is blocked due to PIN attempts
$current_time = date('Y-m-d H:i:s');
if ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] > $current_time) {
    $block_until = date('d/m/Y H:i:s', strtotime($user_data['pin_block_until']));
    $error = "Akun Anda diblokir karena terlalu banyak PIN salah. Coba lagi setelah $block_until.";
    // Render block error page
    ?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <meta name="format-detection" content="telephone=no">
        <title>Transfer - KASDIG</title>
        <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/lbank.png">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
            rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            :root {
                --primary: #2c3e50;
                --primary-dark: #1a252f;
                --primary-light: #34495e;
                --secondary: #3498db;
                --success: #10b981;
                --warning: #f59e0b;
                --danger: #ef4444;
                --elegant-dark: #2c3e50;
                --elegant-gray: #434343;
                --gray-50: #f8fafc;
                --gray-100: #f1f5f9;
                --gray-200: #e2e8f0;
                --gray-300: #cbd5e1;
                --gray-400: #94a3b8;
                --gray-500: #64748b;
                --gray-600: #475569;
                --gray-700: #334155;
                --gray-800: #1e293b;
                --gray-900: #0f172a;
                --white: #ffffff;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
                --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
                --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
                --radius-sm: 8px;
                --radius: 12px;
                --radius-lg: 16px;
                --radius-xl: 20px;
                --radius-2xl: 24px;
                --radius-full: 9999px;
            }

            body {
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
                background: var(--gray-50);
                color: var(--gray-900);
                line-height: 1.6;
                padding-top: 0;
                padding-bottom: 100px;
                min-height: 100vh;
            }

            /* Header Dark Theme */
            .top-header {
                background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
                padding: 20px 20px 120px;
                position: relative;
                overflow: hidden;
                border-radius: 0 0 5px 5px;
            }

            .header-content {
                position: relative;
                z-index: 2;
            }

            .branding {
                color: var(--white);
                font-size: 18px;
                font-weight: 800;
                letter-spacing: 1px;
                margin-bottom: 24px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .page-title {
                font-size: 24px;
                font-weight: 700;
                color: var(--white);
                margin-bottom: 4px;
                letter-spacing: -0.5px;
            }

            .page-subtitle {
                font-size: 14px;
                color: rgba(255, 255, 255, 0.8);
                font-weight: 400;
            }

            /* Main Container */
            .main-container {
                padding: 0 20px;
                margin-top: -50px;
                position: relative;
                z-index: 10;
            }

            /* Error Card */
            .error-card {
                background: var(--white);
                border-radius: var(--radius-2xl);
                padding: 32px 24px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
                text-align: center;
                border: 1px solid rgba(0, 0, 0, 0.02);
            }

            .error-icon {
                color: var(--secondary);
                margin: 0 auto 20px;
                font-size: 64px;
                display: flex;
                justify-content: center;
            }

            .error-title {
                font-size: 18px;
                font-weight: 700;
                color: var(--gray-800);
                margin-bottom: 12px;
            }

            .error-text {
                font-size: 14px;
                color: var(--gray-600);
                margin-bottom: 30px;
                line-height: 1.6;
                max-width: 300px;
                margin-left: auto;
                margin-right: auto;
            }

            /* Buttons */
            .btn {
                padding: 14px 24px;
                border-radius: var(--radius-lg);
                font-size: 14px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-decoration: none;
                width: 100%;
            }

            .btn-primary {
                background: var(--primary);
                color: var(--white);
                box-shadow: 0 4px 12px rgba(44, 62, 80, 0.2);
            }

            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-2px);
            }

            .btn-secondary {
                background: var(--gray-100);
                color: var(--gray-700);
            }

            .btn-secondary:hover {
                background: var(--gray-200);
                transform: translateY(-2px);
            }

            .btn-actions {
                display: grid;
                grid-template-columns: 1fr;
                gap: 12px;
            }
        </style>
    </head>

    <body>
        <header class="top-header">
            <div class="header-content">
                <div>
                    <h1 class="page-title">Transfer</h1>
                    <p class="page-subtitle">Akun Diblokir Sementara</p>
                </div>
            </div>
        </header>

        <div class="main-container">
            <div class="error-card">
                <div class="error-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 class="error-title">Akunmu Diblokir Sementara</h2>
                <p class="error-text"><?= htmlspecialchars($error); ?></p>

                <div class="btn-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        Kembali
                    </a>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <?php include 'bottom_navbar.php'; ?>
    </body>

    </html>
    <?php
    exit();

}
// Reset failed attempts if block has expired
if ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] <= $current_time) {
    $query = "UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    $user_data['failed_pin_attempts'] = 0;
    $user_data['pin_block_until'] = null;
}
// ============================================
// HELPER FUNCTIONS
// ============================================
function getIndonesianMonth($date)
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
    return "$day $month $year";
}
// Function to send email with style similar to proses_setor
function sendTransferEmail($mail, $email, $nama, $no_rekening, $id_transaksi, $no_transaksi, $jumlah, $data_pihak_lain, $keterangan, $is_sender = true)
{
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearReplyTos();

        $mail->isSMTP();
        $mail->Host = 'mail.kasdig.web.id';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@kasdig.web.id';
        $mail->Password = 'BtRjT4wP8qeTL5M';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->Timeout = 30;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('noreply@kasdig.web.id', 'KASDIG');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('noreply@kasdig.web.id', 'KASDIG');

        $unique_id = uniqid('kasdig_', true) . '@kasdig.web.id';
        $mail->MessageID = '<' . $unique_id . '>';

        $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
        $mail->addCustomHeader('X-Reference-Number', $no_transaksi);

        $bulan = [
            'Jan' => 'Januari',
            'Feb' => 'Februari',
            'Mar' => 'Maret',
            'Apr' => 'April',
            'May' => 'Mei',
            'Jun' => 'Juni',
            'Jul' => 'Juli',
            'Aug' => 'Agustus',
            'Sep' => 'September',
            'Oct' => 'Oktober',
            'Nov' => 'November',
            'Dec' => 'Desember'
        ];
        $tanggal_transaksi = date('d M Y, H:i');
        foreach ($bulan as $en => $id_bulan) {
            $tanggal_transaksi = str_replace($en, $id_bulan, $tanggal_transaksi);
        }
        $jurusan = $data_pihak_lain['jurusan'] ?? '-';
        $kelas = $data_pihak_lain['kelas'] ?? '-';
        $nama_pihak_lain = $data_pihak_lain['nama'] ?? '-';
        $no_rek_pihak_lain = $data_pihak_lain['no_rekening'] ?? '-';
        if ($is_sender) {
            $subject = "Bukti Transfer {$no_transaksi}";
            $greeting_text = "Kami informasikan bahwa transaksi transfer dari rekening Anda telah berhasil diproses. Berikut rincian transaksi:";
            $keterangan_text = !empty($keterangan) ? "
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>" . htmlspecialchars($keterangan) . "</p>
            </div>" : "";
            $transaction_details = "
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>ID Transaksi</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$id_transaksi}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Referensi</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$no_transaksi}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Pengirim</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$no_rekening}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nama Pengirim</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$no_rek_pihak_lain}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nama Penerima</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama_pihak_lain}</p>
            </div>
            {$keterangan_text}
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Jumlah Transfer</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp " . number_format($jumlah, 0, ',', '.') . "</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal Transaksi</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$tanggal_transaksi} WIB</p>
            </div>";

        } else {
            $subject = "Pemberitahuan Transfer Masuk {$no_transaksi}";
            $greeting_text = "Kami informasikan bahwa rekening Anda telah menerima transfer dana. Berikut rincian transaksi:";

            $keterangan_text = !empty($keterangan) ? "
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>" . htmlspecialchars($keterangan) . "</p>
            </div>" : "";
            $transaction_details = "
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>ID Transaksi</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$id_transaksi}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Referensi</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$no_transaksi}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Pengirim</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$no_rek_pihak_lain}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nama Pengirim</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama_pihak_lain}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Penerima</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$no_rekening}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nama Penerima</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Jurusan</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$jurusan}</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Kelas</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$kelas}</p>
            </div>
            {$keterangan_text}
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Jumlah Diterima</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp " . number_format($jumlah, 0, ',', '.') . "</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal Transaksi</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$tanggal_transaksi} WIB</p>
            </div>";
        }
        $message_email = "
        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
            <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>{$subject}</h2>
            <p>Halo <strong>{$nama}</strong>,</p>
            <p>{$greeting_text}</p>
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
            {$transaction_details}
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
            <p style='font-size: 12px; color: #999;'>
                Ini adalah pesan otomatis dari sistem KASDIG.<br>
                Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
            </p>
        </div>";
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message_email;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</div>', '</p>'], ["\n", "\n", "\n"], $message_email));
        if ($mail->send()) {
            $mail->smtpClose();
            return true;
        } else {
            throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
        }

    } catch (Exception $e) {
        error_log("Mail error for transaction {$id_transaksi}: " . $e->getMessage());
        return false;
    }
}
// Fetch account details
$query = "SELECT r.id, r.saldo, r.no_rekening
          FROM rekening r
          WHERE r.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$rekening_data = $result->fetch_assoc();
if ($rekening_data) {
    $saldo = $rekening_data['saldo'];
    $no_rekening = $rekening_data['no_rekening'];
    $rekening_id = $rekening_data['id'];
} else {
    $saldo = 0;
    $no_rekening = 'N/A';
    $rekening_id = null;
}
// Check daily transfer limit
$today = date('Y-m-d');
$query = "SELECT SUM(jumlah) as total_transfer
          FROM transaksi
          WHERE rekening_id = ?
          AND jenis_transaksi = 'transfer'
          AND DATE(created_at) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("is", $rekening_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$transfer_data = $result->fetch_assoc();
$daily_transfer_total = $transfer_data['total_transfer'] ?? 0;
$daily_limit = 500000;
$remaining_limit = $daily_limit - $daily_transfer_total;
// Initialize session variables
// Reset transfer-related session variables on GET requests (page load/refresh/return)
function resetTransferSession()
{
    unset($_SESSION['current_step']);
    unset($_SESSION['sub_step']);
    unset($_SESSION['tujuan_data']);
    unset($_SESSION['transfer_amount']);
    unset($_SESSION['keterangan']);
    unset($_SESSION['keterangan_temp']);
    unset($_SESSION['no_transaksi']);
    unset($_SESSION['transfer_rekening']);
    unset($_SESSION['transfer_name']);
    unset($_SESSION['show_popup']);
    unset($_SESSION['transfer_amount_temp']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resetTransferSession();
    // Reset failed PIN attempts when page loads (user navigated away and came back)
    $query_reset = "UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?";
    $stmt_reset = $conn->prepare($query_reset);
    $stmt_reset->bind_param("i", $user_id);
    $stmt_reset->execute();
    $stmt_reset->close();
    // Regenerate form token on page load
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$current_step = isset($_SESSION['current_step']) ? $_SESSION['current_step'] : 1;
$sub_step = isset($_SESSION['sub_step']) ? $_SESSION['sub_step'] : 1;
$tujuan_data = isset($_SESSION['tujuan_data']) ? $_SESSION['tujuan_data'] : null;
$input_jumlah = "";
$input_keterangan = "";
$transfer_amount = null;
$transfer_rekening = null;
$transfer_name = null;
$no_transaksi = null;
$error = null;
$success = null;
// Initialize session token if not set
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
// Reset session only if explicitly canceled
if (!isset($_SESSION['show_popup']) || $_SESSION['show_popup'] === false) {
    if ($current_step == 5) {
        $current_step = 1;
        $_SESSION['current_step'] = 1;
        $sub_step = 1;
        $_SESSION['sub_step'] = 1;
    }
}
function resetSessionAndPinAttempts($conn, $user_id)
{
    $query = "UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    resetTransferSession();
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['form_token']) && $_POST['form_token'] === $_SESSION['form_token']) {
    $action = $_POST['action'];
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
    switch ($action) {
        case 'verify_account':
            $rekening_tujuan = trim($_POST['rekening_tujuan']);

            if (empty($rekening_tujuan)) {
                $error = "Nomor rekening tujuan harus diisi!";
            } elseif ($rekening_tujuan == $no_rekening) {
                $error = "Tidak dapat transfer ke rekening sendiri!";
            } elseif (!preg_match('/^[0-9]{8}$/', $rekening_tujuan)) {
                $error = "Nomor rekening harus berupa 8 digit angka!";
            } else {
                $query = "SELECT r.id, r.user_id, r.no_rekening, u.nama, u.email, us.is_frozen, sp.jurusan_id, sp.kelas_id,
                          j.nama_jurusan, k.nama_kelas, tk.nama_tingkatan
                          FROM rekening r
                          JOIN users u ON r.user_id = u.id
                          JOIN user_security us ON u.id = us.user_id
                          LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
                          LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                          LEFT JOIN kelas k ON sp.kelas_id = k.id
                          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                          WHERE r.no_rekening = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $rekening_tujuan);
                $stmt->execute();
                $result = $stmt->get_result();
                $tujuan_data = $result->fetch_assoc();

                if (!$tujuan_data) {
                    $error = "Nomor rekening tujuan tidak ditemukan!";
                } elseif ($tujuan_data['is_frozen']) {
                    $error = "Rekening tujuan sedang dibekukan dan tidak dapat menerima transfer!";
                } else {
                    $_SESSION['tujuan_data'] = $tujuan_data;
                    $_SESSION['current_step'] = 2;
                    $current_step = 2;
                    $sub_step = 1;
                }
            }
            break;

        case 'input_amount':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data'])) {
                $error = "Data rekening tujuan hilang. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
            $_SESSION['current_step'] = 3;
            $current_step = 3;
            $sub_step = 1;
            $_SESSION['sub_step'] = 1;
            break;

        case 'check_amount':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data'])) {
                $error = "Data rekening tujuan hilang. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            $jumlah = floatval(str_replace(',', '.', str_replace('.', '', $_POST['jumlah'])));
            $input_jumlah = $_POST['jumlah'];
            $input_keterangan = trim($_POST['keterangan'] ?? '');

            if ($jumlah <= 0) {
                $error = "Jumlah transfer harus lebih dari 0!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } elseif ($jumlah < 1) {
                $error = "Jumlah transfer minimal Rp 1!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } elseif ($jumlah > $saldo) {
                $error = "Saldo tidak mencukupi!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } elseif ($jumlah > $remaining_limit) {
                $error = "Melebihi batas transfer harian Rp 500.000!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } else {
                $_SESSION['transfer_amount_temp'] = $jumlah;
                $_SESSION['keterangan_temp'] = $input_keterangan;
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
            }
            break;

        case 'confirm_pin':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data']) || !isset($_SESSION['transfer_amount_temp'])) {
                $error = "Data transaksi tidak lengkap. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            $pin = trim($_POST['pin']);
            if (empty($pin)) {
                $error = "PIN harus diisi!";
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
                break;
            }

            $query = "SELECT u.email, u.nama, us.pin, us.failed_pin_attempts, us.pin_block_until
                      FROM users u
                      JOIN user_security us ON u.id = us.user_id
                      WHERE u.id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data_pin = $result->fetch_assoc();

            if (!$user_data_pin) {
                $error = "Pengguna tidak ditemukan!";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            if ($user_data_pin['pin_block_until'] !== null && $user_data_pin['pin_block_until'] > date('Y-m-d H:i:s')) {
                $block_until = date('d/m/Y H:i:s', strtotime($user_data_pin['pin_block_until']));
                $error = "Akun Anda diblokir karena terlalu banyak PIN salah. Coba lagi setelah $block_until.";
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
                break;
            }

            if (!password_verify($pin, $user_data_pin['pin'])) {
                $new_attempts = $user_data_pin['failed_pin_attempts'] + 1;
                if ($new_attempts >= 5) {
                    $block_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $query = "UPDATE user_security SET failed_pin_attempts = ?, pin_block_until = ? WHERE user_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("isi", $new_attempts, $block_until, $user_id);
                    $stmt->execute();
                    $error = "PIN salah! Akun Anda diblokir selama 1 jam karena 5 kali PIN salah.";
                } else {
                    $query = "UPDATE user_security SET failed_pin_attempts = ? WHERE user_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("ii", $new_attempts, $user_id);
                    $stmt->execute();
                    $error = "PIN yang Anda masukkan salah! Percobaan tersisa: " . (5 - $new_attempts);
                }
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
                break;
            }

            $query = "UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();

            $_SESSION['transfer_amount'] = $_SESSION['transfer_amount_temp'];
            $_SESSION['keterangan'] = $_SESSION['keterangan_temp'] ?? '';
            unset($_SESSION['transfer_amount_temp'], $_SESSION['keterangan_temp']);
            $_SESSION['current_step'] = 4;
            $current_step = 4;
            $_SESSION['sub_step'] = 1;
            break;

        case 'process_transfer':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data']) || !isset($_SESSION['transfer_amount'])) {
                $error = "Data transaksi tidak lengkap. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            $query = "SELECT email, nama FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            $stmt->close();

            if (!$user_data) {
                $error = "Data pengguna tidak ditemukan!";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            $tujuan_data = $_SESSION['tujuan_data'];
            $rekening_tujuan_id = $tujuan_data['id'];
            $rekening_tujuan = $tujuan_data['no_rekening'];
            $rekening_tujuan_nama = $tujuan_data['nama'];
            $rekening_tujuan_user_id = $tujuan_data['user_id'];
            $rekening_tujuan_email = $tujuan_data['email'] ?? '';
            $jumlah = floatval($_SESSION['transfer_amount']);
            $keterangan_transfer = $_SESSION['keterangan'] ?? '';

            if (empty($rekening_tujuan_user_id)) {
                $error = "ID pengguna tujuan tidak valid!";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            $query_saldo_tujuan = "SELECT saldo FROM rekening WHERE id = ?";
            $stmt_saldo_tujuan = $conn->prepare($query_saldo_tujuan);
            $stmt_saldo_tujuan->bind_param("i", $rekening_tujuan_id);
            $stmt_saldo_tujuan->execute();
            $result_saldo_tujuan = $stmt_saldo_tujuan->get_result();
            $saldo_tujuan_data = $result_saldo_tujuan->fetch_assoc();
            $saldo_tujuan_awal = $saldo_tujuan_data['saldo'] ?? 0;
            $stmt_saldo_tujuan->close();

            $saldo_akhir_penerima = $saldo_tujuan_awal + $jumlah;
            $saldo_akhir_pengirim = $saldo - $jumlah;

            try {
                $conn->begin_transaction();

                do {
                    $date_prefix = date('ymd');
                    $random_8digit = sprintf('%08d', mt_rand(10000000, 99999999));
                    $id_transaksi = $date_prefix . $random_8digit;

                    $check_id_query = "SELECT id FROM transaksi WHERE id_transaksi = ?";
                    $check_id_stmt = $conn->prepare($check_id_query);
                    $check_id_stmt->bind_param('s', $id_transaksi);
                    $check_id_stmt->execute();
                    $check_id_result = $check_id_stmt->get_result();
                    $id_exists = $check_id_result->num_rows > 0;
                    $check_id_stmt->close();
                } while ($id_exists);

                do {
                    $date_prefix = date('ymd');
                    $random_6digit = sprintf('%06d', mt_rand(100000, 999999));
                    $no_transaksi = 'TRXTFM' . $date_prefix . $random_6digit;

                    $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param('s', $no_transaksi);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $exists = $check_result->num_rows > 0;
                    $check_stmt->close();
                } while ($exists);

                $query = "INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, jenis_transaksi, jumlah, rekening_tujuan_id, keterangan, status, created_at)
                          VALUES (?, ?, ?, 'transfer', ?, ?, ?, 'approved', NOW())";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ssidis", $id_transaksi, $no_transaksi, $rekening_id, $jumlah, $rekening_tujuan_id, $keterangan_transfer);
                $stmt->execute();
                $transaksi_id = $stmt->insert_id;
                $stmt->close();

                $query = "UPDATE rekening SET saldo = saldo - ? WHERE id = ? AND saldo >= ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("did", $jumlah, $rekening_id, $jumlah);
                $stmt->execute();
                if ($stmt->affected_rows == 0) {
                    throw new Exception("Saldo tidak mencukupi atau rekening tidak valid!");
                }
                $stmt->close();

                $query = "UPDATE rekening SET saldo = saldo + ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("di", $jumlah, $rekening_tujuan_id);
                $stmt->execute();
                if ($stmt->affected_rows == 0) {
                    throw new Exception("Gagal memperbarui saldo penerima!");
                }
                $stmt->close();

                $query_mutasi_pengirim = "INSERT INTO mutasi (rekening_id, transaksi_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mutasi_pengirim = $conn->prepare($query_mutasi_pengirim);
                $stmt_mutasi_pengirim->bind_param("iidd", $rekening_id, $transaksi_id, $jumlah, $saldo_akhir_pengirim);
                $stmt_mutasi_pengirim->execute();
                $stmt_mutasi_pengirim->close();

                $query_mutasi_penerima = "INSERT INTO mutasi (rekening_id, transaksi_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mutasi_penerima = $conn->prepare($query_mutasi_penerima);
                $stmt_mutasi_penerima->bind_param("iidd", $rekening_tujuan_id, $transaksi_id, $jumlah, $saldo_akhir_penerima);
                $stmt_mutasi_penerima->execute();
                $stmt_mutasi_penerima->close();

                $message_pengirim = "Yey, kamu berhasil transfer Rp " . number_format($jumlah, 0, '.', '.') . " ke $rekening_tujuan_nama! Cek riwayat transaksimu sekarang!";
                $query_notifikasi_pengirim = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
                $stmt_notifikasi_pengirim = $conn->prepare($query_notifikasi_pengirim);
                $stmt_notifikasi_pengirim->bind_param("is", $user_id, $message_pengirim);
                $stmt_notifikasi_pengirim->execute();
                $stmt_notifikasi_pengirim->close();

                $message_penerima = "Yey, kamu menerima transfer Rp " . number_format($jumlah, 0, '.', '.') . " dari $user_data[nama]! Cek saldo mu sekarang!";
                $query_notifikasi_penerima = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
                $stmt_notifikasi_penerima = $conn->prepare($query_notifikasi_penerima);
                $stmt_notifikasi_penerima->bind_param("is", $rekening_tujuan_user_id, $message_penerima);
                $stmt_notifikasi_penerima->execute();
                $stmt_notifikasi_penerima->close();

                $conn->commit();

                $mail = new PHPMailer(true);

                $jurusan_tujuan = $tujuan_data['nama_jurusan'] ?? '-';
                $kelas_tujuan = ($tujuan_data['nama_tingkatan'] ?? '') . ' ' . ($tujuan_data['nama_kelas'] ?? '');

                $data_penerima = [
                    'nama' => $rekening_tujuan_nama,
                    'no_rekening' => $rekening_tujuan,
                    'jurusan' => $jurusan_tujuan,
                    'kelas' => trim($kelas_tujuan)
                ];

                $data_pengirim = [
                    'nama' => $user_data['nama'],
                    'no_rekening' => $no_rekening,
                    'jurusan' => '-',
                    'kelas' => '-'
                ];

                try {
                    sendTransferEmail($mail, $user_data['email'] ?? '', $user_data['nama'], $no_rekening, $id_transaksi, $no_transaksi, $jumlah, $data_penerima, $keterangan_transfer, true);
                } catch (Exception $e) {
                    error_log("Failed to send email to sender: " . $e->getMessage());
                }

                try {
                    sendTransferEmail($mail, $rekening_tujuan_email, $rekening_tujuan_nama, $rekening_tujuan, $id_transaksi, $no_transaksi, $jumlah, $data_pengirim, $keterangan_transfer, false);
                } catch (Exception $e) {
                    error_log("Failed to send email to recipient: " . $e->getMessage());
                }

                $_SESSION['no_transaksi'] = $no_transaksi;
                $_SESSION['transfer_amount'] = $jumlah;
                $_SESSION['transfer_rekening'] = $rekening_tujuan;
                $_SESSION['transfer_name'] = $rekening_tujuan_nama;
                $_SESSION['show_popup'] = true;

                unset($_SESSION['tujuan_data']);
                unset($_SESSION['keterangan']);

                $_SESSION['current_step'] = 1;
                $_SESSION['sub_step'] = 1;

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Gagal memproses transfer: " . $e->getMessage();
                $_SESSION['current_step'] = 4;
                $current_step = 4;
            }
            break;

        case 'cancel':
            resetSessionAndPinAttempts($conn, $user_id);
            header("Location: transfer.php");
            exit();

        case 'cancel_to_dashboard':
            resetSessionAndPinAttempts($conn, $user_id);
            header("Location: dashboard.php");
            exit();
    }
}
function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, '.', '.');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#2c3e50">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Transfer - KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/lbank.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Original Dark/Elegant Theme - Matching Dashboard */
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;

            /* Neutral Colors */
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --white: #ffffff;

            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);

            /* Border Radius */
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
            --radius-full: 9999px;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 0;
            padding-bottom: 100px;
            min-height: 100vh;
        }

        /* ============================================ */
        /* HEADER / TOP NAVIGATION - DARK THEME */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
            padding: 20px 20px 120px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 5px 5px;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-info {
            color: var(--white);
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .page-subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            border: none;
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            backdrop-filter: blur(10px);
            text-decoration: none;
        }

        .header-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .header-btn:active {
            transform: scale(0.95);
        }

        .header-btn i {
            font-size: 18px;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        /* Legacy container support */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }

        /* Page Header - for non-header pages */
        .page-header {
            margin-bottom: 32px;
        }

        .page-header .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .page-header .page-subtitle {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 400;
        }

        /* Step Indicator - Inside Header (White Theme) */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-top: 24px;
            gap: 8px;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0 10px;
            flex: 1;
            max-width: 100px;
        }

        .step-number {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            color: rgba(255, 255, 255, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 6px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .step-item.active .step-number {
            background: var(--white);
            color: var(--primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            border: none;
        }

        .step-item.completed .step-number {
            background: var(--success);
            color: var(--white);
            border: none;
        }

        .step-text {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.7);
            text-align: center;
            font-weight: 500;
        }

        .step-item.active .step-text {
            color: var(--white);
            font-weight: 600;
        }

        .step-item.completed .step-text {
            color: rgba(255, 255, 255, 0.9);
        }

        /* Transfer Card - Clean White Theme */
        .transfer-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            color: var(--gray-900);
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .transfer-card::before {
            display: none;
        }

        .transfer-card::after {
            display: none;
        }

        .transfer-card>* {
            position: relative;
            z-index: 2;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 24px;
            text-align: center;
        }

        /* Form Elements - White Card Theme */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
            text-align: center;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 16px;
            transition: all 0.2s ease;
            background: var(--gray-50);
            color: var(--gray-900);
        }

        .form-control::placeholder {
            color: var(--gray-400);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--gray-400);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* PIN Input - White Theme */
        .pin-container {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin: 24px 0;
        }

        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            transition: all 0.2s ease;
            background: var(--gray-50);
            color: var(--gray-900);
        }

        .pin-input:focus {
            outline: none;
            border-color: var(--gray-500);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }

        /* Detail Row - White Theme */
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 500;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
            text-align: right;
        }

        /* Buttons - White Card Theme */
        .btn {
            padding: 14px 24px;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #555555 0%, #1a1a1a 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .btn-danger {
            background: transparent;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
        }

        .btn-danger:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
            border-color: var(--gray-400);
            color: var(--gray-700);
        }

        /* Button Actions - 2 Columns for Multiple Buttons */
        .btn-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }

        /* Single Button - Full Width Center */
        .btn-actions-single {
            display: flex;
            justify-content: center;
            margin-top: 24px;
        }

        .btn-actions-single .btn {
            min-width: 200px;
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-top: none;
            padding: 12px 20px 20px;
            display: flex;
            justify-content: space-around;
            z-index: 100;
            transition: transform 0.3s ease, opacity 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }

        .bottom-nav.hidden {
            transform: translateY(100%);
            opacity: 0;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            color: var(--gray-400);
            transition: all 0.2s ease;
            padding: 8px 16px;
        }

        .nav-item i {
            font-size: 22px;
            transition: color 0.2s ease;
        }

        .nav-item span {
            font-size: 11px;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .nav-item:hover i,
        .nav-item:hover span,
        .nav-item.active i,
        .nav-item.active span {
            color: var(--elegant-dark);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-container {
                padding: 0 16px;
            }

            .top-header {
                padding: 16px 16px 70px;
            }

            .page-title {
                font-size: 20px;
            }

            .page-subtitle {
                font-size: 12px;
            }

            .header-btn {
                width: 40px;
                height: 40px;
            }

            .transfer-card {
                padding: 20px 16px;
            }

            .step-indicator {
                gap: 4px;
                margin-bottom: 20px;
            }

            .step-item {
                padding: 0 6px;
            }

            .step-number {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }

            .step-text {
                font-size: 9px;
            }

            .pin-container {
                gap: 8px;
            }

            .pin-input {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }

            .detail-row {
                flex-direction: column;
                gap: 4px;
            }

            .detail-value {
                text-align: left;
            }
        }

        @media (max-width: 480px) {
            .top-header {
                padding: 14px 14px 60px;
            }

            .page-title {
                font-size: 18px;
            }

            .page-subtitle {
                font-size: 11px;
            }

            .header-btn {
                width: 36px;
                height: 36px;
            }

            .header-btn i {
                font-size: 16px;
            }

            .step-number {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }

            .pin-input {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }

            .section-title {
                font-size: 16px;
            }
        }

        /* Keyboard handling for mobile */
        @media (max-width: 768px) {
            body.keyboard-open {
                padding-bottom: 0;
            }

            body.keyboard-open .bottom-nav {
                display: none;
            }
        }
    </style>
</head>

<body>
    <!-- Top Header - Dark Theme with Step Indicator -->
    <header class="top-header">
        <div class="header-content">
            <div class="header-top">
                <div class="page-info">
                    <h1 class="page-title">Transfer Antar KASDIG</h1>
                    <p class="page-subtitle">Kirim dana ke rekening teman kamu dengan mudah</p>
                </div>

            </div>

            <!-- Step Indicator inside Header -->
            <div class="step-indicator">
                <div
                    class="step-item <?php echo $current_step >= 1 ? ($current_step == 1 ? 'active' : 'completed') : ''; ?>">
                    <div class="step-number">1</div>
                    <div class="step-text">Cek Rekening</div>
                </div>
                <div
                    class="step-item <?php echo $current_step >= 2 ? ($current_step == 2 ? 'active' : 'completed') : ''; ?>">
                    <div class="step-number">2</div>
                    <div class="step-text">Detail</div>
                </div>
                <div
                    class="step-item <?php echo $current_step >= 3 ? ($current_step == 3 ? 'active' : 'completed') : ''; ?>">
                    <div class="step-number">3</div>
                    <div class="step-text">Nominal & PIN</div>
                </div>
                <div class="step-item <?php echo $current_step >= 4 ? 'active' : ''; ?>">
                    <div class="step-number">4</div>
                    <div class="step-text">Konfirmasi</div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <?php if ($error): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '<?php echo addslashes($error); ?>',
                    confirmButtonText: 'OK'
                }).then(() => {
                    const rekeningInput = document.getElementById('rekening_tujuan');
                    if (rekeningInput && <?php echo $current_step; ?> === 1) {
                        rekeningInput.value = '';
                        rekeningInput.focus();
                    }
                    const pinInputs = document.querySelectorAll('.pin-input');
                    if (pinInputs.length > 0 && <?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 2) {
                        pinInputs.forEach(input => {
                            input.value = '';
                            input.dataset.value = '';
                        });
                        const hiddenPin = document.getElementById('pin');
                        if (hiddenPin) hiddenPin.value = '';
                        if (pinInputs[0]) pinInputs[0].focus();
                    }
                    const jumlahInput = document.getElementById('jumlah');
                    if (jumlahInput && <?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 1) {
                        jumlahInput.value = '';
                        jumlahInput.focus();
                    }
                });
            </script>
        <?php endif; ?>
        <?php if (isset($_SESSION['show_popup']) && $_SESSION['show_popup'] === true): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Transfer Berhasil!',
                    text: 'Dana telah dikirim. Cek email Anda untuk bukti transfer.',
                    confirmButtonText: 'OK'
                }).then(() => {
                    window.location.href = 'struk_transaksi_transfer.php?no_transaksi=<?php echo htmlspecialchars($_SESSION['no_transaksi']); ?>';
                });
            </script>
            <?php unset($_SESSION['show_popup']); ?>
        <?php endif; ?>

        <!-- Step 1: Check Account -->
        <?php if ($current_step == 1): ?>
            <div class="transfer-card">
                <h3 class="section-title">Masukkan Nomor Rekening Tujuan</h3>
                <form method="POST" action="" id="cekRekening">
                    <input type="hidden" name="action" value="verify_account">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                    <div class="form-group">
                        <label for="rekening_tujuan" class="form-label">No Rekening (8 Digit)</label>
                        <input type="text" class="form-control" id="rekening_tujuan" name="rekening_tujuan"
                            placeholder="Input No rekening..." required autofocus inputmode="numeric" maxlength="8">
                    </div>
                    <div class="btn-actions-single">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Cek Rekening
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <!-- Step 2: Recipient Details -->
        <?php if ($current_step == 2): ?>
            <div class="transfer-card">
                <h3 class="section-title">Detail Penerima</h3>
                <?php if (isset($_SESSION['tujuan_data']))
                    $tujuan_data = $_SESSION['tujuan_data']; ?>
                <div class="detail-row">
                    <span class="detail-label">Nomor Rekening:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['no_rekening'] ?? '-'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Nama Penerima:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['nama'] ?? '-'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Jurusan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['nama_jurusan'] ?? '-'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Kelas:</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars(($tujuan_data['nama_tingkatan'] ?? '') . ' ' . ($tujuan_data['nama_kelas'] ?? '')); ?></span>
                </div>
                <form method="POST" action="" id="proceedForm2" style="display: inline;">
                    <input type="hidden" name="action" value="input_amount">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                </form>
                <form method="POST" action="" id="cancelForm2" style="display: inline;">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                </form>
                <div class="btn-actions">
                    <button type="submit" form="proceedForm2" class="btn btn-primary">
                        Lanjut
                    </button>
                    <button type="submit" form="cancelForm2" class="btn btn-danger">
                        Batal
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <!-- Step 3: Input Amount and PIN -->
        <?php if ($current_step == 3): ?>
            <div class="transfer-card">
                <h3 class="section-title">Nominal & PIN</h3>
                <?php if ($sub_step == 1): ?>
                    <form method="POST" action="" id="amountForm">
                        <input type="hidden" name="action" value="check_amount">
                        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                        <div class="form-group">
                            <label for="jumlah" class="form-label">Jumlah Transfer</label>
                            <input type="text" class="form-control" id="jumlah" name="jumlah" placeholder="Minimal Rp 1"
                                required value="<?php echo htmlspecialchars($input_jumlah); ?>" inputmode="numeric">
                        </div>
                        <div class="form-group">
                            <label for="keterangan" class="form-label">Keterangan (Opsional)</label>
                            <textarea class="form-control" id="keterangan" name="keterangan"
                                placeholder="Masukkan keterangan transfer..."><?php echo htmlspecialchars($input_keterangan); ?></textarea>
                        </div>
                    </form>
                    <form method="POST" action="" id="cancelForm3a">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                    </form>
                    <div class="btn-actions">
                        <button type="submit" form="amountForm" class="btn btn-primary">
                            Cek Saldo
                        </button>
                        <button type="submit" form="cancelForm3a" class="btn btn-danger">
                            Batal
                        </button>
                    </div>
                <?php elseif ($sub_step == 2): ?>
                    <div class="form-group">
                        <label class="form-label">Jumlah Transfer</label>
                        <div class="detail-row">
                            <span class="detail-label">Nominal:</span>
                            <span
                                class="detail-value"><?php echo formatRupiah($_SESSION['transfer_amount_temp'] ?? 0); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($_SESSION['keterangan_temp'])): ?>
                        <div class="form-group">
                            <label class="form-label">Keterangan</label>
                            <div class="detail-row">
                                <span class="detail-label">Catatan:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($_SESSION['keterangan_temp']); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="" id="pinForm">
                        <input type="hidden" name="action" value="confirm_pin">
                        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                        <div class="form-group">
                            <label class="form-label">Masukkan PIN (6 Digit)</label>
                            <div class="pin-container">
                                <input type="text" class="pin-input" maxlength="1" data-index="0" inputmode="numeric"
                                    autocomplete="off">
                                <input type="text" class="pin-input" maxlength="1" data-index="1" inputmode="numeric"
                                    autocomplete="off">
                                <input type="text" class="pin-input" maxlength="1" data-index="2" inputmode="numeric"
                                    autocomplete="off">
                                <input type="text" class="pin-input" maxlength="1" data-index="3" inputmode="numeric"
                                    autocomplete="off">
                                <input type="text" class="pin-input" maxlength="1" data-index="4" inputmode="numeric"
                                    autocomplete="off">
                                <input type="text" class="pin-input" maxlength="1" data-index="5" inputmode="numeric"
                                    autocomplete="off">
                            </div>
                            <input type="hidden" id="pin" name="pin">
                        </div>
                    </form>
                    <form method="POST" action="" id="cancelForm3b">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                    </form>
                    <div class="btn-actions">
                        <button type="submit" form="pinForm" class="btn btn-primary">
                            Lanjut
                        </button>
                        <button type="submit" form="cancelForm3b" class="btn btn-danger">
                            Batal
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <!-- Step 4: Confirmation -->
        <?php if ($current_step == 4): ?>
            <div class="transfer-card">
                <h3 class="section-title">Konfirmasi Transfer</h3>
                <?php if (isset($_SESSION['tujuan_data']))
                    $tujuan_data = $_SESSION['tujuan_data']; ?>
                <div class="detail-row">
                    <span class="detail-label">Rekening Asal:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($no_rekening); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Rekening Tujuan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['no_rekening'] ?? '-'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Nama Penerima:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['nama'] ?? '-'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Jumlah Transfer:</span>
                    <span class="detail-value"><?php echo formatRupiah($_SESSION['transfer_amount'] ?? 0); ?></span>
                </div>
                <?php if (!empty($_SESSION['keterangan'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Keterangan:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($_SESSION['keterangan']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="detail-label">Biaya:</span>
                    <span class="detail-value">Rp 0</span>
                </div>
                <form method="POST" action="" id="processForm">
                    <input type="hidden" name="action" value="process_transfer">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                </form>
                <form method="POST" action="" id="cancelForm4">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                </form>
                <div class="btn-actions">
                    <button type="submit" form="processForm" class="btn btn-primary">
                        Proses Transfer
                    </button>
                    <button type="submit" form="cancelForm4" class="btn btn-danger">
                        Batal
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <!-- Global Cancel Form -->
    <form id="globalCancelForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="cancel_to_dashboard">
        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
    </form>
    <?php include 'bottom_navbar.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const rekeningInput = document.getElementById('rekening_tujuan');
            const jumlahInput = document.getElementById('jumlah');
            const pinInputs = document.querySelectorAll('.pin-input');
            const hiddenPin = document.getElementById('pin');
            const bottomNav = document.getElementById('bottomNav');
            let isSubmitting = false;
            let scrollTimer;
            let lastScrollTop = 0;
            // Bottom nav auto hide/show
            window.addEventListener('scroll', () => {
                const currentScroll = window.pageYOffset || document.documentElement.scrollTop;

                if (currentScroll > lastScrollTop || currentScroll < lastScrollTop) {
                    bottomNav.classList.add('hidden');
                }

                lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(() => {
                    bottomNav.classList.remove('hidden');
                }, 500);
            }, { passive: true });
            // Mobile keyboard handling
            let initialHeight = window.innerHeight;
            function handleKeyboard() {
                const currentHeight = window.innerHeight;
                if (currentHeight < initialHeight * 0.9) {
                    document.body.classList.add('keyboard-open');
                } else {
                    document.body.classList.remove('keyboard-open');
                }
            }
            window.addEventListener('resize', handleKeyboard);
            // Rekening input validation
            if (rekeningInput) {
                rekeningInput.addEventListener('input', function (e) {
                    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8);
                });
                rekeningInput.addEventListener('keydown', function (e) {
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
            }
            // Jumlah input formatting
            if (jumlahInput) {
                jumlahInput.addEventListener('input', function (e) {
                    let value = e.target.value.replace(/[^0-9]/g, '');
                    if (value) {
                        e.target.value = new Intl.NumberFormat('id-ID').format(parseInt(value));
                    } else {
                        e.target.value = '';
                    }
                });
            }
            // PIN inputs handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', function () {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        this.value = '*';
                        this.dataset.value = value;
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        this.value = '';
                        this.dataset.value = '';
                    }
                    updatePin();
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !this.dataset.value && index > 0) {
                        pinInputs[index - 1].focus();
                    }
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
                input.addEventListener('focus', function () {
                    if (this.value === '*' && this.dataset.value) {
                        this.value = this.dataset.value;
                    }
                });
                input.addEventListener('blur', function () {
                    if (this.value !== '*' && this.dataset.value) {
                        this.value = '*';
                    }
                });
            });
            function updatePin() {
                if (hiddenPin) {
                    hiddenPin.value = Array.from(pinInputs).map(i => i.dataset.value || '').join('');
                }
            }
            // Form submissions with loading
            const cekForm = document.getElementById('cekRekening');
            if (cekForm) {
                cekForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    const rekening = rekeningInput.value.trim();
                    if (rekening.length !== 8 || !/^\d{8}$/.test(rekening)) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Nomor rekening harus 8 digit angka!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            rekeningInput.value = '';
                            rekeningInput.focus();
                        });
                        return;
                    }
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Memverifikasi Rekening...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 2000);
                });
            }
            const amountForm = document.getElementById('amountForm');
            if (amountForm) {
                amountForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    let jumlah = jumlahInput.value.replace(/[^0-9]/g, '');
                    jumlah = parseInt(jumlah);
                    if (!jumlah || jumlah < 1) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Jumlah transfer minimal Rp 1',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            jumlahInput.focus();
                        });
                        return;
                    }
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Mengecek Saldo...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 1500);
                });
            }
            const pinForm = document.getElementById('pinForm');
            if (pinForm) {
                pinForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    let pin = hiddenPin.value;
                    if (pin.length !== 6) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'PIN harus 6 digit',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            pinInputs[0].focus();
                            pinInputs.forEach(input => {
                                input.value = '';
                                input.dataset.value = '';
                            });
                            hiddenPin.value = '';
                        });
                        return;
                    }
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Memverifikasi PIN...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 1500);
                });
            }
            const processForm = document.getElementById('processForm');
            if (processForm) {
                processForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Memproses Transfer',
                        text: 'Mohon tunggu sebentar...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 1000);
                });
            }
            // Focus on relevant input
            if (<?php echo $current_step; ?> === 1 && rekeningInput) {
                rekeningInput.focus();
            } else if (<?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 1 && jumlahInput) {
                jumlahInput.focus();
            } else if (<?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 2 && pinInputs[0]) {
                pinInputs[0].focus();
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>