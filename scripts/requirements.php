<?php
// Standalone preflight; never reads or prints credentials.
$failed = false;
$checks = ['PHP 8.3 o superior (versión probada)' => PHP_VERSION_ID >= 80300];
foreach (['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo', 'soap', 'tokenizer', 'xml', 'simplexml'] as $extension) {
    $checks['Extensión '.$extension] = extension_loaded($extension);
}
$checks['PDO MySQL o SQLite'] = extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite');
$checks['proc_open para firma ARCA'] = function_exists('proc_open');
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALTA] ').$label.PHP_EOL;
    $failed = $failed || !$ok;
}
foreach (['storage', 'bootstrap/cache'] as $directory) {
    $path = dirname(__DIR__).'/'.$directory;
    $ok = is_dir($path) && is_writable($path);
    echo ($ok ? '[OK] ' : '[FALTA] ').$directory.' con escritura'.PHP_EOL;
    $failed = $failed || !$ok;
}
if (function_exists('proc_open')) {
    $process = proc_open(['openssl', 'version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        $version = trim(stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $ok = proc_close($process) === 0;
        echo ($ok ? '[OK] ' : '[FALTA] ').'OpenSSL ejecutable'.($ok ? ' · '.$version : '').PHP_EOL;
        $failed = $failed || !$ok;
    } else {
        $failed = true;
        echo '[FALTA] OpenSSL ejecutable'.PHP_EOL;
    }
}
exit($failed ? 1 : 0);
