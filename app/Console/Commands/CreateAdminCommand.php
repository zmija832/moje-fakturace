<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Bezpečně vytvoří nebo aktualizuje jediný administrátorský účet';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Příkaz je z bezpečnostních důvodů pouze interaktivní.');

            return self::FAILURE;
        }

        $name = $this->ask('Jméno správce');
        $email = mb_strtolower((string) $this->ask('E-mail správce'));
        $password = (string) $this->secret('Heslo (alespoň 12 znaků)');
        $confirmation = (string) $this->secret('Heslo znovu');

        $validator = Validator::make(compact('name', 'email', 'password', 'confirmation'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'same:confirmation', Password::min(12)->letters()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info('Administrátorský účet byl bezpečně uložen.');

        return self::SUCCESS;
    }
}
