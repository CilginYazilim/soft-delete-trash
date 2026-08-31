<?php
/**
 * =====================================================================
 *  YAPILANDIRMA
 *  cilginyazilim.com – Yumuşak Silme ve Çöp Kutusu
 * =====================================================================
 */

declare(strict_types=1);

/*  Doğrudan çağrılmaya karşı ilk katman. İkinci katman system/.htaccess
 *  beyaz listesidir. Bu dosya her çağrıldığında bir VERİTABANI BAĞLANTISI
 *  açar; kimlik doğrulaması olmadan tetiklenebilen ve iş yapan her adres
 *  ucuz bir hizmet dışı bırakma kaldıracıdır. */
if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

/* =====================================================================
 *  OTURUM  (CSRF jetonu burada durur)
 * ---------------------------------------------------------------------
 *  CLI'da oturum açılmaz: bin/purge.php bir cron betiğidir, çerezi
 *  yoktur ve session_start() orada yalnızca boş bir dosya bırakır.
 * ================================================================== */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* =====================================================================
 *  YEREL AYAR DOSYASI
 * ---------------------------------------------------------------------
 *  system/config.local.php .gitignore içindedir: depoya gitmez ve
 *  deploy sırasında SİLİNMEZ. Canlı künye oraya yazılır; buradaki
 *  define'lar yalnızca o dosya YOKSA devreye girer (define bir kez
 *  tanımlanır, ilk tanım kazanır).
 *
 *  Bu projede fazladan bir sebep var: bin/purge.php AYRI BİR SÜREÇTİR
 *  ve aynı yapılandırmayı okur. Künyeyi iki yerde tutmak, birini
 *  güncelleyip diğerini unutmanın kesin yoludur — ve unutulan taraf
 *  cron olursa temizlik sessizce durur.
 * ================================================================== */
$yerelAyar = __DIR__ . '/config.local.php';
if (is_file($yerelAyar)) {
    require_once $yerelAyar;
}

if (! defined('DB_HOST')) { define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1'); }
if (! defined('DB_NAME')) { define('DB_NAME', getenv('DB_NAME') ?: 'cy_trash'); }
if (! defined('DB_USER')) { define('DB_USER', getenv('DB_USER') ?: 'root'); }
if (! defined('DB_PASS')) { define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ''); }
if (! defined('DB_CHARSET')) { define('DB_CHARSET', 'utf8mb4'); }

/* =====================================================================
 *  HATA AYIKLAMA — ORTAMDAN TÜRETİLİR
 * ---------------------------------------------------------------------
 *  APP_DEBUG'ı elle 'true' bırakmak, canlıya alındığında SQL metninin
 *  ve dosya yollarının ekrana basılması demektir. Burada sunucu adına
 *  bakılır: canlı bir alan adında KENDİLİĞİNDEN kapanır.
 *
 *  CLI'da her zaman açık sayılır — orada çıktıyı yalnızca cron'u kuran
 *  yönetici görür ve hata ayrıntısı tam olarak orada gereklidir.
 * ================================================================== */
function cy_is_local_host(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);

    return $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.localhost');
}

if (! defined('APP_DEBUG')) {
    $cyDebugEnv = getenv('APP_DEBUG');
    define('APP_DEBUG', $cyDebugEnv !== false
        ? in_array(strtolower((string) $cyDebugEnv), ['1', 'true', 'on', 'yes'], true)
        : cy_is_local_host());
}

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/* =====================================================================
 *  ÇÖP KUTUSU AYARLARI
 * ---------------------------------------------------------------------
 *  TRASH_RETENTION_DAYS : Çöp kutusundaki bir kayıt kaç gün sonra
 *    otomatik temizlenmeye UYGUN olur.
 *
 *    DİKKAT: süre dolunca kayıt KENDİLİĞİNDEN silinmez. Silme, yalnızca
 *    bin/purge.php (cron) çalıştığında olur. Bu ayrım önemlidir: "30
 *    gün sonra silinir" cümlesi bir güvence değil, cron kurulduğu
 *    sürece geçerli bir sözdür. Arayüzde bu yüzden "silinecek" değil
 *    "temizlenmeye hazır" yazar.
 *
 *  TRASH_WARN_DAYS : Kalan gün bu sayının altına düştüğünde satır
 *    uyarı rengine geçer. Kullanıcı son günü 0'ı görünce değil, ÖNCE
 *    fark etsin.
 * ================================================================== */
define('TRASH_RETENTION_DAYS', 30);
define('TRASH_WARN_DAYS', 7);

define('NOTE_TITLE_MAX', 150);
define('NOTE_BODY_MAX', 5000);
define('PAGE_SIZE_MAX', 200);
define('PAGE_SIZE_DEFAULT', 50);

/* =====================================================================
 *  HIZ SINIRI  [istek, pencere saniyesi]
 * ---------------------------------------------------------------------
 *  Ayrı kovalar, çünkü işlemlerin maliyeti ve riski aynı değil:
 *
 *   READ    : liste ve arama. Sık çağrılır, ucuzdur.
 *   WRITE   : ekle / güncelle / çöpe at / geri al. Tek satır etkiler.
 *   DESTROY : kalıcı silme ve çöpü boşaltma. GERİ ALINAMAZ; tek bir
 *             kazayla çok sayıda kaydı yok edebilir. En dar kova bu.
 * ================================================================== */
define('RATE_LIMIT_READ',    [180, 60]);
define('RATE_LIMIT_WRITE',   [60, 60]);
define('RATE_LIMIT_DESTROY', [15, 60]);

try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            /*  Gerçek prepared statement: sorgu metni ile veri sunucuya
             *  AYRI gider, dolayısıyla veri hiçbir koşulda SQL olarak
             *  yorumlanamaz. */
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo APP_DEBUG
        ? 'Veritabanı bağlantı hatası: ' . $e->getMessage() . "\n\nKurulum:  mysql -u root -p < cy_trash.sql"
        : 'Veritabanına bağlanılamadı.';
    exit;
}
