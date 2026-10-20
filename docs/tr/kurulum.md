# Kurulum ve ilk adımlar

## Başlamadan önce

- Ubuntu Server 24.04 LTS ya da daha yenisi kurulu, amd64 veya arm64, **boş**
  bir sunucu. Servik Nginx'i, PHP-FPM'i, PowerDNS'i, Postfix'i ve diğerlerini
  kendisi yapılandırır ve kendi yazmadığı yapılandırmayı sahiplenmez. Başka bir
  kontrol panelinin yanına ya da zaten site barındıran bir sunucuya kurmayın.
- SSH ile root erişimi.
- Herkese açık bir IPv4 adresi ve sunucu için bir ad (ör. `sunucu.ornek.com`),
  bu ada sunucuyu gösteren bir A kaydı. Panelin ve posta sunucusunun
  sertifikaları için bu adın çözümlenmesi gerekir.
- Alan adlarınızın DNS'ini Servik yanıtlayacaksa iki ad sunucusu adı (ör.
  `ns1.ornek.com` ve `ns2.ornek.com`), alan adı firmanızda sunucuyu gösteren
  glue kayıtlarıyla.

Bellek: e-posta ve bir veritabanı sunucusu olan küçük bir sunucu için 2 GB
yeter; kuyruklu ve ön yüz derlemeli Laravel uygulamaları için 4 GB ya da
fazlasını planlayın.

## Kurulum

```sh
curl -fsSL https://github.com/Winchester37/Servik/releases/latest/download/install.sh -o install.sh
sudo bash install.sh --lang tr
```

Kurulum ne yapacağını gösterir ve başlamadan önce sorar. Sırasıyla:

1. işletim sistemini ve mimariyi denetler;
2. en yeni sürümü indirir ve `SHA256SUMS` ile doğrular;
3. panelin kendi PHP 8.5'ini (Ondřej Surý arşivinden), 5433 portunda kendi
   PostgreSQL kümesi `servik`'i ve kendi web sunucusu `servik-web`'i kurar;
   bunlar müşteri sitelerinin kullanacaklarından ayrıdır;
4. `servik-agent`'ı systemd hizmeti olarak ve `servik` komut satırını kurar;
5. paneli kendinden imzalı bir sertifikayla `https://<sunucu adı>:8443`
   adresinde başlatır;
6. müşteri siteleri için varsayılanları kurar: Nginx, PHP 8.4, Composer,
   Node.js 24 ve Fail2ban (`--no-components` ile atlanır);
7. tek kullanımlık bir kurulum bağlantısı yazar (aşağıda).

Yazdığı her şey `/var/log/servik/install.log` dosyasına da gider.

### Seçenekler

| Seçenek | |
|---------|---|
| `--admin-email E` | Kurulum bağlantısı yerine yöneticiyi şimdi bu e-postayla oluşturur ve parolasını sonda yazar. Otomatik kurulumlar için; kurulum sihirbazı yine ilk girişte açılır. |
| `--admin-name N` | Yöneticinin adı. |
| `--hostname H` | Panelin adresi. Varsayılan: sunucunun tam adı (FQDN). |
| `--lang tr\|en` | Panelin varsayılan dili ve kurulumun dili. Varsayılan: sistemin `LANG` değeri Türkçeyse Türkçe, değilse İngilizce. |
| `--version V` | En yenisi yerine `V` sürümünü kurar. |
| `--channel stable\|beta` | `beta` ön sürümleri de (`v0.2.0-beta.1`) dikkate alır. |
| `--no-components` | Siteler için Nginx, PHP 8.4, Composer, Node.js ve Fail2ban'ı şimdi kurmaz. |
| `--source URL` | Sürümlerin listelendiği yer: bir deponun GitHub API adresi (çatallar ve aynalar için). |
| `--token T` | Gizli bir deponun sürümleri için GitHub token'ı (ya da `SERVIK_GITHUB_TOKEN`). Panelin güncelleme denetimi de kullanabilsin diye yalnızca root'un okuyabildiği `/etc/servik/release-token` dosyasında saklanır. |

### Gizli bir depodan

Depo gizliyken GitHub ne sürümleri ne de kurulum betiğini token olmadan
verir. Depo içeriğini okuma izni olan ince ayarlı (fine-grained) bir token
yeterlidir:

```sh
export SERVIK_GITHUB_TOKEN=github_pat_...
curl -fsSL -H "Authorization: Bearer $SERVIK_GITHUB_TOKEN" -H 'Accept: application/vnd.github.raw' \
  https://api.github.com/repos/Winchester37/Servik/contents/scripts/install.sh | sudo -E bash
```

