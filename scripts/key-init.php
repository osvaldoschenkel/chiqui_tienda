<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (filled(config('app.key'))) {
    echo "APP_KEY existente conservada.\n";
    exit(0);
}
// Never replace a lost key on an existing installation.
try {
    if (Illuminate\Support\Facades\Schema::hasTable('users')) {
        fwrite(STDERR, "La base ya contiene el sistema y falta APP_KEY. Restaurá la clave original desde el respaldo; no generes una nueva.\n");
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "No se pudo comprobar la base. Revisá DB_CONNECTION, host, base y usuario antes de inicializar.\n");
    exit(1);
}
exit($app->make(Illuminate\Contracts\Console\Kernel::class)->call('key:generate', ['--force' => true]));
