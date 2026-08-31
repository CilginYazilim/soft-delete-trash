<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI
 *  cilginyazilim.com – Yumuşak Silme ve Çöp Kutusu
 * ---------------------------------------------------------------------
 *    action=list            → Aktif VEYA çöp listesi (view=active|trash)
 *    action=stats           → Sayaç şeridi (tek sorgu)
 *    action=fetch           → Tek kaydın tüm alanları (detay penceresi)
 *    action=save            → Ekle / güncelle          [çakışma → 409]
 *    action=soft_delete     → Çöpe taşı (deleted_at = NOW())
 *    action=restore         → Çöpten geri al           [çakışma → 409]
 *    action=restore_all     → Çöptekilerin hepsini geri al (çakışanlar hariç)
 *    action=delete_forever  → KALICI sil (tek kayıt, geri alınamaz)
 *    action=empty_trash     → Çöp kutusunu boşalt (expected_count denetimi)
 *    action=purge_expired   → Yalnızca süresi DOLANLARI temizle (cron ile aynı yol)
 *
 *  YAZMA İŞLEMLERİ NEDEN POST? GET ile yapılan bir silme, tarayıcının
 *  ön getirmesi (prefetch), bir arama motoru robotu ya da sayfadaki bir
 *  <img> etiketi tarafından tetiklenebilir. "Bağlantıya tıklayınca
 *  siliniyordu" hatasının kaynağı budur.
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);
require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

require_csrf();

$action = isset($_POST['action']) ? strtolower(trim((string) $_POST['action'])) : 'list';

try {
    switch ($action) {
        case 'list':           handle_list($db);           break;
        case 'stats':          handle_stats($db);          break;
        case 'fetch':          handle_fetch($db);          break;
        case 'save':           handle_save($db);           break;
        case 'soft_delete':    handle_soft_delete($db);    break;
        case 'restore':        handle_restore($db);        break;
        case 'restore_all':    handle_restore_all($db);    break;
        case 'delete_forever': handle_delete_forever($db); break;
        case 'empty_trash':    handle_empty_trash($db);    break;
        case 'purge_expired':  handle_purge_expired($db);  break;
        default:               json_error('Bilinmeyen işlem.', 400);
    }
} catch (PDOException $e) {
    error_log('[TRASH] DB: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'DB hatası: ' . $e->getMessage() : 'Beklenmeyen bir veritabanı hatası.', 500);
} catch (Throwable $e) {
    error_log('[TRASH] Hata: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata.', 500);
}

/* =====================================================================
 *  LİSTE  (aktif veya çöp — kapsam TEK kaynaktan)
 * ================================================================== */
function handle_list(PDO $db): void
{
    rate_limit('read', ...RATE_LIMIT_READ);

    $view  = ($_POST['view'] ?? 'active') === 'trash' ? 'trash' : 'active';
    $scope = $view === 'trash' ? trash_scope() : active_scope();

    $search    = trim((string) ($_POST['search'] ?? ''));
    $params    = [];
    $searchSql = '';

    if ($search !== '') {
        /*  İKİ AYRI YER TUTUCU (:q1, :q2) — aynı ad iki kez kullanılamaz.
         *
         *  ÖLÇÜLEN HATA: burada iki koşul da :q kullanıyordu ve arama
         *  kutusuna bir şey yazıldığı anda sorgu HY093 "Invalid parameter
         *  number" ile patlıyordu. PDO::ATTR_EMULATE_PREPARES = false
         *  iken sorgu MySQL'e gerçek prepared statement olarak gider ve
         *  adlar konumsal ?'lere çevrilir; aynı ad ikinci kez geçemez.
         *  Emülasyon AÇIKKEN çalışıp KAPALIYKEN patlayan, bu yüzden de
         *  gözden kaçması kolay bir hatadır. */
        $searchSql = ' AND (title LIKE :q1 OR body LIKE :q2)';
        $like = '%' . escape_like($search) . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
    }

    $limit = (int) ($_POST['limit'] ?? PAGE_SIZE_DEFAULT);
    if ($limit < 1 || $limit > PAGE_SIZE_MAX) {
        $limit = PAGE_SIZE_DEFAULT;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM notes WHERE $scope $searchSql");
    $stmt->execute($params);
    $count = (int) $stmt->fetchColumn();

    /*  Kalan gün ve "süresi doldu" bilgisi SQL'de hesaplanır; PHP'nin
     *  ve MySQL'in saat dilimi eşit olmak zorunda değildir. */
    $extra = $view === 'trash' ? trash_age_expr((int) TRASH_RETENTION_DAYS) : '';

    $sql = "SELECT id, title, body, created_at, updated_at, deleted_at $extra
            FROM notes
            WHERE $scope $searchSql
            ORDER BY " . ($view === 'trash' ? 'deleted_at' : 'updated_at') . " DESC, id DESC
            LIMIT :lim";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $row = [
            'id'      => (int) $r['id'],
            'title'   => $r['title'],
            'excerpt' => excerpt((string) $r['body'], 140),
            'updated' => date('d.m.Y H:i', strtotime((string) $r['updated_at'])),
        ];

        if ($view === 'trash') {
            $row['deleted']    = date('d.m.Y H:i', strtotime((string) $r['deleted_at']));
            $row['days_left']  = (int) $r['days_left'];
            $row['is_expired'] = (bool) $r['is_expired'];
        }

        $rows[] = $row;
    }

    json_response([
        'success'        => true,
        'view'           => $view,
        'count'          => $count,
        'rows'           => $rows,
        'retention_days' => TRASH_RETENTION_DAYS,
        'warn_days'      => TRASH_WARN_DAYS,
    ]);
}

