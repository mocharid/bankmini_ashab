<?php
/**
 * Proses Validasi Transaksi - Adaptive Path Version
 * File: pages/petugas/proses_validasi_transaksi.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_validasi_transaksi.php
 * - Hosting: public_html/pages/petugas/proses_validasi_transaksi.php
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

// Validasi autentikasi
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'load_transaction') {
    $no_transaksi = trim($_POST['no_transaksi'] ?? '');
    
    if (empty($no_transaksi) || strlen($no_transaksi) > 20) {
        echo json_encode(['success' => false, 'message' => 'No Transaksi tidak valid. Maksimum 20 karakter.']);
        exit;
    }

    $query = "SELECT 
                t.id,
                t.no_transaksi,
                t.id_transaksi,
                t.jenis_transaksi,
                t.jumlah,
                t.created_at,
                t.keterangan,
                t.status,
                t.rekening_id,
                t.rekening_tujuan_id,
                t.petugas_id,
                t.pending_reason,
                r1.no_rekening AS rekening_asal, 
                u1.nama AS nama_nasabah, 
                sp1.jurusan_id AS jurusan_id_asal,
                sp1.kelas_id AS kelas_id_asal,
                j1.nama_jurusan AS jurusan_asal, 
                k1.nama_kelas AS kelas_asal,
                tk1.nama_tingkatan AS tingkatan_asal,
                r2.no_rekening AS rekening_tujuan,
                u2.nama AS nama_penerima,
                sp2.jurusan_id AS jurusan_id_tujuan,
                sp2.kelas_id AS kelas_id_tujuan,
                j2.nama_jurusan AS jurusan_tujuan,
                k2.nama_kelas AS kelas_tujuan,
                tk2.nama_tingkatan AS tingkatan_tujuan,
                up.nama AS petugas_nama
              FROM 
                transaksi t
              JOIN 
                rekening r1 ON t.rekening_id = r1.id
              JOIN 
                users u1 ON r1.user_id = u1.id
              JOIN 
                siswa_profiles sp1 ON u1.id = sp1.user_id
              LEFT JOIN 
                jurusan j1 ON sp1.jurusan_id = j1.id
              LEFT JOIN 
                kelas k1 ON sp1.kelas_id = k1.id
              LEFT JOIN 
                tingkatan_kelas tk1 ON k1.tingkatan_kelas_id = tk1.id
              LEFT JOIN 
                rekening r2 ON t.rekening_tujuan_id = r2.id
              LEFT JOIN 
                users u2 ON r2.user_id = u2.id
              LEFT JOIN 
                siswa_profiles sp2 ON u2.id = sp2.user_id
              LEFT JOIN 
                jurusan j2 ON sp2.jurusan_id = j2.id
              LEFT JOIN 
                kelas k2 ON sp2.kelas_id = k2.id
              LEFT JOIN 
                tingkatan_kelas tk2 ON k2.tingkatan_kelas_id = tk2.id
              LEFT JOIN 
                users up ON t.petugas_id = up.id
              WHERE 
                t.no_transaksi = ? OR t.id_transaksi = ?
              LIMIT 1";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . htmlspecialchars($conn->error)]);
        exit;
    }

    $stmt->bind_param("ss", $no_transaksi, $no_transaksi);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . htmlspecialchars($stmt->error)]);
        $stmt->close();
        exit;
    }

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No Transaksi Tidak Ditemukan']);
        $stmt->close();
        exit;
    }

    $row = $result->fetch_assoc();

    // Fungsi helper
    function formatTanggalLengkap($date) {
        if (empty($date)) return '-';
        
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        
        try {
            $datetime = new DateTime($date);
            return $datetime->format('d') . ' ' . $bulan[(int)$datetime->format('m')] . ' ' . $datetime->format('Y H:i') . ' WIB';
        } catch (Exception $e) {
            return '-';
        }
    }

    function clean($data) {
        return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
    }

    // Proses data
    $badgeClass = '';
    $transactionIcon = '';
    $transactionDisplay = '';
    
    switch ($row['jenis_transaksi']) {
        case 'setor':
            $badgeClass = 'badge-setor';
            $transactionIcon = '<i class="fas fa-arrow-circle-down" style="color: #10b981;"></i>';
            $transactionDisplay = 'SETOR';
            break;
        case 'tarik':
            $badgeClass = 'badge-tarik';
            $transactionIcon = '<i class="fas fa-arrow-circle-up" style="color: #f59e0b;"></i>';
            $transactionDisplay = 'TARIK';
            break;
        case 'transfer':
            $badgeClass = 'badge-transfer';
            $transactionIcon = '<i class="fas fa-exchange-alt" style="color: #3b82f6;"></i>';
            $transactionDisplay = 'TRANSFER';
            break;
        case 'transaksi_qr':
            $badgeClass = 'badge-transaksi_qr';
            $transactionIcon = '<i class="fas fa-qrcode" style="color: #9333ea;"></i>';
            $transactionDisplay = 'PEMBAYARAN QR';
            break;
        default:
            $badgeClass = 'badge-transfer';
            $transactionIcon = '<i class="fas fa-receipt" style="color: #3b82f6;"></i>';
            $transactionDisplay = strtoupper($row['jenis_transaksi']);
    }
    
    $statusBadgeClass = '';
    $status_display = '';
    $statusClass = '';
    
    switch ($row['status']) {
        case 'approved':
            $statusBadgeClass = 'badge-approved';
            $status_display = 'DISETUJUI';
            $statusClass = 'status-approved';
            break;
        case 'pending':
            $statusBadgeClass = 'badge-pending';
            $status_display = 'MENUNGGU';
            $statusClass = 'status-pending';
            break;
        case 'rejected':
            $statusBadgeClass = 'badge-rejected';
            $status_display = 'DITOLAK';
            $statusClass = 'status-rejected';
            break;
        default:
            $statusBadgeClass = 'badge-pending';
            $status_display = strtoupper($row['status']);
            $statusClass = 'status-pending';
    }
    
    $show_petugas = !in_array($row['jenis_transaksi'], ['transfer', 'transaksi_qr']);
    $petugas_display = '';
    
    if ($show_petugas) {
        if (!empty($row['petugas_nama'])) {
            $petugas_display = clean($row['petugas_nama']);
        } else {
            $petugas_display = '-';
        }
    }
    
    $kelas_asal_display = ($row['tingkatan_asal'] && $row['kelas_asal']) 
        ? clean($row['tingkatan_asal'] . ' ' . $row['kelas_asal']) 
        : '-';
    $kelas_tujuan_display = ($row['tingkatan_tujuan'] && $row['kelas_tujuan']) 
        ? clean($row['tingkatan_tujuan'] . ' ' . $row['kelas_tujuan']) 
        : '-';

    // Format ID Transaksi untuk display
    $id_transaksi_display = !empty($row['id_transaksi']) ? clean($row['id_transaksi']) : 'TRX-' . str_pad($row['id'], 8, '0', STR_PAD_LEFT);

    // Bangun HTML hasil
    $html = '<div class="results-card ' . $statusClass . '" id="transactionResult">
                <div class="section-title">
                    ' . $transactionIcon . ' Rincian Transaksi
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">ID Transaksi</div>
                    <div class="detail-value" style="font-weight: 700; color: #1e3a8a;">' . $id_transaksi_display . '</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">No Transaksi</div>
                    <div class="detail-value">' . clean($row['no_transaksi']) . '</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Tipe Transaksi</div>
                    <div class="detail-value">
                        <span class="badge ' . $badgeClass . '">' . $transactionDisplay . '</span>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Nominal</div>
                    <div class="detail-value amount-positive">Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Waktu Transaksi</div>
                    <div class="detail-value">' . formatTanggalLengkap($row['created_at']) . '</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Keterangan</div>
                    <div class="detail-value">' . clean($row['keterangan'] ?: '-') . '</div>
                </div>';
    
    if ($show_petugas) {
        $html .= '<div class="detail-row">
                    <div class="detail-label">Petugas</div>
                    <div class="detail-value">' . $petugas_display . '</div>
                  </div>';
    }
    
    $html .= '<div class="detail-row">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <span class="badge ' . $statusBadgeClass . '">' . $status_display . '</span>
                </div>
              </div>';
    
    // Tampilkan pending reason jika ada
    if ($row['status'] === 'pending' && !empty($row['pending_reason'])) {
        $html .= '<div class="detail-row">
                    <div class="detail-label">Alasan Pending</div>
                    <div class="detail-value">' . clean($row['pending_reason']) . '</div>
                  </div>';
    }
    
    $html .= '<div class="detail-divider"></div>
              
              <div class="detail-subheading">
                  <i class="fas fa-wallet"></i> Rekening Sumber
              </div>
              
              <div class="detail-row">
                  <div class="detail-label">No Rekening</div>
                  <div class="detail-value">' . clean($row['rekening_asal']) . '</div>
              </div>
              
              <div class="detail-row">
                  <div class="detail-label">Nama Nasabah</div>
                  <div class="detail-value">' . clean($row['nama_nasabah']) . '</div>
              </div>
              
              <div class="detail-row">
                  <div class="detail-label">Jurusan</div>
                  <div class="detail-value">' . clean($row['jurusan_asal'] ?: '-') . '</div>
              </div>
              
              <div class="detail-row">
                  <div class="detail-label">Kelas</div>
                  <div class="detail-value">' . $kelas_asal_display . '</div>
              </div>';
    
    if (in_array($row['jenis_transaksi'], ['transfer', 'transaksi_qr']) && !empty($row['rekening_tujuan_id'])) {
        $html .= '<div class="detail-divider"></div>
                  
                  <div class="detail-subheading">
                      <i class="fas fa-arrow-right"></i> Rekening Tujuan
                  </div>
                  
                  <div class="detail-row">
                      <div class="detail-label">No Rekening</div>
                      <div class="detail-value">' . clean($row['rekening_tujuan'] ?: '-') . '</div>
                  </div>
                  
                  <div class="detail-row">
                      <div class="detail-label">Nama Penerima</div>
                      <div class="detail-value">' . clean($row['nama_penerima'] ?: '-') . '</div>
                  </div>
                  
                  <div class="detail-row">
                      <div class="detail-label">Jurusan</div>
                      <div class="detail-value">' . clean($row['jurusan_tujuan'] ?: '-') . '</div>
                  </div>
                  
                  <div class="detail-row">
                      <div class="detail-label">Kelas</div>
                      <div class="detail-value">' . $kelas_tujuan_display . '</div>
                  </div>';
    }
    
    if ($row['status'] === 'approved') {
        $html .= '<div class="info-box">
                    <i class="fas fa-check-circle"></i>
                    <span>Transaksi telah disetujui dan berhasil diproses. ID Transaksi: ' . $id_transaksi_display . '</span>
                  </div>';
    } elseif ($row['status'] === 'rejected') {
        $html .= '<div class="error-box">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Transaksi telah ditolak oleh sistem. ID Transaksi: ' . $id_transaksi_display . '</span>
                  </div>';
    } else {
        $html .= '<div class="warning-box">
                    <i class="fas fa-clock"></i>
                    <span>Transaksi menunggu verifikasi dari petugas. ID Transaksi: ' . $id_transaksi_display . '</span>
                  </div>';
    }
    
    // Tombol aksi hanya untuk admin dan transaksi yang belum approved
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && $row['status'] !== 'approved') {
        $html .= '<div class="action-buttons">
                    <button class="btn btn-success" onclick="approveTransaction(' . $row['id'] . ')">
                        <i class="fas fa-check"></i> Setujui
                    </button>
                    <button class="btn btn-danger" onclick="rejectTransaction(' . $row['id'] . ')">
                        <i class="fas fa-times"></i> Tolak
                    </button>
                  </div>';
    }
    
    $html .= '</div>';

    echo json_encode(['success' => true, 'html' => $html]);
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Aksi tidak valid.']);
}
?>