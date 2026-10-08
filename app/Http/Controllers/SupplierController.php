<?php

namespace App\Http\Controllers;

use App\Models\Supplier;

class SupplierController extends ContactController
{
    protected string $model = Supplier::class;
    protected string $kind = 'suppliers';
    protected string $title = 'Proveedores';
    protected string $foreignKey = 'supplier_id';
}
