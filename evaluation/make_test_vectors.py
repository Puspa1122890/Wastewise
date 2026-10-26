"""Generate tests/scoring_vectors.json from the Python reference implementation."""
import json
import os
from scoring_ref import params, usage_estimate, adaptive_score, baseline_flag

cases = [
    # name, days_remaining, category, household_size, durations (most recent first), overrides
    ("dairy_fallback_size1", 3, "Dairy", 1, [], {}),
    ("dairy_fallback_size2", 3, "Dairy", 2, [], {}),
    ("dairy_fallback_size4", 3, "Dairy", 4, [], {}),
    ("canned_fallback_size1_far_expiry", 100, "Canned Goods", 1, [], {}),
    ("canned_fallback_size3_near_expiry", 2, "Canned Goods", 3, [], {}),
    ("produce_two_items_still_fallback", 4, "Produce", 2, [3, 5], {}),
    ("produce_exactly_three_items_history", 4, "Produce", 2, [3, 5, 4], {}),
    ("meat_history_slow_household", 1, "Meat & Fish", 1, [6, 7, 5, 8], {}),
    ("bread_history_fast_household", 5, "Bread & Bakery", 3, [1, 2, 1, 1], {}),
    ("dairy_history_with_zero_day_use", 2, "Dairy", 2, [0, 0, 1], {}),
    ("expired_item_negative_days", -2, "Dairy", 1, [], {}),
    ("expires_today", 0, "Produce", 2, [3, 3, 3], {}),
    ("far_future", 60, "Other", 1, [], {}),
    ("unknown_category_uses_defaults", 3, "Mystery", 2, [], {}),
    ("history_window_truncates_to_20", 3, "Dairy", 1,
     [2] * 5 + [30] * 30, {}),
    ("min_history_1", 3, "Dairy", 2, [6], {"min_history": 1}),
    ("min_history_5_not_met", 3, "Dairy", 2, [6, 6, 6, 6], {"min_history": 5}),
    ("fallback_exponent_half", 3, "Dairy", 4, [], {"fallback_size_exponent": 0.5}),
    ("fallback_exponent_zero", 3, "Dairy", 4, [], {"fallback_size_exponent": 0.0}),
    ("default_scale_double", 3, "Dairy", 2, [], {"default_scale": 2.0}),
    ("default_scale_half", 3, "Dairy", 2, [], {"default_scale": 0.5}),
    ("urgency_only_weights", 2, "Dairy", 1, [], {"w_urgency": 1.0, "w_category": 0.0, "w_mismatch": 0.0}),
    ("mismatch_heavy_weights", 2, "Dairy", 1, [], {"w_urgency": 0.2, "w_category": 0.2, "w_mismatch": 0.6}),
    ("baseline_lead_7", 6, "Dairy", 1, [], {"baseline_days": 7}),
    ("size_zero_treated_as_one", 3, "Dairy", 0, [], {}),
]

vectors = []
for name, days, cat, size, durations, overrides in cases:
    p = params(**overrides)
    usage = usage_estimate(durations, cat, size, p)
    score = adaptive_score(days, cat, usage["days"], p)
    vectors.append({
        "name": name,
        "days_remaining": days,
        "category": cat,
        "household_size": size,
        "durations": durations,
        "params": overrides,
        "expected_usage_days": usage["days"],
        "expected_source": usage["source"],
        "expected_history_n": usage["n"],
        "expected_score": score,
        "expected_baseline_flag": baseline_flag(days, p),
    })

out = os.path.join(os.path.dirname(__file__), "..", "tests", "scoring_vectors.json")
with open(out, "w") as f:
    json.dump(vectors, f, indent=2)
print("wrote", len(vectors), "vectors to", os.path.normpath(out))
for v in vectors[:8]:
    print(f"{v['name']:45s} score={v['expected_score']:.4f} usage={v['expected_usage_days']:.3f} {v['expected_source']}")
