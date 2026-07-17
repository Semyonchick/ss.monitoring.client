<?php

namespace Ss\Monitoring\Client;

use Bitrix\Main\Config\Option;

final class State
{
  private const MODULE_ID = 'ss.monitoring.client';

  public static function schedulerSuccess(): void
  {
    Option::set(self::MODULE_ID, 'scheduler_last_success_at', self::now());
  }

  public static function backupSuccess(?string $createdAt = null): void
  {
    Option::set(self::MODULE_ID, 'last_backup_at', $createdAt ?: self::now());
  }

  public static function schedulerLastSuccess(): ?string
  {
    return self::read('scheduler_last_success_at');
  }

  public static function backupLastSuccess(): ?string
  {
    return self::read('last_backup_at');
  }

  private static function read(string $name): ?string
  {
    $value = Option::get(self::MODULE_ID, $name, '');
    return $value !== '' ? $value : null;
  }

  private static function now(): string
  {
    return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
  }
}
