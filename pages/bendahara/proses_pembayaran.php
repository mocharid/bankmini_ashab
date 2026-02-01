<?php
/**
 * Halaman Proses Pembayaran (Manual Potong Saldo)
 * File: pages/bendahara/proses_pembayaran.php
 * 
 * Fitur:
 * - Cari Mahasiswa/Siswa
 * - Cek Saldo Rekening Siswa
 * - Pilih Tagihan
 * - Bayar (Auto debet rekening siswa -> kredit rekening bendahara)
 */

// ============================================
// ADAPTIVE PATH DETECTION & INIT
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} else {
    $project_root = dirname(dirname($current_dir)); // Fallback
}

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

// ================= BASE URL =================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script));
    $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL'))
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
if (!defined('ASSETS_URL'))
    define('ASSETS_URL', BASE_URL . '/assets');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Cek Role Bendahara
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bendahara') {
    header("Location: ../../login.php");
    exit;
}

// Variables
$siswa = null;
$unpaid_bills = [];
$success_msg = '';
$error_msg = '';

// ============================================
// HANDLE SEARCH SUGGESTIONS (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'search_siswa') {
    header('Content-Type: application/json');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    if (strlen($q) < 1) {
        echo json_encode([]);
        exit;
    }

    $search = "%{$q}%";
    $stmt = $conn->prepare("SELECT u.id, u.nama, u.username, k.nama_kelas, tk.nama_tingkatan, j.nama_jurusan 
                            FROM users u 
                            LEFT JOIN siswa_profiles sp ON u.id = sp.user_id 
                            LEFT JOIN kelas k ON sp.kelas_id = k.id 
                            LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                            LEFT JOIN jurusan j ON sp.jurusan_id = j.id 
                            WHERE u.role = 'siswa' AND (u.nama LIKE ? OR u.username LIKE ?) 
                            LIMIT 10");
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $kelas = ($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? '');
        $jurusan = $row['nama_jurusan'] ?? '';
        $kelas_jurusan = trim($kelas);
        if ($jurusan) {
            $kelas_jurusan .= ' . ' . $jurusan;
        }

        $students[] = [
            'id' => $row['id'],
            'nama' => $row['nama'],
            'username' => $row['username'],
            'kelas_jurusan' => $kelas_jurusan ?: 'Belum ada data akademik'
        ];
    }

    echo json_encode($students);
    exit;
}

