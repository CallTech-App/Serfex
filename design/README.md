# Design

`mockup/` contains the approved HTML + Tailwind mockups of the Servik panel
(about 20 screens, light and dark). Open `mockup/index.html` in a browser.

- `mockup/*.html` are the built pages.
- `mockup/kaynak/` holds the editable sources: `pages/` for each screen's
  content, `src/` for the shared shell, and `build.py` to rebuild the pages.
- `mockup/kaynak/src/GUIDE.md` lists the design rules (in Turkish).
- `mockup/laravel-kapsam-kontrol.md` is the v1 Laravel feature checklist.

The mockups are a reference for building the Livewire UI in `panel/`; they are
not served by the panel.
