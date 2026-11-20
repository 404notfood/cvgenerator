<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::command('cv:admin {email} {--verify-local : Valider l’e-mail pour un compte local}', function (string $email): int {
    if ($this->option('verify-local') && ! app()->isLocal()) {
        $this->error('La validation directe est réservée à l’environnement local.');

        return 1;
    }

    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error('Ce compte doit être créé avant de lui attribuer le rôle administrateur.');

        return 1;
    }

    $user->forceFill(['role' => 'admin'])->save();
    if ($this->option('verify-local') && ! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }
    $this->info('Rôle administrateur attribué.');

    return 0;
})->purpose('Attribuer le rôle administrateur à un compte existant');
