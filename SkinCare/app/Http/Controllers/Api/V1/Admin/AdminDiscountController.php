<?php

namespace App\Http\Controllers\Api\V1\Admin;

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
        $query = DiscountRule::query()->latest('id');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }
        if ($request->query->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOL));
        }

        $rules = $query->paginate(min(max((int) $request->query('per_page', 25), 1), 100))
            ->through(fn (DiscountRule $rule) => $this->payload($rule));

        return response()->json($rules);
    }

    public function store(StoreDiscountRuleRequest $request): JsonResponse
    {
        $rule = DB::transaction(function () use ($request): DiscountRule {
            $data = $request->validated();
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
            $data = $request->validated();
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
            'value' => $rule->value,
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
        return $rule->only([
            'code', 'name', 'kind', 'value', 'min_subtotal_irr', 'max_discount_irr',
            'starts_at', 'ends_at', 'usage_limit_total', 'usage_limit_per_user', 'is_active',
        ]);
    }
}
