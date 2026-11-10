
/* ULearn Global Theme System — Light, Dark, System */
(() => {
    'use strict';

    const root = document.documentElement;
    const key = 'ulearn-theme-preference';
    const legacyKey = 'ulearn-theme';
    const api = '/ulearn/theme-preference.php';
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const valid = value =>
        ['light', 'dark', 'system'].includes(value);

    function readPreference() {
        try {
            const saved = localStorage.getItem(key);
            if (valid(saved)) return saved;

            const legacy = localStorage.getItem(legacyKey);
            if (legacy === 'light' || legacy === 'dark') {
                return legacy;
            }
        } catch (_) {}

        return 'system';
    }

    let preference = readPreference();
    let csrfToken = '';
    let userChanged = false;
    let applying = false;
    let pendingSave = false;

    function resolved() {
        return preference === 'system'
            ? (media.matches ? 'dark' : 'light')
            : preference;
    }

    function updateIcons(theme) {
        document.querySelectorAll('.theme-icon').forEach(icon => {
            icon.textContent = theme === 'dark' ? '☀' : '☾';
        });

        const qaToggle = document.getElementById('qaThemeToggle');

        if (qaToggle) {
            qaToggle.innerHTML = theme === 'dark'
                ? '<svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>'
                : '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';

            qaToggle.setAttribute(
                'aria-label',
                theme === 'dark'
                    ? 'Switch to light mode'
                    : 'Switch to dark mode'
            );
            qaToggle.title = qaToggle.getAttribute('aria-label');
        }

        document.querySelectorAll(
            '#themeToggle, #theme-toggle, #themeButton'
        ).forEach(button => {
            if (button.querySelector('.theme-icon')) return;
            if (button.querySelector('svg')) return;

            if (button.textContent.trim() === '☾' ||
                button.textContent.trim() === '☀') {
                button.textContent = theme === 'dark' ? '☀' : '☾';
            }
        });
    }

    function apply() {
        const theme = resolved();

        applying = true;
        root.dataset.theme = theme;
        root.style.colorScheme = theme;

        updateIcons(theme);

        try {
            localStorage.setItem(key, preference);
            localStorage.setItem(legacyKey, theme);
        } catch (_) {}

        applying = false;
    }

    async function saveToDatabase() {
        if (!csrfToken) {
            pendingSave = true;
            return;
        }

        const value = preference;
        pendingSave = false;

        try {
            const response = await fetch(api, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    theme: value,
                    csrf: csrfToken
                })
            });

            if (!response.ok) {
                console.error('Could not save theme preference.');
            }
        } catch (error) {
            console.error('Theme preference request failed.', error);
        }
    }

    function choose(value) {
        if (!valid(value)) return;

        userChanged = true;
        preference = value;

        apply();
        saveToDatabase();
    }

    async function loadPreference() {
        try {
            const response = await fetch(api, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) return;

            const data = await response.json();

            csrfToken = data.csrf || '';

            if (!userChanged && valid(data.theme)) {
                preference = data.theme;
                apply();
            }

            if (pendingSave) {
                saveToDatabase();
            }
        } catch (error) {
            console.error('Could not load theme preference.', error);
        }
    }

    apply();
    loadPreference();

    document.addEventListener('DOMContentLoaded', apply);

    media.addEventListener?.('change', () => {
        if (preference === 'system') apply();
    });

    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;

        const button = event.target.closest(
            '#themeToggle, #theme-toggle, #themeButton, ' +
            '#qaThemeToggle, .theme-btn, .theme-button, .theme-toggle'
        );

        if (!button) return;

        const next = resolved() === 'dark' ? 'light' : 'dark';

        queueMicrotask(() => choose(next));
    }, true);

    document.addEventListener('change', event => {
        const target = event.target;

        if (
            target instanceof HTMLInputElement &&
            target.matches('input[name="theme"]') &&
            valid(target.value)
        ) {
            choose(target.value);
        }
    }, true);

    document.addEventListener('submit', event => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) return;

        const selected = form.querySelector(
            'input[name="theme"]:checked'
        );

        if (selected && valid(selected.value)) {
            choose(selected.value);
        }
    }, true);

    const observer = new MutationObserver(() => {
        if (!applying && root.dataset.theme !== resolved()) {
            apply();
        }
    });

    observer.observe(root, {
        attributes: true,
        attributeFilter: ['data-theme']
    });

    window.addEventListener('storage', event => {
        if (event.key === key && valid(event.newValue)) {
            preference = event.newValue;
            apply();
        }
    });

    window.ULearnTheme = {
        getPreference: () => preference,
        setPreference: choose
    };
})();
