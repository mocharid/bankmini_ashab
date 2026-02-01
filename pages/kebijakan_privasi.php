<?php
/**
 * Kebijakan Privasi - KASDIG
 * File: pages/kebijakan_privasi.php
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
    <title>Kebijakan Privasi | KASDIG</title>
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

        <h1 class="page-title">Kebijakan Privasi</h1>
        <p class="last-update">Terakhir diperbarui: 17 Desember 2025</p>

        <div class="section">
            <h2>1. Pendahuluan</h2>
            <p>Kebijakan Privasi ini menjelaskan bagaimana KASDIG mengumpulkan, menggunakan,
                dan melindungi informasi pribadi Anda. Kami berkomitmen untuk menjaga privasi dan keamanan data
                pengguna.</p>
        </div>

        <div class="section">
            <h2>2. Informasi yang Kami Kumpulkan</h2>
            <p>Kami mengumpulkan informasi berikut saat Anda menggunakan layanan kami:</p>
            <ul>
                <li><strong>Data Identitas:</strong> Nama lengkap, NIS, kelas, dan jurusan</li>
                <li><strong>Data Kontak:</strong> Alamat email dan nomor telepon</li>
                <li><strong>Data Akun:</strong> Username, password terenkripsi, dan PIN</li>
                <li><strong>Data Transaksi:</strong> Riwayat setor, tarik, transfer, dan pembayaran</li>
                <li><strong>Data Teknis:</strong> Alamat IP, jenis perangkat, dan waktu akses</li>
            </ul>
        </div>

        <div class="section">
            <h2>3. Penggunaan Informasi</h2>
            <p>Informasi yang dikumpulkan digunakan untuk:</p>
            <ul>
                <li>Menyediakan dan mengelola layanan perbankan digital</li>
                <li>Memproses transaksi keuangan</li>
                <li>Memverifikasi identitas pengguna</li>
                <li>Mengirim notifikasi terkait aktivitas akun</li>
                <li>Meningkatkan kualitas layanan dan keamanan</li>
                <li>Keperluan administrasi dan pelaporan sekolah</li>
            </ul>
        </div>

        <div class="section">
            <h2>4. Perlindungan Data</h2>
            <p>Kami menerapkan langkah-langkah keamanan untuk melindungi data Anda:</p>
            <ul>
                <li>Enkripsi password dan PIN menggunakan algoritma aman</li>
                <li>Sistem autentikasi multi-level</li>
                <li>Pemantauan aktivitas mencurigakan</li>
                <li>Akses terbatas hanya untuk personel berwenang</li>
                <li>Backup data secara berkala</li>
            </ul>
            <div class="highlight-box">
                <p><strong>Catatan:</strong> Meskipun kami berupaya maksimal untuk melindungi data Anda, tidak ada
                    sistem yang 100% aman. Pengguna juga bertanggung jawab menjaga kerahasiaan kredensial akun.</p>
            </div>
        </div>

        <div class="section">
            <h2>5. Pembagian Informasi</h2>
            <p>Kami tidak menjual atau menyewakan data pribadi Anda. Informasi hanya dapat dibagikan kepada:</p>
            <ul>
                <li>Pihak sekolah untuk keperluan administrasi dan laporan</li>
                <li>Otoritas berwenang jika diwajibkan oleh hukum</li>
                <li>Wali/orang tua siswa terkait informasi rekening anak</li>
            </ul>
        </div>

        <div class="section">
            <h2>6. Penyimpanan Data</h2>
            <p>Data Anda disimpan selama akun masih aktif atau selama diperlukan untuk keperluan administrasi sekolah.
                Data transaksi akan disimpan sesuai dengan ketentuan yang berlaku untuk keperluan audit dan pelaporan.
            </p>
        </div>

        <div class="section">
            <h2>7. Hak Pengguna</h2>
            <p>Anda memiliki hak untuk:</p>
            <ul>
                <li>Mengakses informasi pribadi yang tersimpan</li>
                <li>Memperbarui data yang tidak akurat</li>
                <li>Meminta penjelasan tentang penggunaan data</li>
                <li>Melaporkan kekhawatiran terkait privasi</li>
            </ul>
        </div>

        <div class="section">
            <h2>8. Cookie dan Sesi</h2>
            <p>Aplikasi kami menggunakan sesi dan cookie untuk:</p>
            <ul>
                <li>Menjaga status login pengguna</li>
                <li>Mengingat preferensi pengguna</li>
                <li>Meningkatkan keamanan dan pengalaman pengguna</li>
            </ul>
        </div>

        <div class="section">
            <h2>9. Perubahan Kebijakan</h2>
            <p>Kami dapat memperbarui Kebijakan Privasi ini dari waktu ke waktu. Perubahan signifikan akan
                diinformasikan melalui aplikasi. Penggunaan berkelanjutan setelah perubahan berarti Anda menyetujui
                kebijakan yang diperbarui.</p>
        </div>

        <div class="section">
            <h2>10. Hubungi Kami</h2>
            <p>Jika Anda memiliki pertanyaan atau kekhawatiran tentang Kebijakan Privasi ini, silakan hubungi:</p>
            <ul>
                <li><strong>Bank Mini SMK PLUS Ashabulyamin</strong></li>
                <li>Alamat: Jl. Raya Cianjur, Cianjur, Jawa Barat</li>
                <li>Email: bankminismkplusashab@gmail.com</li>
            </ul>
        </div>
    </div>


</body>

</html>