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
    $timestamp = $createdAt ?? self::now();
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $timestamp)) {
      throw new \InvalidArgumentException('Backup creation time must be ISO-8601 with a timezone.');
    }
    try {
      $date = new \DateTimeImmutable($timestamp);
      $errors = \DateTimeImmutable::getLastErrors();
    } catch (\Exception $exception) {
      throw new \InvalidArgumentException('Invalid backup creation time.');
    }
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
      throw new \InvalidArgumentException('Invalid backup creation time.');
    }
    if ($date->getTimestamp() > time() + 300) {
      throw new \InvalidArgumentException('Backup creation time is in the future.');
    }
    $previous = self::backupLastSuccess();
    if ($previous && strtotime($previous) > $date->getTimestamp()) {
      throw new \InvalidArgumentException('Backup creation time is older than the recorded success.');
    }
    Option::set(self::MODULE_ID, 'last_backup_at', $date->format(DATE_ATOM));
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
