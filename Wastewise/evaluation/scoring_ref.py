"""
Independent Python implementation of the WasteWise scoring formula.

This mirrors includes/scoring.php exactly. It exists so that the offline
evaluation can run on many simulated households quickly, and so the PHP
implementation can be cross-checked: tests/scoring_vectors.json is generated
from THIS file, and tests/run_tests.php checks that the PHP code gives the
same numbers.

If you change a formula or default in scoring.php, change it here too and
regenerate the vectors (python3 evaluation/make_test_vectors.py).
"""

DEFAULT_PARAMS = {
    "w_urgency": 0.4,
    "w_category": 0.2,
    "w_mismatch": 0.4,
    "urgency_horizon": 10,
    "min_history": 3,
    "history_window": 20,
    "baseline_days": 3,
    "fallback_size_exponent": 1.0,
    "default_scale": 1.0,
}

CATEGORY_WEIGHTS = {
    "Dairy": 9,
    "Produce": 8,
    "Meat & Fish": 9,
    "Bread & Bakery": 6,
    "Frozen": 2,
    "Canned Goods": 1,
    "Other": 5,
}

CATEGORY_DEFAULT_DAYS = {
    "Dairy": 4,
    "Produce": 3,
    "Meat & Fish": 2,
    "Bread & Bakery": 4,
    "Frozen": 60,
    "Canned Goods": 180,
    "Other": 7,
}


def params(**overrides):
    p = dict(DEFAULT_PARAMS)
    p.update(overrides)
    return p


def clamp(x, lo, hi):
    return max(lo, min(hi, x))


def category_weight(category):
    return CATEGORY_WEIGHTS.get(category, 5)


def category_default(category, p):
    return CATEGORY_DEFAULT_DAYS.get(category, 7) * p["default_scale"]


def usage_estimate(durations, category, household_size, p):
    """durations: days purchase->use for earlier USED items, most recent first."""
    recent = list(durations)[: int(p["history_window"])]
    n = len(recent)
    if n >= p["min_history"] and n > 0:
        total = 0.0
        for d in recent:
            total += max(0, d)
        return {"days": total / n, "source": "history", "n": n}
    size = max(1, int(household_size))
    days = category_default(category, p) / (size ** p["fallback_size_exponent"])
    return {"days": days, "source": "fallback", "n": n}


def adaptive_score(days_remaining, category, usage_days, p):
    h = p["urgency_horizon"]
    urgency = clamp(h - days_remaining, 0, h) * (10.0 / h)
    cat = category_weight(category)
    mismatch = 0.0
    if usage_days > 0:
        mismatch = clamp((usage_days - days_remaining) / usage_days * 10.0, 0, 10)
    return p["w_urgency"] * urgency + p["w_category"] * cat + p["w_mismatch"] * mismatch


def baseline_flag(days_remaining, p):
    return 0 <= days_remaining <= p["baseline_days"]
