# Müşteri kılavuzu

Bu kılavuz, hostingi bir Serfex sunucusunda çalışanlar içindir: hosting
firmanız size bir giriş bağlantısı gönderir; aşağıdaki her şey kendi
panelinizdedir. Neleri kullanabileceğiniz paketinize bağlıdır; paketinizde
olmayanlar "Paketinizde yok" diye görünür.

## Giriş

Size gönderilen giriş bağlantısını açın (7 gün geçerlidir) ve parolanızı
belirleyin. Sonra panelin adresinden e-postanız ve parolanızla giriş yapın.
Parolanızı unutursanız giriş sayfasındaki **Parolamı unuttum** bağlantısı
size yenisini belirlemeniz için bir bağlantı gönderir (60 dakika geçerlidir).

Menüde adınıza tıklayınca açılan **Hesap bilgileri** sayfasında:

- adınızı, telefonunuzu ve panelin dilini (Türkçe ya da İngilizce) değiştirebilir;
- e-posta adresinizi değiştirebilirsiniz: şimdiki parolanızı yazın, sonra yeni
  adrese gelen bağlantıyı açın (24 saat geçerlidir). Açana kadar eski adresle
  giriş yapmaya devam edersiniz.

Yanındaki **Hesap güvenliği** sayfasında:

- parolanızı değiştirebilir (diğer oturumlarınız kapanır);
- bir doğrulama uygulamasıyla (Google Authenticator, Microsoft Authenticator,
  1Password, Bitwarden…) **iki adımlı doğrulamayı** açıp kurtarma kodlarını
  güvenli bir yere kaydedebilir;
- açık oturumlarınızı ve komut satırı girişlerinizi görüp kapatabilirsiniz.

## Genel bakış ve kullanım

İlk sayfa paketinizin kullanımını gösterir: disk alanı, bu ayki trafik ve
sitelerin, posta kutularının, veritabanlarının ve diğerlerinin paket
sınırlarına göre sayısı. **Kullanım** sayfasında site bazında ayrıntılar ve
son 12 ay var. Bir sınırın %85'ine ve %100'üne ulaştığınızda e-posta alırsınız.
Trafik sınırı aşılsa da siteleriniz çalışmaya devam eder. Dolan disk yeni
site, posta kutusu, yükleme ve yedeği durdurur; çoğu sunucuda dosyalara
yazmak da durur: yükleme, SFTP ve sitelerinizin kendi yazmaları başarısız
olur, gelen e-postalar siz yer açana kadar bekletilir (gönderen sunucular
günlerce yeniden dener).

## Siteler

**Siteler → Site ekle**: alan adını yazın ve PHP sürümünü seçin. Site birkaç
saniyede `/home/<kullanıcınız>/web/<alan adı>/public_html` klasöründe açılır;
alan adı sunucuyu gösterdiğinde ücretsiz SSL sertifikası alınır (o zamana kadar
"DNS henüz bu sunucuya işaret etmiyor" yazar). Kendi `index.html` ya da
`index.php` dosyanızı yükleyene kadar ziyaretçiler Serfex'in varsayılan
sayfasını görür.

Bir sitenin sayfasında:

