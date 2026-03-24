<?php

namespace App\Filament\App\Forms\Components;

use Filament\Forms\Components\Field;

class PurchaseItemsTable extends Field
{
    protected string $view = 'filament.app.forms.components.purchase-items-table';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dehydrated(false); // starea e gestionată via $purchaseItems (proprietate publică Livewire)
    }
}
