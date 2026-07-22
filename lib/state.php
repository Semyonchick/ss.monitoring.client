<?php

namespace Ss\Monitoring\Client;

use Bitrix\Main\Application;
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
    $connection = Application::getConnection();
    $helper = $connection->getSqlHelper();
    $moduleId = $helper->forSql(self::MODULE_ID);
    $optionName = $helper->forSql($name);
    $value = $connection->queryScalar(
      "SELECT VALUE FROM b_option WHERE MODULE_ID = '{$moduleId}' AND NAME = '{$optionName}'"
    );
    return is_string($value) && $value !== '' ? $value : null;
  }

  private static function now(): string
  {
    return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
  }
}
