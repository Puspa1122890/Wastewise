# Evaluation protocol

This document defines, before any results are read, exactly what is compared, on what data, at what
moment, and how it is scored. It answers the supervisor's points about circular testing, undefined
"accuracy", the baseline rule and the limits of the claims. It is written to be adapted into
Chapter 3 (Methodology) and Chapter 5 (Results and Discussion).

## 1. Question

Does ranking a household's food by an adaptive waste-risk score identify items that will later be
thrown away better than a fixed-date reminder does, when both use only the information that exists
at the time of the decision?

The study does **not** test whether using WasteWise reduces food waste. That would need real
households over a long period, which this project cannot provide.

## 2. Terms (operational definitions)

| Term | Definition |
|---|---|
| Item | One purchased entry (name, category, purchase date, expiry date, price). |
| Used | The household recorded that the item was used, with the date it happened. |
| Thrown away ("wasted") | The household recorded that the item was thrown away, with the date. |
| Unknown | The household said it did not know. Excluded from scoring, and counted. |
| Expired unconfirmed | The expiry date has passed and nothing was recorded. **Not** treated as wasted. |
| Review day | One household on one calendar day, when it looks at its food list. |
| Candidate list | Items active on the review day (bought, no outcome recorded before that day) that expire within 7 days. |
| Later outcome | What the household eventually recorded: used or thrown away. Used only to judge a prediction, never to make it. |
| Recorded value | Sum of the prices of items recorded as used, or as thrown away. It is **not** a saving. |

"Saved" is deliberately not used as a measured outcome. A recorded-as-used item might have been used
without the app, so no saving can be attributed to the system.

## 3. The two approaches

- **Fixed-date baseline.** Ranks candidates by days until expiry, soonest first. It raises an alert for
  every item within **3 days** of its expiry date, regardless of category, household or history.
  *Why 3 days:* it is a short, round lead time chosen in advance as a plausible default for a reminder.
  It is a design choice, not a measured optimum or a figure taken from a source. Its effect is
  examined by varying it from 1 to 5 days (`results/baseline_lead_time.csv`).
- **Adaptive score.** Ranks candidates by the score in `includes/scoring.php` (documented in
  `docs/DESIGN_NOTES.md`). Parameters were fixed before the evaluation was run.

## 4. Decision time and leakage control

The data are replayed in date order, one review day at a time. On review day *t*:

1. The candidate list contains only items bought on or before *t* with no outcome dated before *t*.
2. The history used for the score contains only items **used strictly before day *t***. An outcome
   dated on day *t* or later is never used, including the outcome of the item being scored.
3. Both approaches are given the same candidate list.
4. Only after both have ranked the list are the later outcomes read, to judge them.

`evaluation/test_evaluation.py` checks this mechanically, including a **label-shuffle test**: if
used/thrown-away labels are assigned at random, both approaches must fall to chance (AUC about 0.5).
If the adaptive score still beat chance on shuffled labels, future information would be leaking in.

## 5. Measures

**Primary: ranking AUC.** On each review day where the candidate list contains at least one item that
was later thrown away and at least one later used, AUC is the probability that a randomly chosen
later-thrown-away item is ranked above a randomly chosen later-used item (ties count half). 0.5 is
chance, 1.0 is perfect. The reported value is the mean over such review days.

AUC is used because the adaptive system produces a **ranking**, not a yes/no label, so no score
threshold has to be invented, and because it is not inflated when most items are used. Plain accuracy
would look high for a system that never predicted any waste at all, since most items are used.

**Supporting measures at matched alert volume.** On each day the baseline flags *k* items (those within
3 days of expiry). The adaptive approach is also given exactly *k* alerts, its top *k*. Both therefore
have the same alert budget. Then:

- *Precision* = flagged items later thrown away / flagged items
- *False alarms* = flagged items that were later used
- *Missed waste* = later-thrown-away items on that day that were not flagged
- *Recall* = share of later-thrown-away items that were flagged

Days where *k* is 0 or equals the whole list are skipped, because there is nothing to choose between.

## 6. Data

1. **Simulated households (system check).** `evaluation/simulate.py` generates households with
   habits, shopping and outcomes by stated rules (listed in that file's header). The generator does not
   use the score. Its category times differ from the score's defaults and its household-size effect is
   weaker than the score's fallback assumes, so the score is not simply tested against its own
   assumptions. Results are reported as a **system check**: they show the code behaves sensibly and
   compare both approaches on identical data. They are not evidence about real homes.
2. **Real pilot (descriptive only).** Real use over a few weeks will yield too few items to support a
   comparison. Export it with "Download my household's data" on the Summary page and describe it
   (counts, share recorded as used / thrown away / unknown, how many items needed confirmation).
   `python3 evaluation/evaluate.py --data wastewise_household_X.csv --out results_real --no-replications`
   will run the same analysis on it, but treat any such result as anecdotal.

## 7. Uncertainty

The same item appears on several review days, and days within a household are related, so ordinary
confidence intervals would be too narrow. Intervals are from a **household-level bootstrap** (whole
households resampled 1000 times). Simulated results are also repeated on 20 independently generated
datasets to show how much they vary.

## 8. Sensitivity analyses (reported, not used to choose settings)

Minimum history (1 to 10 items, or never), household-size handling in the fallback, scale of the
category default days, the three weights, the candidate horizon (3 to 14 days), the baseline lead
time (1 to 5 days), results by household size, and early versus later days of a household's data.

Settings were fixed before running. If a setting is changed after seeing results, that is a **post-hoc
change** and must be declared as such in the report.

## 9. Limits of the claims

- A good simulated result shows the method works under the simulator's rules. It does not show it
  works in real kitchens.
- Real households may leave many items unconfirmed. Excluding unknowns could bias a real result.
- Used / thrown-away records are self-reported and may be late or wrong.
- Recorded value is not money saved.
- The system ranks items to support household decisions. It does not judge whether food is safe to eat.
- Prices are totals per entry, so partly used items are counted whole.

## 10. How to reproduce

```
cd evaluation
python3 simulate.py --out data/simulated_items.csv
python3 evaluate.py --data data/simulated_items.csv --out results --simulated --replications 20
python3 test_evaluation.py
```
Everything is seeded, so the same commands give the same numbers.
