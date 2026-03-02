<?php

namespace App\Filament\App\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;

class Login extends \Filament\Pages\Auth\Login
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        // Forțează redirect la dashboard după login, indiferent de url.intended
        if ($response !== null) {
            session()->put('url.intended', url('/'));
        }

        return $response;
    }
}
