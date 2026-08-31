<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Yumuşak Silme ve Çöp Kutusu
 * ---------------------------------------------------------------------
 *  BÖLÜM 1  Çıktı / JSON
 *  BÖLÜM 2  CSRF
 *  BÖLÜM 3  Hız sınırı
 *  BÖLÜM 4  YUMUŞAK SİLME: tek kapsam (scope) kaynağı + benzersizlik
 *  BÖLÜM 5  Doğrulama ve temizlik
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

/* ===== BÖLÜM 1 – ÇIKTI / JSON ===================================== */

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON_INVALID_UTF8_SUBSTITUTE: not gövdesi kopyala-yapıştır ile gelen
 * bozuk bir bayt içerebilir. O bayrak olmadan json_encode() sessizce
 * `false` döner ve tarayıcı BOŞ gövde alır — liste boş görünür, hata
 * mesajı da dahil her şey kaybolur. Tek bozuk bayt yüzünden yanıtın
 * tamamının yok olmasındansa o bayt "?" olsun.
 */
function json_response(array $p, int $s = 200): void
{
    if (!headers_sent()) {
        http_response_code($s);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_success(string $d, array $x = []): void
{
    json_response(array_merge(['success' => true, 'type' => 'success', 'description' => $d], $x));
}

function json_error(string $d, int $s = 400, array $x = []): void
{
    json_response(array_merge(['success' => false, 'type' => 'danger', 'description' => $d], $x), $s);
}

/* ===== BÖLÜM 2 – CSRF ============================================= */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $t = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    /*  hash_equals: jeton kıyaslaması sabit zamanda yapılır. === ilk
     *  farklı baytta çıkar ve süre farkı ölçülebilir. */
    if (!is_string($t) || $t === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $t)) {
        json_error('Oturum doğrulaması başarısız. Sayfayı yenileyin.', 403);
    }
}

/* ===== BÖLÜM 3 – HIZ SINIRI ======================================= */

function rate_limit_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cy_trash_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function client_ip(): string
{
    /*  X-Forwarded-For BİLEREK okunmuyor: o başlığı istemci uydurabilir.
     *  Uydurulabilir bir değere göre sınır saymak, sınır koymamakla
     *  aynı şeydir. */
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'bilinmiyor');
}

function rate_limit(string $bucket, int $limit, int $window): void
{
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . sha1($bucket . '|' . client_ip()) . '.json';
    $h = @fopen($file, 'c+');
    if ($h === false) {
        /*  Sayaç yazılamıyorsa isteği ENGELLEMİYORUZ: disk sorunu
         *  uygulamanın tamamını kapatmasın. */
        return;
    }

    /*  LOCK_EX: oku-değiştir-yaz üçlüsü bölünemez olmalı. Kilitsiz
     *  yazımda aynı anda gelen iki istek birbirinin sayacını ezer. */
    flock($h, LOCK_EX);

    $now  = microtime(true);
    $hits = json_decode((string) stream_get_contents($h), true);
    $hits = is_array($hits) ? $hits : [];

    /*  Kayan pencere: son N saniyedeki istek ZAMANLARI tutulur. Sabit
     *  pencere (dakika başında sıfırlanan sayaç) sınırın iki katına
     *  izin verir: 59. saniyede N, 61. saniyede N daha. */
    $hits = array_values(array_filter($hits, static fn($t) => is_numeric($t) && ($now - (float) $t) < $window));

    if (count($hits) >= $limit) {
        $retry = max(1, (int) ceil($window - ($now - (float) $hits[0])));
        flock($h, LOCK_UN);
        fclose($h);
        json_error("Çok fazla istek. {$retry} saniye sonra tekrar deneyin.", 429, ['retry_after' => $retry]);
    }

    $hits[] = $now;
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, (string) json_encode($hits));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}

/**
 * LIKE jokerlerini kaçışlar.
 *
 * Kaçışlanmazsa "%" arayan bir kullanıcı bütün tabloyu eşleştirir ve
 * "_" tek karakterlik joker olarak davranır. Ters eğik çizginin kendisi
 * de kaçışlanmalıdır, yoksa "\" araması sorguyu bozar.
 */
