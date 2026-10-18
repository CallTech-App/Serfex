# Laravel: v1 kapsamı ile tasarım karşılaştırması

Kaynak: "Web Hosting Paneli: Mimari ve v1 Kapsamı" belgesindeki "v1 kapsamı: Laravel eklentisi" tablosu. Tarih: 18 Ekim 2026.

| Kapsam maddesi | Önceki tasarımda | Şimdi nerede |
|---|---|---|
| Kurulum: boş proje, Git'ten kurulum, dağıtım anahtarı | Yalnızca depo adresi ve dal | Liste > Laravel uygulaması kur: yeni proje / Git deposu, dağıtım anahtarı notu |
| Başlangıç kiti (React, Vue, Livewire), kurulumda npm install + build | Yoktu | Kurulum penceresi: başlangıç kiti, ön yüz derleme, paket yöneticisi |
| GitHub / GitLab / Bitbucket bağlantısıyla depo seçme | Yoktu | Kurulum penceresi: sağlayıcı bağlantıları ve depo listesi |
| Mevcut uygulamayı bulma, site kökünü public/ yapma, PHP uyumsuzluk uyarısı | Yoktu | Liste > Mevcut uygulamaları bul; listede composer.lock uyarısı; Genel bakış'ta uyumluluk |
| Sıfır kesintili sürümlü dağıtım, geçmiş, geri dönme | Vardı (sade) | Dağıtımlar: sürüm tablosu, süre, Bu sürüme dön |
| Canlı dağıtım günlüğü | Yoktu | Dağıt > canlı akan günlük; her sürümde Günlük |
| Dal veya etiket seçerek dağıtma | Yoktu | Dağıt penceresi: dal/etiket |
| Kancalar (öncesi/sonrası), SSH olmadan betik düzenleme | Tek betik | Dağıtımlar > Dağıtım adımları ve kancalar (3 aşama) |
| Sağlık kontrolü başarısızsa otomatik geri dönme | Yoktu | Dağıtımlar > Güvenlik ağı; #144 örneği |
| Dağıtım öncesi otomatik veritabanı yedeği | Yoktu | Dağıtımlar > Güvenlik ağı; Yedekler tablosu |
| Webhook ile otomatik dağıtım | Vardı | Dağıtımlar: webhook adresi ve kopyala |
| PR başına önizleme ortamı | Yoktu | Önizleme ortamları |
| .env: maskeleme | Vardı | Ortam (.env) |
| .env: .env.example ile fark, değişiklik geçmişi, kaydedince config:cache | Yalnızca config:cache | Ortam (.env): Karşılaştır, Geçmiş |
| Artisan, Composer, npm/yarn/pnpm/bun, canlı çıktı, kayıtlı komutlar | Yalnızca Artisan | Komutlar: Artisan, Composer, Node.js, kayıtlı komutlar |
| Tarayıcıda tinker | Yoktu | Komutlar > Tinker |
| Bakım modu: atlama bağlantısı (--secret), özel 503 sayfası, IP izin listesi | Yoktu | Bakım modu |
| Zamanlayıcı: aç/kapat, son çalışma, süre, çıktı, şimdi çalıştır, kaçırılan çalışma uyarısı | Kısmen | Zamanlayıcı |
| Kuyruklar: systemd işçileri, işçi sayısı, işçi ayarları, Redis/database/SQS, başarısız işler | Kısmen | Kuyruklar (+ / − ile ölçekleme, Ayarlar, Başarısız işler: yeniden dene, sil, temizle) |
| Horizon servis olarak, panelden kimlikli erişim | Yoktu | Süreçler |
| Octane (FrankenPHP, Swoole, RoadRunner), otomatik Nginx vekili | Yoktu | Süreçler |
| Reverb ve WebSocket vekili | Yoktu | Süreçler |
| Günlükler: daily, düzey filtresi, arama, canlı takip, hata uyarısı | Yoktu | Günlükler |
| Sağlık (/up), Pulse, uygulama başına CPU/RAM | Yoktu | Genel bakış |
| Uygulamayı tek başına yedekleme ve geri yükleme | Yoktu | Yedekler |
| Uygulama başına rol: sahip, geliştirici, salt okunur | Yoktu | Erişim ve API |
| REST API, CLI, CI'dan dağıtım | Yoktu | Erişim ve API |
| Composer paketi zorunlu değil | — | Kuyruklar sayfasında not |
