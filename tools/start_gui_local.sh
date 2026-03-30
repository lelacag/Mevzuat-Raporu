#!/bin/bash
# Simple local launcher: opens GUI on current DISPLAY (used when you have X)
set -euo pipefail
cd /srv/www/mevzuatraporu/tools
java -cp . ServerController gui
