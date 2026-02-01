<?php
/**
 * Bendahara Sidebar Component (Standalone)
 * File: pages/bendahara/sidebar_bendahara.php
 * Reusable sidebar menu for all bendahara pages
 */

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Adaptive base URL detection
if (!isset($base_url)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script_name = $_SERVER['SCRIPT_NAME'];
    $path_parts = explode('/', trim(dirname($script_name), '/'));
    $base_path = in_array('schobank', $path_parts) ? '/schobank' : '';
    $base_url = $protocol . '://' . $host . $base_path;
}
?>

<!-- Mobile Navbar -->
<div class="mobile-navbar" id="mobileNavbar">
    <button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>
</div>

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


        <!-- Section: Tagihan Pembayaran -->
        <div class="menu-header">Tagihan Pembayaran</div>

        <a href="tagihan_pembayaran.php"
            class="menu-item <?php echo ($current_page == 'tagihan_pembayaran.php') ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i>
            <span>Buat Tagihan Baru</span>
        </a>
        <a href="daftar_tagihan.php"
            class="menu-item <?php echo ($current_page == 'daftar_tagihan.php') ? 'active' : ''; ?>">
            <i class="fas fa-list-alt"></i>
            <span>Daftar Tagihan</span>
        </a>
        <a href="proses_pembayaran.php"
            class="menu-item <?php echo ($current_page == 'proses_pembayaran.php') ? 'active' : ''; ?>">
            <i class="fas fa-cash-register"></i>
            <span>Proses Pembayaran</span>
        </a>
        <a href="riwayat_pembayaran.php"
            class="menu-item <?php echo ($current_page == 'riwayat_pembayaran.php') ? 'active' : ''; ?>">
            <i class="fas fa-history"></i>
            <span>Riwayat Pembayaran</span>
        </a>
        <a href="arsip_riwayat.php"
            class="menu-item <?php echo ($current_page == 'arsip_riwayat.php') ? 'active' : ''; ?>">
            <i class="fas fa-archive"></i>
            <span>Arsip Riwayat</span>
        </a>

        <a href="laporan_pembayaran.php"
            class="menu-item <?php echo ($current_page == 'laporan_pembayaran.php') ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Laporan</span>
        </a>




    </nav>

    <!-- User Section at Bottom -->
    <div class="sidebar-footer">
        <div class="sidebar-user" id="sidebarUser">
            <div class="sidebar-user-avatar">
                <?php echo strtoupper(substr($nama_bendahara ?? 'B', 0, 1)); ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($nama_bendahara ?? 'Bendahara'); ?></div>
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
   SIDEBAR STYLES - Harmonized with Admin Sidebar
   ============================================ */
    :root {
        --gray-50: #f9fafb;
        --gray-100: #f1f5f9;
        --gray-200: #e2e8f0;
        --gray-300: #cbd5e1;
        --gray-400: #94a3b8;
        --gray-500: #64748b;
        --gray-600: #475569;
        --gray-700: #334155;
        --gray-800: #1e293b;
        --gray-900: #0f172a;

        --primary-color: var(--gray-800);
        --primary-dark: var(--gray-900);
        --secondary-color: var(--gray-600);
        --secondary-dark: var(--gray-700);
        --danger-color: #e74c3c;
        --danger: #ef4444;
        --text-primary: #333;
        --text-secondary: #666;
        --bg-light: #f8fafc;
        --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
        --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
        --transition: all 0.3s ease;
        --scrollbar-track: var(--gray-900);
        --scrollbar-thumb: var(--gray-600);
        --scrollbar-thumb-hover: var(--gray-500);
        --radius-sm: 6px;
        --radius: 8px;
        --radius-lg: 12px;
        --radius-xl: 16px;
        --space-xs: 0.5rem;
        --space-sm: 0.75rem;
        --space-md: 1rem;
        --space-lg: 1.5rem;
        --space-xl: 2rem;
        --space-2xl: 3rem;
    }

    * {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    /* Mobile Navbar */
    .mobile-navbar {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: 60px;
        background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
        z-index: 998;
        padding: 0 1rem;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
    }

    /* Hamburger Button - Circular style like dashboard header buttons */
    .hamburger-btn {
        width: 44px;
        height: 44px;
        border-radius: 9999px;
        border: none;
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
        backdrop-filter: blur(10px);
    }

    .hamburger-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.05);
    }

    .hamburger-btn:active {
        transform: scale(0.95);
    }

    .hamburger-btn.is-active {
        background: rgba(255, 255, 255, 0.2);
    }

    .hamburger-btn i {
        font-size: 18px;
    }

    .hamburger-btn.is-active i::before {
        content: "\f00d";
        /* Font Awesome times/close icon */
    }

    .mobile-navbar-title {
        flex: 1;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .mobile-logo {
        height: 36px;
        width: auto;
    }

    .mobile-navbar-spacer {
        width: 44px;
    }

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

    /* Sidebar Container - Dark Theme like Admin */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
        color: white;
        display: flex;
        flex-direction: column;
        z-index: 1000;
        transition: transform 0.3s ease;
        box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
    }

    .sidebar-header {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 15px 20px 10px;
        background: var(--primary-dark);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        position: relative;
        min-height: 90px;
    }

    .sidebar-brand {
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .sidebar-brand img {
        max-width: 160px;
        height: auto;
        object-fit: contain;
        filter: brightness(0) invert(1);
    }

    .sidebar-close {
        display: none;
        background: none;
        border: none;
        font-size: 1.25rem;
        color: rgba(255, 255, 255, 0.7);
        cursor: pointer;
        padding: var(--space-xs);
        position: absolute;
        right: var(--space-lg);
        top: 50%;
        transform: translateY(-50%);
    }

    .sidebar-close:hover {
        color: white;
    }

    /* Sidebar Navigation */
    .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        padding: 10px 0;
    }

    /* Custom Scrollbar - Dark Theme */
    .sidebar-nav::-webkit-scrollbar {
        width: 8px;
    }

    .sidebar-nav::-webkit-scrollbar-track {
        background: var(--scrollbar-track);
        border-radius: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: var(--scrollbar-thumb);
        border-radius: 4px;
        border: 2px solid var(--scrollbar-track);
    }

    .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: var(--scrollbar-thumb-hover);
    }

    /* Menu Header */
    .menu-header {
        padding: 15px 25px 10px;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: rgba(255, 255, 255, 0.6);
        font-weight: 500;
        margin-top: 10px;
    }

    /* Menu Item - Dark Theme */
    .menu-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: 14px 25px;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 400;
        cursor: pointer;
        transition: var(--transition);
        position: relative;
        border-left: 4px solid transparent;
    }

    .menu-item:hover {
        background-color: rgba(255, 255, 255, 0.1);
        border-left-color: var(--secondary-color);
        color: white;
    }

    .menu-item.active {
        background-color: rgba(255, 255, 255, 0.1);
        border-left-color: var(--secondary-color);
        color: white;
        font-weight: 500;
    }

    .menu-item i:first-child {
        margin-right: 12px;
        width: 20px;
        text-align: center;
        font-size: 1.1rem;
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

    /* Submenu - Dark Theme */
    .submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background-color: rgba(0, 0, 0, 0.15);
    }

    .submenu.open {
        max-height: 400px;
    }

    .submenu-item {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: 12px 20px 12px 60px;
        color: rgba(255, 255, 255, 0.75);
        text-decoration: none;
        font-size: 0.85rem;
        transition: var(--transition);
        border-left: 4px solid transparent;
    }

    .submenu-item:hover {
        background-color: rgba(255, 255, 255, 0.08);
        border-left-color: var(--secondary-color);
        color: white;
    }

    .submenu-item.active {
        background-color: rgba(255, 255, 255, 0.08);
        border-left-color: var(--secondary-color);
        color: white;
        font-weight: 500;
    }

    .submenu-item i {
        font-size: 0.8rem;
        width: 16px;
        text-align: center;
    }

    /* Sidebar Footer - Dark Theme */
    .sidebar-footer {
        background: var(--primary-dark);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
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
        background: rgba(255, 255, 255, 0.1);
    }

    .sidebar-user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--secondary-color);
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
        color: white;
    }

    .sidebar-user-role {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.6);
    }

    .sidebar-user-menu {
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.9rem;
    }

    /* Sidebar User Dropdown - Dark Theme */
    .sidebar-user-dropdown {
        position: absolute;
        bottom: calc(100% + 5px);
        left: var(--space-md);
        right: var(--space-md);
        background: var(--gray-800);
        border: 1px solid rgba(255, 255, 255, 0.1);
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
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .sidebar-dropdown-item:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .sidebar-dropdown-item.logout {
        color: var(--danger-color);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .sidebar-dropdown-item.logout:hover {
        background: rgba(231, 76, 60, 0.2);
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
        background: var(--bg-light);
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
        color: var(--primary-color);
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
        .mobile-navbar {
            display: flex;
        }

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
            padding-top: 70px;
        }

        .sidebar-toggle {
            display: block;
        }
    }

    @media (max-width: 768px) {
        .sidebar {
            width: 260px;
        }

        .page-breadcrumb {
            display: none;
        }

        .sidebar-header {
            padding: 10px 15px 5px;
            min-height: 75px;
        }

        .sidebar-brand img {
            max-width: 130px;
        }

        .menu-item {
            padding: 12px 20px;
            font-size: 0.85rem;
        }

        .submenu-item {
            padding: 10px 15px 10px 50px;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 480px) {
        .sidebar {
            width: 240px;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Toggle Submenu
        document.querySelectorAll('.menu-item.has-submenu').forEach(function (item) {
            item.addEventListener('click', function () {
                this.classList.toggle('open');
                var submenu = this.nextElementSibling;
                if (submenu && submenu.classList.contains('submenu')) {
                    submenu.classList.toggle('open');
                }
            });
        });

        // Sidebar Toggle (Mobile)
        var sidebarToggle = document.getElementById('sidebarToggle');
        var hamburgerBtn = document.getElementById('hamburgerBtn');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function () {
                document.getElementById('sidebar').classList.add('active');
                document.getElementById('sidebarOverlay').classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        }

        // Hamburger Button (Mobile)
        if (hamburgerBtn) {
            hamburgerBtn.addEventListener('click', function () {
                this.classList.toggle('is-active');
                var sidebar = document.getElementById('sidebar');
                var overlay = document.getElementById('sidebarOverlay');

                if (sidebar.classList.contains('active')) {
                    closeSidebar();
                } else {
                    sidebar.classList.add('active');
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });
        }

        // Close Sidebar
        var sidebarClose = document.getElementById('sidebarClose');
        var sidebarOverlay = document.getElementById('sidebarOverlay');

        if (sidebarClose) {
            sidebarClose.addEventListener('click', closeSidebar);
        }
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            document.getElementById('sidebarOverlay').classList.remove('active');
            document.body.style.overflow = '';
            // Reset hamburger button state
            var hamburgerBtn = document.getElementById('hamburgerBtn');
            if (hamburgerBtn) {
                hamburgerBtn.classList.remove('is-active');
            }
        }

        // User Dropdown Toggle
        var sidebarUser = document.getElementById('sidebarUser');
        if (sidebarUser) {
            sidebarUser.addEventListener('click', function (e) {
                e.stopPropagation();
                document.getElementById('sidebarUserDropdown').classList.toggle('active');
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (e) {
            var footer = document.querySelector('.sidebar-footer');
            if (footer && !footer.contains(e.target)) {
                var dropdown = document.getElementById('sidebarUserDropdown');
                if (dropdown) dropdown.classList.remove('active');
            }
        });
    });

    // Logout Function
    function logout() {
        if (typeof Swal !== 'undefined') {
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
        } else {
            if (confirm('Yakin ingin keluar?')) {
                window.location.href = '../../logout.php';
            }
        }
    }
</script>