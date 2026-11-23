<?php
/** PDF2Biblio — diag terkumpul: lint, wiring, flag, log fatal, uji child pada PDF upload terakhir. */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = __DIR__;
echo "== PHP ==\n", PHP_VERSION, "\nproc_open=", function_exists('proc_open') ? 'OK' : 'DISABLED',
    " shell_exec=", function_exists('shell_exec') ? 'OK' : 'DISABLED',
    " exec=", function_exists('exec') ? 'OK' : 'DISABLED', "\n";
echo "== LINT ==\n";
foreach (['lib/Api.php', 'lib/Extractor.php', 'lib/ParseRunner.php', 'cli_parse.php', 'index.php'] as $f) {
    $out = []; $code = 0;
    if (function_exists('exec')) { @exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $code); echo implode("\n", $out), "\n"; }
    else echo "exec disabled: lewati lint $f\n";
}
echo "== CALL-SITE Api ==\n";
foreach (explode("\n", (string)file_get_contents($root . '/lib/Api.php')) as $i => $ln) {
    if (preg_match('/ParseRunner|ParseGuard|Extractor::extract/', $ln)) echo ($i + 1), ': ', trim($ln), "\n";
}
echo "== PRESET Extractor ==\n";
foreach (explode("\n", (string)file_get_contents($root . '/lib/Extractor.php')) as $i => $ln) {
    if (strpos($ln, 'preset') !== false) echo ($i + 1), ': ', trim($ln), "\n";
}
echo "== FLAGS ==\n";
foreach (glob($root . '/tmp/crash_*') ?: [] as $f) echo basename($f), ' ', filesize($f), "\n";
echo "== TMP PDF (terbaru dulu) ==\n";
$pdfs = glob($root . '/tmp/*.pdf') ?: [];
usort($pdfs, fn($a, $b) => filemtime($b) - filemtime($a));
foreach (array_slice($pdfs, 0, 5) as $f) echo basename($f), ' ', filesize($f), ' ', date('H:i:s', filemtime($f)), "\n";
echo "== DEBUG LOG tail ==\n";
echo implode("\n", array_slice(explode("\n", trim((string)@file_get_contents($root . '/debug.log'))), -12)), "\n";
echo "== ERROR LOGS tail ==\n";
foreach (glob('C:\\laragon\\logs\\*error*.log') ?: [] as $f) {
    echo "--- $f\n";
    echo implode("\n", array_slice(explode("\n", trim((string)@file_get_contents($f))), -25)), "\n";
}
echo "== CHILD TEST (PDF upload terakhir, mesin native) ==\n";
if ($pdfs && function_exists('exec')) {
    $out = $root . '/tmp/diag_child.json';
    @unlink($out);
    $cmd = 'php ' . escapeshellarg($root . '/cli_parse.php') . ' ' . escapeshellarg($pdfs[0]) . ' diag.pdf native ' . escapeshellarg($out);
    $t = microtime(true); $o = []; $c = 0;
    @exec($cmd . ' 2>&1', $o, $c);
    echo 'exit=', $c, ' waktu=', round(microtime(true) - $t, 1), "s\n", implode("\n", array_slice($o, -6)), "\n";
    echo 'json=', is_file($out) ? filesize($out) . ' bytes' : 'TIDAK ADA (child mati)', "\n";
} else echo "tidak ada tmp/*.pdf atau exec disabled\n";
echo "== SELESAI ==\n";