<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Ensure TCPDF is included safely
if (!file_exists('../../tcpdf/tcpdf.php')) {
    error_log('[DEBUG] TCPDF library not found at ../../tcpdf/tcpdf.php');
    die('TCPDF library not found');
}
require_once '../../tcpdf/tcpdf.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: ../../index.php');
    exit();
}

// Function to mask no_rekening (e.g., REK789456 -> REK7****6)
function maskNoRekening($no_rekening) {
    if (empty($no_rekening) || strlen($no_rekening) < 5) {
        return '-';
    }
    return substr($no_rekening, 0, 4) . '****' . substr($no_rekening, -1);
}

// Initialize filter parameters
$jurusan_id = isset($_GET['jurusan_id']) ? intval($_GET['jurusan_id']) : 0;
$tingkatan_id = isset($_GET['tingkatan_id']) ? intval($_GET['tingkatan_id']) : 0;
$kelas_id = isset($_GET['kelas_id']) ? intval($_GET['kelas_id']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle AJAX table refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh_table') {
    ob_start();
    // Fetch students
    $query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas, r.saldo 
              FROM users u
              LEFT JOIN rekening r ON u.id = r.user_id
              LEFT JOIN jurusan j ON u.jurusan_id = j.id
              LEFT JOIN kelas k ON u.kelas_id = k.id
              LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
              WHERE u.role = 'siswa'";
    $count_query = "SELECT COUNT(*) as total FROM users u WHERE u.role = 'siswa'";
    $params = [];
    $types = '';

    if ($jurusan_id > 0) {
        $query .= " AND u.jurusan_id = ?";
        $count_query .= " AND u.jurusan_id = ?";
        $params[] = $jurusan_id;
        $types .= 'i';
    }
    if ($tingkatan_id > 0) {
        $query .= " AND k.tingkatan_kelas_id = ?";
        $count_query .= " AND u.kelas_id IN (SELECT id FROM kelas WHERE tingkatan_kelas_id = ?)";
        $params[] = $tingkatan_id;
        $types .= 'i';
    }
    if ($kelas_id > 0) {
        $query .= " AND u.kelas_id = ?";
        $count_query .= " AND u.kelas_id = ?";
        $params[] = $kelas_id;
        $types .= 'i';
    }

    $display_query = $query . " ORDER BY u.nama LIMIT ? OFFSET ?";
    $params_display = array_merge($params, [$limit, $offset]);
    $types_display = $types . 'ii';

    $stmt_siswa = $conn->prepare($display_query);
    if (!empty($params_display)) {
        $stmt_siswa->bind_param($types_display, ...$params_display);
    }
    $stmt_siswa->execute();
    $siswa = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_siswa->close();

    $stmt_count = $conn->prepare($count_query);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $limit);
    $stmt_count->close();

    // Generate table HTML
    $output = '';
    if (empty($siswa)) {
        $output .= '<div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>Tidak ada data siswa yang ditemukan</p>
                    </div>';
    } else {
        $output .= '<table class="summary-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>No</th>
                                <th>Nama</th>
                                <th>No Rekening</th>
                                <th>Jurusan</th>
                                <th>Kelas</th>
                                <th>Saldo</th>
                            </tr>
                        </thead>
                        <tbody>';
        $no = $offset + 1;
        foreach ($siswa as $row) {
            $output .= "<tr>
                            <td><input type='checkbox' name='selected_ids[]' value='{$row['id']}' class='select-item'></td>
                            <td>$no</td>
                            <td>" . htmlspecialchars($row['nama'] ?? '-') . "</td>
                            <td>" . htmlspecialchars(maskNoRekening($row['no_rekening'])) . "</td>
                            <td>" . htmlspecialchars($row['nama_jurusan'] ?? '-') . "</td>
                            <td>" . htmlspecialchars($row['nama_kelas'] ?? '-') . "</td>
                            <td>Rp " . number_format($row['saldo'] ?? 0, 0, ',', '.') . "</td>
                        </tr>";
            $no++;
        }
        $output .= '</tbody></table>';
    }

    if ($total_pages > 1) {
        $output .= '<div class="pagination">
                        <a href="?page=' . max(1, $page - 1) . '&jurusan_id=' . htmlspecialchars($jurusan_id) . '&tingkatan_id=' . htmlspecialchars($tingkatan_id) . '&kelas_id=' . htmlspecialchars($kelas_id) . '" 
                           class="pagination-link ' . ($page <= 1 ? 'disabled' : '') . '">« Sebelumnya</a>
                        <a href="?page=' . min($total_pages, $page + 1) . '&jurusan_id=' . htmlspecialchars($jurusan_id) . '&tingkatan_id=' . htmlspecialchars($tingkatan_id) . '&kelas_id=' . htmlspecialchars($kelas_id) . '" 
                           class="pagination-link ' . ($page >= $total_pages ? 'disabled' : '') . '">Selanjutnya »</a>
                    </div>';
    }

    $response = [
        'success' => true,
        'html' => $output,
        'total_siswa' => count($siswa)
    ];
    header('Content-Type: application/json');
    echo json_encode($response);
    ob_end_flush();
    exit();
}