/* =====================================================================
 *  SAYAÇLAR
 * ================================================================== */
function handle_stats(PDO $db): void
{
    rate_limit('read', ...RATE_LIMIT_READ);

    json_response(['success' => true, 'stats' => trash_stats($db, (int) TRASH_RETENTION_DAYS)]);
}

/* =====================================================================
 *  TEK KAYIT  (detay penceresi ve düzenleme formu)
 * ---------------------------------------------------------------------
 *  Çöpteki kayıt da GETİRİLEBİLİR — kullanıcı geri almadan önce içine
 *  bakabilmeli. Ama düzenlenemez: yanıttaki 'editable' alanı bunu
 *  söyler ve handle_save zaten aktif kapsam dışına yazmaz. Kural iki
 *  yerde birden durur; istemcinin unutması sunucuyu bağlamaz.
 * ================================================================== */
function handle_fetch(PDO $db): void
{
    rate_limit('read', ...RATE_LIMIT_READ);

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_error('Geçersiz kayıt.', 400);
    }

    $stmt = $db->prepare(
        'SELECT id, title, body, created_at, updated_at, deleted_at'
        . trash_age_expr((int) TRASH_RETENTION_DAYS) . '
         FROM notes WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error('Kayıt bulunamadı.', 404);
    }

    $inTrash = $row['deleted_at'] !== null;

    /*  Çöpteki bir kayıt geri alınabilir mi? Aynı başlıkta AKTİF bir
     *  kayıt varsa alınamaz. Bunu kullanıcıya geri alma düğmesine
     *  BASMADAN ÖNCE söylemek, 409 hatasıyla karşılaştırmaktan iyidir. */
    $canRestore = null;
    if ($inTrash) {
        $chk = $db->prepare('SELECT 1 FROM notes WHERE title = :t AND ' . active_scope() . ' LIMIT 1');
        $chk->execute([':t' => $row['title']]);
        $canRestore = $chk->fetchColumn() === false;
    }

    json_response([
        'success' => true,
        'note'    => [
            'id'          => (int) $row['id'],
            'title'       => $row['title'],
            'body'        => $row['body'],
            'created'     => date('d.m.Y H:i', strtotime((string) $row['created_at'])),
            'updated'     => date('d.m.Y H:i', strtotime((string) $row['updated_at'])),
            'deleted'     => $inTrash ? date('d.m.Y H:i', strtotime((string) $row['deleted_at'])) : null,
            'in_trash'    => $inTrash,
            'editable'    => !$inTrash,
            'days_left'   => $inTrash ? (int) $row['days_left'] : null,
            'is_expired'  => $inTrash ? (bool) $row['is_expired'] : null,
            'can_restore' => $canRestore,
        ],
    ]);
}