- **Dosya Yöneticisi:** yükleme (dosya başına 2 GB'a kadar), düzenleme, zip
  ve açma, izinler. Silinen dosyalar 7 gün çöp kutusunda kalır.
- **FTP hesapları:** sitenin bir klasörüne SFTP hesapları. Bir SFTP
  programıyla (FileZilla, WinSCP, Cyberduck) 22 numaralı porta bağlanın;
  klasik FTP desteklenmez. Parola bir kez gösterilir.
- **SSH erişimi** (paketinizde varsa): sitenin kendi kullanıcısı için SFTP'yi
  açın, açık anahtarınızı (`~/.ssh/id_ed25519.pub`) ekleyin ve
  `sftp <kullanıcı>@<alan adınız>` ile bağlanın. Ana klasörünüze düşersiniz ve
  yalnızca kendi dosyalarınızı görürsünüz. Parola isteğe bağlıdır ve bir kez
  gösterilir. Kabuk erişimini sunucu yöneticiniz açar.
- **Bağlantı bilgileri:** sunucu adresi, SFTP portu ve hesapları, sitenin
  veritabanları ve kullanıcıları, SSH komutları tek sayfada, kopyalanmaya
  hazır. Parolalar saklanmaz; her bölümün yanındaki bağlantıdan yenisi
  belirlenir.
- **PHP ve web sunucusu:** PHP sürümü ve `memory_limit`,
  `upload_max_filesize` gibi ayarlar.
- **Zamanlanmış görevler:** bir komutu her gece, her saat ya da birkaç
  dakikada bir çalıştırın.
- **Git dağıtımı:** sitenin klasörü (varsayılan public_html) bir Git
  deposundan gelir. Herkese açık bir `https://` adresi, erişim anahtarıyla
  açılan bir depo ya da Serfex'in oluşturduğu dağıtım anahtarıyla bir SSH
  adresi bağlayın; sonra **Dağıt**'a basın ya da webhook adresini depoya
  ekleyip her push'ta dağıtın. GitHub, GitLab veya Bitbucket hesabınızı
  bağladıysanız (adınız → Git hesapları, sunucu sunuyorsa) depoyu ve dalı
  listeden seçersiniz; Serfex dağıtım anahtarını ve webhook'u depoya kendisi
  ekler. Depoda olmayan dosyalarınız silinmez; önceki her dağıtıma
  dönülebilir, ilk dağıtım geri alınabilir.
- **İstatistikler:** ziyaretçiler, sayfalar, bant genişliği, yönlendirenler,
  ülkeler ve botlar.
- **Günlükler:** sitenin erişim ve hata günlükleri.
- **Alan adları:** alt alan adları, aynı siteyi açan başka alan adları ve
  yönlendirmeler.
- **Hata sayfaları:** kendi 403, 404 ve 50x sayfalarınız.
- Paketiniz güvenlik araçlarını içeriyorsa **SSL sertifikası**, **Güvenlik
  taraması**, **Parola korumalı klasörler** ve **web uygulaması güvenlik duvarı**.

## Alan Adları ve DNS

DNS'iniz bu sunucudaysa alan adlarınızın kayıtları: A, AAAA, CNAME, MX, TXT,
NS, SRV ve CAA. Kullanmak için alan adı firmanızda ad sunucularını hosting
firmanızın verdikleriyle değiştirin.

## E-posta

Sitelerinizin alan adlarında e-posta açılır. Bir alan adının sayfasında:

- Kotasıyla **posta kutusu oluşturun**. **Webmail** kutuyu tarayıcıda parolasız açar.
- Yönlendirme, otomatik yanıt ve başka adreslere yönlendiriciler; bilinmeyen
  adresleri toplayan bir "tümünü yakala" dahil.
- **E-posta istemcisi ayarları:** posta sunucusunun adında IMAP 993, POP3 995
  ve SMTP 465 ya da 587; kullanıcı adı tam e-posta adresidir. Thunderbird ve
  Outlook bunları kendileri bulur.
- **DNS kayıtları:** postanızın ihtiyaç duyduğu MX, SPF, DKIM ve DMARC
  kayıtları. DNS'iniz başka yerdeyse gösterilenleri orada ekleyin.

## Veritabanları

Bir site için kullanıcısıyla birlikte **veritabanı oluşturun** (adlar hesabınızın
önekiyle başlar). Parola bir kez gösterilir; o anda kopyalayın.
**phpMyAdmin** ya da **Adminer** veritabanını tarayıcıda parolasız açar. Bir
kullanıcıya kendi bilgisayarınızın adresinden bağlanma izni verilebilir.

## Laravel

Paketiniz Laravel içeriyorsa: Git deposundan uygulama kurun ya da yeni proje
başlatın, kesintisiz dağıtın, geri dönün, Artisan çalıştırın; kuyrukları,
zamanlayıcıyı ve `.env`'yi yönetin, geliştiricilerinize erişim verin.
Bkz. [Laravel kılavuzu](../laravel.md) (İngilizce).

## WordPress

Site → **WordPress**: WordPress'i tek tıkla kurun (veritabanı ve yönetici
hesabı sizin için hazırlanır) ya da sitede zaten olanı yönetin. WordPress'i,
eklentileri ve temaları güvenle güncelleyin: her güncellemeden önce geri dönüş
noktası alınır, bozulan site kendiliğinden eski hâline döner ve son
güncellemeyi 7 gün içinde geri alabilirsiniz. **WordPress'e giriş yap**
yönetim panelini parolasız açar. Bakım modu ve önerilen güvenlik ayarları
birer tıklık.

## Yedekler

Siteleriniz sunucunun zamanlanmış yedeklerine dahildir. **Yedek al** ile
kendiniz de alabilirsiniz (paketinizin sınırına kadar); **Geri yükle**
dosyaları, veritabanlarını, e-postayı ya da DNS'i bunlardan birinden geri
getirir; önce mevcut durumu yedekler, böylece geri alabilirsiniz.

## Komut satırı

`serfex` komut satırı sizin hesabınızla da çalışır:

```sh
curl -fsSL https://<panel adresi>:8443/cli/install.sh | sh
serfex login <panel adresi>:8443
serfex sites list
```

Bkz. [Komut satırı başvurusu](cli.md).
