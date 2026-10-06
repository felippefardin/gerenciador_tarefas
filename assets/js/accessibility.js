(function () {
    const storageKey = 'gerenciador_tarefas_text_size';
    const levels = [0.7, 0.85, 1, 1.15, 1.3];
    const root = document.documentElement;

    function readSavedLevel() {
        try {
            const saved = Number(localStorage.getItem(storageKey));
            return levels.includes(saved) ? saved : 1;
        } catch (_) {
            return 1;
        }
    }

    function applyLevel(level) {
        const safeLevel = levels.includes(level) ? level : 1;
        root.style.setProperty('--interface-scale', String(safeLevel));
        root.dataset.textSize = String(safeLevel);

        const value = document.querySelector('[data-font-size-value]');
        const decrease = document.querySelector('[data-font-size-decrease]');
        const increase = document.querySelector('[data-font-size-increase]');
        const index = levels.indexOf(safeLevel);
        if (value) value.textContent = Math.round(safeLevel * 100) + '%';
        if (decrease) decrease.disabled = index === 0;
        if (increase) increase.disabled = index === levels.length - 1;
    }

    function saveAndApply(level) {
        try { localStorage.setItem(storageKey, String(level)); } catch (_) {}
        applyLevel(level);
    }

    applyLevel(readSavedLevel());

    document.addEventListener('DOMContentLoaded', function () {
        const widget = document.querySelector('[data-font-size-widget]');
        const toggle = widget && widget.querySelector('[data-font-size-toggle]');
        const panel = widget && widget.querySelector('[data-font-size-panel]');
        const decrease = widget && widget.querySelector('[data-font-size-decrease]');
        const increase = widget && widget.querySelector('[data-font-size-increase]');
        const reset = widget && widget.querySelector('[data-font-size-reset]');
        if (!widget || !toggle || !panel) return;

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
        dock.appendChild(widget);

        const currentLevel = () => {
            const level = Number(root.dataset.textSize);
            return levels.includes(level) ? level : 1;
        };
        const close = () => {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        };

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const opening = panel.hidden;
            panel.hidden = !opening;
            toggle.setAttribute('aria-expanded', String(opening));
        });
        panel.addEventListener('click', event => event.stopPropagation());
        decrease.addEventListener('click', function () {
            const index = levels.indexOf(currentLevel());
            if (index > 0) saveAndApply(levels[index - 1]);
        });
        increase.addEventListener('click', function () {
            const index = levels.indexOf(currentLevel());
            if (index < levels.length - 1) saveAndApply(levels[index + 1]);
        });
        reset.addEventListener('click', () => saveAndApply(1));
        document.addEventListener('click', close);
        document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
        applyLevel(readSavedLevel());
    });
})();
