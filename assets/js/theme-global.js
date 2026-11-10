
/* ULearn unified appearance preference — Light, Dark, System. */
(() => {
    'use strict';

    const key = 'ulearn-theme-preference';
    const legacyKey = 'ulearn-theme';
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const valid = v => ['light', 'dark', 'system'].includes(v);

    const read = () => {
        try {
            const saved = localStorage.getItem(key);

            if (valid(saved)) return saved;

            const legacy = localStorage.getItem(legacyKey);

            return valid(legacy) ? legacy : 'system';
        } catch (_) {
            return 'system';
        }
    };

    let pref = read();
    let applying = false;

    const resolved = () => {
        return pref === 'system'
            ? (media.matches ? 'dark' : 'light')
            : pref;
    };

    function updateThemeIcons(actual) {
        const qaToggle = document.getElementById('qaThemeToggle');

        if (!qaToggle) return;

        if (actual === 'dark') {
            qaToggle.innerHTML = `
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
            `;

            qaToggle.setAttribute('aria-label', 'Switch to light mode');
            qaToggle.setAttribute('title', 'Switch to light mode');

        } else {
            qaToggle.innerHTML = `
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            `;

            qaToggle.setAttribute('aria-label', 'Switch to dark mode');
            qaToggle.setAttribute('title', 'Switch to dark mode');
        }
    }

    function apply() {
        const actual = resolved();

        applying = true;

        root.setAttribute('data-theme', actual);
        root.style.colorScheme = actual;

        updateThemeIcons(actual);

        applying = false;
    }

    function choose(value) {
        if (!valid(value)) return;

        pref = value;

        try {
            localStorage.setItem(key, value);
            localStorage.setItem(legacyKey, resolved());
        } catch (_) {}

        apply();
    }

    // Apply saved preference.
    apply();

    // Keep the page synchronized with the saved preference.
    const observer = new MutationObserver(() => {
        if (!applying && root.dataset.theme !== resolved()) {
            apply();
        }
    });

    observer.observe(root, {
        attributes: true,
        attributeFilter: ['data-theme']
    });

    // Follow system appearance when System is selected.
    media.addEventListener?.('change', () => {
        if (pref === 'system') apply();
    });

    // Synchronize theme across browser tabs.
    window.addEventListener('storage', e => {
        if (e.key === key) {
            pref = read();
            apply();
        }
    });

    // Handle all ULearn theme buttons, including Q&A.
    document.addEventListener('click', e => {
        const target = e.target;

        if (!(target instanceof Element)) return;

        const btn = target.closest(
            '#themeToggle, #theme-toggle, #qaThemeToggle'
        );

        if (!btn) return;

        const next = resolved() === 'dark' ? 'light' : 'dark';

        queueMicrotask(() => {
            choose(next);
        });

    }, true);

    // Handle profile theme radio buttons.
    document.addEventListener('change', e => {
        const target = e.target;

        if (
            target instanceof HTMLInputElement &&
            target.matches('input[name="theme"]') &&
            valid(target.value)
        ) {
            choose(target.value);
        }
    }, true);

    // Handle profile theme form submissions.
    document.addEventListener('submit', e => {
        const form = e.target;

        if (!(form instanceof HTMLFormElement)) return;

        const selected = form.querySelector(
            'input[name="theme"]:checked'
        );

        if (selected && valid(selected.value)) {
            choose(selected.value);
        }
    }, true);

    // Update the Q&A icon after the page has loaded.
    document.addEventListener('DOMContentLoaded', apply);

    // Public theme API.
    window.ULearnTheme = {
        getPreference: () => pref,
        setPreference: choose
    };

})();
