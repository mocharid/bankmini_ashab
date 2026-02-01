<?php
/**
 * Script untuk membuat multiple akun (admin1, admin2, bendahara)
 * Admin1 dan Admin2: TIDAK PUNYA REKENING
 * Bendahara: PUNYA REKENING
 * File: create_multiple_accounts.php
 * Jalankan sekali saja melalui browser
 */

// Load database connection
require_once __DIR__ . '/includes/db_connection.php';

// Data akun yang akan dibuat
$accounts = [
    [
        'username' => 'admin1',
        'password' => '12345',
        'nama' => 'Administrator 1',
        'role' => 'admin',
        'has_rekening' => false,  // Admin1 TIDAK punya rekening
        'no_rekening' => null
    ],
    [
        'username' => 'admin2',
        'password' => '12345',
        'nama' => 'Administrator 2',
        'role' => 'admin',
        'has_rekening' => false,  // Admin2 TIDAK punya rekening
        'no_rekening' => null
    ],
    [
        'username' => 'bendahara',
        'password' => '12345',
        'nama' => 'Bendahara Sekolah',
        'role' => 'bendahara',
        'has_rekening' => true,   // Bendahara PUNYA rekening
        'no_rekening' => '11200300'
    ]
];

echo "<!DOCTYPE html><html><head><title>Create Multiple Accounts</title>";
echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; }
.success { color: green; background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
.error { color: red; background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
.warning { color: orange; background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0; }
.info { background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 10px 0; }
code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; font-size: 11px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
table th, table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
table th { background: #f4f4f4; font-weight: bold; }
.btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; cursor: pointer; border: none; }
.btn:hover { background: #0056b3; }
.danger { background: #dc3545; }
.danger:hover { background: #c82333; }
.account-box { background: #f8f9fa; padding: 15px; margin: 15px 0; border-left: 4px solid #007bff; border-radius: 5px; }
.no-rekening { background: #e7f3ff; padding: 10px; border-left: 4px solid #2196F3; margin: 10px 0; }
</style>";
echo "</head><body>";
echo "<h2>🔐 Membuat Multiple Akun (Admin1, Admin2, Bendahara)</h2>";
echo "<hr>";

// Array untuk menyimpan hasil
$success_count = 0;
$skip_count = 0;
$error_count = 0;

// Proses setiap akun
foreach ($accounts as $account) {
    $username = $account['username'];
    $password = $account['password'];
    $nama = $account['nama'];
    $role = $account['role'];
    $has_rekening = $account['has_rekening'];
    $no_rekening = $account['no_rekening'];

    // Generate hash bcrypt
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    echo "<div class='account-box'>";
    echo "<h3>📋 Memproses: <strong>" . $username . "</strong> (" . $role . ")</h3>";

    if ($has_rekening) {
        echo "<div class='no-rekening'>🏦 <strong>Akun ini AKAN dibuat dengan rekening</strong></div>";
    } else {
        echo "<div class='no-rekening'>👤 <strong>Akun ini TIDAK memerlukan rekening</strong></div>";
    }

    // Cek apakah username sudah ada
    $check_query = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        echo "<div class='warning'>⚠️ Username '<strong>" . $username . "</strong>' sudah ada di database.</div>";
        $user_data = $result->fetch_assoc();
        $user_id = $user_data['id'];

        // Cek rekening (hanya jika seharusnya punya rekening)
        if ($has_rekening) {
            $check_rek = "SELECT * FROM rekening WHERE user_id = ?";
            $check_rek_stmt = $conn->prepare($check_rek);
            $check_rek_stmt->bind_param("i", $user_id);
            $check_rek_stmt->execute();
            $rek_result = $check_rek_stmt->get_result();

            if ($rek_result->num_rows > 0) {
                $rek_data = $rek_result->fetch_assoc();
                echo "<p>✅ Akun dan rekening sudah lengkap</p>";
                echo "<table>";
                echo "<tr><th>User ID</th><td>" . $user_id . "</td></tr>";
                echo "<tr><th>Username</th><td><strong>" . $username . "</strong></td></tr>";
                echo "<tr><th>No Rekening</th><td>" . $rek_data['no_rekening'] . "</td></tr>";
                echo "<tr><th>Saldo</th><td>Rp " . number_format($rek_data['saldo'], 0, ',', '.') . "</td></tr>";
                echo "</table>";
                $skip_count++;
            } else {
                echo "<p>⚠️ Akun ada, tapi rekening belum dibuat. Membuat rekening...</p>";

                // Cek apakah no rekening sudah dipakai
                $check_no_rek = "SELECT id FROM rekening WHERE no_rekening = ?";
                $check_no_stmt = $conn->prepare($check_no_rek);
                $check_no_stmt->bind_param("s", $no_rekening);
                $check_no_stmt->execute();
                $no_rek_result = $check_no_stmt->get_result();

                if ($no_rek_result->num_rows > 0) {
                    echo "<div class='error'>❌ No rekening " . $no_rekening . " sudah digunakan!</div>";
                    $error_count++;
                } else {
                    $insert_rek = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
                    $insert_rek_stmt = $conn->prepare($insert_rek);
                    $insert_rek_stmt->bind_param("si", $no_rekening, $user_id);

                    if ($insert_rek_stmt->execute()) {
                        echo "<div class='success'>✅ Rekening berhasil dibuat untuk " . $username . "</div>";
                        echo "<table>";
                        echo "<tr><th>No Rekening</th><td>" . $no_rekening . "</td></tr>";
                        echo "<tr><th>Saldo Awal</th><td>Rp 0</td></tr>";
                        echo "</table>";
                        $success_count++;
                    } else {
                        echo "<div class='error'>❌ Gagal membuat rekening: " . $conn->error . "</div>";
                        $error_count++;
                    }
                    $insert_rek_stmt->close();
                }
                $check_no_stmt->close();
            }
            $check_rek_stmt->close();
        } else {
            // Admin tidak perlu rekening
            echo "<p>✅ Akun sudah ada (admin tidak perlu rekening)</p>";
            echo "<table>";
            echo "<tr><th>User ID</th><td>" . $user_id . "</td></tr>";
            echo "<tr><th>Username</th><td><strong>" . $username . "</strong></td></tr>";
            echo "<tr><th>Role</th><td>" . $role . "</td></tr>";
            echo "<tr><th>Status Rekening</th><td><em>Tidak diperlukan</em></td></tr>";
            echo "</table>";
            $skip_count++;
        }

    } else {
        // Insert akun baru
        if ($has_rekening) {
            // Cek apakah no rekening sudah dipakai (untuk bendahara)
            $check_no_rek = "SELECT id FROM rekening WHERE no_rekening = ?";
            $check_no_stmt = $conn->prepare($check_no_rek);
            $check_no_stmt->bind_param("s", $no_rekening);
            $check_no_stmt->execute();
            $no_rek_result = $check_no_stmt->get_result();

            if ($no_rek_result->num_rows > 0) {
                echo "<div class='error'>❌ No rekening " . $no_rekening . " sudah digunakan oleh user lain!</div>";
                $error_count++;
                $check_no_stmt->close();
                $check_stmt->close();
                echo "</div>";
                echo "<hr>";
                continue;
            }
            $check_no_stmt->close();
        }

        // Insert akun baru dengan transaction
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

            // 3. Insert ke rekening (hanya jika has_rekening = true)
            $rekening_id = null;
            if ($has_rekening) {
                $rekening_query = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
                $rekening_stmt = $conn->prepare($rekening_query);
                $rekening_stmt->bind_param("si", $no_rekening, $user_id);
                $rekening_stmt->execute();
                $rekening_id = $conn->insert_id;
                $rekening_stmt->close();
            }

            $conn->commit();

            echo "<div class='success'>";
            echo "<h4>✅ Akun Berhasil Dibuat!</h4>";
            echo "</div>";

            echo "<table>";
            echo "<tr><th>Field</th><th>Value</th></tr>";
            echo "<tr><td>User ID</td><td>" . $user_id . "</td></tr>";
            echo "<tr><td>Username</td><td><strong>" . $username . "</strong></td></tr>";
            echo "<tr><td>Password</td><td><strong>" . $password . "</strong></td></tr>";
            echo "<tr><td>Role</td><td>" . $role . "</td></tr>";
            echo "<tr><td>Nama</td><td>" . $nama . "</td></tr>";

            if ($has_rekening) {
                echo "<tr><td>Rekening ID</td><td>" . $rekening_id . "</td></tr>";
                echo "<tr><td>No Rekening</td><td><strong>" . $no_rekening . "</strong></td></tr>";
                echo "<tr><td>Saldo Awal</td><td>Rp 0</td></tr>";
            } else {
                echo "<tr><td>Status Rekening</td><td><em>Tidak diperlukan (Admin)</em></td></tr>";
            }
            echo "</table>";

            // Test verification
            if (password_verify($password, $password_hash)) {
                echo "<p style='color: green;'>✅ Password Verification: <strong>BERHASIL</strong></p>";
            } else {
                echo "<p style='color: red;'>❌ Password Verification: <strong>GAGAL</strong></p>";
            }

            $success_count++;

            $insert_stmt->close();
            $security_stmt->close();

        } catch (Exception $e) {
            $conn->rollback();
            echo "<div class='error'>❌ Gagal membuat akun: " . $e->getMessage() . "</div>";
            $error_count++;
        }
    }

    $check_stmt->close();
    echo "</div>"; // Close account-box
    echo "<hr>";
}

// Summary
echo "<div class='info'>";
echo "<h3>📊 Ringkasan Proses</h3>";
echo "<table>";
echo "<tr><th>Status</th><th>Jumlah</th></tr>";
echo "<tr><td>✅ Berhasil Dibuat</td><td><strong>" . $success_count . "</strong></td></tr>";
echo "<tr><td>⚠️ Sudah Ada (Dilewati)</td><td><strong>" . $skip_count . "</strong></td></tr>";
echo "<tr><td>❌ Error</td><td><strong>" . $error_count . "</strong></td></tr>";
echo "<tr><th>Total Diproses</th><th>" . count($accounts) . "</th></tr>";
echo "</table>";
echo "</div>";

// Login credentials summary
echo "<div class='info'>";
echo "<h3>🔑 Kredensial Login</h3>";
echo "<table>";
echo "<tr><th>Username</th><th>Password</th><th>Role</th><th>Status Rekening</th></tr>";
foreach ($accounts as $account) {
    echo "<tr>";
    echo "<td><strong>" . $account['username'] . "</strong></td>";
    echo "<td><strong>" . $account['password'] . "</strong></td>";
    echo "<td>" . $account['role'] . "</td>";
    if ($account['has_rekening']) {
        echo "<td>🏦 " . $account['no_rekening'] . "</td>";
    } else {
        echo "<td><em>Tidak ada rekening</em></td>";
    }
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Important notes
echo "<div class='warning'>";
echo "<h3>📌 Catatan Penting:</h3>";
echo "<ul style='margin: 5px 0;'>";
echo "<li><strong>Admin1 & Admin2:</strong> Tidak memiliki rekening (hanya untuk manajemen sistem)</li>";
echo "<li><strong>Bendahara:</strong> Memiliki rekening untuk transaksi keuangan</li>";
echo "<li>Semua akun sudah dilengkapi dengan <code>user_security</code> record</li>";
echo "</ul>";
echo "</div>";

// Next steps
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h3>📝 Langkah Selanjutnya:</h3>";
echo "<ol>";
echo "<li>Login ke sistem dengan salah satu kredensial di atas</li>";
echo "<li>URL Login: <a href='pages/login.php'>pages/login.php</a></li>";
echo "<li><strong>Admin1/Admin2:</strong> <a href='pages/admin/dashboard.php'>Dashboard Admin</a></li>";
echo "<li><strong>Bendahara:</strong> <a href='pages/bendahara/dashboard.php'>Dashboard Bendahara</a></li>";
echo "</ol>";
echo "</div>";

// Warning to delete file
echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
echo "<p style='color: red; font-weight: bold; margin: 0;'>⚠️ PENTING: Hapus file ini setelah selesai!</p>";
echo "<p style='margin: 5px 0 0 0;'>File: <code>create_multiple_accounts.php</code></p>";
echo "</div>";

$conn->close();

echo "<hr>";
echo "<p style='text-align: center; color: #666; font-size: 12px;'>SchoBank System - Multiple Account Creator v1.2</p>";
echo "</body></html>";
?>