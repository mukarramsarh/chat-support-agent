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

// 1) Refresh URL sources due for a recrawl (each isolated; failures don't block).
$r = $c->get(SupportAI\Application\Ingestion\RecrawlService::class)->refreshDue(5);
echo "OK — recrawl checked {$r['checked']} (updated {$r['updated']}, unchanged {$r['unchanged']}, failed {$r['failed']}).\n";

// 2) Long-term memory: summarise + extract facts from grown conversations.
$mem = $c->get(SupportAI\Application\Chat\MemoryMaintenanceService::class)->process(5);
echo "memory processed {$mem['processed']} conversation(s), +{$mem['facts']} fact(s).\n";

// 3) Storage-limitation: purge data past the retention window (PDPL).
$purged = $c->get(SupportAI\Application\Compliance\ComplianceService::class)->purge();
echo "retention purge removed {$purged} conversation(s).\n";
