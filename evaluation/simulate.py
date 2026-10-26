"""
Simulated household data for the WasteWise evaluation.

THIS IS A SYSTEM CHECK, NOT REAL-WORLD EVIDENCE. Simulated households can
show that the code behaves sensibly and let the adaptive score and the
fixed-date baseline be compared on identical data. They cannot show that the
approach would reduce food waste in real homes. Real households are used only
as a small descriptive pilot (see docs/EVALUATION_PROTOCOL.md).

HOW THE DATA ARE GENERATED (the complete rules, disclosed on purpose):

  For each household:
    - size is drawn from {1: 25%, 2: 30%, 3: 20%, 4: 20%, 5: 5%}
    - for each food category a "habit multiplier" is drawn from a lognormal
      distribution (sigma 0.45): some households are slow with some foods
  Shopping:
    - each day there is a shopping trip with probability trips_per_week / 7
    - a trip buys 3 to 8 items, categories drawn with fixed weights
    - each item gets a shelf life (days from purchase to expiry) drawn
      uniformly from a category range, and a price of 8 to 80 kr
  Outcome of each item:
    - time_to_use = base_days[category] * habit multiplier
                    * household_size ** -0.3 * lognormal(sigma 0.5)
    - if the item is used within shelf life + 1 day it is 'used', on the
      day purchase + max(1, round(time_to_use))
    - otherwise it is 'wasted', 0 to 2 days after the expiry date
    - 6% of items are recorded as 'unknown' and are excluded from scoring

  Why this does not simply copy the score (avoiding a circular test):
    - the generator's base_days differ from the defaults in scoring.php
    - household size acts as size ** -0.3 here (a weak, sublinear effect),
      whereas the scoring fallback divides by size (a strong, linear
      assumption). The fallback is therefore tested against a deliberate
      mismatch rather than against its own assumption.
    - outcomes are decided by the latent habit and noise, not by the score.
  The generator does not look at the score at any point.
"""
import argparse
import csv
import math
import random
from datetime import date, timedelta

# category: (shelf_min, shelf_max, base_days_to_use, purchase_weight)
CATEGORIES = {
    "Dairy": (5, 12, 4.0, 0.20),
    "Produce": (4, 9, 4.0, 0.25),
    "Meat & Fish": (2, 6, 2.5, 0.12),
    "Bread & Bakery": (3, 7, 4.0, 0.12),
    "Frozen": (60, 180, 40.0, 0.10),
    "Canned Goods": (300, 700, 120.0, 0.10),
    "Other": (20, 120, 25.0, 0.11),
}
SIZE_WEIGHTS = {1: 0.25, 2: 0.30, 3: 0.20, 4: 0.20, 5: 0.05}

COLUMNS = ["item_id", "household_id", "household_size", "category", "purchase_date",
           "expiry_date", "outcome", "outcome_date", "price"]


def generate(seed=1, n_households=40, n_days=180, trips_per_week=2.0,
             habit_sigma=0.45, noise_sigma=0.5, size_effect=0.3,
             unknown_prob=0.06, start=date(2026, 1, 1)):
    rng = random.Random(seed)
    cat_names = list(CATEGORIES)
    cat_weights = [CATEGORIES[c][3] for c in cat_names]
    sizes = list(SIZE_WEIGHTS)
    size_w = [SIZE_WEIGHTS[s] for s in sizes]

    rows = []
    item_id = 0
    for h in range(1, n_households + 1):
        size = rng.choices(sizes, size_w)[0]
        habit = {c: rng.lognormvariate(0.0, habit_sigma) for c in cat_names}
        for d in range(n_days):
            if rng.random() >= trips_per_week / 7.0:
                continue
            purchase = start + timedelta(days=d)
            for _ in range(rng.randint(3, 8)):
                item_id += 1
                cat = rng.choices(cat_names, cat_weights)[0]
                shelf_min, shelf_max, base, _w = CATEGORIES[cat]
                shelf = rng.randint(shelf_min, shelf_max)
                expiry = purchase + timedelta(days=shelf)

                t_use = (base * habit[cat] * (size ** -size_effect)
                         * rng.lognormvariate(0.0, noise_sigma))
                use_days = max(1, round(t_use))
                if use_days <= shelf + 1:
                    outcome = "used"
                    outcome_date = purchase + timedelta(days=use_days)
                else:
                    outcome = "wasted"
                    outcome_date = expiry + timedelta(days=rng.randint(0, 2))
                if rng.random() < unknown_prob:
                    outcome = "unknown"
                    outcome_date = None

                rows.append({
                    "item_id": item_id, "household_id": h, "household_size": size,
                    "category": cat, "purchase_date": purchase.isoformat(),
                    "expiry_date": expiry.isoformat(), "outcome": outcome,
                    "outcome_date": outcome_date.isoformat() if outcome_date else "",
                    "price": round(rng.uniform(8, 80), 2),
                })
    return rows


def write_csv(rows, path):
    with open(path, "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=COLUMNS)
        w.writeheader()
        w.writerows(rows)


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--households", type=int, default=40)
    ap.add_argument("--days", type=int, default=180)
    ap.add_argument("--out", default="data/simulated_items.csv")
    a = ap.parse_args()
    import os
    os.makedirs(os.path.dirname(a.out) or ".", exist_ok=True)
    data = generate(seed=a.seed, n_households=a.households, n_days=a.days)
    write_csv(data, a.out)
    n = len(data)
    wasted = sum(1 for r in data if r["outcome"] == "wasted")
    used = sum(1 for r in data if r["outcome"] == "used")
    unk = sum(1 for r in data if r["outcome"] == "unknown")
    print(f"wrote {n} items to {a.out}: used {used}, wasted {wasted} "
          f"({wasted / max(1, used + wasted):.1%} of confirmed), unknown {unk}")
