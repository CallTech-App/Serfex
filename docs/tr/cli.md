<!-- Bu dosya üretilir: cd cli && go test ./internal/app -run TestReferenceIsCurrent -update -->

# Komut satırı başvurusu

Serfex komut satırı: siteler, alan adları, DNS, SSL, e-posta, veritabanları, yedekler, hizmetler ve Laravel uygulamaları.

Önce bir panele giriş yapın: serfex login panel.ornek.com.tr:8443

Her komut `--help` ile kendi yardımını da gösterir: `serfex sites create --help`.

## Genel seçenekler

Bu seçenekler her komutta geçerlidir.

| Seçenek | |
| --- | --- |
| `--profile <ad>` | Kullanılacak profil (varsayılan: son giriş yapılan; SERFEX_PROFILE) |
| `--json` | Çıktıyı JSON olarak yazar |
| `--lang <tr\|en>` | Dil (varsayılan: sistem dili; SERFEX_LANG) |
| `--url <adres>` | Panel adresi (SERFEX_URL) |
| `--token <anahtar>` | API anahtarı ya da uygulama belirteci (SERFEX_TOKEN) |
| `-y, --yes` | Onay sormadan yapar |
| `--no-color` | Renksiz çıktı (NO_COLOR) |
| `-h, --help` | Yardımı gösterir |

## Hesap

### serfex login

```
serfex login [panel-adresi] [seçenekler]
```

