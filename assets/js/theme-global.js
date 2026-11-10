
/* ULearn Global User Theme System */
(() => {
    'use strict';

    const root = document.documentElement;
    const api = '/ulearn/theme-preference.php';

    const key = 'ulearn-theme-preference';

    const media = window.matchMedia(
        '(prefers-color-scheme: dark)'
    );

    let preference = 'system';
    let csrfToken = '';
    let userChanged = false;

    const valid = value =>
        ['light', 'dark', 'system'].includes(value);

    const resolved = () => {
        if (preference === 'system') {
            return media.matches ? 'dark' : 'light';
        }

        return preference;
    };

    
function apply() {
    const theme = resolved();

    root.setAttribute('data-theme', theme);
    root.style.colorScheme = theme;

    // Update the same Moon / Sun icon on every page.
    document.querySelectorAll('.theme-icon').forEach(icon => {
        icon.textContent = theme === 'dark' ? '☀' : '☾';
    });

    try {
        localStorage.setItem(key, preference);
        localStorage.setItem('ulearn-theme', theme);
    } catch (error) {}
}



    async function saveToDatabase(theme) {
        if (!csrfToken) return;

        try {
            const response = await fetch(api, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    theme: theme,
                    csrf: csrfToken
                })
            });

            if (!response.ok) {
                console.error('Theme save failed.');
            }
        } catch (error) {
            console.error('Could not save theme.');
        }
    }

    function choose(theme) {
        if (!valid(theme)) return;

        userChanged = true;
        preference = theme;

        apply();
        saveToDatabase(theme);
    }

    // Load the logged-in user's preference from the database.
    async function loadPreference() {
        try {
            const response = await fetch(api, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) return;

            const data = await response.json();

            if (!valid(data.theme)) return;

            csrfToken = data.csrf || '';

            if (!userChanged) {
                preference = data.theme;
                apply();
            }
        } catch (error) {
            console.error('Could not load theme preference.');
        }
    }

    // Initial theme while database preference loads.
    try {
        const saved = localStorage.getItem(key);

        if (valid(saved)) {
            preference = saved;
        }
    } catch (error) {}

    apply();

    // Load the actual user preference.
    loadPreference();

    document.addEventListener('DOMContentLoaded', () => {
    const theme = resolved();

    document.querySelectorAll('.theme-icon').forEach(icon => {
        icon.textContent = theme === 'dark' ? '☀' : '☾';
    });
});

    // Listen for system theme changes.
    media.addEventListener?.('change', () => {
        if (preference === 'system') {
            apply();
        }
    });

    // Support all existing theme buttons.
    document.addEventListener('click', event => {
        const button = event.target.closest(
            '#themeToggle, #theme-toggle, #themeButton, #qaThemeToggle, .theme-btn, .theme-button, .theme-toggle'
        );

        if (!button) return;

        const next = resolved() === 'dark'
            ? 'light'
            : 'dark';

        queueMicrotask(() => choose(next));
    }, true);

    // Support Appearance radio buttons.
    document.addEventListener('change', event => {
        const target = event.target;

        if (
            target.matches('input[name="theme"]') &&
            valid(target.value)
        ) {
            choose(target.value);
        }
    }, true);

    // Support existing Appearance forms.
    document.addEventListener('submit', event => {
        const selected = event.target.querySelector(
            'input[name="theme"]:checked'
        );

        if (selected && valid(selected.value)) {
            choose(selected.value);
        }
    }, true);

    // Prevent older page scripts from overriding the chosen theme.
    let applying = false;

    const observer = new MutationObserver(() => {
        if (
            !applying &&
            root.dataset.theme !== resolved()
        ) {
            applying = true;
            apply();
            applying = false;
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
