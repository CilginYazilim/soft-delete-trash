<?php
/**
 * =====================================================================
 *  ÇÖP KUTUSU OTOMATİK TEMİZLİK  (cron ile çalıştırılır)
 *  cilginyazilim.com – Yumuşak Silme &amp; Çöp Kutusu
 * ---------------------------------------------------------------------
 *  Çöp kutusundaki, TRASH_RETENTION_DAYS'ten eski kayıtları KALICI siler.
 *
 *  Cron örneği (her gece 03:15):
 *    15 3 * * *  php /var/www/soft-delete-trash/bin/purge.php >> /var/log/cy-trash-purge.log 2>&1
 *
 *  Windows Görev Zamanlayıcı:
 *    C:\xampp\php\php.exe C:\xampp\htdocs\soft-delete-trash\bin\purge.php
 *
 *  Tekrar tekrar çalıştırmak güvenlidir: silinecek bir şey yoksa 0 döner.
 *  Bu betik yalnızca KOMUT SATIRINDAN çalışır; web'den çağrılırsa çıkar.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

define('CY_APP', true);
require __DIR__ . '/../system/config.php';
require __DIR__ . '/../system/function.php';

$start   = microtime(true);
$deleted = purge_expired_trash($db, TRASH_RETENTION_DAYS);
$ms      = (int) ((microtime(true) - $start) * 1000);

printf(
    "[%s] cy-trash purge: %d kayıt silindi (>%d gün), %d ms\n",
    date('Y-m-d H:i:s'),
    $deleted,
    TRASH_RETENTION_DAYS,
    $ms
);

exit(0);