// Handle account deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected']) && !isset($_POST['action'])) {
    $selected_ids = isset($_POST['selected_ids']) && is_array($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
    $success_count = 0;
    $failed_accounts = [];

    if (!empty($selected_ids)) {
        foreach ($selected_ids as $user_id) {
            $conn->begin_transaction();
            try {
                // Fetch user and account details
                $stmt = $conn->prepare("SELECT u.id, u.nama, r.id AS rekening_id, r.saldo, r.no_rekening 
                                        FROM users u 
                                        LEFT JOIN rekening r ON u.id = r.user_id 
                                        WHERE u.id = ? AND u.role = 'siswa'");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows === 0) {
                    throw new Exception("User ID $user_id not found or not a student.");
                }
                $user = $result->fetch_assoc();
                $stmt->close();

                $rekening_id = $user['rekening_id'];
                $saldo = $user['saldo'] ?? 0;
                $no_rekening = $user['no_rekening'] ?? '';

                if (!is_null($saldo) && $saldo != 0) {
                    $failed_accounts[] = "Akun $no_rekening (Saldo: Rp " . number_format($saldo, 0, ',', '.') . ")";
                    $conn->rollback();
                    continue;
                }

                if ($rekening_id) {
                    $stmt = $conn->prepare("DELETE m FROM mutasi m 
                                            JOIN transaksi t ON m.transaksi_id = t.id 
                                            WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?");
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
                }

                $tables = [
                    'account_freeze_log' => ['column' => 'siswa_id', 'type' => 'i'],
                    'active_sessions' => ['column' => 'user_id', 'type' => 'i'],
                    'log_aktivitas' => ['column' => 'siswa_id', 'type' => 'i'],
                    'notifications' => ['column' => 'user_id', 'type' => 'i'],
                    'reset_request_cooldown' => ['column' => 'user_id', 'type' => 'i'],
                ];

                foreach ($tables as $table => $config) {
                    if ($conn->query("SHOW TABLES LIKE '$table'")->num_rows > 0) {
                        $stmt = $conn->prepare("DELETE FROM $table WHERE {$config['column']} = ?");
                        $stmt->bind_param($config['type'], $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    throw new Exception("User ID $user_id still exists after deletion attempt.");
                }
                $stmt->close();

                $conn->commit();
                $success_count++;
            } catch (Exception $e) {
                $conn->rollback();
                $failed_accounts[] = "Akun $no_rekening (Error: " . htmlspecialchars($e->getMessage()) . ")";
            }
        }

        $response = [
            'success' => $success_count > 0,
            'message' => $success_count > 0 ? "$success_count akun berhasil dihapus." : "Tidak ada akun yang berhasil dihapus.",
            'errors' => !empty($failed_accounts) ? implode("<br>", $failed_accounts) : null
        ];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    } else {
        $response = [
            'success' => false,
            'message' => 'Tidak ada akun yang dipilih untuk dihapus.',
            'errors' => null
        ];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
}

// Start output buffering
ob_start();

// Fetch jurusan options
$stmt_jurusan = $conn->prepare("SELECT id, nama_jurusan FROM jurusan ORDER BY nama_jurusan");
$stmt_jurusan->execute();
$jurusan_options = $stmt_jurusan->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_jurusan->close();

// Base query for fetching students
$query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas, r.saldo 
          FROM users u
          LEFT JOIN rekening r ON u.id = r.user_id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          WHERE u.role = 'siswa'";
$count_query = "SELECT COUNT(*) as total FROM users u WHERE u.role = 'siswa'";
$params = [];
$types = '';

if ($jurusan_id > 0) {
    $query .= " AND u.jurusan_id = ?";
    $count_query .= " AND u.jurusan_id = ?";
    $params[] = $jurusan_id;
    $types .= 'i';
}
if ($tingkatan_id > 0) {
    $query .= " AND k.tingkatan_kelas_id = ?";
    $count_query .= " AND u.kelas_id IN (SELECT id FROM kelas WHERE tingkatan_kelas_id = ?)";
    $params[] = $tingkatan_id;
    $types .= 'i';
}
if ($kelas_id > 0) {
    $query .= " AND u.kelas_id = ?";
    $count_query .= " AND u.kelas_id = ?";
    $params[] = $kelas_id;
    $types .= 'i';
}

$display_query = $query . " ORDER BY u.nama LIMIT ? OFFSET ?";
$params_display = array_merge($params, [$limit, $offset]);
$types_display = $types . 'ii';

$stmt_siswa = $conn->prepare($display_query);
if (!empty($params_display)) {
    $stmt_siswa->bind_param($types_display, ...$params_display);
}
$stmt_siswa->execute();
$siswa = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_siswa->close();

$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
$stmt_count->close();

// Fetch filter names
$filter_jurusan = 'Semua Jurusan';
if ($jurusan_id > 0) {
    $stmt_jurusan_name = $conn->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
    $stmt_jurusan_name->bind_param("i", $jurusan_id);
    if ($stmt_jurusan_name->execute()) {
        $filter_jurusan = $stmt_jurusan_name->get_result()->fetch_assoc()['nama_jurusan'] ?? 'Semua Jurusan';
    }
    $stmt_jurusan_name->close();
}

$filter_tingkatan = 'Semua Tingkatan';
if ($tingkatan_id > 0) {
    $stmt_tingkatan_name = $conn->prepare("SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?");
    $stmt_tingkatan_name->bind_param("i", $tingkatan_id);
    if ($stmt_tingkatan_name->execute()) {
        $filter_tingkatan = $stmt_tingkatan_name->get_result()->fetch_assoc()['nama_tingkatan'] ?? 'Semua Tingkatan';
    }
    $stmt_tingkatan_name->close();
}

$filter_kelas = 'Semua Kelas';
if ($kelas_id > 0) {
    $stmt_kelas_name = $conn->prepare("SELECT CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas 
                                       FROM kelas k 
                                       JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                                       WHERE k.id = ?");
    $stmt_kelas_name->bind_param("i", $kelas_id);
    if ($stmt_kelas_name->execute()) {
        $filter_kelas = $stmt_kelas_name->get_result()->fetch_assoc()['nama_kelas'] ?? 'Semua Kelas';
    }
    $stmt_kelas_name->close();
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    ob_end_clean();
    try {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 60);

        $stmt_export = $conn->prepare($query . " ORDER BY u.nama");
        if (!empty($params)) {
            $stmt_export->bind_param($types, ...$params);
        }
        $stmt_export->execute();
        $all_siswa = $stmt_export->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_export->close();

        if (empty($all_siswa)) {
            http_response_code(404);
            die('No data found for export');
        }

        class MYPDF extends TCPDF {
            public function trimText($text, $maxLength) {
                return strlen($text) > $maxLength ? substr($text, 0, $maxLength - 3) . '...' : $text;
            }
            
            public function formatCurrency($amount) {
                return 'Rp ' . number_format($amount, 2, ',', '.');
            }

            public function maskNoRekening($no_rekening) {
                if (empty($no_rekening) || strlen($no_rekening) < 5) {
                    return '-';
                }
                return substr($no_rekening, 0, 4) . '****' . substr($no_rekening, -1);
            }

            public function Header() {
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
                $this->Line(15, 35, 195, 35);
            }

            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'L');
                $this->Cell(90, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        }

        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('SCHOBANK');
        $pdf->SetTitle('Data Siswa');
        $pdf->SetMargins(15, 40, 15);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'DATA REKENING SISWA', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 7, 'Tanggal: ' . date('d/m/Y'), 0, 1, 'C');

        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(180, 7, 'KETERANGAN', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(60, 6, 'Jurusan: ' . $filter_jurusan, 1, 0, 'L');
        $pdf->Cell(60, 6, 'Tingkatan: ' . $filter_tingkatan, 1, 0, 'L');
        $pdf->Cell(60, 6, 'Kelas: ' . $filter_kelas, 1, 1, 'L');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(180, 7, 'DAFTAR SISWA', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C');
        $pdf->Cell(50, 6, 'Nama', 1, 0, 'C');
        $pdf->Cell(30, 6, 'No Rekening', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jurusan', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Kelas', 1, 0, 'C');
        $pdf->Cell(30, 6, 'Saldo', 1, 1, 'C');

        $pdf->SetFont('helvetica', '', 8);
        $no = 1;
        foreach ($all_siswa as $row) {
            if (($no - 1) % 30 == 0 && $no > 1) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->Cell(10, 6, 'No', 1, 0, 'C');
                $pdf->Cell(50, 6, 'Nama', 1, 0, 'C');
                $pdf->Cell(30, 6, 'No Rekening', 1, 0, 'C');
                $pdf->Cell(35, 6, 'Jurusan', 1, 0, 'C');
                $pdf->Cell(25, 6, 'Kelas', 1, 0, 'C');
                $pdf->Cell(30, 6, 'Saldo', 1, 1, 'C');
                $pdf->SetFont('helvetica', '', 8);
            }
            
            $pdf->Cell(10, 6, $no++, 1, 0, 'C');
            $pdf->Cell(50, 6, $pdf->trimText($row['nama'] ?? '-', 25), 1, 0, 'L');
            $pdf->Cell(30, 6, $pdf->maskNoRekening($row['no_rekening']), 1, 0, 'L');
            $pdf->Cell(35, 6, $pdf->trimText($row['nama_jurusan'] ?? '-', 20), 1, 0, 'L');
            $pdf->Cell(25, 6, $pdf->trimText($row['nama_kelas'] ?? '-', 15), 1, 0, 'L');
            $pdf->Cell(30, 6, $pdf->formatCurrency($row['saldo'] ?? 0), 1, 1, 'R');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="data_siswa.pdf"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $pdf->Output('data_siswa.pdf', 'D');
        exit();
    } catch (Exception $e) {
        error_log('[DEBUG] PDF Generation Error: ' . $e->getMessage());
        http_response_code(500);
        die('Failed to generate PDF: ' . $e->getMessage());
    }
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Data Siswa - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        select {
            width: 100%;
            padding: 12px 35px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            background-color: white;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%231e3a8a%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 12px;
            appearance: none;
            -webkit-user-select: text;
            user-select: text;
        }

        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .loading-spinner {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: clamp(0.9rem, 2vw, 1rem);
            animation: spin 1s linear infinite;
            display: none;
        }

        .tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
        }

        .form-group:hover .tooltip {
            opacity: 1;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
            text-decoration: none;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #d32f2f 0%, var(--danger-color) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-disabled {
            background: #ccc;
            color: #666;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-disabled:hover {
            background: #ccc;
            transform: none;
            box-shadow: none;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .summary-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .summary-title {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .summary-table th, .summary-table td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
        }

        .summary-table th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        .summary-table td {
            background: white;
        }

        .summary-table th:last-child, .summary-table td:last-child {
            text-align: right;
        }

        .summary-table th:first-child, .summary-table td:first-child {
            width: 50px;
            text-align: center;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 20px 0;
        }

        .pagination-link {
            background: var(--bg-light);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover:not(.disabled) {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination-link.disabled {
            background: #f0f0f0;
            color: #aaa;
            cursor: not-allowed;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: clamp(2rem, 4vw, 2.2rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(350px, 85vw, 450px);
            border-radius: 15px;
            padding: clamp(30px, 5vw, 40px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
        }

        .success-modal {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(3.8rem, 8vw, 4.8rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: white;
        }

        .error-icon {
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 20px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.95rem, 2.3vw, 1.05rem);
            margin: 0 0 25px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-footer {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .modal-footer .btn {
            padding: 10px 20px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            min-width: 120px;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .filter-form {
                flex-direction: column;
                gap: 15px;
            }

            .form-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .summary-container {
                padding: 20px;
            }

            .summary-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .summary-table th, .summary-table td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pagination {
                flex-wrap: wrap;
            }

            .success-modal, .error-modal {
                width: clamp(330px, 90vw, 440px);
                padding: clamp(25px, 5vw, 35px);
                margin: 15px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.5rem, 7vw, 4.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.3rem, 2.8vw, 1.5rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .summary-title {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .empty-state i {
                font-size: clamp(1.8rem, 3.5vw, 2rem);
            }

            .success-modal, .error-modal {
                width: clamp(310px, 92vw, 380px);
                padding: clamp(20px, 4vw, 30px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.2rem, 6.5vw, 4rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.2rem, 2.7vw, 1.4rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.85rem, 2.1vw, 0.95rem);
            }

            .modal-footer {
                gap: 10px;
            }

            .modal-footer .btn {
                padding: 8px 15px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
                min-width: 100px;
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Data Rekening Siswa</h2>
            <p>Kelola data rekening siswa dengan mudah</p>
        </div>

        <div id="alertContainer">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="success-overlay">
                    <div class="success-modal">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Berhasil</h3>
                        <p><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php elseif (isset($_SESSION['error_message'])): ?>
                <div class="success-overlay">
                    <div class="error-modal">
                        <div class="error-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <h3>Gagal</h3>
                        <p><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
        </div>

        <div class="filter-section">
            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="jurusan_id">Jurusan</label>
                    <select id="jurusan_id" name="jurusan_id">
                        <option value="0">Semua Jurusan</option>
                        <?php foreach ($jurusan_options as $jurusan): ?>
                            <option value="<?= $jurusan['id'] ?>" <?= $jurusan_id == $jurusan['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($jurusan['nama_jurusan']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="tooltip">Pilih jurusan siswa</span>
                </div>
                <div class="form-group">
                    <label for="tingkatan_id">Tingkatan</label>
                    <select id="tingkatan_id" name="tingkatan_id">
                        <option value="0">Semua Tingkatan</option>
                        <?php if ($jurusan_id > 0):
                            $stmt_tingkatan = $conn->prepare("SELECT DISTINCT tk.id, tk.nama_tingkatan 
                                                             FROM tingkatan_kelas tk
                                                             JOIN kelas k ON tk.id = k.tingkatan_kelas_id 
                                                             WHERE k.jurusan_id = ? 
                                                             ORDER BY tk.nama_tingkatan");
                            $stmt_tingkatan->bind_param("i", $jurusan_id);
                            if ($stmt_tingkatan->execute()) {
                                $tingkatan_options = $stmt_tingkatan->get_result()->fetch_all(MYSQLI_ASSOC);
                                foreach ($tingkatan_options as $tingkatan): ?>
                                    <option value="<?= $tingkatan['id'] ?>" <?= $tingkatan_id == $tingkatan['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tingkatan['nama_tingkatan']) ?>
                                    </option>
                                <?php endforeach;
                            }
                            $stmt_tingkatan->close();
                        endif; ?>
                    </select>
                    <span class="tooltip">Pilih tingkatan kelas</span>
                    <i class="fas fa-spinner loading-spinner" id="tingkatan-loading"></i>
                </div>
                <div class="form-group">
                    <label for="kelas_id">Kelas</label>
                    <select id="kelas_id" name="kelas_id">
                        <option value="0">Semua Kelas</option>
                        <?php if ($jurusan_id > 0 && $tingkatan_id > 0):
                            $stmt_kelas = $conn->prepare("SELECT k.id, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas 
                                                         FROM kelas k 
                                                         JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                                                         WHERE k.jurusan_id = ? AND k.tingkatan_kelas_id = ? 
                                                         ORDER BY tk.nama_tingkatan, k.nama_kelas");
                            $stmt_kelas->bind_param("ii", $jurusan_id, $tingkatan_id);
                            if ($stmt_kelas->execute()) {
                                $kelas_options = $stmt_kelas->get_result()->fetch_all(MYSQLI_ASSOC);
                                foreach ($kelas_options as $kelas): ?>
                                    <option value="<?= $kelas['id'] ?>" <?= $kelas_id == $kelas['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($kelas['nama_kelas']) ?>
                                    </option>
                                <?php endforeach;
                            }
                            $stmt_kelas->close();
                        endif; ?>
                    </select>
                    <span class="tooltip">Pilih kelas siswa</span>
                    <i class="fas fa-spinner loading-spinner" id="kelas-loading"></i>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <span class="btn-content"><i class="fas fa-filter"></i> Filter</span>
                    </button>
                    <button type="button" id="exportPdfButton" class="btn">
                        <span class="btn-content"><i class="fas fa-file-pdf"></i> Ekspor PDF</span>
                    </button>
                    <button type="button" id="deleteButton" class="btn btn-disabled" disabled>
                        <span class="btn-content"><i class="fas fa-trash"></i> Hapus Terpilih</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="summary-container">
            <h3 class="summary-title">Daftar Siswa</h3>
            <form id="deleteForm" method="POST">
                <input type="hidden" name="delete_selected" value="1">
                <div id="tableContainer">
                    <?php if (empty($siswa)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>Tidak ada data siswa yang ditemukan</p>
                        </div>
                    <?php else: ?>
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll"></th>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th>No Rekening</th>
                                    <th>Jurusan</th>
                                    <th>Kelas</th>
                                    <th>Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = $offset + 1; foreach ($siswa as $row): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_ids[]" value="<?= $row['id'] ?>" class="select-item"></td>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($row['nama'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars(maskNoRekening($row['no_rekening'])) ?></td>
                                        <td><?= htmlspecialchars($row['nama_jurusan'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['nama_kelas'] ?? '-') ?></td>
                                        <td>Rp <?= number_format($row['saldo'] ?? 0, 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <a href="?page=<?= max(1, $page - 1) ?>&jurusan_id=<?= htmlspecialchars($jurusan_id) ?>&tingkatan_id=<?= htmlspecialchars($tingkatan_id) ?>&kelas_id=<?= htmlspecialchars($kelas_id) ?>" 
                               class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>">« Sebelumnya</a>
                            <a href="?page=<?= min($total_pages, $page + 1) ?>&jurusan_id=<?= htmlspecialchars($jurusan_id) ?>&tingkatan_id=<?= htmlspecialchars($tingkatan_id) ?>&kelas_id=<?= htmlspecialchars($kelas_id) ?>" 
                               class="pagination-link <?= $page >= $total_pages ? 'disabled' : '' ?>">Selanjutnya »</a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
            <input type="hidden" id="totalSiswa" value="<?= count($siswa) ?>">
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Prevent zooming and double-tap issues
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });

        // Alert function
        function showAlert(message, type, isConfirm = false, confirmCallback = null) {
            const alertContainer = document.getElementById('alertContainer');
            const existingAlerts = alertContainer.querySelectorAll('.success-overlay');
            existingAlerts.forEach(alert => {
                alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => alert.remove(), 500);
            });

            const alertDiv = document.createElement('div');
            alertDiv.className = 'success-overlay';
            alertDiv.innerHTML = `
                <div class="${type}-modal">
                    <div class="${type}-icon">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : isConfirm ? 'exclamation-triangle' : 'exclamation-circle'}"></i>
                    </div>
                    <h3>${type === 'success' ? 'Berhasil' : isConfirm ? 'Konfirmasi Penghapusan' : 'Gagal'}</h3>
                    <p>${message}</p>
                    ${isConfirm ? `
                        <div class="modal-footer">
                            <button type="button" class="btn modal-close-btn">
                                <i class="fas fa-xmark"></i> Batal
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                                <i class="fas fa-trash-alt"></i> Ya, Hapus
                            </button>
                        </div>
                    ` : ''}
                </div>
            `;
            alertContainer.appendChild(alertDiv);

            if (!isConfirm) {
                setTimeout(() => {
                    alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alertDiv.remove(), 500);
                }, 3000); // Success/error popup disappears after 3 seconds
            } else {
                alertDiv.addEventListener('click', function(event) {
                    if (!event.target.closest('.error-modal')) {
                        alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        setTimeout(() => alertDiv.remove(), 500);
                    }
                });

                const confirmBtn = alertDiv.querySelector('#confirmDeleteBtn');
                const cancelBtn = alertDiv.querySelector('.modal-close-btn');

                if (confirmBtn) {
                    confirmBtn.addEventListener('click', function() {
                        const spinner = showLoadingSpinner(this);
                        setTimeout(() => {
                            spinner.restore();
                            alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                            setTimeout(() => {
                                alertDiv.remove();
                                if (confirmCallback) confirmCallback();
                            }, 500);
                        }, 1000);
                    });
                }

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function() {
                        alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        setTimeout(() => alertDiv.remove(), 500);
                    });
                }
            }
        }

        // Show loading spinner
        function showLoadingSpinner(element, isButton = true) {
            console.log('[DEBUG] Showing loading spinner for element:', element.id || element.className);
            const originalContent = element.innerHTML;
            element.innerHTML = isButton 
                ? '<span class="btn-content"><i class="fas fa-spinner fa-spin"></i> Memproses...</span>'
                : '<i class="fas fa-spinner fa-spin"></i> Memproses...';
            element.classList.add('loading');
            element.disabled = true;
            return {
                restore: () => {
                    element.innerHTML = originalContent;
                    element.classList.remove('loading');
                    element.disabled = false;
                }
            };
        }

        // Refresh table via AJAX
        function refreshTable() {
            console.log('[DEBUG] Refreshing table');
            const jurusan_id = $('#jurusan_id').val();
            const tingkatan_id = $('#tingkatan_id').val();
            const kelas_id = $('#kelas_id').val();
            const page = <?= $page ?>;

            $.ajax({
                url: 'data_siswa.php',
                type: 'POST',
                data: {
                    action: 'refresh_table',
                    jurusan_id: jurusan_id,
                    tingkatan_id: tingkatan_id,
                    kelas_id: kelas_id,
                    page: page
                },
                dataType: 'json',
                success: function(response) {
                    console.log('[DEBUG] Table refresh success:', response);
                    if (response.success) {
                        $('#tableContainer').html(response.html);
                        $('#totalSiswa').val(response.total_siswa);
                        bindCheckboxEvents();
                    } else {
                        showAlert('Gagal memuat ulang tabel.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEBUG] Error refreshing table:', status, error, xhr.responseText);
                    showAlert('Gagal memuat ulang tabel.', 'error');
                }
            });
        }

        // Bind checkbox events
        function bindCheckboxEvents() {
            console.log('[DEBUG] Binding checkbox events');
            const selectAll = document.getElementById('selectAll');
            const selectItems = document.querySelectorAll('.select-item');

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    selectItems.forEach(item => {
                        item.checked = this.checked;
                    });
                    toggleDeleteButton();
                });
            }

            selectItems.forEach(item => {
                item.addEventListener('change', function() {
                    if (!this.checked) {
                        selectAll.checked = false;
                    } else if (document.querySelectorAll('.select-item:checked').length === selectItems.length) {
                        selectAll.checked = true;
                    }
                    toggleDeleteButton();
                });
            });

            toggleDeleteButton();
        }

        // Toggle delete button state
        function toggleDeleteButton() {
            const checkedItems = document.querySelectorAll('.select-item:checked');
            const deleteButton = document.getElementById('deleteButton');
            console.log('[DEBUG] Checked items:', checkedItems.length);
            if (checkedItems.length > 0) {
                deleteButton.classList.remove('btn-disabled');
                deleteButton.classList.add('btn-danger');
                deleteButton.disabled = false;
            } else {
                deleteButton.classList.add('btn-disabled');
                deleteButton.classList.remove('btn-danger');
                deleteButton.disabled = true;
            }
        }

        // Filter form handling
        const filterForm = document.getElementById('filterForm');
        const filterButton = document.getElementById('filterButton');
        const exportPdfButton = document.getElementById('exportPdfButton');
        const jurusanSelect = document.getElementById('jurusan_id');
        const tingkatanSelect = document.getElementById('tingkatan_id');
        const kelasSelect = document.getElementById('kelas_id');
        const tingkatanLoading = document.getElementById('tingkatan-loading');
        const kelasLoading = document.getElementById('kelas-loading');
        const totalSiswa = document.getElementById('totalSiswa');
        const deleteButton = document.getElementById('deleteButton');

        if (filterForm && filterButton) {
            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const spinner = showLoadingSpinner(filterButton);
                setTimeout(() => {
                    spinner.restore();
                    window.location.href = `data_siswa.php?page=1&jurusan_id=${jurusanSelect.value}&tingkatan_id=${tingkatanSelect.value}&kelas_id=${kelasSelect.value}`;
                }, 1000);
            });
        }

        // Build export URL
        function buildExportUrl(format) {
            const jurusan_id = jurusanSelect.value;
            const tingkatan_id = tingkatanSelect.value;
            const kelas_id = kelasSelect.value;
            let url = `?export=${format}`;
            if (jurusan_id !== '0') url += `&jurusan_id=${encodeURIComponent(jurusan_id)}`;
            if (tingkatan_id !== '0') url += `&tingkatan_id=${encodeURIComponent(tingkatan_id)}`;
            if (kelas_id !== '0') url += `&kelas_id=${encodeURIComponent(kelas_id)}`;
            return url;
        }

        // Handle PDF export
        if (exportPdfButton) {
            exportPdfButton.addEventListener('click', function() {
                if (parseInt(totalSiswa.value) === 0) {
                    showAlert('Tidak ada data untuk diekspor.', 'error');
                    return;
                }
                const spinner = showLoadingSpinner(exportPdfButton);
                const url = buildExportUrl('pdf');
                setTimeout(() => {
                    spinner.restore();
                    window.location.href = url;
                }, 1000);
            });
        }

        // Handle delete button click
        if (deleteButton) {
            deleteButton.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('[DEBUG] Delete button clicked');
                const checkedItems = document.querySelectorAll('.select-item:checked');
                if (checkedItems.length === 0) {
                    showAlert('Pilih setidaknya satu akun untuk dihapus.', 'error');
                    return;
                }

                console.log('[DEBUG] Selected items for deletion:', Array.from(checkedItems).map(item => item.value));

                showAlert(
                    `PERINGATAN: Anda akan menghapus ${checkedItems.length} akun secara permanen. Pastikan semua saldo akun nol sebelum melanjutkan. Apakah Anda yakin?`,
                    'error',
                    true,
                    () => {
                        const spinner = showLoadingSpinner(deleteButton);
                        const formData = new FormData();
                        formData.append('delete_selected', '1');
                        checkedItems.forEach(item => {
                            formData.append('selected_ids[]', item.value);
                        });

                        $.ajax({
                            url: 'data_siswa.php',
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            success: function(response) {
                                console.log('[DEBUG] Deletion response:', response);
                                spinner.restore();
                                if (response.success) {
                                    showAlert(response.message, 'success');
                                    refreshTable();
                                } else {
                                    showAlert(response.message, 'error');
                                }
                                if (response.errors) {
                                    showAlert(response.errors, 'error');
                                }
                            },
                            error: function(xhr, status, error) {
                                spinner.restore();
                                console.error('[DEBUG] Error deleting accounts:', status, error, xhr.responseText);
                                showAlert('Gagal menghapus akun.', 'error');
                            }
                        });
                    }
                );
            });
        }

        // Dynamic tingkatan and kelas loading
        if (jurusanSelect && tingkatanSelect && kelasSelect) {
            jurusanSelect.addEventListener('change', function() {
                const jurusan_id = this.value;
                tingkatanLoading.style.display = 'block';
                kelasLoading.style.display = 'block';
                $.ajax({
                    url: 'get_kelas_by_jurusan_dan_tingkatan.php',
                    type: 'GET',
                    data: { jurusan_id: jurusan_id, action: 'get_tingkatan' },
                    success: function(response) {
                        tingkatanSelect.innerHTML = '<option value="0">Semua Tingkatan</option>' + response;
                        kelasSelect.innerHTML = '<option value="0">Semua Kelas</option>';
                        tingkatanLoading.style.display = 'none';
                        kelasLoading.style.display = 'none';
                    },
                    error: function(xhr, status, error) {
                        console.error('[DEBUG] Error fetching tingkatan:', status, error, xhr.responseText);
                        showAlert('Gagal memuat data tingkatan', 'error');
                        tingkatanSelect.innerHTML = '<option value="0">Semua Tingkatan</option>';
                        kelasSelect.innerHTML = '<option value="0">Semua Kelas</option>';
                        tingkatanLoading.style.display = 'none';
                        kelasLoading.style.display = 'none';
                    }
                });
            });

            tingkatanSelect.addEventListener('change', function() {
                const jurusan_id = jurusanSelect.value;
                const tingkatan_id = this.value;
                kelasLoading.style.display = 'block';
                $.ajax({
                    url: 'get_kelas_by_jurusan_dan_tingkatan.php',
                    type: 'GET',
                    data: { jurusan_id: jurusan_id, tingkatan_id: tingkatan_id, action: 'get_kelas' },
                    success: function(response) {
                        kelasSelect.innerHTML = '<option value="0">Semua Kelas</option>' + response;
                        kelasLoading.style.display = 'none';
                    },
                    error: function(xhr, status, error) {
                        console.error('[DEBUG] Error fetching kelas:', status, error, xhr.responseText);
                        showAlert('Gagal memuat data kelas', 'error');
                        kelasSelect.innerHTML = '<option value="0">Semua Kelas</option>';
                        kelasLoading.style.display = 'none';
                    }
                });
            });
        }

        // Prevent text selection on double-click
        document.addEventListener('mousedown', function(e) {
            if (e.detail > 1) {
                e.preventDefault();
            }
        });

        // Keyboard accessibility
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && document.activeElement.tagName !== 'BUTTON') {
                filterForm.dispatchEvent(new Event('submit'));
            }
        });

        // Ensure responsive table scroll
        const table = document.querySelector('.summary-table');
        if (table) {
            table.parentElement.style.overflowX = 'auto';
        }

        // Fix touch issues in Safari
        document.addEventListener('touchstart', function(e) {
            e.stopPropagation();
        }, { passive: true });

        // Bind initial checkbox events
        bindCheckboxEvents();
    });
    </script>
</body>
</html>