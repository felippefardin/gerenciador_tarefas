(function () {
    const storageKey = 'gerenciador_tarefas_theme';
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: light)');

    function savedTheme() {
        try {
            const value = localStorage.getItem(storageKey);
            return value === 'light' || value === 'dark' ? value : null;
        } catch (_) {
            return null;
        }
    }

    function applyTheme(theme) {
        root.dataset.theme = theme;
        root.style.colorScheme = theme;
        const button = document.querySelector('[data-theme-toggle]');
        if (button) {
            const isDark = theme === 'dark';
            button.innerHTML = '<span aria-hidden="true">' + (isDark ? '☀' : '☾') + '</span><span class="theme-label">' + (isDark ? 'Modo claro' : 'Modo escuro') + '</span>';
            button.setAttribute('aria-label', isDark ? 'Ativar modo claro' : 'Ativar modo escuro');
            button.setAttribute('title', isDark ? 'Ativar modo claro' : 'Ativar modo escuro');
        }
    }

    applyTheme(savedTheme() || (media.matches ? 'light' : 'dark'));

    document.addEventListener('DOMContentLoaded', function () {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'theme-toggle';
        button.dataset.themeToggle = '';

        let dock = document.querySelector('[data-accessibility-dock]');
        if (!dock) {
            dock = document.createElement('div');
            dock.className = 'accessibility-dock';
            dock.dataset.accessibilityDock = '';
        }

        const userbox = document.querySelector('.userbox');
        if (userbox) {
            dock.classList.add('is-in-header');
            const notificationMenu = userbox.querySelector('.notification-menu');
            if (dock.parentNode !== userbox) {
                userbox.insertBefore(dock, notificationMenu ? notificationMenu.nextSibling : userbox.firstChild);
            }
        } else if (!dock.parentNode) {
            document.body.appendChild(dock);
        }
        dock.appendChild(button);

        button.addEventListener('click', function () {
            const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(storageKey, next); } catch (_) {}
            applyTheme(next);
        });
        applyTheme(root.dataset.theme || 'dark');
    });

    media.addEventListener('change', function (event) {
        if (!savedTheme()) applyTheme(event.matches ? 'light' : 'dark');
    });
})();