/* =====================================================================
 *  KAYDET  (ekle / güncelle)
 * ================================================================== */
function handle_save(PDO $db): void
{
    rate_limit('write', ...RATE_LIMIT_WRITE);

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    [$data, $errors] = validate_note($_POST);

    if ($errors) {
        json_error('Lütfen formdaki hataları düzeltin.', 422, ['errors' => $errors]);
    }

    try {
        if ($id === 0) {
            $stmt = $db->prepare('INSERT INTO notes (title, body) VALUES (:t, :b)');
            $stmt->execute([':t' => $data['title'], ':b' => $data['body']]);
            json_success('Not eklendi.', ['id' => (int) $db->lastInsertId()]);
        }

        /*  Kapsam koşulu UPDATE'in WHERE'inde: çöpteki bir kayıt
         *  düzenlenemez. Kontrolü PHP'de yapıp sonra koşulsuz UPDATE
         *  çalıştırmak, iki adım arasında kaydın çöpe taşınmasına açık
         *  bir pencere bırakırdı. */
        $stmt = $db->prepare('UPDATE notes SET title = :t, body = :b WHERE id = :id AND ' . active_scope());
        $stmt->execute([':t' => $data['title'], ':b' => $data['body'], ':id' => $id]);

        if ($stmt->rowCount() === 0) {
            /*  rowCount() = 0 iki anlama gelir: kayıt yok/çöpte, YA DA
             *  hiçbir alan değişmedi. İkisi çok farklı sonuçlar; ayırt
             *  etmeden hata döndürmek, "hiçbir şey değiştirmeden kaydet"
             *  diyen kullanıcıya sahte bir hata gösterirdi. */
            $chk = $db->prepare('SELECT 1 FROM notes WHERE id = :id AND ' . active_scope());
            $chk->execute([':id' => $id]);
            if ($chk->fetchColumn() === false) {
                json_error('Kayıt bulunamadı veya çöp kutusunda.', 404);
            }
        }

        json_success('Not güncellendi.', ['id' => $id]);
    } catch (PDOException $ex) {
        /*  Çakışmayı İNDEKS ADINA bakarak ayırt ediyoruz. Yalnızca
         *  '23000' koduna bakmak yetmez: aynı kod yabancı anahtar ve
         *  NOT NULL ihlallerinde de gelir ve hepsine "bu başlık
         *  kullanımda" demek yanlış olurdu. */
        $isDupeTitle = $ex->getCode() === '23000'
            && str_contains($ex->getMessage(), 'uq_notes_active_title');

        if ($isDupeTitle) {
            json_error('Bu başlıkta aktif bir not zaten var.', 409, ['errors' => ['title' => 'Bu başlık kullanımda.']]);
        }
        throw $ex;
    }
}

/* =====================================================================
 *  ÇÖPE TAŞI  (soft delete)
 * ---------------------------------------------------------------------
 *  Kayıt SİLİNMEZ, işaretlenir. Bunun bedeli her sorguya bir koşul
 *  eklemektir; karşılığı, yanlışlıkla silinen bir kaydın geri
 *  gelebilmesidir. Çoğu üründe bu takas fazlasıyla kârlıdır.
 * ================================================================== */
function handle_soft_delete(PDO $db): void
{
    rate_limit('write', ...RATE_LIMIT_WRITE);

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_error('Geçersiz kayıt.', 400);
    }

    $stmt = $db->prepare('UPDATE notes SET deleted_at = NOW() WHERE id = :id AND ' . active_scope());
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error('Kayıt bulunamadı veya zaten çöpte.', 404);
    }

    json_success('Not çöp kutusuna taşındı. ' . TRASH_RETENTION_DAYS . ' gün içinde geri alabilirsiniz.');
}