Bir Serfex paneline giriş yapar. Tarayıcıda bir onay sayfası açılır; onayladığınızda bu bilgisayar için bir anahtar oluşur ve Hesap güvenliği altında listelenir.

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
serfex login panel.ornek.com.tr:8443
serfex login https://panel.ornek.com.tr:8443 --token sfx_…
sudo serfex login --local
```

### serfex setup-url

```
serfex setup-url
```

Henüz yöneticisi olmayan bir panel için tek kullanımlık kurulum bağlantısı oluşturur. Bağlantıyı açan kişi ilk yönetici hesabını oluşturur ve kurulum sihirbazına geçer. Bağlantı 24 saat geçerlidir; yenisi oluşunca eskisi çalışmaz.

install.sh ilk bağlantıyı kurulumun sonunda yazar. Sunucunun kendisinde root olarak çalışır.

Örnekler:

```sh
sudo serfex setup-url
```

### serfex reset-password

```
serfex reset-password <e-posta>
```

Bir yönetici, müşteri ya da ekip üyesi hesabı için tek kullanımlık parola sıfırlama bağlantısı oluşturur. Bağlantıyı açan kişi yeni parolasını belirler; iki adımlı doğrulama açıksa girişte yine sorulur. Bağlantı 60 dakika geçerlidir; yenisi oluşunca eskisi çalışmaz.

Panel e-posta gönderemediğinde ya da e-posta ulaşmadığında kullanın. Sunucunun kendisinde root olarak çalışır.

Örnekler:

```sh
sudo serfex reset-password ayse@ornek.com.tr
```

### serfex logout

```
serfex logout
```

Profilden çıkar ve anahtarını panelde kapatır

### serfex profile

```
serfex profile <komut>
```

Giriş yapılan panelleri listeler ve aralarında geçer

Diğer adları: `profiles`

| Komut | |
| --- | --- |
| [`list`](#serfex-profile-list) | Profilleri listeler |
| [`use`](#serfex-profile-use) | Varsayılan profili değiştirir |
| [`remove`](#serfex-profile-remove) | Profili siler ve anahtarını panelde kapatır |

### serfex profile list

```
serfex profile list
```

Profilleri listeler

Diğer adları: `ls`

### serfex profile use

```
serfex profile use <ad>
```

Varsayılan profili değiştirir

### serfex profile remove

```
serfex profile remove <ad>
```

Profili siler ve anahtarını panelde kapatır

Diğer adları: `rm`

### serfex whoami

```
serfex whoami
```

Kim olarak, hangi panele bağlı olduğunuzu gösterir

### serfex status

```
serfex status
```

Sunucunun durumunu ve dikkat isteyen sorunları gösterir

### serfex fingerprint

```
serfex fingerprint [panel-adresi]
```

Panelin gösterdiği sertifikanın SHA-256 parmak izini yazar. Sunucuda adres vermeden çalıştırın ve çıkan değeri ilk girişte bilgisayarınızda görünenle karşılaştırın.

## Barındırma

### serfex sites

```
serfex sites <komut>
```

Siteleri listeler, oluşturur ve siler

Diğer adları: `site`

| Komut | |
| --- | --- |
| [`list`](#serfex-sites-list) | Siteleri listeler |
| [`show`](#serfex-sites-show) | Bir sitenin ayrıntılarını gösterir |
| [`create`](#serfex-sites-create) | Yeni bir site oluşturur |
| [`delete`](#serfex-sites-delete) | Siteyi dosyaları, veritabanları ve e-postasıyla siler |

### serfex sites list

```
serfex sites list
```

Siteleri listeler

Diğer adları: `ls`

### serfex sites show

```
serfex sites show <site>
```

Bir sitenin ayrıntılarını gösterir

### serfex sites create

```
serfex sites create <alan-adı> [seçenekler]
```

Yeni bir site oluşturur: sistem kullanıcısı, web klasörü, web sunucusu ayarı ve DNS bölgesi, paneldeki gibi.

Diğer adları: `add`

| Seçenek | |
| --- | --- |
| `--php <sürüm>` | PHP sürümü (varsayılan: sunucunun varsayılanı) |
| `--www` | www. adı da açılır (varsayılan: evet; kapatmak için --no-www) |
| `--prefer <bare\|www\|none>` | Tercih edilen adres: bare (www'suz, varsayılan), www ya da none (ikisi de açılır) |
| `--customer <id>` | Sitenin ait olacağı müşteri (yöneticiler) |
| `--account <ad>` | Sistem kullanıcısının adı (varsayılan: alan adından) |
| `--wait` | Site hazır olana kadar bekler |

Örnekler:

```sh
serfex sites create kahveatolyesi.com.tr --php 8.4 --wait
```

### serfex sites delete

```
serfex sites delete <site> [seçenekler]
```

Siteyi dosyaları, veritabanları ve e-postasıyla siler

Diğer adları: `rm`

| Seçenek | |
| --- | --- |
| `--keep-dns` | DNS bölgesini bırakır |
| `--no-backup` | Silmeden önce yedek almaz (yalnızca yönetici) |

### serfex domains

```
serfex domains <komut>
```

Alt alan adları, ek alan adları ve yönlendirmeler

Diğer adları: `domain`

| Komut | |
| --- | --- |
| [`list`](#serfex-domains-list) | Bir sitenin diğer adlarını listeler |
| [`add`](#serfex-domains-add) | Alt alan adı (blog) ya da ek alan adı (ornek.net) ekler |
| [`redirect`](#serfex-domains-redirect) | Bir yolu (/eski) ya da alan adını başka bir adrese yönlendirir |
| [`remove`](#serfex-domains-remove) | Alt alan adını, ek alan adını ya da yönlendirmeyi kaldırır |

### serfex domains list

```
serfex domains list <site>
```

Bir sitenin diğer adlarını listeler

Diğer adları: `ls`

### serfex domains add

```
serfex domains add <site> <ad> [seçenekler]
```

Bir siteye ad ekler. Noktasız ya da sitenin altındaki bir ad (blog, blog.ornek.com.tr) kendi klasörü olan bir alt alan adıdır; başka bir alan adı (ornek.net) aynı siteyi açan ek alan adıdır. DNS ve Let's Encrypt kendiliğinden ayarlanır.

| Seçenek | |
| --- | --- |
| `--root <klasör>` | Alt alan adının klasörü, sitenin klasörüne göre (varsayılan: subdomains/<ad>) |
| `--dns` | Ek alan adı için DNS bölgesi oluşturur (varsayılan: evet) |

Örnekler:

```sh
serfex domains add ornek.com.tr blog
serfex domains add ornek.com.tr ornek.net --no-dns
```

### serfex domains redirect

```
serfex domains redirect <site> <kaynak> <hedef> [seçenekler]
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
serfex domains redirect ornek.com.tr /eski https://ornek.com.tr/yeni
serfex domains redirect ornek.com.tr eskiad.com https://ornek.com.tr --keep-path
```

### serfex domains remove

```
serfex domains remove <site> <ad>
```

Alt alan adını, ek alan adını ya da yönlendirmeyi kaldırır

Diğer adları: `rm`

### serfex dns

```
serfex dns <komut>
```

DNS bölgeleri ve kayıtları

| Komut | |
| --- | --- |
| [`zones`](#serfex-dns-zones) | DNS bölgelerini listeler |
| [`records`](#serfex-dns-records) | Bir bölgenin kayıtlarını listeler |
| [`add`](#serfex-dns-add) | Kayıt ekler (ad: @ bölgenin kendisi) |
| [`delete`](#serfex-dns-delete) | Kaydı siler (numarası: serfex dns records) |

### serfex dns zones

```
serfex dns zones
```

DNS bölgelerini listeler

Diğer adları: `list`, `ls`

### serfex dns records

```
serfex dns records <bölge>
```

Bir bölgenin kayıtlarını listeler

Diğer adları: `show`

### serfex dns add

```
serfex dns add <bölge> <tür> <ad> <değer> [seçenekler]
```

Kayıt ekler (ad: @ bölgenin kendisi)

| Seçenek | |
| --- | --- |
| `--ttl <saniye>` | Kaydın TTL'i (varsayılan: bölgeninki) |
| `--priority <sayı>` | MX ve SRV önceliği (varsayılan: 10) |

Örnekler:

```sh
serfex dns add ornek.com.tr A www 203.0.113.10
serfex dns add ornek.com.tr MX @ mail.ornek.com.tr --priority 10
serfex dns add ornek.com.tr TXT @ "v=spf1 mx -all"
```

### serfex dns delete

```
serfex dns delete <bölge> <kayıt-no>
```

Kaydı siler (numarası: serfex dns records)

Diğer adları: `rm`

### serfex ssl

```
serfex ssl <komut>
```

Sitelerin sertifikaları ve HTTPS

| Komut | |
| --- | --- |
| [`list`](#serfex-ssl-list) | Sitelerin sertifikalarını listeler |
| [`show`](#serfex-ssl-show) | Bir sitenin sertifikasını gösterir |
| [`issue`](#serfex-ssl-issue) | Let's Encrypt sertifikası alır |
| [`renew`](#serfex-ssl-renew) | Sertifikayı şimdi yeniler |
| [`https`](#serfex-ssl-https) | HTTP isteklerini HTTPS'e yönlendirmeyi açar ya da kapatır |

### serfex ssl list

```
serfex ssl list
```

Sitelerin sertifikalarını listeler

Diğer adları: `ls`

### serfex ssl show

```
serfex ssl show <site>
```

Bir sitenin sertifikasını gösterir

### serfex ssl issue

```
serfex ssl issue <site> [seçenekler]
```

Let's Encrypt sertifikası alır

| Seçenek | |
| --- | --- |
| `--wildcard` | *.alan-adı için de (DNS bu sunucudaysa) |
| `--only-domain` | www. ve ek alan adları olmadan |
| `--wait` | Sertifika alınana kadar bekler |

### serfex ssl renew

```
serfex ssl renew <site> [seçenekler]
```

Sertifikayı şimdi yeniler

| Seçenek | |
| --- | --- |
| `--wait` | Sertifika yenilenene kadar bekler |

### serfex ssl https

```
serfex ssl https <site> on|off
```

HTTP isteklerini HTTPS'e yönlendirmeyi açar ya da kapatır

### serfex mail

```
serfex mail <komut>
```

E-posta alan adları, posta kutuları ve yönlendiriciler

| Komut | |
| --- | --- |
| [`domains`](#serfex-mail-domains) | E-postası açık alan adlarını listeler |
| [`enable`](#serfex-mail-enable) | Bir alan adında e-postayı açar (DNS kayıtları kendiliğinden yazılır) |
| [`list`](#serfex-mail-list) | Posta kutularını ve yönlendiricileri listeler |
| [`create`](#serfex-mail-create) | Posta kutusu oluşturur |
| [`password`](#serfex-mail-password) | Posta kutusunun parolasını değiştirir |
| [`quota`](#serfex-mail-quota) | Posta kutusunun kotasını değiştirir (0 sınırsız) |
| [`delete`](#serfex-mail-delete) | Posta kutusunu siler |
| [`forward`](#serfex-mail-forward) | Bir adresi başka adreslere yönlendirir (*@alan-adı: tümünü yakala) |
| [`unforward`](#serfex-mail-unforward) | Yönlendiriciyi siler |

### serfex mail domains

```
serfex mail domains
```

E-postası açık alan adlarını listeler

### serfex mail enable

```
serfex mail enable <alan-adı>
```

Bir alan adında e-postayı açar (DNS kayıtları kendiliğinden yazılır)

### serfex mail list

```
serfex mail list <alan-adı>
```

Posta kutularını ve yönlendiricileri listeler

Diğer adları: `ls`

### serfex mail create

```
serfex mail create <adres> [seçenekler]
```

Posta kutusu oluşturur

Diğer adları: `add`

| Seçenek | |
| --- | --- |
| `--password-stdin` | Parolayı standart girdiden okur (verilmezse panel güçlü bir parola oluşturur ve bir kez gösterir) |
| `--quota <MB>` | Kota, MB (varsayılan: sunucunun varsayılanı; 0 sınırsız) |

Örnekler:

```sh
serfex mail create siparis@ornek.com.tr
printf '%s' "$PAROLA" | serfex mail create info@ornek.com.tr --password-stdin
```

### serfex mail password

```
serfex mail password <adres> [seçenekler]
```

Posta kutusunun parolasını değiştirir

| Seçenek | |
| --- | --- |
| `--password-stdin` | Parolayı standart girdiden okur (verilmezse panel güçlü bir parola oluşturur ve bir kez gösterir) |

### serfex mail quota

```
serfex mail quota <adres> <MB>
```

Posta kutusunun kotasını değiştirir (0 sınırsız)

### serfex mail delete

```
serfex mail delete <adres>
```

Posta kutusunu siler

Diğer adları: `rm`

### serfex mail forward

```
serfex mail forward <adres> <hedef>…
```

Bir adresi başka adreslere yönlendirir (*@alan-adı: tümünü yakala)

Örnekler:

```sh
serfex mail forward satis@ornek.com.tr ali@ornek.com.tr ayse@gmail.com
serfex mail forward '*@ornek.com.tr' info@ornek.com.tr
```

### serfex mail unforward

```
serfex mail unforward <adres>
```

Yönlendiriciyi siler

### serfex db

```
serfex db <komut>
```

Sitelerin veritabanları

Diğer adları: `databases`, `database`

| Komut | |
| --- | --- |
| [`list`](#serfex-db-list) | Veritabanlarını listeler |
| [`create`](#serfex-db-create) | Veritabanı ve kullanıcısını oluşturur |
| [`delete`](#serfex-db-delete) | Veritabanını siler (önce yedeğini alır) |

### serfex db list

```
serfex db list [seçenekler]
```

Veritabanlarını listeler

Diğer adları: `ls`

| Seçenek | |
| --- | --- |
| `--site <site>` | Yalnızca bu sitenin veritabanları |

### serfex db create

```
serfex db create <site> <ad> [seçenekler]
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
serfex db create kahveatolyesi.com.tr dukkan
```

### serfex db delete

```
serfex db delete <ad> [seçenekler]
```

Veritabanını siler (önce yedeğini alır)

Diğer adları: `rm`

| Seçenek | |
| --- | --- |
| `--no-backup` | Silmeden önce yedek almaz |

### serfex backups

```
serfex backups <komut>
```

Yedekler ve geri yükleme

Diğer adları: `backup`

| Komut | |
| --- | --- |
| [`list`](#serfex-backups-list) | Yedekleri listeler, en yenisi önce |
| [`show`](#serfex-backups-show) | Bir yedeğin içindekileri gösterir |
| [`create`](#serfex-backups-create) | Şimdi yedek alır: bir siteyi ya da (yöneticiler) tüm sunucuyu |
| [`restore`](#serfex-backups-restore) | Bir siteyi yedekten geri yükler |

### serfex backups list

```
serfex backups list [seçenekler]
```

Yedekleri listeler, en yenisi önce

Diğer adları: `ls`

| Seçenek | |
| --- | --- |
| `--site <site>` | Yalnızca bu sitenin bulunduğu yedekler |
| `-n, --limit <sayı>` | En fazla kaç yedek (varsayılan: 20) |

### serfex backups show

```
serfex backups show <no>
```

Bir yedeğin içindekileri gösterir

### serfex backups create

```
serfex backups create [site] [seçenekler]
```

Şimdi yedek alır: bir siteyi ya da (yöneticiler) tüm sunucuyu

| Seçenek | |
| --- | --- |
| `--wait` | Yedek bitene kadar bekler |

### serfex backups restore

```
serfex backups restore <no> <site> [seçenekler]
```

Bir siteyi yedekten geri yükler. Önce sitenin şimdiki hâli yedeklenir, böylece geri yükleme de geri alınabilir.

| Seçenek | |
| --- | --- |
| `--only <parça>` | Yalnızca bunlar: files, databases, mail, dns, certificates (virgülle) |
| `--no-safety` | Önce güvenlik yedeği almaz |
| `--wait` | Geri yükleme bitene kadar bekler |

Örnekler:

```sh
serfex backups restore 42 kahveatolyesi.com.tr --only files,databases --wait
```

## Sunucu

### serfex services

```
serfex services <komut>
```

Sunucunun hizmetleri (yöneticiler)

Diğer adları: `service`

| Komut | |
| --- | --- |
| [`list`](#serfex-services-list) | Hizmetleri listeler |
| [`status`](#serfex-services-status) | Bir hizmetin durumunu gösterir |
| [`start`](#serfex-services-start) | Hizmeti başlatır |
| [`stop`](#serfex-services-stop) | Hizmeti durdurur |
| [`restart`](#serfex-services-restart) | Ayarını denetleyip hizmeti yeniden başlatır |
| [`logs`](#serfex-services-logs) | Hizmetin günlüğünün son satırları |

### serfex services list

```
serfex services list
```

Hizmetleri listeler

Diğer adları: `ls`

### serfex services status

```
serfex services status <hizmet>
```

Bir hizmetin durumunu gösterir

Diğer adları: `show`

### serfex services start

```
serfex services start <hizmet>
```

Hizmeti başlatır

### serfex services stop

```
serfex services stop <hizmet>
```

Hizmeti durdurur

### serfex services restart

```
serfex services restart <hizmet>
```

Ayarını denetleyip hizmeti yeniden başlatır

### serfex services logs

```
serfex services logs <hizmet> [seçenekler]
```

Hizmetin günlüğünün son satırları

| Seçenek | |
| --- | --- |
| `-n, --lines <sayı>` | Kaç satır (varsayılan: 50) |

### serfex customers

```
serfex customers <komut>
```

Müşteriler ve paketleri (yöneticiler)

Diğer adları: `customer`

| Komut | |
| --- | --- |
| [`list`](#serfex-customers-list) | Müşterileri listeler |
| [`show`](#serfex-customers-show) | Müşterinin siteleri ve paket kullanımı |
| [`suspend`](#serfex-customers-suspend) | Müşteriyi askıya alır (siteleri 503, postaları bekletilir) |
| [`unsuspend`](#serfex-customers-unsuspend) | Askıyı kaldırır |
| [`packages`](#serfex-customers-packages) | Paketleri listeler |

### serfex customers list

```
serfex customers list
```

Müşterileri listeler

Diğer adları: `ls`

### serfex customers show

```
serfex customers show <no>
```

Müşterinin siteleri ve paket kullanımı

### serfex customers suspend

```
serfex customers suspend <no> [seçenekler]
```

Müşteriyi askıya alır (siteleri 503, postaları bekletilir)

| Seçenek | |
| --- | --- |
| `--reason <metin>` | Müşterinin göreceği neden |

### serfex customers unsuspend

```
serfex customers unsuspend <no>
```

Askıyı kaldırır

### serfex customers packages

```
serfex customers packages
```

Paketleri listeler

## Laravel

### serfex apps

```
serfex apps
```

Erişebildiğiniz Laravel uygulamalarını listeler

### serfex deploy

```
serfex deploy [seçenekler]
```

Uygulamayı deposundan dağıtır: yeni sürüm releases/ altına kurulur, sağlık kontrolünden geçince yayına girer, geçemezse önceki sürüm yayında kalır.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `--ref <dal\|etiket\|commit>` | Dağıtılacak dal, etiket ya da commit (varsayılan: uygulamanın dalı) |
| `--no-migrate` | Bu dağıtımda migrate çalıştırmaz |
| `--no-frontend` | Bu dağıtımda ön yüzü (npm run build) derlemez |
| `--wait` | Bitene kadar çıktısını gösterir; başarısız olursa sıfırdan farklı kodla çıkar |
| `--timeout <süre>` | --wait için en uzun bekleme (varsayılan: 60m) |

Örnekler:

```sh
serfex deploy -a api.ornek.com.tr --wait
SERFEX_URL=https://panel.ornek.com.tr:8443 SERFEX_TOKEN=sfx_… serfex deploy --ref "$GITHUB_SHA" --wait
```

### serfex deployments

```
serfex deployments [seçenekler]
```

Son dağıtımları listeler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `-n, --limit <sayı>` | Kaç dağıtım (varsayılan: 10) |

### serfex rollback

```
serfex rollback [seçenekler]
```

Önceki sürüme döner

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `--to <no>` | Dönülecek dağıtımın numarası (varsayılan: bir önceki) |
| `--wait` | Bitene kadar çıktısını gösterir; başarısız olursa sıfırdan farklı kodla çıkar |
| `--timeout <süre>` | --wait için en uzun bekleme (varsayılan: 60m) |

### serfex preview

```
serfex preview <komut>
```

Önizleme ortamları (<dal>.alan-adı)

Diğer adları: `previews`

| Komut | |
| --- | --- |
| [`create`](#serfex-preview-create) | Bir dal için önizleme ortamı açar |
| [`delete`](#serfex-preview-delete) | Önizleme ortamını kaldırır |

### serfex preview create

```
serfex preview create <dal> [seçenekler]
```

Bir dal için önizleme ortamı açar

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex preview delete

```
serfex preview delete <ad> [seçenekler]
```

Önizleme ortamını kaldırır

Diğer adları: `rm`

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex env

```
serfex env [seçenekler]
```

Uygulamanın .env dosyasını gösterir ve değiştirir. Kaydedilince config:cache yenilenir, işçiler yeniden başlar; panel her sürümü saklar.

| Komut | |
| --- | --- |
| [`list`](#serfex-env-list) | Anahtarları ve değerlerini listeler (gizliler maskeli) |
| [`get`](#serfex-env-get) | Bir değeri olduğu gibi yazar |
| [`set`](#serfex-env-set) | Değerleri değiştirir ya da ekler |
| [`unset`](#serfex-env-unset) | Anahtarları siler |
| [`pull`](#serfex-env-pull) | .env dosyasını indirir |
| [`push`](#serfex-env-push) | .env dosyasının tamamını değiştirir |

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `--reveal` | Parola ve anahtarları gizlemeden gösterir |

### serfex env list

```
serfex env list [seçenekler]
```

Anahtarları ve değerlerini listeler (gizliler maskeli)

Diğer adları: `ls`

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `--reveal` | Parola ve anahtarları gizlemeden gösterir |

### serfex env get

```
serfex env get <ANAHTAR> [seçenekler]
```

Bir değeri olduğu gibi yazar

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex env set

```
serfex env set <ANAHTAR=değer>… [seçenekler]
```

Değerleri değiştirir ya da ekler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

Örnekler:

```sh
serfex env set APP_DEBUG=false MAIL_FROM_NAME="Kargo"
```

### serfex env unset

```
serfex env unset <ANAHTAR>… [seçenekler]
```

Anahtarları siler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex env pull

```
serfex env pull [seçenekler]
```

.env dosyasını indirir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `-o, --output <dosya>` | Yazılacak dosya (varsayılan: standart çıktı) |

### serfex env push

```
serfex env push <dosya> [seçenekler]
```

.env dosyasının tamamını değiştirir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex artisan

```
serfex artisan <komut>… [seçenekler]
```

artisan komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. serfex seçenekleri (-a) komuttan önce gelir.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex composer

```
serfex composer <komut>… [seçenekler]
```

composer komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. serfex seçenekleri (-a) komuttan önce gelir.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex npm

```
serfex npm <komut>… [seçenekler]
```

npm komutunu uygulamanın klasöründe, sitenin kullanıcısıyla çalıştırır ve çıktısını gösterir; çıkış kodu komutunki olur. serfex seçenekleri (-a) komuttan önce gelir.

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex queues

```
serfex queues [seçenekler]
```

Kuyruk işçileri ve bekleyen işler

Diğer adları: `queue`

| Komut | |
| --- | --- |
| [`status`](#serfex-queues-status) | İşçileri ve bekleyen işleri gösterir |
| [`failed`](#serfex-queues-failed) | Başarısız işleri listeler |
| [`retry`](#serfex-queues-retry) | Başarısız bir işi ya da hepsini yeniden kuyruğa alır |
| [`restart`](#serfex-queues-restart) | İşçileri yeniden başlatır |

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex queues status

```
serfex queues status [seçenekler]
```

İşçileri ve bekleyen işleri gösterir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex queues failed

```
serfex queues failed [seçenekler]
```

Başarısız işleri listeler

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex queues retry

```
serfex queues retry [iş-no] [seçenekler]
```

Başarısız bir işi ya da hepsini yeniden kuyruğa alır

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex queues restart

```
serfex queues restart [seçenekler]
```

İşçileri yeniden başlatır

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex maintenance

```
serfex maintenance [seçenekler]
```

Bakım modu

Diğer adları: `down`

| Komut | |
| --- | --- |
| [`status`](#serfex-maintenance-status) | Bakım modunda olup olmadığını gösterir |
| [`on`](#serfex-maintenance-on) | Bakım modunu açar |
| [`off`](#serfex-maintenance-off) | Bakım modunu kapatır |

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex maintenance status

```
serfex maintenance status [seçenekler]
```

Bakım modunda olup olmadığını gösterir

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex maintenance on

```
serfex maintenance on [seçenekler]
```

Bakım modunu açar

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `--mode <serfex\|custom\|app>` | Serfex sayfası (varsayılan), kendi HTML'iniz ya da php artisan down |
| `--allow <ip>` | Bakımdayken siteyi görebilecek IP ya da ağ (birden çok kez) |
| `--secret <yol>` | Bakımı atlatan gizli yol (varsayılan: rastgele) |
| `--retry <saniye>` | Retry-After başlığı |
| `--html-file <dosya>` | --mode custom için HTML dosyası |

### serfex maintenance off

```
serfex maintenance off [seçenekler]
```

Bakım modunu kapatır

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |

### serfex logs

```
serfex logs [seçenekler]
```

Uygulamanın günlükleri (storage/logs)

| Seçenek | |
| --- | --- |
| `-a, --app <alan-adı>` | Laravel uygulaması (SERFEX_APP; bir belirteçle girişte gerekmez) |
| `--level <düzey>` | Bu düzey ve üstü: debug, info, warning, error, critical |
| `--file <dosya>` | Günlük dosyası (varsayılan: en yenisi) |
| `--search <metin>` | İçinde bu metin geçenler |
| `-n, --lines <sayı>` | Kaç kayıt (varsayılan: 50) |
| `-f, --follow` | Yeni kayıtları geldikçe yazar |
| `--detail` | Hata ayrıntılarını (stack trace) da yazar |

Örnekler:

```sh
serfex logs -a api.ornek.com.tr --level error -n 20
serfex logs -f
```

## Diğer

### serfex completion

```
serfex completion bash|zsh|fish
```

Kabuk tamamlama betiğini yazar. Komutlar, seçenekler ve siteler, uygulamalar gibi adlar Tab ile tamamlanır.

Örnekler:

```sh
source <(serfex completion bash)
serfex completion zsh > "${fpath[1]}/_serfex"
serfex completion fish > ~/.config/fish/completions/serfex.fish
```

### serfex version

```
serfex version
```

Sürümü yazar
