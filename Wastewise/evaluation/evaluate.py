"""
Offline evaluation: adaptive waste-risk score versus the fixed-date baseline.

The full protocol and the reasons for each choice are in
docs/EVALUATION_PROTOCOL.md. In short:

  CHRONOLOGICAL REPLAY (no leakage)
    The data are replayed one day at a time. On each review day t, for each
    household, the system sees only what was known on day t: items bought on
    or before t that have no recorded outcome before t, and the history of
    items used STRICTLY BEFORE t. Outcomes on or after t are never used to
    score day t; they are kept only to judge the prediction afterwards.

  ELIGIBLE ITEMS (the "candidate list" on a review day)
    Items that are active on day t (bought, not yet used or thrown away) and
    expire within `horizon` days (default 7). Items with an unknown outcome
    are excluded because they cannot be judged (and are counted).

  THE TWO APPROACHES
    Baseline : ranks candidates by days until expiry (soonest first) and
               flags every item within `baseline_days` (default 3) of expiry.
    Adaptive : ranks candidates by the adaptive risk score
               (scoring_ref.adaptive_score), using only history before day t.

  PRIMARY MEASURE: ranking AUC.
    On each review day, the probability that a randomly chosen item that was
    LATER thrown away is ranked above a randomly chosen item that was LATER
    used (ties count half). 0.5 means no better than chance. Reported as the
    mean over review days, with a 95% interval from a household-level
    bootstrap.

  SUPPORTING MEASURES (alert-volume matched)
    On days where the baseline flags k items, the adaptive approach is also
    given exactly k alerts (its top k). Both then have the same alert budget,
    so we can compare:
      precision      = flagged items that were later thrown away / flagged
      false alarms   = flagged items that were later used
      missed waste   = items later thrown away that were not flagged
      recall         = share of later-wasted items that were flagged

Usage:
    python3 evaluate.py --data data/simulated_items.csv --out results
    python3 evaluate.py --data wastewise_household_1.csv --out results_real --no-replications
"""
import argparse
import csv
import os
import random
from bisect import bisect_left
from collections import defaultdict
from datetime import date

from scoring_ref import params as make_params, usage_estimate, adaptive_score

# ----------------------------------------------------------------------
# Loading and indexing
# ----------------------------------------------------------------------

def to_ord(s):
    return date.fromisoformat(s).toordinal() if s else None


def load_items(rows):
    """rows: list of dicts with the standard column names (strings)."""
    items = []
    for r in rows:
        items.append({
            "id": int(r["item_id"]),
            "h": int(r["household_id"]),
            "size": int(r["household_size"]),
            "cat": r["category"],
            "p": to_ord(r["purchase_date"]),
            "e": to_ord(r["expiry_date"]),
            "outcome": r["outcome"],
            "o": to_ord(r["outcome_date"]) if r.get("outcome_date") else None,
        })
    return items


def read_csv(path):
    with open(path, newline="") as f:
        return load_items(list(csv.DictReader(f)))


def build_history(items):
    """hist[h][cat] = sorted (outcome_ord, id) with durations, for USED items."""
    tmp = defaultdict(lambda: defaultdict(list))
    for it in items:
        if it["outcome"] == "used" and it["o"] is not None:
            tmp[it["h"]][it["cat"]].append((it["o"], it["id"], max(0, it["o"] - it["p"])))
    hist = {}
    for h, cats in tmp.items():
        hist[h] = {}
        for cat, lst in cats.items():
            lst.sort()
            hist[h][cat] = {"ords": [x[0] for x in lst], "durs": [x[2] for x in lst]}
    return hist


def history_before(hist_cat, t, window):
    """Durations of used items with outcome date strictly before day t,
    most recent first (same rule as the SQL in scoring.php)."""
    if not hist_cat:
        return []
    k = bisect_left(hist_cat["ords"], t)      # entries with outcome date < t
    chunk = hist_cat["durs"][max(0, k - window):k]
    return list(reversed(chunk))


