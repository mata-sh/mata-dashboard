// Light/dark theme. The server renders <html data-theme> from the mata_theme cookie, so the
// page paints in the right theme without an inline script; this file only handles switching.

const mataThemeCookie = 'mata_theme';

function mataCurrentTheme() {
    return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}

// Theme colors for canvas code (Chart.js), which cannot read CSS variables itself.
function mataThemeColor(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Tick labels and grid lines of every chart follow these defaults.
function mataApplyChartTheme() {
    if (!window.Chart) {
        return;
    }

    window.Chart.defaults.color = mataThemeColor('--chart-text');
    window.Chart.defaults.borderColor = mataThemeColor('--chart-grid');
}

// The cookie covers the whole app, also when it runs below a sub-path (see <base href>).
function mataThemeCookiePath() {
    return new URL(document.baseURI).pathname.replace(/\/+$/, '') || '/';
}

function syncThemeToggles() {
    const light = mataCurrentTheme() === 'light';
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', light ? 'true' : 'false');
        button.title = light ? 'Switch to dark theme' : 'Switch to light theme';
    });
}

function mataSetTheme(theme) {
    document.documentElement.dataset.theme = theme;
    document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', theme);
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${mataThemeCookie}=${theme}; Path=${mataThemeCookiePath()}; Max-Age=31536000; SameSite=Lax${secure}`;
    syncThemeToggles();
    document.dispatchEvent(new CustomEvent('mata:themechange', { detail: { theme } }));
}

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-theme-toggle]')) {
        mataSetTheme(mataCurrentTheme() === 'light' ? 'dark' : 'light');
    }
});

// Charts on screen: new defaults, and datasets re-read the tokens named in dataset.themeColors,
// e.g. { borderColor: '--chart-line' }.
document.addEventListener('mata:themechange', () => {
    mataApplyChartTheme();
    if (!window.Chart) {
        return;
    }

    Object.values(window.Chart.instances).forEach((chart) => {
        chart.data.datasets.forEach((dataset) => {
            Object.entries(dataset.themeColors || {}).forEach(([option, token]) => {
                dataset[option] = mataThemeColor(token);
            });
        });
        chart.update('none');
    });
});

mataApplyChartTheme();

window.mataThemeColor = mataThemeColor;
