<?php

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'backup-success') {
  fwrite(STDERR, "Usage: php mark.php backup-success [ISO-8601 timestamp]" . PHP_EOL);
  exit(64);
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('ss.monitoring.client');

\Ss\Monitoring\Client\State::backupSuccess($argv[2] ?? null);
