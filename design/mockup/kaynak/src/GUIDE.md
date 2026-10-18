# Servik ekran yazım rehberi

Servik: açık kaynak web hosting paneli (Plesk/cPanel alternatifi), güçlü Laravel araç seti, yerleşik AI (Claude). Arayüz dili Türkçe.

## Dosya düzeni
- `pages/<ad>.html` = YALNIZCA `<main>` etiketinin İÇERİĞİ, ardından isteğe bağlı tek bir `<script>` bloğu. Kenar menüsü, üst çubuk, AI çekmecesi, bildirim (toast), tema, ikonlar ortak kabukta (`src/`). Onlara dokunma.
- Derle: `python3 build.py <ad>` -> `out/<ad>.html`. Kontrol: `NODE_PATH=$(npm root -g) node check.js <ad>` -> `shots/<ad>-desktop.png` (açık tema 1440) ve `shots/<ad>-mobile.png` (koyu tema 400). scrollWidth viewport'a eşit olmalı, hata olmamalı.
- Örnek: `pages/index.html` (ana panel) ve `ds-body.html` (tasarım sistemi). Aynı görsel dili, sınıfları ve yoğunluğu kullan.

## Ortak kabuğun sunduğu şeyler
- `<main>` zaten `space-y-6` ile dikey boşluk verir; sayfa doğrudan bölümleri sıralar.
- `data-toast="Mesaj"` olan her düğme tıklanınca bildirim gösterir; `data-undo="1"` eklersen "Geri al" çıkar. JS'ten: `toast('Mesaj', true)`.
- `<svg class="spark w-full h-12" data-values="1,2,3" data-color="accent|info|warn|ok|crit" viewBox="0 0 200 48" preserveAspectRatio="none"></svg>` mini grafik; dinamik eklersen `renderSparks(el)` çağır.
- Lucide ikonları: `<i data-lucide="ad" class="w-4 h-4"></i>` (lucide 0.460.0 adları). JS ile HTML eklersen sonunda `lucide.createIcons()` çağır.
- Sayfa script'i ortak script'ten ÖNCE çalışır; tıklama işleyicileri içinde `toast`, `renderSparks`, `lucide` kullanılabilir.

