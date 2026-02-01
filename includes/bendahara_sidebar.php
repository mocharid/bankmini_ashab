<?php
/**
 * Bendahara Sidebar Component
 * File: includes/bendahara_sidebar.php
 * Reusable sidebar menu for all bendahara pages
 */

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-brand">
            <img src="<?php echo $base_url; ?>/assets/images/side.png" alt="KASDIG">
        </a>
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <a href="dashboard.php" class="menu-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>

        <!-- MASTER DATA Section -->
        <div class="menu-header">MASTER DATA</div>

        <div
            class="menu-item has-submenu <?php echo in_array($current_page, ['kategori.php', 'item_pembayaran.php']) ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i>
            <span>Pengaturan Pembayaran</span>
            <i class="fas fa-chevron-right submenu-arrow"></i>
        </div>
        <div
            class="submenu <?php echo in_array($current_page, ['kategori.php', 'item_pembayaran.php']) ? 'open' : ''; ?>">
            <a href="kategori.php"
                class="submenu-item <?php echo ($current_page == 'kategori.php') ? 'active' : ''; ?>">
                <i class="fas fa-folder"></i> Kategori
            </a>
            <a href="item_pembayaran.php"
                class="submenu-item <?php echo ($current_page == 'item_pembayaran.php') ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> Item Pembayaran
            </a>
        </div>

        <!-- MANAJEMEN Section -->
        <div class="menu-header">MANAJEMEN</div>

        <a href="status_pembayaran_siswa.php"
            class="menu-item <?php echo ($current_page == 'status_pembayaran_siswa.php') ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>Data Siswa</span>
        </a>

        <div
            class="menu-item has-submenu <?php echo in_array($current_page, ['tagihan_pembayaran.php', 'daftar_tagihan.php', 'proses_pembayaran.php', 'riwayat_pembayaran.php']) ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Tagihan</span>
            <i class="fas fa-chevron-right submenu-arrow"></i>
        </div>
        <div
            class="submenu <?php echo in_array($current_page, ['tagihan_pembayaran.php', 'daftar_tagihan.php', 'proses_pembayaran.php', 'riwayat_pembayaran.php']) ? 'open' : ''; ?>">
            <a href="tagihan_pembayaran.php"
                class="submenu-item <?php echo ($current_page == 'tagihan_pembayaran.php') ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> Buat Tagihan Baru
            </a>
            <a href="daftar_tagihan.php"
                class="submenu-item <?php echo ($current_page == 'daftar_tagihan.php') ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i> Daftar Tagihan
            </a>
            <a href="proses_pembayaran.php"
                class="submenu-item <?php echo ($current_page == 'proses_pembayaran.php') ? 'active' : ''; ?>">
                <i class="fas fa-cash-register"></i> Proses Pembayaran
            </a>
            <a href="riwayat_pembayaran.php"
                class="submenu-item <?php echo ($current_page == 'riwayat_pembayaran.php') ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Riwayat Pembayaran
            </a>
        </div>

        <div class="menu-item has-submenu <?php echo ($current_page == 'laporan_pembayaran.php') ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Laporan</span>
            <i class="fas fa-chevron-right submenu-arrow"></i>
        </div>
        <div class="submenu <?php echo ($current_page == 'laporan_pembayaran.php') ? 'open' : ''; ?>">
            <a href="laporan_pembayaran.php?view=monthly" class="submenu-item">
                <i class="fas fa-calendar-alt"></i> Ringkasan Bulanan
            </a>
            <a href="laporan_pembayaran.php?view=item" class="submenu-item">
                <i class="fas fa-boxes"></i> Rekap per Item
            </a>
            <a href="laporan_pembayaran.php?view=outstanding" class="submenu-item">
                <i class="fas fa-exclamation-triangle"></i> Tagihan Tertunggak
            </a>
        </div>

        <a href="notifikasi.php" class="menu-item <?php echo ($current_page == 'notifikasi.php') ? 'active' : ''; ?>">
            <i class="fas fa-bell"></i>
            <span>Notifikasi</span>
        </a>
    </nav>

    <!-- User Section at Bottom -->
    <div class="sidebar-footer">
        <div class="sidebar-user" id="sidebarUser">
            <div class="sidebar-user-avatar">
                <?php echo strtoupper(substr($nama_bendahara, 0, 1)); ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($nama_bendahara); ?></div>
                <div class="sidebar-user-role">Bendahara</div>
            </div>
            <i class="fas fa-ellipsis-v sidebar-user-menu"></i>
        </div>

        <div class="sidebar-user-dropdown" id="sidebarUserDropdown">
            <a href="pengaturan.php" class="sidebar-dropdown-item">
                <i class="fas fa-wrench"></i> Pengaturan Akun
            </a>
            <div class="sidebar-dropdown-item logout" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i> Keluar
            </div>
        </div>
    </div>
