<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateCustomer extends Command
{
    protected $signature = 'customer:create {email? : Correo del cliente} {--name= : Nombre}';

    protected $description = 'Crea un usuario cliente con contraseña ingresada de forma oculta';

    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->argument('email') ?: $this->ask('Correo electrónico'))));
        $name = $this->option('name') ?: $this->ask('Nombre', 'Cliente');
        $password = $this->secret('Contraseña (mínimo 12 caracteres)');
        $confirmation = $this->secret('Repetir contraseña');
        $validator = Validator::make([
            'email' => $email, 'name' => $name,
            'password' => $password, 'password_confirmation' => $confirmation,
        ], [
            'email' => 'required|email|max:255|unique:users,email',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:12|confirmed',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['email' => $email, 'name' => $name, 'password' => $password]);
        $user->role = 'customer';
        $user->save();
        $this->info('Cliente creado. Ya podés ingresar a la tienda.');

        return self::SUCCESS;
    }
}