function escape_like(string $v): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
}

/* =====================================================================
 *  BÖLÜM 4 – YUMUŞAK SİLME
 * ---------------------------------------------------------------------
 *  ALTIN KURAL: "aktif kayıt" tanımı TEK yerde durur. Listede, sayaçta,
 *  tekil getirmede, düzenlemede… her yerde AYNI koşul kullanılır. Bu
 *  koşul üç ayrı yere kopyalanıp biri güncellenir, diğeri unutulursa
 *  kullanıcı çöpe attığını sandığı kaydı hâlâ listede görür — ya da
 *  tersi, geri aldığı kayıt hiçbir yerde görünmez.
 *
 *      Aktif kayıt   →  deleted_at IS NULL
 *      Çöp kutusu    →  deleted_at IS NOT NULL
 *
 *  BENZERSİZLİK TUZAĞI: notes.title üzerinde DÜZ bir UNIQUE indeks
 *  olsaydı, "Alışveriş" başlıklı bir notu çöpe atıp AYNI başlıkla
 *  yenisini oluşturamazdınız — çöptekiyle çakışırdı. Kullanıcı için bu
 *  anlamsızdır: sildiği bir şey hâlâ yolunu tıkıyor.
 *
 *  Çözüm: benzersizlik yalnızca AKTİF kayıtlar arasında olmalı.
 *  MySQL'de bunu şöyle kurarız:
 *
 *      is_active = generated column: (CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END)
 *      UNIQUE KEY (title, is_active)
 *
 *  NULL değerler UNIQUE indekste ÇAKIŞMAZ; böylece çöpte kaç kayıt
 *  olursa olsun (is_active = NULL) yalnızca bir AKTİF "Alışveriş"
 *  olabilir. Ayrıntı cy_trash.sql içinde.
 * ================================================================== */

function active_scope(string $alias = ''): string
{
    $col = $alias === '' ? 'deleted_at' : $alias . '.deleted_at';
    return "$col IS NULL";
}

function trash_scope(string $alias = ''): string
{
    $col = $alias === '' ? 'deleted_at' : $alias . '.deleted_at';
    return "$col IS NOT NULL";
}

/**
 * Liste için kısa özet.
 *
 * Ham mb_substr() metni kelimenin ORTASINDAN keser ve hiçbir işaret
 * bırakmaz: ekranda "…aynı başlıkta iki a" gibi bozuk görünen, ama
 * aslında yalnızca kırpılmış bir cümle kalır. Kullanıcı metnin devamı
 * olduğunu anlamaz. Burada son boşluğa geri sarılır ve üç nokta
 * eklenir — kırpma görünür olur.
 */
function excerpt(string $text, int $len): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));

    if (mb_strlen($text) <= $len) {
        return $text;
    }

    $kesik = mb_substr($text, 0, $len);
    $son   = mb_strrpos($kesik, ' ');

    /*  Boşluk yoksa (tek uzun kelime) olduğu gibi bırakılır; geri
     *  sarmak metnin tamamını yok ederdi. */
    if ($son !== false && $son > $len * 0.6) {
        $kesik = mb_substr($kesik, 0, $son);
    }

    return rtrim($kesik, " ,;:.") . '…';
}

/**
 * Çöpteki bir kaydın kalan gününü ve bayat olup olmadığını SQL'de
 * hesaplayan ifade.
 *
 * NEDEN SQL'DE? Aynı hesabı PHP'de `time()` ile yapmak, PHP'nin ve
 * MySQL'in saat dilimi ayarlarının eşit olmasını varsayar. Eşit
 * olmadıklarında liste ile detay farklı gün sayısı gösterir ve
 * hangisinin doğru olduğu belli olmaz. `deleted_at` MySQL'in NOW()
 * değeriyle yazıldığına göre kıyas da MySQL'de yapılmalıdır.
 */
