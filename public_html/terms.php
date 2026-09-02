<?php
// Redirect old terms page to the unified rules page to avoid duplicate content.
header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/kurallar-sartlar', true, 301);
exit;
