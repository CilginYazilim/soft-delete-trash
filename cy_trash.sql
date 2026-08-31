-- =====================================================================
--  cilginyazilim.com – Yumuşak Silme ve Çöp Kutusu
--    mysql -u root -p < cy_trash.sql
-- =====================================================================

-- ---------------------------------------------------------------------
--  ÖNEMLİ: İSTEMCİ KARAKTER SETİ
-- ---------------------------------------------------------------------
--  Bu satır olmadan `mysql -u root -p < dosya.sql` komutu, İSTEMCİNİN
--  varsayılan karakter setini kullanır. Windows'ta bu genellikle latin1
--  ya da cp1254'tür; dosyadaki UTF-8 baytları latin1 sanılıp yeniden
--  kodlanır ve veri ÇİFT KODLANMIŞ (mojibake) olarak girer:
--
--      "savunması"  →  "savunmasÄ±"
--
--  Hata sessizdir: kurulum başarıyla biter, tablolar oluşur, hiçbir
--  uyarı çıkmaz. Sorun ancak ekranda bozuk harfler görününce fark
--  edilir.
--
--  SET NAMES, istemciye "gönderdiğim baytlar utf8mb4" der ve dosyanın
--  hangi istemciyle içe aktarıldığından bağımsız olarak doğru sonucu
--  garanti eder.
-- ---------------------------------------------------------------------
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `cy_trash`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cy_trash`;

DROP TABLE IF EXISTS `notes`;

-- ---------------------------------------------------------------------
--  notes
-- ---------------------------------------------------------------------
--    deleted_at NULL      → AKTİF kayıt
--    deleted_at NOT NULL  → çöp kutusunda (o tarihte silindi)
--
--  NEDEN DATETIME, TIMESTAMP DEĞİL?
--  TIMESTAMP'in MySQL'deki üst sınırı 2038'dir ve oturum saat dilimine
--  göre dönüştürülür. deleted_at bir "olay anı"dır ve created_at ile
--  aynı muameleyi görmesi gerekmez; DATETIME sürprizsizdir.
--
--  is_active : ÜRETİLEN (generated) sütun. deleted_at NULL iken 1,
--  dolu iken NULL üretir. Aşağıdaki UNIQUE(title, is_active) sayesinde:
--    · Aynı başlıkta yalnızca BİR aktif not olabilir  (1 = 1 çakışır)
--    · Çöpte kaç tane olursa olsun sorun yok          (NULL'lar çakışmaz)
--
--  Bu, yumuşak silmenin en çok atlanan ayrıntısıdır. title üzerinde
--  DÜZ bir UNIQUE indeks olsaydı, bir notu çöpe atıp aynı başlıkla
--  yenisini oluşturamazdınız — sildiğiniz bir şey hâlâ yolunuzu
--  tıkardı ve kullanıcı sebebini asla anlayamazdı.
--
--  VIRTUAL, STORED değil: değer diskte tutulmaz, indeks için okunurken
--  hesaplanır. Sütunun kendisine hiç bakmıyoruz; yalnızca indeksin
--  varlık sebebi.
-- ---------------------------------------------------------------------
CREATE TABLE `notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150) NOT NULL,
  `body`       TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `is_active`  TINYINT GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notes_active_title` (`title`, `is_active`),
  -- Her liste sorgusu deleted_at ile filtreler; bu indeks o filtrenin
  -- indeksidir. Sıralama sütunu ikinci olarak eklendi: MySQL o zaman
  -- hem filtreyi hem sıralamayı tek indeksten karşılar.
  KEY `idx_notes_deleted` (`deleted_at`, `id`),
  KEY `idx_notes_active_updated` (`is_active`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  ÖRNEK VERİ
-- ---------------------------------------------------------------------
--  Zamanlar NOW() - INTERVAL ile üretilir: dosya ne zaman içe
--  aktarılırsa aktarılsın kayıtlar "son birkaç gün/hafta" içinde
--  görünür. Sabit tarih yazılsaydı demo birkaç ay sonra "her şeyin
--  süresi dolmuş" hâlde açılırdı.
--
--  Çöp kutusu BİLEREK her yaş grubunu temsil eder:
--    · yeni silinmiş           → bol bol vakti var
--    · son günlerine gelmiş    → uyarı rengi (kalan gün < 7)
--    · süresi dolmuş           → "temizlenmeye hazır", purge onu siler
--
--  ÖZEL DURUM — BAŞLIK ÇAKIŞMASI:
--  "Alışveriş listesi" başlığı hem AKTİF bir notta hem de ÇÖPTEKİ bir
--  notta var. Çöptekini geri almaya çalışmak 409 döndürür. Bu, bir
--  hata değil; yumuşak silmenin en ilginç kenar durumudur ve demoda
--  görünür olması için bilerek kuruldu.
-- =====================================================================
INSERT INTO `notes` (`title`, `body`, `created_at`, `updated_at`, `deleted_at`) VALUES

-- --- AKTİF NOTLAR ----------------------------------------------------
('Yumuşak silme nedir?',
 'Kayıt tablodan SİLİNMEZ, silinmiş olarak İŞARETLENİR: deleted_at sütunu dolar. Böylece yanlışlıkla silinen bir kayıt geri gelebilir, silinme anı kayıt altında kalır ve ilişkili tablolardaki bağlantılar kopmaz. Bedeli, her sorguya bir koşul eklemektir.',
 NOW() - INTERVAL 41 DAY, NOW() - INTERVAL 5 DAY, NULL),

('Tek kapsam kaynağı',
 '"Aktif kayıt" tanımı TEK yerde durmalıdır. Bu koşul listeye, sayaca ve tekil getirmeye ayrı ayrı kopyalanırsa, biri güncellenip diğeri unutulduğunda kullanıcı çöpe attığını sandığı kaydı hâlâ listede görür. Bu projede active_scope() ve trash_scope() o tek kaynaktır.',
 NOW() - INTERVAL 38 DAY, NOW() - INTERVAL 12 DAY, NULL),

('Benzersizlik tuzağı',
 'title üzerinde düz bir UNIQUE indeks olsaydı, "Alışveriş listesi" başlıklı bir notu çöpe atıp aynı başlıkla yenisini oluşturamazdınız. Çözüm, benzersizliği yalnızca AKTİF kayıtlar arasında kurmaktır: üretilen bir is_active sütunu ve UNIQUE(title, is_active). NULL değerler UNIQUE indekste çakışmaz.',
 NOW() - INTERVAL 33 DAY, NOW() - INTERVAL 2 DAY, NULL),

('Alışveriş listesi',
 'Kahve, süt, defter, kalem. Bu başlıkta ÇÖP KUTUSUNDA da bir not var; onu geri almayı deneyin. Sunucu 409 döndürür, çünkü aynı başlıkta iki aktif not olamaz.',
 NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 4 HOUR, NULL),

('Onaydaki sayı bir sözleşmedir',
 '"Çöp kutusundaki 12 not kalıcı silinecek" onayı, 14 kaydı silmeye yetki VERMEZ. Onay penceresi açıkken başka bir sekmede iki kayıt daha çöpe atılmış olabilir. İstemci gördüğü sayıyı sunucuya geri gönderir; sunucu kendi saydığıyla kıyaslar, tutmuyorsa hiçbir şeye dokunmaz.',
 NOW() - INTERVAL 29 DAY, NOW() - INTERVAL 29 DAY, NULL),

('Silme neden POST ile yapılır?',
 'GET ile yapılan bir silme; tarayıcının ön getirmesi (prefetch), bir arama motoru robotu ya da sayfadaki bir img etiketi tarafından tetiklenebilir. "Linke tıklamadım ama kayıt silindi" hatasının kaynağı budur. Yazma işlemleri POST ister ve CSRF jetonu taşır.',
 NOW() - INTERVAL 24 DAY, NOW() - INTERVAL 24 DAY, NULL),

('Süre dolunca ne oluyor?',
 'Hiçbir şey — kendiliğinden. Saklama süresi dolmuş bir kayıt yalnızca "temizlenmeye hazır" hale gelir; silinmesi bin/purge.php çalıştığında olur. Bu yüzden arayüzde "silinecek" değil "temizlenmeye hazır" yazıyor: cron kurulu değilse o kayıtlar sonsuza dek çöpte durur.',
 NOW() - INTERVAL 19 DAY, NOW() - INTERVAL 3 DAY, NULL),

('Haftalık plan',
 'Pazartesi sunum, Çarşamba deploy, Cuma retro. Ayın son haftası performans testleri.',
 NOW() - INTERVAL 14 DAY, NOW() - INTERVAL 14 DAY, NULL),

('Kitap önerileri',
 'Temiz Kod, Pragmatik Programcı, SICP, Designing Data-Intensive Applications.',
 NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 11 DAY, NULL),

('Toplantı notları',
 'API sürüm 2 geriye dönük uyumlu kalacak. Eski uçlar altı ay boyunca çalışmaya devam edecek, yanıtlara Deprecation başlığı eklenecek.',
 NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY, NULL),

('Fikirler',
 'Çöp kutusu için otomatik temizlik cron''u. Geri alma bildiriminde "geri al" düğmesi. Toplu seçim ve toplu çöpe taşıma.',
 NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 30 MINUTE, NULL),

-- --- ÇÖP KUTUSU: yeni silinmişler ------------------------------------
('Eski taslak',
 'Bu not az önce çöpe atıldı; geri almak için bolca vakit var. Geri alma düğmesi deleted_at değerini NULL yapar, başka hiçbir şeye dokunmaz.',
 NOW() - INTERVAL 27 DAY, NOW() - INTERVAL 22 DAY, NOW() - INTERVAL 2 HOUR),

('Alışveriş listesi',
 'DİKKAT: aynı başlıkta AKTİF bir not var. Bu notu geri almaya çalışın — sunucu 409 döndürecek ve sebebini söyleyecek. Yumuşak silmenin en ilginç kenar durumu budur.',
 NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 31 DAY, NOW() - INTERVAL 1 DAY),

('Vazgeçilen özellik',
 'Notlara etiket ekleme özelliği ertelendi. Karar gerekçesi: etiketler ayrı bir tablo ve ayrı bir arayüz gerektiriyor; bu örneğin anlattığı konuyu gölgede bırakırdı.',
 NOW() - INTERVAL 35 DAY, NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 4 DAY),

('Deneme notu',
 'Silinip geri alınmak üzere bırakılmış sıradan bir kayıt. Geri alın, sonra tekrar çöpe atın; deleted_at değeri her seferinde yeniden yazılır ve sayaç sıfırdan başlar.',
 NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 18 DAY, NOW() - INTERVAL 9 DAY),

-- --- ÇÖP KUTUSU: son günlerine gelmişler (uyarı rengi) ---------------
('Q2 bütçe taslağı',
 'Yerini Q3 taslağı aldı. Kalan gün sayısı 7''nin altına düştüğü için satır uyarı rengine geçer — kullanıcı son günü 0 görünce değil, ÖNCE fark etsin.',
 NOW() - INTERVAL 60 DAY, NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 25 DAY),

('Eski parola politikası',
 'Yerini yeni politika aldı. Bu kayıt birkaç gün içinde temizlenmeye hazır hale gelecek.',
 NOW() - INTERVAL 70 DAY, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 28 DAY),

-- --- ÇÖP KUTUSU: süresi dolmuşlar (purge bunları siler) --------------
('Süresi dolmuş taslak',
 'Bu not 31 gündür çöpte. Saklama süresi (30 gün) doldu; artık "temizlenmeye hazır". Ama hâlâ duruyor — çünkü silme işi cron''undur, sürenin kendisi değil.',
 NOW() - INTERVAL 90 DAY, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 31 DAY),

('2025 arşiv notu',
 'Çok eski bir kayıt. purge.php ilk çalıştığında bu da gidecek. Arayüzdeki "Süresi dolanları temizle" düğmesi cron ile AYNI fonksiyonu çağırır.',
 NOW() - INTERVAL 200 DAY, NOW() - INTERVAL 120 DAY, NOW() - INTERVAL 47 DAY),

('Kapatılan proje',
 'Üç aydır çöpte. Kalıcı silme geri alınamaz; bu yüzden ayrı bir hız sınırı kovasında ve ayrı bir onay penceresinin arkasında durur.',
 NOW() - INTERVAL 240 DAY, NOW() - INTERVAL 180 DAY, NOW() - INTERVAL 88 DAY);

-- ---------------------------------------------------------------------
--  AUTO_INCREMENT'i ileri al.
-- ---------------------------------------------------------------------
--  Bu uygulamada kayıt GERÇEKTEN silinir (kalıcı silme ve purge).
--  Numaralar boşalır. Sayaç ileri alınmazsa yeni bir not, kalıcı
--  silinmiş bir notun numarasını devralabilir; o numaraya işaret eden
--  paylaşılmış bir bağlantı (#not-42) yanlış kayda gider.
-- ---------------------------------------------------------------------
ALTER TABLE `notes` AUTO_INCREMENT = 214;