function trash_age_expr(int $retentionDays): string
{
    $d = (int) $retentionDays;   // yapılandırma sabiti, istemciden gelmez

    return ", GREATEST(0, DATEDIFF(deleted_at + INTERVAL $d DAY, NOW())) AS days_left"
         . ", (deleted_at < (NOW() - INTERVAL $d DAY)) AS is_expired";
}

/* ===== BÖLÜM 5 – DOĞRULAMA VE TEMİZLİK ============================ */

/** @return array{0:array<string,string>,1:array<string,string>} */
function validate_note(array $in): array
{
    $errors = [];
    $title = trim((string) ($in['title'] ?? ''));
    $body  = trim((string) ($in['body'] ?? ''));

    if (mb_strlen($title) < 2 || mb_strlen($title) > NOTE_TITLE_MAX) {
        $errors['title'] = 'Başlık 2-' . NOTE_TITLE_MAX . ' karakter olmalıdır.';
    }
    if (mb_strlen($body) > NOTE_BODY_MAX) {
        $errors['body'] = 'İçerik en fazla ' . number_format(NOTE_BODY_MAX, 0, ',', '.') . ' karakter olabilir.';
    }

    return [['title' => $title, 'body' => $body], $errors];
}

/**
 * Çöp kutusundaki, saklama süresi dolmuş kayıtları KALICI siler.
 * Hem bin/purge.php (cron) hem de arayüzdeki "Süresi dolanları temizle"
 * düğmesi BU yolu kullanır — iki ayrı temizleme kodu zamanla ayrışırdı
 * ve hangisinin doğru olduğu belli olmazdı.
 *
 * @return int silinen satır sayısı
 */
function purge_expired_trash(PDO $db, int $retentionDays): int
{
    $stmt = $db->prepare(
        'DELETE FROM notes
         WHERE ' . trash_scope() . '
           AND deleted_at < (NOW() - INTERVAL :days DAY)'
    );
    $stmt->bindValue(':days', $retentionDays, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

/** Süresi dolmuş kayıt sayısı — silmeden önce "kaç tane?" sorusunun cevabı. */
function count_expired_trash(PDO $db, int $retentionDays): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM notes
         WHERE ' . trash_scope() . '
           AND deleted_at < (NOW() - INTERVAL :days DAY)'
    );
    $stmt->bindValue(':days', $retentionDays, PDO::PARAM_INT);
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * Sayaç şeridinin tamamı TEK sorgudan gelir.
 *
 * Dört ayrı COUNT sorgusu yazmak, dört ayrı anlık görüntü demektir:
 * sorgular arasında bir kayıt çöpe taşınırsa toplamlar birbirini
 * tutmaz ve ekranda "5 aktif + 3 çöpte = 9 toplam" gibi imkânsız bir
 * satır belirir.
 *
 * @return array<string,int>
 */
function trash_stats(PDO $db, int $retentionDays): array
{
    $d = (int) $retentionDays;

    $row = $db->query(
        'SELECT
             SUM(deleted_at IS NULL)                                          AS aktif,
             SUM(deleted_at IS NOT NULL)                                      AS copte,
             SUM(deleted_at IS NOT NULL AND deleted_at <  (NOW() - INTERVAL ' . $d . ' DAY)) AS suresi_dolan,
             SUM(deleted_at IS NOT NULL AND deleted_at >= (NOW() - INTERVAL ' . $d . ' DAY)
                                        AND deleted_at <  (NOW() - INTERVAL ' . ($d - (int) TRASH_WARN_DAYS) . ' DAY)) AS son_gunler,
             COUNT(*)                                                         AS toplam
         FROM notes'
    )->fetch();

    return [
        'aktif'        => (int) $row['aktif'],
        'copte'        => (int) $row['copte'],
        'suresi_dolan' => (int) $row['suresi_dolan'],
        'son_gunler'   => (int) $row['son_gunler'],
        'toplam'       => (int) $row['toplam'],
    ];
}
