<!-- Bu dosya üretilir: cd cli && go test ./internal/app -run TestReferenceIsCurrent -update -->

# Komut satırı başvurusu

Servik komut satırı: siteler, alan adları, DNS, SSL, e-posta, veritabanları, yedekler, hizmetler ve Laravel uygulamaları.

Önce bir panele giriş yapın: servik login panel.ornek.com.tr:8443

Her komut `--help` ile kendi yardımını da gösterir: `servik sites create --help`.

## Genel seçenekler

Bu seçenekler her komutta geçerlidir.

| Seçenek | |
| --- | --- |
| `--profile <ad>` | Kullanılacak profil (varsayılan: son giriş yapılan; SERVIK_PROFILE) |
| `--json` | Çıktıyı JSON olarak yazar |
| `--lang <tr\|en>` | Dil (varsayılan: sistem dili; SERVIK_LANG) |
| `--url <adres>` | Panel adresi (SERVIK_URL) |
| `--token <anahtar>` | API anahtarı ya da uygulama belirteci (SERVIK_TOKEN) |
| `-y, --yes` | Onay sormadan yapar |
| `--no-color` | Renksiz çıktı (NO_COLOR) |
| `-h, --help` | Yardımı gösterir |

## Hesap

### servik login

```
servik login [panel-adresi] [seçenekler]
```

Bir Servik paneline giriş yapar. Tarayıcıda bir onay sayfası açılır; onayladığınızda bu bilgisayar için bir anahtar oluşur ve Hesap güvenliği altında listelenir.

CI ya da betikler için --token ile bir API anahtarı ya da bir uygulamanın Erişim ve API bölümündeki belirteci verin. Sunucunun kendisinde root olarak --local tarayıcısız giriş yapar.

| Seçenek | |
| --- | --- |
| `--local` | Sunucuda root olarak, tarayıcısız giriş |
| `--email <e-posta>` | --local: anahtarın ait olacağı yönetici (varsayılan: ilk tam yetkili yönetici) |
| `--read-only` | --local: yalnızca okuyabilen anahtar |
| `--fingerprint <sha256>` | Panelin sertifikasının beklenen SHA-256 parmak izi |
| `--no-browser` | Tarayıcıyı açmaz; bağlantıyı yalnızca yazar |

Örnekler:

```sh
servik login panel.ornek.com.tr:8443
servik login https://panel.ornek.com.tr:8443 --token svk_…
sudo servik login --local
```

### servik logout

```
servik logout
```

Profilden çıkar ve anahtarını panelde kapatır

### servik profile

```
servik profile <komut>
```

Giriş yapılan panelleri listeler ve aralarında geçer

Diğer adları: `profiles`

