<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DiscountKind;
use App\Enums\DiscountRedemptionStatus;
use App\Exceptions\CheckoutConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDiscountRuleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDiscountRuleRequest;
use App\Models\DiscountRedemption;
use App\Models\DiscountRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminDiscountController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = DiscountRule::query()->latest('id');

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }
        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $rules = $query->paginate((int) ($validated['per_page'] ?? 25))
            ->through(fn (DiscountRule $rule) => $this->payload($rule));

        return response()->json($rules);
    }

    public function store(StoreDiscountRuleRequest $request): JsonResponse
    {
        $rule = DB::transaction(function () use ($request): DiscountRule {
            $data = $this->mapApiValues($request->validated(), null);
            $data['created_by'] = $request->user()->getKey();
            $data['updated_by'] = $request->user()->getKey();
            $rule = DiscountRule::query()->create($data);

            $this->auditLogger->record(
                actor: $request->user(),
                action: 'discount.created',
                subject: $rule,
                changes: $this->auditable($rule),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $rule;
        });

        return response()->json(['data' => $this->payload($rule)], Response::HTTP_CREATED);
    }

    public function update(UpdateDiscountRuleRequest $request, DiscountRule $discountRule): JsonResponse
    {
        $rule = DB::transaction(function () use ($request, $discountRule): DiscountRule {
            $rule = DiscountRule::query()->whereKey($discountRule->getKey())->lockForUpdate()->firstOrFail();
            $data = $this->mapApiValues($request->validated(), $rule);
            $this->assertUsageLimits($rule, $data);

            $before = $this->auditable($rule);
            $rule->fill($data);
            $rule->updated_by = $request->user()->getKey();
            $rule->save();
            $after = $this->auditable($rule);

            if ($before !== $after) {
                $this->auditLogger->record(
                    actor: $request->user(),
                    action: 'discount.updated',
                    subject: $rule,
                    changes: ['before' => $before, 'after' => $after],
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }

            return $rule;
        });

        return response()->json(['data' => $this->payload($rule)]);
    }

    private function mapApiValues(array $data, ?DiscountRule $existing): array
    {
        $kind = $data['kind'] ?? $existing?->kind->value;

        if ($kind === DiscountKind::Fixed->value && array_key_exists('amount_irr', $data)) {
            $data['value'] = $data['amount_irr'];
        }
        if ($kind === DiscountKind::Percentage->value && array_key_exists('percentage_bps', $data)) {
            $data['value'] = $data['percentage_bps'];
        }

        unset($data['amount_irr'], $data['percentage_bps']);

        return $data;
    }

    private function assertUsageLimits(DiscountRule $rule, array $data): void
    {
        $activeStatuses = [
            DiscountRedemptionStatus::Reserved->value,
            DiscountRedemptionStatus::Consumed->value,
        ];

        if (array_key_exists('usage_limit_total', $data) && $data['usage_limit_total'] !== null) {
            $used = DiscountRedemption::query()
                ->where('discount_rule_id', $rule->getKey())
                ->whereIn('status', $activeStatuses)
                ->count();
            if ($used > (int) $data['usage_limit_total']) {
                throw new CheckoutConflictException('سقف مصرف کل نمی‌تواند کمتر از مصرف فعلی باشد.');
            }
        }

        if (array_key_exists('usage_limit_per_user', $data) && $data['usage_limit_per_user'] !== null) {
            $maximumUsedByOneUser = (int) (DiscountRedemption::query()
                ->where('discount_rule_id', $rule->getKey())
                ->whereIn('status', $activeStatuses)
                ->selectRaw('user_id, COUNT(*) AS aggregate')
                ->groupBy('user_id')
                ->get()
                ->max('aggregate') ?? 0);
            if ($maximumUsedByOneUser > (int) $data['usage_limit_per_user']) {
                throw new CheckoutConflictException('سقف مصرف هر کاربر نمی‌تواند کمتر از مصرف فعلی باشد.');
            }
        }
    }

    private function payload(DiscountRule $rule): array
    {
        return [
            'id' => $rule->id,
            'code' => $rule->code,
            'name' => $rule->name,
            'kind' => $rule->kind->value,
            'amount_irr' => $rule->kind === DiscountKind::Fixed ? $rule->value : null,
            'percentage_bps' => $rule->kind === DiscountKind::Percentage ? $rule->value : null,
            'min_subtotal_irr' => $rule->min_subtotal_irr,
            'max_discount_irr' => $rule->max_discount_irr,
            'starts_at' => $rule->starts_at?->toISOString(),
            'ends_at' => $rule->ends_at?->toISOString(),
            'usage_limit_total' => $rule->usage_limit_total,
            'usage_limit_per_user' => $rule->usage_limit_per_user,
            'is_active' => $rule->is_active,
        ];
    }

    private function auditable(DiscountRule $rule): array
    {
        return $this->payload($rule);
    }
}
