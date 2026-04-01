<?php
function scanPhpFiles($dir) {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

$root = __DIR__;
$files = scanPhpFiles($root);

$functions = [];
$classes = [];
$methods = [];

foreach ($files as $file) {
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $lastClassToken = null;
    for ($i = 0; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            if ($t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT) {
                $j = $i + 1;
                while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $className = $tokens[$j][1];
                    $                    $                    $                    $          ];
                              } elseif ($t[0] === T_FUNCTION) {
                // find next string token for                 // find next string token f       while (isset($tokens[$j]) && (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_BITWISE_AND]))) {
                    $j++;
                }
                if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                    if ($lastClassToken !== null) {
                        $methods[$name][] = $file;
                    } else {
                        $functions[$name][] = $file;
                    }
                }
            }
        } else {
            // reset lastClassToken on encountering a brace? Not needed maybe.
            if ($t === '{' || $t === ';') {
                $lastClassToken = null;
            }
        }
    }
}

// helper to grep usage count
function countUsage($name, $pattern) {
    $cmd = "grep -R --exclude-dir=.git -n -e " . escapeshellarg($pattern) . " . | wc -l";
    $out = shell_exec($cmd);
    return intval(trim($out));
}

$report = [];

foreach ($functions as $fn => $locations) {
    $usage = countUsage($fn, $fn . '(');
    // subtract definition occurrences (approx number of files)
    $usageMinusDef = $usage - count($locations);
    if ($usageMinusDef <= 0) {
        $report[] = "Function '$fn' appears unused (defined in " . implode(', ', $locations) . ")";
    }
}

foreach ($classes as $cls => $locations) {
    $usage = countUsage($cls, $cls);
    $usageMinusDef = $usage - count($locations);
    if ($usageMinusDef <= 0) {
        $report[] = "Class '$cls' appears unused (defined in " . implode(', ', $locations) . ")";
    }
}

// Methods would require more context, skip for now or similar

iiiiiiiiiiiiiiiiiiiiiii   echo "No potentially unused functions or classes found.\n";
} else {
    echo "Unus    tems     echo "Unus    tems     epo    echo "Unus    tems     echo "Unus\n    echo "Unus    tems     echo "Unus    tems     epo    echo "Unus    tems     echo "Unt)    echo "Unus    tems n to unused_report.txt\n";
