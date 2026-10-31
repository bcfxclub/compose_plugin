#!/bin/bash
# Compose Manager - Backup Cron Script
# Called by cron to create scheduled backups.

PLUGIN_ROOT="/usr/local/emhttp/plugins/compose.manager"
# shellcheck disable=SC1091
. "$PLUGIN_ROOT/scripts/common.sh"

composeLogger "计划备份开始…" info backup daemon

# Execute the backup via the PHP backend
result=$(php -r "
  \$_POST = ['action' => 'createBackup'];
  require_once('${PLUGIN_ROOT}/include/Defines.php');
  require_once('${PLUGIN_ROOT}/include/BackupFunctions.php');
  \$r = createBackup();
  echo json_encode(\$r);
")

# Parse result fields individually to avoid eval
# shellcheck disable=SC2016
status=$(printf '%s' "$result" | php -r '$j = json_decode(file_get_contents("php://stdin"), true) ?: []; echo $j["result"] ?? "error";')
# shellcheck disable=SC2016
message=$(printf '%s' "$result" | php -r '$j = json_decode(file_get_contents("php://stdin"), true) ?: []; echo $j["message"] ?? "Unknown error";')
# shellcheck disable=SC2016
archive=$(printf '%s' "$result" | php -r '$j = json_decode(file_get_contents("php://stdin"), true) ?: []; echo $j["archive"] ?? "";')
# shellcheck disable=SC2016
size=$(printf '%s' "$result" | php -r '$j = json_decode(file_get_contents("php://stdin"), true) ?: []; echo $j["size"] ?? "";')
# shellcheck disable=SC2016
stacks=$(printf '%s' "$result" | php -r '$j = json_decode(file_get_contents("php://stdin"), true) ?: []; echo $j["stacks"] ?? "0";')

if [ "$status" = "success" ]; then
    composeLogger "Scheduled backup completed: $archive ($size, $stacks stacks)" info backup daemon
else
    composeLogger "Scheduled backup FAILED: $message" error backup daemon
fi
