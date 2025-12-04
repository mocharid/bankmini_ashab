<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
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

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out;
            top: 0;
            left: 0;
        }

        .sidebar-header {
            padding: 15px 20px 10px;
            background: var(--primary-dark);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 90px;
        }

        .logo-container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            width: 100%;
            height: 70px;
            text-align: center;
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            margin-bottom: 4px;
        }

        .logo-subtitle {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.8);
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 400;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .sidebar-footer {
            background: var(--primary-dark);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            bottom: 0;
            z-index: 101;
            padding: 5px 0;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 8px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
            border-radius: 4px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 4px;
            border: 2px solid var(--scrollbar-track);
        }

        .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
        }

        .menu-label {
            padding: 10px 25px 8px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 600;
            margin-top: 12px;
            user-select: none;
        }

        .menu-item {
            position: relative;
            margin: 5px 0;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 400;
            font-size: 0.9rem;
        }

        .menu-item a:hover,
        .menu-item a.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .dropdown-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 25px;
            width: 100%;
            text-align: left;
            background: none;
            color: rgba(255, 255, 255, 0.85);
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 400;
        }

        .dropdown-btn:hover,
        .dropdown-btn.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .dropdown-btn .menu-icon {
            display: flex;
            align-items: center;
        }

        .dropdown-btn .menu-icon i:first-child {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .dropdown-btn .arrow {
            transition: transform 0.3s ease;
            font-size: 0.8rem;
        }

        .dropdown-btn.active .arrow {
            transform: rotate(180deg);
        }

        .dropdown-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(0, 0, 0, 0.15);
        }

        .dropdown-container.show {
            max-height: 300px;
        }

        .dropdown-container a {
            padding: 12px 20px 12px 60px;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.85rem;
            border-left: 4px solid transparent;
        }

        .dropdown-container a:hover,
        .dropdown-container a.active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .logout-btn {
            color: var(--danger-color);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
                z-index: 1000;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            body.sidebar-active::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                transition: opacity 0.3s ease;
                opacity: 1;
            }

            body:not(.sidebar-active)::before {
                opacity: 0;
                pointer-events: none;
            }
        }

        @media (max-width: 480px) {
            .sidebar {
                width: 240px;
            }

            .sidebar-header {
                padding: 10px 15px 5px;
                min-height: 75px;
            }

            .logo-container {
                height: 55px;
            }

            .logo-text {
                font-size: 1.5rem;
            }

            .logo-subtitle {
                font-size: 0.7rem;
            }

            .menu-item a,
            .dropdown-btn {
                padding: 12px 20px;
                font-size: 0.85rem;
            }

            .dropdown-container a {
                padding: 10px 15px 10px 50px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <?php
    require_once '../../includes/auth.php';
    $admin_id = $_SESSION['user_id'] ?? 0;
    if ($admin_id <= 0) {
        header('Location: ../login.php');
        exit();
    }
    ?>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo-text">My Schobank</div>
                <div class="logo-subtitle">Admin Panel</div>
            </div>
        </div>

        <div class="sidebar-content">
            <div class="sidebar-menu">
                <div class="menu-label">Utama</div>
                <div class="menu-item">
                    <a href="dashboard.php">
                        <i class="fas fa-chart-pie"></i> Dashboard 
                    </a>
                </div>

                <div class="menu-label">Transaksi</div>
                <div class="menu-item">
                    <button class="dropdown-btn" id="transaksiDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-money-check-alt"></i>  Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="transaksiDropdownContainer">
                        <a href="setor.php">
                            <i class="fas fa-hand-holding-usd"></i> Setoran
                        </a>
                        <a href="tarik.php">
                            <i class="fas fa-money-bill-wave"></i> Penarikan
                        </a>
                    </div>
                </div>

                <div class="menu-label">Nasabah & Petugas</div>
                <div class="menu-item">
                    <a href="kelola_rekening.php">
                        <i class="fas fa-address-card"></i> Rekening Nasabah
                    </a>
                </div>

                <div class="menu-item">
                    <a href="manajemen_akun.php">
                        <i class="fas fa-users-cog"></i> Kelola Akun Nasabah
                    </a>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn" id="petugasDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-user-tie"></i> Manajemen Petugas
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="petugasDropdownContainer">
                        <a href="kelola_akun_petugas.php">
                            <i class="fas fa-id-card"></i> Data Akun Petugas
                        </a>
                        <a href="kelola_jadwal.php">
                            <i class="fas fa-calendar-check"></i> Jadwal Petugas
                        </a>
                        <a href="kontrol_akses_petugas.php">
                            <i class="fas fa-key"></i> Hak Akses Sistem
                        </a>
                    </div>
                </div>

                <div class="menu-label">Data & Laporan</div>
                <div class="menu-item">
                    <button class="dropdown-btn" id="dataMasterDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-database"></i> Data Master Sekolah
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="dataMasterDropdownContainer">
                        <a href="kelola_jurusan.php">
                            <i class="fas fa-book-open"></i> Data Jurusan
                        </a>
                        <a href="kelola_tingkatan.php">
                            <i class="fas fa-layer-group"></i> Tingkatan Kelas
                        </a>
                        <a href="kelola_kelas.php">
                            <i class="fas fa-chalkboard"></i> Data Kelas
                        </a>
                    </div>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn" id="laporanDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-file-contract"></i> Laporan Keuangan
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="laporanDropdownContainer">
                        <a href="transaksi_admin.php">
                            <i class="fas fa-receipt"></i> Riwayat Transaksi Admin
                        </a>
                        <a href="transaksi_petugas.php">
                            <i class="fas fa-file-invoice"></i> Riwayat Transaksi Petugas
                        </a>
                        <a href="rekapitulasi_transaksi.php">
                            <i class="fas fa-calculator"></i> Rekap Transaksi 
                        </a>
                    </div>
                </div>

                <div class="menu-item">
                    <a href="rekapitulasi_absensi.php">
                        <i class="fas fa-clipboard-check"></i> Rekap Kehadiran Petugas
                    </a>
                </div>

                <div class="menu-label">Pengaturan</div>
                <div class="menu-item">
                    <a href="pengaturan.php">
                        <i class="fas fa-cog"></i> Pengaturan Akun
                    </a>
                </div>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="menu-item">
                <a href="../../logout.php" class="logout-btn">
                    <i class="fas fa-power-off"></i> Keluar
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const currentPath = window.location.pathname.split('/').pop();

            const dropdownButtons = document.querySelectorAll('.dropdown-btn');
            dropdownButtons.forEach(button => {
                const dropdownContainer = button.nextElementSibling;
                const links = dropdownContainer.querySelectorAll('a');

                let isActive = false;
                links.forEach(link => {
                    const linkPath = link.getAttribute('href').split('/').pop();
                    if (linkPath === currentPath) {
                        link.classList.add('active');
                        isActive = true;
                    }
                });

                if (isActive) {
                    button.classList.add('active');
                    dropdownContainer.classList.add('show');
                }

                button.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const isCurrentlyActive = this.classList.contains('active');

                    dropdownButtons.forEach(btn => {
                        const container = btn.nextElementSibling;
                        if (btn !== this) {
                            btn.classList.remove('active');
                            container.classList.remove('show');
                        }
                    });

                    if (!isCurrentlyActive) {
                        this.classList.add('active');
                        dropdownContainer.classList.add('show');
                    } else {
                        this.classList.remove('active');
                        dropdownContainer.classList.remove('show');
                    }
                });
            });

            document.addEventListener('click', function (e) {
                dropdownButtons.forEach(button => {
                    const dropdownContainer = button.nextElementSibling;
                    const links = dropdownContainer.querySelectorAll('a');
                    let keepOpen = false;

                    links.forEach(link => {
                        const linkPath = link.getAttribute('href').split('/').pop();
                        if (linkPath === currentPath) {
                            keepOpen = true;
                        }
                    });

                    if (!keepOpen && !button.contains(e.target)) {
                        button.classList.remove('active');
                        dropdownContainer.classList.remove('show');
                    }
                });
            });

            const highlightLinks = [
                "dashboard.php",
                "pengaturan.php",
                "../../logout.php",
                "kelola_rekening.php",
                "manajemen_akun.php",
                "kontrol_akses_petugas.php",
                "kelola_akun_petugas.php",
                "kelola_jadwal.php",
                "rekapitulasi_absensi.php",
            ];

            highlightLinks.forEach(linkHref => {
                const link = document.querySelector(`a[href="${linkHref}"]`);
                if (link && currentPath === linkHref.split('/').pop()) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>
