<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use Livewire\Attributes\On;

abstract class BaseReportPage extends Page
{
    /**
     * Subclasses that register navigation must override this to true.
     */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * If > 0, a wire:poll.{N}s attribute is added to the page wrapper.
     */
    protected int $refreshIntervalSeconds = 0;

    /**
     * Return an associative array of data; each key becomes a public property.
     */
    abstract protected function getReportData(): array;

    public function mount(): void
    {
        $this->loadData();
    }

    protected function loadData(): void
    {
        foreach ($this->getReportData() as $key => $value) {
            $this->{$key} = $value;
        }
    }

    #[On('refresh')]
    public function refresh(): void
    {
        $this->loadData();
    }

    /**
     * Expose the poll interval to the view so blades can add wire:poll if needed.
     */
    public function getRefreshIntervalSeconds(): int
    {
        return $this->refreshIntervalSeconds;
    }
}