def build_index(items, horizon):
    """index[h][t] = items that are candidates for the review on day t."""
    index = defaultdict(lambda: defaultdict(list))
    for it in items:
        if it["outcome"] not in ("used", "wasted") or it["o"] is None:
            continue
        lo = max(it["p"], it["e"] - horizon)
        hi = min(it["o"], it["e"])
        for t in range(lo, hi + 1):
            index[it["h"]][t].append(it)
    return index


# ----------------------------------------------------------------------
# Core replay
# ----------------------------------------------------------------------

def pairwise_auc(pos, neg):
    wins = 0.0
    for a in pos:
        for b in neg:
            if a > b:
                wins += 1.0
            elif a == b:
                wins += 0.5
    return wins / (len(pos) * len(neg))


def replay(items, hist, index, p, day_filter=None, households=None):
    """Replay all review days. Returns per-household sums for both approaches."""
    size_of = {}
    for it in items:
        size_of[it["h"]] = it["size"]

    per_h = {}
    for h, days in index.items():
        if households is not None and h not in households:
            continue
        s = {"au": 0, "as_a": 0.0, "as_b": 0.0,      # AUC units and sums (adaptive, baseline)
             "ku": 0, "k": 0, "ha": 0, "hb": 0, "w": 0}  # alert-day units, alerts, hits, wasted
        for t, cand in days.items():
            if day_filter is not None and not day_filter(h, t):
                continue
            if len(cand) < 2:
                continue
            size = size_of[h]
            usage_cache = {}
            rows = []
            for it in cand:
                cat = it["cat"]
                if cat not in usage_cache:
                    durs = history_before(hist.get(h, {}).get(cat), t, p["history_window"])
                    usage_cache[cat] = usage_estimate(durs, cat, size, p)["days"]
                dr = it["e"] - t
                rows.append((it, dr, adaptive_score(dr, cat, usage_cache[cat], p)))

            pos = [r for r in rows if r[0]["outcome"] == "wasted"]
            neg = [r for r in rows if r[0]["outcome"] == "used"]
            if pos and neg:
                s["au"] += 1
                s["as_a"] += pairwise_auc([r[2] for r in pos], [r[2] for r in neg])
                s["as_b"] += pairwise_auc([-r[1] for r in pos], [-r[1] for r in neg])

            k = sum(1 for r in rows if r[1] <= p["baseline_days"])
            if 1 <= k < len(rows):
                base_flagged = [r for r in rows if r[1] <= p["baseline_days"]]
                ranked = sorted(rows, key=lambda r: (-r[2], r[1], r[0]["id"]))
                adapt_flagged = ranked[:k]
                s["ku"] += 1
                s["k"] += k
                s["hb"] += sum(1 for r in base_flagged if r[0]["outcome"] == "wasted")
                s["ha"] += sum(1 for r in adapt_flagged if r[0]["outcome"] == "wasted")
                s["w"] += len(pos)
        per_h[h] = s
    return per_h


# ----------------------------------------------------------------------
# Summaries and bootstrap
# ----------------------------------------------------------------------

def summarise(sums):
    tot = defaultdict(float)
    for s in sums:
        for key, v in s.items():
            tot[key] += v
    out = {
        "review_days_auc": int(tot["au"]),
        "alert_days": int(tot["ku"]),
        "alerts_per_approach": int(tot["k"]),
    }
    out["auc_adaptive"] = tot["as_a"] / tot["au"] if tot["au"] else float("nan")
    out["auc_baseline"] = tot["as_b"] / tot["au"] if tot["au"] else float("nan")
    out["auc_diff"] = out["auc_adaptive"] - out["auc_baseline"]
    k = tot["k"]
    out["precision_adaptive"] = tot["ha"] / k if k else float("nan")
    out["precision_baseline"] = tot["hb"] / k if k else float("nan")
    out["precision_diff"] = out["precision_adaptive"] - out["precision_baseline"]
    out["false_alarms_adaptive"] = int(k - tot["ha"])
    out["false_alarms_baseline"] = int(k - tot["hb"])
    out["missed_waste_adaptive"] = int(tot["w"] - tot["ha"])
    out["missed_waste_baseline"] = int(tot["w"] - tot["hb"])
    out["recall_adaptive"] = tot["ha"] / tot["w"] if tot["w"] else float("nan")
    out["recall_baseline"] = tot["hb"] / tot["w"] if tot["w"] else float("nan")
    return out


