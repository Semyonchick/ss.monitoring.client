#!/usr/bin/env bash
set -euo pipefail

# Run once as root: sudo bash server-setup.sh
# The site root and upload directory are derived from this module's location.

if [[ ${EUID} -ne 0 ]]; then
  echo 'Run this script as root.' >&2
  exit 1
fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/../../../.." && pwd)
BACKUP_USER=ssbackup
BACKUP_GROUP=ssmonitoring
BACKUP_ROOT=/srv/ss-monitoring
BACKUP_DIR=${BACKUP_ROOT}/backups
PHP_BIN=${PHP_BIN:-$(command -v php)}

[[ -f "${SITE_ROOT}/bitrix/modules/main/include/prolog_before.php" ]] || {
  echo "Bitrix root was not found near this module: ${SITE_ROOT}" >&2
  exit 1
}
[[ -d "${SITE_ROOT}/upload" ]] || {
  echo "Bitrix upload directory was not found near this module: ${SITE_ROOT}/upload" >&2
  exit 1
}

UPLOAD_DIR=$(CDPATH= cd -- "${SITE_ROOT}/upload" && pwd)
APP_USER=$(stat -c '%U' "${UPLOAD_DIR}")

for command in useradd groupadd install sshd tar zstd mysqldump cp rm "$PHP_BIN"; do
  command -v "$command" >/dev/null 2>&1 || { echo "Required command is missing: $command" >&2; exit 1; }
done
id "$APP_USER" >/dev/null 2>&1 || { echo "Application user does not exist: $APP_USER" >&2; exit 1; }

getent group "$BACKUP_GROUP" >/dev/null || groupadd --system "$BACKUP_GROUP"
id "$BACKUP_USER" >/dev/null 2>&1 || useradd --system --gid "$BACKUP_GROUP" --home-dir "$BACKUP_ROOT" --shell /usr/sbin/nologin --no-create-home "$BACKUP_USER"
usermod -a -G "$BACKUP_GROUP" "$APP_USER"

install -d -m 0755 -o root -g root "$BACKUP_ROOT"
install -d -m 0755 -o root -g root /etc/ssh/sshd_config.d
install -d -m 0700 -o root -g root "$BACKUP_ROOT/.ssh"
touch "$BACKUP_ROOT/.ssh/authorized_keys"
chown root:root "$BACKUP_ROOT/.ssh/authorized_keys"
chmod 0600 "$BACKUP_ROOT/.ssh/authorized_keys"
install -d -m 2750 -o "$APP_USER" -g "$BACKUP_GROUP" "$BACKUP_DIR"
install -d -m 0750 -o "$APP_USER" -g "$BACKUP_GROUP" /var/log/ss-monitoring

cat > /etc/ssh/sshd_config.d/ss-monitoring.conf <<EOF
Match User $BACKUP_USER
  ChrootDirectory $BACKUP_ROOT
  ForceCommand internal-sftp -d /backups
  PasswordAuthentication no
  PermitTTY no
  AllowTcpForwarding no
  X11Forwarding no
EOF

grep -Eq '^\s*Include\s+.*/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config || {
  echo 'OpenSSH does not include /etc/ssh/sshd_config.d/*.conf; configuration was not activated.' >&2
  rm -f /etc/ssh/sshd_config.d/ss-monitoring.conf
  exit 1
}

sshd -t
systemctl reload sshd 2>/dev/null || systemctl reload ssh 2>/dev/null || service ssh reload

cat > /etc/cron.d/ss-monitoring-backup <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 3 * * * $APP_USER umask 027; SS_MONITORING_BACKUP_DIR='$BACKUP_DIR' SS_MONITORING_UPLOAD_DIR='$UPLOAD_DIR' $PHP_BIN '$SCRIPT_DIR/backup.php' >> /var/log/ss-monitoring/backup.log 2>&1
EOF
chmod 0644 /etc/cron.d/ss-monitoring-backup

echo "Setup complete. Add the Synology public key to: $BACKUP_ROOT/.ssh/authorized_keys"
echo "Site root detected automatically: $SITE_ROOT"
echo "Bitrix upload detected automatically: $UPLOAD_DIR"
echo "Synology SFTP user: $BACKUP_USER"
echo "Read-only SFTP directory: /backups"
echo "Local archive directory: $BACKUP_DIR"
