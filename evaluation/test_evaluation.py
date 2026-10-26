"""
Tests for the evaluation harness itself. Run:  python3 evaluation/test_evaluation.py

These exist because an evaluation can look impressive while being wrong.
The most important one is the label-shuffle test: if outcomes are assigned
at random, neither approach should beat chance. If the adaptive score still
did, information from the future would be leaking into the predictions.
"""
import json
import os
import random
import sys

sys.path.insert(0, os.path.dirname(__file__))
from evaluate import (build_history, build_index, history_before, load_items, pairwise_auc, replay, run,
                      summarise)
from scoring_ref import params, usage_estimate, adaptive_score, baseline_flag
from simulate import generate

passed = failed = 0


def test(name):
    def deco(fn):
        global passed, failed
        try:
            fn()
            passed += 1
            print("  PASS ", name)
        except AssertionError as e:
            failed += 1
            print("  FAIL ", name, "->", e)
        return fn
    return deco


def item(i, h, cat, p, e, outcome, o, size=2):
    return {"id": i, "h": h, "size": size, "cat": cat, "p": p, "e": e, "outcome": outcome, "o": o}


print("Pairwise AUC")


@test("perfect ranking gives 1.0")
def _():
    assert pairwise_auc([3], [1, 2]) == 1.0


@test("reversed ranking gives 0.0")
def _():
    assert pairwise_auc([1], [2, 3]) == 0.0


@test("ties count half")
def _():
    assert pairwise_auc([1], [1]) == 0.5
    assert pairwise_auc([1, 2], [1.5]) == 0.5


print("\nNo leakage from the future")


@test("history uses only outcomes strictly before the review day")
def _():
    items = [item(1, 1, "Dairy", 0, 20, "used", 4), item(2, 1, "Dairy", 1, 20, "used", 8),
             item(3, 1, "Dairy", 2, 20, "used", 12)]
    hist = build_history(items)
    cat = hist[1]["Dairy"]
    assert history_before(cat, 4, 20) == []            # outcome on day 4 is NOT before day 4
    assert history_before(cat, 5, 20) == [4]           # durations: 4-0
    assert history_before(cat, 9, 20) == [7, 4]        # most recent first
    assert history_before(cat, 100, 20) == [10, 7, 4]


@test("an item's own outcome never enters the history used to score it")
def _():
    items = [item(1, 1, "Dairy", 0, 30, "used", 3), item(2, 1, "Dairy", 0, 30, "used", 4),
             item(3, 1, "Dairy", 0, 30, "used", 5),
             item(99, 1, "Dairy", 10, 14, "used", 11)]   # the item being scored
    hist = build_history(items)
    assert len(history_before(hist[1]["Dairy"], 10, 20)) == 3
    # on its own outcome day it is still not in the history...
    assert history_before(hist[1]["Dairy"], 11, 20) == [5, 4, 3]
    # ...and appears (most recent first) only from the day AFTER
    assert history_before(hist[1]["Dairy"], 12, 20) == [1, 5, 4, 3]


@test("changing a FUTURE outcome does not change an earlier score")
def _():
    base = [item(1, 1, "Dairy", 0, 30, "used", 3), item(2, 1, "Dairy", 0, 30, "used", 4),
            item(3, 1, "Dairy", 0, 30, "used", 5)]
    a = base + [item(9, 1, "Dairy", 10, 14, "used", 13)]
    b = base + [item(9, 1, "Dairy", 10, 14, "wasted", 15)]
    t = 12
    p = params()
    out = []
    for data in (a, b):
        hist = build_history(data)
        d = history_before(hist[1]["Dairy"], t, 20)
        u = usage_estimate(d, "Dairy", 2, p)["days"]
        out.append(adaptive_score(14 - t, "Dairy", u, p))
    assert out[0] == out[1], out


print("\nReplay mechanics")


