<?php

namespace Ss\Monitoring\Client;

use Bitrix\Main\Config\Option;

final class HealthHandler
{
  public static function handle(): void
  {
    $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($path !== '/monitoring/health') return;

    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    try {
      $token = Option::get('ss.monitoring.client', 'token');
      Health::respond($token);
    } catch (\Throwable $exception) {
      Health::failure();
    }

    exit;
  }
}
