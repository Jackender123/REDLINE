<?php
/** Regression: standard review cannot approve an empty bill; zero-cost requires an audit reason. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/repair_cost_review.php';
function expect_review(array $snapshot, string $type, string $reason, bool $accepted): void {
    try {
        rl_cost_review_validate_choice($snapshot, $type, $reason);
        if (!$accepted) throw new LogicException('Incorrectly accepted ' . $type . ' approval.');
    } catch (RuntimeException $e) {
        if ($accepted) throw $e;
    }
}
$empty = ['pending'=>0, 'count'=>0, 'total'=>0.0];
$freeLine = ['pending'=>0, 'count'=>1, 'total'=>0.0];
$paid = ['pending'=>0, 'count'=>1, 'total'=>150000.0];
expect_review($empty, 'standard', '', false);
expect_review($empty, 'zero', '', false);
expect_review($empty, 'zero', 'Warranty repair approved', true);
expect_review($freeLine, 'zero', 'Courtesy inspection', true);
expect_review($paid, 'zero', 'Warranty repair approved', false);
expect_review($paid, 'standard', '', true);
expect_review(['pending'=>1, 'count'=>1, 'total'=>0.0], 'zero', 'Warranty repair approved', false);
expect_review($paid, 'invalid', '', false);
expect_review($empty, 'zero', 'Lý do', false);
expect_review($empty, 'zero', 'Miễn phí bảo hành theo chính sách', true);
echo "Cost review approval validation: PASS\n";