// ============================================
// HANDLE PIN VERIFICATION (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_pin') {
    header('Content-Type: application/json');

    $siswa_id = intval($_POST['siswa_id'] ?? 0);
    $pin = trim($_POST['pin'] ?? '');

    if (empty($pin)) {
        echo json_encode(['success' => false, 'message' => 'PIN harus diisi!']);
        exit;
    }

    if ($siswa_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID siswa tidak valid!']);
        exit;
    }

    // Get student's PIN from user_security table
    $stmt = $conn->prepare("SELECT us.pin, us.has_pin, us.failed_pin_attempts, us.pin_block_until 
                            FROM user_security us 
                            WHERE us.user_id = ?");
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pin_data = $result->fetch_assoc();
    $stmt->close();

    if (!$pin_data) {
        echo json_encode(['success' => false, 'message' => 'Data keamanan siswa tidak ditemukan!']);
        exit;
    }

    if (empty($pin_data['pin']) || !$pin_data['has_pin']) {
        echo json_encode(['success' => false, 'message' => 'Siswa belum mengatur PIN. Minta siswa untuk membuat PIN terlebih dahulu.']);
        exit;
    }

    // Check if PIN is blocked
    if ($pin_data['pin_block_until'] !== null && $pin_data['pin_block_until'] > date('Y-m-d H:i:s')) {
        $block_until = date('d/m/Y H:i:s', strtotime($pin_data['pin_block_until']));
        echo json_encode(['success' => false, 'message' => "PIN siswa diblokir karena terlalu banyak percobaan salah. Coba lagi setelah $block_until."]);
        exit;
    }

    // Verify PIN
    if (!password_verify($pin, $pin_data['pin'])) {
        $new_attempts = $pin_data['failed_pin_attempts'] + 1;

        if ($new_attempts >= 5) {
            $block_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $stmt = $conn->prepare("UPDATE user_security SET failed_pin_attempts = ?, pin_block_until = ? WHERE user_id = ?");
            $stmt->bind_param("isi", $new_attempts, $block_until, $siswa_id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'PIN salah! Akun siswa diblokir selama 1 jam karena 5 kali PIN salah.']);
        } else {
            $stmt = $conn->prepare("UPDATE user_security SET failed_pin_attempts = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $new_attempts, $siswa_id);
            $stmt->execute();
            $stmt->close();
            $remaining = 5 - $new_attempts;
            echo json_encode(['success' => false, 'message' => "PIN yang dimasukkan salah! Percobaan tersisa: $remaining"]);
        }
        exit;
    }

    // PIN correct - reset failed attempts
    $stmt = $conn->prepare("UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'PIN valid']);
    exit;
}

// ============================================
// HANDLE PEMBAYARAN (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay') {
    $siswa_id = intval($_POST['siswa_id']);
    $selected_bills = $_POST['bill_ids'] ?? [];
    $pin_verified = $_POST['pin_verified'] ?? '';

    // Verify that PIN was validated
    if ($pin_verified !== 'true') {
        $error_msg = "Verifikasi PIN diperlukan untuk melanjutkan pembayaran.";
    } elseif (empty($selected_bills)) {
        $error_msg = "Pilih minimal satu tagihan untuk dibayar.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Ambil Data Siswa & Saldo Terbaru (Lock For Update biar aman)
            $stmt_siswa = $conn->prepare("SELECT u.id, u.nama, r.id as rek_id, r.saldo FROM users u JOIN rekening r ON u.id = r.user_id WHERE u.id = ? FOR UPDATE");
            $stmt_siswa->bind_param("i", $siswa_id);
            $stmt_siswa->execute();
            $curr_siswa = $stmt_siswa->get_result()->fetch_assoc();

            if (!$curr_siswa)
                throw new Exception("Data siswa tidak ditemukan.");

            // 2. Hitung Total Tagihan yang dipilih
            $total_bayar = 0;
            $bill_details = [];

            // Siapkan statement check bill
            $stmt_bill = $conn->prepare("SELECT pt.id, pt.nominal, pi.nama_item FROM pembayaran_tagihan pt JOIN pembayaran_item pi ON pt.item_id = pi.id WHERE pt.id = ? AND pt.status = 'belum_bayar'");

            foreach ($selected_bills as $bill_id) {
                $stmt_bill->bind_param("i", $bill_id);
                $stmt_bill->execute();
                $res_bill = $stmt_bill->get_result();

                if ($row = $res_bill->fetch_assoc()) {
                    $total_bayar += $row['nominal'];
                    $bill_details[] = $row;
                } else {
                    throw new Exception("Salah satu tagihan tidak valid atau sudah dibayar (ID: $bill_id).");
                }
            }

            // 3. Cek Saldo Cukup
            if ($curr_siswa['saldo'] < $total_bayar) {
                throw new Exception("Saldo siswa tidak mencukupi! \nSaldo: Rp " . number_format($curr_siswa['saldo'], 0, ',', '.') . "\nTotal Tagihan: Rp " . number_format($total_bayar, 0, ',', '.'));
            }

            // 4. Ambil Info Bendahara (Penerima)
            $res_bendahara = $conn->query("SELECT u.id, r.id as rek_id FROM users u JOIN rekening r ON u.id = r.user_id WHERE u.role = 'bendahara' LIMIT 1");
            $bendahara = $res_bendahara->fetch_assoc();

            if (!$bendahara)
                throw new Exception("Akun rekening Bendahara tidak ditemukan. Hubungi Admin.");

            // 5. EKSEKUSI PEMBAYARAN
            // A. Potong Saldo Siswa
            $conn->query("UPDATE rekening SET saldo = saldo - $total_bayar WHERE id = " . $curr_siswa['rek_id']);

            // B. Tambah Saldo Bendahara
            $conn->query("UPDATE rekening SET saldo = saldo + $total_bayar WHERE id = " . $bendahara['rek_id']);

            // C. Update Status Tagihan & Log Transaksi
            $stmt_update_bill = $conn->prepare("UPDATE pembayaran_tagihan SET status = 'sudah_bayar', no_transaksi = ?, tanggal_bayar = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt_log_trans = $conn->prepare("INSERT INTO pembayaran_transaksi 
                (tagihan_id, siswa_id, bendahara_id, rekening_siswa_id, rekening_bendahara_id, nominal_bayar, no_pembayaran, metode_bayar, tanggal_bayar, keterangan) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'lainnya', NOW(), 'Pembayaran Manual via Bendahara (Potong Saldo)')");

            // D. INSERT ke tabel transaksi agar muncul di mutasi/aktivitas/dashboard siswa
            // Note: We need the inserted ID for mutasi
            $stmt_insert_transaksi = $conn->prepare("INSERT INTO transaksi 
                (no_transaksi, rekening_id, rekening_tujuan_id, jenis_transaksi, jumlah, status, keterangan, created_at, petugas_id) 
                VALUES (?, ?, ?, 'bayar', ?, 'approved', ?, NOW(), ?)");

            // E. Statement for Mutasi
            $stmt_insert_mutasi = $conn->prepare("INSERT INTO mutasi (rekening_id, transaksi_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())");

            // Variables for running balance calculation
            $current_saldo_siswa = $curr_siswa['saldo'];
            $current_saldo_bendahara = 0; // We need bendahara's initial balance. Fetching it now.

            // Re-fetch Bendahara balance to be sure
            $res_bendahara_bal = $conn->query("SELECT saldo FROM rekening WHERE id = " . $bendahara['rek_id']);
            $current_saldo_bendahara = $res_bendahara_bal->fetch_assoc()['saldo'] - $total_bayar; // Undo the update in memory to start from before-txn state

            // Get ID of current user (Bendahara) for petugas_id in transaksi
            $petugas_id = $_SESSION['user_id'];

            foreach ($bill_details as $bill) {
                // Generate nomor transaksi - PAYADM prefix for bendahara-processed payments
                $no_trx = 'PAYADM' . date('ymd') . sprintf('%04d', rand(0, 9999)); // 6+6+4 = 16 chars

                // Update Bill dengan no_transaksi
                $stmt_update_bill->bind_param("si", $no_trx, $bill['id']);
                $stmt_update_bill->execute();

                // Log pembayaran_transaksi
                $stmt_log_trans->bind_param(
                    "iiiiids",
                    $bill['id'],
                    $siswa_id,
                    $bendahara['id'],
                    $curr_siswa['rek_id'],
                    $bendahara['rek_id'],
                    $bill['nominal'],
                    $no_trx
                );
                $stmt_log_trans->execute();

                // INSERT ke tabel transaksi
                $keterangan_transaksi = 'Pembayaran Tagihan ' . $bill['nama_item'];
                $stmt_insert_transaksi->bind_param(
                    "siidsi",
                    $no_trx,
                    $curr_siswa['rek_id'],
                    $bendahara['rek_id'],
                    $bill['nominal'],
                    $keterangan_transaksi,
                    $petugas_id
                );
                $stmt_insert_transaksi->execute();
                $transaksi_id = $conn->insert_id;

                // POST-PROCESSING: Insert Mutasi Records

                // 1. Mutasi Siswa (Debit / Pengurangan)
                $current_saldo_siswa -= $bill['nominal'];
                $debit_amount = -1 * $bill['nominal'];
                $stmt_insert_mutasi->bind_param("iids", $curr_siswa['rek_id'], $transaksi_id, $debit_amount, $current_saldo_siswa);
                $stmt_insert_mutasi->execute();

                // 2. Mutasi Bendahara (Kredit / Penambahan)
                $current_saldo_bendahara += $bill['nominal'];
                $credit_amount = $bill['nominal'];
                $stmt_insert_mutasi->bind_param("iids", $bendahara['rek_id'], $transaksi_id, $credit_amount, $current_saldo_bendahara);
                $stmt_insert_mutasi->execute();
            }

            $conn->commit();
            $success_msg = "Pembayaran BERHASIL diproses! Total: Rp " . number_format($total_bayar, 0, ',', '.');

            // Refresh data siswa agar saldo update
            $siswa = $curr_siswa;
            $siswa['saldo'] -= $total_bayar; // visual update only

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = $e->getMessage();
            // Keep the ID to reload page properly
            $_GET['siswa_id'] = $siswa_id;
        }
    }
}

