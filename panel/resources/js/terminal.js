// SSH terminali: the browser half of the site user's shell (see
// App\Services\Files\WebTerminal and the agent's package terminal).
//
// The page asks the panel for a one-time ticket, opens a WebSocket on
// serfex-web's /terminal/socket with it and shows the shell in xterm.js.
// Binary messages carry the terminal's bytes both ways; text messages are
// JSON controls ({"t":"resize"} from here, ready/idle/exit from the agent).
//
// Copy and paste is meant to just work, which web terminals often get
// wrong:
//   - selecting text copies it (can be turned off);
//   - Ctrl+C copies when text is selected, and interrupts otherwise;
//   - Ctrl+V, Ctrl+Shift+V, Shift+Insert and Cmd+V paste;
//   - right-click opens Kopyala / Yapıştır / Tümünü seç / Temizle;
//   - the toolbar's Yapıştır button reads the clipboard, and when the
//     browser does not allow that (or on a phone), opens a box to paste
//     into;
//   - several lines pasted into a program that does not take bracketed
//     paste (so each line would run at once) ask first.

// xterm.js is a separate chunk, loaded the first time a terminal opens.
let xterm = null;
const loadXterm = () =>
    (xterm ??= Promise.all([
        import('@xterm/xterm'),
        import('@xterm/addon-fit'),
        import('@xterm/addon-web-links'),
        import('@xterm/xterm/css/xterm.css'),
    ]).then(([core, fit, links]) => ({ Terminal: core.Terminal, FitAddon: fit.FitAddon, WebLinksAddon: links.WebLinksAddon })));

const CHUNK = 32 * 1024;
const encoder = new TextEncoder();

const stored = (key, fallback) => {
    try {
        const v = localStorage.getItem(key);
        return v === null ? fallback : JSON.parse(v);
    } catch {
        return fallback;
    }
};
const store = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage blocked: the choice lasts for this page.
    }
};

async function writeClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Older browsers, or no permission: the hidden-textarea way.
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch {
            ok = false;
        }
        area.remove();
        return ok;
    }
}

