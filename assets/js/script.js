document.addEventListener('DOMContentLoaded', function() {
    // Setup dropdown menus
    function setupDropdown(btnId, containerId) {
        const dropdownBtn = document.getElementById(btnId);
        const dropdownContainer = document.getElementById(containerId);
        
        if (dropdownBtn && dropdownContainer) {
            dropdownBtn.addEventListener('click', function() {
                this.classList.toggle('active');
                dropdownContainer.classList.toggle('show');
            });
        }
    }

    // Initialize dropdowns
    setupDropdown('transaksiDropdown', 'transaksiDropdownContainer');
    setupDropdown('searchDropdown', 'searchDropdownContainer');

    // User menu dropdown
    const userMenuBtn = document.getElementById('userMenuBtn');
    const userDropdownMenu = document.getElementById('userDropdownMenu');
    
    if (userMenuBtn && userDropdownMenu) {
        userMenuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdownMenu.classList.toggle('show');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userMenuBtn.contains(e.target) && !userDropdownMenu.contains(e.target)) {
                userDropdownMenu.classList.remove('show');
            }
        });
    }

    // Mobile menu toggle
    const menuToggle = document.getElementById('menuToggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
            document.body.classList.toggle('sidebar-active');
        });
    }

    // Close sidebar when clicking main content (mobile)
    const mainContent = document.getElementById('mainContent');
    if (mainContent) {
        mainContent.addEventListener('click', function() {
            if (document.body.classList.contains('sidebar-active')) {
                document.getElementById('sidebar').classList.remove('active');
                document.body.classList.remove('sidebar-active');
            }
        });
    }

    // Highlight active menu item
    const currentPage = window.location.pathname.split('/').pop();
    const menuLinks = document.querySelectorAll('.sidebar-menu a');
    
    menuLinks.forEach(link => {
        if (link.getAttribute('href') === currentPage) {
            link.classList.add('active');
            const parentDropdown = link.closest('.dropdown-container');
            if (parentDropdown) {
                parentDropdown.classList.add('show');
                parentDropdown.previousElementSibling.classList.add('active');
            }
        }
    });
});