<?php

namespace Ss\Monitoring\Client;

final class SchedulerAgent
{
  public static function run(): string
  {
    State::schedulerSuccess();

    return '\\Ss\\Monitoring\\Client\\SchedulerAgent::run();';
  }
}
