<?php

namespace Tests\Unit;

use App\Models\Finding;
use App\Services\RiskScoreCalculator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class RiskScoreCalculatorTest extends TestCase
{
    public function test_clean_portfolio_scores_one_hundred(): void
    {
        $this->assertSame(100, (new RiskScoreCalculator())->score(new Collection()));
    }

    public function test_critical_findings_reduce_score_more_than_review_findings(): void
    {
        $critical = new Finding(['category' => 'Copyright', 'severity' => 'critical', 'confidence' => 95]);
        $review = new Finding(['category' => 'Copyright', 'severity' => 'review', 'confidence' => 95]);
        $calculator = new RiskScoreCalculator();

        $this->assertLessThan(
            $calculator->score(collect([$review])),
            $calculator->score(collect([$critical]))
        );
    }
}