export function webTerminal(labels) {
    // Kept out of Alpine's reactive state: xterm.js and the socket are
    // not meant to be wrapped in proxies.
    let term = null;
    let fit = null;
    let ws = null;

    return {
        state: 'ready', // ready, connecting, open, closed
        reason: '',
        sessionId: null,
        copyOnSelect: stored('serfex-terminal-copy-on-select', true),
        fontSize: stored('serfex-terminal-font', window.innerWidth < 640 ? 12 : 14),
        menu: { show: false, x: 0, y: 0 },
        pasteBox: false,
        pasteValue: '',
        confirmLines: 0,
        pending: '',
        idleSeconds: 0,
        hint: '',
        ctrl: false,
        hasSelection: false,
        userClosed: false,
        hintTimer: null,
        idleTimer: null,

        init() {
            this.$watch('copyOnSelect', (v) => store('serfex-terminal-copy-on-select', v));
            this.$watch('fontSize', (v) => {
                store('serfex-terminal-font', v);
                if (term) {
                    term.options.fontSize = v;
                    this.refit();
                }
            });
            this.onResize = () => this.refit();
            window.addEventListener('resize', this.onResize);
            // wire:navigate away: end the session like closing the tab.
            this.onLeave = () => this.disconnect();
            document.addEventListener('livewire:navigating', this.onLeave, { once: true });
            window.addEventListener('beforeunload', this.onLeave);
        },

        destroy() {
            window.removeEventListener('resize', this.onResize);
            window.removeEventListener('beforeunload', this.onLeave);
            this.disconnect();
            term?.dispose();
        },

        flash(text) {
            this.hint = text;
            clearTimeout(this.hintTimer);
            this.hintTimer = setTimeout(() => (this.hint = ''), 1600);
        },

        async start() {
            if (this.state === 'connecting' || this.state === 'open') {
                return;
            }
            this.state = 'connecting';
            this.reason = '';
            this.idleSeconds = 0;
            if (!term) {
                try {
                    this.create(await loadXterm());
                } catch {
                    this.state = 'closed';
                    this.reason = labels.failed;
                    return;
                }
            } else {
                term.reset();
            }
            await this.$nextTick();
            this.refit();
            const ticket = await this.$wire.start();
            if (!ticket || ticket.error) {
                this.state = 'closed';
                this.reason = ticket?.error ?? labels.failed;
                return;
            }
            this.sessionId = ticket.id;
            const scheme = location.protocol === 'https:' ? 'wss' : 'ws';
            const url = `${scheme}://${location.host}${ticket.path}?token=${encodeURIComponent(ticket.token)}&cols=${term.cols}&rows=${term.rows}`;
            ws = new WebSocket(url);
            ws.binaryType = 'arraybuffer';
            ws.onmessage = (e) => {
                if (typeof e.data !== 'string') {
                    term.write(new Uint8Array(e.data));
                    return;
                }
                let msg;
                try {
                    msg = JSON.parse(e.data);
                } catch {
                    return;
                }
                if (msg.t === 'ready') {
                    this.state = 'open';
                    term.focus();
                } else if (msg.t === 'idle') {
                    this.idleSeconds = msg.seconds;
                    clearInterval(this.idleTimer);
                    this.idleTimer = setInterval(() => {
                        this.idleSeconds = Math.max(0, this.idleSeconds - 1);
                    }, 1000);
                }
            };
            const socket = ws;
            socket.onclose = () => this.closed(socket);
            ws.onerror = () => {};
        },

        async closed(socket) {
            if (ws !== socket) {
                return;
            }
            ws = null;
            clearInterval(this.idleTimer);
            this.idleSeconds = 0;
            const wasOpen = this.state === 'open';
            this.state = 'closed';
            const id = this.sessionId;
            this.sessionId = null;
            term?.blur();
            let reason = null;
            if (id) {
                try {
                    reason = await this.$wire.ended(id);
                } catch {
                    reason = null;
                }
            }
            if (this.userClosed) {
                reason = labels.closed;
                this.userClosed = false;
            }
            this.reason = reason ?? (wasOpen ? labels.closed : labels.failed);
        },

        // The toolbar's Kapat.
        stop() {
            this.userClosed = true;
            this.disconnect();
        },

        disconnect() {
            if (ws) {
                ws.close(1000);
            }
        },

        create({ Terminal, FitAddon, WebLinksAddon }) {
            term = new Terminal({
                fontFamily: '"IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
                fontSize: this.fontSize,
                lineHeight: 1.15,
                cursorBlink: true,
                scrollback: 5000,
                allowProposedApi: false,
                rightClickSelectsWord: false,
                macOptionIsMeta: true,
                theme: {
                    background: '#0b1012',
                    foreground: '#e4ebec',
                    cursor: '#34b8bc',
                    cursorAccent: '#0b1012',
                    selectionBackground: 'rgba(52, 184, 188, 0.35)',
                    black: '#1b2427', red: '#ef6b6b', green: '#5fcf8a', yellow: '#e8c467',
                    blue: '#6aa8f0', magenta: '#c792ea', cyan: '#34b8bc', white: '#d5dddf',
                    brightBlack: '#5a686d', brightRed: '#ff8a8a', brightGreen: '#7fe0a5', brightYellow: '#f5d78a',
                    brightBlue: '#8cbcf5', brightMagenta: '#d9aef2', brightCyan: '#62d3d6', brightWhite: '#ffffff',
                },
            });
            fit = new FitAddon();
            term.loadAddon(fit);
            term.loadAddon(new WebLinksAddon());
            term.open(this.$refs.screen);

            term.onData((data) => {
                // The phone's Ctrl key applies to the next letter typed.
                if (this.ctrl && data.length === 1) {
                    const code = data.toUpperCase().charCodeAt(0);
                    if (code >= 64 && code < 96) {
                        data = String.fromCharCode(code - 64);
                    }
                    this.ctrl = false;
                }
                this.send(data);
            });
            term.onBinary((data) => this.sendBytes(Uint8Array.from(data, (c) => c.charCodeAt(0))));
            term.onResize(({ cols, rows }) => {
                if (ws?.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ t: 'resize', cols, rows }));
                }
            });
            let selTimer = null;
            term.onSelectionChange(() => {
                this.hasSelection = term.hasSelection();
                clearTimeout(selTimer);
                selTimer = setTimeout(() => {
                    if (this.copyOnSelect && term.hasSelection()) {
                        this.copy(false);
                    }
                }, 200);
            });

            term.attachCustomKeyEventHandler((e) => {
                if (e.type !== 'keydown') {
                    return true;
                }
                if (e.key === 'Escape' && this.menu.show) {
                    this.menu.show = false;
                    return false;
                }
                const key = e.key.toLowerCase();
                const mod = e.ctrlKey && !e.altKey && !e.metaKey;
                // Copy: Ctrl+C with a selection, Ctrl+Shift+C, Ctrl+Insert.
                if ((mod && key === 'c' && (e.shiftKey || term.hasSelection())) || (mod && key === 'insert')) {
                    e.preventDefault();
                    this.copy(true);
                    return false;
                }
                // Paste: let the browser fire its paste event (no permission
                // needed); the handler below takes it.
                if ((mod && key === 'v') || (e.shiftKey && key === 'insert')) {
                    return false;
                }
                return true;
            });
            // Every paste goes through pasteText.
            this.$refs.screen.addEventListener(
                'paste',
                (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    this.pasteText(e.clipboardData?.getData('text/plain') ?? '');
                },
                true,
            );
            this.$refs.screen.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                const box = this.$refs.wrap.getBoundingClientRect();
                this.menu = { show: true, x: Math.min(e.clientX - box.left, box.width - 190), y: Math.min(e.clientY - box.top, box.height - 170) };
            });
        },

        refit() {
            if (!fit) {
                return;
            }
            try {
                fit.fit();
            } catch {
                // Not laid out yet.
            }
        },

        send(text) {
            if (ws?.readyState !== WebSocket.OPEN) {
                return;
            }
            this.idleSeconds = 0;
            clearInterval(this.idleTimer);
            this.sendBytes(encoder.encode(text));
        },

        sendBytes(bytes) {
            if (ws?.readyState !== WebSocket.OPEN) {
                return;
            }
            for (let i = 0; i < bytes.length; i += CHUNK) {
                ws.send(bytes.subarray(i, i + CHUNK));
            }
        },

        async copy(clear) {
            const text = term?.getSelection() ?? '';
            if (text === '') {
                return;
            }
            if (await writeClipboard(text)) {
                this.flash(labels.copied);
            }
            if (clear) {
                term.clearSelection();
            }
            term.focus();
        },

        // Several lines run one by one unless the program in the terminal
        // takes bracketed paste (bash, zsh, vim do): then ask first.
        pasteText(text) {
            this.menu.show = false;
            if (!text || this.state !== 'open') {
                return;
            }
            const lines = text.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n').length;
            if (lines > 1 && !term.modes.bracketedPasteMode) {
                this.pending = text;
                this.confirmLines = lines;
                return;
            }
            term.paste(text);
            term.focus();
        },

        confirmPaste() {
            const text = this.pending;
            this.pending = '';
            this.confirmLines = 0;
            term.paste(text);
            term.focus();
        },

        cancelPaste() {
            this.pending = '';
            this.confirmLines = 0;
            term?.focus();
        },

        // The toolbar and menu button: the clipboard if the browser lets
        // the page read it, else a box to paste into.
        async pasteFromClipboard() {
            this.menu.show = false;
            try {
                const text = await navigator.clipboard.readText();
                if (text !== '') {
                    this.pasteText(text);
                    return;
                }
            } catch {
                // Not allowed: the box below.
            }
            this.pasteValue = '';
            this.pasteBox = true;
            this.$nextTick(() => this.$refs.pasteArea?.focus());
        },

        submitPasteBox() {
            const text = this.pasteValue;
            this.pasteBox = false;
            this.pasteValue = '';
            this.pasteText(text);
        },

        selectAll() {
            this.menu.show = false;
            term?.selectAll();
        },

        clearScreen() {
            this.menu.show = false;
            term?.clear();
            term?.focus();
        },

        // The phone's key row.
        key(seq) {
            this.send(seq);
            term?.focus();
        },

        toggleCtrl() {
            this.ctrl = !this.ctrl;
            term?.focus();
        },

        zoom(step) {
            this.fontSize = Math.min(22, Math.max(11, this.fontSize + step));
        },

        fullscreen() {
            const el = this.$refs.wrap;
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                el.requestFullscreen?.().then(() => setTimeout(() => this.refit(), 100));
            }
        },
    };
}
