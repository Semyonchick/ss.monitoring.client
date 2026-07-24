<?php

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;

class ss_monitoring_client extends CModule
{
  public $MODULE_ID = 'ss.monitoring.client';
  public $MODULE_VERSION;
  public $MODULE_VERSION_DATE;
  public $MODULE_NAME = 'Клиент мониторинга';
  public $MODULE_DESCRIPTION = 'Публикует защищённый health endpoint для внешней системы мониторинга.';

  public function __construct()
  {
    $version = [];
    include __DIR__ . '/version.php';
    $this->MODULE_VERSION = $version['VERSION'] ?? $arModuleVersion['VERSION'];
    $this->MODULE_VERSION_DATE = $version['VERSION_DATE'] ?? $arModuleVersion['VERSION_DATE'];
  }

  public function DoInstall()
  {
    ModuleManager::registerModule($this->MODULE_ID);
    if (Option::get($this->MODULE_ID, 'token', '') === '') {
      Option::set($this->MODULE_ID, 'token', bin2hex(random_bytes(32)));
    }
    $events = EventManager::getInstance();
    $events->unRegisterEventHandler('main', 'OnBeforeProlog', $this->MODULE_ID, 'Ss\\Monitoring\\Client\\HealthHandler', 'handle');
    $events->registerEventHandler('main', 'OnBeforeProlog', $this->MODULE_ID, 'Ss\\Monitoring\\Client\\HealthHandler', 'handle');
    \Bitrix\Main\Loader::includeModule($this->MODULE_ID);
    \Ss\Monitoring\Client\SchedulerAgent::ensureRegistered();
  }

  public function DoUninstall()
  {
    \CAgent::RemoveModuleAgents($this->MODULE_ID);
    EventManager::getInstance()->unRegisterEventHandler('main', 'OnBeforeProlog', $this->MODULE_ID, 'Ss\\Monitoring\\Client\\HealthHandler', 'handle');
    Option::delete($this->MODULE_ID);
    ModuleManager::unRegisterModule($this->MODULE_ID);
  }
}
