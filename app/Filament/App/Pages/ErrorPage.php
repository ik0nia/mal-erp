<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;

class ErrorPage extends Page
{
    protected string  $view             = 'filament.app.pages.error-page';
    protected static bool    $shouldRegisterNavigation = false;

    public int    $code    = 403;
    public string $message = '';

    public function mount(int $code = 403): void
    {
        $this->code = $code;

        $this->message = match ($code) {
            403 => 'Nu ai acces la această pagină.',
            404 => 'Pagina nu a fost găsită.',
            500 => 'A apărut o eroare de server.',
            default => 'A apărut o eroare neașteptată.',
        };
    }

    public static function canAccess(): bool
    {
        return true; // Mereu accesibil
    }

    public function getTitle(): string
    {
        return match ($this->code) {
            403 => 'Acces restricționat',
            404 => 'Pagina nu există',
            default => 'Eroare',
        };
    }
}
