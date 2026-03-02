<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\EmailEntity;
use App\Models\EmailMessage;
use App\Models\SupplierPriceQuote;
use App\Models\WooProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Procesează un email cu Claude AI și extrage date structurate:
 *  - Clasificare tip email
 *  - Rezumat în română
 *  - Prețuri menționate per produs
 *  - Termeni de livrare și plată
 *  - Urgență și necesitate răspuns
 *
 * Rezultatele se salvează în:
 *  - email_messages.agent_actions (JSON complet)
 *  - email_messages.agent_processed_at
 *  - email_entities (entități individuale)
 *  - supplier_price_quotes (prețuri extrase)
 */
class ProcessEmailAIJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;
    public int $backoff = 30;

    public function __construct(public readonly int $emailId)
    {
    }

    public function handle(): void
    {
        $email = EmailMessage::with(['supplier', 'supplierContact'])->find($this->emailId);

        if (! $email) {
            return;
        }

        // Nu reprocessăm emailuri deja procesate
        if ($email->agent_processed_at) {
            return;
        }

        $apiKey = AppSetting::getEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY);

        if (blank($apiKey)) {
            Log::warning("ProcessEmailAIJob: API key Anthropic lipsă, skip email #{$this->emailId}");
            return;
        }

        // Construim textul emailului pentru analiză
        $emailText = $this->buildEmailText($email);

        if (blank(trim($emailText))) {
            $email->update(['agent_processed_at' => now()]);
            return;
        }

        // Apelăm Claude
        $result = $this->callClaude($apiKey, $email, $emailText);

        if (! $result) {
            return;
        }

        // Salvăm rezultatul principal
        $email->update([
            'agent_actions'      => $result,
            'agent_processed_at' => now(),
        ]);

        // Extragem entitățile și prețurile în tabele dedicate
        $this->saveEntities($email, $result);
        $this->savePriceQuotes($email, $result);
    }

    private function buildEmailText(EmailMessage $email): string
    {
        $parts = [];

        if ($email->subject) {
            $parts[] = "Subiect: {$email->subject}";
        }

        if ($email->from_email) {
            $from = $email->from_name ? "{$email->from_name} <{$email->from_email}>" : $email->from_email;
            $parts[] = "De la: {$from}";
        }

        if ($email->supplier) {
            $parts[] = "Furnizor: {$email->supplier->name}";
        }

        if ($email->sent_at) {
            $parts[] = "Data: " . $email->sent_at->format('d.m.Y H:i');
        }

        $parts[] = "---";

        // Preferăm text plain, altfel extragem din HTML
        $body = '';
        if (filled($email->body_text)) {
            $body = mb_substr(trim($email->body_text), 0, 4000);
        } elseif (filled($email->body_html)) {
            $body = mb_substr(trim(strip_tags($email->body_html)), 0, 4000);
        }

        if (filled($body)) {
            $parts[] = $body;
        }

        // Atașamente (doar numele)
        if ($email->hasAttachments()) {
            $attNames = collect($email->attachments)->pluck('name')->implode(', ');
            $parts[] = "\nAtașamente: {$attNames}";
        }

        return implode("\n", $parts);
    }

    private function callClaude(string $apiKey, EmailMessage $email, string $emailText): ?array
    {
        $supplierContext = $email->supplier
            ? "Furnizorul expeditorului este: {$email->supplier->name}."
            : "Expeditorul nu este asociat unui furnizor cunoscut.";

        $prompt = <<<PROMPT
Ești un asistent ERP pentru o firmă de retail din România. Analizează emailul de afaceri de mai jos și returnează EXCLUSIV un JSON valid (fără text înainte sau după).

{$supplierContext}

EMAIL:
{$emailText}

Returnează JSON cu structura exactă:
{
  "type": "offer|invoice|order_confirmation|delivery_notification|price_list|payment|complaint|inquiry|automated|general",
  "summary": "Rezumat 1-2 propoziții în română",
  "urgency": "low|medium|high",
  "needs_reply": true|false,
  "sentiment": "positive|neutral|negative",
  "products_mentioned": [
    {"name": "...", "sku": null, "quantity": null, "unit": null}
  ],
  "prices_mentioned": [
    {"product": "...", "price": 0.00, "currency": "RON", "unit": null, "min_qty": null}
  ],
  "invoice_number": null,
  "delivery_date": null,
  "payment_terms": null,
  "discount_mentioned": null,
  "action_items": ["..."],
  "key_info": "Informație cheie extrasă dacă există (număr comandă, referință, etc.)"
}

Reguli:
- "type" = automated dacă e newsletter, notificare automată, spam, sau nu e de la o persoană reală
- "prices_mentioned" = listă DOAR cu prețuri explicite din text (nu estimări)
- "delivery_date" = data în format ISO "YYYY-MM-DD" sau null
- Dacă nu există informații pentru un câmp, pune null sau []
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1024,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning("ProcessEmailAIJob: API error {$response->status()} pentru email #{$this->emailId}: " . $response->body());
                return null;
            }

            $content = $response->json('content.0.text', '');
            $content = trim($content);

            // Extragem JSON-ul (poate fi înconjurat de markdown ```json...```)
            if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
                $content = $m[1];
            }

            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning("ProcessEmailAIJob: JSON invalid pentru email #{$this->emailId}: {$content}");
                return null;
            }

            return $decoded;

        } catch (\Throwable $e) {
            Log::error("ProcessEmailAIJob: Excepție pentru email #{$this->emailId}: " . $e->getMessage());
            return null;
        }
    }

    private function saveEntities(EmailMessage $email, array $result): void
    {
        // Prețuri → email_entities
        foreach ($result['prices_mentioned'] ?? [] as $price) {
            if (empty($price['product']) || empty($price['price'])) {
                continue;
            }

            EmailEntity::create([
                'email_message_id' => $email->id,
                'entity_type'      => EmailEntity::TYPE_PRODUCT_PRICE,
                'raw_text'         => "{$price['product']}: {$price['price']} " . ($price['currency'] ?? 'RON'),
                'product_name_raw' => $price['product'],
                'amount'           => $price['price'],
                'currency'         => $price['currency'] ?? 'RON',
                'unit'             => $price['unit'] ?? null,
                'confidence'       => 85,
                'metadata'         => ['min_qty' => $price['min_qty'] ?? null],
            ]);
        }

        // Dată livrare → email_entities
        if (! empty($result['delivery_date'])) {
            try {
                $date = \Carbon\Carbon::parse($result['delivery_date'])->toDateString();
                EmailEntity::create([
                    'email_message_id' => $email->id,
                    'entity_type'      => EmailEntity::TYPE_DELIVERY_DATE,
                    'raw_text'         => $result['delivery_date'],
                    'date_value'       => $date,
                    'confidence'       => 80,
                ]);
            } catch (\Throwable) {
                // data invalidă, skip
            }
        }

        // Număr factură → email_entities
        if (! empty($result['invoice_number'])) {
            EmailEntity::create([
                'email_message_id' => $email->id,
                'entity_type'      => EmailEntity::TYPE_INVOICE_NUMBER,
                'raw_text'         => $result['invoice_number'],
                'confidence'       => 90,
            ]);
        }

        // Termeni plată → email_entities
        if (! empty($result['payment_terms'])) {
            EmailEntity::create([
                'email_message_id' => $email->id,
                'entity_type'      => EmailEntity::TYPE_PAYMENT_TERMS,
                'raw_text'         => $result['payment_terms'],
                'confidence'       => 85,
            ]);
        }

        // Reducere → email_entities
        if (! empty($result['discount_mentioned'])) {
            EmailEntity::create([
                'email_message_id' => $email->id,
                'entity_type'      => EmailEntity::TYPE_DISCOUNT,
                'raw_text'         => $result['discount_mentioned'],
                'confidence'       => 80,
            ]);
        }
    }

    private function savePriceQuotes(EmailMessage $email, array $result): void
    {
        if (! $email->supplier_id) {
            return;
        }

        // Nu salvăm prețuri din emailuri automate/newsletters
        if (in_array($result['type'] ?? '', ['automated', 'general'])) {
            return;
        }

        foreach ($result['prices_mentioned'] ?? [] as $price) {
            if (empty($price['product']) || empty($price['price'])) {
                continue;
            }

            $priceValue = (float) $price['price'];
            if ($priceValue <= 0) {
                continue;
            }

            // Încearcă să potrivim produsul cu catalogul WooCommerce
            $productId = $this->matchProduct($price['product']);

            SupplierPriceQuote::create([
                'supplier_id'      => $email->supplier_id,
                'email_message_id' => $email->id,
                'woo_product_id'   => $productId,
                'product_name_raw' => $price['product'],
                'unit_price'       => $priceValue,
                'currency'         => $price['currency'] ?? 'RON',
                'min_qty'          => $price['min_qty'] ?? null,
                'quoted_at'        => $email->sent_at,
            ]);
        }
    }

    /**
     * Încearcă să potrivească un nume de produs (din email) cu un produs din catalog.
     * Returnează woo_product_id sau null dacă nu găsește.
     */
    private function matchProduct(string $rawName): ?int
    {
        if (strlen($rawName) < 4) {
            return null;
        }

        // Căutare simplă după cuvinte cheie (primele 2-3 cuvinte)
        $words = array_filter(explode(' ', preg_replace('/[^\w\s]/u', ' ', $rawName)));
        $significantWords = array_slice($words, 0, 3);

        if (empty($significantWords)) {
            return null;
        }

        $query = WooProduct::query();
        foreach ($significantWords as $word) {
            if (strlen($word) >= 3) {
                $query->where('name', 'like', "%{$word}%");
            }
        }

        $product = $query->first();

        return $product?->id;
    }
}
