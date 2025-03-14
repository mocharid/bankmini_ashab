<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php'; 
use TCPDF as TCPDF;

// Fungsi generateStruk yang dimodifikasi
function generateStruk($data) {
    // Buat kelas turunan TCPDF untuk perforasi tepi
    class MYPDF extends TCPDF {
        public function Footer() {
            // Posisi di 15 mm dari bawah
            $this->SetY(-15);
            // Set gaya garis untuk perforasi
            $this->SetLineStyle(array('dash' => '1,1', 'color' => array(160, 160, 160)));
            // Gambar garis bergerigi/perforasi horizontal
            $this->Line(0, $this->GetY(), $this->getPageWidth(), $this->GetY());
        }
    }
    
    // Buat dokumen PDF baru dengan ukuran khusus untuk thermal printer (80mm width)
    $pdf = new MYPDF('P', 'mm', array(80, 150), true, 'UTF-8', false);
    
    // Informasi dokumen
    $pdf->SetCreator('SCHOBANK SYSTEM');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Struk Transaksi');
    
    // Hilangkan header dan footer default
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true); // Kita gunakan footer untuk perforasi
    
    // Atur margin
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(true, 15); // Margin bawah untuk perforasi
    
    // Tambah halaman
    $pdf->AddPage();
    
    // Set background warna broken white (thermal receipt style)
    $pdf->SetFillColor(252, 252, 250);
    $pdf->Rect(0, 0, $pdf->getPageWidth(), $pdf->getPageHeight(), 'F');
    
    // Set font
    $pdf->SetFont('courier', 'B', 12); // Gunakan font courier untuk thermal printer look
    $pdf->SetTextColor(0, 0, 0); // Teks hitam
    
    // Logo/judul
    $pdf->Cell(0, 10, 'SCHOBANK', 0, 1, 'C');
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 5, 'Sistem Bank Sekolah', 0, 1, 'C');
    $pdf->Ln(2);
    
    // Tambahkan garis pemisah dengan gaya putus-putus (bergerigi)
    $pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(0, 0, 0)));
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(2);
    
    // Detail transaksi
    $pdf->SetFont('courier', 'B', 8);
    
    // Tampilkan label transaksi yang berbeda
    $transaksi_label = 'BUKTI TRANSAKSI';
    if (isset($data['jenis_transaksi'])) {
        if ($data['jenis_transaksi'] == 'setor') {
            $transaksi_label = 'BUKTI SETORAN';
        } else if ($data['jenis_transaksi'] == 'tarik') {
            $transaksi_label = 'BUKTI PENARIKAN';
        } else if ($data['jenis_transaksi'] == 'transfer') {
            $transaksi_label = 'BUKTI TRANSFER';
        }
    }
    
    $pdf->Cell(0, 5, $transaksi_label, 0, 1, 'C');
    $pdf->Ln(2);
    
    $pdf->SetFont('courier', '', 8);
    
    // Lebar kolom
    $w1 = 25; // Lebar label
    $w2 = 45; // Lebar nilai
    
    // No Transaksi
    $pdf->Cell($w1, 5, 'No. Transaksi:', 0, 0);
    $pdf->Cell($w2, 5, $data['no_transaksi'], 0, 1);
    
    // Tanggal
    $pdf->Cell($w1, 5, 'Tanggal:', 0, 0);
    $pdf->Cell($w2, 5, date('d-m-Y H:i:s', strtotime($data['tanggal'])), 0, 1);
    
    // Handle jenis transaksi yang berbeda
    if (isset($data['jenis_transaksi'])) {
        if ($data['jenis_transaksi'] == 'setor') {
            // Untuk setoran, tampilkan rekening tujuan
            $pdf->Cell($w1, 5, 'Ke Rekening:', 0, 0);
            $pdf->Cell($w2, 5, $data['rekening_tujuan'], 0, 1);
            
            if (!empty($data['nama_penerima'])) {
                $pdf->Cell($w1, 5, 'Nama Pemilik:', 0, 0);
                $pdf->Cell($w2, 5, $data['nama_penerima'], 0, 1);
            }
            
            $pdf->Cell($w1, 5, 'Disetor Oleh:', 0, 0);
            $pdf->Cell($w2, 5, 'Petugas Bank', 0, 1);
            
        } else if ($data['jenis_transaksi'] == 'tarik') {
            // Untuk penarikan, tampilkan rekening asal
            $pdf->Cell($w1, 5, 'Dari Rekening:', 0, 0);
            $pdf->Cell($w2, 5, $data['rekening_asal'], 0, 1);
            
            if (!empty($data['nama_pemilik'])) {
                $pdf->Cell($w1, 5, 'Nama Pemilik:', 0, 0);
                $pdf->Cell($w2, 5, $data['nama_pemilik'], 0, 1);
            }
            
            $pdf->Cell($w1, 5, 'Dilayani Oleh:', 0, 0);
            $pdf->Cell($w2, 5, 'Petugas Bank', 0, 1);
            
        } else {
            // Untuk transfer, tampilkan kedua rekening
            $pdf->Cell($w1, 5, 'Dari Rekening:', 0, 0);
            $pdf->Cell($w2, 5, $data['rekening_asal'], 0, 1);
            
            if (!empty($data['nama_pemilik'])) {
                $pdf->Cell($w1, 5, 'Nama Pengirim:', 0, 0);
                $pdf->Cell($w2, 5, $data['nama_pemilik'], 0, 1);
            }
            
            if (!empty($data['rekening_tujuan'])) {
                $pdf->Cell($w1, 5, 'Ke Rekening:', 0, 0);
                $pdf->Cell($w2, 5, $data['rekening_tujuan'], 0, 1);
                
                if (!empty($data['nama_penerima'])) {
                    $pdf->Cell($w1, 5, 'Nama Penerima:', 0, 0);
                    $pdf->Cell($w2, 5, $data['nama_penerima'], 0, 1);
                }
            }
        }
    }
    
    // Jumlah dengan gaya thermal printer
    $pdf->Ln(1);
    $pdf->SetLineStyle(array('dash' => '0.5,0.5', 'color' => array(150, 150, 150)));
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(2);
    
    $pdf->SetFont('courier', 'B', 9);
    $pdf->Cell($w1, 5, 'Jumlah:', 0, 0);
    $pdf->Cell($w2, 5, 'Rp ' . number_format($data['jumlah'], 0, ',', '.'), 0, 1);
    
    $pdf->Ln(1);
    $pdf->SetLineStyle(array('dash' => '0.5,0.5', 'color' => array(150, 150, 150)));
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(2);
    
    // Tambahkan tanda * pada nomor transaksi untuk gaya struk thermal
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 5, '* ' . $data['no_transaksi'] . ' *', 0, 1, 'C');
    
    // Tambahkan catatan kaki
    $pdf->SetFont('courier', 'I', 7);
    $pdf->Cell(0, 4, 'Struk ini merupakan bukti transaksi yang sah', 0, 1, 'C');
    $pdf->Cell(0, 4, 'Simpan sebagai bukti pembayaran Anda', 0, 1, 'C');
    $pdf->Cell(0, 4, 'Terima kasih telah menggunakan SCHOBANK', 0, 1, 'C');
    
    // Tambahkan garis perforasi kecil di bagian bawah
    $pdf->SetLineStyle(array('dash' => '0.5,0.5', 'color' => array(0, 0, 0)));
    $pdf->Line(5, $pdf->GetY() + 2, 75, $pdf->GetY() + 2);
    $pdf->Ln(3);
    
    // Tambahkan QR code dengan nomor transaksi di bagian bawah, UKURAN LEBIH KECIL
    $style = array(
        'border' => false,
        'padding' => 0,
        'fgcolor' => array(0, 0, 0),
        'bgcolor' => false
    );
    
    // Posisikan QR code di bagian bawah dengan ukuran lebih kecil (15x15 mm)
    $pdf->Ln(2);
    $qr_size = 15; // Ukuran QR code dikecilkan dari 25mm menjadi 15mm
    $qr_x = ($pdf->getPageWidth() - $qr_size) / 2;
    $pdf->write2DBarcode($data['no_transaksi'], 'QRCODE,L', $qr_x, $pdf->GetY(), $qr_size, $qr_size, $style);
    $pdf->Ln($qr_size + 5);
    
    // Tambahkan dotted line di bawah QR code
    $pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(0, 0, 0)));
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    
    // Tambahkan teks "Potong di Sini" dengan gaya dashed line
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('courier', '', 6);
    $pdf->Cell(0, 4, '- - - - - - - - POTONG DI SINI - - - - - - - -', 0, 1, 'C');
    
    // Dapatkan direktori root dokumen
    $doc_root = $_SERVER['DOCUMENT_ROOT'];
    $app_path = '/bankmini'; // Ubah ini sesuai dengan path aplikasi Anda jika berbeda
    
    // Tentukan direktori upload relatif terhadap root dokumen
    $upload_dir = $doc_root . $app_path . '/uploads/struk/';
    
    // Buat direktori jika tidak ada
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Buat nama file
    $filename = 'struk_' . $data['no_transaksi'] . '.pdf';
    $filepath = $upload_dir . $filename;
    
    // Simpan PDF ke file
    $pdf->Output($filepath, 'F');
    
    // Kembalikan informasi file dengan URL yang dapat diakses web
    return [
        'file_url' => $app_path . '/uploads/struk/' . $filename,
        'filename' => $filename
    ];
}