def bootstrap_ci(per_h, n_boot=1000, seed=12345, keys=("auc_adaptive", "auc_baseline", "auc_diff",
                                                      "precision_adaptive", "precision_baseline", "precision_diff")):
    """Household-level (cluster) bootstrap: whole households are resampled,
    because days within a household are not independent of each other."""
    rng = random.Random(seed)
    hs = list(per_h.values())
    n = len(hs)
    draws = {k: [] for k in keys}
    for _ in range(n_boot):
        sample = [hs[rng.randrange(n)] for _ in range(n)]
        sm = summarise(sample)
        for k in keys:
            if sm[k] == sm[k]:          # skip NaN
                draws[k].append(sm[k])
    ci = {}
    for k, vals in draws.items():
        vals.sort()
        if vals:
            ci[k] = (vals[int(0.025 * (len(vals) - 1))], vals[int(0.975 * (len(vals) - 1))])
        else:
            ci[k] = (float("nan"), float("nan"))
    return ci


def run(items, hist, p, horizon=7, day_filter=None, households=None, n_boot=0):
    index = build_index(items, horizon)
    per_h = replay(items, hist, index, p, day_filter=day_filter, households=households)
    summary = summarise(per_h.values())
    if n_boot:
        summary["ci"] = bootstrap_ci(per_h, n_boot=n_boot)
    return summary, per_h


# ----------------------------------------------------------------------
# Reporting helpers
# ----------------------------------------------------------------------

def fmt(x, nd=3):
    return "n/a" if x != x else f"{x:.{nd}f}"


def md_table(header, rows):
    lines = ["| " + " | ".join(header) + " |", "|" + "|".join(["---"] * len(header)) + "|"]
    for r in rows:
        lines.append("| " + " | ".join(str(c) for c in r) + " |")
    return "\n".join(lines)


def write_csv_rows(path, header, rows):
    with open(path, "w", newline="") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)


def ci_text(ci, key):
    lo, hi = ci[key]
    return f"{fmt(lo)} to {fmt(hi)}"


# ----------------------------------------------------------------------
# Main analyses
# ----------------------------------------------------------------------

def main_analysis(items, hist, out, n_boot):
    base = make_params()
    summary, per_h = run(items, hist, base, n_boot=n_boot)
    ci = summary["ci"]
    header = ["measure", "baseline", "adaptive", "difference (adaptive - baseline)", "95% interval for difference"]
    rows = [
        ["Ranking AUC (primary)", fmt(summary["auc_baseline"]), fmt(summary["auc_adaptive"]),
         fmt(summary["auc_diff"], 3), ci_text(ci, "auc_diff")],
        ["Precision of alerts (matched volume)", fmt(summary["precision_baseline"]), fmt(summary["precision_adaptive"]),
         fmt(summary["precision_diff"], 3), ci_text(ci, "precision_diff")],
        ["Recall of later-wasted items", fmt(summary["recall_baseline"]), fmt(summary["recall_adaptive"]), "", ""],
        ["False alarms (alerts on items later used)", summary["false_alarms_baseline"], summary["false_alarms_adaptive"], "", ""],
        ["Missed waste (later-wasted items not flagged)", summary["missed_waste_baseline"], summary["missed_waste_adaptive"], "", ""],
    ]
    write_csv_rows(os.path.join(out, "main_results.csv"), header, rows)
    return summary, header, rows


