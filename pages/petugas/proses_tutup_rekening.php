<?php
/**
 * Proses Tutup Rekening - Adaptive Path Version
 * File: pages/petugas/proses_tutup_rekening.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_tutup_rekening.php
 * - Hosting: public_html/pages/petugas/proses_tutup_rekening.php
 */
// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: naik max 5 level cari includes/
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
// Fallback: pakai current dir
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
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';

// Validate session and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../login.php');
    exit();
}

// Function to mask no_rekening (e.g., 01234567 -> 012****7)
function maskNoRekening($no_rekening)
{
    if (empty($no_rekening) || strlen($no_rekening) < 5) {
        return '-';
    }
    return substr($no_rekening, 0, 3) . '****' . substr($no_rekening, -1);
}

// Function to mask username (e.g., abcd -> ab**d)
function maskUsername($username)
{
    if (empty($username) || strlen($username) < 3) {
        return $username ?: '-';
    }
    $masked = substr($username, 0, 2);
    $masked .= str_repeat('*', strlen($username) - 3);
    $masked .= substr($username, -1);
    return $masked;
}

// Indonesian month names
$bulan_indonesia = [
    'January' => 'Januari',
    'February' => 'Februari',
    'March' => 'Maret',
    'April' => 'April',
    'May' => 'Mei',
    'June' => 'Juni',
    'July' => 'Juli',
    'August' => 'Agustus',
    'September' => 'September',
    'October' => 'Oktober',
    'November' => 'November',
    'December' => 'Desember'
];

// Handle AJAX check rekening
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_rekening' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $no_rekening = trim($_POST['no_rekening'] ?? '');

    if (empty($no_rekening) || !preg_match('/^\d{8}$/', $no_rekening)) {
        echo json_encode(['success' => false, 'message' => 'Nomor rekening harus 8 digit angka.']);
        exit();
    }

    // Fetch rekening and user details
    $stmt = $conn->prepare("
        SELECT u.id AS user_id, u.nama, u.username, u.email,
               sp.nis_nisn, sp.alamat_lengkap, sp.jenis_kelamin, sp.tanggal_lahir,
               r.id AS rekening_id, r.no_rekening, r.saldo,
               j.nama_jurusan,
               CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas,
               COALESCE(us.is_frozen, 0) AS is_frozen
        FROM rekening r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
        LEFT JOIN user_security us ON u.id = us.user_id
        LEFT JOIN jurusan j ON sp.jurusan_id = j.id
        LEFT JOIN kelas k ON sp.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        WHERE r.no_rekening = ? AND u.role = 'siswa'
    ");
    $stmt->bind_param("s", $no_rekening);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Rekening tidak ditemukan!']);
        $stmt->close();
        exit();
    }

    $data = $result->fetch_assoc();
    $stmt->close();

    // Format additional fields
    $data['jenis_kelamin_display'] = $data['jenis_kelamin'] ? ($data['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan') : '-';
    $data['tanggal_lahir_display'] = $data['tanggal_lahir'] ? strtr(date('d F Y', strtotime($data['tanggal_lahir'])), $bulan_indonesia) : '-';
    $data['alamat_lengkap'] = $data['alamat_lengkap'] ?: '-';
    $data['nis_nisn'] = $data['nis_nisn'] ?: '-';
    $data['email'] = $data['email'] ?: '-';
    $data['username_display'] = maskUsername($data['username']);
    $data['nama_jurusan'] = $data['nama_jurusan'] ?? '-';
    $data['nama_kelas'] = $data['nama_kelas'] ?? '-';
    $data['no_rekening_display'] = $data['no_rekening'];

    // Check conditions
    $errors = [];
    $has_saldo = false;
    if ($data['saldo'] != 0) {
        $has_saldo = true;
        $errors[] = 'Saldo masih tersisa Rp ' . number_format($data['saldo'], 0, ',', '.') . '. Silakan tarik sisa saldo terlebih dahulu.';
    }
    if ($data['is_frozen']) {
        $errors[] = 'Akun sedang dibekukan.';
    }

    // Additional check: no pending transaksi
    $stmt_pending = $conn->prepare("SELECT COUNT(*) as count FROM transaksi WHERE (rekening_id = ? OR rekening_tujuan_id = ?) AND status = 'pending'");
    $stmt_pending->bind_param("ii", $data['rekening_id'], $data['rekening_id']);
    $stmt_pending->execute();
    $pending = $stmt_pending->get_result()->fetch_assoc()['count'];
    $stmt_pending->close();

    if ($pending > 0) {
        $errors[] = 'Ada ' . $pending . ' transaksi pending.';
    }

    $can_close = empty($errors);

    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => $data['user_id'],
            'nama' => $data['nama'],
            'username' => $data['username_display'],
            'email' => $data['email'],
            'alamat_lengkap' => $data['alamat_lengkap'],
            'nis_nisn' => $data['nis_nisn'],
            'jenis_kelamin_display' => $data['jenis_kelamin_display'],
            'tanggal_lahir_display' => $data['tanggal_lahir_display'],
            'no_rekening' => $data['no_rekening'],
            'no_rekening_display' => $data['no_rekening_display'],
            'nama_jurusan' => $data['nama_jurusan'],
            'nama_kelas' => $data['nama_kelas'],
            'saldo' => $data['saldo'],
            'is_frozen' => $data['is_frozen']
        ],
        'can_close' => $can_close,
        'has_saldo' => $has_saldo,
        'errors' => $errors,
        'rekening_id' => $data['rekening_id']
    ]);
    exit();
}

