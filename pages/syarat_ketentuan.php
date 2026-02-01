<?php
/**
 * Syarat dan Ketentuan - KASDIG
 * File: pages/syarat_ketentuan.php
 */

$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = dirname($current_dir);

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

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
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <title>Syarat dan Ketentuan | KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            font-family: 'Poppins', sans-serif;
            background: #ffffff;
            color: #333;
            line-height: 1.7;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px 20px 40px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #1a1a2e;
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 25px;
            transition: opacity 0.3s ease;
        }

        .back-btn:hover {
            opacity: 0.7;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .last-update {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 30px;
        }

        .section {
            margin-bottom: 25px;
        }

        .section h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 10px;
        }

        .section p,
        .section li {
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 8px;
        }

        .section ul,
        .section ol {
            padding-left: 20px;
        }

        .section ul li,
        .section ol li {
            margin-bottom: 6px;
        }

        .highlight-box {
            background: #f8f9fa;
            border-left: 3px solid #1a1a2e;
            padding: 15px;
            margin: 15px 0;
            border-radius: 0 8px 8px 0;
        }

        .highlight-box p {
            margin: 0;
            font-size: 0.85rem;
        }


        @media (max-width: 480px) {
            .container {
                padding: 20px 15px 30px;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .section h2 {
                font-size: 0.95rem;
            }

            .section p,
            .section li {
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="<?= BASE_URL ?>/pages/login.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>

        <h1 class="page-title">Syarat dan Ketentuan</h1>
        <p class="last-update">Terakhir diperbarui: 17 Desember 2025</p>

        <div class="section">
            <h2>1. Penerimaan Syarat</h2>
            <p>Dengan mengakses dan menggunakan aplikasi KASDIG, Anda menyetujui untuk terikat
                dengan syarat dan ketentuan yang berlaku. Jika Anda tidak menyetujui salah satu atau seluruh syarat ini,
                mohon untuk tidak menggunakan layanan kami.</p>
        </div>

        <div class="section">
            <h2>2. Definisi</h2>
            <ul>
                <li><strong>KASDIG</strong> adalah aplikasi digital banking yang dikelola oleh SMK PLUS Ashabulyamin
                    Cianjur untuk keperluan edukasi dan praktik perbankan siswa.</li>
                <li><strong>Pengguna</strong> adalah siswa, petugas, atau administrator yang terdaftar dan memiliki akun
                    pada aplikasi ini.</li>
                <li><strong>Layanan</strong> mencakup seluruh fitur yang tersedia dalam aplikasi termasuk transaksi
                    setor, tarik, transfer, dan pembayaran infaq.</li>
            </ul>
        </div>

        <div class="section">
            <h2>3. Kelayakan Pengguna</h2>
            <p>Layanan KASDIG hanya tersedia untuk:</p>
            <ul>
                <li>Siswa aktif SMK PLUS Ashabulyamin Cianjur</li>
                <li>Petugas yang ditunjuk oleh pihak sekolah</li>
                <li>Administrator yang berwenang</li>
            </ul>
        </div>

        <div class="section">
            <h2>4. Keamanan Akun</h2>
            <p>Pengguna bertanggung jawab untuk:</p>
            <ul>
                <li>Menjaga kerahasiaan username, password, dan PIN</li>
                <li>Tidak membagikan informasi akun kepada pihak lain</li>
                <li>Segera melaporkan jika terjadi penyalahgunaan akun</li>
                <li>Melakukan logout setelah selesai menggunakan aplikasi</li>
            </ul>
            <div class="highlight-box">
                <p><strong>Penting:</strong> KASDIG tidak bertanggung jawab atas kerugian yang timbul akibat kelalaian
                    pengguna dalam menjaga keamanan akun.</p>
            </div>
        </div>

        <div class="section">
            <h2>5. Penggunaan Layanan</h2>
            <p>Pengguna dilarang untuk:</p>
            <ul>
                <li>Menggunakan layanan untuk tujuan ilegal atau melanggar hukum</li>
                <li>Mencoba mengakses akun pengguna lain tanpa izin</li>
                <li>Melakukan manipulasi data atau transaksi</li>
                <li>Mengganggu sistem atau infrastruktur aplikasi</li>
            </ul>
        </div>

        <div class="section">
            <h2>6. Transaksi</h2>
            <p>Semua transaksi yang dilakukan melalui KASDIG bersifat final dan tidak dapat dibatalkan setelah
                diproses. Pastikan untuk memeriksa kembali detail transaksi sebelum mengonfirmasi.</p>
        </div>

        <div class="section">
            <h2>7. Pembatasan Tanggung Jawab</h2>
            <p>KASDIG tidak bertanggung jawab atas:</p>
            <ul>
                <li>Gangguan layanan akibat pemeliharaan sistem</li>
                <li>Kerugian akibat force majeure atau keadaan di luar kendali</li>
                <li>Kesalahan transaksi akibat kelalaian pengguna</li>
            </ul>
        </div>

        <div class="section">
            <h2>8. Perubahan Syarat</h2>
            <p>KASDIG berhak mengubah syarat dan ketentuan ini sewaktu-waktu. Perubahan akan diinformasikan melalui
                aplikasi dan berlaku efektif sejak dipublikasikan.</p>
        </div>

        <div class="section">
            <h2>9. Kontak</h2>
            <p>Untuk pertanyaan terkait syarat dan ketentuan ini, silakan hubungi administrator sekolah atau kunjungi
                kantor Bank Mini SMK PLUS Ashabulyamin Cianjur.</p>
        </div>
    </div>


</body>

</html>