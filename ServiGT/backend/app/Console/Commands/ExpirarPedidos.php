<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpirarPedidos extends Command
{
    protected $signature = 'pedidos:expirar';

    protected $description = 'Marca como expirados los pedidos abiertos cuya fecha_expiracion ya paso';

    public function handle(): int
    {
        $total = Pedido::where('estado', 'abierto')
            ->where('fecha_expiracion', '<=', now())
            ->update(['estado' => 'expirado']);

        Log::info("pedidos:expirar — {$total} pedido(s) marcados como expirados.");
        $this->info("{$total} pedido(s) expirados.");

        return self::SUCCESS;
    }
}
