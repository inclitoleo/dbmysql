<?php

declare(strict_types=1);

$path = $argv[1] ?? 'coverage.xml';
if (!is_file($path)) {
    fwrite(STDERR, "Coverage file not found: {$path}\n");
    exit(1);
}

$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "Unable to parse coverage file: {$path}\n");
    exit(1);
}

$prefixes = [
    '/src/Connection/',
    '/src/Query/',
    '/src/Security/',
    '/src/Exception/',
    '/src/Mapper/',
];

$covered = 0;
$total = 0;

foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = (string) $file['name'];
    $match = false;
    foreach ($prefixes as $prefix) {
        if (str_contains($name, $prefix)) {
            $match = true;
            break;
        }
    }
    if (!$match) {
        continue;
    }

    foreach ($file->xpath('.//line') ?: [] as $line) {
        $type = (string) $line['type'];
        if ($type !== 'stmt' && $type !== 'method') {
            continue;
        }
        $count = (int) $line['count'];
        $total++;
        if ($count > 0) {
            $covered++;
        }
    }
}

if ($total === 0) {
    fwrite(STDERR, "No v3 coverage data found in {$path}\n");
    exit(1);
}

$percent = ($covered / $total) * 100;
$formatted = number_format($percent, 2);
echo "v3 coverage: {$formatted}% ({$covered}/{$total})\n";

if ($percent < 90) {
    fwrite(STDERR, "Coverage {$formatted}% is below the 90% requirement.\n");
    exit(1);
}
