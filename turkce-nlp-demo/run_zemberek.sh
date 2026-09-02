#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAVA_BIN=/usr/bin/java
JAR_PATH="$SCRIPT_DIR/zemberek-full.jar"
export LANG="${LANG:-C.UTF-8}"
export LC_ALL="${LC_ALL:-C.UTF-8}"
exec "$JAVA_BIN" \
  -Dfile.encoding=UTF-8 \
  -Dstdout.encoding=UTF-8 \
  -Dstderr.encoding=UTF-8 \
  -Duser.language=tr \
  -Duser.country=TR \
  -cp "$JAR_PATH:$SCRIPT_DIR" \
  ZemberekSuggest "$@"