/* =====================================================================
 *  GERİ AL  (restore) — çakışma kontrolü ŞART
 * ---------------------------------------------------------------------
 *  Kayıt çöpteyken, aynı başlıkta YENİ bir aktif not oluşturulmuş
 *  olabilir. Geri alma o durumda UNIQUE ihlaline düşer. Bunu ham bir
 *  veritabanı hatası olarak değil, açık bir 409 olarak döndürürüz —
 *  kullanıcı ne yapması gerektiğini bilsin.
 *
 *  Kontrol ve güncelleme TEK TRANSACTION içinde, satır kilidiyle
 *  yapılır: ikisi arasında başka bir istek aynı başlıkta not
 *  oluşturursa kontrol geçer ama UPDATE patlar. Yine de UNIQUE
 *  indeksin kendisi son savunma hattı olarak yerinde durur.
 * ================================================================== */
function handle_restore(PDO $db): void
{
    rate_limit('write', ...RATE_LIMIT_WRITE);

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_error('Geçersiz kayıt.', 400);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT title FROM notes WHERE id = :id AND ' . trash_scope() . ' FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $title = $stmt->fetchColumn();

        if ($title === false) {
            $db->rollBack();
            json_error('Kayıt çöp kutusunda bulunamadı.', 404);
        }

        $chk = $db->prepare('SELECT id FROM notes WHERE title = :t AND ' . active_scope() . ' LIMIT 1');
        $chk->execute([':t' => $title]);
        $carpisan = $chk->fetchColumn();

        if ($carpisan !== false) {
            $db->rollBack();
            json_error(
                'Bu başlıkta aktif bir not var. Geri almadan önce onu yeniden adlandırın.',
                409,
                ['conflict_id' => (int) $carpisan]
            );
        }

        $db->prepare('UPDATE notes SET deleted_at = NULL WHERE id = :id')->execute([':id' => $id]);
        $db->commit();
    } catch (PDOException $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($ex->getCode() === '23000' && str_contains($ex->getMessage(), 'uq_notes_active_title')) {
            json_error('Başlık çakışması nedeniyle geri alınamadı.', 409);
        }
        throw $ex;
    } catch (Throwable $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $ex;
    }

    json_success('Not geri alındı.');
}

/* =====================================================================
 *  HEPSİNİ GERİ AL
 * ---------------------------------------------------------------------
 *  Çakışanları ATLAR, geri kalanı geri alır ve KAÇ TANESİNİN
 *  atlandığını söyler. "Hepsi ya da hiçbiri" davranışı burada yanlış
 *  olurdu: tek bir başlık çakışması yüzünden 20 kaydın geri
 *  alınmaması, kullanıcının işine yaramaz.
 * ================================================================== */
function handle_restore_all(PDO $db): void
{
    rate_limit('write', ...RATE_LIMIT_WRITE);

    $db->beginTransaction();
    try {
        /*  Tek UPDATE: çöpteki bir kaydı, AYNI başlıkta aktif kayıt
         *  YOKSA geri al. NOT EXISTS koşulu çakışanları kendiliğinden
         *  dışarıda bırakır — satır satır dönüp tek tek denemeye
         *  gerek kalmaz. */
        $sql = 'UPDATE notes t
                SET t.deleted_at = NULL
                WHERE ' . trash_scope('t') . '
                  AND NOT EXISTS (
                        SELECT 1 FROM (SELECT title FROM notes WHERE ' . active_scope() . ') a
                        WHERE a.title = t.title
                  )';

        $toplam = (int) $db->query('SELECT COUNT(*) FROM notes WHERE ' . trash_scope())->fetchColumn();
        $stmt = $db->query($sql);
        $geri = $stmt->rowCount();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $atlanan = $toplam - $geri;
    $mesaj = $geri . ' not geri alındı.';
    if ($atlanan > 0) {
        $mesaj .= ' ' . $atlanan . ' not, aynı başlıkta aktif bir kayıt olduğu için atlandı.';
    }

    json_success($mesaj, ['restored' => $geri, 'skipped' => $atlanan]);
}

/* =====================================================================
 *  KALICI SİL  (tek kayıt) — geri alınamaz
 * ================================================================== */
function handle_delete_forever(PDO $db): void
{
    rate_limit('destroy', ...RATE_LIMIT_DESTROY);

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_error('Geçersiz kayıt.', 400);
    }

    /*  Yalnızca ÇÖPTEKİ kayıt kalıcı silinebilir. "Önce çöpe at" adımı
     *  zorunludur: tek tıkla kalıcı silme, yumuşak silmenin varlık
     *  sebebini ortadan kaldırırdı. */
    $stmt = $db->prepare('DELETE FROM notes WHERE id = :id AND ' . trash_scope());
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error('Kayıt çöp kutusunda bulunamadı. Önce çöpe taşıyın.', 404);
    }

    json_success('Not kalıcı olarak silindi.');
}