def sensitivity_analysis(items, hist, out):
    """Vary one design choice at a time. Baseline is the same in every row
    unless the baseline lead time itself is being varied."""
    variants = [
        ("proposed design (reference)", {}, 7),
        ("min history 1 item", {"min_history": 1}, 7),
        ("min history 2 items", {"min_history": 2}, 7),
        ("min history 5 items", {"min_history": 5}, 7),
        ("min history 10 items", {"min_history": 10}, 7),
        ("never use own history (fallback only)", {"min_history": 10**6}, 7),
        ("fallback ignores household size (exponent 0)", {"fallback_size_exponent": 0.0}, 7),
        ("fallback divides by sqrt(size) (exponent 0.5)", {"fallback_size_exponent": 0.5}, 7),
        ("default days x 0.5", {"default_scale": 0.5}, 7),
        ("default days x 2", {"default_scale": 2.0}, 7),
        ("weights: urgency only (0.4/0.2/0.4 -> 1/0/0)", {"w_urgency": 1.0, "w_category": 0.0, "w_mismatch": 0.0}, 7),
        ("weights: urgency + category (2/3, 1/3, 0)", {"w_urgency": 2/3, "w_category": 1/3, "w_mismatch": 0.0}, 7),
        ("weights: no category (0.5, 0, 0.5)", {"w_urgency": 0.5, "w_category": 0.0, "w_mismatch": 0.5}, 7),
        ("weights: equal thirds", {"w_urgency": 1/3, "w_category": 1/3, "w_mismatch": 1/3}, 7),
        ("weights: mismatch heavy (0.2, 0.2, 0.6)", {"w_urgency": 0.2, "w_category": 0.2, "w_mismatch": 0.6}, 7),
        ("candidate horizon 3 days", {}, 3),
        ("candidate horizon 5 days", {}, 5),
        ("candidate horizon 10 days", {}, 10),
        ("candidate horizon 14 days", {}, 14),
    ]
    rows, table = [], []
    for name, over, horizon in variants:
        s, _ = run(items, hist, make_params(**over), horizon=horizon)
        row = [name, horizon, fmt(s["auc_baseline"]), fmt(s["auc_adaptive"]), fmt(s["auc_diff"]),
               fmt(s["precision_baseline"]), fmt(s["precision_adaptive"]), fmt(s["precision_diff"])]
        rows.append(row)
    header = ["variant", "horizon (days)", "baseline AUC", "adaptive AUC", "AUC difference",
              "baseline precision", "adaptive precision", "precision difference"]
    write_csv_rows(os.path.join(out, "sensitivity.csv"), header, rows)

    lead_rows = []
    for lead in (1, 2, 3, 5, 7):
        s, _ = run(items, hist, make_params(baseline_days=lead))
        lead_rows.append([lead, s["alert_days"], s["alerts_per_approach"], fmt(s["precision_baseline"]),
                          fmt(s["precision_adaptive"]), fmt(s["precision_diff"]),
                          fmt(s["recall_baseline"]), fmt(s["recall_adaptive"])])
    lead_header = ["baseline lead time (days)", "alert days", "alerts per approach", "baseline precision",
                   "adaptive precision", "precision difference", "baseline recall", "adaptive recall"]
    write_csv_rows(os.path.join(out, "baseline_lead_time.csv"), lead_header, lead_rows)
    return (header, rows), (lead_header, lead_rows)


def household_size_analysis(items, hist, out):
    groups = [("1 person", {1}), ("2 people", {2}), ("3 people", {3}), ("4 or more", {4, 5, 6, 7, 8, 9, 10, 11, 12})]
    size_of = {it["h"]: it["size"] for it in items}
    rows = []
    for label, sizes in groups:
        hs = {h for h, s in size_of.items() if s in sizes}
        if not hs:
            continue
        s, _ = run(items, hist, make_params(), households=hs)
        s_fb, _ = run(items, hist, make_params(min_history=10**6), households=hs)
        s_fb0, _ = run(items, hist, make_params(min_history=10**6, fallback_size_exponent=0.0), households=hs)
        rows.append([label, len(hs), fmt(s["auc_baseline"]), fmt(s["auc_adaptive"]), fmt(s["auc_diff"]),
                     fmt(s_fb["auc_adaptive"]), fmt(s_fb0["auc_adaptive"])])
    header = ["household size", "households", "baseline AUC", "adaptive AUC (proposed)", "difference",
              "adaptive AUC, fallback only, divides by size", "adaptive AUC, fallback only, ignores size"]
    write_csv_rows(os.path.join(out, "by_household_size.csv"), header, rows)
    return header, rows


