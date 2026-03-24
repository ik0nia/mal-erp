<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SocialMedia\TemplateGeneratorService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analizează imaginile de referință vizuală selectate cu Claude AI
 * și generează structuri de template Fabric.js refolosibile.
 *
 * Rulează în background (queue). La final notifică utilizatorul
 * în panoul Filament cu numărul de template-uri create și un link direct.
 */
class AnalyzeReferenceImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;
    public int $backoff = 30;

    public function __construct(
        public readonly array $imagePaths,
        public readonly int   $userId,
    ) {}

    public function handle(TemplateGeneratorService $service): void
    {
        $user = User::find($this->userId);

        try {
            $templates = $service->analyzeAndGenerate($this->imagePaths);
            $count     = count($templates);

            Log::info("AnalyzeReferenceImagesJob: {$count} template-uri generate pentru user #{$this->userId}");

            if ($user) {
                Notification::make()
                    ->title("{$count} template-uri grafice generate")
                    ->body('Șabloanele AI sunt disponibile în Șabloane Grafice pentru editare și rafinare.')
                    ->success()
                    ->actions([
                        Action::make('view')
                            ->label('Deschide șabloane')
                            ->url(route('filament.app.resources.graphic-templates.index'))
                            ->button(),
                    ])
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            Log::error("AnalyzeReferenceImagesJob: Eroare — {$e->getMessage()}", [
                'user_id'     => $this->userId,
                'image_count' => count($this->imagePaths),
                'trace'       => $e->getTraceAsString(),
            ]);

            if ($user) {
                Notification::make()
                    ->title('Eroare la generarea template-urilor')
                    ->body($e->getMessage())
                    ->danger()
                    ->sendToDatabase($user);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error(class_basename(static::class) . ' failed', [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
