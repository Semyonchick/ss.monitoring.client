<?php

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'backup-success' || count($argv) !== 3) {
  fwrite(STDERR, "Usage: php mark.php backup-success <ISO-8601 creation timestamp with timezone>" . PHP_EOL);
  exit(64);
}

// Acknowledging a completed NAS operation must not execute agents or mail.
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');

try {
  require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
  if (!\Bitrix\Main\Loader::includeModule('ss.monitoring.client')) {
    throw new RuntimeException('Monitoring client module is not installed.');
  }
  \Ss\Monitoring\Client\State::backupSuccess($argv[2]);
  echo json_encode([
    'status' => 'ok',
    'last_backup_at' => \Ss\Monitoring\Client\State::backupLastSuccess(),
  ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
  fwrite(STDERR, 'BACKUP_MARK_FAILED: ' . $exception->getMessage() . PHP_EOL);
  exit(1);
}
