<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
// Restrict access to non-siswa users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: ../login.php");
    exit;
}
$error_message = '';
$success = '';
$tanggal = '';
$selected_petugas_id = '';
$petugas1_nama = '';
$petugas2_nama = '';
$id = null;
$show_error_modal = false;
$show_success_modal = false;
$show_confirm_modal = false;
// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['csrf_token'];
// Fetch all petugas accounts from petugas_info
$petugas_query = "SELECT u.id, u.nama, u.username, pi.petugas1_nama, pi.petugas2_nama
                  FROM users u
                  INNER JOIN petugas_info pi ON u.id = pi.user_id
                  WHERE u.role = 'petugas'
                  AND pi.petugas1_nama IS NOT NULL
                  AND pi.petugas1_nama != ''
                  AND pi.petugas2_nama IS NOT NULL
                  AND pi.petugas2_nama != ''
                  ORDER BY u.nama ASC";
$petugas_result = $conn->query($petugas_query);
$petugas_list = [];
if ($petugas_result && $petugas_result->num_rows > 0) {
    while ($row = $petugas_result->fetch_assoc()) {
        $petugas_list[] = $row;
    }
}
// Handle Edit Request
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
 
    $query = "SELECT pt.*, pi.petugas1_nama, pi.petugas2_nama
              FROM petugas_tugas pt
              LEFT JOIN petugas_info pi ON pt.petugas1_id = pi.user_id
              WHERE pt.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $tanggal = $row['tanggal'];
        $selected_petugas_id = $row['petugas1_id'];
        $petugas1_nama = $row['petugas1_nama'];
        $petugas2_nama = $row['petugas2_nama'];
    } else {
        $error_message = "Jadwal tidak ditemukan!";
        $show_error_modal = true;
    }
    $stmt->close();
}
// ===============================================================
// PERBAIKAN: Handle Delete Request dengan Reset Variabel
// ===============================================================
if (isset($_POST['delete_id']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $delete_id = (int)$_POST['delete_id'];
   
    try {
        $query_get_jadwal = "SELECT petugas1_id, tanggal FROM petugas_tugas WHERE id = ?";
        $stmt_get_jadwal = $conn->prepare($query_get_jadwal);
        $stmt_get_jadwal->bind_param("i", $delete_id);
        $stmt_get_jadwal->execute();
        $result_jadwal = $stmt_get_jadwal->get_result();
       
        if ($result_jadwal->num_rows === 0) {
            $error_message = 'Jadwal tidak ditemukan!';
            $show_error_modal = true;
            $stmt_get_jadwal->close();
            // Reset variabel
            $id = null;
            $tanggal = '';
            $selected_petugas_id = '';
            $petugas1_nama = '';
            $petugas2_nama = '';
        } else {
            $jadwal_data = $result_jadwal->fetch_assoc();
            $petugas_id = $jadwal_data['petugas1_id'];
            $tanggal_jadwal = $jadwal_data['tanggal'];
            $stmt_get_jadwal->close();
           
            $query_check_transaksi = "SELECT COUNT(*) as total FROM transaksi
                                      WHERE petugas_id = ? AND DATE(created_at) = ?";
            $stmt_check = $conn->prepare($query_check_transaksi);
            $stmt_check->bind_param("is", $petugas_id, $tanggal_jadwal);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $check_data = $result_check->fetch_assoc();
            $total_transaksi = $check_data['total'];
            $stmt_check->close();
           
            if ($total_transaksi > 0) {
                $error_message = 'Jadwal tidak dapat dihapus karena sudah ada ' . $total_transaksi . ' transaksi yang dilakukan oleh petugas pada tanggal tersebut!';
                $show_error_modal = true;
                // PERBAIKAN UTAMA: Reset variabel $id agar tidak menampilkan tombol edit
                $id = null;
                $tanggal = '';
                $selected_petugas_id = '';
                $petugas1_nama = '';
                $petugas2_nama = '';
            } else {
                $conn->begin_transaction();
                try {
                    $query_petugas = "DELETE FROM petugas_tugas WHERE id = ?";
                    $stmt_petugas = $conn->prepare($query_petugas);
                    $stmt_petugas->bind_param("i", $delete_id);
                    $stmt_petugas->execute();
                    $stmt_petugas->close();
                   
                    $conn->commit();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: kelola_jadwal.php?delete_success=1');
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = 'Gagal menghapus jadwal: ' . $e->getMessage();
                    $show_error_modal = true;
                    // Reset variabel
                    $id = null;
                    $tanggal = '';
                    $selected_petugas_id = '';
                    $petugas1_nama = '';
                    $petugas2_nama = '';
                }
            }
        }
    } catch (Exception $e) {
        $error_message = 'Terjadi kesalahan: ' . $e->getMessage();
        $show_error_modal = true;
        // Reset variabel
        $id = null;
        $tanggal = '';
        $selected_petugas_id = '';
        $petugas1_nama = '';
        $petugas2_nama = '';
    }
}
// ===============================================================
// PERBAIKAN LENGKAP: Handle Edit Submission dengan Auto Logout
// ===============================================================
if (isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $id = (int)$_POST['edit_id'];
    $tanggal = $_POST['edit_tanggal'];
    $selected_petugas_id = (int)$_POST['edit_petugas_id'];
 
    if (empty($tanggal) || empty($selected_petugas_id)) {
        $error_message = "Semua field harus diisi!";
        $show_error_modal = true;
    } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
        $error_message = "Tanggal tidak boleh di masa lalu!";
        $show_error_modal = true;
    } else {
        $check_query = "SELECT id FROM petugas_tugas WHERE tanggal = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $tanggal, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
       
        if ($result->num_rows > 0) {
            $error_message = "Tanggal ini sudah digunakan untuk jadwal lain!";
            $show_error_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                // Ambil data original
                $query_original = "SELECT tanggal, petugas1_id FROM petugas_tugas WHERE id = ?";
                $stmt_original = $conn->prepare($query_original);
                $stmt_original->bind_param("i", $id);
                $stmt_original->execute();
                $original = $stmt_original->get_result()->fetch_assoc();
                $original_date = $original['tanggal'];
                $original_petugas_id = $original['petugas1_id'];
                $stmt_original->close();
               
                // Update jadwal di tabel petugas_tugas
                $query = "UPDATE petugas_tugas SET tanggal = ?, petugas1_id = ?, petugas2_id = ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("siii", $tanggal, $selected_petugas_id, $selected_petugas_id, $id);
                $stmt->execute();
                $stmt->close();
               
                // Hapus absensi lama jika ada perubahan
                if ($original_date !== $tanggal || $original_petugas_id != $selected_petugas_id) {
                    $query_delete_absensi = "DELETE FROM absensi WHERE tanggal = ?";
                    $stmt_delete = $conn->prepare($query_delete_absensi);
                    $stmt_delete->bind_param("s", $original_date);
                    $stmt_delete->execute();
                    $stmt_delete->close();
                }
               
                // ============================================
                // TAMBAHAN: Force logout petugas yang diganti
                // ============================================
                if ($original_petugas_id != $selected_petugas_id) {
                    // Hapus semua session aktif petugas lama
                    $session_path = session_save_path();
                    if (empty($session_path)) {
                        $session_path = sys_get_temp_dir();
                    }
                   
                    // Scan session files dan hapus yang berisi user_id petugas lama
                    $session_files = @glob($session_path . '/sess_*');
                    if ($session_files) {
                        foreach ($session_files as $file) {
                            $session_data = @file_get_contents($file);
                            // Cek apakah session ini milik petugas lama
                            if ($session_data && strpos($session_data, 'user_id";i:' . $original_petugas_id . ';') !== false) {
                                @unlink($file); // Hapus session file
                            }
                        }
                    }
                }
               
                $conn->commit();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: kelola_jadwal.php?edit_success=1');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = 'Gagal mengupdate jadwal: ' . $e->getMessage();
                $show_error_modal = true;
            }
        }
        $check_stmt->close();
    }
 
    if ($show_error_modal) {
        $tanggal = $_POST['edit_tanggal'];
        $selected_petugas_id = $_POST['edit_petugas_id'];
    }
}
// Handle Export Request (PDF/Excel)
if (isset($_GET['export']) && !empty($_GET['export'])) {
    $export_type = $_GET['export'];
    $query = "SELECT pt.*, u.nama as nama_akun, u.username, pi.petugas1_nama, pi.petugas2_nama
              FROM petugas_tugas pt
              LEFT JOIN users u ON pt.petugas1_id = u.id
              LEFT JOIN petugas_info pi ON pt.petugas1_id = pi.user_id
              ORDER BY pt.tanggal ASC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        if ($export_type === 'pdf') {
            require_once '../../tcpdf/tcpdf.php';
            class MYPDF extends TCPDF {
                public function Header() {
                    $this->SetFont('helvetica', 'B', 16);
                    $this->Cell(0, 10, 'MY SCHOBANK', 0, false, 'C', 0);
                    $this->Ln(6);
                    $this->SetFont('helvetica', '', 10);
                    $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
                    $this->Ln(6);
                    $this->SetFont('helvetica', '', 8);
                    $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
                    $this->Line(10, 35, $this->getPageWidth() - 10, 35);
                }
                public function Footer() {
                    $this->SetY(-15);
                    $this->SetFont('helvetica', 'I', 8);
                    $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/y H:i'), 0, 0, 'L');
                    $this->Cell(0, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
                }
            }
            $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor('MY SCHOBANK');
            $pdf->SetTitle('Jadwal Petugas');
            $pdf->SetMargins(10, 40, 10);
            $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
            $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
            $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
            $pdf->AddPage();
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 7, 'DAFTAR JADWAL PETUGAS BANK MINI', 0, 1, 'C');
            $pdf->Ln(5);
           
            $pageWidth = $pdf->getPageWidth() - 20;
            $colWidths = [
                'no' => 0.05 * $pageWidth,
                'tanggal' => 0.15 * $pageWidth,
                'petugas1' => 0.40 * $pageWidth,
                'petugas2' => 0.40 * $pageWidth
            ];
           
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell($colWidths['no'], 6, 'No', 1, 0, 'C', true);
            $pdf->Cell($colWidths['tanggal'], 6, 'Tanggal', 1, 0, 'C', true);
            $pdf->Cell($colWidths['petugas1'], 6, 'Petugas 1 (Kelas 10)', 1, 0, 'C', true);
            $pdf->Cell($colWidths['petugas2'], 6, 'Petugas 2 (Kelas 11)', 1, 1, 'C', true);
           
            $pdf->SetFont('helvetica', '', 8);
            $no = 1;
            foreach ($data as $row) {
                $pdf->Cell($colWidths['no'], 6, $no++, 1, 0, 'C');
                $pdf->Cell($colWidths['tanggal'], 6, date('d/m/y', strtotime($row['tanggal'])), 1, 0, 'C');
                $pdf->Cell($colWidths['petugas1'], 6, $row['petugas1_nama'], 1, 0, 'L');
                $pdf->Cell($colWidths['petugas2'], 6, $row['petugas2_nama'], 1, 1, 'L');
            }
            $pdf->Output('jadwal_petugas.pdf', 'D');
            exit;
        } elseif ($export_type === 'excel') {
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="jadwal_petugas.xls"');
            echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            echo '<head>';
            echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
            echo '<style>
                    body { font-family: Arial, sans-serif; }
                    .text-left { text-align: left; }
                    .text-center { text-align: center; }
                    .text-right { text-align: right; }
                    td, th { padding: 4px; }
                    .title { font-size: 14pt; font-weight: bold; text-align: center; }
                    .subtitle { font-size: 10pt; text-align: center; }
                    .section-header { background-color: #f0f0f0; font-weight: bold; text-align: center; }
                  </style>';
            echo '</head>';
            echo '<body>';
            echo '<table border="0" cellpadding="3">';
            echo '<tr><td colspan="4" class="title">DAFTAR JADWAL PETUGAS BANK MINI</td></tr>';
            echo '</table>';
            echo '<table border="1" cellpadding="3">';
            echo '<tr><th colspan="4" class="section-header">DAFTAR JADWAL</th></tr>';
            echo '<tr>';
            echo '<th>No</th>';
            echo '<th>Tanggal</th>';
            echo '<th>Petugas 1 ( Kelas 10 ) </th>';
            echo '<th>Petugas 2 ( Kelas 11 )</th>';
            echo '</tr>';
            $no = 1;
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td class="text-center">' . $no++ . '</td>';
                echo '<td class="text-center">' . date('d/m/y', strtotime($row['tanggal'])) . '</td>';
                echo '<td class="text-left">' . htmlspecialchars($row['petugas1_nama']) . '</td>';
                echo '<td class="text-left">' . htmlspecialchars($row['petugas2_nama']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</body></html>';
            exit;
        }
    } else {
        $error_message = 'Tidak ada data untuk diekspor!';
        $show_error_modal = true;
    }
    $stmt->close();
}
// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['csrf_token']) {
    if (isset($_POST['confirm'])) {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $tanggal = $_POST['tanggal'];
        $selected_petugas_id = (int)$_POST['petugas_id'];
     
        if (empty($tanggal) || empty($selected_petugas_id)) {
            $error_message = "Semua field harus diisi!";
            $show_error_modal = true;
        } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
            $error_message = "Tanggal tidak boleh di masa lalu!";
            $show_error_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                if ($id) {
                    $error_message = "Operasi tidak valid!";
                    $show_error_modal = true;
                } else {
                    $query_check = "SELECT id FROM petugas_tugas WHERE tanggal = ?";
                    $stmt_check = $conn->prepare($query_check);
                    $stmt_check->bind_param("s", $tanggal);
                    $stmt_check->execute();
                    $result_check = $stmt_check->get_result();
                    if ($result_check->num_rows > 0) {
                        $error_message = "Tanggal ini sudah digunakan untuk jadwal lain!";
                        $show_error_modal = true;
                        throw new Exception($error_message);
                    }
                    $query = "INSERT INTO petugas_tugas (tanggal, petugas1_id, petugas2_id) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("sii", $tanggal, $selected_petugas_id, $selected_petugas_id);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: kelola_jadwal.php?success=1');
                    exit;
                }
            } catch (Exception $e) {
                $conn->rollback();
                if (!$show_error_modal) {
                    $error_message = $e->getMessage();
                    $show_error_modal = true;
                }
            }
            $stmt_check->close();
        }
     
        if ($show_error_modal) {
            $tanggal = $_POST['tanggal'];
            $selected_petugas_id = $_POST['petugas_id'];
        }
    } else {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $tanggal = $_POST['tanggal'];
        $selected_petugas_id = (int)$_POST['petugas_id'];
     
        $query_get_petugas = "SELECT petugas1_nama, petugas2_nama FROM petugas_info WHERE user_id = ?";
        $stmt_get_petugas = $conn->prepare($query_get_petugas);
        $stmt_get_petugas->bind_param("i", $selected_petugas_id);
        $stmt_get_petugas->execute();
        $result_petugas = $stmt_get_petugas->get_result();
     
        if ($result_petugas->num_rows > 0) {
            $petugas_data = $result_petugas->fetch_assoc();
            $petugas1_nama = $petugas_data['petugas1_nama'];
            $petugas2_nama = $petugas_data['petugas2_nama'];
        } else {
            $error_message = "Data petugas tidak ditemukan!";
            $show_error_modal = true;
            $stmt_get_petugas->close();
            goto validation_end3;
        }
        $stmt_get_petugas->close();
       
        if (empty($tanggal) || empty($selected_petugas_id)) {
            $error_message = 'Semua field harus diisi!';
            $show_error_modal = true;
        } elseif (strtotime($tanggal) < strtotime(date('Y-m-d'))) {
            $error_message = "Tanggal tidak boleh di masa lalu!";
            $show_error_modal = true;
        } else {
            $show_confirm_modal = true;
            header('Location: kelola_jadwal.php?confirm=1&tanggal=' . urlencode($tanggal) . '&petugas_id=' . urlencode($selected_petugas_id) . '&petugas1_nama=' . urlencode($petugas1_nama) . '&petugas2_nama=' . urlencode($petugas2_nama));
            exit;
        }
     
        validation_end3:
        if ($show_error_modal) {
            $tanggal = $_POST['tanggal'];
            $selected_petugas_id = $_POST['petugas_id'];
        }
    }
}
// Check for success query parameters
if (isset($_GET['success'])) {
    $success = 'Jadwal berhasil dibuat!';
    $show_success_modal = true;
}
if (isset($_GET['edit_success'])) {
    $success = 'Jadwal berhasil diupdate!';
    $show_success_modal = true;
}
if (isset($_GET['delete_success'])) {
    $success = 'Jadwal berhasil dihapus!';
    $show_success_modal = true;
}
if (isset($_GET['confirm'])) {
    $show_confirm_modal = true;
    $tanggal = isset($_GET['tanggal']) ? urldecode($_GET['tanggal']) : '';
    $selected_petugas_id = isset($_GET['petugas_id']) ? urldecode($_GET['petugas_id']) : '';
    $petugas1_nama = isset($_GET['petugas1_nama']) ? urldecode($_GET['petugas1_nama']) : '';
    $petugas2_nama = isset($_GET['petugas2_nama']) ? urldecode($_GET['petugas2_nama']) : '';
}
// Fetch Data - Pagination 10
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$total_query = "SELECT COUNT(*) as total FROM petugas_tugas";
$total_stmt = $conn->prepare($total_query);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_row = $total_result->fetch_assoc();
$total_data = $total_row['total'];
$total_pages = ceil($total_data / $limit);
$total_stmt->close();
$jadwal_query = "SELECT pt.*, u.nama as nama_akun, u.username, pi.petugas1_nama, pi.petugas2_nama
                 FROM petugas_tugas pt
                 LEFT JOIN users u ON pt.petugas1_id = u.id
                 LEFT JOIN petugas_info pi ON pt.petugas1_id = pi.user_id
                 ORDER BY pt.tanggal DESC LIMIT ? OFFSET ?";