def cold_start_analysis(items, hist, out):
    """Compare early days (household has little history) with later days."""
    first = {}
    for it in items:
        first[it["h"]] = min(first.get(it["h"], 10**9), it["p"])
    periods = [
        ("first 30 days of a household's data", lambda h, t: t - first[h] < 30),
        ("days 30 to 59", lambda h, t: 30 <= t - first[h] < 60),
        ("day 60 onwards", lambda h, t: t - first[h] >= 60),
    ]
    rows = []
    for label, f in periods:
        s, _ = run(items, hist, make_params(), day_filter=f)
        s_fb, _ = run(items, hist, make_params(min_history=10**6), day_filter=f)
        rows.append([label, s["review_days_auc"], fmt(s["auc_baseline"]), fmt(s["auc_adaptive"]),
                     fmt(s["auc_diff"]), fmt(s_fb["auc_adaptive"])])
    header = ["period", "review days", "baseline AUC", "adaptive AUC (proposed)", "difference",
              "adaptive AUC, fallback only"]
    write_csv_rows(os.path.join(out, "cold_start.csv"), header, rows)
    return header, rows


def replications(n, out, n_households, n_days):
    from simulate import generate
    rows = []
    for seed in range(1, n + 1):
        data = load_items(generate(seed=seed, n_households=n_households, n_days=n_days))
        hist = build_history(data)
        s, _ = run(data, hist, make_params())
        rows.append([seed, fmt(s["auc_baseline"]), fmt(s["auc_adaptive"]), fmt(s["auc_diff"]),
                     fmt(s["precision_baseline"]), fmt(s["precision_adaptive"]), fmt(s["precision_diff"])])
    header = ["seed", "baseline AUC", "adaptive AUC", "AUC difference",
              "baseline precision", "adaptive precision", "precision difference"]
    write_csv_rows(os.path.join(out, "replications.csv"), header, rows)
    return header, rows


def dataset_description(items):
    total = len(items)
    used = sum(1 for i in items if i["outcome"] == "used")
    wasted = sum(1 for i in items if i["outcome"] == "wasted")
    unknown = sum(1 for i in items if i["outcome"] == "unknown")
    active = total - used - wasted - unknown
    hs = {i["h"] for i in items}
    return {"households": len(hs), "items": total, "used": used, "wasted": wasted,
            "unknown": unknown, "other": active,
            "waste_share": wasted / (used + wasted) if used + wasted else float("nan")}


def make_figures(out, main, sens, reps):
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
    except Exception:
        return []
    made = []

    # Figure 1: advantage over the matching baseline, by design choice.
    # (Rows with a different candidate horizon have a different baseline, so
    # each bar is the DIFFERENCE from its own baseline, not a raw AUC.)
    header, rows = sens
    labels = [r[0] for r in rows]
    diffs = [float(r[4]) for r in rows]
    colours = ["#66a56e" if d >= 0 else "#c9605a" for d in diffs]
    fig, ax = plt.subplots(figsize=(9, 7))
    ypos = list(range(len(labels)))
    ax.barh(ypos, diffs, color=colours)
    ax.axvline(0, color="#333", linewidth=1)
    ax.set_yticks(ypos)
    ax.set_yticklabels(labels, fontsize=8)
    ax.invert_yaxis()
    ax.set_xlabel("AUC difference: adaptive minus fixed-date baseline (0 = no advantage)")
    ax.set_title("Advantage over the baseline under different design choices (simulated data)")
    fig.tight_layout()
    p1 = os.path.join(out, "fig_auc_by_variant.png")
    fig.savefig(p1, dpi=150)
    plt.close(fig)
    made.append(p1)

    # Figure 2: replications
    if reps:
        diffs = [float(r[3]) for r in reps[1]]
        fig, ax = plt.subplots(figsize=(6, 4))
        ax.hist(diffs, bins=min(10, len(diffs)), color="#66a56e", edgecolor="white")
        ax.axvline(0, color="#c9605a", linestyle="--")
        ax.set_xlabel("AUC difference (adaptive minus baseline)")
        ax.set_ylabel("Number of simulated datasets")
        ax.set_title(f"Spread over {len(diffs)} independent simulated datasets")
        fig.tight_layout()
        p2 = os.path.join(out, "fig_replications.png")
        fig.savefig(p2, dpi=150)
        plt.close(fig)
        made.append(p2)
    return made


