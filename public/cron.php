<?php

declare(strict_types=1);

/**
 * Web-triggerable cron endpoint for hosts without shell cron. Protect it with a
 * secret token (?token=...) matching CRON_TOKEN. Prefer real crontab where
 * available:  * * * * * php /path/bin/console cron
 */

use SupportAI\Support\Config;
use SupportAI\Support\Container;
use SupportAI\Support\Env;

/** @var Container $c */
$c = require dirname(__DIR__) . '/bootstrap.php';

$expected = Env::get('CRON_TOKEN', '');
$given = $_GET['token'] ?? '';
if ($expected === '' || !hash_equals($expected, (string) $given)) {
    http_response_code(403);
    echo 'Forbidden';
    return;
}

header('Content-Type: text/plain');

// Storage-limitation: purge data past the retention window (PDPL).
$purged = $c->get(SupportAI\Application\Compliance\ComplianceService::class)->purge();
echo "OK — retention purge removed {$purged} conversation(s).\n";
