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
sudo bash install.sh --admin-email siz@ornek.com --lang tr
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
7. yöneticiyi oluşturur ve parolasını yazar.

Yazdığı her şey `/var/log/servik/install.log` dosyasına da gider.

### Seçenekler

| Seçenek | |
|---------|---|
| `--admin-email E` | Yöneticiyi bu e-postayla oluşturur; parola sonda yazılır. Verilmezse yöneticiyi sonra `servik:admin` ile oluşturun (aşağıda). |
| `--admin-name N` | Yöneticinin adı. |
| `--hostname H` | Panelin adresi. Varsayılan: sunucunun tam adı (FQDN). |
| `--lang tr\|en` | Panelin varsayılan dili ve kurulumun dili. Varsayılan: sistemin `LANG` değeri Türkçeyse Türkçe, değilse İngilizce. |
| `--version V` | En yenisi yerine `V` sürümünü kurar. |
| `--channel stable\|beta` | `beta` ön sürümleri de (`v0.2.0-beta.1`) dikkate alır. |
| `--no-components` | Siteler için Nginx, PHP 8.4, Composer, Node.js ve Fail2ban'ı şimdi kurmaz. |
| `--source URL` | Sürümlerin listelendiği yer: bir deponun GitHub API adresi (çatallar ve aynalar için). |
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

### `--admin-email` vermediyseniz

```sh
sudo -u servik php8.5 /opt/servik/current/panel/artisan servik:admin
```

E-postayı, adı ve en az 12 karakterlik bir parolayı sorar (ya da `--email`,
`--name`, `--password` alır). Yeniden çalıştırmak ilk yöneticinin e-postasını,
adını ve parolasını sıfırlar; parola unutulduğunda panele dönmenin yolu budur.
Diğer yöneticiler Araçlar ve Ayarlar → Yöneticiler'den yönetilir.

## Paneldeki ilk adımlar

`https://<sunucu adı>:8443` adresinden giriş yapın. Tarayıcınız ilk seferde
kendinden imzalı sertifika için uyarır.

1. **Parolayı değiştirin ve iki adımlı girişi açın:** sağ üstte adınız →
   Hesap güvenliği.
2. **Panele kendi sertifikasını verin:** Araçlar ve Ayarlar → Genel → Panel
   adresi. Sunucu adı sunucuya çözümlendiğinde Servik bunun için Let's Encrypt
   sertifikası alır ve yeniler.
3. **Sunucu ayarlarını gözden geçirin:** Araçlar ve Ayarlar → Genel (dil,
   saat dilimi, oturum süresi) ve Araçlar ve Ayarlar → Sunucu (sunucunun adı).
4. Alan adlarınızı Servik yanıtlayacaksa **DNS sunucusunu kurun:** Alan Adları
   ve DNS → DNS sunucusunu kur. Ardından ad sunucularınızı Alan Adları ve DNS →
   Sunucu DNS ayarları'na yazın.
5. E-posta barındıracaksanız **posta sunucusunu kurun:** E-posta → E-posta
   ayarları'nda posta sunucusunun adını (ör. `mail.ornek.com`) yazın, sonra
   Posta sunucusunu kur.
6. **Bir veritabanı sunucusu kurun:** Veritabanları → Veritabanı sunucuları.
   Varsayılan MySQL'dir; MariaDB onun yerine geçer, PostgreSQL yanında çalışır.
7. **İlk sitenizi ekleyin:** Siteler → Site ekle. Servik sistem kullanıcısını,
   PHP-FPM havuzunu, web sunucusu yapılandırmasını ve DNS bölgesini oluşturur;
   alan adı sunucuyu gösterdiği anda sertifikayı alır.
8. **Bir yedek hedefi belirleyin:** Yedekler → Hedefler. Siz S3 uyumlu bir
   depolama ya da SFTP eklemedikçe yedekler her gün 03:00'te yerel diske alınır.
9. **Kime haber verileceğini seçin:** Araçlar ve Ayarlar → Bildirimler
   (e-posta ve webhook).
10. İsteğe bağlı: **Servik AI** → Ayarlar, Claude API anahtarınızla.

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