def write_report(out, source, desc, main, sens, lead, size, cold, reps, figs, simulated):
    s, mh, mr = main
    L = []
    L.append("# Evaluation results: adaptive waste-risk score versus fixed-date baseline\n")
    if simulated:
        L.append("> **These results come from simulated households.** They are a system check. They show how the two "
                 "approaches behave on identical, controlled data. They are NOT evidence that the approach reduces "
                 "food waste in real homes. The rules that generated the data are listed in `evaluation/simulate.py` "
                 "and in `docs/EVALUATION_PROTOCOL.md`.\n")
    else:
        L.append("> These results come from the data file given on the command line. With a small number of real "
                 "households and a short period they are descriptive only and must not be read as proof of an effect.\n")
    L.append(f"Data source: `{source}`\n")
    L.append("## 1. Data\n")
    L.append(md_table(["households", "items", "used", "wasted", "unknown (excluded)", "share wasted among confirmed"],
                      [[desc["households"], desc["items"], desc["used"], desc["wasted"], desc["unknown"],
                        f"{desc['waste_share']:.1%}"]]))
    L.append("\n")
    L.append("## 2. Main comparison\n")
    L.append("Review day = one household on one day. Candidate items expire within 7 days. The baseline flags items "
             "within 3 days of expiry. The adaptive approach is given the same number of alerts on each day. "
             f"Review days used for AUC: {s['review_days_auc']}. Days with alerts: {s['alert_days']} "
             f"({s['alerts_per_approach']} alerts per approach).\n")
    L.append(md_table(mh, mr))
    L.append("\nIntervals come from resampling whole households 1000 times. If an interval for a difference includes "
             "zero, the data do not show a reliable difference.\n")
    L.append("## 3. Sensitivity to design choices\n")
    L.append("One choice is changed at a time. The 'proposed design' row is the reference.\n")
    L.append("**Settings were fixed before this evaluation was run.** The weights (0.4 / 0.2 / 0.4), the default "
             "days per category, the 3-item history threshold and the 3-day baseline lead time were chosen at design "
             "time. The other rows show what would have happened under different choices. They are reported for "
             "transparency, not used to pick settings. Changing a setting after seeing these numbers would be a "
             "post-hoc change and must be declared as one.\n")
    L.append("'n/a' means every candidate item was already inside the baseline's lead time, so both approaches flag "
             "the same items and there is nothing to compare.\n")
    L.append(md_table(sens[0], sens[1]))
    L.append("\n### Baseline lead time\n")
    L.append("The baseline's 3-day lead time is a pre-specified design choice, not a measured optimum. The table shows "
             "how the comparison changes if the lead time (and therefore the alert volume) changes.\n")
    L.append(md_table(lead[0], lead[1]))
    L.append("\n## 4. Household size and the cold-start fallback\n")
    L.append(md_table(size[0], size[1]))
    L.append("\nThe last two columns use no household history at all, so they isolate the fallback rule. "
             "Compare 'divides by size' with 'ignores size' within each group.\n")
    L.append("\n## 5. Early days versus later days\n")
    L.append(md_table(cold[0], cold[1]))
    L.append("\nIf learning from a household's own history helps, the adaptive score should improve relative to the "
             "'fallback only' column in later periods.\n")
    if reps:
        diffs = [float(r[3]) for r in reps[1]]
        pdiffs = [float(r[6]) for r in reps[1]]
        mean = sum(diffs) / len(diffs)
        sd = (sum((d - mean) ** 2 for d in diffs) / max(1, len(diffs) - 1)) ** 0.5
        pm = sum(pdiffs) / len(pdiffs)
        L.append(f"\n## 6. Repeated on {len(diffs)} independently simulated datasets\n")
        L.append(f"AUC difference (adaptive minus baseline): mean {mean:.3f}, standard deviation {sd:.3f}, "
                 f"range {min(diffs):.3f} to {max(diffs):.3f}; adaptive was higher in "
                 f"{sum(1 for d in diffs if d > 0)} of {len(diffs)} datasets. "
                 f"Mean precision difference: {pm:.3f}.\n")
        L.append(md_table(reps[0], reps[1]))
    L.append("\n## Checks that the evaluation itself is sound\n")
    L.append("`python3 evaluation/test_evaluation.py` runs 12 checks, including: history used for a score contains "
             "only outcomes dated strictly before the review day; changing a future outcome does not change an "
             "earlier score; an urgency-only score reproduces the baseline exactly; and a **label-shuffle test**, "
             "in which outcomes are assigned at random and both approaches must fall to chance (about 0.5). If the "
             "adaptive score still beat chance there, information from the future would be leaking in.\n")
    L.append("\n## How to read these results honestly\n")
    L.append("- **Circularity.** The score is evaluated only with information available on each day, and the "
             "simulator does not use the score. Even so, simulated outcomes are produced by rules chosen by the "
             "author, so a good result shows the method works under those rules, not in the real world.\n"
             "- **Unknown outcomes** are excluded. Real households may leave many items unconfirmed, which could bias "
             "any real-data result.\n"
             "- **Price totals** are the value of items recorded as used or thrown away. They are not savings.\n"
             "- **Repeated days.** The same item can appear on several review days. That is why intervals come from "
             "resampling whole households.\n")
    if figs:
        L.append("\n## Figures\n")
        for f in figs:
            L.append(f"![{os.path.basename(f)}]({os.path.basename(f)})\n")
    with open(os.path.join(out, "evaluation_report.md"), "w") as f:
        f.write("\n".join(L))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--data", default="data/simulated_items.csv")
    ap.add_argument("--out", default="results")
    ap.add_argument("--bootstrap", type=int, default=1000)
    ap.add_argument("--replications", type=int, default=20)
    ap.add_argument("--no-replications", action="store_true")
    ap.add_argument("--simulated", action="store_true", help="label the report as simulated data")
    ap.add_argument("--households", type=int, default=40, help="used only for replications")
    ap.add_argument("--days", type=int, default=180, help="used only for replications")
    a = ap.parse_args()

    os.makedirs(a.out, exist_ok=True)
    items = read_csv(a.data)
    hist = build_history(items)
    simulated = a.simulated or "simulated" in os.path.basename(a.data)

    print("dataset:", dataset_description(items))
    main_res = main_analysis(items, hist, a.out, a.bootstrap)
    print("main analysis done")
    sens, lead = sensitivity_analysis(items, hist, a.out)
    print("sensitivity done")
    size = household_size_analysis(items, hist, a.out)
    cold = cold_start_analysis(items, hist, a.out)
    reps = None
    if not a.no_replications and simulated:
        reps = replications(a.replications, a.out, a.households, a.days)
        print("replications done")
    figs = make_figures(a.out, main_res, sens, reps)
    write_report(a.out, a.data, dataset_description(items), main_res, sens, lead, size, cold, reps, figs, simulated)
    print("report written to", os.path.join(a.out, "evaluation_report.md"))


if __name__ == "__main__":
    main()
