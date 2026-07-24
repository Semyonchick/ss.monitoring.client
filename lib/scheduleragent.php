<?php

namespace Ss\Monitoring\Client;

final class SchedulerAgent
{
  private const NAME = '\\Ss\\Monitoring\\Client\\SchedulerAgent::run();';
  private const MODULE_ID = 'ss.monitoring.client';
  private const INTERVAL = 300;

  public static function run(): string
  {
    State::schedulerSuccess();

    return self::NAME;
  }

  public static function ensureRegistered(): void
  {
    $agents = \CAgent::GetList([], [
      'MODULE_ID' => self::MODULE_ID,
      'NAME' => self::NAME,
    ]);
    $agent = $agents->Fetch();

    if (!$agent) {
      \CAgent::AddAgent(
        self::NAME,
        self::MODULE_ID,
        'N',
        self::INTERVAL
      );
      return;
    }

    $fields = [];
    if (($agent['IS_PERIOD'] ?? '') !== 'N') $fields['IS_PERIOD'] = 'N';
    if ((int) ($agent['AGENT_INTERVAL'] ?? 0) !== self::INTERVAL) {
      $fields['AGENT_INTERVAL'] = self::INTERVAL;
    }
    if (($agent['ACTIVE'] ?? 'N') !== 'Y') {
      $fields['ACTIVE'] = 'Y';
      $fields['NEXT_EXEC'] = \ConvertTimeStamp(time(), 'FULL');
    }

    if ($fields) \CAgent::Update((int) $agent['ID'], $fields);
  }
}