## Görsel kurallar
- Renkler YALNIZCA token sınıfları: bg, surface, surface-2, line, fg, muted, accent, accent-fg, accent-soft, ok/ok-soft, warn/warn-soft, crit/crit-soft/on-crit, info/info-soft. Asla hex, `text-white` (kırmızı düğmede `text-on-crit`), `gray-*`, `blue-*` vb. kullanma. Açık/koyu tema otomatik çalışır.
- Durum rengi anlamı: ok sorun yok, warn bakılmalı, crit ziyaretçi etkileniyor, info süren iş. accent yalnızca birincil eylem, bağlantı, Servik ve AI.
- Yazı: IBM Plex Sans; alan adı, IP, yol, komut, sürüm, hash, kayıt değeri `font-mono`. Sayılar `num` sınıfı (tabular). Türkçe sayı biçimi: 1.240, 21,4 GB, %92; saat 24 saat (23:41).
- Boyutlar: sayfa başlığı `text-[22px] font-semibold tracking-tight`; bölüm başlığı `text-[14.5px] font-semibold`; gövde 13–13.5px; etiket `text-2xs uppercase tracking-[.06em] text-muted font-medium`.
- Bölüm kartı: `rounded-xl border border-line bg-surface`, başlık şeridi `flex items-center gap-3 px-5 h-12 border-b border-line`. Gölge yalnızca açılır katmanlarda.
- Düğmeler: birincil `h-9 px-3.5 rounded-md bg-accent text-accent-fg hover:brightness-110 text-[13.5px] font-medium`; ikincil `border border-line bg-surface hover:bg-surface-2`; küçük `h-8 px-3 text-[13px]`; tehlikeli `bg-crit text-on-crit`; müdahale `bg-fg text-bg`.
- Rozet: `inline-flex items-center gap-1.5 text-2xs font-medium px-2 py-0.5 rounded-full bg-ok-soft text-ok` + `<i class="w-1.5 h-1.5 rounded-full bg-ok"></i>`.
- Uyarı satırı: sol kenarda `absolute left-0 inset-y-0 w-1 bg-warn` şerit + ikon + kalın başlık + sayılı açıklama + en fazla iki eylem.
- Sekmeler (sayfa içi): alt çizgili sekme (ds-body.html "Sekmeler ve tablo" örneği). Sekmeler gerçekten çalışsın (küçük JS ile panel değiştir), varsayılan sekme dolu olsun.
- Tablolar: `<div class="relative overflow-x-auto scroll-thin">` içinde, tabloya `min-w-[...]`; hücrelerde `whitespace-nowrap` gerektiğinde. Grid/flex çocuklarına `min-w-0`.
- Sayfanın üstü: başlık + bir satır bağlam (`text-[13px] text-muted`) solda, ana eylem(ler) sağda. Varsa önce "dikkat gerektiren" şey, sonra özet, sonra ayrıntı.
- Telefon genişliğinde (400px) yatay kaydırma OLMAMALI; ızgaralar tek sütuna insin.
- Emoji, gradyan, dekoratif süs yok. Boş durum gerekiyorsa ds-body.html örneği.
- Metin: düğme ne yapacağını söyler ("Kaydı ekle", "Yedeği geri yükle"), hata nasıl düzeltileceğini söyler. Kullanıcının tanıdığı ad önce, teknik ad yanında mono. Geri dönüşü olmayan eylemler (silme, geri yükleme) onay penceresi ister; geri alınabilenler toast'ta "Geri al" verir.
- AI: sayfaya özgü bir "Servik AI" önerisi/özeti en fazla bir yerde, `bg-accent-soft/60` zeminli, sparkles ikonlu. AI'ın onaysız yapabildiği tek şey IP engelleme ve geçici kaynak sınırı; diğer her öneri "Onayla / Vazgeç" kartıyla.

