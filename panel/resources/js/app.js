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

// A Livewire request that never reaches the panel (network down, or a
// connection the browser refused) shows <x-connection-lost> instead of a
// button that waits forever; the next request that gets through hides it.
const watchConnection = () => {
    window.Livewire.interceptRequest(({ onFailure, onResponse }) => {
        const tell = (ok) => window.dispatchEvent(new CustomEvent('servik-connection', { detail: { ok } }));
        onFailure(() => tell(false));
        onResponse(() => tell(true));
    });
};
if (window.Livewire) {
    watchConnection();
} else {
    document.addEventListener('livewire:init', watchConnection);
}
