#!/bin/bash
# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"
composeLogger "阵列未启动，脚本中止" warning compose
echo "必须启动阵列才能运行此脚本"

