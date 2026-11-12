
/* ULearn Global Theme System
 * Shared by Admin, Student and Lecturer.
 */
(() => {
    'use strict';

    if (window.ULearnTheme) return;

    const root = document.documentElement;

    const STORAGE_KEY = 'ulearn-theme-preference';
    const LEGACY_KEY = 'ulearn-theme';

    const API = new URL(
        '../../theme-preference.php',
        document.currentScript.src
    ).href;

    const VALID_THEMES = ['light', 'dark', 'system'];

    const media = window.matchMedia(
        '(prefers-color-scheme: dark)'
    );

    let preference = 'system';
    let csrfToken = '';
    let userChanged = false;
    let saveQueue = Promise.resolve();

    const validTheme = value =>
        VALID_THEMES.includes(value);

    function getActiveTheme() {
        if (preference === 'system') {
            return media.matches ? 'dark' : 'light';
        }

        return preference;
    }

    function updateButton(button) {
        if (!(button instanceof HTMLButtonElement)) return;

        const active = getActiveTheme();

        const icon = button.querySelector(
            '.theme-icon, .theme-symbol, #ul-theme-icon, #themeIcon, #themeSymbol, #ucThemeIcon, #upaThemeIcon'
        );

        if (icon) {
            icon.textContent = active === 'dark' ? '☀' : '☾';
        }

        const label = button.querySelector(
            '.theme-name, .ex-theme-label, #themeName, #ul-theme-text, #qh-theme-text, #notes-theme-label'
        );

        if (label) {
            label.textContent =
                active === 'dark' ? 'Light mode' : 'Dark mode';
        }

        button.setAttribute(
            'aria-label',
            active === 'dark'
                ? 'Switch to light mode'
                : 'Switch to dark mode'
        );

        button.setAttribute('aria-pressed', String(active === 'dark'));
    }

    function updateAllButtons() {
        document.querySelectorAll(
            '#themeToggle, #theme-toggle, #themeButton, #nextTheme, #qaThemeToggle, #ucTheme, #utTheme, #upaTheme, #ul-theme-toggle, #theme-button, #qh-theme, #ex-theme-toggle, #notes-theme-toggle, .theme-btn, .theme-button, .theme-toggle, .theme, .uc-theme, .ut-theme, .upa-theme, .qh-theme, .ex-theme-toggle, .notes-theme-toggle, .ui-theme, .uai-theme, [data-theme-toggle="true"]'
        ).forEach(updateButton);
    }

    function applyTheme() {
        const active = getActiveTheme();

        root.dataset.theme = active;
        root.style.colorScheme = active;

        try {
            localStorage.setItem(STORAGE_KEY, preference);
            localStorage.setItem(LEGACY_KEY, active);
        } catch (error) {
            // Continue without local storage.
        }

        updateAllButtons();
    }

    async function saveTheme() {
        if (!csrfToken) return;

        const currentPreference = preference;

        saveQueue = saveQueue.then(async () => {
            const response = await fetch(API, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    theme: currentPreference,
                    csrf: csrfToken
                })
            });

            if (!response.ok) {
                throw new Error('Theme save failed: ' + response.status);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Theme save failed.');
            }
        }).catch(error => {
            console.error('ULearn theme save error:', error);
        });

        return saveQueue;
    }

    function setTheme(value) {
        if (!validTheme(value)) return;

        userChanged = true;
        preference = value;

        applyTheme();
        saveTheme();
    }

    async function loadTheme() {
        try {
            const response = await fetch(API, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('Unable to load theme preference.');
            }

            const data = await response.json();

            csrfToken = data.csrf || '';

            if (!userChanged && validTheme(data.theme)) {
                preference = data.theme;
                applyTheme();
            }

            if (userChanged) {
                saveTheme();
            }

        } catch (error) {
            console.error('ULearn theme loading error:', error);
        }
    }

    function handleToggle(event) {
        const target = event.target;

        if (!(target instanceof Element)) return;

        const button = target.closest(
            '#themeToggle, #theme-toggle, #themeButton, #nextTheme, #qaThemeToggle, #ucTheme, #utTheme, #upaTheme, #ul-theme-toggle, #theme-button, #qh-theme, #ex-theme-toggle, #notes-theme-toggle, .theme-btn, .theme-button, .theme-toggle, .theme, .uc-theme, .ut-theme, .upa-theme, .qh-theme, .ex-theme-toggle, .notes-theme-toggle, .ui-theme, .uai-theme, [data-theme-toggle="true"]'
        );

        if (!button) return;

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        setTheme(getActiveTheme() === 'dark' ? 'light' : 'dark');
    }

    function ensureThemeButton() {
        const existing = document.querySelector(
            '#themeToggle, #theme-toggle, #themeButton, #nextTheme, #qaThemeToggle, #ucTheme, #utTheme, #upaTheme, #ul-theme-toggle, #theme-button, #qh-theme, #ex-theme-toggle, #notes-theme-toggle, .theme-btn, .theme-button, .theme-toggle, .theme, .uc-theme, .ut-theme, .upa-theme, .qh-theme, .ex-theme-toggle, .notes-theme-toggle, .ui-theme, .uai-theme, [data-theme-toggle="true"]'
        );

        if (existing) {
            updateAllButtons();
            return;
        }

        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'ulearn-global-theme-toggle';
        button.dataset.themeToggle = 'true';
        button.innerHTML =
            '<span class="theme-icon" aria-hidden="true">☾</span>';

        const style = document.createElement('style');

        style.textContent = `
            .ulearn-global-theme-toggle {
                width:42px;
                height:42px;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                border:1px solid var(--border,#ddd);
                border-radius:12px;
                background:var(--surface,#fff);
                color:var(--text,#222);
                font-size:21px;
                cursor:pointer;
                margin-left:8px;
            }
            .ulearn-global-theme-toggle.is-floating {
                position:fixed;
                top:16px;
                right:18px;
                z-index:9999;
            }
        `;

        document.head.appendChild(style);

        const host = document.querySelector(
            '.top-actions, .topbar-right, .topbar .actions, .header-actions, .uai-headright, .upa-nav, .topbar, .top, header nav, header'
        );

        if (host) {
            host.appendChild(button);
        } else {
            button.classList.add('is-floating');
            document.body.appendChild(button);
        }

        updateButton(button);
    }

    // Restore preference before the page is displayed.
    try {
        const saved = localStorage.getItem(STORAGE_KEY);

        if (validTheme(saved)) {
            preference = saved;
        } else {
            const legacy = localStorage.getItem(LEGACY_KEY);

            if (legacy === 'light' || legacy === 'dark') {
                preference = legacy;
            }
        }
    } catch (error) {
        // Use system preference.
    }

    applyTheme();

    // One global click handler for all theme buttons.
    document.addEventListener('click', handleToggle, true);

    // Support Light, Dark and System radio controls.
    document.addEventListener('change', event => {
        const target = event.target;

        if (
            target instanceof HTMLInputElement &&
            target.name === 'theme' &&
            validTheme(target.value)
        ) {
            setTheme(target.value);
        }
    }, true);

    media.addEventListener('change', () => {
        if (preference === 'system') {
            applyTheme();
        }
    });

    window.addEventListener('storage', event => {
        if (event.key === STORAGE_KEY && validTheme(event.newValue)) {
            preference = event.newValue;
            applyTheme();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            ensureThemeButton,
            { once: true }
        );
    } else {
        ensureThemeButton();
    }

    loadTheme();

    window.ULearnTheme = {
        getPreference: () => preference,
        getActiveTheme,
        setPreference: setTheme
    };

})();