/* =====================================================================
 *  ÇÖP KUTUSUNU BOŞALT  — onaylanan sayı denetimiyle
 * ---------------------------------------------------------------------
 *  Kullanıcı "12 not kalıcı silinecek" onayını verir. İstemci gördüğü
 *  sayıyı (expected_count) geri gönderir; sunucu kendi saydığıyla
 *  kıyaslar. Tutmuyorsa 409 döner ve hiçbir şeye dokunmaz.
 *
 *  Neden? Onay penceresi açıkken başka bir sekmede (ya da başka bir
 *  kullanıcı tarafından) çöpe iki kayıt daha atılmış olabilir. "12
 *  kayıt silinecek" onayı 14 kaydı silmeye yetki VERMEZ. Onaydaki
 *  sayı bir süs değil, işlemin sözleşmesidir.
 * ================================================================== */
function handle_empty_trash(PDO $db): void
{
    rate_limit('destroy', ...RATE_LIMIT_DESTROY);

    $expected = filter_input(INPUT_POST, 'expected_count', FILTER_VALIDATE_INT);
    if ($expected === null || $expected === false) {
        json_error('Onay sayısı eksik.', 422);
    }

    $db->beginTransaction();
    try {
        /*  Sayım ve silme AYNI transaction içinde. Ayrı olsalardı,
         *  ikisi arasında eklenen bir kayıt sayıma girmeden silinirdi. */
        $current = (int) $db->query('SELECT COUNT(*) FROM notes WHERE ' . trash_scope())->fetchColumn();

        if ($current !== $expected) {
            $db->rollBack();
            json_error(
                'Liste değişti. Çöp kutusunda şu an ' . $current . ' not var; işlem yapılmadı.',
                409,
                ['current' => $current]
            );
        }

        $deleted = $db->query('DELETE FROM notes WHERE ' . trash_scope())->rowCount();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    json_success($deleted . ' not kalıcı olarak silindi.', ['deleted' => $deleted]);
}

/* =====================================================================
 *  SÜRESİ DOLANLARI TEMİZLE  (cron'un elle çalıştırılmış hâli)
 * ---------------------------------------------------------------------
 *  bin/purge.php ile AYNI fonksiyonu çağırır. İki ayrı temizleme kodu
 *  yazmak, birini düzeltip diğerini unutmanın kesin yoludur — ve
 *  unutulan taraf cron olursa fark hiç görünmez.
 *
 *  Bu düğme arayüzde bilerek var: otomatik temizliğin ne yaptığını
 *  cron kurmadan göstermenin başka yolu yok.
 * ================================================================== */
function handle_purge_expired(PDO $db): void
{
    rate_limit('destroy', ...RATE_LIMIT_DESTROY);

    $deleted = purge_expired_trash($db, (int) TRASH_RETENTION_DAYS);

    if ($deleted === 0) {
        json_response([
            'success'     => true,
            'type'        => 'info',
            'description' => 'Süresi dolan kayıt yok. Çöpteki notların hepsi ' . TRASH_RETENTION_DAYS . ' günden yeni.',
            'deleted'     => 0,
        ]);
    }

    json_success(
        $deleted . ' adet süresi dolmuş not kalıcı olarak silindi (' . TRASH_RETENTION_DAYS . ' günden eski).',
        ['deleted' => $deleted]
    );
}
