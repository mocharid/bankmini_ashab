/**
 * Session Auto-Logout (No Popup)
 * File: assets/js/session_auto_logout.js
 * 
 * Automatically redirects to login after idle timeout
 * No warning popup - direct redirect
 */

(function () {
    'use strict';

    // Get config from window object (set by PHP)
    const config = window.sessionConfig || {
        timeout: 300,  // 5 minutes default in seconds
        logoutUrl: '/logout.php?reason=timeout'
    };

    let idleSeconds = 0;
    let intervalId = null;

    console.log('[Session] Auto-logout initialized. Timeout:', config.timeout, 'seconds');

    /**
     * Reset idle timer on any user activity
     */
    function resetIdleTimer() {
        idleSeconds = 0;
    }

    /**
     * Perform logout redirect
     */
    function performLogout() {
        console.log('[Session] Timeout reached. Redirecting to login...');
        window.location.href = config.logoutUrl;
    }

    /**
     * Check idle time every second
     */
    function checkIdleTime() {
        idleSeconds++;

        // Log progress every 30 seconds for debugging
        if (idleSeconds % 30 === 0) {
            console.log('[Session] Idle for', idleSeconds, 'seconds. Timeout at', config.timeout);
        }

        // If idle time exceeds timeout, redirect to logout
        if (idleSeconds >= config.timeout) {
            if (intervalId) {
                clearInterval(intervalId);
            }
            performLogout();
        }
    }

    /**
     * Initialize event listeners for user activity
     */
    function init() {
        console.log('[Session] Setting up idle detection...');

        // Events that indicate user activity
        const activityEvents = [
            'mousedown',
            'mousemove',
            'keypress',
            'scroll',
            'touchstart',
            'click',
            'keydown',
            'wheel'
        ];

        // Add listeners to reset timer on activity
        activityEvents.forEach(event => {
            document.addEventListener(event, resetIdleTimer, { passive: true });
        });

        // Also listen on window for focus events
        window.addEventListener('focus', resetIdleTimer);

        // Check idle time every second
        intervalId = setInterval(checkIdleTime, 1000);

        console.log('[Session] Idle detection active. Will logout after', config.timeout, 'seconds of inactivity.');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
