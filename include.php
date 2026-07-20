<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('ss.monitoring.client', [
  'Ss\\Monitoring\\Client\\Health' => 'lib/health.php',
  'Ss\\Monitoring\\Client\\HealthHandler' => 'lib/healthhandler.php',
  'Ss\\Monitoring\\Client\\SchedulerAgent' => 'lib/scheduleragent.php',
  'Ss\\Monitoring\\Client\\State' => 'lib/state.php',
]);
