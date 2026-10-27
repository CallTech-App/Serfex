#!/usr/bin/env python3
"""Serfex ekranlarını derler: pages/<ad>.html (main içeriği + isteğe bağlı <script>) -> out/<ad>.html"""
import os, sys
D = os.path.dirname(os.path.abspath(__file__))
PAGES = {
  'index': 'Serfex Panel', 'canli-izleme': 'Serfex Canlı İzleme', 'ai-asistan': 'Serfex AI Asistan',
  'siteler': 'Serfex Siteler', 'site-detay': 'Serfex Site Detayı', 'laravel': 'Serfex Laravel',
  'dns': 'Serfex DNS', 'dns-detay': 'Serfex DNS Kayıtları', 'laravel-detay': 'Serfex Laravel Uygulaması', 'eposta-detay': 'Serfex Posta Kutuları', 'dosyalar-detay': 'Serfex Dosya Yöneticisi', 'php-ayarlari': 'Serfex PHP ve Web Sunucusu', 'eposta': 'Serfex E-posta', 'veritabanlari': 'Serfex Veritabanları',
  'ssl': 'Serfex SSL', 'guvenlik': 'Serfex Güvenlik',
  'yedekler': 'Serfex Yedekler', 'hizmetler': 'Serfex Hizmetler', 'musteriler': 'Serfex Müşteriler',
  'bilesenler': 'Serfex Yazılım Yönetimi', 'ayarlar': 'Serfex Ayarlar', 'guncellemeler': 'Serfex Güncellemeler',
}
ACTIVE_ALIAS = {'site-detay': 'siteler', 'dns-detay': 'dns', 'laravel-detay': 'laravel', 'eposta-detay': 'eposta', 'dosyalar-detay': 'siteler', 'php-ayarlari': 'siteler', 'guncellemeler': 'ayarlar'}
NAV = [
  (None, [('index', 'layout-dashboard', 'Genel Bakış', ''), ('canli-izleme', 'activity', 'Canlı İzleme', 'crit:2'), ('ai-asistan', 'sparkles', 'Serfex AI', '')]),
  ('Barındırma', [('siteler', 'globe', 'Siteler', '24'), ('laravel', 'layers', 'Laravel', '7'), ('dns', 'network', 'Alan Adları ve DNS', ''),
                  ('eposta', 'mail', 'E-posta', ''), ('veritabanlari', 'database', 'Veritabanları', ''), ('ssl', 'shield-check', 'SSL Sertifikaları', '')]),
  ('Hesaplar', [('musteriler', 'users', 'Müşteriler ve Paketler', '')]),
  ('Sunucu', [('guvenlik', 'shield-alert', 'Güvenlik', ''), ('yedekler', 'archive', 'Yedekler', ''), ('hizmetler', 'server-cog', 'Hizmetler', ''), ('bilesenler', 'package-plus', 'Yazılım Yönetimi', ''),
              ('ayarlar', 'settings', 'Araçlar ve Ayarlar', '')]),
]
def nav(active):
    active = ACTIVE_ALIAS.get(active, active)
    out = ['<nav class="flex-1 overflow-y-auto scroll-thin px-3 py-4 space-y-6 text-[13.5px]" aria-label="Ana menü">']
    for g, items in NAV:
        out.append('      <div>')
        if g: out.append(f'        <p class="px-3 mb-1.5 text-2xs uppercase tracking-[.08em] text-nav-muted font-medium">{g}</p>')
        out.append('        <div class="space-y-0.5">')
        for key, icon, label, badge in items:
            on = key == active
            cls = 'bg-nav-active text-white font-medium' if on else 'text-nav-fg/80 hover:bg-nav-active hover:text-white'
            ic = ' text-accent' if on else ''
            b = ''
            if badge.startswith('crit:'):
                b = f'<span class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-crit text-on-crit text-2xs font-semibold grid place-items-center num">{badge[5:]}</span>'
            elif badge:
                b = f'<span class="ml-auto text-2xs text-nav-muted num">{badge}</span>'
            cur = ' aria-current="page"' if on else ''
            out.append(f'          <a href="{key}.html"{cur} class="flex items-center gap-3 px-3 h-9 rounded-md {cls}"><i data-lucide="{icon}" class="w-4 h-4{ic}"></i>{label}{b}</a>')
        out.append('        </div>\n      </div>')
    out.append('    </nav>')
    return '\n'.join(out)
def wrap(h, b):
    return ('<!doctype html>\n<html lang="tr">\n<head>\n<meta charset="utf-8">\n'
            '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n' + h + '</head>\n<body>\n' + b + '</body>\n</html>\n')
def build(name):
    head = open(f'{D}/src/head.html').read().replace('{{TITLE}}', PAGES[name])
    top = open(f'{D}/src/shell-top.html').read().replace('{{NAV}}', nav(name))
    bottom = open(f'{D}/src/shell-bottom.html').read()
    body = top + open(f'{D}/pages/{name}.html').read() + '\n' + bottom
    open(f'{D}/out/{name}.html', 'w').write(wrap(head, body))
    if name == 'index':
        open(f'{D}/out/_index-fragment.html', 'w').write(head + body)
if __name__ == '__main__':
    names = sys.argv[1:] or [n for n in PAGES if os.path.exists(f'{D}/pages/{n}.html')]
    for n in names: build(n); print('built', n)
