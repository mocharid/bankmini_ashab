<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'cek_rekening':
            handleCheckAccount();
            break;
        case 'verify_pin':
            handleVerifyPin();
            break;
        case 'tarik_tunai':
            handleWithdrawal();
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

function handleCheckAccount() {
    global $pdo;
    
    $no_rekening = $_POST['no_rekening'] ?? '';
    
    if (empty($no_rekening)) {
        echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak boleh kosong']);
        return;
    }
    
    // Check if account exists
    $stmt = $pdo->prepare("SELECT r.id as rekening_id, r.no_rekening, r.saldo, u.id as user_id, u.nama, u.pin, u.has_pin, u.email 
                          FROM rekening r 
                          JOIN users u ON r.user_id = u.id 
                          WHERE r.no_rekening = ?");
    $stmt->execute([$no_rekening]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        echo json_encode(['status' => 'error', 'message' => 'Rekening tidak ditemukan']);
        return;
    }
    
    // Return account data
    echo json_encode([
        'status' => 'success',
        'no_rekening' => $account['no_rekening'],
        'nama' => $account['nama'],
        'saldo' => $account['saldo'],
        'rekening_id' => $account['rekening_id'],
        'user_id' => $account['user_id'],
        'email' => $account['email'],
        'has_pin' => (bool)$account['has_pin']
    ]);
}

function handleVerifyPin() {
    global $pdo;
    
    $user_id = $_POST['user_id'] ?? 0;
    $pin = $_POST['pin'] ?? '';
    
    if (empty($user_id) || $user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'User ID tidak valid']);
        return;
    }
    
    if (strlen($pin) !== 6) {
        echo json_encode(['status' => 'error', 'message' => 'PIN harus 6 digit']);
        return;
    }
    
    // Get user's PIN
    $stmt = $pdo->prepare("SELECT pin FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan']);
        return;
    }
    
    // Verify PIN
    if (!password_verify($pin, $user['pin'])) {
        echo json_encode(['status' => 'error', 'message' => 'PIN salah']);
        return;
    }
    
    echo json_encode(['status' => 'success', 'message' => 'PIN valid']);
}

function handleWithdrawal() {
    global $pdo;
    
    $no_rekening = $_POST['no_rekening'] ?? '';
    $jumlah = (float)($_POST['jumlah'] ?? 0);
    $petugas_id = $_SESSION['user_id'] ?? 0;
    
    if (empty($no_rekening)) {
        echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak boleh kosong']);
        return;
    }
    
    if ($jumlah <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan tidak valid']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Get account info (nasabah)
        $stmt = $pdo->prepare("SELECT r.id, r.saldo, r.user_id FROM rekening r WHERE r.no_rekening = ? FOR UPDATE");
        $stmt->execute([$no_rekening]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Rekening tidak ditemukan']);
            return;
        }
        
        // 2. Check if balance is sufficient
        if ($account['saldo'] < $jumlah) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Saldo tidak mencukupi']);
            return;
        }
        
        // 3. Generate transaction number
        $no_transaksi = 'TRX-' . time() . '-' . rand(100, 999);
        
        // 4. Create withdrawal transaction (tanpa mempengaruhi rekening admin)
        $stmt = $pdo->prepare("INSERT INTO transaksi 
                              (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status) 
                              VALUES (?, ?, 'tarik', ?, ?, 'approved')");
        $stmt->execute([$no_transaksi, $account['id'], $jumlah, $petugas_id]);
        $transaksi_id = $pdo->lastInsertId();
        
        // 5. Update nasabah's balance (decrease)
        $new_nasabah_balance = $account['saldo'] - $jumlah;
        $stmt = $pdo->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
        $stmt->execute([$new_nasabah_balance, $account['id']]);
        
        // 6. Add mutation record for nasabah
        $stmt = $pdo->prepare("INSERT INTO mutasi 
                              (transaksi_id, rekening_id, jumlah, saldo_akhir) 
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$transaksi_id, $account['id'], -$jumlah, $new_nasabah_balance]);
        
        $pdo->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Penarikan berhasil',
            'saldo_baru' => $new_nasabah_balance,
            'no_transaksi' => $no_transaksi
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Withdrawal error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Transaksi gagal: ' . $e->getMessage()]);
    }
}
?>