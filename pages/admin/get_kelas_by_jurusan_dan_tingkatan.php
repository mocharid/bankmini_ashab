<?php
require_once '../../includes/db_connection.php';

// Initialize response
$response = ['success' => false, 'message' => '', 'kelas' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle JSON API for POST requests
    header('Content-Type: application/json');
    
    $jurusan_id = isset($_POST['jurusan_id']) ? (int)$_POST['jurusan_id'] : 0;
    $tingkatan_kelas_id = isset($_POST['tingkatan_kelas_id']) ? (int)$_POST['tingkatan_kelas_id'] : 0;

    if ($jurusan_id <= 0 || $tingkatan_kelas_id <= 0) {
        $response['message'] = 'Jurusan dan tingkatan kelas harus dipilih.';
        echo json_encode($response);
        exit;
    }

    try {
        $query = "SELECT k.id, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas 
                  FROM kelas k 
                  JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                  WHERE k.jurusan_id = ? AND k.tingkatan_kelas_id = ? 
                  ORDER BY k.nama_kelas";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Error preparing kelas query: " . $conn->error);
            $response['message'] = 'Terjadi kesalahan server.';
            echo json_encode($response);
            exit;
        }

        $stmt->bind_param("ii", $jurusan_id, $tingkatan_kelas_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $kelas = [];
        while ($row = $result->fetch_assoc()) {
            $kelas[] = [
                'id' => $row['id'],
                'nama_kelas' => $row['nama_kelas']
            ];
        }

        if (count($kelas) > 0) {
            $response['success'] = true;
            $response['kelas'] = $kelas;
        } else {
            $response['message'] = 'Tidak ada kelas tersedia untuk kombinasi jurusan dan tingkatan kelas ini.';
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching kelas: " . $e->getMessage());
        $response['message'] = 'Terjadi kesalahan server.';
    }
    
    echo json_encode($response);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Handle HTML output for GET requests (for data_siswa.php compatibility)
    header('Content-Type: text/html; charset=UTF-8');
    
    $jurusan_id = isset($_GET['jurusan_id']) ? (int)$_GET['jurusan_id'] : 0;
    $tingkatan_id = isset($_GET['tingkatan_id']) ? (int)$_GET['tingkatan_id'] : 0;
    $action = isset($_GET['action']) ? $_GET['action'] : 'get_tingkatan';
    
    $output = '';
    
    try {
        if ($action === 'get_tingkatan' && $jurusan_id > 0) {
            // Fetch class levels (tingkatan_kelas)
            $stmt = $conn->prepare("SELECT DISTINCT tk.id, tk.nama_tingkatan 
                                    FROM tingkatan_kelas tk
                                    JOIN kelas k ON tk.id = k.tingkatan_kelas_id 
                                    WHERE k.jurusan_id = ? 
                                    ORDER BY tk.nama_tingkatan");
            if (!$stmt) {
                error_log("Error preparing tingkatan query: " . $conn->error);
                exit;
            }
            $stmt->bind_param("i", $jurusan_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $output .= "<option value='{$row['id']}'>" . htmlspecialchars($row['nama_tingkatan']) . "</option>";
            }
            $stmt->close();
        } elseif ($action === 'get_kelas' && $jurusan_id > 0) {
            // Fetch classes (kelas)
            $query = "SELECT k.id, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas 
                      FROM kelas k 
                      JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                      WHERE k.jurusan_id = ?";
            $params = [$jurusan_id];
            $types = 'i';

            if ($tingkatan_id > 0) {
                $query .= " AND k.tingkatan_kelas_id = ?";
                $params[] = $tingkatan_id;
                $types .= 'i';
            }

            $query .= " ORDER BY tk.nama_tingkatan, k.nama_kelas";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                error_log("Error preparing kelas query: " . $conn->error);
                exit;
            }
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $output .= "<option value='{$row['id']}'>" . htmlspecialchars($row['nama_kelas']) . "</option>";
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error in get_kelas_by_jurusan_dan_tingkatan: " . $e->getMessage());
        $output = '';
    }
    
    echo $output;
} else {
    header('Content-Type: application/json');
    $response['message'] = 'Metode tidak diizinkan.';
    echo json_encode($response);
}

$conn->close();
?>