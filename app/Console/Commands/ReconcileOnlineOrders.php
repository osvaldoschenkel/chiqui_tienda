<?php

namespace App\Console\Commands;

use App\Services\OnlineOrders;
use Illuminate\Console\Command;

class ReconcileOnlineOrders extends Command
{
    protected $signature = 'commerce:reconcile';

    protected $description = 'Conciliar pagos y liberar reservas vencidas sin duplicar stock';

    public function handle(OnlineOrders $orders): int
    {
        $orders->expire();
        $this->info('Conciliación completada.');

        return self::SUCCESS;
    }
}