if (!isset($_GET['no_transaksi'])) {
    die("No Transaksi tidak ditemukan.");
}

$no_transaksi = $_GET['no_transaksi'];

// Ambil data transaksi dari database
$query = "SELECT 
              t.no_transaksi, 
              t.jenis_transaksi, 
              t.jumlah, 
              t.created_at AS tanggal, 
              r1.no_rekening AS rekening_asal, 
              r2.no_rekening AS rekening_tujuan, 
              u1.nama AS nama_pemilik,
              u2.nama AS nama_penerima
          FROM transaksi t
          LEFT JOIN rekening r1 ON t.rekening_id = r1.id
          LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
          LEFT JOIN users u1 ON r1.user_id = u1.id
          LEFT JOIN users u2 ON r2.user_id = u2.id
          WHERE t.no_transaksi = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $no_transaksi);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Transaksi tidak ditemukan.");
}

$transaksi = $result->fetch_assoc();

// Generate struk
$struk = generateStruk([
    'no_transaksi' => $transaksi['no_transaksi'],
    'tanggal' => $transaksi['tanggal'],
    'jenis_transaksi' => $transaksi['jenis_transaksi'],
    'rekening_asal' => $transaksi['rekening_asal'],
    'rekening_tujuan' => $transaksi['rekening_tujuan'],
    'nama_pemilik' => $transaksi['nama_pemilik'],
    'nama_penerima' => $transaksi['nama_penerima'],
    'jumlah' => $transaksi['jumlah']
]);

// Redirect ke file PDF yang dihasilkan
header("Location: " . $struk['file_url']);
exit;
?>