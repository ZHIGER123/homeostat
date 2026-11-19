<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;

class UploadQuotaService
{
    public function assertCanUpload(User $user, int $duration): void
    {
        $plan = $user->currentPlan();
        $limit = config("plans.{$plan}.upload_seconds");
        if ($limit === null) return;

        $used = (int) $user->tracks()->where('created_at', '>=', now()->startOfMonth())->sum('duration');
        if ($used + $duration > $limit) {
            $available = max(0, $limit - $used);
            throw new BusinessException("Лимит загрузок тарифа {$plan} исчерпан. Осталось секунд: {$available}. Выберите Artist Pro для безлимитных загрузок.");
        }
    }

    public function usage(User $user): array
    {
        $plan = $user->currentPlan();
        $limit = config("plans.{$plan}.upload_seconds");
        $used = (int) $user->tracks()->where('created_at', '>=', now()->startOfMonth())->sum('duration');
        return ['plan' => $plan, 'used_seconds' => $used, 'limit_seconds' => $limit, 'unlimited' => $limit === null, 'percent' => $limit ? min(100, round($used / $limit * 100, 1)) : 0];
    }
}
