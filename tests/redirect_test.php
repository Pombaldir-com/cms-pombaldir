<?php
// Basic assertions verifying that redirects are normalized to internal paths
// and that potentially malicious values are rejected.

declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/index.php';

require_once __DIR__ . '/../functions.php';

$cases = [
    'valid path' => ['%2Fdashboard', '/dashboard'],
    'valid path with query' => ['/cms/content.php?tab=seo', '/cms/content.php?tab=seo'],
    'valid path with fragment' => ['/cms/content.php#preview', '/cms/content.php#preview'],
    'external https' => ['https://example.com/', null],
    'protocol-relative' => ['//example.com/path', null],
    'with newline' => ["/cms/foo\r\nLocation: https://evil.com", null],
    'relative without leading slash' => ['dashboard', null],
];

$allPassed = true;
foreach ($cases as $label => [$input, $expected]) {
    $result = normalizeRedirectTarget($input);
    if ($result !== $expected) {
        $allPassed = false;
        fwrite(
            STDERR,
            sprintf(
                "Test '%s' failed: expected %s got %s\n",
                $label,
                var_export($expected, true),
                var_export($result, true)
            )
        );
    }
}

if (!$allPassed) {
    exit(1);
}

echo "All redirect normalization tests passed\n";
