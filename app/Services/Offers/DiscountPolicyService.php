<?php

namespace App\Services\Offers;

use App\Models\DiscountPolicy;
use App\Models\User;
use App\Models\WooProduct;
use Illuminate\Support\Collection;

/**
 * Rezolvă plafonul de discount permis unui utilizator pentru un produs,
 * pe baza politicilor configurate (rol / user × toate / categorie / furnizor).
 *
 * Precedență (cea mai specifică politică câștigă):
 *   user+categorie/furnizor → user+toate → rol+categorie/furnizor → rol+toate → fără limită.
 */
class DiscountPolicyService
{
    /** @var array<int, array{category_ids: int[], supplier_ids: int[]}> */
    private array $productScopeCache = [];

    private ?Collection $policyCache = null;

    /**
     * @return array{max: float|null, approval: float|null, policy: DiscountPolicy|null}
     *   max      = discount maxim fără aprobare (null = nelimitat / fără politică)
     *   approval = discount maxim cu aprobare manager (null = nu există treaptă de aprobare)
     */
    public function resolve(User $user, int $productId): array
    {
        $policies = $this->policiesFor($user);

        if ($policies->isEmpty()) {
            return ['max' => null, 'approval' => null, 'policy' => null];
        }

        $scope = $this->productScope($productId);

        $applicable = $policies->filter(function (DiscountPolicy $p) use ($scope): bool {
            return match ($p->scope_type) {
                DiscountPolicy::SCOPE_ALL      => true,
                DiscountPolicy::SCOPE_CATEGORY => $p->woo_category_id !== null && in_array($p->woo_category_id, $scope['category_ids'], true),
                DiscountPolicy::SCOPE_SUPPLIER => $p->supplier_id !== null && in_array($p->supplier_id, $scope['supplier_ids'], true),
                default => false,
            };
        });

        if ($applicable->isEmpty()) {
            return ['max' => null, 'approval' => null, 'policy' => null];
        }

        // Cea mai specifică; la egalitate, cea mai permisivă (max cel mai mare).
        $winner = $applicable
            ->sortByDesc(fn (DiscountPolicy $p): string => sprintf('%02d.%06.2f', $p->specificity(), (float) $p->max_discount_percent))
            ->first();

        $max = (float) $winner->max_discount_percent;
        $approval = $winner->approval_discount_percent !== null
            ? max($max, (float) $winner->approval_discount_percent)
            : null;

        return ['max' => $max, 'approval' => $approval, 'policy' => $winner];
    }

    /**
     * Doar plafonul fără aprobare (helper rapid).
     */
    public function maxDiscount(User $user, int $productId): ?float
    {
        return $this->resolve($user, $productId)['max'];
    }

    private function policiesFor(User $user): Collection
    {
        $this->policyCache ??= DiscountPolicy::query()
            ->where('is_active', true)
            ->get();

        return $this->policyCache->filter(function (DiscountPolicy $p) use ($user): bool {
            if ($p->subject_type === DiscountPolicy::SUBJECT_USER) {
                return (int) $p->user_id === (int) $user->id;
            }

            return $p->role === $user->role;
        })->values();
    }

    /**
     * @return array{category_ids: int[], supplier_ids: int[]}
     */
    private function productScope(int $productId): array
    {
        if (isset($this->productScopeCache[$productId])) {
            return $this->productScopeCache[$productId];
        }

        $product = WooProduct::query()
            ->with(['categories:id', 'suppliers:id'])
            ->find($productId);

        $scope = [
            'category_ids' => $product?->categories->pluck('id')->map(fn ($v) => (int) $v)->all() ?? [],
            'supplier_ids' => $product?->suppliers->pluck('id')->map(fn ($v) => (int) $v)->all() ?? [],
        ];

        return $this->productScopeCache[$productId] = $scope;
    }
}