</aside>

<style>
    /* ============================================
   SIDEBAR STYLES
   ============================================ */

    /* Sidebar Overlay (Mobile) */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .sidebar-overlay.active {
        display: block;
        opacity: 1;
    }

    /* Sidebar Container */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: #fff;
        border-right: 1px solid var(--gray-200);
        display: flex;
        flex-direction: column;
        z-index: 1000;
        transition: transform 0.3s ease;
    }

    .sidebar-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--space-lg);
        border-bottom: 1px solid var(--gray-200);
    }

    .sidebar-brand img {
        height: 40px;
        width: auto;
    }

    .sidebar-close {
        display: none;
        background: none;
        border: none;
        font-size: 1.25rem;
        color: var(--gray-500);
        cursor: pointer;
        padding: var(--space-xs);
    }

    /* Sidebar Navigation */
    .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        padding: var(--space-md) 0;
    }

    /* Menu Header */
    .menu-header {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--gray-400);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: var(--space-lg) var(--space-lg) var(--space-xs);
        margin-top: var(--space-xs);
    }

    /* Menu Item */
    .menu-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-sm) var(--space-lg);
        color: var(--gray-600);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
    }

    .menu-item:hover {
        background: var(--gray-50);
        color: var(--gray-900);
    }

    .menu-item.active {
        background: linear-gradient(135deg, rgba(100, 116, 139, 0.1) 0%, rgba(71, 85, 105, 0.1) 100%);
        color: var(--primary);
        border-right: 3px solid var(--primary);
    }

    .menu-item i:first-child {
        width: 20px;
        text-align: center;
        font-size: 1rem;
    }

    .menu-item span {
        flex: 1;
    }

    /* Submenu Arrow */
    .submenu-arrow {
        font-size: 0.7rem;
        transition: transform 0.2s ease;
    }

    .menu-item.has-submenu.open .submenu-arrow {
        transform: rotate(90deg);
    }

    /* Submenu */
    .submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: var(--gray-50);
    }

    .submenu.open {
        max-height: 300px;
    }

    .submenu-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-sm) var(--space-lg);
        padding-left: calc(var(--space-lg) + 28px);
        color: var(--gray-500);
        text-decoration: none;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }

    .submenu-item:hover {
        color: var(--gray-900);
        background: var(--gray-100);
    }

    .submenu-item.active {
        color: var(--primary);
        font-weight: 500;
    }

    .submenu-item i {
        font-size: 0.8rem;
        width: 16px;
        text-align: center;
    }

    /* Sidebar Footer */
    .sidebar-footer {
        border-top: 1px solid var(--gray-200);
        padding: var(--space-md);
        position: relative;
    }

    .sidebar-user {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-sm);
        border-radius: var(--radius);
        cursor: pointer;
        transition: background 0.2s ease;
    }

    .sidebar-user:hover {
        background: var(--gray-100);
    }

    .sidebar-user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 600;
        font-size: 1rem;
    }

    .sidebar-user-info {
        flex: 1;
    }

    .sidebar-user-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--gray-900);
    }

    .sidebar-user-role {
        font-size: 0.75rem;
        color: var(--gray-500);
    }

    .sidebar-user-menu {
        color: var(--gray-400);
        font-size: 0.9rem;
    }

    /* Sidebar User Dropdown */
    .sidebar-user-dropdown {
        position: absolute;
        bottom: calc(100% + 5px);
        left: var(--space-md);
        right: var(--space-md);
        background: #fff;
        border: 1px solid var(--gray-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow-lg);
        opacity: 0;
        visibility: hidden;
        transform: translateY(10px);
        transition: all 0.2s ease;
        z-index: 10;
    }

    .sidebar-user-dropdown.active {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .sidebar-dropdown-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-sm) var(--space-md);
        color: var(--gray-700);
        text-decoration: none;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .sidebar-dropdown-item:hover {
        background: var(--gray-100);
        color: var(--primary);
    }

    .sidebar-dropdown-item.logout {
        color: var(--danger);
        border-top: 1px solid var(--gray-200);
    }

    .sidebar-dropdown-item.logout:hover {
        background: rgba(239, 68, 68, 0.1);
    }

    .sidebar-dropdown-item i {
        width: 18px;
        text-align: center;
    }

    /* Main Content with Sidebar */
    .app-wrapper {
        display: flex;
        min-height: 100vh;
    }

    .main-content {
        flex: 1;
        margin-left: 280px;
        min-height: 100vh;
        background: var(--gray-50);
    }

    /* Top Navbar (simplified for sidebar layout) */
    .top-navbar {
        position: sticky;
        top: 0;
        z-index: 100;
        background: #fff;
        border-bottom: 1px solid var(--gray-200);
        box-shadow: var(--shadow-sm);
        padding: 0 var(--space-lg);
        height: 64px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .navbar-left {
        display: flex;
        align-items: center;
        gap: var(--space-md);
    }

    .sidebar-toggle {
        display: none;
        background: none;
        border: none;
        font-size: 1.25rem;
        color: var(--gray-600);
        cursor: pointer;
        padding: var(--space-xs);
    }

    .page-breadcrumb {
        font-size: 0.9rem;
        color: var(--gray-600);
    }

    .page-breadcrumb a {
        color: var(--primary);
        text-decoration: none;
    }

    .page-breadcrumb a:hover {
        text-decoration: underline;
    }

    .navbar-right {
        display: flex;
        align-items: center;
        gap: var(--space-md);
    }

    /* Mobile Responsive */
    @media (max-width: 1024px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-close {
            display: block;
        }

        .main-content {
            margin-left: 0;
        }

        .sidebar-toggle {
            display: block;
        }
    }

    @media (max-width: 768px) {
        .sidebar {
            width: 100%;
            max-width: 300px;
        }

        .page-breadcrumb {
            display: none;
        }
    }
</style>

<script>
    $(function () {
        // Toggle Submenu
        $('.menu-item.has-submenu').on('click', function () {
            $(this).toggleClass('open');
            $(this).next('.submenu').toggleClass('open');
        });

        // Sidebar Toggle (Mobile)
        $('#sidebarToggle').on('click', function () {
            $('#sidebar').addClass('active');
            $('#sidebarOverlay').addClass('active');
            $('body').css('overflow', 'hidden');
        });

        // Close Sidebar
        $('#sidebarClose, #sidebarOverlay').on('click', function () {
            $('#sidebar').removeClass('active');
            $('#sidebarOverlay').removeClass('active');
            $('body').css('overflow', '');
        });

        // User Dropdown Toggle
        $('#sidebarUser').on('click', function (e) {
            e.stopPropagation();
            $('#sidebarUserDropdown').toggleClass('active');
        });

        // Close dropdown when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.sidebar-footer').length) {
                $('#sidebarUserDropdown').removeClass('active');
            }
        });
    });

    // Logout Function
    function logout() {
        Swal.fire({
            title: 'Konfirmasi',
            text: 'Yakin ingin keluar?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, Keluar',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../../logout.php';
            }
        });
    }
</script>