| Komut | |
| --- | --- |
| [`list`](#servik-profile-list) | Profilleri listeler |
| [`use`](#servik-profile-use) | Varsayılan profili değiştirir |
| [`remove`](#servik-profile-remove) | Profili siler ve anahtarını panelde kapatır |

### servik profile list

```
servik profile list
```

Profilleri listeler

Diğer adları: `ls`

### servik profile use

```
servik profile use <ad>
```

Varsayılan profili değiştirir

### servik profile remove

```
servik profile remove <ad>
```

Profili siler ve anahtarını panelde kapatır

Diğer adları: `rm`

### servik whoami

```
servik whoami
```

Kim olarak, hangi panele bağlı olduğunuzu gösterir

### servik status

```
servik status
```

Sunucunun durumunu ve dikkat isteyen sorunları gösterir

### servik fingerprint

```
servik fingerprint [panel-adresi]
```

Panelin gösterdiği sertifikanın SHA-256 parmak izini yazar. Sunucuda adres vermeden çalıştırın ve çıkan değeri ilk girişte bilgisayarınızda görünenle karşılaştırın.

## Barındırma

### servik sites

```
servik sites <komut>
```

Siteleri listeler, oluşturur ve siler

Diğer adları: `site`

| Komut | |
| --- | --- |
| [`list`](#servik-sites-list) | Siteleri listeler |
| [`show`](#servik-sites-show) | Bir sitenin ayrıntılarını gösterir |
| [`create`](#servik-sites-create) | Yeni bir site oluşturur |
| [`delete`](#servik-sites-delete) | Siteyi dosyaları, veritabanları ve e-postasıyla siler |

### servik sites list

```
servik sites list
```

Siteleri listeler

Diğer adları: `ls`

### servik sites show

```
servik sites show <site>
```

Bir sitenin ayrıntılarını gösterir

### servik sites create

```
servik sites create <alan-adı> [seçenekler]
```

Yeni bir site oluşturur: sistem kullanıcısı, web klasörü, web sunucusu ayarı ve DNS bölgesi, paneldeki gibi.

Diğer adları: `add`

| Seçenek | |
| --- | --- |
| `--php <sürüm>` | PHP sürümü (varsayılan: sunucunun varsayılanı) |
| `--www` | www. adı da açılır (varsayılan: evet; kapatmak için --no-www) |
| `--customer <id>` | Sitenin ait olacağı müşteri (yöneticiler) |
| `--account <ad>` | Sistem kullanıcısının adı (varsayılan: alan adından) |
| `--wait` | Site hazır olana kadar bekler |

Örnekler:

```sh
servik sites create kahveatolyesi.com.tr --php 8.4 --wait
```

### servik sites delete

```
servik sites delete <site> [seçenekler]
```

Siteyi dosyaları, veritabanları ve e-postasıyla siler

Diğer adları: `rm`

| Seçenek | |
| --- | --- |
| `--keep-dns` | DNS bölgesini bırakır |

### servik domains

```
servik domains <komut>
```

Alt alan adları, ek alan adları ve yönlendirmeler

Diğer adları: `domain`

| Komut | |
| --- | --- |
| [`list`](#servik-domains-list) | Bir sitenin diğer adlarını listeler |
| [`add`](#servik-domains-add) | Alt alan adı (blog) ya da ek alan adı (ornek.net) ekler |
| [`redirect`](#servik-domains-redirect) | Bir yolu (/eski) ya da alan adını başka bir adrese yönlendirir |
| [`remove`](#servik-domains-remove) | Alt alan adını, ek alan adını ya da yönlendirmeyi kaldırır |

### servik domains list

```
servik domains list <site>
```

Bir sitenin diğer adlarını listeler

Diğer adları: `ls`

### servik domains add

```
servik domains add <site> <ad> [seçenekler]
```

Bir siteye ad ekler. Noktasız ya da sitenin altındaki bir ad (blog, blog.ornek.com.tr) kendi klasörü olan bir alt alan adıdır; başka bir alan adı (ornek.net) aynı siteyi açan ek alan adıdır. DNS ve Let's Encrypt kendiliğinden ayarlanır.

| Seçenek | |
| --- | --- |
| `--root <klasör>` | Alt alan adının klasörü, sitenin klasörüne göre (varsayılan: subdomains/<ad>) |
| `--dns` | Ek alan adı için DNS bölgesi oluşturur (varsayılan: evet) |

Örnekler:

```sh
servik domains add ornek.com.tr blog
servik domains add ornek.com.tr ornek.net --no-dns
```

### servik domains redirect

```
servik domains redirect <site> <kaynak> <hedef> [seçenekler]
```

Bir yolu (/eski) ya da alan adını başka bir adrese yönlendirir

| Seçenek | |
| --- | --- |
| `--code <301\|302>` | Yönlendirme kodu (varsayılan: 301) |
| `--keep-path` | Alan adı yönlendirmesinde yolu korur |
| `--www` | Alan adı yönlendirmesine www. adını da ekler |
| `--dns` | Yönlendirilen alan adı için DNS bölgesi oluşturur (varsayılan: evet) |

Örnekler:

```sh
servik domains redirect ornek.com.tr /eski https://ornek.com.tr/yeni
servik domains redirect ornek.com.tr eskiad.com https://ornek.com.tr --keep-path
```

### servik domains remove

```
servik domains remove <site> <ad>
```

Alt alan adını, ek alan adını ya da yönlendirmeyi kaldırır

Diğer adları: `rm`

### servik dns

```
servik dns <komut>
```

DNS bölgeleri ve kayıtları

| Komut | |
| --- | --- |
| [`zones`](#servik-dns-zones) | DNS bölgelerini listeler |
| [`records`](#servik-dns-records) | Bir bölgenin kayıtlarını listeler |
| [`add`](#servik-dns-add) | Kayıt ekler (ad: @ bölgenin kendisi) |
| [`delete`](#servik-dns-delete) | Kaydı siler (numarası: servik dns records) |

### servik dns zones

```
servik dns zones
```

DNS bölgelerini listeler

Diğer adları: `list`, `ls`

### servik dns records

```
servik dns records <bölge>
```

Bir bölgenin kayıtlarını listeler

Diğer adları: `show`

### servik dns add

```
servik dns add <bölge> <tür> <ad> <değer> [seçenekler]
```

Kayıt ekler (ad: @ bölgenin kendisi)

| Seçenek | |
| --- | --- |
| `--ttl <saniye>` | Kaydın TTL'i (varsayılan: bölgeninki) |
| `--priority <sayı>` | MX ve SRV önceliği (varsayılan: 10) |

Örnekler:

```sh
servik dns add ornek.com.tr A www 203.0.113.10
servik dns add ornek.com.tr MX @ mail.ornek.com.tr --priority 10
servik dns add ornek.com.tr TXT @ "v=spf1 mx -all"
```

### servik dns delete

```
servik dns delete <bölge> <kayıt-no>
```

Kaydı siler (numarası: servik dns records)

Diğer adları: `rm`

### servik ssl

```
servik ssl <komut>
```

Sitelerin sertifikaları ve HTTPS

| Komut | |
| --- | --- |
| [`list`](#servik-ssl-list) | Sitelerin sertifikalarını listeler |
| [`show`](#servik-ssl-show) | Bir sitenin sertifikasını gösterir |
| [`issue`](#servik-ssl-issue) | Let's Encrypt sertifikası alır |
| [`renew`](#servik-ssl-renew) | Sertifikayı şimdi yeniler |
| [`https`](#servik-ssl-https) | HTTP isteklerini HTTPS'e yönlendirmeyi açar ya da kapatır |

### servik ssl list

```
servik ssl list
```

Sitelerin sertifikalarını listeler

Diğer adları: `ls`

### servik ssl show

```
servik ssl show <site>
```

Bir sitenin sertifikasını gösterir

### servik ssl issue

```
servik ssl issue <site> [seçenekler]
```

Let's Encrypt sertifikası alır

| Seçenek | |
| --- | --- |
| `--wildcard` | *.alan-adı için de (DNS bu sunucudaysa) |
| `--only-domain` | www. ve ek alan adları olmadan |
| `--wait` | Sertifika alınana kadar bekler |

### servik ssl renew

```
servik ssl renew <site> [seçenekler]
```

Sertifikayı şimdi yeniler

| Seçenek | |
| --- | --- |
| `--wait` | Sertifika yenilenene kadar bekler |

### servik ssl https

```
servik ssl https <site> on|off
```

HTTP isteklerini HTTPS'e yönlendirmeyi açar ya da kapatır

### servik mail

```
servik mail <komut>
```

E-posta alan adları, posta kutuları ve yönlendiriciler

| Komut | |
| --- | --- |
| [`domains`](#servik-mail-domains) | E-postası açık alan adlarını listeler |
| [`enable`](#servik-mail-enable) | Bir alan adında e-postayı açar (DNS kayıtları kendiliğinden yazılır) |
| [`list`](#servik-mail-list) | Posta kutularını ve yönlendiricileri listeler |
| [`create`](#servik-mail-create) | Posta kutusu oluşturur |
| [`password`](#servik-mail-password) | Posta kutusunun parolasını değiştirir |
| [`quota`](#servik-mail-quota) | Posta kutusunun kotasını değiştirir (0 sınırsız) |
| [`delete`](#servik-mail-delete) | Posta kutusunu siler |
| [`forward`](#servik-mail-forward) | Bir adresi başka adreslere yönlendirir (*@alan-adı: tümünü yakala) |
| [`unforward`](#servik-mail-unforward) | Yönlendiriciyi siler |

### servik mail domains

```
servik mail domains
```

E-postası açık alan adlarını listeler

### servik mail enable

```
servik mail enable <alan-adı>
```

Bir alan adında e-postayı açar (DNS kayıtları kendiliğinden yazılır)

### servik mail list

```
servik mail list <alan-adı>
```

Posta kutularını ve yönlendiricileri listeler

Diğer adları: `ls`

### servik mail create

```
servik mail create <adres> [seçenekler]
```

Posta kutusu oluşturur

Diğer adları: `add`

| Seçenek | |
| --- | --- |
| `--password-stdin` | Parolayı standart girdiden okur (verilmezse panel güçlü bir parola oluşturur ve bir kez gösterir) |
| `--quota <MB>` | Kota, MB (varsayılan: sunucunun varsayılanı; 0 sınırsız) |

Örnekler:

```sh
servik mail create siparis@ornek.com.tr
printf '%s' "$PAROLA" | servik mail create info@ornek.com.tr --password-stdin
```

### servik mail password

```
servik mail password <adres> [seçenekler]
```

Posta kutusunun parolasını değiştirir

| Seçenek | |
| --- | --- |
| `--password-stdin` | Parolayı standart girdiden okur (verilmezse panel güçlü bir parola oluşturur ve bir kez gösterir) |

### servik mail quota

```
servik mail quota <adres> <MB>
```

Posta kutusunun kotasını değiştirir (0 sınırsız)

### servik mail delete

```
servik mail delete <adres>
```

Posta kutusunu siler

Diğer adları: `rm`

### servik mail forward

```
servik mail forward <adres> <hedef>…
```

Bir adresi başka adreslere yönlendirir (*@alan-adı: tümünü yakala)

Örnekler:

```sh
servik mail forward satis@ornek.com.tr ali@ornek.com.tr ayse@gmail.com
servik mail forward '*@ornek.com.tr' info@ornek.com.tr
```

### servik mail unforward

```
servik mail unforward <adres>
```

Yönlendiriciyi siler

### servik db

```
servik db <komut>
```

Sitelerin veritabanları

Diğer adları: `databases`, `database`

| Komut | |
| --- | --- |
| [`list`](#servik-db-list) | Veritabanlarını listeler |
| [`create`](#servik-db-create) | Veritabanı ve kullanıcısını oluşturur |
| [`delete`](#servik-db-delete) | Veritabanını siler (önce yedeğini alır) |

### servik db list

```
servik db list [seçenekler]
```

Veritabanlarını listeler

Diğer adları: `ls`

| Seçenek | |
| --- | --- |
| `--site <site>` | Yalnızca bu sitenin veritabanları |

### servik db create

```
servik db create <site> <ad> [seçenekler]
```

Veritabanı ve aynı adlı bir kullanıcı oluşturur. Adlara sitenin ön eki (kahve_) kendiliğinden eklenir; parola verilmezse panel güçlü bir parola oluşturur ve bağlantı bilgileriyle bir kez gösterir.

Diğer adları: `add`

| Seçenek | |
| --- | --- |
| `--user <ad>` | Kullanıcının adı (varsayılan: veritabanınınki) |
| `--no-user` | Kullanıcı oluşturmaz |
| `--password-stdin` | Kullanıcının parolasını standart girdiden okur |
| `--access <all\|read>` | Kullanıcının yetkisi (varsayılan: all) |
| `--server <mysql\|postgresql>` | Veritabanı sunucusu (varsayılan: kurulu olan ilki) |

Örnekler:

```sh
servik db create kahveatolyesi.com.tr dukkan
```

### servik db delete

```
servik db delete <ad> [seçenekler]
```

Veritabanını siler (önce yedeğini alır)

Diğer adları: `rm`

| Seçenek | |
| --- | --- |
| `--no-backup` | Silmeden önce yedek almaz |

### servik backups

```
servik backups <komut>
```

Yedekler ve geri yükleme

Diğer adları: `backup`

| Komut | |
| --- | --- |
| [`list`](#servik-backups-list) | Yedekleri listeler, en yenisi önce |
| [`show`](#servik-backups-show) | Bir yedeğin içindekileri gösterir |
| [`create`](#servik-backups-create) | Şimdi yedek alır: bir siteyi ya da (yöneticiler) tüm sunucuyu |
| [`restore`](#servik-backups-restore) | Bir siteyi yedekten geri yükler |

### servik backups list

```
servik backups list [seçenekler]
```

Yedekleri listeler, en yenisi önce

Diğer adları: `ls`

| Seçenek | |
| --- | --- |
| `--site <site>` | Yalnızca bu sitenin bulunduğu yedekler |
| `-n, --limit <sayı>` | En fazla kaç yedek (varsayılan: 20) |

### servik backups show

```
servik backups show <no>
```

Bir yedeğin içindekileri gösterir

### servik backups create

```
servik backups create [site] [seçenekler]
```

Şimdi yedek alır: bir siteyi ya da (yöneticiler) tüm sunucuyu

| Seçenek | |
| --- | --- |
| `--wait` | Yedek bitene kadar bekler |

### servik backups restore

```
servik backups restore <no> <site> [seçenekler]
```

Bir siteyi yedekten geri yükler. Önce sitenin şimdiki hâli yedeklenir, böylece geri yükleme de geri alınabilir.

| Seçenek | |
| --- | --- |
| `--only <parça>` | Yalnızca bunlar: files, databases, mail, dns, certificates (virgülle) |
| `--no-safety` | Önce güvenlik yedeği almaz |
| `--wait` | Geri yükleme bitene kadar bekler |

Örnekler:

```sh
servik backups restore 42 kahveatolyesi.com.tr --only files,databases --wait
```

## Sunucu

### servik services

```
servik services <komut>
```

Sunucunun hizmetleri (yöneticiler)

Diğer adları: `service`

| Komut | |
| --- | --- |
| [`list`](#servik-services-list) | Hizmetleri listeler |
| [`status`](#servik-services-status) | Bir hizmetin durumunu gösterir |
| [`start`](#servik-services-start) | Hizmeti başlatır |
| [`stop`](#servik-services-stop) | Hizmeti durdurur |
| [`restart`](#servik-services-restart) | Ayarını denetleyip hizmeti yeniden başlatır |
| [`logs`](#servik-services-logs) | Hizmetin günlüğünün son satırları |

### servik services list

```
servik services list
```

Hizmetleri listeler

Diğer adları: `ls`

### servik services status

```
servik services status <hizmet>
```

Bir hizmetin durumunu gösterir

Diğer adları: `show`

### servik services start

```
servik services start <hizmet>
```

Hizmeti başlatır

### servik services stop

```
servik services stop <hizmet>
```

Hizmeti durdurur

### servik services restart

```
servik services restart <hizmet>
```

Ayarını denetleyip hizmeti yeniden başlatır

### servik services logs

```
servik services logs <hizmet> [seçenekler]
```

Hizmetin günlüğünün son satırları

| Seçenek | |
| --- | --- |
| `-n, --lines <sayı>` | Kaç satır (varsayılan: 50) |

### servik customers

```
servik customers <komut>
```

Müşteriler ve paketleri (yöneticiler)

Diğer adları: `customer`

| Komut | |
| --- | --- |
| [`list`](#servik-customers-list) | Müşterileri listeler |
| [`show`](#servik-customers-show) | Müşterinin siteleri ve paket kullanımı |
| [`suspend`](#servik-customers-suspend) | Müşteriyi askıya alır (siteleri 503, postaları bekletilir) |
| [`unsuspend`](#servik-customers-unsuspend) | Askıyı kaldırır |
| [`packages`](#servik-customers-packages) | Paketleri listeler |

### servik customers list

```
servik customers list
```

Müşterileri listeler

Diğer adları: `ls`

### servik customers show

```
servik customers show <no>
```

Müşterinin siteleri ve paket kullanımı

### servik customers suspend

```
servik customers suspend <no> [seçenekler]
```

Müşteriyi askıya alır (siteleri 503, postaları bekletilir)

| Seçenek | |
| --- | --- |
| `--reason <metin>` | Müşterinin göreceği neden |

### servik customers unsuspend

```
servik customers unsuspend <no>
```

Askıyı kaldırır

### servik customers packages

```
servik customers packages
```

Paketleri listeler

## Laravel

### servik apps

```
servik apps
```

Erişebildiğiniz Laravel uygulamalarını listeler

### servik deploy

```
servik deploy [seçenekler]
```

Uygulamayı deposundan dağıtır: yeni sürüm releases/ altına kurulur, sağlık kontrolünden geçince yayına girer, geçemezse önceki sürüm yayında kalır.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `--ref <dal\|etiket\|commit>` | Dağıtılacak dal, etiket ya da commit (varsayılan: uygulamanın dalı) |
| `--no-migrate` | Bu dağıtımda migrate çalıştırmaz |
| `--no-frontend` | Bu dağıtımda ön yüzü (npm run build) derlemez |
| `--wait` | Bitene kadar çıktısını gösterir; başarısız olursa sıfırdan farklı kodla çıkar |
| `--timeout <süre>` | --wait için en uzun bekleme (varsayılan: 60m) |

Örnekler:

```sh
servik deploy -a api.ornek.com.tr --wait
SERVIK_URL=https://panel.ornek.com.tr:8443 SERVIK_TOKEN=svk_… servik deploy --ref "$GITHUB_SHA" --wait
```

### servik deployments

```
servik deployments [seçenekler]
```

Son dağıtımları listeler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `-n, --limit <sayı>` | Kaç dağıtım (varsayılan: 10) |

### servik rollback

```
servik rollback [seçenekler]
```

Önceki sürüme döner

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `--to <no>` | Dönülecek dağıtımın numarası (varsayılan: bir önceki) |
| `--wait` | Bitene kadar çıktısını gösterir; başarısız olursa sıfırdan farklı kodla çıkar |
| `--timeout <süre>` | --wait için en uzun bekleme (varsayılan: 60m) |

### servik preview

```
servik preview <komut>
```

Önizleme ortamları (<dal>.alan-adı)

Diğer adları: `previews`

| Komut | |
| --- | --- |
| [`create`](#servik-preview-create) | Bir dal için önizleme ortamı açar |
| [`delete`](#servik-preview-delete) | Önizleme ortamını kaldırır |

### servik preview create

```
servik preview create <dal> [seçenekler]
```

Bir dal için önizleme ortamı açar

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik preview delete

```
servik preview delete <ad> [seçenekler]
```

Önizleme ortamını kaldırır

Diğer adları: `rm`

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik env

```
servik env [seçenekler]
```

Uygulamanın .env dosyasını gösterir ve değiştirir. Kaydedilince config:cache yenilenir, işçiler yeniden başlar; panel her sürümü saklar.

| Komut | |
| --- | --- |
| [`list`](#servik-env-list) | Anahtarları ve değerlerini listeler (gizliler maskeli) |
| [`get`](#servik-env-get) | Bir değeri olduğu gibi yazar |
| [`set`](#servik-env-set) | Değerleri değiştirir ya da ekler |
| [`unset`](#servik-env-unset) | Anahtarları siler |
| [`pull`](#servik-env-pull) | .env dosyasını indirir |
| [`push`](#servik-env-push) | .env dosyasının tamamını değiştirir |

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `--reveal` | Parola ve anahtarları gizlemeden gösterir |

### servik env list

```
servik env list [seçenekler]
```

Anahtarları ve değerlerini listeler (gizliler maskeli)

Diğer adları: `ls`

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `--reveal` | Parola ve anahtarları gizlemeden gösterir |

### servik env get

```
servik env get <ANAHTAR> [seçenekler]
```

Bir değeri olduğu gibi yazar

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik env set

```
servik env set <ANAHTAR=değer>… [seçenekler]
```

Değerleri değiştirir ya da ekler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

Örnekler:

```sh
servik env set APP_DEBUG=false MAIL_FROM_NAME="Kargo"
```

### servik env unset

```
servik env unset <ANAHTAR>… [seçenekler]
```

Anahtarları siler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik env pull

```
servik env pull [seçenekler]
```

.env dosyasını indirir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `-o, --output <dosya>` | Yazılacak dosya (varsayılan: standart çıktı) |

### servik env push

```
servik env push <dosya> [seçenekler]
```

.env dosyasının tamamını değiştirir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik artisan

```
servik artisan <komut>… [seçenekler]
```

artisan komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. servik seçenekleri (-a) komuttan önce gelir.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik composer

```
servik composer <komut>… [seçenekler]
```

composer komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. servik seçenekleri (-a) komuttan önce gelir.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik npm

```
servik npm <komut>… [seçenekler]
```

npm komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. servik seçenekleri (-a) komuttan önce gelir.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik queues

```
servik queues [seçenekler]
```

Kuyruk işçileri ve bekleyen işler

Diğer adları: `queue`

| Komut | |
| --- | --- |
| [`status`](#servik-queues-status) | İşçileri ve bekleyen işleri gösterir |
| [`failed`](#servik-queues-failed) | Başarısız işleri listeler |
| [`retry`](#servik-queues-retry) | Başarısız bir işi ya da hepsini yeniden kuyruğa alır |
| [`restart`](#servik-queues-restart) | İşçileri yeniden başlatır |

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik queues status

```
servik queues status [seçenekler]
```

İşçileri ve bekleyen işleri gösterir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik queues failed

```
servik queues failed [seçenekler]
```

Başarısız işleri listeler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik queues retry

```
servik queues retry [iş-no] [seçenekler]
```

Başarısız bir işi ya da hepsini yeniden kuyruğa alır

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik queues restart

```
servik queues restart [seçenekler]
```

İşçileri yeniden başlatır

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik maintenance

```
servik maintenance [seçenekler]
```

Bakım modu

Diğer adları: `down`

| Komut | |
| --- | --- |
| [`status`](#servik-maintenance-status) | Bakım modunda olup olmadığını gösterir |
| [`on`](#servik-maintenance-on) | Bakım modunu açar |
| [`off`](#servik-maintenance-off) | Bakım modunu kapatır |

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik maintenance status

```
servik maintenance status [seçenekler]
```

Bakım modunda olup olmadığını gösterir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik maintenance on

```
servik maintenance on [seçenekler]
```

Bakım modunu açar

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `--mode <servik\|custom\|app>` | Servik sayfası (varsayılan), kendi HTML'iniz ya da php artisan down |
| `--allow <ip>` | Bakımdayken siteyi görebilecek IP ya da ağ (birden çok kez) |
| `--secret <yol>` | Bakımı atlatan gizli yol (varsayılan: rastgele) |
| `--retry <saniye>` | Retry-After başlığı |
| `--html-file <dosya>` | --mode custom için HTML dosyası |

### servik maintenance off

```
servik maintenance off [seçenekler]
```

Bakım modunu kapatır

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |

### servik logs

```
servik logs [seçenekler]
```

Uygulamanın günlükleri (storage/logs)

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERVIK_APP; bir belirteçle girişte gerekmez) |
| `--level <düzey>` | Bu düzey ve üstü: debug, info, warning, error, critical |
| `--file <dosya>` | Günlük dosyası (varsayılan: en yenisi) |
| `--search <metin>` | İçinde bu metin geçenler |
| `-n, --lines <sayı>` | Kaç kayıt (varsayılan: 50) |
| `-f, --follow` | Yeni kayıtları geldikçe yazar |
| `--detail` | Hata ayrıntılarını (stack trace) da yazar |

Örnekler:

```sh
servik logs -a api.ornek.com.tr --level error -n 20
servik logs -f
```

## Diğer

### servik completion

```
servik completion bash|zsh|fish
```

Kabuk tamamlama betiğini yazar. Komutlar, seçenekler ve siteler, uygulamalar gibi adlar Tab ile tamamlanır.

Örnekler:

```sh
source <(servik completion bash)
servik completion zsh > "${fpath[1]}/_servik"
servik completion fish > ~/.config/fish/completions/servik.fish
```

### servik version

```
servik version
```

Sürümü yazar
