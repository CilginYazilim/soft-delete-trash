<?php
/**
 * =====================================================================
 *  ARAYÜZ (Sunum Katmanı)
 *  cilginyazilim.com – Yumuşak Silme ve Çöp Kutusu
 * ---------------------------------------------------------------------
 *  Ekran üç bölümden oluşur ve sırası bilinçlidir:
 *
 *    1) SAYAÇ ŞERİDİ  → aktif, çöpte, son günlerinde, süresi dolan
 *    2) İKİ SEKME     → Aktif notlar / Çöp kutusu. İkisi de AYNI uç
 *       noktadan beslenir; farkı yalnızca `view` parametresidir
 *    3) TABLO         → satıra tıklayınca detay penceresi açılır
 *
 *  "Süresi dolan" sayacı bu ekranın en öğretici parçasıdır: sıfırdan
 *  büyükse, saklama süresi dolmuş ama HÂLÂ DURAN kayıtlar var demektir.
 *  Süre dolunca hiçbir şey kendiliğinden olmaz; silme, cron çalıştığında
 *  olur. Arayüz bu yüzden "silinecek" değil "temizlenmeye hazır" der.
 *
 *  Bu dosya veritabanına DOKUNMAZ. Tüm veri system/ajax.php üzerinden,
 *  AJAX ile gelir.
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);
require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP PDO ve MySQL ile yumuşak silme (soft delete) ve çöp kutusu: deleted_at kalıbı, tek kapsam kaynağı, aktif kayıtlar arasında benzersizlik, geri alma çakışması ve cron ile otomatik temizlik.">
    <meta name="theme-color" content="#0b5cb5">

    <title>Yumuşak Silme ve Çöp Kutusu | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <!--
        CSS YÜKLEME SIRASI ÖNEMLİDİR:
          1) bootstrap      → temel çatı
          2) cilginyazilim  → MARKA TASARIM KALIBI (Bootstrap'i ezer)
          3) style          → yalnızca bu sayfaya özel eklemeler
    -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="cy-app">

<!-- Sayfanın en üstündeki ince marka şeridi -->
<div class="cy-topbar"></div>

<div class="container py-4 py-lg-5">

    <div class="cy-card mb-4">

        <div class="cy-card__header">
            <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-3">

                <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                    <span class="cy-brand__mark">
                        <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                    </span>
                    <div>
                        <h1 class="cy-brand__title">Yumuşak Silme ve Çöp Kutusu</h1>
                        <p class="cy-brand__subtitle">
                            <code>deleted_at</code> kalıbı &middot; Tek kapsam kaynağı &middot; Geri alma &middot; Otomatik temizlik
                        </p>
                    </div>
                </a>

                <div class="cy-header-controls d-flex align-items-center gap-2 flex-wrap">
                    <span class="cy-badge cy-badge--glass">
                        <strong id="badge-total">0</strong> kayıt
                    </span>

                    <a class="btn cy-btn cy-btn--glass"
                       href="https://github.com/CilginYazilim/soft-delete-trash"
                       target="_blank" rel="noopener" title="Projeyi GitHub'da aç">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" style="vertical-align:-2px">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/>
                        </svg>
                        <span class="cy-header-controls__label">GitHub</span>
                    </a>

                    <!-- id="add_button" — marka CSS'inin mobil kuralları bu
                         kimliği hedefler (dar ekranda tam genişliğe geçer). -->
                    <button type="button" id="add_button" class="btn cy-btn cy-btn--onbrand">
                        <span aria-hidden="true">+</span> Yeni not
                    </button>
                </div>
            </div>
        </div>

        <div class="cy-card__body">

            <!-- ---------------------------------------------------------
                 1) SAYAÇ ŞERİDİ
                 ---------------------------------------------------------
                 "Son günlerinde" ve "Süresi dolan" sıfırdan büyükse
                 uyarı rengine geçer. İkisi de bir sorun değil, bir
                 HATIRLATMADIR: birinde vakit daralıyor, diğerinde
                 cron'un işi birikmiş.
                 --------------------------------------------------------- -->
            <div class="cy-stats" id="cy-stats">
                <div class="cy-stat cy-stat--active">
                    <span class="cy-stat__value" id="stat-aktif">0</span>
                    <span class="cy-stat__label">Aktif not</span>
                </div>
                <div class="cy-stat cy-stat--trash">
                    <span class="cy-stat__value" id="stat-copte">0</span>
                    <span class="cy-stat__label">Çöp kutusunda</span>
                </div>
                <div class="cy-stat cy-stat--warn">
                    <span class="cy-stat__value" id="stat-son">0</span>
                    <span class="cy-stat__label">Son <?= (int) TRASH_WARN_DAYS ?> günde</span>
                </div>
                <div class="cy-stat cy-stat--expired">
                    <span class="cy-stat__value" id="stat-dolan">0</span>
                    <span class="cy-stat__label">Temizlenmeye hazır</span>
                </div>
                <div class="cy-stat cy-stat--total">
                    <span class="cy-stat__value" id="stat-toplam">0</span>
                    <span class="cy-stat__label">Tabloda toplam</span>
                </div>
            </div>

            <!-- ---------------------------------------------------------
                 2) SEKMELER VE ARAÇ ÇUBUĞU
                 ---------------------------------------------------------
                 Aktif ve çöp listeleri AYNI uç noktadan gelir; farkı
                 yalnızca `view` parametresidir. İki ayrı yükleme
                 fonksiyonu yazmak, arama ve sayaç mantığını iki kez
                 yazmak demekti — ve biri güncellenip diğeri unutulurdu.
                 --------------------------------------------------------- -->
            <div class="cy-toolbar">
                <div class="cy-tabs" role="tablist" aria-label="Görünüm">
                    <button type="button" class="cy-tab is-active js-view" data-view="active"
                            role="tab" aria-selected="true">
                        Aktif <span class="cy-tab__count" id="cnt-active">0</span>
                    </button>
                    <button type="button" class="cy-tab js-view" data-view="trash"
                            role="tab" aria-selected="false">
                        Çöp kutusu <span class="cy-tab__count" id="cnt-trash">0</span>
                    </button>
                </div>

                <div class="cy-toolbar__actions">
                    <label class="visually-hidden" for="q">Notlarda ara</label>
                    <input type="search" id="q" class="form-control cy-search"
                           placeholder="Başlık ve içerikte ara…" autocomplete="off">

                    <!-- Çöp görünümüne özgü eylemler. Aktif sekmedeyken
                         gizlenir; görünmeyen bir düğmeye basılamaz. -->
                    <button type="button" id="btn-restore-all" class="btn cy-btn d-none">
                        Hepsini geri al
                    </button>
                    <button type="button" id="btn-purge" class="btn cy-btn d-none" title="Saklama süresi dolan kayıtları kalıcı siler">
                        Süresi dolanları temizle
                    </button>
                    <button type="button" id="btn-empty" class="btn cy-btn cy-btn--danger d-none">
                        Çöpü boşalt
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table cy-table cy-notes mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Not</th>
                            <th scope="col" class="cy-col-meta" id="th-meta">Güncellendi</th>
                            <th scope="col" class="text-end">İşlem</th>
                        </tr>
                    </thead>
                    <tbody id="list"></tbody>
                </table>
            </div>
        </div>

        <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
            <span>
                Saklama süresi <b><?= (int) TRASH_RETENTION_DAYS ?> gün</b>. Süre dolunca kayıt
                <b>kendiliğinden silinmez</b> — <code>php bin/purge.php</code> (cron) çalıştığında silinir.
            </span>
            <span>PHP <?= e(PHP_VERSION) ?></span>
        </div>
    </div>

    <div class="cy-footer-note">
        <p class="mb-1">
            Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
            tarafından geliştirilmiştir. MIT lisanslıdır; dilediğiniz gibi indirip kullanabilirsiniz.
        </p>
        <p class="mb-1">
            Katkı sağlamak ister misiniz? Depoyu çatallayın (fork) ve pull request gönderin:
            <a href="https://github.com/CilginYazilim/soft-delete-trash"
               target="_blank" rel="noopener">github.com/CilginYazilim</a>
        </p>
        <p class="mb-0">
            Aynı tasarım kalıbıyla hazırlanmış diğer açıklamalı örnekler:
            <a href="https://cilginyazilim.com/kutuphane" target="_blank" rel="noopener">cilginyazilim.com/kutuphane</a>
        </p>
    </div>
</div>


<!-- =====================================================================
     MODAL – NOT FORMU (ekle / düzenle)
     ===================================================================== -->
<div class="modal fade" id="modal-note" tabindex="-1" aria-labelledby="modal-note-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content cy-modal">
            <form id="form-note" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-note-title">Yeni not</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="0">

                    <div class="mb-3">
                        <label class="form-label" for="f-title">Başlık</label>
                        <input id="f-title" name="title" class="form-control" maxlength="<?= (int) NOTE_TITLE_MAX ?>" autocomplete="off">
                        <div class="invalid-feedback" data-for="title"></div>
                        <p class="cy-hint mt-1 mb-0">
                            Aynı başlıkta yalnızca <b>bir aktif</b> not olabilir. Çöptekiler bu kuralın dışındadır.
                        </p>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="f-body">İçerik</label>
                        <textarea id="f-body" name="body" rows="6" class="form-control" maxlength="<?= (int) NOTE_BODY_MAX ?>"></textarea>
                        <div class="invalid-feedback" data-for="body"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn cy-btn" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn cy-btn cy-btn--primary" id="btn-save">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- =====================================================================
     MODAL – NOT DETAYI
     ---------------------------------------------------------------------
     Çöpteki bir kayıt da açılabilir: kullanıcı geri almadan önce içine
     bakabilmeli. Düzenlenemez — o kuralı hem bu pencere hem sunucu
     bilir.
     ===================================================================== -->
<div class="modal fade" id="modal-detail" tabindex="-1" aria-labelledby="modal-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content cy-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-detail-title">Not</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div id="detail-badges" class="cy-badges mb-3"></div>
                <div id="detail-warn" class="cy-explain d-none"></div>

                <dl class="cy-detail" id="detail-meta"></dl>

                <h3 class="cy-section-title mt-3">İçerik</h3>
                <div class="cy-body-box" id="detail-body"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn cy-btn" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn cy-btn" id="detail-edit">Düzenle</button>
                <button type="button" class="btn cy-btn cy-btn--primary" id="detail-restore">Geri al</button>
                <button type="button" class="btn cy-btn cy-btn--danger" id="detail-delete">Çöpe taşı</button>
            </div>
        </div>
    </div>
</div>


<!-- =====================================================================
     MODAL – ONAY
     ---------------------------------------------------------------------
     Tarayıcının confirm() kutusu yerine markayla uyumlu bir pencere.
     Sebebi yalnızca görünüm değil: confirm() metni biçimlendiremez,
     kalıcı silme ile çöpe taşımayı görsel olarak ayıramaz ve bazı
     tarayıcılarda "bu siteden gelen kutuları engelle" ile kapatılabilir.
     ===================================================================== -->
<div class="modal fade" id="modal-confirm" tabindex="-1" aria-labelledby="modal-confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content cy-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-confirm-title">Emin misiniz?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <p id="confirm-text" class="mb-2"></p>
                <div id="confirm-note" class="cy-explain d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn cy-btn" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn cy-btn cy-btn--danger" id="confirm-ok">Evet</button>
            </div>
        </div>
    </div>
</div>


<div id="cy-toast-area" class="cy-toast-area" aria-live="polite" aria-atomic="true"></div>

<script>
window.CY_CSRF = <?= json_encode($token) ?>;
/* Sunucudaki sabitleri istemciye TAŞIYORUZ, elle kopyalamıyoruz.
 * İki taraf ayrışırsa ekranda yazan gün sayısı ile davranış birbirini
 * tutmaz — ve hangisinin doğru olduğu belli olmaz. */
window.CY_RETENTION_DAYS = <?= (int) TRASH_RETENTION_DAYS ?>;
window.CY_WARN_DAYS      = <?= (int) TRASH_WARN_DAYS ?>;
</script>
<script src="assets/js/jquery-3.7.0.js"></script>
<script src="assets/js/bootstrap.bundle.js"></script>
<script src="assets/js/trash.js"></script>
</body>
</html>
