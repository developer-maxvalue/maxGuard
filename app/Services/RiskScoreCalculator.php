<?php

namespace App\Services;

use App\Models\Finding;
use Illuminate\Support\Collection;

final class RiskScoreCalculator
{
    /** @param Collection<int, Finding> $findings */
    public function score(Collection $findings): int
    {
        $weights = ['critical' => 32, 'high' => 18, 'review' => 8, 'info' => 2];
        $penalty = 0.0;

        foreach ($findings->groupBy('category') as $categoryFindings) {
            $ordered = $categoryFindings->sortByDesc(fn (Finding $finding): int => $weights[$finding->severity] ?? 0)->values();
            foreach ($ordered as $index => $finding) {
                $weight = $weights[$finding->severity] ?? 0;
                $confidenceFactor = max(0.35, $finding->confidence / 100);
                $repeatFactor = $index === 0 ? 1.0 : max(0.15, 0.42 / ($index + 1));
                $penalty += $weight * $confidenceFactor * $repeatFactor;
            }
        }

        return max(0, min(100, (int) round(100 - min(95, $penalty))));
    }
}

