// Theme: follows the system unless the user picked one with the top-bar button.
window.servikToggleTheme = () => {
    const root = document.documentElement;
    const dark =
        root.dataset.theme === 'dark' ||
        (!root.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.dataset.theme = dark ? 'light' : 'dark';
    try {
        localStorage.setItem('servik-theme', root.dataset.theme);
    } catch {
        // Storage may be blocked; the choice then lasts for this page only.
    }
};
