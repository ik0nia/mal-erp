<?php

namespace App\Filament\App\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;

class Login extends \Filament\Auth\Pages\Login
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
