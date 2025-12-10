<?php
// bottom_navbar.php
// Mendeteksi halaman saat ini untuk class active
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
    /* Bottom Navigation - Fully Rounded */
    .bottom-nav {
        position: fixed;
        bottom: 12px;
        left: 12px;
        right: 12px;
        background: var(--white);
        padding: 16px 20px 24px;
        display: flex;
        justify-content: space-around;
        align-items: flex-end;
        z-index: 100;
        box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.15);
        border-radius: 32px;
        transition: all 0.3s ease;
        transform: translateY(0);
        opacity: 1;
    }
    .bottom-nav.hidden {
        transform: translateY(120%);
        opacity: 0;
    }
    .nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        text-decoration: none;
        color: var(--gray-400);
        transition: all 0.2s ease;
        padding: 8px 12px;
        flex: 1;
        position: relative;
    }
    .nav-item.active {
        color: var(--elegant-dark);
    }
    .nav-item i {
        font-size: 22px;
        transition: all 0.2s ease;
    }
    .nav-item span {
        font-size: 10px;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .nav-item:hover i,
    .nav-item:hover span {
        color: var(--elegant-dark);
        transform: scale(1.1);
    }
    .nav-item.active i,
    .nav-item.active span {
        color: var(--elegant-dark);
    }
    /* Center Transfer Button - Larger & Higher */
    .nav-center {
        position: relative;
        flex: 0 0 auto;
        margin: 0 8px;
    }
    .nav-center-btn {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: var(--elegant-dark);
        border: 4px solid var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 12px 32px rgba(44, 62, 80, 0.4);
        position: relative;
        bottom: 24px;
        text-decoration: none;
        color: var(--white);
    }
    .nav-center-btn:hover {
        transform: translateY(-6px) scale(1.08);
        box-shadow: 0 16px 40px rgba(44, 62, 80, 0.5);
    }
    .nav-center-btn:active {
        transform: translateY(-3px) scale(1.02);
    }
    .nav-center-btn i {
        font-size: 28px;
    }
    /* Responsive for Bottom Nav */
    @media (max-width: 768px) {
        .nav-center-btn {
            width: 64px;
            height: 64px;
            bottom: 22px;
        }
        .nav-center-btn i {
            font-size: 26px;
        }
        .nav-item i {
            font-size: 20px;
        }
        .bottom-nav {
            padding: 14px 18px 22px;
            bottom: 10px;
            left: 10px;
            right: 10px;
            border-radius: 28px;
        }
    }
    @media (max-width: 480px) {
        .nav-center-btn {
            width: 62px;
            height: 62px;
            bottom: 20px;
        }
        .nav-center-btn i {
            font-size: 24px;
        }
        .nav-item {
            padding: 6px 8px;
        }
        .nav-item i {
            font-size: 19px;
        }
        .nav-item span {
            font-size: 9px;
        }
        .bottom-nav {
            border-radius: 24px;
            padding: 12px 16px 20px;
            bottom: 8px;
            left: 8px;
            right: 8px;
        }
    }
</style>
<!-- Bottom Navigation - Fully Rounded with New Icons -->
<div class="bottom-nav" id="bottomNav">
    <a href="dashboard.php" class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
        <i class="fas fa-home"></i>
        <span>Beranda</span>
    </a>
    <a href="cek_mutasi.php" class="nav-item <?php echo ($current_page == 'cek_mutasi.php') ? 'active' : ''; ?>">
        <i class="fas fa-list-alt"></i>
        <span>Mutasi</span>
    </a>
    <!-- Center Transfer Button - Paper Airplane -->
    <div class="nav-center">
        <a href="transfer_pribadi.php" class="nav-center-btn" title="Transfer">
            <i class="fas fa-paper-plane"></i>
        </a>
    </div>
    <a href="aktivitas.php" class="nav-item <?php echo ($current_page == 'aktivitas.php') ? 'active' : ''; ?>">
        <i class="fas fa-exchange-alt"></i>
        <span>Aktivitas</span>
    </a>
    <a href="profil.php" class="nav-item <?php echo ($current_page == 'profil.php') ? 'active' : ''; ?>">
        <i class="fas fa-user"></i>
        <span>Profil</span>
    </a>
</div>
<script>
    // Bottom Bar Auto Hide/Show on Scroll
    let scrollTimer;
    let lastScrollTop = 0;
    const bottomNav = document.getElementById('bottomNav');
    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
        if (currentScroll > lastScrollTop || currentScroll < lastScrollTop) {
            bottomNav.classList.add('hidden');
        }
        lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(() => {
            bottomNav.classList.remove('hidden');
        }, 500);
    }, { passive: true });
</script>