// ============================================
// CARI SISWA (GET Request)
// ============================================
if (isset($_GET['siswa_id']) || isset($_GET['q'])) {

    // Jika search by string
    if (isset($_GET['q']) && !isset($_GET['siswa_id'])) {
        $q = "%" . trim($_GET['q']) . "%";
        $find = $conn->prepare("SELECT id FROM users WHERE role='siswa' AND (nama LIKE ? OR username LIKE ?) LIMIT 1");
        $find->bind_param("ss", $q, $q);
        $find->execute();
        $res = $find->get_result();
        if ($row = $res->fetch_assoc()) {
            $_GET['siswa_id'] = $row['id'];
        } else {
            $error_msg = "Siswa tidak ditemukan.";
        }
    }

    // Jika ID sudah dapat
    if (isset($_GET['siswa_id'])) {
        $sid = intval($_GET['siswa_id']);

        // Get Info
        $stmt = $conn->prepare("SELECT u.id, u.nama, u.username, r.no_rekening, r.saldo, k.nama_kelas, tk.nama_tingkatan 
                                FROM users u 
                                LEFT JOIN rekening r ON u.id = r.user_id 
                                LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
                                LEFT JOIN kelas k ON sp.kelas_id = k.id
                                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                                WHERE u.id = ? AND u.role = 'siswa'");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $siswa = $stmt->get_result()->fetch_assoc();

        if ($siswa) {
            // Get Unpaid Bills
            $stmt_bills = $conn->prepare("SELECT pt.*, pi.nama_item 
                                         FROM pembayaran_tagihan pt 
                                         JOIN pembayaran_item pi ON pt.item_id = pi.id 
                                         WHERE pt.siswa_id = ? AND pt.status = 'belum_bayar' 
                                         ORDER BY pt.tanggal_jatuh_tempo ASC");
            $stmt_bills->bind_param("i", $sid);
            $stmt_bills->execute();
            $result_bills = $stmt_bills->get_result();
            while ($b = $result_bills->fetch_assoc()) {
                $unpaid_bills[] = $b;
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proses Pembayaran - KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">

    <!-- CSS Dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        :root {
            --primary: #0c4a6e;
            --primary-light: #0369a1;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --radius-sm: 6px;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }

        /* Page Title Section */
        .page-title-section {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            padding: 1.5rem 0;
            margin: -2rem -2rem 1.5rem -2rem;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-title-content {
            flex: 1;
        }

        .page-hamburger {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            align-items: center;
            justify-content: center;
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        .page-hamburger:active {
            transform: scale(0.95);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--gray-600);
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border: 1px solid var(--gray-100);
            position: relative;
        }

        .card-header {
            background: linear-gradient(to right, var(--gray-50), white);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Search Area */
        .search-area {
            display: flex;
            gap: 1rem;
            max-width: 600px;
        }

        .search-input-wrapper {
            position: relative;
            flex: 1;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            padding-right: 2.5rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--gray-400);
            box-shadow: 0 0 0 4px rgba(100, 116, 139, 0.1);
        }

        .btn-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 1rem;
            display: none;
        }

        .btn-clear:hover {
            color: var(--gray-600);
        }

        /* Search Suggestions */
        .suggestions-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--gray-200);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            margin-top: 2px;
        }

        .suggestions-dropdown.show {
            display: block;
        }

        .suggestion-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.15s ease;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover {
            background: var(--gray-50);
        }

        .suggestion-name {
            font-weight: 500;
            color: var(--gray-800);
            font-size: 0.9rem;
        }

        .suggestion-meta {
            font-size: 0.8rem;
            color: var(--gray-500);
            display: flex;
            justify-content: space-between;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
        }

        .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }

        .btn-search {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
        }

        .btn-search:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }

        /* Student Info */
        .student-info {
            display: flex;
            gap: 2rem;
            padding: 1.5rem;
            background: var(--gray-50);
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            align-items: center;
            border: 1px solid var(--gray-200);
        }

        .info-group h4 {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 5px;
        }

        .info-group p {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .balance-info {
            color: var(--gray-700) !important;
        }

        /* Bill Table */
        .bill-table {
            width: 100%;
            border-collapse: collapse;
        }

        .bill-table th {
            text-align: left;
            padding: 1rem 1.5rem;
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
            font-size: 0.8rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .bill-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.9rem;
        }

        .bill-table tr:hover {
            background: var(--gray-50);
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
        }

        .checkbox-wrapper input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--gray-600);
        }

        /* Total Bar */
        .total-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }

        .total-label {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .total-amount {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gray-800);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-400);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: block;
            color: var(--gray-300);
        }

        /* Late Badge */
        .late-badge {
            color: var(--gray-600);
            font-size: 0.8rem;
            font-weight: 500;
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 75px;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-section {
                margin: -1rem -1rem 1rem -1rem;
                padding: 1rem;
            }

            .student-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .bill-table {
                display: block;
                overflow-x: auto;
            }

            .search-area {
                flex-direction: column;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.25rem;
            }

            .total-bar {
                flex-direction: column;
                gap: 0.75rem;
                text-align: center;
            }

            .info-group {
                text-align: left !important;
                margin-left: 0 !important;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
                padding-top: 70px;
            }

            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .card-header {
                padding: 1rem;
            }

            .card-body {
                padding: 1rem;
            }

            .student-info {
                padding: 1rem;
            }

            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.85rem;
            }
        }

        /* Hide Hamburger Button as requested */
        .mobile-navbar {
            display: none !important;
        }

        @media (max-width: 1024px) {
            .main-content {
                padding-top: 1rem !important;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/sidebar_bendahara.php'; ?>

    <div class="main-content">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Proses Pembayaran</h1>
                <p class="page-subtitle">Bayar tagihan siswa dengan memotong saldo rekening mereka</p>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <script>Swal.fire({ title: 'Berhasil!', text: '<?php echo $success_msg; ?>', icon: 'success' });</script>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <script>Swal.fire({ title: 'Gagal!', text: '<?php echo str_replace("\n", '<br>', addslashes($error_msg)); ?>', icon: 'error' });</script>
        <?php endif; ?>

        <!-- Search Section -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="card-header-text">
                    <h3>Cari Siswa</h3>
                    <p>Masukkan nama atau username siswa</p>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" class="search-area">
                    <div class="search-input-wrapper">
                        <input type="text" name="q" id="searchInput" class="form-control"
                            placeholder="Cari Nama Siswa atau Username..."
                            value="<?php echo htmlspecialchars($_GET['q'] ?? ($siswa['nama'] ?? '')); ?>" required
                            oninput="toggleClearBtn()" autocomplete="off">
                        <button type="button" class="btn-clear" id="clearBtn" onclick="clearSearch()">
                            <i class="fas fa-times"></i>
                        </button>
                        <div id="searchResults" class="suggestions-dropdown"></div>
                    </div>
                    <button type="submit" class="btn btn-search"><i class="fas fa-search"></i> Cari</button>
                </form>
            </div>
        </div>

        <?php if ($siswa): ?>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="siswa_id" value="<?php echo $siswa['id']; ?>">
                <input type="hidden" name="pin_verified" id="pin_verified" value="">

                <!-- Student Detail -->
                <div class="student-info">
                    <div class="info-group">
                        <h4>Nama Siswa</h4>
                        <p><?php echo htmlspecialchars($siswa['nama']); ?></p>
                    </div>
                    <div class="info-group">
                        <h4>Kelas</h4>
                        <p><?php echo htmlspecialchars(($siswa['nama_tingkatan'] ?? '') . ' ' . ($siswa['nama_kelas'] ?? '-')); ?>
                        </p>
                    </div>
                    <div class="info-group">
                        <h4>No. Rekening</h4>
                        <p style="font-family: monospace;"><?php echo htmlspecialchars($siswa['no_rekening']); ?></p>
                    </div>
                    <div class="info-group" style="margin-left: auto; text-align: right;">
                        <h4>Saldo Tabungan</h4>
                        <p class="balance-info">Rp <?php echo number_format($siswa['saldo'], 0, ',', '.'); ?></p>
                        <input type="hidden" id="current_balance" value="<?php echo $siswa['saldo']; ?>">
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="card-header-text">
                            <h3>Tagihan Belum Dibayar</h3>
                            <p>Pilih tagihan yang akan dibayarkan</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($unpaid_bills)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>Tidak ada tagihan yang perlu dibayar.</p>
                            </div>
                        <?php else: ?>
                            <table class="bill-table">
                                <thead>
                                    <tr>
                                        <th width="50"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
                                        <th>Nama Tagihan</th>
                                        <th>Jatuh Tempo</th>
                                        <th style="text-align: right;">Nominal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaid_bills as $bill): ?>
                                        <tr>
                                            <td>
                                                <div class="checkbox-wrapper">
                                                    <input type="checkbox" name="bill_ids[]" value="<?php echo $bill['id']; ?>"
                                                        class="bill-check" data-nominal="<?php echo $bill['nominal']; ?>"
                                                        onchange="calculateTotal()">
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($bill['nama_item']); ?></strong><br>
                                                <span class="late-badge"><?php echo $bill['no_tagihan']; ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $bulan_indo = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
                                                $due = strtotime($bill['tanggal_jatuh_tempo']);
                                                $is_late = $due < time();
                                                echo date('d', $due) . ' ' . $bulan_indo[(int) date('n', $due)] . ' ' . date('Y', $due);
                                                if ($is_late)
                                                    echo ' <span class="late-badge">(Lewat)</span>';
                                                ?>
                                            </td>
                                            <td style="text-align: right; font-weight: 600;">
                                                Rp <?php echo number_format($bill['nominal'], 0, ',', '.'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="total-bar">
                                <div class="total-label">Total Yang Akan Dibayar:</div>
                                <div class="total-amount" id="displayTotal">Rp 0</div>
                            </div>

                            <div style="text-align: right; margin-top: 1.5rem;">
                                <button type="button" class="btn btn-success" onclick="confirmPayment()">
                                    <i class="fas fa-money-bill-wave"></i> Proses Pembayaran
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Check clear button on load
        $(document).ready(function () {
            toggleClearBtn();
        });

        function toggleClearBtn() {
            const val = document.getElementById('searchInput').value;
            const btn = document.getElementById('clearBtn');
            btn.style.display = val.length > 0 ? 'block' : 'none';
        }

        function clearSearch() {
            document.getElementById('searchInput').value = '';
            toggleClearBtn();
            document.getElementById('searchResults').classList.remove('show');
            // Optional: Jika ingin langsung reset pencarian (halaman reload)
            window.location.href = 'proses_pembayaran.php';
        }

        // Search Suggestions Logic
        const searchInput = document.getElementById('searchInput');
        const resultsContainer = document.getElementById('searchResults');
        let searchTimeout;

        searchInput.addEventListener('input', function () {
            const query = this.value.trim();
            clearTimeout(searchTimeout);

            if (query.length < 2) {
                resultsContainer.classList.remove('show');
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch(`proses_pembayaran.php?action=search_siswa&q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        resultsContainer.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach(student => {
                                const div = document.createElement('div');
                                div.className = 'suggestion-item';
                                div.innerHTML = `
                                    <div class="suggestion-name">${student.nama}</div>
                                    <div class="suggestion-meta">
                                        <span>${student.kelas_jurusan}</span>
                                    </div>
                                `;
                                div.addEventListener('click', () => {
                                    searchInput.value = student.nama; // Fill input with name
                                    resultsContainer.classList.remove('show');
                                    window.location.href = `proses_pembayaran.php?siswa_id=${student.id}`;
                                });
                                resultsContainer.appendChild(div);
                            });
                            resultsContainer.classList.add('show');
                        } else {
                            resultsContainer.classList.remove('show');
                        }
                    })
                    .catch(error => console.error('Error fetching suggestions:', error));
            }, 300); // Debounce 300ms
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                resultsContainer.classList.remove('show');
            }
        });

        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.bill-check');
            checkboxes.forEach(cb => cb.checked = source.checked);
            calculateTotal();
        }

        function calculateTotal() {
            let total = 0;
            const checkboxes = document.querySelectorAll('.bill-check:checked');
            checkboxes.forEach(cb => {
                total += parseInt(cb.getAttribute('data-nominal'));
            });

            document.getElementById('displayTotal').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
            return total;
        }

        function confirmPayment() {
            const total = calculateTotal();
            const balance = parseInt(document.getElementById('current_balance').value);
            const siswaId = document.querySelector('input[name="siswa_id"]').value;

            if (total === 0) {
                Swal.fire('Peringatan', 'Pilih minimal satu tagihan untuk dibayar.', 'warning');
                return;
            }

            if (total > balance) {
                Swal.fire({
                    icon: 'error',
                    title: 'Saldo Tidak Cukup',
                    text: 'Saldo siswa tidak mencukupi untuk membayar tagihan yang dipilih.',
                    footer: 'Total: Rp ' + new Intl.NumberFormat('id-ID').format(total) + ' | Saldo: Rp ' + new Intl.NumberFormat('id-ID').format(balance)
                });
                return;
            }

            // Step 1: Show confirmation
            Swal.fire({
                title: 'Konfirmasi Pembayaran',
                html: `
                    <p style="margin-bottom: 15px;">Total bayar: <strong>Rp ${new Intl.NumberFormat('id-ID').format(total)}</strong></p>
                    <p style="color: #666; font-size: 14px;">Saldo siswa akan dipotong langsung.</p>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#64748b',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Lanjutkan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Step 2: Request PIN
                    requestPinVerification(siswaId, total);
                }
            });
        }

        function requestPinVerification(siswaId, total) {
            Swal.fire({
                title: 'Verifikasi PIN Siswa',
                html: `
                    <p style="margin-bottom: 15px; color: #666; font-size: 14px;">Untuk keamanan, masukkan PIN siswa untuk melanjutkan pembayaran.</p>
                    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 15px;">
                        <input type="password" id="pin1" class="pin-input" maxlength="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                        <input type="password" id="pin2" class="pin-input" maxlength="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                        <input type="password" id="pin3" class="pin-input" maxlength="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                        <input type="password" id="pin4" class="pin-input" maxlength="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                        <input type="password" id="pin5" class="pin-input" maxlength="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                        <input type="password" id="pin6" class="pin-input" maxlength="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                    </div>
                    <style>
                        .pin-input {
                            width: 45px;
                            height: 55px;
                            text-align: center;
                            font-size: 24px;
                            font-weight: 600;
                            border: 2px solid #e2e8f0;
                            border-radius: 12px;
                            background: #f8fafc;
                            transition: all 0.2s ease;
                        }
                        .pin-input:focus {
                            outline: none;
                            border-color: #64748b;
                            box-shadow: 0 0 0 4px rgba(100, 116, 139, 0.1);
                            background: white;
                        }
                        .pin-input::-webkit-outer-spin-button,
                        .pin-input::-webkit-inner-spin-button {
                            -webkit-appearance: none;
                            margin: 0;
                        }
                    </style>
                `,
                showCancelButton: true,
                confirmButtonColor: '#64748b',
                cancelButtonColor: '#d33',
                confirmButtonText: '<i class="fas fa-check"></i> Verifikasi',
                cancelButtonText: 'Batal',
                didOpen: () => {
                    const pinInputs = document.querySelectorAll('.pin-input');
                    pinInputs[0].focus();

                    pinInputs.forEach((input, index) => {
                        input.addEventListener('input', (e) => {
                            // Only allow numbers
                            e.target.value = e.target.value.replace(/[^0-9]/g, '');

                            if (e.target.value.length === 1 && index < pinInputs.length - 1) {
                                pinInputs[index + 1].focus();
                            }
                        });

                        input.addEventListener('keydown', (e) => {
                            if (e.key === 'Backspace' && e.target.value === '' && index > 0) {
                                pinInputs[index - 1].focus();
                            }
                        });

                        input.addEventListener('paste', (e) => {
                            e.preventDefault();
                            const pasteData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                            pasteData.split('').forEach((char, i) => {
                                if (pinInputs[i]) {
                                    pinInputs[i].value = char;
                                }
                            });
                            if (pasteData.length > 0) {
                                pinInputs[Math.min(pasteData.length - 1, 5)].focus();
                            }
                        });
                    });
                },
                preConfirm: () => {
                    const pin = Array.from(document.querySelectorAll('.pin-input'))
                        .map(input => input.value)
                        .join('');

                    if (pin.length !== 6) {
                        Swal.showValidationMessage('Masukkan 6 digit PIN');
                        return false;
                    }
                    return pin;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    verifyPinAndProcess(siswaId, result.value, total);
                }
            });
        }

        function verifyPinAndProcess(siswaId, pin, total) {
            // Show loading
            Swal.fire({
                title: 'Memverifikasi PIN...',
                html: 'Mohon tunggu sebentar',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Verify PIN via AJAX
            const formData = new FormData();
            formData.append('action', 'verify_pin');
            formData.append('siswa_id', siswaId);
            formData.append('pin', pin);

            fetch('proses_pembayaran.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // PIN verified - proceed with payment
                        document.getElementById('pin_verified').value = 'true';

                        Swal.fire({
                            title: 'PIN Terverifikasi!',
                            text: 'Memproses pembayaran...',
                            icon: 'success',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            document.getElementById('paymentForm').submit();
                        });
                    } else {
                        // PIN verification failed
                        Swal.fire({
                            icon: 'error',
                            title: 'Verifikasi Gagal',
                            text: data.message,
                            confirmButtonColor: '#64748b'
                        }).then(() => {
                            // Allow retry
                            requestPinVerification(siswaId, total);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Terjadi kesalahan saat memverifikasi PIN. Silakan coba lagi.',
                        confirmButtonColor: '#64748b'
                    });
                });
        }
    </script>
    <script>
        // Page Hamburger Button functionality
        document.addEventListener('DOMContentLoaded', function () {
            var pageHamburgerBtn = document.getElementById('pageHamburgerBtn');

            if (pageHamburgerBtn) {
                pageHamburgerBtn.addEventListener('click', function () {
                    var sidebar = document.getElementById('sidebar');
                    var overlay = document.getElementById('sidebarOverlay');

                    if (sidebar && overlay) {
                        sidebar.classList.add('active');
                        overlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                });
            }
        });
    </script>
</body>

</html>