// Handle AJAX close rekening
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_rekening' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $pin = trim($_POST['pin'] ?? '');
    $no_rekening = trim($_POST['no_rekening'] ?? '');

    if ($user_id <= 0 || empty($pin)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit();
    }

    if (!preg_match('/^\d{6}$/', $pin)) {
        echo json_encode(['success' => false, 'message' => 'PIN harus 6 digit angka.']);
        exit();
    }

    $conn->begin_transaction();
    try {
        // Verify PIN from user_security table
        $stmt = $conn->prepare("SELECT us.pin FROM user_security us JOIN users u ON us.user_id = u.id WHERE u.id = ? AND u.role = 'siswa'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception("User tidak ditemukan.");
        }

        $user_security = $result->fetch_assoc();
        $stmt->close();

        if (empty($user_security['pin'])) {
            throw new Exception("Gagal menutup rekening. PIN siswa belum diatur.");
        }

        // Verify PIN (using password_verify to match transfer page)
        if (!password_verify($pin, $user_security['pin'])) {
            throw new Exception("Gagal menutup rekening. PIN tidak sesuai.");
        }

        $stmt = $conn->prepare("SELECT r.id AS rekening_id, r.saldo, us.is_frozen FROM users u JOIN rekening r ON u.id = r.user_id JOIN user_security us ON u.id = us.user_id WHERE u.id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($data['saldo'] != 0) {
            throw new Exception("Gagal menutup rekening. Saldo masih tersisa Rp " . number_format($data['saldo'], 0, ',', '.') . ". Silakan tarik saldo terlebih dahulu.");
        }

        if ($data['is_frozen']) {
            throw new Exception("Gagal menutup rekening. Akun sedang dibekukan.");
        }

        $rekening_id = $data['rekening_id'];

        $stmt_pending = $conn->prepare("SELECT COUNT(*) as count FROM transaksi WHERE (rekening_id = ? OR rekening_tujuan_id = ?) AND status = 'pending'");
        $stmt_pending->bind_param("ii", $rekening_id, $rekening_id);
        $stmt_pending->execute();
        $pending_result = $stmt_pending->get_result()->fetch_assoc();
        $stmt_pending->close();

        if ($pending_result['count'] > 0) {
            throw new Exception("Gagal menutup rekening. Ada transaksi pending.");
        }

        $stmt = $conn->prepare("DELETE m FROM mutasi m JOIN transaksi t ON m.transaksi_id = t.id WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?");
        $stmt->bind_param("ii", $rekening_id, $rekening_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?");
        $stmt->bind_param("ii", $rekening_id, $rekening_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM rekening WHERE id = ?");
        $stmt->bind_param("i", $rekening_id);
        $stmt->execute();
        $stmt->close();

        $tables = [
            'account_freeze_log' => 'siswa_id',
            'log_aktivitas' => 'siswa_id',
            'notifications' => 'user_id',
            'reset_request_cooldown' => 'user_id',
            'password_reset' => 'user_id'
        ];

        foreach ($tables as $table => $column) {
            if ($conn->query("SHOW TABLES LIKE '$table'")->num_rows > 0) {
                $stmt = $conn->prepare("DELETE FROM $table WHERE $column = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("DELETE FROM user_security WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM siswa_profiles WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Exception("Gagal menghapus user.");
        }
        $stmt->close();

        $conn->commit();

        echo json_encode(['success' => true, 'message' => "Rekening $no_rekening berhasil ditutup."]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}
?>