## Ortak örnek veri evreni (tutarlı kullan)
- Sunucu `srv-ist-01`, Ubuntu 24.04.1 LTS, 8 çekirdek, 32 GB, 480 GB NVMe, IPv4 `185.98.142.21`, IPv6 `2a0e:1c80:4:2a::21`. Servik 1.4.1 kurulu, 1.4.2 hazır. Yönetici: Sercan Yılmaz. Diğer kullanıcı: Ayşe Kaya (müşteri yetkilisi).
- 24 site. Önemli olanlar: magazaonline.com.tr (WordPress/Woo, şu an saldırı altında, PHP havuzu 12/12), ozkanlojistik.com + panel.ozkanlojistik.com (Laravel 12, kuyruğu takılı SendInvoiceJob), api.hizlikargo.app (Laravel 12, sağlıklı, sürüm #148 8f3c2a1), staging.hizlikargo.app (son dağıtım migrate adımında başarısız), bulten.atlasyayin.com (Laravel 11, toplu posta 1.840/sa), kahveatolyesi.com.tr (WooCommerce) ve eski.kahveatolyesi.com.tr (SSL 6 gün sonra bitiyor, A kaydı başka sunucuyu gösteriyor 104.21.8.17), klinikmavi.com (statik + PHP 8.4), atlasyayin.com, hizlikargo.app.
- Müşteriler: Mağaza Online, Özkan Lojistik, Hızlı Kargo A.Ş., Atlas Yayıncılık, Kahve Atölyesi, Klinik Mavi (+ birkaç küçük). Paketler: Başlangıç, Profesyonel, Laravel Pro, Kurumsal.
- Saldırgan IP'ler: 45.155.205.233, 193.42.33.117, 103.153.79.6; AI'ın otomatik engellediği 185.220.101.47 (23:41). Saat şu an ~23:50.
- Servis yığını: Nginx 1.26.2, PHP-FPM 8.3/8.4/8.5, MySQL 8.4.3 (müşteri), PostgreSQL 17.2 (panel), Redis 7.4.1, PowerDNS 4.9.2, Postfix 3.8.6, Dovecot 2.3.21, Rspamd 3.10, Roundcube, Let's Encrypt, nftables + fail2ban + ModSecurity/Coraza WAF, cgroup v2 site başına sınır.

## 2. TUR: SADE İÇ SAYFA KURALLARI (yukarıdakilerle çelişirse BUNLAR geçerli)
Sercan'ın geri bildirimi: "İç sayfalar aşırı karışık, Plesk ve cPanel'i örnek al." İlk turdaki sayfalar (pages-v1/) çok yoğundu. Yeni kalıp Plesk ve cPanel'in araç sayfaları gibi: her sayfa TEK iş yapar.
Referans sayfalar: `pages/eposta.html` (liste sayfası kalıbı) ve `pages/site-detay.html` (cPanel tarzı araç ızgarası). Bunları birebir kalıp olarak al.

Sayfa yapısı, sırayla:
1. Başlık (h1) + bir satır sade açıklama ("Posta kutularını ... yönetin."). Sağda en fazla bir seçici (alan adı/site/uygulama) ya da 1-2 düğme.
2. Tek bir bölüm kartı. İçinde en fazla 4 sekme (gerekmiyorsa sekme yok).
3. Sekmenin içinde: araç çubuğu (birincil "X oluştur/ekle" + seçime bağlı "Sil" gibi 1-2 ikincil düğme + sağda arama) ve ALTINDA TEK tablo. Tablo en fazla 5-6 sütun. Satır başına en fazla bir satır içi eylem ("Sil", "Düzenle").
4. Ayar sekmeleri Plesk formu gibi: solda etiket (220px), sağda alan, satırlar `divide-y`, en altta Kaydet / Vazgeç. Az kullanılan ayarlar `<details>` "Gelişmiş" altında kapalı.
5. Oluşturma/düzenleme: küçük modal form (3-5 alan). Silme ve geri dönüşü olmayan işler: onay modalı.

YASAK (iç sayfalarda): metrik kutuları/sparkline şeritleri, "dikkat gerektirenler" listesi, AI öneri kartları, yan yana birden fazla bölüm kartı, sayfa başına birden fazla tablo aynı anda görünür, yan panelde grafikler, süs açıklamalar, ilk turdaki gibi her şeyi tek ekrana koymak. Bir sorun gerçekten o sayfanın nesnesiyle ilgiliyse tablo satırında rozet olarak göster (ör. SSL satırında "6 gün kaldı" uyarı rozeti), ayrı uyarı bloğu açma. En fazla bir satırlık bilgi şeridi istisna olabilir.
AI yalnızca üst çubuktaki Servik AI çekmecesinde ve kendi sayfasında yaşar.
Türkçe adlandırma cPanel/Plesk kullanıcısının bildiği gibi: "Posta Kutuları", "DNS Kayıtları", "Zamanlanmış Görevler", "Veritabanı Kullanıcıları".
Hedef: sayfa dosyası 20 KB altında; ekranda ilk bakışta tek bir tablo ya da tek bir form.
Gizlemek için `hidden` özniteliği kullan (CSS'te `[hidden]{display:none!important}` var).
- Birden çok nesneyi yöneten bölümler (DNS, siteler vb.) önce nesne LİSTESİ, satıra tıklayınca o nesnenin DETAY sayfası (Plesk gibi). Detay sayfasında konum satırı (breadcrumb) ve "listeye dön". Sercan DNS ve Laravel için bunu açıkça istedi; E-posta ve Dosya Yöneticisi de böyle. Liste sayfası üretmek için mklist.py.

## Özgünlük kuralı (Sercan, 2026-10-18)
Plesk ve cPanel'in işleyişinden (liste → detay, site içi araçlar, site bazında web sunucusu seçimi) ilham alınır, ama görünüm birebir kopyalanmaz: onların sekme adları, menü/araç adları ve sıraları, ikonları, renkleri, sayfa yerleşimleri ve açıklama metinleri aynen alınmaz. Servik kendi düzenini, adlarını ve metinlerini kullanır. Hukuki görüş değildir; yayın öncesi bir avukata danışılmalı.
