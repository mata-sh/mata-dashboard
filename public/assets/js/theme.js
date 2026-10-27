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

mataApplyChartTheme();

window.mataThemeColor = mataThemeColor;
