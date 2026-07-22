#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SITE_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/../../../.." && pwd)
CREDENTIALS_FILE=${SS_MONITORING_BEGET_CREDENTIALS:-"$(dirname -- "$SITE_ROOT")/.beget-api-credentials"}
PHP_BIN=${SS_MONITORING_PHP_BIN:-/usr/local/php/cgi/8.2/bin/php}
MARK_SCRIPT=${SS_MONITORING_MARK_SCRIPT:-"${SCRIPT_DIR}/mark.php"}
API_URL=https://api.beget.com/api/backup

[[ -r "$CREDENTIALS_FILE" ]] || {
  echo "Beget API credentials are unavailable: $CREDENTIALS_FILE" >&2
  exit 1
}
[[ -x "$PHP_BIN" ]] || {
  echo "PHP CLI is unavailable: $PHP_BIN" >&2
  exit 1
}
[[ -f "$MARK_SCRIPT" ]] || {
  echo "Backup marker is unavailable: $MARK_SCRIPT" >&2
  exit 1
}

login=$(sed -n '1p' "$CREDENTIALS_FILE")
password=$(sed -n '2p' "$CREDENTIALS_FILE")
login=${login%$'\r'}
password=${password%$'\r'}
[[ -n "$login" && -n "$password" ]] || {
  echo 'Beget API credentials must contain the login and password on separate lines.' >&2
  exit 1
}

temporary_dir=$(mktemp -d)
trap 'rm -rf -- "$temporary_dir"' EXIT
chmod 0700 "$temporary_dir"
printf '%s' "$login" > "$temporary_dir/login"
printf '%s' "$password" > "$temporary_dir/password"

request_backup_list()
{
  local method=$1
  curl --fail --silent --show-error --get \
    --connect-timeout 10 \
    --max-time 30 \
    --data-urlencode "login@${temporary_dir}/login" \
    --data-urlencode "passwd@${temporary_dir}/password" \
    --data 'output_format=json' \
    "${API_URL}/${method}"
}

extract_latest_date()
{
  local response=$1
  local pattern='"date"[[:space:]]*:[[:space:]]*"([0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2})"'
  if [[ $response =~ $pattern ]]; then
    printf '%s\n' "${BASH_REMATCH[1]}"
    return
  fi
  return 1
}

file_response=$(request_backup_list getFileBackupList)
mysql_response=$(request_backup_list getMysqlBackupList)
file_date=$(extract_latest_date "$file_response") || {
  echo 'Beget API returned no file backups.' >&2
  exit 1
}
mysql_date=$(extract_latest_date "$mysql_response") || {
  echo 'Beget API returned no MySQL backups.' >&2
  exit 1
}

backup_date=$file_date
if [[ $mysql_date < $backup_date ]]; then
  backup_date=$mysql_date
fi

"$PHP_BIN" "$MARK_SCRIPT" backup-success "$backup_date"
printf 'Beget backup confirmed: files=%s, mysql=%s, recorded=%s\n' "$file_date" "$mysql_date" "$backup_date"
