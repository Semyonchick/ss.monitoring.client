<?php

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['scheduler-success', 'backup-success'], true)) {
  fwrite(STDERR, "Usage: php mark.php scheduler-success|backup-success [ISO-8601 timestamp]" . PHP_EOL);
  exit(64);
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('ss.monitoring.client');

if ($argv[1] === 'scheduler-success') {
  \Ss\Monitoring\Client\State::schedulerSuccess();
} else {
  \Ss\Monitoring\Client\State::backupSuccess($argv[2] ?? null);
}
