<?php

namespace App\Filament\App\Search;

use App\Filament\App\Resources\CustomerResource;
use App\Filament\App\Resources\SupplierResource;
use App\Filament\App\Resources\WooProductResource;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\WooProduct;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AppGlobalSearchProvider implements GlobalSearchProvider
{
    // Cuvinte cheie care forțează o singură categorie
    private const SUPPLIER_KEYWORDS = ['furnizor', 'furnizori', 'supplier', 'brand', 'brands'];
    private const CUSTOMER_KEYWORDS = ['client', 'clienti', 'clienți', 'cumparator', 'cumpărător'];
    private const PRODUCT_KEYWORDS  = ['produs', 'produse', 'sku', 'articol', 'articole'];

    public function getResults(string $query): ?GlobalSearchResults
    {
        $query = trim($query);

        if (strlen($query) < 2) {
            return null;
        }

        [$category, $searchTerm] = $this->parseQuery($query);

        $builder = GlobalSearchResults::make();

        if ($category === null || $category === 'products') {
            $this->addProducts($builder, $searchTerm);
        }

        if ($category === null || $category === 'customers') {
            $this->addCustomers($builder, $searchTerm);
        }

        if ($category === null || $category === 'suppliers') {
            $this->addSuppliers($builder, $searchTerm);
        }

        return $builder;
    }

    /**
     * Detectează dacă query-ul conține un prefix de categorie.
     * Returnează [null|'products'|'customers'|'suppliers', termenul de căutare].
     */
    private function parseQuery(string $query): array
    {
        $words = preg_split('/\s+/', mb_strtolower($query), 2);
        $first = $words[0] ?? '';
        $rest  = $words[1] ?? '';

        if (in_array($first, self::SUPPLIER_KEYWORDS, true)) {
            return ['suppliers', $rest !== '' ? $rest : $first];
        }

        if (in_array($first, self::CUSTOMER_KEYWORDS, true)) {
            return ['customers', $rest !== '' ? $rest : $first];
        }

        if (in_array($first, self::PRODUCT_KEYWORDS, true)) {
            return ['products', $rest !== '' ? $rest : $first];
        }

        return [null, $query];
    }

    private function addProducts(GlobalSearchResults $builder, string $query): void
    {
        if ($query === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $user = Auth::user();

        $q = WooProduct::query()
            ->select(['id', 'name', 'sku', 'price', 'stock_status'])
            ->where(function (Builder $sub) use ($like): void {
                $sub->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like);
            });

        if ($user && method_exists($user, 'isSuperAdmin') && ! $user->isSuperAdmin()) {
            $locationIds = $user->operationalLocationIds();
            $q->whereHas('stocks', fn (Builder $s) => $s->whereIn('location_id', $locationIds)->where('quantity', '>', 0));
        }

        $products = $q->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$query])
            ->orderBy('name')
            ->limit(8)
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $results = $products->map(function (WooProduct $product): GlobalSearchResult {
            $price = $product->price !== null
                ? number_format((float) $product->price, 2, '.', ',') . ' RON'
                : '—';

            return new GlobalSearchResult(
                title: html_entity_decode((string) $product->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                url: WooProductResource::getUrl('view', ['record' => $product->id]),
                details: ['SKU' => $product->sku ?: '—', 'Preț' => $price],
            );
        });

        $builder->category('Produse', $results);
    }

    private function addCustomers(GlobalSearchResults $builder, string $query): void
    {
        if ($query === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $user = Auth::user();

        $q = Customer::query()
            ->select(['id', 'name', 'type', 'phone', 'email', 'representative_name'])
            ->where('is_active', true)
            ->where(function (Builder $sub) use ($like): void {
                $sub->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('representative_name', 'like', $like);
            });

        if ($user && method_exists($user, 'isSuperAdmin') && ! $user->isSuperAdmin()) {
            $locationIds = $user->operationalLocationIds();
            $q->whereIn('location_id', $locationIds);
        }

        $customers = $q->orderBy('name')->limit(6)->get();

        if ($customers->isEmpty()) {
            return;
        }

        $results = $customers->map(function (Customer $customer): GlobalSearchResult {
            $details = [];

            if ($customer->phone) {
                $details['Telefon'] = $customer->phone;
            }

            if ($customer->email) {
                $details['Email'] = $customer->email;
            }

            $details['Tip'] = $customer->type === Customer::TYPE_COMPANY ? 'Persoană juridică' : 'Persoană fizică';

            return new GlobalSearchResult(
                title: $customer->name,
                url: CustomerResource::getUrl('edit', ['record' => $customer->id]),
                details: $details,
            );
        });

        $builder->category('Clienți', $results);
    }

    /**
     * Caută furnizori după: nume furnizor, telefon, email, nume brand asociat.
     * Afișează furnizorul (nu contactul individual) cu contactele principale.
     */
    private function addSuppliers(GlobalSearchResults $builder, string $query): void
    {
        if ($query === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        $suppliers = Supplier::query()
            ->select(['suppliers.id', 'suppliers.name', 'suppliers.phone', 'suppliers.email'])
            ->where('suppliers.is_active', true)
            ->where(function (Builder $sub) use ($like): void {
                $sub->where('suppliers.name', 'like', $like)
                    ->orWhere('suppliers.phone', 'like', $like)
                    ->orWhere('suppliers.email', 'like', $like)
                    ->orWhereHas('brands', fn (Builder $b) => $b->where('brands.name', 'like', $like))
                    ->orWhereHas('contacts', fn (Builder $c) => $c->where(function (Builder $cc) use ($like): void {
                        $cc->where('name', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    }));
            })
            ->with([
                'brands:id,name',
                'contacts' => fn ($q) => $q->where('is_primary', true)->select(['id', 'supplier_id', 'name', 'phone', 'role']),
            ])
            ->orderBy('suppliers.name')
            ->limit(6)
            ->get();

        if ($suppliers->isEmpty()) {
            return;
        }

        $results = $suppliers->map(function (Supplier $supplier): GlobalSearchResult {
            $details = [];

            $brandNames = $supplier->brands->pluck('name')->join(', ');
            if ($brandNames !== '') {
                $details['Branduri'] = $brandNames;
            }

            $primaryContact = $supplier->contacts->first();
            if ($primaryContact) {
                $details['Contact'] = $primaryContact->name . ($primaryContact->phone ? ' · ' . $primaryContact->phone : '');
            } elseif ($supplier->phone) {
                $details['Telefon'] = $supplier->phone;
            }

            if ($supplier->email) {
                $details['Email'] = $supplier->email;
            }

            return new GlobalSearchResult(
                title: $supplier->name,
                url: SupplierResource::getUrl('edit', ['record' => $supplier->id]),
                details: $details,
            );
        });

        $builder->category('Furnizori', $results);
    }
}