Depo herkese açılınca token'ı hem GitHub'da hem sunucuda silin
(`sudo rm /etc/servik/release-token`).
| `-y`, `--yes` | Başlamadan önce sormaz. |

### Portlar

Sağlayıcınızın güvenlik duvarında açın:

| Port | Ne için |
|------|---------|
| 22 | SSH ve SFTP hesapları |
| 80, 443 | Siteler ve Let's Encrypt'in HTTP doğrulaması |
| 8443 | Panel, phpMyAdmin ve Adminer |
| 53 (TCP ve UDP) | DNS, DNS sunucusunu kurduğunuzda |
| 25, 465, 587, 110, 995, 143, 993 | E-posta (SMTP, POP3, IMAP), posta sunucusunu kurduğunuzda |

Servik'in kendi güvenlik duvarı da var (Güvenlik → Güvenlik duvarı). Siz
açana kadar kapalıdır; açıldığında kurulu hizmetlerin ihtiyaç duyduğu portları
açık tutar.

### Kurulum bağlantısı

Kurulum şuna benzer bir bağlantıyla biter:

```
https://203.0.113.10:8443/install/Xk9…
```

Bağlantıyı tarayıcıda açın (kendinden imzalı sertifika uyarısını kabul edin).
Adınızı, e-postanızı, en az 12 karakterlik bir parolayı ve panelin dilini
sorar; ilk yöneticiyi oluşturur ve kurulum sihirbazını açar. Bağlantı bir kez
ve 24 saat çalışır. Kullanılana kadar kimse giriş yapamaz; yenisini yalnızca
sunucuda root olan oluşturabilir:

```sh
sudo servik setup-url
```

Yeni bağlantı öncekini geçersiz kılar. Bir yönetici varsa komut bağlantı
oluşturmaz; unutulan parola sunucuda
`sudo -u servik php8.5 /opt/servik/current/panel/artisan servik:admin` ile
sıfırlanır (`--email`, `--name`, `--password` da alır). Diğer yöneticiler
Araçlar ve Ayarlar → Yöneticiler'den davet edilir.

Servik sunucunun root parolasıyla giriş yaptırmaz: PHP hiçbir zaman root
olarak çalışmaz, root parolası tarayıcıdan geçmez ve kurulum bağlantısı
yalnızca SSH anahtarıyla girilen sunucularda da çalışır.

## Kurulum sihirbazı

Sihirbaz yönetici oluşturulunca açılır; hesap başka yoldan açıldıysa tam
yetkili bir yöneticinin ilk girişinde açılır. Her adım kendi ayar sayfasıyla
aynı ayarları kaydeder ve "Sonra" ile ertelenebilir:

1. **Sunucu:** sunucunun tam adı (posta için sağlayıcınızın panelinde IP'nin
   PTR kaydını da bu ada yönlendirin), DNS kayıtlarında kullanılan IPv4 ve IPv6
   adresleri, saat dilimi ve sunucunun dili.
