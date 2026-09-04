# Değişiklik Günlüğü

Bu dosyanın biçimi [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/) esas alınarak,
sürüm numaralandırması [Semantic Versioning](https://semver.org/lang/tr/) kuralına göre tutulur.

---

## [1.1.0] — 2026-09-04

### Eklendi

- **Veritabanı bilgileri artık `.env` dosyasından okunabiliyor.**
  Daha önce tek yol `system/config.php` dosyasını elle düzenlemekti — ve
  o dosya depoda durur: yazdığınız parola hem GitHub'a gider hem de ilk
  dağıtımda depodaki sürümle değiştirilerek kaybolur.

  Depo köküne `.env.example` eklendi; kopyalayıp `.env` yapmanız yeterli.
  `.env` zaten `.gitignore` içindeydi.

  Değer arama sırası: `.env` → sunucunun gerçek ortam değişkeni → bu
  dosyadaki varsayılan. (`config.local.php` destekleyen depolarda o hâlâ
  en önde gelir; eski kurulumlar olduğu gibi çalışır.)

  Uygulama kodu değişmedi: `cy_env()` yardımcısı bilerek `getenv()` ile
  aynı sözleşmeyi taşır (değer ya da `false`), böylece mevcut `?:` ve
  `!== false` kalıplarının hiçbirine dokunulmadı.

  README'ler (TR + EN) buna göre elden geçirildi:
  kurulum adımlarına `.env` basamağı, dosya yapısı ağacına `.env.example`,
  ayrıntılı bir "Ortam değişkenleri" bölümü ve tüm değişkenlerin tablosu
  eklendi. Öncelik zincirini anlatan satırlar da düzeltildi — `.env`
  eklendikten sonra eski zincir (`config.local.php → ortam değişkeni →
  varsayılan`) artık eksik kalıyordu; yani belge, kodun yaptığı işi
  yanlış anlatıyordu.

### Değiştirildi

- **Depo adı `PHP-MySQL-Soft-Delete-Yumusak-Silme-Cop-Kutusu-PDO-Ajax`
  yerine `soft-delete-trash` oldu.** Uzun ad adres satırında okunmuyordu ve
  klasör adıyla eşleşmediği için vitrindeki bağlantılar kırılıyordu.
  Klon, ZIP, issue ve yerel kurulum adresleri buna göre güncellendi.
  GitHub eski adresi yenisine yönlendirir; eski bağlantılar kırılmaz.

- **Zaman dilimi artık açıkça sabitleniyor.** `system/config.php` içinde
  `APP_TIMEZONE` (varsayılan `Europe/Istanbul`) tanımlanıp
  `date_default_timezone_set()` çağrılıyor; ortam değişkeniyle
  değiştirilebilir.

  **Ölçülen sorun:** XAMPP'ın `php.ini` dosyasındaki `date.timezone`,
  MySQL'in kullandığı sistem diliminden farklı olabiliyor. Test
  makinesinde PHP `Europe/Berlin`, MySQL ise `Europe/Istanbul`
  kullanıyordu ve aynı anı anlatan iki satır bir saat farklı görünüyordu:

  ```
  worker günlüğü (PHP date)  : 14:03:17
  veritabanı  (MySQL NOW())  : 15:03:17
  ```

  Zaman **aritmetiği** bu depoda bilinçli olarak SQL tarafında yapıldığı
  için (`NOW()`, `INTERVAL`, `TIMESTAMPDIFF`) hesaplar zaten doğruydu;
  kayan şey PHP'nin ekrana ve günlüğe bastığı saatti. Ama demoyu deneyen
  biri için bu, "sistem yanlış çalışıyor" gibi görünüyordu.

### Düzeltildi

- **"Canlı Demo" bağlantısı kırıktı.** README'lerdeki en görünür düğme
  `…/kutuphane/uygulama/PHP-MySQL-Soft-Delete-Yumusak-Silme-Cop-Kutusu-PDO-Ajax-main/` adresine gidiyordu; o adres **404**
  döndürüyordu. Vitrindeki gerçek adres `…/kutuphane/uygulama/soft-delete-trash/`.
  Her iki dildeki README'de tüm geçtiği yerler düzeltildi ve adreslerin
  200 döndüğü doğrulandı.

---

## [1.0.0] — 2026-08-31

İlk genel sürüm. Yumuşak silme çekirdeği, çöp kutusu, cron temizliği, arayüz ve belgelendirme üretime hazır durumda.

### Eklendi

**Yumuşak silme çekirdeği**
- `deleted_at` kalıbı. Kayıt silinmez, işaretlenir; `NULL` aktif, dolu çöpte demektir.
- **Tek kapsam kaynağı**: `active_scope()` ve `trash_scope()`. Bu koşul listeye, sayaca ve tekil getirmeye ayrı ayrı kopyalanırsa, biri güncellenip diğeri unutulduğunda liste 11 kayıt gösterirken sayaç 20 der — kullanıcı hangisine inanacağını bilemez.
- Kapsam koşulu her `UPDATE`/`DELETE`'in `WHERE`'inde bulunur. PHP'de kontrol edip sonra koşulsuz sorgu çalıştırmak, iki adım arasında kaydın durumunun değişmesine açık bir pencere bırakırdı.
- **Aktif kayıtlar arasında benzersizlik**: üretilen `is_active` sütunu (`deleted_at IS NULL` iken `1`, aksi hâlde `NULL`) ve `UNIQUE (title, is_active)`. `NULL` değerler UNIQUE indekste çakışmadığı için çöpteki kayıt yeni kaydın yolunu tıkamaz. Düz bir `UNIQUE (title)` olsaydı, bir notu çöpe atıp aynı başlıkla yenisini oluşturamazdınız — kullanıcı sebebini asla anlayamazdı.
- Çöpteki kayıt **düzenlenemez**; kural hem sunucuda (`active_scope()` ile sınırlı `UPDATE`) hem arayüzde (`editable` alanı) durur. İstemcinin unutması sunucuyu bağlamaz.
- Kalıcı silme yalnızca **çöpteki** kayda uygulanır. "Önce çöpe at" adımı zorunludur; tek tıkla kalıcı silme, yumuşak silmenin varlık sebebini ortadan kaldırırdı.

**Çöp kutusu**
- Kalan gün rozeti üç durumu ayırır: normal, uyarı (kalan < `TRASH_WARN_DAYS`) ve **temizlenmeye hazır**.
- Kalan gün ve "süresi doldu" bilgisi **SQL'de** hesaplanır. PHP'nin `time()` değeriyle hesaplamak, PHP ile MySQL'in saat diliminin eşit olmasını varsayardı; eşit olmadıklarında liste ile detay farklı gün sayısı gösterir ve hangisinin doğru olduğu belli olmaz. `deleted_at` MySQL'in `NOW()` değeriyle yazıldığına göre kıyas da MySQL'de yapılmalıdır.
- **Geri alma çakışması**: aynı başlıkta aktif bir kayıt varsa `409` döner ve `conflict_id` taşır. Kontrol ile güncelleme **tek transaction** içinde, `FOR UPDATE` kilidiyle yapılır; UNIQUE indeksin kendisi son savunma hattı olarak yerinde durur.
- Çakışma kullanıcıya **düğmeye basmadan önce** söylenir: detay penceresi kaydı getirirken çakışmayı da sorar, varsa "Geri al" pasif gelir ve sebebi yazar.
- **Hepsini geri al** çakışanları atlar ve kaç tanesinin atlandığını söyler. "Hepsi ya da hiçbiri" burada yanlış olurdu: tek bir başlık çakışması yüzünden 20 kaydın geri alınmaması kullanıcının işine yaramaz. Tek `UPDATE … WHERE NOT EXISTS` ile yapılır; satır satır dönüp tek tek denemeye gerek kalmaz.
- **Çöpü boşalt**, `expected_count` sözleşmesiyle korunur: istemci kullanıcıya gösterdiği sayıyı geri gönderir, sunucu kendi saydığıyla kıyaslar, tutmuyorsa `409` döner ve hiçbir şeye dokunmaz. Sayım ve silme aynı transaction içindedir.

**Otomatik temizlik**
- `bin/purge.php` — cron / Windows Görev Zamanlayıcı betiği. Çıktısı tek satır, günlüğe yazılmaya uygun.
- Web'den çalıştırılamaz: dosyanın başında `PHP_SAPI !== 'cli'` kontrolü **ve** `bin/.htaccess` içinde `Require all denied` — iki katman. `.htaccess`'in okunmadığı bir sunucuda (nginx) PHP kontrolü çalışır.
- Tekrar çalıştırmak güvenlidir; silinecek bir şey yoksa `0` döner.
- Arayüzdeki **"Süresi dolanları temizle"** düğmesi ile cron **aynı** `purge_expired_trash()` fonksiyonunu çağırır. İki ayrı temizleme kodu yazmak, birini düzeltip diğerini unutmanın kesin yoludur — ve unutulan taraf cron olursa fark hiç görünmez.
- **Süre dolunca hiçbir şey kendiliğinden olmaz.** Arayüz bu yüzden "silinecek" değil "temizlenmeye hazır" der: cron kurulmamışsa o kayıtlar sonsuza dek çöpte durur ve kullanıcıya tutulamayacak bir söz verilmemiş olur.

**Uç noktalar**
- On uç: `list`, `stats`, `fetch`, `save`, `soft_delete`, `restore`, `restore_all`, `delete_forever`, `empty_trash`, `purge_expired`.
- Hepsi **POST** ister. `GET` ile yapılan bir silme; tarayıcının ön getirmesi (prefetch), bir arama motoru robotu ya da sayfadaki bir `<img>` etiketi tarafından tetiklenebilir.
- Sayaç şeridinin beş sayısı **tek sorgudan** gelir. Beş ayrı `COUNT` beş ayrı anlık görüntü demektir; sorgular arasında bir kayıt çöpe taşınırsa ekranda "11 aktif + 9 çöpte = 21 toplam" gibi imkânsız bir satır belirir.
- Doğru durum kodları: 200 / 400 / 403 / 404 / 405 / 409 / 422 / 429 / 500.
- Üç ayrı hız sınırı kovası: `read` (ucuz, sık), `write` (tek satır etkiler), `destroy` (**geri alınamaz**, en dar kova).

**Arayüz**
- Marka tasarım kalıbına taşındı: gradyan başlıklı kartlar, sayaç şeridi, toast bildirimleri, `aria-live` alanları.
- **Beş sayaçlı şerit**: aktif, çöpte, son günlerinde, temizlenmeye hazır, tabloda toplam. Son ikisi sıfırdan büyükse uyarı rengine geçer.
- Tarayıcının `confirm()` kutusu yerine **markayla uyumlu onay penceresi**. Sebebi yalnızca görünüm değil: `confirm()` metni biçimlendiremez, kalıcı silme ile çöpe taşımayı görsel olarak ayıramaz ve bazı tarayıcılarda kullanıcı tarafından kapatılabilir.
- Onay metni **geri alınabilir/alınamaz** ayrımını açıkça yazar. "Çöpe taşı" ile "Kalıcı sil" aynı dille konuşmaz.
- İki sekme aynı uç noktadan beslenir; farkı yalnızca `view` parametresidir. Sunucudaki `active_scope()` / `trash_scope()` ayrımının istemci karşılığı.
- Not detay penceresi ve **paylaşılabilir derin bağlantı** (`#not-13`). Bağlantıyla açılan sayfa, kayıt çöpteyse o sekmeye kendisi geçer.
- Arama (250 ms bekleme) başlıkta ve içerikte; her tuşta istek atmak sunucuyu yorar ve yanıtlar sırasız dönerse liste titrer.
- Çift gönderim koruması; kaydet düğmesi istek boyunca kilitli.
- Doğrulama hataları **alan adıyla** gelir ve doğru kutunun altına yazılır.
- İkon butonlar `opacity: 0` ile gizlenmiyor: dokunmatik ekranda hover yoktur ve o düğmelere hiç ulaşılamazdı.

**Veri**
- `cy_trash.sql` veritabanını kendisi oluşturur, başında `SET NAMES utf8mb4` bulunur.
- **11 aktif + 9 çöpteki not.** Zamanlar `NOW() - INTERVAL` ile üretilir; dosya ne zaman içe aktarılırsa aktarılsın kayıtlar "son birkaç gün/hafta" içinde görünür.
- Çöp kutusu bilerek **her yaş grubunu** temsil eder: yeni silinmiş (30 gün kaldı), son günlerine gelmiş (5 ve 2 gün — uyarı rengi) ve süresi dolmuş (3 kayıt).
- **Başlık çakışması bilerek kuruldu**: "Alışveriş listesi" hem aktif hem çöpte bulunur. Çöptekini geri almaya çalışmak `409` döndürür — yumuşak silmenin en ilginç kenar durumu demoda görünür olsun diye.
- `AUTO_INCREMENT` ileri alındı: bu uygulamada kayıt gerçekten silinir (kalıcı silme ve purge) ve numaralar boşalır; yeni bir kayıt silinmiş bir numarayı devralırsa `#not-42` bağlantısı yanlış kayda gider.

**Mobil ve erişilebilirlik**
- Dar ekranda **yatay kaydırma yok** (360px'te iki görünümde de ölçüldü: `scrollWidth == clientWidth`, taşan eleman yok). Tarih sütunu gizlenir; durum rozeti başlığın altına taşınır, tarih detay penceresinde korunur.
- Durum rozetleri renk **ve** metin taşır — renk tek başına anlam taşımaz.
- Tablo satırları `tabindex="0"` taşır ve klavyeyle açılabilir; dokunma hedefleri en az 32-44px.

**Altyapı**
- `system/config.local.php` desteği. Bu projede fazladan bir sebep var: `bin/purge.php` **ayrı bir süreçtir** ve aynı yapılandırmayı okur; künyeyi iki yerde tutmak, birini güncelleyip diğerini unutmanın kesin yoludur — ve unutulan taraf cron olursa temizlik sessizce durur.
- `APP_DEBUG` sunucu adından türetilir; canlı bir alan adında kendiliğinden kapanır. CLI'da her zaman açık sayılır: orada çıktıyı yalnızca cron'u kuran yönetici görür.
- CLI'da oturum açılmaz; `bin/purge.php`'nin çerezi yoktur ve `session_start()` orada yalnızca boş bir dosya bırakırdı.
- `JSON_INVALID_UTF8_SUBSTITUTE`: not gövdesi kopyala-yapıştır ile gelen bozuk bir bayt içerebilir; o bayrak olmadan `json_encode()` sessizce `false` döner ve tarayıcı **boş** gövde alır — liste boş görünür, hata mesajı da dahil her şey kaybolur.

**Belgelendirme**
- Türkçe ve İngilizce README (canlı demo bölümü, 60 saniyelik deneme tablosu, beş kritik karar, güvenlik tablosu, cron kurulumu, API referansı, şema kararları, SSS, üretim kontrol listesi, sorun giderme).
- Ekran görüntüleri: aktif notlar, çöp kutusu, not detayı (geri alma çakışması), mobil görünüm.

### Düzeltildi

- **Arama tümüyle çalışmıyordu.** `title LIKE :q OR body LIKE :q` sorgusunda aynı adlı yer tutucu iki kez kullanılıyordu; arama kutusuna bir şey yazıldığı anda sorgu `HY093 Invalid parameter number` ile patlıyor ve liste boş dönüyordu. `PDO::ATTR_EMULATE_PREPARES = false` iken sorgu MySQL'e gerçek prepared statement olarak gider ve adlar konumsal `?`'lere çevrilir; aynı ad ikinci kez geçemez. Emülasyon **açıkken çalışıp kapalıyken** patlayan, bu yüzden de gözden kaçması kolay bir hatadır. `:q1` / `:q2` olarak ayrıldı.
- **Liste özeti kelimenin ortasından kesiliyordu.** Ham `mb_substr()` hiçbir işaret bırakmadığı için ekranda "…aynı başlıkta iki a" gibi bozuk görünen bir cümle kalıyor, kullanıcı metnin devamı olduğunu anlamıyordu. Artık son boşluğa geri sarılıyor ve üç nokta ekleniyor.
- **Çakışma yalnızca `23000` hata koduna bakılarak ayırt ediliyordu.** Aynı kod yabancı anahtar ve `NOT NULL` ihlallerinde de gelir; hepsine "bu başlık kullanımda" demek yanlış olurdu. Artık indeks adına (`uq_notes_active_title`) bakılıyor.
- **Kök `.htaccess` bütün `.md` dosyalarını kapatıyordu**, README dahil. Kütüphane vitrini README'yi içerik olarak okuduğu için örneğin anlatımı boş görünürdü. `README*.md` için istisna eklendi; `CHANGELOG.md` kapalı kaldı. `DirectoryIndex index.php` satırı da eklendi.
- **`bin/` klasörü için `.htaccess` yoktu**; cron betiği yalnızca kendi içindeki `PHP_SAPI` kontrolüne dayanıyordu. İkinci katman eklendi.
- Geri alma kontrolü ile güncelleme **ayrı ayrı** çalışıyordu; ikisi arasına giren bir istek çakışmaya yol açabilirdi. Tek transaction ve `FOR UPDATE` kilidine alındı.
- `APP_DEBUG` elle `true` bırakılmıştı; canlıya alındığında SQL metni ve dosya yolları yanıtta görünürdü. Artık sunucu adından türetiliyor.
- Kullanılmayan DataTables dosyaları depodan çıkarıldı (bu örnek DataTables kullanmıyor).

### Güvenlik

- **Yazma işlemleri yalnızca POST** ve CSRF jetonu taşır; jeton `hash_equals()` ile sabit zamanda doğrulanır.
- **SQL Injection:** tüm sorgular prepared statement, `EMULATE_PREPARES = false`; `LIKE` jokerleri (`\`, `%`, `_`) kaçışlanır.
- **XSS:** not başlığı ve gövdesi kullanıcı verisidir; sunucuda `e()`, istemcide `esc()`. Hiçbir kullanıcı verisi `.html()` ile basılmaz.
- **Kalıcı silme ayrı bir hız sınırı kovasındadır** (15 istek / 60 sn): geri alınamaz işlemler, geri alınabilir olanlarla aynı sınırı paylaşmamalı.
- **Cron betiğinin web'den çalıştırılması** iki katmanla engellendi.
- `system/.htaccess` **beyaz listedir**: yalnızca `ajax.php` dışarıya açıktır. Kara liste yazsaydık, projeye yarın eklenen her yeni dosya varsayılan olarak açık olurdu.
- `.htaccess`: dizin listeleme kapalı; `.sql`, `.md`, `.json`, `.log`, `.ini`, `.bak`, `.example` kapalı — `README*.md` bilinçli istisnadır.
- `X-Frame-Options: SAMEORIGIN` — görünmez bir çerçeveye alınmış sayfada kullanıcıya farkında olmadan "Çöpü boşalt" tıklatılamaz.
- **KVKK/GDPR notu:** yumuşak silme, veriyi saklamaya devam etmek demektir. Kişisel veri söz konusuysa `TRASH_RETENTION_DAYS` ve çalışan bir cron bir hukuki gerekliliktir, bir tercih değil.

[1.0.0]: https://github.com/CilginYazilim/soft-delete-trash/releases/tag/v1.0.0