$jadwal_stmt = $conn->prepare($jadwal_query);
$jadwal_stmt->bind_param("ii", $limit, $offset);
$jadwal_stmt->execute();
$jadwal_result = $jadwal_stmt->get_result();
$jadwal = [];
if ($jadwal_result && $jadwal_result->num_rows > 0) {
    while ($row = $jadwal_result->fetch_assoc()) {
        $jadwal[] = $row;
    }
}
$jadwal_stmt->close();
date_default_timezone_set('Asia/Jakarta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <title>Kelola Data Jadwal Petugas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
        }
        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            overflow-x: hidden;
        }
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .welcome-banner .content {
            flex: 1;
        }
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.8;
        }
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }
        .form-card, .jadwal-list {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            flex: 1;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            position: relative;
        }
        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
        }
        .date-input-wrapper {
            position: relative;
            width: 100%;
        }
        input[type="text"], input[type="date"], select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            user-select: text;
            -webkit-user-select: text;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background: white;
        }
        input[type="date"] {
            padding-right: 50px;
            position: relative;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            cursor: pointer;
            opacity: 0;
            z-index: 2;
        }
        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #666;
            font-size: 18px;
            z-index: 1;
        }
        select {
            background: white;
            cursor: pointer;
            text-align: center;
            text-align-last: center;
        }
        select option {
            text-align: center;
        }
        select option[value=""] {
            text-align: center;
            color: #999;
        }
        select:invalid {
            color: #999;
        }
        select:valid {
            color: var(--text-primary);
        }
        input[type="text"]:focus, input[type="date"]:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }
        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 200px;
            height: 44px;
            margin: 0 auto;
            transition: var(--transition);
        }
        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .btn-edit, .btn-delete, .btn-confirm, .btn-cancel, .btn-print, .btn-search, .btn-reset {
            width: auto;
            min-width: 70px;
            padding: 8px 12px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
        }
        .btn-edit {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }
        .btn-delete {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }
        .btn-cancel, .btn-reset {
            background: #f0f0f0;
            color: var(--text-secondary);
        }
        .btn-cancel:hover, .btn-reset:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }
        .btn-confirm {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }
        .btn-print {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
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
            font-size: 0.9rem;
            position: absolute;
            animation: spin 1.5s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .jadwal-list h3 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .jadwal-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .spacer {
            flex-grow: 1;
        }
        .action-column-align {
            width: 190px;
            text-align: right;
        }
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: var(--shadow-sm);
            touch-action: pan-x pan-y;
        }
        .table-wrapper::-webkit-scrollbar {
            height: 8px;
        }
        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }
        .table-wrapper::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 5px;
        }
        table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            background: white;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }
        th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: flex-start;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 25px;
        }
        .pagination a {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 5px;
            transition: var(--transition);
            font-size: clamp(1rem, 2.2vw, 1.1rem);
            min-width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .pagination a:not(.disabled) {
            background-color: var(--primary-color);
            color: white;
        }
        .pagination a:hover:not(.disabled) {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .pagination .current-page {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            min-width: 50px;
            text-align: center;
        }
        .pagination a.disabled {
            color: var(--text-secondary);
            background-color: #e0e0e0;
            cursor: not-allowed;
            pointer-events: none;
        }
        .no-data {
            text-align: center;
            padding: 60px 30px;
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2vw, 1.1rem);
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.03) 0%, rgba(59, 130, 246, 0.03) 100%);
            border-radius: 5px;
            border: 2px dashed rgba(30, 58, 138, 0.2);
        }
        .no-data i {
            font-size: clamp(3.5rem, 6vw, 4.5rem);
            color: var(--primary-color);
            opacity: 0.4;
            margin-bottom: 15px;
            display: block;
        }
        .scroll-hint {
            display: none;
            text-align: center;
            padding: 8px;
            background: #fff3cd;
            color: #856404;
            border-radius: 5px 5px 0 0;
            font-size: clamp(0.8rem, 1.8vw, 0.85rem);
            border: 1px solid #ffeeba;
            border-bottom: none;
        }
        .modal-date-wrapper {
            position: relative;
            width: 100%;
        }
        .modal-date-wrapper input[type="date"] {
            width: 100% !important;
            max-width: none !important;
            padding: 12px 50px 12px 16px !important;
            margin: 10px 0 !important;
        }
        .modal-date-wrapper .modal-calendar-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #666;
            font-size: 18px;
            z-index: 1;
        }
        .modal-date-wrapper input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            cursor: pointer;
            opacity: 0;
            z-index: 2;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                max-width: 100%;
                padding: 15px;
            }
            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }
            .menu-toggle {
                display: block;
            }
            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                align-items: center;
            }
            .form-card, .jadwal-list {
                padding: 20px;
                border-radius: 5px;
            }
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            .btn {
                width: 100%;
                max-width: 300px;
            }
            .btn-edit, .btn-delete, .btn-print {
                padding: 10px 20px;
                min-width: 100px;
                font-size: clamp(0.85rem, 1.8vw, 0.9rem);
                width: auto;
            }
            .jadwal-list-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            .action-column-align {
                width: 100%;
                text-align: center;
            }
            .btn-print {
                width: 100%;
                max-width: none;
            }
            .scroll-hint {
                display: block;
            }
            .table-wrapper {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                display: block;
            }
            table {
                min-width: 950px;
                display: table;
            }
            th, td {
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }
            input[type="date"], select {
                padding: 12px 35px 12px 15px !important;
                font-size: 16px;
            }
            input[type="date"]::-webkit-calendar-picker-indicator {
                right: 5px !important;
                opacity: 0.5 !important;
            }
            select {
                text-align: center !important;
                text-align-last: center !important;
            }
        }
        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }
        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }
            .form-card, .jadwal-list {
                padding: 15px;
            }
            .btn-edit, .btn-delete, .btn-print {
                padding: 8px 15px;
                min-width: 80px;
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            }
            table {
                min-width: 1000px;
            }
            th, td {
                padding: 8px 10px;
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            }
            input[type="date"], select {
                padding: 10px 30px 10px 12px !important;
                font-size: 16px !important;
            }
            select {
                text-align: center !important;
                text-align-last: center !important;
            }
        }
        .swal2-input, .swal2-select {
            width: 100% !important;
            max-width: 400px !important;
            padding: 12px 16px !important;
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
            font-size: 1rem !important;
            margin: 10px auto !important;
            box-sizing: border-box !important;
            text-align: center !important;
            -webkit-appearance: none !important;
            appearance: none !important;
            background: white !important;
        }
        .swal2-select {
            text-align: center !important;
            text-align-last: center !important;
        }
        .swal2-select option {
            text-align: center !important;
        }
        .swal2-input:focus, .swal2-select:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15) !important;
        }
        .swal2-popup .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
        }
        .swal2-html-container {
            font-size: 1rem !important;
            margin: 15px 0 !important;
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Kelola Data Jadwal Petugas</h2>
                <p>Kelola Data jadwal petugas untuk sistem</p>
            </div>
        </div>
        <div class="form-card">
            <form action="" method="POST" id="jadwal-form">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="tanggal">Tanggal</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="tanggal" name="tanggal" value="<?php echo htmlspecialchars($tanggal ?: date('Y-m-d')); ?>" required min="<?php echo date('Y-m-d'); ?>" data-placeholder="Pilih tanggal">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="petugas_id">Kode Petugas</label>
                        <select id="petugas_id" name="petugas_id" required>
                            <option value="">-- Pilih Kode Petugas --</option>
                            <?php if (!empty($petugas_list)): ?>
                                <?php foreach ($petugas_list as $petugas): ?>
                                    <option value="<?php echo $petugas['id']; ?>"
                                            data-petugas1="<?php echo htmlspecialchars($petugas['petugas1_nama']); ?>"
                                            data-petugas2="<?php echo htmlspecialchars($petugas['petugas2_nama']); ?>"
                                            <?php echo ($selected_petugas_id == $petugas['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($petugas['nama']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>Tidak ada kode petugas yang tersedia</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="petugas1_nama">Nama Petugas 1 (Kelas 10)</label>
                        <input type="text" id="petugas1_nama" name="petugas1_nama" value="<?php echo htmlspecialchars($petugas1_nama); ?>" placeholder="Akan terisi otomatis" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="petugas2_nama">Nama Petugas 2 (Kelas 11)</label>
                        <input type="text" id="petugas2_nama" name="petugas2_nama" value="<?php echo htmlspecialchars($petugas2_nama); ?>" placeholder="Akan terisi otomatis" required readonly>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content"><i class="fas fa-plus-circle"></i> <?php echo $id ? 'Update Jadwal' : 'Tambah'; ?></span>
                    </button>
                    <?php if ($id): ?>
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='kelola_jadwal.php'">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="jadwal-list">
            <div class="jadwal-list-header">
                <h3><i class="fas fa-list"></i> Daftar Jadwal</h3>
                <div class="spacer"></div>
                <div class="action-column-align">
                    <button id="printButton" class="btn btn-print">
                        <span class="btn-content"><i class="fas fa-print"></i> Cetak</span>
                    </button>
                </div>
            </div>
           
            <?php if (!empty($jadwal)): ?>
                <div class="scroll-hint">
                    <i class="fas fa-hand-pointer"></i> Geser tabel ke kanan untuk melihat lebih banyak
                </div>
               
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 5%;">No</th>
                                <th style="width: 15%;">Tanggal</th>
                                <th style="width: 20%;">Kode Petugas</th>
                                <th style="width: 25%;">Petugas 1 ( Kelas 10 )</th>
                                <th style="width: 25%;">Petugas 2 ( Kelas 11 )</th>
                                <th style="width: 10%;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="jadwal-table">
                            <?php
                            $no = ($page - 1) * $limit + 1;
                            foreach ($jadwal as $row):
                            ?>
                                <tr id="row-<?php echo $row['id']; ?>">
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d/m/y', strtotime($row['tanggal'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['nama_akun']); ?></td>
                                    <td><?php echo htmlspecialchars($row['petugas1_nama']); ?></td>
                                    <td><?php echo htmlspecialchars($row['petugas2_nama']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-edit"
                                                    onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['tanggal'], ENT_QUOTES); ?>', '<?php echo $row['petugas1_id']; ?>', '<?php echo htmlspecialchars(addslashes($row['petugas1_nama']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($row['petugas2_nama']), ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-delete"
                                                    onclick="deleteJadwal(<?php echo $row['id']; ?>, '<?php echo date('d/m/y', strtotime($row['tanggal'])); ?>')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>">&lt;</a>
                    <?php else: ?>
                        <a class="disabled">&lt;</a>
                    <?php endif; ?>
                    <span class="current-page"><?php echo $page; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>">&gt;</a>
                    <?php else: ?>
                        <a class="disabled">&gt;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>Belum ada jadwal yang dibuat</p>
                </div>
            <?php endif; ?>
        </div>
        <input type="hidden" id="jadwalCount" value="<?php echo count($jadwal); ?>">
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
           
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
               
                document.addEventListener('click', function(e) {
                    if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }
            const petugasSelect = document.getElementById('petugas_id');
            const petugas1Input = document.getElementById('petugas1_nama');
            const petugas2Input = document.getElementById('petugas2_nama');
           
            if (petugasSelect && petugas1Input && petugas2Input) {
                petugasSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        petugas1Input.value = selectedOption.getAttribute('data-petugas1');
                        petugas2Input.value = selectedOption.getAttribute('data-petugas2');
                    } else {
                        petugas1Input.value = '';
                        petugas2Input.value = '';
                    }
                });
            }
            function formatDateDDMMYY(dateString) {
                const date = new Date(dateString);
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = String(date.getFullYear()).slice(-2);
                return `${day}/${month}/${year}`;
            }
            window.showEditModal = function(id, tanggal, petugas_id, petugas1_nama, petugas2_nama) {
                Swal.fire({
                    title: '<i class="fas fa-edit" style="color:#1e3a8a;"></i> Edit Jadwal',
                    html: `
                        <div style="text-align:left; margin-bottom:15px;">
                            <div style="margin-bottom:15px;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px;">Tanggal</label>
                                <div class="modal-date-wrapper">
                                    <input id="swal-tanggal" class="swal2-input" type="date" value="${tanggal}" min="<?php echo date('Y-m-d'); ?>" style="width:100% !important; max-width:none !important; margin:10px 0 !important; padding-right: 50px !important;">
                                    <i class="fas fa-calendar-alt modal-calendar-icon"></i>
                                </div>
                            </div>
                            <div style="margin-bottom:15px;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px;">Kode Petugas</label>
                                <select id="swal-petugas_id" class="swal2-select" style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
                                    <option value="">-- Pilih Kode Petugas --</option>
                                    <?php if (!empty($petugas_list)): ?>
                                        <?php foreach ($petugas_list as $petugas): ?>
                                            <option value="<?php echo $petugas['id']; ?>"
                                                    data-petugas1="<?php echo htmlspecialchars($petugas['petugas1_nama']); ?>"
                                                    data-petugas2="<?php echo htmlspecialchars($petugas['petugas2_nama']); ?>">
                                                <?php echo htmlspecialchars($petugas['nama']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div style="margin-bottom:15px;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px;">Nama Petugas 1 (Kelas 10)</label>
                                <input id="swal-petugas1_nama" class="swal2-input" value="${petugas1_nama}" placeholder="Akan terisi otomatis" readonly style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
                            </div>
                            <div style="margin-bottom:15px;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px;">Nama Petugas 2 (Kelas 11)</label>
                                <input id="swal-petugas2_nama" class="swal2-input" value="${petugas2_nama}" placeholder="Akan terisi otomatis" readonly style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
                            </div>
                        </div>
                    `,
                    focusConfirm: false,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-save"></i> Simpan',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#e74c3c',
                    width: '500px',
                    didOpen: () => {
                        const petugasSelect = document.getElementById('swal-petugas_id');
                        petugasSelect.value = petugas_id;
                        petugasSelect.addEventListener('change', function() {
                            const selectedOption = this.options[this.selectedIndex];
                            if (selectedOption.value) {
                                document.getElementById('swal-petugas1_nama').value = selectedOption.getAttribute('data-petugas1');
                                document.getElementById('swal-petugas2_nama').value = selectedOption.getAttribute('data-petugas2');
                            } else {
                                document.getElementById('swal-petugas1_nama').value = '';
                                document.getElementById('swal-petugas2_nama').value = '';
                            }
                        });
                        petugasSelect.dispatchEvent(new Event('change'));
                    },
                    preConfirm: () => {
                        const tanggalInput = document.getElementById('swal-tanggal');
                        const petugasIdSelect = document.getElementById('swal-petugas_id');
                        const valueTanggal = tanggalInput.value;
                        const valuePetugasId = petugasIdSelect.value;
                        const today = new Date().toISOString().split('T')[0];
                       
                        if (!valueTanggal) {
                            Swal.showValidationMessage('Tanggal harus diisi!');
                            return false;
                        }
                        if (!valuePetugasId) {
                            Swal.showValidationMessage('Kode petugas harus dipilih!');
                            return false;
                        }
                        if (new Date(valueTanggal) < new Date(today)) {
                            Swal.showValidationMessage('Tanggal tidak boleh di masa lalu!');
                            return false;
                        }
                        return { tanggal: valueTanggal, petugas_id: valuePetugasId };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const { tanggal, petugas_id } = result.value;
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '';
                        form.innerHTML = `
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="edit_id" value="${id}">
                            <input type="hidden" name="edit_tanggal" value="${tanggal}">
                            <input type="hidden" name="edit_petugas_id" value="${petugas_id}">
                            <input type="hidden" name="edit_confirm" value="1">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };
            window.deleteJadwal = function(id, tanggal) {
                Swal.fire({
                    title: 'Hapus Jadwal?',
                    html: `Apakah Anda yakin ingin menghapus jadwal pada tanggal <strong>"${tanggal}"</strong>?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Hapus',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#1e3a8a',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '';
                        form.innerHTML = `
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="delete_id" value="${id}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };
            const printButton = document.getElementById('printButton');
            const jadwalCount = parseInt(document.getElementById('jadwalCount').value);
           
            if (printButton) {
                printButton.addEventListener('click', function() {
                    if (jadwalCount === 0) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Tidak ada jadwal untuk dicetak!',
                            confirmButtonColor: '#1e3a8a'
                        });
                        return;
                    }
                   
                    let exportUrl = `${window.location.pathname}?export=pdf`;
                    window.open(exportUrl, '_blank');
                });
            }
            <?php if ($show_confirm_modal && !$show_error_modal): ?>
            Swal.fire({
                title: 'KONFIRMASI JADWAL BARU',
                html: `
                    <div style="text-align: center; font-weight: bold; font-size: 1.1rem; line-height: 1.4; margin-bottom: 10px;">
                        Jadwal untuk tanggal ${formatDateDDMMYY('<?php echo htmlspecialchars($tanggal); ?>')}
                    </div>
                    <div style="text-align: center; font-size: 1rem; color: #555; line-height: 1.4;">
                        Dengan Petugas <strong><?php echo htmlspecialchars($petugas1_nama); ?></strong> DAN <strong><?php echo htmlspecialchars($petugas2_nama); ?></strong>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Konfirmasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#e74c3c'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    form.innerHTML = `
                        <input type="hidden" name="token" value="<?php echo $token; ?>">
                        <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
                        <input type="hidden" name="petugas_id" value="<?php echo htmlspecialchars($selected_petugas_id); ?>">
                        <input type="hidden" name="confirm" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    window.location.href = 'kelola_jadwal.php';
                }
            });
            <?php endif; ?>
            <?php if ($show_success_modal): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: '<?php echo addslashes($success); ?>',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>
            <?php if ($show_error_modal): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?php echo addslashes($error_message); ?>',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>