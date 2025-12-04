<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/session_validator.php';

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to petugas/admin only
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit();
}

// Function to format date in Indonesian
function formatTanggalIndonesia($date) {
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $split = explode('-', date('Y-n-j', strtotime($date)));
    return $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'load_mutasi') {
        $no_rekening = $conn->real_escape_string(trim($_POST['no_rekening'] ?? ''));
        $page = isset($_POST['page']) && is_numeric($_POST['page']) ? (int)$_POST['page'] : 1;
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
        
        // Fetch mutations
        $query = "SELECT m.id, m.jumlah, m.saldo_akhir, m.created_at, m.rekening_id,
                  t.jenis_transaksi, t.no_transaksi, t.status, t.keterangan,
                  t.rekening_id as transaksi_rekening_id, t.rekening_tujuan_id,
                  u_petugas.nama as petugas_nama,
                  DATE_FORMAT(m.created_at, '%d %b %Y, %H:%i') as formatted_date
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
            echo '<div class="table-container" id="resultsTable">';
            echo '<div class="table-header">';
            echo '<div class="table-title">Mutasi Rekening: ' . htmlspecialchars($account['nama']) . '</div>';
            echo '<div class="table-count">' . $total_rows . ' Transaksi</div>';
            echo '</div>';
            echo '<div class="table-wrapper">';
            echo '<table><thead><tr>';
            echo '<th>Tanggal & Waktu</th><th>Jenis Transaksi</th><th>No. Transaksi</th><th>Keterangan</th>';
            echo '<th style="text-align:right;">Jumlah</th><th>Status</th><th style="text-align:right;">Saldo Akhir</th>';
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
                        case 'setor': $is_debit = false; break;
                        case 'tarik': $is_debit = true; break;
                        default: $is_debit = true;
                    }
                }
                
                $status = $row['status'] ?? 'approved';
                $status_class = $status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : 'pending');
                $status_text = $status === 'approved' ? 'Berhasil' : ($status === 'rejected' ? 'Ditolak' : 'Menunggu');
                
                $amountClass = $is_debit ? 'amount-debit' : 'amount-credit';
                $amountSign = $is_debit ? '- ' : '+ ';
                
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['formatted_date']) . ' WIB</td>';
                echo '<td><span class="transaction-type">' . htmlspecialchars($jenis_text) . '</span></td>';
                echo '<td style="font-family: monospace; font-size:0.78rem; color:#6b7280;">' . htmlspecialchars($row['no_transaksi'] ?? '-') . '</td>';
                echo '<td style="color:#6b7280;">' . htmlspecialchars($row['keterangan'] ?? '-') . '</td>';
                echo '<td class="' . $amountClass . '" style="text-align:right;">' . $amountSign . 'Rp ' . number_format(abs($row['jumlah']), 0, ',', '.') . '</td>';
                echo '<td><span class="status-badge ' . $status_class . '">' . $status_text . '</span></td>';
                echo '<td style="text-align:right; font-weight:600;">Rp ' . number_format($row['saldo_akhir'], 0, ',', '.') . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table></div>';
            
            // Pagination
            if ($total_pages > 1) {
                echo '<div class="pagination">';
                
                // Previous <
                if ($page > 1) {
                    echo '<a href="#" data-page="' . ($page - 1) . '">&lt;</a>';
                } else {
                    echo '<span style="color:#ccc;">&lt;</span>';
                }
                
                // Current page number
                echo '<span class="current-page">' . $page . '</span>';
                
                // Next >
                if ($page < $total_pages) {
                    echo '<a href="#" data-page="' . ($page + 1) . '">&gt;</a>';
                } else {
                    echo '<span style="color:#ccc;">&gt;</span>';
                }
                
                echo '</div>';
            }
            
            echo '</div>';
        } else {
            echo '<div class="table-container" id="resultsTable">';
            echo '<div class="no-data">';
            echo '<i class="fas fa-inbox"></i>';
            echo '<p>Tidak ada transaksi dalam periode ini</p>';
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
