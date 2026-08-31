/* =====================================================================
 *  ÇÖP KUTUSU ARAYÜZ MANTIĞI
 *  cilginyazilim.com – Yumuşak Silme ve Çöp Kutusu
 * ---------------------------------------------------------------------
 *  BU DOSYANIN ÖĞRETTİĞİ İKİ KAVRAM
 *
 *  1) ONAY PENCERESİNDEKİ SAYI BİR SÜS DEĞİL, İŞLEMİN SÖZLEŞMESİDİR.
 *
 *     "Çöp kutusundaki 12 not KALICI silinecek" diye onay alıyorsak,
 *     o onay 13 kaydı silmeye yetki VERMEZ. Onay penceresi açıkken
 *     başka bir sekmede iki kayıt daha çöpe atılmış olabilir.
 *
 *     Bu yüzden istemci, kullanıcıya gösterdiği sayıyı (lastCount)
 *     işlem isteğiyle birlikte sunucuya GERİ GÖNDERİR:
 *
 *         post('empty_trash', { expected_count: lastCount })
 *
 *     Sunucu kendi saydığıyla karşılaştırır; tutmuyorsa 409 döner ve
 *     hiçbir şeye dokunmaz. İstemci de 409'u bir HATA olarak değil bir
 *     FREN olarak ele alır: listeyi yeniler, kullanıcı güncel sayıyla
 *     yeniden onaylar.
 *
 *  2) TEK GÖRÜNÜM DEĞİŞKENİ.
 *
 *     Aktif ve çöp listeleri AYNI uç noktadan (`list`) gelir; farkı
 *     yalnızca `view` parametresidir. İki ayrı yükleme fonksiyonu
 *     yazmak, arama ve sayaç mantığını iki kez yazmak demekti — ve
 *     biri güncellenip diğeri unutulurdu. Sunucu tarafında da aynı
 *     ilke geçerli: active_scope() / trash_scope().
 * ================================================================== */

/* global jQuery, bootstrap */