@test("candidate window: active from max(purchase, expiry-horizon) to min(outcome, expiry)")
def _():
    items = [item(1, 1, "Dairy", 0, 20, "used", 15)]
    idx = build_index(items, 7)
    days = sorted(idx[1].keys())
    assert days[0] == 13 and days[-1] == 15, days


@test("unknown and active items are never candidates")
def _():
    items = [item(1, 1, "Dairy", 0, 10, "unknown", None), item(2, 1, "Dairy", 0, 10, "active", None)]
    assert len(build_index(items, 7)) == 0


@test("urgency-only weights reproduce the baseline exactly")
def _():
    data = load_items(generate(seed=5, n_households=8, n_days=90))
    hist = build_history(data)
    s, _ = run(data, hist, params(w_urgency=1.0, w_category=0.0, w_mismatch=0.0))
    assert abs(s["auc_adaptive"] - s["auc_baseline"]) < 1e-12
    assert s["precision_adaptive"] == s["precision_baseline"]


@test("both approaches get the same number of alerts (matched volume)")
def _():
    data = load_items(generate(seed=6, n_households=8, n_days=90))
    s, per_h = run(data, build_history(data), params())
    # false alarms + hits = alerts for each approach
    assert s["false_alarms_adaptive"] + round(s["precision_adaptive"] * s["alerts_per_approach"]) == s["alerts_per_approach"]
    assert s["false_alarms_baseline"] + round(s["precision_baseline"] * s["alerts_per_approach"]) == s["alerts_per_approach"]


print("\nLabel-shuffle null test (the leakage check)")


@test("with outcomes assigned at random, neither approach beats chance")
def _():
    # One shuffle is noisy (waste is rare and days within a household are
    # correlated), so use the average of several shuffles.
    rng = random.Random(2024)
    data = load_items(generate(seed=3, n_households=25, n_days=150))
    eligible = [d for d in data if d["outcome"] in ("used", "wasted")]
    labels = [d["outcome"] for d in eligible]
    adaptive, baseline = [], []
    for rep in range(12):
        rng.shuffle(labels)
        shuffled = []
        for d, lab in zip(eligible, labels):
            c = dict(d)
            c["outcome"] = lab
            shuffled.append(c)
        s, _ = run(shuffled, build_history(shuffled), params())
        adaptive.append(s["auc_adaptive"])
        baseline.append(s["auc_baseline"])
    mean_a = sum(adaptive) / len(adaptive)
    mean_b = sum(baseline) / len(baseline)
    print("        mean shuffled AUC: adaptive %.3f, baseline %.3f (chance = 0.500)" % (mean_a, mean_b))
    assert abs(mean_a - 0.5) < 0.02, ("adaptive", mean_a)
    assert abs(mean_b - 0.5) < 0.02, ("baseline", mean_b)

    # The real (unshuffled) result must stand clearly above that null spread.
    real, _ = run(load_items(generate(seed=3, n_households=25, n_days=150)),
                  build_history(load_items(generate(seed=3, n_households=25, n_days=150))), params())
    print("        real AUC: adaptive %.3f, baseline %.3f; highest shuffled adaptive %.3f"
          % (real["auc_adaptive"], real["auc_baseline"], max(adaptive)))
    assert real["auc_adaptive"] > max(adaptive)
    assert real["auc_baseline"] > max(baseline)


print("\nScoring reference versus shared vectors")


@test("scoring_ref reproduces tests/scoring_vectors.json")
def _():
    path = os.path.join(os.path.dirname(__file__), "..", "tests", "scoring_vectors.json")
    vectors = json.load(open(path))
    assert len(vectors) > 20
    for v in vectors:
        p = params(**v["params"])
        u = usage_estimate(v["durations"], v["category"], v["household_size"], p)
        s = adaptive_score(v["days_remaining"], v["category"], u["days"], p)
        assert abs(s - v["expected_score"]) < 1e-12, v["name"]
        assert baseline_flag(v["days_remaining"], p) == v["expected_baseline_flag"], v["name"]


print(f"\nResult: {passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