2. **Ad sunucuları:** kendi alan adınız, altında `ns1`/`ns2` (sunucunun ikinci
   bir IP'si varsa `ns2` için o), DNS sunucusunun kurulması ve alan adınızın DNS
   bölgesinin oluşturulması. Bu iki adı IP adresleriyle alan adı firmanızda
   glue (host) kaydı olarak açın, sonra alan adının ad sunucuları yapın;
   sihirbaz tam adları ve adresleri gösterir.
3. **Panel adresi:** paneli sunucunun kendi adıyla (varsayılan), `yonetim.ornek.com.tr`
   gibi ayrı bir adla ya da yalnızca IP adresi ve portla açarsınız. Bir ad
   seçerseniz Let's Encrypt sertifikası alınır; ad sunucuyu gösterene kadar her
   saat yeniden denenir. Ad bir sitenin ya da posta sunucusunun adı olamaz. IP
   adresiyle açılan panel her zaman çalışır. Port Araçlar ve Ayarlar →
   Genel'den değiştirilir.
4. **Bildirimler:** sorun e-postalarının gideceği adresler. Sunucu henüz posta
   gönderemiyorsa adım bunu söyler ve giden posta ayarlarına bağlantı verir.
5. **Güvenlik duvarı:** kurulu hizmetlerin ihtiyaç duyduğu portlarla (panel,
   web, gerçek portundaki SSH; kurulunca DNS ve posta) Servik'in güvenlik
   duvarını açar. Panele kendi bağlantınız hiçbir zaman kesilmez.
6. **Varsayılanlar:** yeni sitelerin web sunucusu ve PHP sürümü, güncelleme
   kanalı (kararlı ya da beta). Güncellemeler hiçbir zaman kendiliğinden
   kurulmaz.
7. **Yedekler:** günlük sunucu yedeği ve saati; uzak bir hedef (S3 ya da SFTP)
   eklemeniz için hatırlatma.

Özet neyin yapıldığını, neyin kaldığını, glue ve PTR hatırlatmalarıyla
gösterir. **Kurulumu bitir**'e basana kadar Genel Bakış'ın üstünde aynı liste
durur. Sihirbaz istendiğinde Araçlar ve Ayarlar → Kurulum sihirbazı'ndan
yeniden çalıştırılır.

## Sihirbazdan sonra

1. **İki adımlı girişi açın:** sağ üstte adınız → Hesap güvenliği.
2. E-posta barındıracaksanız **posta sunucusunu kurun:** E-posta → E-posta
   ayarları'nda posta sunucusunun adını (ör. `mail.ornek.com`) yazın, sonra
   Posta sunucusunu kur.
3. **Bir veritabanı sunucusu kurun:** Veritabanları → Veritabanı sunucuları.
   Varsayılan MySQL'dir; MariaDB onun yerine geçer, PostgreSQL yanında çalışır.
4. **İlk sitenizi ekleyin:** Siteler → Site ekle. Servik sistem kullanıcısını,
   PHP-FPM havuzunu, web sunucusu yapılandırmasını ve DNS bölgesini oluşturur;
   alan adı sunucuyu gösterdiği anda sertifikayı alır.
5. İsteğe bağlı: **Servik AI** → Ayarlar, Claude API anahtarınızla.

Her alanın ayrıntısı [yönetici kılavuzunda](../README.md#administrator-guide) (İngilizce).

## Sunucuda komut satırı

Kurulum `servik` komutunu `/usr/local/bin` içine koyar. Root'u tarayıcı
olmadan bağlayın:

```sh
sudo servik login --local
servik sites list
```

Bkz. [Komut satırı başvurusu](cli.md).

## Neler nerede

| Yol | |
|-----|---|
| `/opt/servik/releases/<sürüm>/` | Kurulu sürümler: `panel/`, `bin/servik-agent`, `bin/servik` |
| `/opt/servik/current` | Çalışan sürüme bağlantı |
| `/etc/servik/panel.env` | Panelin ayarları (`APP_KEY`, veritabanı parolası). Yalnızca root ve `servik` grubu okuyabilir. |
| `/etc/servik/` | Ajanın durumu: DNS, e-posta, güvenlik duvarı, cron, sınırlar |
| `/var/lib/servik/panel/storage` | Panelin depolaması ve günlükleri |
| `/var/lib/servik/backups/` | Yerel yedekler ve içe aktarma klasörü |
| `/var/log/servik/` | Kurulum ve güncelleme günlükleri, sitelerin web sunucusu günlükleri |
| `/home/<kullanıcı>/web/<alan adı>/` | Bir sitenin dosyaları |

Hizmetler: `servik-agent`, `servik-web`, `servik-php`, `postgresql@16-servik`
(panelin veritabanı), `servik-panel-queue`, `servik-panel-backups` ve
`servik-panel-scheduler`. Hizmetler sayfası bunları müşteri hizmetlerinden
ayrı gösterir ve yanlışlıkla durdurulmalarına izin vermez.

## Güncelleme

Araçlar ve Ayarlar → Güncellemeler her gün 04:30'da yeni sürüm olup olmadığına
bakar; **Güncelle**'ye bastığınızda panel kendini yedekler, yeni sürümü eskisinin
yanına kurar, veritabanını taşır ve sağlığını denetler. Denetim başarısız
olursa eski sürüm ve veritabanı kendiliğinden geri gelir.

## Sorun giderme

- Kurulum bir mesajla durur; tüm çıktı `/var/log/servik/install.log`
  dosyasındadır. Servik zaten kuruluysa yeniden çalışmaz: panelden güncelleyin.
- Panel açılmıyor: `systemctl status servik-web servik-php`,
  `/var/log/servik/web/` ve `journalctl -u servik-php`.
- Panel ajana ulaşılamadığını söylüyor: `systemctl status servik-agent` ve
  `journalctl -u servik-agent`.
