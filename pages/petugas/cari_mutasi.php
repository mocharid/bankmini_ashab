<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Mutasi - SCHOBANK SYSTEM</title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
    
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navigation Styles */
        .top-nav {
            background: var(--primary-dark);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .nav-brand {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
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
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .search-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
            max-width: 100%;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            padding-left: 2.5rem; /* Space for the icon */
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            bottom: 0.75rem; /* Position relative to bottom to align properly */
            color: var(--text-secondary);
            pointer-events: none; /* Ensures clicks pass through to the input */
        }

        .search-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            min-width: 120px;
            position: relative; /* For loading animation */
        }

        .search-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Filter Section */
        .filter-section {
            display: none;
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .filter-section:hover {
            box-shadow: var(--shadow-md);
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .date-inputs {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-btn {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            min-width: 120px;
            position: relative; /* For loading animation */
        }

        .filter-btn:hover {
            background: #e08600;
            transform: translateY(-2px);
        }

        .reset-btn {
            background: #f1f1f1;
            color: var(--text-secondary);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            position: relative; /* For loading animation */
        }

        .reset-btn:hover {
            background: #e1e1e1;
            transform: translateY(-2px);
        }

        /* Results Section */
        .results-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .table-responsive {
            overflow-x: auto;
            margin: 0 -1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--primary-color);
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .amount {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        .positive {
            color: var(--secondary-color);
        }

        .negative {
            color: var(--danger-color);
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-info {
            background: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        /* Results Count */
        .results-count {
            margin-bottom: 1rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Loading Spinner - Button specific */
        .button-spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s linear infinite;
            margin-right: 10px;
            display: none; /* Hide by default */
        }

        /* Filter toggle button */
        .filter-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: -1rem;
            margin-bottom: 1rem;
            padding: 0.5rem 1rem;
            background: var(--primary-light);
            border-radius: 8px;
            cursor: pointer;
            width: fit-content;
            color: var(--primary-dark);
            font-weight: 500;
            transition: var(--transition);
        }

        .filter-toggle:hover {
            background: #d0ddef;
        }

        /* Button loading animation */
        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading span {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .nav-buttons {
                width: 100%;
                justify-content: center;
            }

            .main-content {
                padding: 1rem;
            }

            .search-form, .filter-form {
                flex-direction: column;
                gap: 0.5rem;
            }

            .form-group {
                width: 100%;
            }

            .input-icon {
                bottom: 0.85rem; /* Adjust for mobile */
            }

            .search-btn, .filter-btn, .reset-btn {
                width: 100%;
                justify-content: center;
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .welcome-banner,
            .search-card,
            .results-card,
            .filter-section {
                padding: 1rem;
            }

            th, td {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
            
            .date-inputs {
                flex-direction: column;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">SCHOBANK</div>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-banner">
            <h2>
                <i class="fas fa-list-alt"></i>
                <span>Cek Mutasi Rekening</span>
            </h2>
        </div>

        <div class="search-card">
            <form id="searchForm" action="" method="GET" class="search-form">
                <div class="form-group">
                    <label for="no_rekening">No Rekening:</label>
                    <span class="input-icon"><i class="fas fa-credit-card"></i></span>
                    <input type="text" 
                           id="no_rekening" 
                           name="no_rekening" 
                           placeholder="Masukkan nomor rekening"
                           value="<?php echo isset($_GET['no_rekening']) ? htmlspecialchars($_GET['no_rekening']) : ''; ?>"
                           required>
                </div>
                <button type="submit" class="search-btn" id="searchBtn">
                    <span class="button-spinner" id="searchSpinner"></span>
                    <i class="fas fa-search"></i>
                    <span>Cari</span>
                </button>
            </form>
        </div>

        <?php if (isset($_GET['no_rekening']) && !empty($_GET['no_rekening'])): ?>
        <div class="filter-toggle" id="filterToggle">
            <i class="fas fa-filter"></i>
            <span>Filter Berdasarkan Tanggal</span>
        </div>

        <div class="filter-section" id="filterSection">
            <form id="filterForm" action="" method="GET" class="filter-form">
                <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($_GET['no_rekening']); ?>">
                
                <div class="form-group">
                    <label>Rentang Tanggal:</label>
                    <div class="date-inputs">
                        <div class="form-group">
                            <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                            <input type="text" 
                                id="start_date" 
                                name="start_date" 
                                placeholder="Tanggal Awal"
                                value="<?php echo isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : ''; ?>"
                                class="datepicker">
                        </div>
                        <div class="form-group">
                            <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                            <input type="text" 
                                id="end_date" 
                                name="end_date" 
                                placeholder="Tanggal Akhir"
                                value="<?php echo isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : ''; ?>"
                                class="datepicker">
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="filter-btn" id="filterBtn">
                        <span class="button-spinner" id="filterSpinner"></span>
                        <i class="fas fa-filter"></i>
                        <span>Terapkan Filter</span>
                    </button>
                    <button type="button" class="reset-btn" id="resetBtn">
                        <span class="button-spinner" id="resetSpinner"></span>
                        <i class="fas fa-undo"></i>
                        <span>Reset</span>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Results Section -->
        <div id="results">
            <?php
            if (isset($_GET['no_rekening'])) {
                $no_rekening = $conn->real_escape_string($_GET['no_rekening']);
                
                $where_clause = "r.no_rekening = '$no_rekening'";
                
                // Add date filtering if provided
                if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                    $start_date = $conn->real_escape_string($_GET['start_date']);
                    $start_date = date('Y-m-d', strtotime($start_date));
                    $where_clause .= " AND DATE(m.created_at) >= '$start_date'";
                }
                
                if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                    $end_date = $conn->real_escape_string($_GET['end_date']);
                    $end_date = date('Y-m-d', strtotime($end_date)) . ' 23:59:59';
                    $where_clause .= " AND m.created_at <= '$end_date'";
                }
                
                $query = "SELECT m.*, r.no_rekening 
                         FROM mutasi m 
                         JOIN rekening r ON m.rekening_id = r.id 
                         WHERE $where_clause 
                         ORDER BY m.created_at DESC";
                $result = $conn->query($query);
                $total_rows = $result ? $result->num_rows : 0;

                echo '<div class="results-card">';
                
                // Show filter status if any filter is applied
                if (isset($_GET['start_date']) && !empty($_GET['start_date']) || isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                    echo '<div class="alert alert-info" style="margin-bottom: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        <span>Menampilkan hasil filter ';
                    
                    if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                        echo 'dari ' . date('d/m/Y', strtotime($_GET['start_date']));
                    }
                    
                    if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                        echo ' sampai ' . date('d/m/Y', strtotime($_GET['end_date']));
                    }
                    
                    echo '</span>
                    </div>';
                }
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="results-count">Menampilkan ' . $total_rows . ' transaksi</div>';
                    echo '<div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Jumlah</th>
                                    <th>Saldo Akhir</th>
                                    <th>Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>';
                    
                    $counter = 1;
                    while ($row = $result->fetch_assoc()) {
                        $amountClass = $row['jumlah'] < 0 ? 'negative' : 'positive';
                        $amountPrefix = $row['jumlah'] < 0 ? '-' : '+';
                        echo "<tr>
                            <td>{$counter}</td>
                            <td class='amount {$amountClass}'>{$amountPrefix}Rp " . number_format(abs($row['jumlah']), 2, ',', '.') . "</td>
                            <td class='amount'>Rp " . number_format($row['saldo_akhir'], 2, ',', '.') . "</td>
                            <td>" . date('d/m/Y H:i', strtotime($row['created_at'])) . "</td>
                        </tr>";
                        $counter++;
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span>Tidak ada mutasi untuk rekening ini';
                    
                    if (isset($_GET['start_date']) && !empty($_GET['start_date']) || isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                        echo ' dengan filter yang diterapkan';
                    }
                    
                    echo '.</span>
                    </div>';
                }
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize date picker
            $(".datepicker").flatpickr({
                dateFormat: "d/m/Y",
                allowInput: true,
                locale: {
                    firstDayOfWeek: 1
                }
            });
            
            // Toggle filter section
            $("#filterToggle").on("click", function() {
                $("#filterSection").slideToggle(300);
                const icon = $(this).find("i");
                if (icon.hasClass("fa-filter")) {
                    icon.removeClass("fa-filter").addClass("fa-times");
                    $(this).find("span").text("Tutup Filter");
                } else {
                    icon.removeClass("fa-times").addClass("fa-filter");
                    $(this).find("span").text("Filter Berdasarkan Tanggal");
                }
            });
            
            // Show filter section if filters are applied
            <?php if (isset($_GET['start_date']) || isset($_GET['end_date'])): ?>
                $("#filterSection").show();
                $("#filterToggle").find("i").removeClass("fa-filter").addClass("fa-times");
                $("#filterToggle").find("span").text("Tutup Filter");
            <?php endif; ?>
            
            // Form handling with improved loading
            $('#searchForm').on('submit', function(e) {
                e.preventDefault();
                
                const input = $('#no_rekening');
                const value = input.val().trim();
                
                if (!value) {
                    alert('Silakan masukkan nomor rekening');
                    input.focus();
                    return false;
                }
                
                // Show loading spinner in button
                $('#searchBtn').addClass('btn-loading');
                
                // Submit after short delay to show animation
                setTimeout(() => {
                    this.submit();
                }, 300);
            });
            
            // Filter form handling
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                // Show loading spinner in button
                $('#filterBtn').addClass('btn-loading');
                
                // Submit after short delay
                setTimeout(() => {
                    this.submit();
                }, 300);
            });
            
            // Reset button functionality
            $('#resetBtn').on('click', function() {
                $('#resetBtn').addClass('btn-loading');
                
                // Redirect to the page with just the account number
                setTimeout(() => {
                    window.location.href = window.location.pathname + '?no_rekening=' + 
                        encodeURIComponent($('input[name="no_rekening"]').val());
                }, 300);
            });

            // Account number input handling
            $('#no_rekening').on('input', function() {
                this.value = this.value.toUpperCase();
                
                if (this.value.length > 20) {
                    this.value = this.value.slice(0, 20);
                }
            });

            // Table row hover effect
            $(document).on('mouseenter', 'table tbody tr', function() {
                $(this).css('background-color', '#f8fafc');
            }).on('mouseleave', 'table tbody tr', function() {
                $(this).css('background-color', '');
            });

            // Keyboard navigation
            $('#no_rekening').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#searchForm').submit();
                }
            });
            
            // Add animation when results are loaded
            if ($('#results').children().length > 0) {
                $('#results').hide().fadeIn(500);
            }
        });
    </script>
</body>
</html>