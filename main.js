/**
 * Predictions Platform — Main JavaScript
 * Vanilla JS, no jQuery.
 */

(function () {
    'use strict';

    /* ──────────────────────────────────────────────────────────
       Utility Functions
       ────────────────────────────────────────────────────────── */

    /**
     * Show a browser confirm dialog.
     * @param {string} message
     * @returns {boolean}
     */
    window.confirmAction = function (message) {
        return confirm(message || 'Are you sure?');
    };

    /**
     * Show a toast notification.
     * @param {string} message
     * @param {string} type - 'success' | 'danger' | 'warning' | 'info'
     */
    window.showToast = function (message, type) {
        type = type || 'info';
        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        var icons = {
            success: 'fas fa-check-circle',
            danger: 'fas fa-times-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        var toast = document.createElement('div');
        toast.className = 'toast-item toast-' + type;
        toast.innerHTML = '<i class="' + (icons[type] || icons.info) + '"></i> <span>' + escapeHtml(message) + '</span>';
        container.appendChild(toast);

        // Auto-dismiss after 4s
        setTimeout(function () {
            toast.style.animation = 'toastSlideOut 0.3s ease forwards';
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }, 4000);
    };

    /**
     * Format a large number with K / M suffixes.
     * @param {number} num
     * @returns {string}
     */
    window.formatNumber = function (num) {
        num = parseFloat(num);
        if (isNaN(num)) return '0';
        if (num >= 1000000) return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return num.toString();
    };

    /**
     * Copy text to clipboard.
     * @param {string} text
     */
    window.copyToClipboard = function (text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showToast('Copied to clipboard!', 'success');
            }).catch(function () {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    };

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showToast('Copied to clipboard!', 'success');
        } catch (e) {
            showToast('Failed to copy.', 'danger');
        }
        document.body.removeChild(ta);
    }

    /**
     * Escape HTML special characters.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ──────────────────────────────────────────────────────────
       Auto-dismiss Bootstrap Alerts after 5 seconds
       ────────────────────────────────────────────────────────── */
    function autoDismissAlerts() {
        var alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function (alert) {
            setTimeout(function () {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            }, 5000);
        });
    }

    /* ──────────────────────────────────────────────────────────
       Navbar Shadow on Scroll
       ────────────────────────────────────────────────────────── */
    function initNavbarScroll() {
        var navbar = document.getElementById('mainNavbar');
        if (!navbar) return;

        function onScroll() {
            if (window.scrollY > 10) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ──────────────────────────────────────────────────────────
       Prevent Double-Submit on Forms
       ────────────────────────────────────────────────────────── */
    function initFormProtection() {
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('button[type="submit"], input[type="submit"]');
                if (btn && !btn.dataset.noDisable) {
                    setTimeout(function () {
                        btn.disabled = true;
                        if (btn.tagName === 'BUTTON') {
                            btn.dataset.originalText = btn.innerHTML;
                            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Processing...';
                        }
                    }, 10);
                }
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       AJAX Helper — Fetch wrapper with CSRF token
       ────────────────────────────────────────────────────────── */
    window.apiCall = function (url, data, method) {
        method = method || 'POST';

        // Try to get CSRF token from a hidden field on the page
        var csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput && data && typeof data === 'object') {
            data.csrf_token = csrfInput.value;
        }

        var options = {
            method: method,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (method !== 'GET' && data) {
            var params = new URLSearchParams();
            for (var key in data) {
                if (data.hasOwnProperty(key)) {
                    params.append(key, data[key]);
                }
            }
            options.body = params.toString();
        }

        return fetch(url, options)
            .then(function (response) {
                return response.json();
            })
            .catch(function (error) {
                console.error('API Error:', error);
                showToast('An error occurred. Please try again.', 'danger');
                throw error;
            });
    };

    /* ──────────────────────────────────────────────────────────
       Notification Mark-as-Read Handler
       ────────────────────────────────────────────────────────── */
    function initNotificationHandlers() {
        document.querySelectorAll('.notification-item[data-id]').forEach(function (item) {
            item.addEventListener('click', function () {
                var id = this.dataset.id;
                if (!id) return;

                var el = this;
                apiCall('api/mark_notification.php', { notification_id: id })
                    .then(function (result) {
                        if (result && result.success) {
                            el.classList.remove('unread');
                            // Update badge count
                            var badge = document.querySelector('.notification-badge');
                            if (badge) {
                                var count = parseInt(badge.textContent, 10) - 1;
                                if (count <= 0) {
                                    badge.style.display = 'none';
                                } else {
                                    badge.textContent = count;
                                }
                            }
                        }
                    });
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       Character Counter for Textareas
       ────────────────────────────────────────────────────────── */
    function initCharCounters() {
        document.querySelectorAll('textarea[data-maxlength]').forEach(function (textarea) {
            var maxLen = parseInt(textarea.dataset.maxlength, 10);
            if (isNaN(maxLen)) return;

            // Create counter element
            var counter = document.createElement('div');
            counter.className = 'char-counter';
            textarea.parentNode.insertBefore(counter, textarea.nextSibling);

            function updateCounter() {
                var len = textarea.value.length;
                counter.textContent = len + ' / ' + maxLen;
                if (len > maxLen) {
                    counter.classList.add('over-limit');
                } else {
                    counter.classList.remove('over-limit');
                }
            }

            textarea.addEventListener('input', updateCounter);
            updateCounter();
        });
    }

    /* ──────────────────────────────────────────────────────────
       Probability Slider Visual Update
       ────────────────────────────────────────────────────────── */
    function initProbabilitySlider() {
        var slider = document.getElementById('probabilitySlider');
        var display = document.getElementById('probabilityDisplay');
        if (!slider || !display) return;

        function update() {
            var val = parseInt(slider.value, 10);
            display.textContent = val + '%';

            // Update color based on value
            if (val <= 33) {
                display.style.color = '#ef4444';
            } else if (val <= 66) {
                display.style.color = '#f59e0b';
            } else {
                display.style.color = '#10b981';
            }
        }

        slider.addEventListener('input', update);
        update();
    }

    /* ──────────────────────────────────────────────────────────
       Search Input Debounce
       ────────────────────────────────────────────────────────── */
    function initSearchDebounce() {
        var searchInput = document.getElementById('searchInput');
        if (!searchInput) return;

        var debounceTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var val = this.value;
            debounceTimer = setTimeout(function () {
                // If there's a search form, submit it; otherwise dispatch event
                var form = searchInput.closest('form');
                if (form && val.length >= 2) {
                    form.submit();
                }
            }, 500);
        });
    }

    /* ──────────────────────────────────────────────────────────
       Mobile Menu Auto-Close on Link Click
       ────────────────────────────────────────────────────────── */
    function initMobileMenuClose() {
        var navbarCollapse = document.getElementById('navbarMain');
        if (!navbarCollapse) return;

        navbarCollapse.querySelectorAll('.nav-link:not(.dropdown-toggle)').forEach(function (link) {
            link.addEventListener('click', function () {
                var bsCollapse = bootstrap.Collapse.getInstance(navbarCollapse);
                if (bsCollapse) bsCollapse.hide();
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       Animate Numbers on Page Load (Count-Up Effect)
       ────────────────────────────────────────────────────────── */
    function initNumberAnimations() {
        var elements = document.querySelectorAll('.animate-number');
        if (!elements.length) return;

        elements.forEach(function (el) {
            var target = parseFloat(el.dataset.target || el.textContent);
            if (isNaN(target)) return;

            var decimals = (el.dataset.decimals !== undefined) ? parseInt(el.dataset.decimals, 10) : 0;
            var duration = parseInt(el.dataset.duration, 10) || 1000;
            var start = 0;
            var startTime = null;

            function step(timestamp) {
                if (!startTime) startTime = timestamp;
                var progress = Math.min((timestamp - startTime) / duration, 1);
                // Ease-out
                var eased = 1 - Math.pow(1 - progress, 3);
                var current = start + (target - start) * eased;
                el.textContent = current.toFixed(decimals);
                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = target.toFixed(decimals);
                }
            }

            // Use IntersectionObserver for lazy trigger
            if ('IntersectionObserver' in window) {
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            requestAnimationFrame(step);
                            observer.unobserve(el);
                        }
                    });
                }, { threshold: 0.3 });
                observer.observe(el);
            } else {
                requestAnimationFrame(step);
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       Bet Amount Slider / Input Sync
       ────────────────────────────────────────────────────────── */
    function initBetAmountSync() {
        var slider = document.getElementById('betAmountSlider');
        var input = document.getElementById('betAmountInput');
        if (!slider || !input) return;

        slider.addEventListener('input', function () {
            input.value = this.value;
        });

        input.addEventListener('input', function () {
            var val = parseFloat(this.value);
            if (!isNaN(val)) {
                val = Math.max(parseFloat(slider.min) || 0, Math.min(val, parseFloat(slider.max) || 9999));
                slider.value = val;
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       Active Nav Item Highlighting Based on Current URL
       ────────────────────────────────────────────────────────── */
    function initActiveNavHighlight() {
        var currentPath = window.location.pathname;
        document.querySelectorAll('#mainNavbar .nav-link').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href) return;

            // Extract pathname from href
            try {
                var linkPath = new URL(href, window.location.origin).pathname;
                if (linkPath === currentPath) {
                    link.classList.add('active');
                }
            } catch (e) {
                // Ignore invalid URLs
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       Confirm Delete / Dangerous Actions
       ────────────────────────────────────────────────────────── */
    function initConfirmActions() {
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var message = this.dataset.confirm || 'Are you sure?';
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       Initialize Everything on DOM Ready
       ────────────────────────────────────────────────────────── */
    function init() {
        autoDismissAlerts();
        initNavbarScroll();
        initFormProtection();
        initNotificationHandlers();
        initCharCounters();
        initProbabilitySlider();
        initSearchDebounce();
        initMobileMenuClose();
        initNumberAnimations();
        initBetAmountSync();
        initActiveNavHighlight();
        initConfirmActions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
