<?php
// Start session
session_start();

// Include database connection
require_once '../../includes/db_connection.php';

// Set response header
header('Content-Type: application/json');

// Get the action
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Process based on action
switch ($action) {
    case 'cek_rekening':
        checkRekening($conn);
        break;
    case 'tarik_tunai':
        processWithdrawal($conn);
        break;
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid action'
        ]);
        break;
}

/**
 * Check if the account exists and return customer details
 */
function checkRekening($conn) {
    $no_rekening = isset($_POST['no_rekening']) ? $_POST['no_rekening'] : '';
    
    if (empty($no_rekening)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Nomor rekening tidak boleh kosong'
        ]);
        return;
    }
    
    // Prepare a statement to prevent SQL injection
    $stmt = $conn->prepare("
        SELECT r.no_rekening, u.nama, r.saldo, r.id as rekening_id, u.id as user_id
        FROM rekening r
        JOIN users u ON r.user_id = u.id
        WHERE r.no_rekening = ?
    ");
    
    $stmt->bind_param("s", $no_rekening);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Rekening tidak ditemukan'
        ]);
        return;
    }
    
    $account = $result->fetch_assoc();
    echo json_encode([
        'status' => 'success',
        'no_rekening' => $account['no_rekening'],
        'nama' => $account['nama'],
        'saldo' => $account['saldo'],
        'rekening_id' => $account['rekening_id'],
        'user_id' => $account['user_id']
    ]);
}

/**
 * Process the withdrawal transaction
 */
function processWithdrawal($conn) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        $no_rekening = isset($_POST['no_rekening']) ? $_POST['no_rekening'] : '';
        $jumlah = isset($_POST['jumlah']) ? floatval($_POST['jumlah']) : 0;
        
        // Get a valid petugas_id, either from session or from database
        if (isset($_SESSION['user_id'])) {
            $petugas_id = $_SESSION['user_id'];
            
            // Verify the petugas_id exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->bind_param("i", $petugas_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Session has invalid user_id, get a valid one from database
                $result = $conn->query("SELECT id FROM users LIMIT 1");
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    $petugas_id = $user['id'];
                } else {
                    throw new Exception("No valid users found in database");
                }
            }
        } else {
            // No session user_id, get a valid one from database
            $result = $conn->query("SELECT id FROM users LIMIT 1");
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $petugas_id = $user['id'];
            } else {
                throw new Exception("No valid users found in database");
            }
        }
        
        // Validate input
        if (empty($no_rekening) || $jumlah <= 0) {
            throw new Exception("Data tidak valid");
        }
        
        // Minimum withdrawal amount
        if ($jumlah < 10000) {
            throw new Exception("Jumlah penarikan minimal Rp 10.000");
        }
        
        // Get account details
        $stmt = $conn->prepare("
            SELECT r.id as rekening_id, r.saldo, u.id as user_id
            FROM rekening r
            JOIN users u ON r.user_id = u.id
            WHERE r.no_rekening = ?
        ");
        
        $stmt->bind_param("s", $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Rekening tidak ditemukan");
        }
        
        $account = $result->fetch_assoc();
        
        // Check if balance is sufficient
        if ($account['saldo'] < $jumlah) {
            throw new Exception("Saldo tidak mencukupi");
        }
        
        $rekening_id = $account['rekening_id'];
        $user_id = $account['user_id'];
        $saldo_awal = $account['saldo'];
        $saldo_akhir = $saldo_awal - $jumlah;
        
        // Generate transaction number
        $no_transaksi = 'TRX-' . date('YmdHis') . '-' . rand(1000, 9999);
        
        // Insert transaction record
        $stmt = $conn->prepare("
            INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status)
            VALUES (?, ?, 'tarik', ?, ?, 'approved')
        ");
        
        $stmt->bind_param("sidi", $no_transaksi, $rekening_id, $jumlah, $petugas_id);
        $stmt->execute();
        $transaksi_id = $conn->insert_id;
        
        // Update account balance
        $stmt = $conn->prepare("
            UPDATE rekening
            SET saldo = saldo - ?
            WHERE id = ?
        ");
        
        $stmt->bind_param("di", $jumlah, $rekening_id);
        $stmt->execute();
        
        // Insert mutation record
        $stmt = $conn->prepare("
            INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->bind_param("iidd", $transaksi_id, $rekening_id, $jumlah, $saldo_akhir);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Penarikan tunai berhasil',
            'data' => [
                'no_transaksi' => $no_transaksi,
                'jumlah' => $jumlah,
                'saldo_awal' => $saldo_awal,
                'saldo_akhir' => $saldo_akhir
            ]
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}

// Close database connection
$conn->close();
?>