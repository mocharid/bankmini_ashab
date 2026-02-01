<?php
/**
 * Proses Cek Mutasi - Adaptive Path Version
 * File: pages/petugas/proses_cek_mutasi.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_cek_mutasi.php
 * - Hosting: public_html/pages/petugas/proses_cek_mutasi.php
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

// Restrict access to petugas/admin only
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'load_mutasi') {
        $no_rekening = $conn->real_escape_string(trim($_POST['no_rekening'] ?? ''));
        $page = isset($_POST['page']) && is_numeric($_POST['page']) ? (int) $_POST['page'] : 1;
        $start_date = isset($_POST['start_date']) && !empty($_POST['start_date']) ? $conn->real_escape_string($_POST['start_date']) : null;
        $end_date = isset($_POST['end_date']) && !empty($_POST['end_date']) ? $conn->real_escape_string($_POST['end_date']) : null;

        // Validation
        if (empty($no_rekening)) {
            echo json_encode(['success' => false, 'message' => 'Nomor rekening tidak boleh kosong']);
            exit();
        }

        if (!preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode(['success' => false, 'message' => 'Nomor rekening harus terdiri dari 8 digit']);
            exit();
        }

        if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
            echo json_encode(['success' => false, 'message' => 'Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai']);
            exit();
        }

        // Check if account exists
        $check_query = "SELECT r.*, u.nama FROM rekening r 
                        JOIN users u ON r.user_id = u.id 
                        WHERE r.no_rekening = '$no_rekening'";
        $check_result = $conn->query($check_query);

        if (!$check_result || $check_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Nomor rekening tidak ditemukan']);
            exit();
        }

        $account = $check_result->fetch_assoc();

        // Build WHERE clause
        $where_clause = "rek.no_rekening = '$no_rekening' AND (t.jenis_transaksi != 'transaksi_qr' OR t.jenis_transaksi IS NULL)";

        if ($start_date && $end_date) {
            $where_clause .= " AND DATE(m.created_at) BETWEEN '$start_date' AND '$end_date'";
        } else {
            $where_clause .= " AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }

        // Count total records
        $count_query = "SELECT COUNT(*) as total FROM mutasi m 
                        LEFT JOIN transaksi t ON m.transaksi_id = t.id 
                        LEFT JOIN rekening rek ON m.rekening_id = rek.id 
                        WHERE $where_clause";
        $count_result = $conn->query($count_query);
        $total_rows = $count_result ? $count_result->fetch_assoc()['total'] : 0;

        // Pagination
        $per_page = 15;
        $total_pages = ceil($total_rows / $per_page);
        $offset = ($page - 1) * $per_page;

        // Fungsi untuk format bulan dalam bahasa Indonesia (tanpa waktu)
        function formatTanggalIndonesia($datetime)
        {
            $bulan = [
                1 => 'Jan',
                2 => 'Feb',
                3 => 'Mar',
                4 => 'Apr',
                5 => 'Mei',
                6 => 'Jun',
                7 => 'Jul',
                8 => 'Agu',
                9 => 'Sep',
                10 => 'Okt',
                11 => 'Nov',
                12 => 'Des'
            ];
            $timestamp = strtotime($datetime);
            $hari = date('d', $timestamp);
            $bln = (int) date('m', $timestamp);
            $tahun = date('Y', $timestamp);
            return $hari . ' ' . $bulan[$bln] . ' ' . $tahun;
        }

        // Fetch mutations
        $query = "SELECT m.id, m.jumlah, m.saldo_akhir, m.created_at, m.rekening_id,
                  t.jenis_transaksi, t.no_transaksi, t.status, t.keterangan,
                  t.rekening_id as transaksi_rekening_id, t.rekening_tujuan_id,
                  u_petugas.nama as petugas_nama
                  FROM mutasi m 
                  LEFT JOIN transaksi t ON m.transaksi_id = t.id 
                  LEFT JOIN rekening rek ON m.rekening_id = rek.id 
                  LEFT JOIN users u_petugas ON t.petugas_id = u_petugas.id
                  WHERE $where_clause 
                  ORDER BY m.created_at DESC 
                  LIMIT $offset, $per_page";

        $result = $conn->query($query);

        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengambil data: ' . $conn->error]);
            exit();
        }

        // Build HTML output
        ob_start();


        if ($result->num_rows > 0) {
            echo '<div class="card" id="resultsTable">';
            echo '<div class="card-header">';
            echo '<div class="card-header-icon"><i class="fas fa-list"></i></div>';
            echo '<div class="card-header-text">';
            echo '<h3>Mutasi Rekening: ' . htmlspecialchars($account['nama']) . '</h3>';
            echo '<p>Ditemukan ' . $total_rows . ' transaksi</p>';
            echo '</div>';
            // Optional: keep badge if needed, or just put in text
            // echo '<div class="badge badge-success">' . $total_rows . ' Transaksi</div>';
            echo '</div>';

            echo '<div class="card-body p-0" style="padding:0;">';
            echo '<table class="data-table"><thead><tr>';
            echo '<th>Tanggal</th><th>Jenis Transaksi</th><th>No. Transaksi</th><th>Keterangan</th>';
            echo '<th style="text-align:right;">Debit</th><th style="text-align:right;">Kredit</th><th style="text-align:right;">Saldo Akhir</th>';
            echo '</tr></thead><tbody>';

            while ($row = $result->fetch_assoc()) {
                $is_debit = false;
                $jenis_text = '';

                if ($row['jenis_transaksi'] === 'transfer') {
                    $is_debit = ($row['rekening_id'] == $row['transaksi_rekening_id']);
                    $jenis_text = $is_debit ? 'Transfer Keluar' : 'Transfer Masuk';
                } elseif ($row['jenis_transaksi'] === null) {
                    $jenis_text = 'Transfer Masuk';
                } else {
                    $jenis_text = ucfirst(str_replace('_', ' ', $row['jenis_transaksi']));
                    switch ($row['jenis_transaksi']) {
                        case 'setor':
                            $is_debit = false;
                            break;
                        case 'tarik':
                            $is_debit = true;
                            break;
                        default:
                            $is_debit = true;
                    }
                }

                $status = $row['status'] ?? 'approved';

                // Hitung debit dan kredit
                $debit = $is_debit ? number_format(abs($row['jumlah']), 0, ',', '.') : '-';
                $kredit = !$is_debit ? number_format(abs($row['jumlah']), 0, ',', '.') : '-';

                echo '<tr>';
                echo '<td>' . formatTanggalIndonesia($row['created_at']) . '</td>';
                echo '<td>' . htmlspecialchars($jenis_text) . '</td>';
                echo '<td style="font-family: monospace; color:var(--gray-600);">' . htmlspecialchars($row['no_transaksi'] ?? '-') . '</td>';
                echo '<td style="color:var(--gray-600);">' . htmlspecialchars($row['keterangan'] ?? '-') . '</td>';
                echo '<td style="text-align:right;" class="amount-debit">' . $debit . '</td>';
                echo '<td style="text-align:right;" class="amount-credit">' . $kredit . '</td>';
                echo '<td style="text-align:right; font-weight:600;">Rp ' . number_format($row['saldo_akhir'], 0, ',', '.') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';

            if ($total_pages > 1) {
                echo '<div class="card-body" style="border-top: 1px solid var(--gray-200); padding: 1rem;">';
                echo '<div class="pagination" style="margin-top:0;">';

                // Previous <
                if ($page > 1) {
                    echo '<a href="#" class="pagination-link" data-page="' . ($page - 1) . '"><i class="fas fa-chevron-left"></i></a>';
                } else {
                    echo '<span class="pagination-link disabled" style="opacity:0.5; cursor:not-allowed;"><i class="fas fa-chevron-left"></i></span>';
                }

                // Current page number
                echo '<span class="pagination-link active">' . $page . '</span>';

                // Next >
                if ($page < $total_pages) {
                    echo '<a href="#" class="pagination-link" data-page="' . ($page + 1) . '"><i class="fas fa-chevron-right"></i></a>';
                } else {
                    echo '<span class="pagination-link disabled" style="opacity:0.5; cursor:not-allowed;"><i class="fas fa-chevron-right"></i></span>';
                }

                echo '</div></div>';
            }

            echo '</div>'; // end card
        } else {
            echo '<div class="card" id="resultsTable">';
            echo '<div class="card-body empty-state">';
            echo '<i class="fas fa-inbox empty-icon"></i>';
            echo '<p class="empty-text">Tidak ada transaksi dalam periode ini</p>';
            echo '</div></div>';
        }

        $html = ob_get_clean();

        echo json_encode([
            'success' => true,
            'html' => $html,
            'total_rows' => $total_rows,
            'current_page' => $page,
            'total_pages' => $total_pages
        ]);
        exit();
    }
}

// Invalid request
echo json_encode(['success' => false, 'message' => 'Request tidak valid']);
exit();
?>