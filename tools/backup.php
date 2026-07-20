<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Ss\Monitoring\Client\State;

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This script must be run from CLI." . PHP_EOL);
  exit(64);
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
Loader::includeModule('ss.monitoring.client');
umask(0027);

$backupDir = getenv('SS_MONITORING_BACKUP_DIR') ?: '/srv/ss-monitoring/backups';
$siteRoot = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
$siteParent = dirname($siteRoot);
$siteDirectory = basename($siteRoot);
$siteUploadPath = $siteRoot . '/upload';
$uploadDir = getenv('SS_MONITORING_UPLOAD_DIR') ?: $siteUploadPath;
$uploadExcludedPaths = [
  'backup',
  'dev2fun.imagecompress',
  'resize_cache',
  'tmp',
];
$excludedSitePaths = [
  'bitrix/backup',
  'bitrix/cache',
  'bitrix/html_pages',
  'bitrix/managed_cache',
  'bitrix/stack_cache',
  'bitrix/tmp',
  ...array_map(static fn(string $path): string => 'upload/' . $path, $uploadExcludedPaths),
];
$siteName = preg_replace('/[^a-z0-9-]+/i', '-', $siteDirectory);
$timestamp = (new DateTimeImmutable('now'))->format('Ymd-His');
$id = $siteName . '-' . $timestamp;
$archiveName = $id . '.tar.zst';
$archivePath = $backupDir . '/' . $archiveName;
$manifestPath = $backupDir . '/' . $id . '.manifest.json';
$temporaryDir = $backupDir . '/.work-' . bin2hex(random_bytes(8));
$lockFile = $backupDir . '/backup.lock';
$lock = null;

function ssMonitoringRun(string $command, ?string $output = null): void
{
  $descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $output ?: '/dev/null', 'w'],
    2 => ['pipe', 'w'],
  ];
  $process = proc_open($command, $descriptors, $pipes);
  if (!is_resource($process)) throw new RuntimeException('Unable to start command.');
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);
  $code = proc_close($process);
  if ($code !== 0) throw new RuntimeException(trim($stderr) ?: 'Command failed: ' . $command);
}

function ssMonitoringRemoveDirectory(string $directory): void
{
  if (!is_dir($directory)) return;
  ssMonitoringRun('rm -rf ' . escapeshellarg($directory));
}

try {
  if (!is_dir($backupDir) || !is_writable($backupDir)) throw new RuntimeException('Backup directory is unavailable.');
  if (!is_dir($siteRoot)) throw new RuntimeException('Bitrix site directory is unavailable.');
  if (!is_dir($uploadDir)) throw new RuntimeException('Bitrix upload directory is unavailable.');
  $lock = fopen($lockFile, 'c');
  if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('A backup is already running.');
  }
  if (!mkdir($temporaryDir, 0700) && !is_dir($temporaryDir)) {
    throw new RuntimeException('Unable to create temporary directory.');
  }
  $connection = Application::getConnection();
  $database = $connection->getConfiguration();
  foreach (['host', 'database', 'login', 'password'] as $key) {
    if (empty($database[$key])) throw new RuntimeException('Database connection setting is missing: ' . $key);
  }

  $credentials = $temporaryDir . '/mysql.cnf';
  $mysqlConfig = "[client]\n" .
    'host=' . $database['host'] . "\n" .
    'user=' . $database['login'] . "\n" .
    'password=' . $database['password'] . "\n";
  if (!empty($database['port'])) $mysqlConfig .= 'port=' . (int)$database['port'] . "\n";
  file_put_contents($credentials, $mysqlConfig, LOCK_EX);
  chmod($credentials, 0600);
  $dumpCommand = 'mysqldump --defaults-extra-file=' . escapeshellarg($credentials) .
    ' --single-transaction --quick --routines --events --triggers ' . escapeshellarg($database['database']);
  ssMonitoringRun($dumpCommand, $temporaryDir . '/database.sql');
  @unlink($credentials);

  $versions = [
    'php' => PHP_VERSION,
    'created_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
  ];
  file_put_contents(
    $temporaryDir . '/versions.json',
    json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
  );

  $excludeArguments = implode(' ', array_map(
    static fn(string $path): string => '--exclude=' . escapeshellarg($siteDirectory . '/' . $path),
    $excludedSitePaths
  ));
  $archiveSources = 'tar -C ' . escapeshellarg($temporaryDir) . ' -cf - database.sql versions.json' .
    ' -C ' . escapeshellarg($siteParent) . ' ' . $excludeArguments . ' ' . escapeshellarg($siteDirectory);
  $manifestContents = ['database', 'site'];
  $externalUploadDirectory = null;
  if (is_link($siteUploadPath)) {
    $externalUploadDirectory = basename($uploadDir);
    $uploadExcludeArguments = implode(' ', array_map(
      static fn(string $path): string => '--exclude=' . escapeshellarg($externalUploadDirectory . '/' . $path),
      $uploadExcludedPaths
    ));
    $archiveSources .= ' -C ' . escapeshellarg(dirname($uploadDir)) . ' ' . $uploadExcludeArguments .
      ' ' . escapeshellarg($externalUploadDirectory);
    $manifestContents[] = 'external_upload';
  }
  $archiveCommand = $archiveSources . ' | zstd -T0 -q -o ' . escapeshellarg($archivePath . '.tmp');
  ssMonitoringRun($archiveCommand);
  ssMonitoringRun('zstd -tq ' . escapeshellarg($archivePath . '.tmp'));
  if (!rename($archivePath . '.tmp', $archivePath)) {
    throw new RuntimeException('Unable to publish backup archive.');
  }
  $manifest = [
    'id' => $id,
    'created_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    'file' => $archiveName,
    'size_bytes' => filesize($archivePath),
    'sha256' => hash_file('sha256', $archivePath),
    'contents' => $manifestContents,
    'site_directory' => $siteDirectory,
    'excluded_site_paths' => $excludedSitePaths,
  ];
  if ($externalUploadDirectory !== null) {
    $manifest['external_upload_directory'] = $externalUploadDirectory;
  }
  $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  if (
    file_put_contents($manifestPath . '.tmp', $manifestJson, LOCK_EX) === false ||
    !rename($manifestPath . '.tmp', $manifestPath)
  ) {
    throw new RuntimeException('Unable to publish backup manifest.');
  }
  if (
    file_put_contents($backupDir . '/manifest.json.tmp', $manifestJson, LOCK_EX) === false ||
    !rename($backupDir . '/manifest.json.tmp', $backupDir . '/manifest.json')
  ) {
    throw new RuntimeException('Unable to publish current backup manifest.');
  }

  $archives = glob($backupDir . '/*.tar.zst') ?: [];
  usort($archives, static fn($left, $right) => filemtime($right) <=> filemtime($left));
  foreach (array_slice($archives, 2) as $oldArchive) {
    @unlink($oldArchive);
    @unlink(substr($oldArchive, 0, -strlen('.tar.zst')) . '.manifest.json');
  }
  State::backupSuccess($manifest['created_at']);
  echo $archiveName . PHP_EOL;
} catch (Throwable $exception) {
  @unlink($archivePath . '.tmp');
  @unlink($manifestPath . '.tmp');
  @unlink($backupDir . '/manifest.json.tmp');
  fwrite(STDERR, $exception->getMessage() . PHP_EOL);
  exit(1);
} finally {
  ssMonitoringRemoveDirectory($temporaryDir);
  if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
