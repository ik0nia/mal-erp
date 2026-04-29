<?php

namespace App\Jobs;

use App\Models\WhPushSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SendWhPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 5;
    public int $timeout = 30;
    public int $backoff = 10; // secunde între reîncercări

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '/wh/',
    ) {}

    public function handle(): void
    {
        $subscriptions = WhPushSubscription::all();
        if ($subscriptions->isEmpty()) return;

        $auth = [
            'VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ];

        $webPush = new WebPush($auth);
        $webPush->setDefaultOptions(['TTL' => 3600]);

        $payload = json_encode([
            'title' => $this->title,
            'body'  => $this->body,
            'url'   => $this->url,
            'icon'  => '/wh-icon-192.png',
            'badge' => '/wh-icon-192.png',
        ]);

        $toDelete = [];

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'keys'            => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $toDelete[] = $report->getEndpoint();
            }
        }

        if ($toDelete) {
            WhPushSubscription::whereIn('endpoint', $toDelete)->delete();
            Log::info('[WH Push] Removed expired subscriptions', ['count' => count($toDelete)]);
        }
    }
}
