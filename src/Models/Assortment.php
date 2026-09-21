<?php

namespace Dashed\DashedEcommerceReseller\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Dashed\DashedEcommerceCore\Models\Product;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Dashed\DashedEcommerceCore\Models\ProductCategory;
use Dashed\DashedEcommerceReseller\Enums\StockDisplay;

/**
 * Wat een afnemer mag zien. Een product zit erin als het publiek is, op de
 * site staat, door minstens één opname-regel geraakt wordt en door geen
 * enkele uitsluiting. Zonder regels is een assortiment leeg, niet "alles".
 */
class Assortment extends Model
{
    use SoftDeletes;

    public const RULE_TYPES = ['category', 'product_group', 'product'];

    public const FORM_FIELDS = [
        'include_category', 'include_product_group', 'include_product',
        'exclude_category', 'exclude_product_group', 'exclude_product',
    ];

    protected $table = 'dashed__reseller_assortments';

    protected $fillable = ['name', 'site_id', 'stock_display', 'stock_cap'];

    protected $attributes = ['stock_display' => 'exact'];

    protected $casts = [
        'stock_display' => StockDisplay::class,
        'stock_cap' => 'integer',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(AssortmentRule::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(ResellerProfile::class);
    }

    /**
     * Bewust whereJsonContains en niet thisSite(): dit draait ook in jobs,
     * en daar is er geen verzoek dat de site bepaalt.
     */
    public function productQuery(): Builder
    {
        $include = $this->ruleIds(AssortmentRule::MODE_INCLUDE);
        $exclude = $this->ruleIds(AssortmentRule::MODE_EXCLUDE);

        $query = Product::query()
            ->where('dashed__products.public', 1)
            ->whereJsonContains('dashed__products.site_ids', $this->site_id);

        if ($include['category'] === [] && $include['product_group'] === [] && $include['product'] === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $any) use ($include) {
            if ($include['product'] !== []) {
                $any->orWhereIn('dashed__products.id', $include['product']);
            }

            if ($include['product_group'] !== []) {
                $any->orWhereIn('dashed__products.product_group_id', $include['product_group']);
            }

            if ($include['category'] !== []) {
                $any->orWhereIn('dashed__products.id', self::productIdsInCategories($include['category']));
            }
        });

        if ($exclude['product'] !== []) {
            $query->whereNotIn('dashed__products.id', $exclude['product']);
        }

        if ($exclude['product_group'] !== []) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('dashed__products.product_group_id')
                ->orWhereNotIn('dashed__products.product_group_id', $exclude['product_group']));
        }

        if ($exclude['category'] !== []) {
            $query->whereNotIn('dashed__products.id', self::productIdsInCategories($exclude['category']));
        }

        return $query;
    }

    /**
     * @return array{category: list<int>, product_group: list<int>, product: list<int>}
     */
    public function ruleIds(string $mode): array
    {
        $rules = $this->relationLoaded('rules') ? $this->rules : $this->rules()->get();
        $ids = array_fill_keys(self::RULE_TYPES, []);

        foreach ($rules->where('mode', $mode) as $rule) {
            $ids[$rule->type][] = (int) $rule->target_id;
        }

        $ids['category'] = self::withDescendants($ids['category']);

        return $ids;
    }

    public function syncRules(array $rules): void
    {
        DB::transaction(function () use ($rules) {
            $this->rules()->delete();

            foreach ([AssortmentRule::MODE_INCLUDE, AssortmentRule::MODE_EXCLUDE] as $mode) {
                foreach (self::RULE_TYPES as $type) {
                    foreach (array_unique(array_map('intval', $rules[$mode][$type] ?? [])) as $targetId) {
                        // Staat een doel in beide lijsten, dan wint uitsluiten:
                        // de unieke sleutel laat maar één rij toe.
                        $this->rules()->updateOrCreate(
                            ['type' => $type, 'target_id' => $targetId],
                            ['mode' => $mode],
                        );
                    }
                }
            }
        });

        $this->unsetRelation('rules');

        // touch() vuurt saved, en daar hangt de herberekening van de
        // catalogi aan.
        $this->touch();
    }

    public function rulesForForm(): array
    {
        $data = array_fill_keys(self::FORM_FIELDS, []);

        foreach ($this->rules()->get() as $rule) {
            $data[$rule->mode . '_' . $rule->type][] = (int) $rule->target_id;
        }

        return $data;
    }

    public static function rulesFromForm(array $data): array
    {
        $rules = [];

        foreach ([AssortmentRule::MODE_INCLUDE, AssortmentRule::MODE_EXCLUDE] as $mode) {
            foreach (self::RULE_TYPES as $type) {
                $rules[$mode][$type] = array_values(array_filter((array) ($data[$mode . '_' . $type] ?? [])));
            }
        }

        return $rules;
    }

    private static function productIdsInCategories(array $categoryIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('dashed__product_category')
            ->select('product_id')
            ->whereIn('product_category_id', $categoryIds);
    }

    /**
     * Een categorie opnemen neemt ook alles eronder op.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function withDescendants(array $ids): array
    {
        $all = array_values(array_unique($ids));
        $frontier = $all;

        while ($frontier !== []) {
            $children = ProductCategory::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $all));
            $all = array_merge($all, $frontier);
        }

        return $all;
    }
}
