<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Validate session
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Sesi tidak valid']));
}

// Get report date & format
$date   = $_GET['date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'pdf';

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    error_log("Invalid date format: $date");
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'Format tanggal tidak valid']));
}

// Function to format date in Indonesian
function formatIndonesianDate($date) {
    $days   = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $timestamp = strtotime($date);
    $day_name  = $days[date('w', $timestamp)];
    $day       = date('d', $timestamp);
    $month     = $months[date('n', $timestamp) - 1];
    $year      = date('Y', $timestamp);
    return "$day_name, $day $month $year";
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

try {
    /* ============================================================= */
    /* 1. AMBIL NAMA PETUGAS BERDASARKAN JADWAL PADA TANGGAL TERSEBUT */
    /* ============================================================= */
    $officer1_name = 'Tidak Ada Jadwal';
    $officer2_name = 'Tidak Ada Jadwal';

    $jadwal_query = "SELECT pi.petugas1_nama, pi.petugas2_nama
                     FROM petugas_tugas pt
                     JOIN petugas_info pi ON pt.petugas1_id = pi.user_id
                     WHERE pt.tanggal = ?
                     LIMIT 1";

    $stmt_jadwal = $conn->prepare($jadwal_query);
    if ($stmt_jadwal) {
        $stmt_jadwal->bind_param("s", $date);
        $stmt_jadwal->execute();
        $jadwal_result = $stmt_jadwal->get_result();

        if ($jadwal_result && $jadwal_result->num_rows > 0) {
            $jadwal_data   = $jadwal_result->fetch_assoc();
            $officer1_name = !empty($jadwal_data['petugas1_nama']) ? $jadwal_data['petugas1_nama'] : 'Tidak Ada Jadwal';
            $officer2_name = !empty($jadwal_data['petugas2_nama']) ? $jadwal_data['petugas2_nama'] : 'Tidak Ada Jadwal';
        }
        $stmt_jadwal->close();
    }
    /* ============================================================= */

    /* 2. AMBIL DATA TRANSAKSI */
    $query = "SELECT
                t.no_transaksi,
                t.jenis_transaksi,
                t.jumlah,
                t.created_at,
                u.nama AS nama_siswa,
                CONCAT_WS(' ', tk.nama_tingkatan, k.nama_kelas) AS nama_kelas
              FROM transaksi t
              JOIN rekening r ON t.rekening_id = r.id
              JOIN users u ON r.user_id = u.id
              LEFT JOIN kelas k ON u.kelas_id = k.id
              LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
              WHERE DATE(t.created_at) = ?
                AND t.jenis_transaksi != 'transfer'
                AND t.petugas_id IS NOT NULL
                AND (t.status = 'approved' OR t.status IS NULL)
              ORDER BY t.created_at DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Gagal menyiapkan kueri transaksi: " . $conn->error);
    }
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();

    /* 3. HITUNG TOTAL */
    $total_query = "SELECT
                      COUNT(id) as total_transactions,
                      SUM(CASE WHEN jenis_transaksi = 'setor'  AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
                      SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
                    FROM transaksi
                    WHERE DATE(created_at) = ?
                      AND jenis_transaksi != 'transfer'
                      AND petugas_id IS NOT NULL";

    $stmt_total = $conn->prepare($total_query);
    if (!$stmt_total) {
        throw new Exception("Gagal menyiapkan kueri total: " . $conn->error);
    }
    $stmt_total->bind_param("s", $date);
    $stmt_total->execute();
    $totals = $stmt_total->get_result()->fetch_assoc();
    $stmt_total->close();

    $saldo_bersih = ($totals['total_debit'] ?? 0) - ($totals['total_kredit'] ?? 0);
    $saldo_bersih = max(0, $saldo_bersih);

    /* 4. SIAPKAN DATA TRANSAKSI UNTUK PDF */
    $transactions = [];
    $row_number   = 1;
    $has_transactions = false;

    while ($row = $result->fetch_assoc()) {
        $has_transactions = true;
        $row['no']            = $row_number++;
        $row['display_jenis'] = $row['jenis_transaksi'] == 'setor' ? 'Setor' : 'Tarik';
        $row['display_nama']  = $row['nama_siswa'] ?? 'N/A';
        $row['display_kelas'] = trim($row['nama_kelas'] ?? '') ?: 'N/A';
        $transactions[]       = $row;
    }
    $stmt->close();

    /* 5. GENERATE PDF */
    if ($format !== 'pdf') {
        throw new Exception("Format tidak valid. Hanya 'pdf' yang didukung.");
    }

    if (!file_exists('../../tcpdf/tcpdf.php')) {
        throw new Exception("Pustaka TCPDF tidak ditemukan di ../../tcpdf/tcpdf.php");
    }
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        public function Header() {
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
            $this->Ln(6);
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
            $this->Ln(6);
            $this->SetFont('helvetica', '', 8);
            $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
            $this->Line(10, 35, 200, 35);
        }
    }

    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Harian Transaksi');
    $pdf->SetMargins(10, 40, 10);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(0);
    $pdf->SetAutoPageBreak(TRUE, 20);
    $pdf->SetCellPadding(1.5);
    $pdf->AddPage();

    // Title
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'LAPORAN TRANSAKSI PETUGAS', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Tanggal: ' . formatIndonesianDate($date), 0, 1, 'C');

    // Officer Information
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(190, 7, 'INFORMASI PETUGAS', 1, 1, 'C', true);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(95, 6, 'Petugas 1', 1, 0, 'C', false);
    $pdf->Cell(95, 6, 'Petugas 2', 1, 1, 'C', false);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(95, 6, $officer1_name, 1, 0, 'C', false);
    $pdf->Cell(95, 6, $officer2_name, 1, 1, 'C', false);

    // Transaction Summary
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(190, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 8);
    if ($has_transactions) {
        $pdf->Cell(63, 7, 'Total Transaksi: ' . ($totals['total_transactions'] ?? 0), 1, 0, 'C', false);
        $pdf->Cell(63, 7, 'Setor: ' . formatRupiah($totals['total_debit'] ?? 0), 1, 0, 'C', false);
        $pdf->Cell(64, 7, 'Tarik: ' . formatRupiah($totals['total_kredit'] ?? 0), 1, 1, 'C', false);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(190, 7, 'Saldo Bersih: ' . formatRupiah($saldo_bersih), 1, 1, 'C', false);
        $pdf->SetFont('helvetica', '', 8);
    } else {
        $pdf->Cell(190, 7, 'Tidak ada aktivitas transaksi hari ini', 1, 1, 'C', false);
    }

    // Transaction Details
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(190, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);

    if ($has_transactions) {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C', true);
        $pdf->Cell(65, 6, 'Nama', 1, 0, 'C', true);
        $pdf->Cell(35, 6, 'Kelas', 1, 0, 'C', true);
        $pdf->Cell(30, 6, 'Jenis', 1, 0, 'C', true);
        $pdf->Cell(50, 6, 'Jumlah', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 8);
        $row_count = 0;
        foreach ($transactions as $row) {
            $bg_color = $row_count % 2 == 0 ? 245 : 255;
            $pdf->SetFillColor($bg_color, $bg_color, $bg_color);

            $displayName  = mb_strlen($row['display_nama']) > 40 ? mb_substr($row['display_nama'], 0, 37) . '...' : $row['display_nama'];
            $displayKelas = mb_strlen($row['display_kelas']) > 15 ? mb_substr($row['display_kelas'], 0, 12) . '...' : $row['display_kelas'];

            $pdf->Cell(10, 6, $row['no'], 1, 0, 'C', true);
            $pdf->Cell(65, 6, $displayName, 1, 0, 'L', true);
            $pdf->Cell(35, 6, $displayKelas, 1, 0, 'C', true);
            $pdf->Cell(30, 6, $row['display_jenis'], 1, 0, 'C', true);
            $pdf->Cell(50, 6, formatRupiah($row['jumlah']), 1, 1, 'R', true);
            $row_count++;
        }

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(140, 6, 'Total', 1, 0, 'C', true);
        $pdf->Cell(50, 6, formatRupiah($saldo_bersih), 1, 1, 'R', true);
    } else {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(190, 6, 'Tidak ada transaksi debit atau kredit hari ini', 1, 1, 'C', false);
    }

    // Notes
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetFillColor(240, 248, 255);
    $pdf->MultiCell(190, 12,
        "Catatan:\n- Laporan ini hanya mencakup transaksi setor dan tarik yang dilakukan oleh petugas.\n- Nama petugas diambil dari jadwal petugas pada tanggal tersebut.",
        1, 'L', true);

    $pdf->Output('laporan_transaksi_' . $date . '.pdf', 'D');

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gagal menghasilkan laporan: ' . $e->getMessage()]);
}

$conn->close();
?>