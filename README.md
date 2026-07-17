# ss.monitoring.client

Автономный Bitrix-модуль для публикации защищённого health endpoint SmartSAM и подготовки серверных резервных копий. Модуль не выполняет исходящих HTTP-запросов и не требует endpoint-файлов, изменений `urlrewrite.php` или файлов конфигурации вне каталога модуля.

## Подключение к проекту

Разместите репозиторий в `local/modules/ss.monitoring.client` и установите модуль в административной части Bitrix. При установке модуль:

- регистрирует обработчик `main:OnBeforeProlog` для `GET /monitoring/health`;
- создаёт случайный токен в настройках Bitrix;
- не копирует файлы в остальные каталоги проекта.

Для подключения репозитория через Git subtree:

```bash
git subtree add --prefix=local/modules/ss.monitoring.client \
  https://github.com/rere-design/ss.monitoring.client.git master --squash
```

Последующие обновления:

```bash
git subtree pull --prefix=local/modules/ss.monitoring.client \
  https://github.com/rere-design/ss.monitoring.client.git master --squash
```

## Health endpoint

Внешний мониторинг выполняет запрос по HTTPS и передаёт токен только в заголовке `X-Monitoring-Token`:

```text
GET /monitoring/health
X-Monitoring-Token: <token>
```

Ответ содержит поля `status`, `message`, `application`, `database`, `storage`, `scheduler`, `backup`, `disk`, `scheduler_last_success_at`, `last_backup_at`, `system` и `timestamp`. Состояния scheduler и backup, как и токен, хранятся через `Bitrix\Main\Config\Option`; модулю не нужен отдельный каталог состояния.

Получить токен можно в Bitrix Command Line:

```php
echo \Bitrix\Main\Config\Option::get('ss.monitoring.client', 'token') . PHP_EOL;
```

После успешного выполнения основного cron проекта зарегистрируйте отметку одним из способов:

```php
\Ss\Monitoring\Client\State::schedulerSuccess();
```

```bash
php local/modules/ss.monitoring.client/tools/mark.php scheduler-success
```

## Серверные резервные копии

Один раз запустите из каталога проекта от `root`:

```bash
sudo bash local/modules/ss.monitoring.client/tools/server-setup.sh
```

Скрипт определяет корень Bitrix-сайта и физический каталог `upload` из расположения модуля. Затем он:

- создаёт ежедневный cron на 03:15;
- создаёт SFTP-only пользователя `ssbackup` без IP-ограничений;
- хранит архивы вне сайта в `/srv/ss-monitoring/backups`;
- показывает этот каталог внутри SFTP-chroot как `/backups` только для чтения;
- резервирует MySQL/MariaDB, `upload` и настройки Bitrix;
- создаёт SHA-256 manifest для каждой копии и хранит две последние пары архивов и manifest.

Окружение должно предоставлять `mysqldump`, `tar`, `zstd` и PHP CLI. После запуска остаётся только добавить публичный ключ Synology в:

```text
/srv/ss-monitoring/.ssh/authorized_keys
```
