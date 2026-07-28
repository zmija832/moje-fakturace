<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConfigureBusinessesCommand extends Command
{
    protected $signature = 'app:configure-businesses';

    protected $description = 'Interaktivně nastaví dva fakturační subjekty a oprávnění správce';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Příkaz je z bezpečnostních důvodů pouze interaktivní.');

            return self::FAILURE;
        }

        $adminEmail = mb_strtolower((string) $this->ask('E-mail existujícího správce'));
        $admin = User::query()->where('email', $adminEmail)->first();

        if (! $admin) {
            $this->error('Správce neexistuje. Nejprve spusťte app:create-admin.');

            return self::FAILURE;
        }

        foreach (['business_1', 'business_2'] as $index => $connectionName) {
            $this->newLine();
            $this->info('Nastavení subjektu '.($index + 1));

            $attributes = [
                'display_name' => (string) $this->ask('Zobrazovaný název'),
                'registration_number' => preg_replace('/\D+/', '', (string) $this->ask('IČO')),
                'short_label' => (string) $this->ask('Krátké označení'),
                'visual_identifier' => $this->choice('Ikona', ['briefcase', 'home-business'], $index),
                'connection_name' => $connectionName,
                'sort_order' => $index + 1,
                'is_active' => $this->confirm('Je subjekt aktivní?', true),
            ];

            $validator = Validator::make($attributes, [
                'display_name' => ['required', 'string', 'max:255'],
                'registration_number' => ['required', 'digits:8'],
                'short_label' => ['required', 'string', 'max:32'],
                'visual_identifier' => ['required', 'in:briefcase,home-business'],
                'connection_name' => ['required', 'in:business_1,business_2'],
                'sort_order' => ['required', 'integer', 'between:1,2'],
                'is_active' => ['required', 'boolean'],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $business = Business::query()->updateOrCreate(
                ['connection_name' => $connectionName],
                ['uuid' => Business::query()->where('connection_name', $connectionName)->value('uuid') ?? (string) Str::uuid()]
                    + $validator->validated(),
            );

            $admin->businesses()->syncWithoutDetaching([$business->id => ['role' => 'admin']]);
        }

        $this->info('Oba subjekty a oprávnění správce byly uloženy.');

        return self::SUCCESS;
    }
}
