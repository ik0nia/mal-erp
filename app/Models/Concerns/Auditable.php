<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit trail GDPR/securitate — loghează modificările pe modelele sensibile
 * în tabelul `activity_log` (cine, ce, când). Exclude secretele.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(array_merge(
                ['password', 'remember_token', 'warehouse_pin', 'created_at', 'updated_at'],
                property_exists($this, 'auditExcept') ? $this->auditExcept : []
            ))
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('audit')
            ->setDescriptionForEvent(fn (string $event): string => static::auditDescription($event));
    }

    protected static function auditDescription(string $event): string
    {
        $name = class_basename(static::class);

        return match ($event) {
            'created' => "{$name} creat",
            'updated' => "{$name} modificat",
            'deleted' => "{$name} șters",
            'restored' => "{$name} restaurat",
            default => "{$name} {$event}",
        };
    }

    /** Identificator lizibil (nr. document / nume), nu id intern. */
    public function auditLabel(): string
    {
        foreach (['number', 'name', 'title', 'sku', 'label', 'email'] as $f) {
            if (! empty($this->{$f})) {
                return (string) $this->{$f};
            }
        }

        return '#'.$this->getKey();
    }

    /** Reține eticheta lizibilă în properties la momentul logării (rezistă la ștergere). */
    public function tapActivity(\Spatie\Activitylog\Contracts\Activity $activity, string $eventName): void
    {
        $props = $activity->properties ?? collect();
        $activity->properties = $props->put('subject_label', $this->auditLabel());
    }
}
