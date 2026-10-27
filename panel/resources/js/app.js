// Theme: follows the system unless the user picked one with the top-bar button.
window.serfexToggleTheme = () => {
    const root = document.documentElement;
    const dark =
        root.dataset.theme === 'dark' ||
        (!root.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.dataset.theme = dark ? 'light' : 'dark';
    try {
        localStorage.setItem('serfex-theme', root.dataset.theme);
    } catch {
        // Storage may be blocked; the choice then lasts for this page only.
    }
};

// The inline script in each layout's <head> applies the stored choice on a
// full page load. wire:navigate does not run it again: it copies the new
// page's <html> attributes, which drops data-theme, so the page fell back to
// the system theme. Put the choice back as soon as the new page is swapped
// in (before it is painted), and when another tab changes it.
const applyStoredTheme = () => {
    let theme = null;
    try {
        theme = localStorage.getItem('serfex-theme');
    } catch {
        // Storage blocked: keep whatever this page already shows.
        return;
    }
    const root = document.documentElement;
    if (theme === 'light' || theme === 'dark') {
        root.dataset.theme = theme;
    } else {
        delete root.dataset.theme;
    }
};
document.addEventListener('livewire:navigating', (event) => event.detail.onSwap(applyStoredTheme));
document.addEventListener('livewire:navigated', applyStoredTheme);
window.addEventListener('storage', (event) => {
    if (event.key === 'serfex-theme') {
        applyStoredTheme();
    }
});

// A Livewire request that never reaches the panel (network down, or a
// connection the browser refused) shows <x-connection-lost> instead of a
// button that waits forever; the next request that gets through hides it.
const watchConnection = () => {
    window.Livewire.interceptRequest(({ onFailure, onResponse }) => {
        const tell = (ok) => window.dispatchEvent(new CustomEvent('serfex-connection', { detail: { ok } }));
        onFailure(() => tell(false));
        onResponse(() => tell(true));
    });
};
if (window.Livewire) {
    watchConnection();
} else {
    document.addEventListener('livewire:init', watchConnection);
}
