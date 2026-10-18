"""Plesk tarzı liste sayfası üretir: başlık, araç çubuğu, tek tablo; satır detay sayfasına gider."""
import json
def badge(tone, text):
    return f'<span class="inline-flex items-center gap-1.5 text-2xs font-medium px-2 py-0.5 rounded-full whitespace-nowrap bg-{tone}-soft text-{tone}"><i class="w-1.5 h-1.5 rounded-full bg-{tone}"></i>{text}</span>'
def page(title, desc, cols, rows, href, total, noun, create=None, search_ph='Ara'):
    th = ''.join(f'<th class="font-medium px-3 py-2.5{" text-right" if c.endswith(">") else ""}">{c.rstrip(">")}</th>' for c in cols)
    right = [c.endswith('>') for c in cols]
    body_rows = []
    for r in rows:
        name = r[0]
        cells = [f'<td class="px-5 py-3"><a href="{href}" class="font-mono text-[13px] font-medium hover:text-accent">{name}</a>{("<div class=\"text-2xs text-muted mt-0.5\">"+r[1]+"</div>") if r[1] else ""}</td>']
        for v, rt in zip(r[2:], right[1:]):
            cells.append(f'<td class="px-3 py-3{" text-right num" if rt else ""} whitespace-nowrap">{v}</td>')
        body_rows.append(f'<tr class="hover:bg-surface-2/60 cursor-pointer" data-name="{name}">' + ''.join(cells) + '</tr>')
    btn, modal, mjs = '', '', ''
    if create:
        label, fields = create
        btn = f'<button id="newBtn" class="h-9 px-3.5 rounded-md bg-accent text-accent-fg hover:brightness-110 text-[13.5px] font-medium flex items-center gap-2"><i data-lucide="plus" class="w-4 h-4"></i>{label}</button>'
        fh = []
        for i, (fl, kind) in enumerate(fields):
            fid = f'f{i}'
            if isinstance(kind, list):
                inp = f'<select id="{fid}" class="w-full h-9 px-2.5 rounded-md border border-line bg-surface">' + ''.join(f'<option>{o}</option>' for o in kind) + '</select>'
            else:
                inp = f'<input id="{fid}" placeholder="{kind}" class="w-full h-9 px-3 rounded-md border border-line bg-surface focus:border-accent outline-none font-mono text-[13px]">'
            fh.append(f'<div><label for="{fid}" class="block mb-1.5">{fl}</label>{inp}</div>')
        modal = f'''
      <div id="newModal" hidden class="fixed inset-0 z-50 bg-black/40 grid place-items-center p-4">
        <form id="newForm" class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-labelledby="newTitle">
          <h2 id="newTitle" class="px-5 h-12 flex items-center border-b border-line text-[14.5px] font-semibold">{label}</h2>
          <div class="p-5 space-y-4 text-[13.5px]">{''.join(fh)}</div>
          <div class="px-5 py-3 border-t border-line flex justify-end gap-2">
            <button type="button" id="newCancel" class="h-9 px-3.5 rounded-md border border-line hover:bg-surface-2 text-[13.5px]">Vazgeç</button>
            <button type="submit" class="h-9 px-3.5 rounded-md bg-accent text-accent-fg hover:brightness-110 text-[13.5px] font-medium">{label.split()[-1].capitalize()}</button>
          </div>
        </form>
      </div>'''
        mjs = f'''
  const nm = document.getElementById('newModal');
  document.getElementById('newBtn').onclick = () => {{ nm.hidden = false; nm.querySelector('input,select').focus(); }};
  document.getElementById('newCancel').onclick = () => nm.hidden = true;
  document.getElementById('newForm').addEventListener('submit', e => {{ e.preventDefault(); nm.hidden = true; toast({json.dumps(label + ' işlemi başladı. Bitince bildirim alacaksınız.')}); }});'''
    return f'''      <!-- Başlık -->
      <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{title}</h1>
        <p class="mt-1 text-[13px] text-muted">{desc}</p>
      </div>

      <section class="rounded-xl border border-line bg-surface min-w-0">
        <div class="flex flex-wrap items-center gap-2 px-5 py-3 border-b border-line">
          {btn}
          <label class="ml-auto relative w-full sm:w-64">
            <span class="sr-only">{search_ph}</span>
            <i data-lucide="search" class="w-4 h-4 text-muted absolute left-3 top-1/2 -translate-y-1/2"></i>
            <input id="q" type="search" placeholder="{search_ph}" class="w-full h-9 pl-9 pr-3 rounded-md bg-surface-2 border border-transparent focus:border-accent focus:bg-surface outline-none text-[13.5px]">
          </label>
        </div>
        <div class="relative overflow-x-auto scroll-thin">
          <table class="w-full text-[13.5px] min-w-[720px]">
            <thead><tr class="text-left text-2xs uppercase tracking-[.06em] text-muted border-b border-line">{th.replace('px-3 py-2.5', 'px-5 py-2.5', 1)}</tr></thead>
            <tbody id="rows" class="divide-y divide-line">
              {chr(10).join(body_rows)}
            </tbody>
          </table>
        </div>
        <p class="px-5 py-3 border-t border-line text-2xs text-muted num" id="count">{len(rows)} / {total} {noun}</p>
      </section>
{modal}

<script>
  const rows = document.getElementById('rows');
  rows.addEventListener('click', e => {{ if (e.target.closest('a,button,input')) return; const tr = e.target.closest('tr'); if (tr) location.href = '{href}'; }});
  document.getElementById('q').oninput = e => {{
    const q = e.target.value.trim().toLowerCase(); let n = 0;
    rows.querySelectorAll('tr').forEach(tr => {{ const show = tr.dataset.name.includes(q); tr.hidden = !show; n += show; }});
    document.getElementById('count').textContent = q ? `${{n}} {noun}` : '{len(rows)} / {total} {noun}';
  }};{mjs}
</script>
'''
