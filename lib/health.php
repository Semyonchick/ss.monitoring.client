<?php

namespace Ss\Monitoring\Client;

use Bitrix\Main\Application;

final class Health
{
  private const OK = 'ok';
  private const WARNING = 'warning';
  private const CRITICAL = 'critical';
  private const UNKNOWN = 'unknown';

  public static function respond(string $token): void
  {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
      self::send(405, ['status' => self::CRITICAL]);
      return;
    }
    if (!self::secure() || !self::authorized($token)) {
      self::send(403, ['status' => self::CRITICAL]);
      return;
    }

    $database = self::database();
    $disk = self::disk();
    $scheduler = self::scheduler();
    $backup = self::backup();
    $status = self::overall([$database, $disk['status'], $scheduler['status'], $backup['status']]);

    self::send($status === self::CRITICAL ? 500 : 200, [
      'status' => $status,
      'message' => self::message($database, $disk['status'], $scheduler, $backup),
      'application' => self::OK,
      'database' => $database,
      'storage' => $disk['status'],
      'scheduler' => $scheduler['status'],
      'backup' => $backup['status'],
      'disk' => $disk['data'],
      'scheduler_last_success_at' => $scheduler['last_success_at'],
      'last_backup_at' => $backup['last_backup_at'],
      'system' => self::system(),
      'timestamp' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
    ]);
  }

  public static function failure(): void
  {
    self::send(500, [
      'status' => self::CRITICAL,
      'message' => 'Health check failed unexpectedly.',
      'application' => self::CRITICAL,
      'database' => self::UNKNOWN,
      'storage' => self::UNKNOWN,
      'scheduler' => self::UNKNOWN,
      'backup' => self::UNKNOWN,
      'disk' => null,
      'scheduler_last_success_at' => null,
      'last_backup_at' => null,
      'system' => null,
      'timestamp' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
    ]);
  }

  private static function database(): string
  {
    try {
      Application::getConnection()->queryScalar('SELECT 1');
      return self::OK;
    } catch (\Throwable $exception) {
      return self::CRITICAL;
    }
  }

  private static function disk(): array
  {
    $paths = [$_SERVER['DOCUMENT_ROOT'], $_SERVER['DOCUMENT_ROOT'] . '/upload'];
    $lowest = null;
    foreach (array_unique(array_filter($paths, 'is_string')) as $path) {
      $total = @disk_total_space($path);
      $free = @disk_free_space($path);
      if ($total === false || $free === false || $total <= 0) return ['status' => self::CRITICAL, 'data' => null];
      $current = ['total_bytes' => (int) $total, 'free_bytes' => (int) $free, 'free_percent' => round($free / $total * 100, 2)];
      if ($lowest === null || $current['free_percent'] < $lowest['free_percent']) $lowest = $current;
    }
    if ($lowest === null) return ['status' => self::UNKNOWN, 'data' => null];
    if ($lowest['free_percent'] < 5) return ['status' => self::CRITICAL, 'data' => $lowest];
    if ($lowest['free_percent'] < 15) return ['status' => self::WARNING, 'data' => $lowest];
    return ['status' => self::OK, 'data' => $lowest];
  }

  private static function scheduler(): array
  {
    $lastSuccess = State::schedulerLastSuccess();
    return ['status' => self::age($lastSuccess, 300), 'last_success_at' => $lastSuccess];
  }

  private static function backup(): array
  {
    $lastBackup = State::backupLastSuccess();
    return [
      'status' => self::age($lastBackup, 108000, 172800),
      'last_backup_at' => $lastBackup,
    ];
  }

  private static function age(?string $timestamp, int $warning, ?int $critical = null): string
  {
    if (!$timestamp) return self::CRITICAL;
    try {
      $seconds = time() - (new \DateTimeImmutable($timestamp))->getTimestamp();
    } catch (\Throwable $exception) {
      return self::UNKNOWN;
    }
    $critical = $critical ?? $warning * 4;
    if ($seconds > $critical) return self::CRITICAL;
    if ($seconds > $warning * 2) return self::WARNING;
    return self::OK;
  }

  private static function overall(array $statuses): string
  {
    if (in_array(self::CRITICAL, $statuses, true)) return self::CRITICAL;
    if (in_array(self::WARNING, $statuses, true)) return self::WARNING;
    if (in_array(self::UNKNOWN, $statuses, true)) return self::UNKNOWN;
    return self::OK;
  }

  private static function message(string $database, string $storage, array $scheduler, array $backup): string
  {
    $problems = [];
    if ($database !== self::OK) $problems[] = 'database is unavailable';
    if ($storage !== self::OK) $problems[] = 'disk space is insufficient or unavailable';
    if ($scheduler['status'] !== self::OK) {
      $problems[] = $scheduler['last_success_at'] ? 'scheduler execution is overdue' : 'no successful scheduler execution was recorded';
    }
    if ($backup['status'] !== self::OK) {
      $problems[] = $backup['last_backup_at'] ? 'backup is overdue' : 'no successful backup was recorded';
    }
    return $problems ? implode('; ', $problems) . '.' : 'All monitored components are operational.';
  }

  private static function system(): array
  {
    $load = function_exists('sys_getloadavg') ? \sys_getloadavg() : false;
    return [
      'cpu_percent' => self::cpuPercent(),
      'load_average' => [
        '1m' => isset($load[0]) ? (float) $load[0] : null,
        '5m' => isset($load[1]) ? (float) $load[1] : null,
        '15m' => isset($load[2]) ? (float) $load[2] : null,
      ],
      'ram' => self::ram(),
    ];
  }

  private static function cpuPercent(): ?float
  {
    $first = self::cpuStat();
    if (!$first) return null;
    usleep(100000);
    $second = self::cpuStat();
    if (!$second) return null;
    $total = $second['total'] - $first['total'];
    $idle = $second['idle'] - $first['idle'];
    return $total > 0 ? round((1 - $idle / $total) * 100, 2) : null;
  }

  private static function cpuStat(): ?array
  {
    $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
    $line = $lines[0] ?? null;
    if (!$line || !preg_match('/^cpu\s+(.+)$/', trim($line), $matches)) return null;
    $values = array_map('intval', preg_split('/\s+/', $matches[1]));
    return ['total' => array_sum($values), 'idle' => ($values[3] ?? 0) + ($values[4] ?? 0)];
  }

  private static function ram(): array
  {
    $data = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$data) return ['total_bytes' => null, 'used_bytes' => null, 'used_percent' => null];
    $values = [];
    foreach ($data as $line) {
      if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', $line, $matches)) {
        $values[$matches[1]] = (int) $matches[2] * 1024;
      }
    }
    $total = $values['MemTotal'] ?? null;
    $available = $values['MemAvailable'] ?? null;
    if (!$total || $available === null) return ['total_bytes' => null, 'used_bytes' => null, 'used_percent' => null];
    $used = max(0, $total - $available);
    return ['total_bytes' => $total, 'used_bytes' => $used, 'used_percent' => round($used / $total * 100, 2)];
  }

  private static function authorized(string $token): bool
  {
    return $token !== '' && hash_equals($token, (string) ($_SERVER['HTTP_X_MONITORING_TOKEN'] ?? ''));
  }

  private static function secure(): bool
  {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
  }

  private static function send(int $code, array $data): void
  {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  }
}
