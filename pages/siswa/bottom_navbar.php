<?php
// bottom_navbar.php - Original Floating Rounded Style
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
    /* Bottom Navigation - Floating Rounded Style */
    .bottom-nav {
        position: fixed;
        bottom: 12px;
        left: 12px;
        right: 12px;
        background: #f1f5f9;
        padding: 14px 16px 20px;
        display: flex;
        justify-content: space-around;
        align-items: flex-end;
        z-index: 100;
        box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.12);
        border-radius: 28px;
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
        color: #94a3b8;
        transition: all 0.2s ease;
        padding: 8px 12px;
        flex: 1;
        position: relative;
    }

    .nav-item.active {
        color: #2c3e50;
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
        color: #2c3e50;
        transform: scale(1.1);
    }

    .nav-item.active i,
    .nav-item.active span {
        color: #2c3e50;
    }

    /* Center Transfer Button - Floating Action */
    .nav-center {
        position: relative;
        flex: 0 0 auto;
        margin: 0 8px;
    }

    .nav-center-btn {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
        border: 4px solid var(--white, #ffffff);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 10px 28px rgba(44, 62, 80, 0.4);
        position: relative;
        bottom: 26px;
        text-decoration: none;
        color: white;
    }

    .nav-center-btn:hover {
        transform: translateY(-6px) scale(1.08);
        box-shadow: 0 14px 36px rgba(44, 62, 80, 0.5);
    }

    .nav-center-btn:active {
        transform: translateY(-3px) scale(1.02);
    }

    .nav-center-btn i {
        font-size: 28px;
        color: white;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .bottom-nav {
            bottom: 10px;
            left: 10px;
            right: 10px;
            padding: 12px 14px 18px;
            border-radius: 24px;
        }

        .nav-item {
            padding: 6px 10px;
        }

        .nav-item i {
            font-size: 20px;
        }

        .nav-center-btn {
            width: 64px;
            height: 64px;
            bottom: 24px;
        }

        .nav-center-btn i {
            font-size: 26px;
        }
    }

    @media (max-width: 480px) {
        .bottom-nav {
            bottom: 8px;
            left: 8px;
            right: 8px;
            padding: 10px 10px 16px;
            border-radius: 22px;
        }

        .nav-item {
            padding: 6px 8px;
        }

        .nav-item i {
            font-size: 18px;
        }

        .nav-item span {
            font-size: 9px;
        }

        .nav-center-btn {
            width: 58px;
            height: 58px;
            bottom: 22px;
            border-width: 3px;
        }

        .nav-center-btn i {
            font-size: 24px;
        }
    }

    @media (max-width: 360px) {
        .bottom-nav {
            left: 6px;
            right: 6px;
            border-radius: 20px;
        }

        .nav-center-btn {
            width: 54px;
            height: 54px;
            bottom: 20px;
        }

        .nav-center-btn i {
            font-size: 22px;
        }
    }
</style>

<!-- Bottom Navigation -->
<nav class="bottom-nav" id="bottomNav">
    <a href="dashboard.php" class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
        <i class="fas fa-home"></i>
        <span>Beranda</span>
    </a>
    <a href="mutasi.php" class="nav-item <?php echo ($current_page == 'mutasi.php') ? 'active' : ''; ?>">
        <i class="fas fa-list-alt"></i>
        <span>Mutasi</span>
    </a>

    <!-- Center Transfer Button -->
    <div class="nav-center">
        <a href="transfer.php" class="nav-center-btn" title="Transfer">
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
</nav>

<script>
    // Bottom Bar Auto Hide/Show on Scroll
    let scrollTimer;
    let lastScrollTop = 0;
    const bottomNav = document.getElementById('bottomNav');

    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset || document.documentElement.scrollTop;

        if (currentScroll > lastScrollTop && currentScroll > 100) {
            bottomNav.classList.add('hidden');
        } else {
            bottomNav.classList.remove('hidden');
        }

        lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;

        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(() => {
            bottomNav.classList.remove('hidden');
        }, 500);
    }, { passive: true });
</script>