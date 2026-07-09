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

// Phase 2: drain job_queue (parse → embed → memory extraction).
header('Content-Type: text/plain');
echo "OK — no jobs registered yet.\n";