(function ($) {
    'use strict';

    var ENDPOINT = 'system/ajax.php';

    /* --- Görünüm durumu ---
     * view      : 'active' | 'trash' — hangi kapsamı listeliyoruz
     * lastCount : son list() yanıtındaki toplam. "Çöpü boşalt"
     *             onayında gösterilir VE expected_count olarak sunucuya
     *             döner. Yani bu değişken sadece bir görüntü sayacı
     *             değil, işlemin sözleşmesinin istemci tarafıdır. */
    var view = 'active';
    var lastCount = 0;
    var rows = [];          // son listelenen satırlar (detay için)
    var searchTimer = null;
    var currentId = 0;      // detay penceresinde açık olan kayıt

    var modalNote, modalDetail, modalConfirm;
    var confirmAction = null;

    /* =================================================================
     *  BÖLÜM 1 – YARDIMCILAR
     * ================================================================= */

    /** HTML kaçışı. Not başlığı ve içeriği KULLANICI VERİSİDİR. */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function toast(msg, type) {
        var el = $('<div class="cy-toast cy-toast--' + (type || 'info') + '" role="status"></div>').text(msg);
        $('#cy-toast-area').append(el);
        setTimeout(function () {
            el.addClass('cy-toast--out');
            setTimeout(function () { el.remove(); }, 320);
        }, 3800);
    }

    /**
     * Sunucuya POST atar.
     *
     * CSRF jetonu HER istekte gider. GET kullanmıyoruz: GET ile yapılan
     * bir silme, tarayıcının ön getirmesi ya da bir robot tarafından
     * tetiklenebilir.
     */
    function post(action, data) {
        data = data || {};
        data.action = action;
        data.csrf_token = window.CY_CSRF;

        return $.ajax({ url: ENDPOINT, method: 'POST', data: data, dataType: 'json' })
            .catch(function (xhr) {
                /* Hata yanıtının GÖVDESİ de bizim için anlamlı: 409
                 * "liste değişti", 422 alan hataları taşır. jQuery
                 * bunları .fail()'e attığı için burada normalize edip
                 * tek bir akışa döndürüyoruz. */
                var j = xhr.responseJSON;
                if (j) { j.status = xhr.status; return j; }
                return { success: false, type: 'danger', status: xhr.status,
                         description: 'Sunucuya ulaşılamadı (' + xhr.status + ').' };
            });
    }

    function gunMetni(n) {
        if (n <= 0) { return 'süresi doldu'; }
        return n + ' gün kaldı';
    }

    /* =================================================================
     *  BÖLÜM 2 – SAYAÇLAR
     * ================================================================= */

    function loadStats() {
        return post('stats').then(function (r) {
            if (!r || !r.success) { return; }
            var s = r.stats;

            $('#stat-aktif').text(s.aktif);
            $('#stat-copte').text(s.copte);
            $('#stat-son').text(s.son_gunler);
            $('#stat-dolan').text(s.suresi_dolan);
            $('#stat-toplam').text(s.toplam);
            $('#badge-total').text(s.toplam);

            $('#cnt-active').text(s.aktif);
            $('#cnt-trash').text(s.copte);

            /* Renk TEK BAŞINA anlam taşımasın diye sayının yanında
             * zaten metin var ("Temizlenmeye hazır"); is-warn yalnızca
             * dikkati çeker. */
            $('.cy-stat--warn').toggleClass('is-warn', s.son_gunler > 0);
            $('.cy-stat--expired').toggleClass('is-warn', s.suresi_dolan > 0);

            /* Süresi dolan yoksa temizleme düğmesi anlamsızdır. */
            $('#btn-purge').prop('disabled', s.suresi_dolan === 0)
                .attr('title', s.suresi_dolan === 0
                    ? 'Süresi dolan kayıt yok'
                    : s.suresi_dolan + ' kayıt temizlenmeye hazır');
        });
    }

    /* =================================================================
     *  BÖLÜM 3 – LİSTE
     * ================================================================= */

    function load() {
        var search = $('#q').val();

        return post('list', { view: view, search: search }).then(function (r) {
            if (!r || !r.success) {
                toast(r && r.description ? r.description : 'Liste alınamadı.', 'danger');
                return;
            }

            /* Yanıt gecikip kullanıcı sekme değiştirdiyse bu yanıt
             * artık yanlış listeyi çizerdi. Sunucu hangi görünümü
             * cevapladığını söylüyor; uyuşmuyorsa yok sayıyoruz. */
            if (r.view !== view) { return; }

            lastCount = r.count;
            rows = r.rows;
            render(r.rows);
            $('#th-meta').text(view === 'trash' ? 'Silindi' : 'Güncellendi');
        });
    }

    function render(list) {
        var $tb = $('#list').empty();

        if (!list.length) {
            var bos = $('#q').val()
                ? 'Aramanızla eşleşen not yok.'
                : (view === 'trash' ? 'Çöp kutusu boş.' : 'Henüz not yok. Sağ üstten ekleyebilirsiniz.');
            $tb.append('<tr><td colspan="3" class="cy-empty-cell">' + esc(bos) + '</td></tr>');
            return;
        }

        list.forEach(function (n) {
            var meta, durum = '';

            if (view === 'trash') {
                meta = n.deleted;
                var sinif = n.is_expired ? 'cy-state--expired'
                          : (n.days_left <= window.CY_WARN_DAYS ? 'cy-state--warn' : 'cy-state--ok');
                durum = '<span class="cy-state ' + sinif + '">' +
                        esc(n.is_expired ? 'temizlenmeye hazır' : gunMetni(n.days_left)) + '</span>';
            } else {
                meta = n.updated;
            }

            var eylemler = view === 'trash'
                ? '<button type="button" class="cy-btn-icon cy-btn-icon--restore js-restore" data-id="' + n.id + '" title="Geri al" aria-label="Geri al">↩</button>' +
                  '<button type="button" class="cy-btn-icon cy-btn-icon--delete js-forever" data-id="' + n.id + '" title="Kalıcı sil" aria-label="Kalıcı sil">✕</button>'
                : '<button type="button" class="cy-btn-icon cy-btn-icon--edit js-edit" data-id="' + n.id + '" title="Düzenle" aria-label="Düzenle">✎</button>' +
                  '<button type="button" class="cy-btn-icon cy-btn-icon--trash js-trash" data-id="' + n.id + '" title="Çöpe taşı" aria-label="Çöpe taşı">🗑</button>';

            $tb.append(
                '<tr tabindex="0" data-id="' + n.id + '">' +
                    '<td>' +
                        '<div class="cy-note-title">' + esc(n.title) + '</div>' +
                        '<div class="cy-note-excerpt">' + esc(n.excerpt) + '</div>' +
                        (durum ? '<div class="cy-note-state d-md-none mt-1">' + durum + '</div>' : '') +
                    '</td>' +
                    '<td class="cy-col-meta">' +
                        '<span class="cy-nowrap cy-muted">' + esc(meta) + '</span>' +
                        (durum ? '<div class="mt-1">' + durum + '</div>' : '') +
                    '</td>' +
                    '<td class="text-end"><div class="cy-actions">' + eylemler + '</div></td>' +
                '</tr>'
            );
        });
    }

    function refresh() {
        return $.when(load(), loadStats());
    }

    /* =================================================================
     *  BÖLÜM 4 – GÖRÜNÜM DEĞİŞTİRME
     * ================================================================= */

    function setView(v) {
        view = v === 'trash' ? 'trash' : 'active';

        $('.js-view').removeClass('is-active').attr('aria-selected', 'false');
        $('.js-view[data-view="' + view + '"]').addClass('is-active').attr('aria-selected', 'true');

        /* Çöp görünümüne özgü düğmeler yalnızca orada görünür.
         * Görünmeyen bir düğmeye basılamaz; ama sunucu yine de her
         * isteği kendi başına doğrular. */
        $('#btn-restore-all, #btn-purge, #btn-empty').toggleClass('d-none', view !== 'trash');
        $('#add_button').toggleClass('d-none', view === 'trash');

        load();
    }

    /* =================================================================
     *  BÖLÜM 5 – ONAY PENCERESİ
     * ================================================================= */

    /**
     * @param {string}   metin  ana soru
     * @param {?string}  not    kırmızı kutuda ek uyarı (geri alınamaz vb.)
     * @param {string}   etiket onay düğmesinin metni
     * @param {Function} fn     onaylanınca çalışacak iş
     */
    function confirmAsk(metin, not, etiket, fn) {
        $('#confirm-text').text(metin);

        if (not) {
            $('#confirm-note').removeClass('d-none').html(not);
        } else {
            $('#confirm-note').addClass('d-none').empty();
        }

        $('#confirm-ok').text(etiket || 'Evet');
        confirmAction = fn;
        modalConfirm.show();
    }

    $('#confirm-ok').on('click', function () {
        var fn = confirmAction;
        confirmAction = null;
        modalConfirm.hide();
        if (fn) { fn(); }
    });

    /* =================================================================
     *  BÖLÜM 6 – İŞLEMLER
     * ================================================================= */

    function softDelete(id) {
        var n = rows.filter(function (x) { return x.id === id; })[0];

        confirmAsk(
            '"' + (n ? n.title : '#' + id) + '" çöp kutusuna taşınsın mı?',
            'Bu işlem <b>geri alınabilir</b>. Not ' + window.CY_RETENTION_DAYS +
            ' gün boyunca çöp kutusunda durur ve istediğiniz an geri alınabilir.',
            'Çöpe taşı',
            function () {
                post('soft_delete', { id: id }).then(function (r) {
                    toast(r.description, r.type);
                    if (r.success) { modalDetail.hide(); refresh(); }
                });
            }
        );
    }

    function restore(id) {
        post('restore', { id: id }).then(function (r) {
            toast(r.description, r.success ? 'success' : 'danger');
            if (r.success) {
                modalDetail.hide();
                refresh();
            } else if (r.status === 409) {
                /* 409 bir hata değil, bir FREN: aynı başlıkta aktif bir
                 * kayıt var. Kullanıcıya ne yapması gerektiğini söyledik;
                 * listeyi tazelemeye gerek yok. */
                refresh();
            }
        });
    }

    function deleteForever(id) {
        var n = rows.filter(function (x) { return x.id === id; })[0];

        confirmAsk(
            '"' + (n ? n.title : '#' + id) + '" kalıcı olarak silinsin mi?',
            '<b>Bu işlem geri alınamaz.</b> Kayıt veritabanından tümüyle kaldırılır; ' +
            'çöp kutusundan geri alma seçeneği kalmaz.',
            'Kalıcı sil',
            function () {
                post('delete_forever', { id: id }).then(function (r) {
                    toast(r.description, r.type);
                    if (r.success) { modalDetail.hide(); refresh(); }
                });
            }
        );
    }

    /* =================================================================
     *  BÖLÜM 7 – DETAY PENCERESİ
     * ================================================================= */

    function openDetail(id) {
        post('fetch', { id: id }).then(function (r) {
            if (!r || !r.success) {
                toast(r && r.description ? r.description : 'Kayıt alınamadı.', 'danger');
                return;
            }

            var n = r.note;
            currentId = n.id;

            $('#modal-detail-title').text(n.title);
            $('#detail-body').text(n.body || '(içerik boş)');

            var rozetler = '<span class="cy-badge cy-badge--soft">#' + n.id + '</span>';
            rozetler += n.in_trash
                ? '<span class="cy-state ' + (n.is_expired ? 'cy-state--expired' : (n.days_left <= window.CY_WARN_DAYS ? 'cy-state--warn' : 'cy-state--ok')) + '">' +
                  esc(n.is_expired ? 'temizlenmeye hazır' : gunMetni(n.days_left)) + '</span>'
                : '<span class="cy-state cy-state--active">aktif</span>';
            $('#detail-badges').html(rozetler);

            var meta = '<dt>Oluşturuldu</dt><dd>' + esc(n.created) + '</dd>' +
                       '<dt>Güncellendi</dt><dd>' + esc(n.updated) + '</dd>';
            if (n.in_trash) {
                meta += '<dt>Çöpe atıldı</dt><dd>' + esc(n.deleted) + '</dd>';
            }
            $('#detail-meta').html(meta);

            /* Geri alınabilirlik BASMADAN ÖNCE söylenir. Kullanıcıyı
             * düğmeye bastırıp 409 göstermek, aynı bilgiyi bir adım
             * geç vermektir. */
            var uyari = '';
            if (n.in_trash && n.can_restore === false) {
                uyari = '<b>Bu not geri alınamaz.</b> Aynı başlıkta <b>aktif</b> bir not var ve ' +
                        'aynı başlıkta iki aktif kayıt olamaz. Geri almadan önce aktif olanı yeniden adlandırın.';
            } else if (n.in_trash && n.is_expired) {
                uyari = 'Saklama süresi doldu. Bu kayıt <b>temizlenmeye hazır</b> — ama hâlâ duruyor, ' +
                        'çünkü silme işi <code>bin/purge.php</code>\'nindir, sürenin kendisi değil. ' +
                        'Geri almak hâlâ mümkün.';
            }
            $('#detail-warn').toggleClass('d-none', uyari === '').html(uyari);

            $('#detail-edit').toggleClass('d-none', !n.editable);
            $('#detail-delete').toggleClass('d-none', n.in_trash);
            $('#detail-restore').toggleClass('d-none', !n.in_trash)
                .prop('disabled', n.in_trash && n.can_restore === false);

            /* Paylaşılabilir derin bağlantı. pushState değil
             * replaceState: geri düğmesi kayıtlar arasında dolaşmasın. */
            history.replaceState(null, '', '#not-' + n.id);

            modalDetail.show();
        });
    }

    /* =================================================================
     *  BÖLÜM 8 – NOT FORMU
     * ================================================================= */

    function openForm(id) {
        $('#form-note')[0].reset();
        $('#form-note .is-invalid').removeClass('is-invalid');
        $('#form-note [name=id]').val(id || 0);

        if (!id) {
            $('#modal-note-title').text('Yeni not');
            modalNote.show();
            return;
        }

        post('fetch', { id: id }).then(function (r) {
            if (!r || !r.success) {
                toast(r && r.description ? r.description : 'Kayıt alınamadı.', 'danger');
                return;
            }
            if (!r.note.editable) {
                toast('Çöp kutusundaki bir not düzenlenemez. Önce geri alın.', 'danger');
                return;
            }
            $('#modal-note-title').text('Notu düzenle');
            $('#form-note [name=title]').val(r.note.title);
            $('#form-note [name=body]').val(r.note.body);
            modalNote.show();
        });
    }

    $('#form-note').on('submit', function (ev) {
        ev.preventDefault();

        var $btn = $('#btn-save');
        if ($btn.prop('disabled')) { return; }   // çift gönderim koruması
        $btn.prop('disabled', true).addClass('is-busy');

        $('#form-note .is-invalid').removeClass('is-invalid');

        post('save', $(this).serializeArray().reduce(function (a, f) {
            a[f.name] = f.value; return a;
        }, {})).then(function (r) {
            if (r.success) {
                modalNote.hide();
                toast(r.description, 'success');
                refresh();
            } else if (r.errors) {
                /* Alan hataları alan adıyla gelir; doğru kutunun altına
                 * yazılır. "Bir hata oluştu" demek kullanıcıyı hangi
                 * alanı düzelteceğini aramaya bırakırdı. */
                Object.keys(r.errors).forEach(function (k) {
                    $('#form-note [name="' + k + '"]').addClass('is-invalid');
                    $('#form-note [data-for="' + k + '"]').text(r.errors[k]);
                });
                toast(r.description, 'danger');
            } else {
                toast(r.description, 'danger');
            }
        }).always(function () {
            $btn.prop('disabled', false).removeClass('is-busy');
        });
    });

    /* =================================================================
     *  BÖLÜM 9 – OLAYLAR
     * ================================================================= */

    $('.js-view').on('click', function () { setView($(this).data('view')); });

    $('#add_button').on('click', function () { openForm(0); });

    /* Arama: her tuşta istek atmak sunucuyu gereksiz yorar ve yanıtlar
     * sırasız dönerse liste titrer. 250 ms bekliyoruz. */
    $('#q').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(load, 250);
    });

    /* Satır tıklaması detayı açar; ama İŞLEM DÜĞMELERİ hariç. Düğmeye
     * basan biri detay penceresi değil, o işlemi bekler. */
    $('#list').on('click keydown', 'tr[data-id]', function (ev) {
        if ($(ev.target).closest('.cy-btn-icon').length) { return; }
        if (ev.type === 'keydown' && ev.key !== 'Enter' && ev.key !== ' ') { return; }
        ev.preventDefault();
        openDetail($(this).data('id'));
    });

    $('#list').on('click', '.js-edit',    function () { openForm($(this).data('id')); });
    $('#list').on('click', '.js-trash',   function () { softDelete($(this).data('id')); });
    $('#list').on('click', '.js-restore', function () { restore($(this).data('id')); });
    $('#list').on('click', '.js-forever', function () { deleteForever($(this).data('id')); });

    $('#detail-edit').on('click',    function () { modalDetail.hide(); openForm(currentId); });
    $('#detail-delete').on('click',  function () { softDelete(currentId); });
    $('#detail-restore').on('click', function () { restore(currentId); });

    /* Detay kapanınca adres çubuğundaki derin bağlantı temizlenir;
     * yoksa sayfa yenilendiğinde pencere kendiliğinden açılırdı. */
    $('#modal-detail').on('hidden.bs.modal', function () {
        if (location.hash.indexOf('#not-') === 0) {
            history.replaceState(null, '', location.pathname + location.search);
        }
    });

    /* --- Çöp kutusu toplu eylemleri --- */

    $('#btn-restore-all').on('click', function () {
        confirmAsk(
            'Çöp kutusundaki ' + lastCount + ' notun hepsi geri alınsın mı?',
            'Aynı başlıkta <b>aktif</b> bir kaydı olan notlar <b>atlanır</b> — tek bir çakışma ' +
            'yüzünden diğerlerinin geri alınmaması işinize yaramazdı. Kaç tanesinin atlandığı size söylenir.',
            'Hepsini geri al',
            function () {
                post('restore_all').then(function (r) {
                    toast(r.description, r.type);
                    refresh();
                });
            }
        );
    });

    $('#btn-purge').on('click', function () {
        confirmAsk(
            'Saklama süresi dolan notlar kalıcı olarak silinsin mi?',
            '<b>Bu işlem geri alınamaz.</b> Yalnızca <b>' + window.CY_RETENTION_DAYS +
            ' günden eski</b> kayıtlar silinir; daha yeni olanlara dokunulmaz. ' +
            'Bu düğme <code>bin/purge.php</code> ile <b>aynı</b> fonksiyonu çağırır.',
            'Süresi dolanları sil',
            function () {
                post('purge_expired').then(function (r) {
                    toast(r.description, r.type);
                    refresh();
                });
            }
        );
    });

    $('#btn-empty').on('click', function () {
        if (lastCount === 0) {
            toast('Çöp kutusu zaten boş.', 'info');
            return;
        }

        confirmAsk(
            'Çöp kutusundaki ' + lastCount + ' not kalıcı olarak silinsin mi?',
            '<b>Bu işlem geri alınamaz.</b> Süresi dolmamış olanlar da dahil <b>hepsi</b> silinir. ' +
            'Onaydaki sayı sunucuya geri gönderilir: liste bu arada değiştiyse işlem yapılmaz.',
            'Hepsini kalıcı sil',
            function () {
                /* expected_count: kullanıcının GÖRDÜĞÜ sayı. Sunucu
                 * kendi saydığıyla kıyaslar; tutmuyorsa 409 döner. */
                post('empty_trash', { expected_count: lastCount }).then(function (r) {
                    toast(r.description, r.type);
                    refresh();
                });
            }
        );
    });

    /* =================================================================
     *  BÖLÜM 10 – AÇILIŞ
     * ================================================================= */

    $(function () {
        modalNote    = new bootstrap.Modal(document.getElementById('modal-note'));
        modalDetail  = new bootstrap.Modal(document.getElementById('modal-detail'));
        modalConfirm = new bootstrap.Modal(document.getElementById('modal-confirm'));

        refresh().then(function () {
            /* PAYLAŞILABİLİR DERİN BAĞLANTI: #not-12
             * Adres bir kaydı işaret ediyorsa açılışta detayı açarız.
             * Kayıt çöpteyse önce o sekmeye geçilir — yoksa pencere
             * kapandığında kullanıcı boş bir listeye bakardı. */
            var m = /^#not-(\d+)$/.exec(location.hash);
            if (!m) { return; }

            var id = parseInt(m[1], 10);
            post('fetch', { id: id }).then(function (r) {
                if (!r || !r.success) { return; }
                if (r.note.in_trash && view !== 'trash') {
                    setView('trash');
                }
                openDetail(id);
            });
        });
    });

})(jQuery);
