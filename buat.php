<?php
/**
 * Script untuk membuat akun bendahara dengan rekening
 * File: create_bendahara_account.php
 * Jalankan sekali saja melalui browser
 */

// Load database connection
require_once __DIR__ . '/includes/db_connection.php';

// Data bendahara
$username = 'bendahara';
$password = '12345';
$nama = 'Bendahara Sekolah';
$role = 'bendahara';
$no_rekening = '11200300';

// Generate hash bcrypt
$password_hash = password_hash($password, PASSWORD_BCRYPT);

echo "<!DOCTYPE html><html><head><title>Create Bendahara</title>";
echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
.success { color: green; background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
.error { color: red; background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
.warning { color: orange; background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0; }
.info { background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 10px 0; }
code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; font-size: 12px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
table th, table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
table th { background: #f4f4f4; }
.btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
.btn:hover { background: #0056b3; }
.danger { background: #dc3545; }
.danger:hover { background: #c82333; }
</style>";
echo "</head><body>";
echo "<h2>🔐 Membuat Akun Bendahara + Rekening</h2>";
echo "<hr>";

// Cek apakah username sudah ada
$check_query = "SELECT id FROM users WHERE username = ?";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("s", $username);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows > 0) {
    echo "<div class='warning'>⚠️ Username 'bendahara' sudah ada di database.</div>";
    $user_data = $result->fetch_assoc();
    $user_id = $user_data['id'];
    
    // Cek rekening
    $check_rek = "SELECT * FROM rekening WHERE user_id = ?";
    $check_rek_stmt = $conn->prepare($check_rek);
    $check_rek_stmt->bind_param("i", $user_id);
    $check_rek_stmt->execute();
    $rek_result = $check_rek_stmt->get_result();
    
    if ($rek_result->num_rows > 0) {
        $rek_data = $rek_result->fetch_assoc();
        echo "<div class='info'>";
        echo "<h3>📊 Data Akun yang Sudah Ada:</h3>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        echo "<tr><td>User ID</td><td>" . $user_id . "</td></tr>";
        echo "<tr><td>Username</td><td>" . $username . "</td></tr>";
        echo "<tr><td>Nama</td><td>" . $nama . "</td></tr>";
        echo "<tr><td>No Rekening</td><td>" . $rek_data['no_rekening'] . "</td></tr>";
        echo "<tr><td>Saldo</td><td>Rp " . number_format($rek_data['saldo'], 0, ',', '.') . "</td></tr>";
        echo "</table>";
        echo "</div>";
        echo "<p>Apakah ingin UPDATE password? <a href='?update=yes' class='btn'>Update Password</a></p>";
    } else {
        echo "<div class='warning'>⚠️ Akun ada, tapi rekening belum dibuat.</div>";
        echo "<p><a href='?create_rekening=yes' class='btn'>Buat Rekening</a></p>";
    }
    
    // Update password jika diminta
    if (isset($_GET['update']) && $_GET['update'] == 'yes') {
        $update_query = "UPDATE users SET password = ?, nama = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ssi", $password_hash, $nama, $user_id);
        
        if ($update_stmt->execute()) {
            echo "<div class='success'>✅ Password bendahara berhasil diupdate!</div>";
            echo "<table>";
            echo "<tr><th>Username</th><td>" . $username . "</td></tr>";
            echo "<tr><th>Password Baru</th><td>" . $password . "</td></tr>";
            echo "<tr><th>Hash</th><td><code style='font-size:10px; word-break: break-all;'>" . $password_hash . "</code></td></tr>";
            echo "</table>";
        } else {
            echo "<div class='error'>❌ Gagal update password: " . $conn->error . "</div>";
        }
        $update_stmt->close();
    }
    
    // Buat rekening jika diminta
    if (isset($_GET['create_rekening']) && $_GET['create_rekening'] == 'yes') {
        // Cek apakah no rekening sudah dipakai
        $check_no_rek = "SELECT id FROM rekening WHERE no_rekening = ?";
        $check_no_stmt = $conn->prepare($check_no_rek);
        $check_no_stmt->bind_param("s", $no_rekening);
        $check_no_stmt->execute();
        $no_rek_result = $check_no_stmt->get_result();
        
        if ($no_rek_result->num_rows > 0) {
            echo "<div class='error'>❌ No rekening " . $no_rekening . " sudah digunakan!</div>";
        } else {
            $insert_rek = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
            $insert_rek_stmt = $conn->prepare($insert_rek);
            $insert_rek_stmt->bind_param("si", $no_rekening, $user_id);
            
            if ($insert_rek_stmt->execute()) {
                echo "<div class='success'>✅ Rekening berhasil dibuat!</div>";
                echo "<table>";
                echo "<tr><th>No Rekening</th><td>" . $no_rekening . "</td></tr>";
                echo "<tr><th>User ID</th><td>" . $user_id . "</td></tr>";
                echo "<tr><th>Saldo Awal</th><td>Rp 0</td></tr>";
                echo "</table>";
            } else {
                echo "<div class='error'>❌ Gagal membuat rekening: " . $conn->error . "</div>";
            }
            $insert_rek_stmt->close();
        }
        $check_no_stmt->close();
    }
    
    $check_rek_stmt->close();
    
} else {
    // Cek apakah no rekening sudah dipakai
    $check_no_rek = "SELECT id FROM rekening WHERE no_rekening = ?";
    $check_no_stmt = $conn->prepare($check_no_rek);
    $check_no_stmt->bind_param("s", $no_rekening);
    $check_no_stmt->execute();
    $no_rek_result = $check_no_stmt->get_result();
    
    if ($no_rek_result->num_rows > 0) {
        echo "<div class='error'>❌ No rekening " . $no_rekening . " sudah digunakan oleh user lain!</div>";
        echo "<p>Silakan ganti no rekening di script ini.</p>";
    } else {
        // Insert bendahara baru
        $conn->begin_transaction();
        
        try {
            // 1. Insert ke users
            $insert_query = "INSERT INTO users (username, password, role, nama, email_status) VALUES (?, ?, ?, ?, 'kosong')";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssss", $username, $password_hash, $role, $nama);
            $insert_stmt->execute();
            $user_id = $conn->insert_id;
            
            // 2. Insert ke user_security
            $security_query = "INSERT INTO user_security (user_id, is_frozen, is_blocked) VALUES (?, 0, 0)";
            $security_stmt = $conn->prepare($security_query);
            $security_stmt->bind_param("i", $user_id);
            $security_stmt->execute();
            
            // 3. Insert ke rekening
            $rekening_query = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
            $rekening_stmt = $conn->prepare($rekening_query);
            $rekening_stmt->bind_param("si", $no_rekening, $user_id);
            $rekening_stmt->execute();
            $rekening_id = $conn->insert_id;
            
            $conn->commit();
            
            echo "<div class='success'>";
            echo "<h3>✅ Akun Bendahara Berhasil Dibuat!</h3>";
            echo "</div>";
            
            echo "<div class='info'>";
            echo "<h3>📊 Detail Akun:</h3>";
            echo "<table>";
            echo "<tr><th>Field</th><th>Value</th></tr>";
            echo "<tr><td>User ID</td><td>" . $user_id . "</td></tr>";
            echo "<tr><td>Username</td><td><strong>" . $username . "</strong></td></tr>";
            echo "<tr><td>Password</td><td><strong>" . $password . "</strong></td></tr>";
            echo "<tr><td>Role</td><td>" . $role . "</td></tr>";
            echo "<tr><td>Nama</td><td>" . $nama . "</td></tr>";
            echo "</table>";
            echo "</div>";
            
            echo "<div class='info'>";
            echo "<h3>🏦 Detail Rekening:</h3>";
            echo "<table>";
            echo "<tr><th>Field</th><th>Value</th></tr>";
            echo "<tr><td>Rekening ID</td><td>" . $rekening_id . "</td></tr>";
            echo "<tr><td>No Rekening</td><td><strong>" . $no_rekening . "</strong></td></tr>";
            echo "<tr><td>Saldo Awal</td><td>Rp 0</td></tr>";
            echo "</table>";
            echo "</div>";
            
            echo "<div class='info'>";
            echo "<h3>🔐 Password Hash (bcrypt):</h3>";
            echo "<code style='word-break: break-all; font-size: 10px;'>" . $password_hash . "</code>";
            echo "</div>";
            
            // Test verification
            echo "<div class='info'>";
            echo "<h3>✅ Verification Test:</h3>";
            if (password_verify($password, $password_hash)) {
                echo "<p style='color: green; font-weight: bold;'>✅ Password Verification: <strong>BERHASIL</strong></p>";
            } else {
                echo "<p style='color: red; font-weight: bold;'>❌ Password Verification: <strong>GAGAL</strong></p>";
            }
            echo "</div>";
            
            echo "<hr>";
            echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
            echo "<p><strong>📝 Langkah Selanjutnya:</strong></p>";
            echo "<ol>";
            echo "<li>Login ke sistem dengan kredensial di atas</li>";
            echo "<li>URL Login: <a href='pages/login.php'>pages/login.php</a></li>";
            echo "<li>Atau: <a href='pages/bendahara/dashboard.php'>Dashboard Bendahara</a></li>";
            echo "</ol>";
            echo "</div>";
            
            echo "<hr>";
            echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
            echo "<p style='color: red; font-weight: bold;'>⚠️ PENTING: Hapus file ini setelah selesai!</p>";
            echo "<p>File: <code>create_bendahara_account.php</code></p>";
            echo "</div>";
            
            $insert_stmt->close();
            $security_stmt->close();
            $rekening_stmt->close();
            
        } catch (Exception $e) {
            $conn->rollback();
            echo "<div class='error'>❌ Gagal membuat akun: " . $e->getMessage() . "</div>";
        }
    }
    $check_no_stmt->close();
}

$check_stmt->close();
$conn->close();

echo "<hr>";
echo "<p style='text-align: center; color: #666; font-size: 12px;'>SchoBank System - Bendahara Account Creator</p>";
echo "</body></html>";
?>