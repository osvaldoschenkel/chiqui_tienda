<?php

namespace App\Http\Controllers;

use App\Models\Customer;

class CustomerController extends ContactController
{
    protected string $model = Customer::class;
    protected string $kind = 'customers';
    protected string $title = 'Clientes';
    protected string $foreignKey = 'customer_id';
}
