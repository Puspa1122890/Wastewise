# Design notes: the adaptive waste-risk score

## 1. Definition

For an active item on a given day:

```
score = 0.4 * urgency + 0.2 * category_weight + 0.4 * mismatch        (range 0 to 10)

urgency         = clamp(10 - days_remaining, 0, 10)
category_weight = fixed value per category (0 to 10)
mismatch        = clamp( (usual_days - days_remaining) / usual_days * 10, 0, 10 )
usual_days      = the household's average days from purchase to use for this category
                  (if it has at least 3 used items, using up to the 20 most recent)
                  otherwise: category_default_days / household_size
```

`mismatch` is large when the household usually takes longer to use this kind of food than the days
that are left. It is expressed as a share of the usual time so that quick and slow categories are on
the same scale. (An earlier draft used raw days, capped at 10. That saturated for slow categories
such as canned goods, so it was changed. This was a design change made before any evaluation was run.)

`household_size` is declared by the user and only matters in the fallback. Once a household has at
least 3 used items in a category, `usual_days` comes from its own behaviour, which already reflects how
many people eat the food. Using size again would count the same thing twice, so size then has no effect.

The implementation is `includes/scoring.php` (PHP) and `evaluation/scoring_ref.py` (independent
Python). `tests/scoring_vectors.json` holds 25 cases computed by the Python code; `tests/run_tests.php`
checks that the PHP code gives identical numbers.

## 2. Worked examples

*Milk (Dairy, weight 9), 3 days left, 2 people, no history.* Default 4 days / 2 people = 2 days usual.
Urgency 7. Mismatch = (2 - 3)/2*10 < 0, so 0. Score = 0.4*7 + 0.2*9 + 0 = **4.6**.

*Same milk, 1 person.* Usual = 4 days. Mismatch = (4 - 3)/4*10 = 2.5. Score = 2.8 + 1.8 + 1.0 = **5.6**.

*Meat (weight 9), 1 day left, household that took 6, 7, 5 and 8 days on past items.* Usual = 6.5.
Urgency 9. Mismatch = (6.5 - 1)/6.5*10 = 8.46. Score = 3.6 + 1.8 + 3.38 = **8.78**.

Note from the first two examples: household size only changes the score when the estimated usual time
exceeds the days remaining.

## 3. Where each parameter comes from

None of these is a measured fact. All are author-assigned design choices and are labelled that way in
the thesis. They were fixed before the evaluation was run.

| Parameter | Value | Basis | Status |
|---|---|---|---|
| Weights | 0.4 / 0.2 / 0.4 | Author choice: expiry and the household's own habit count most, category is a weaker prior | Sensitivity tested (7 weight sets) |
| Urgency horizon | 10 days | Author choice | Candidate-horizon variants tested |
| Category weights | Dairy 9, Meat & Fish 9, Produce 8, Bread 6, Other 5, Frozen 2, Canned 1 | Author's qualitative ranking of how fast each type becomes a problem | Not independently sourced |
| Default days to use (1 person) | Dairy 4, Meat & Fish 2, Produce 3, Bread 4, Other 7, Frozen 60, Canned 180 | Author estimates of typical use time | Not independently sourced. Scaled x0.5 and x2 in sensitivity tests |
| Minimum history | 3 items | Smallest number where an average is not one item | Tested at 1, 2, 5, 10 and never |
| History window | 20 most recent used items | Keeps the estimate current | Not varied |
| Fallback | default / household size | Assumes use time falls in direct proportion to people | **Questioned, see section 4** |
| Baseline lead time | 3 days | Round short default | Tested 1 to 5 days |

If suitable published storage-time guidance is found and verified, cite it for the defaults. Until
then they are assumptions and the thesis must say so.

## 4. What the simulated sensitivity tests show

From `evaluation/results` (seed 1; 40 simulated households, 180 days). These are system-check results,
not evidence about real homes.

- The adaptive score ranked later-thrown-away items higher than later-used ones more often than the
  baseline (AUC 0.722 versus 0.667), and was ahead in 20 of 20 independently simulated datasets.
- **The advantage comes from learning each household's own history.** With history switched off
  ("fallback only") the score is about equal to the baseline (0.672 versus 0.667).
- **The category weight did not help.** Weights of urgency plus category only (no history term) scored
  *below* the baseline (0.626). Removing the category term altogether (0.5 / 0 / 0.5) scored higher than
  the proposed weights (0.738 versus 0.722).
- **Dividing by household size did not help and hurt for larger households.** For 3-person households
  the fallback scored 0.638 when dividing by size and 0.697 when ignoring size. The simulator's
  size effect is weaker than linear, so this is evidence that the linear assumption is not safe, not
  proof about real households.

These are reported as findings. Because the settings were fixed in advance, the proposed design stays
as specified. A revised design (for example no category term, or a square-root size effect) would be a
post-hoc change and must be declared as one if adopted.

## 5. Known limitations

- Category weights and default days are assumptions.
- The score assumes a household's next item in a category behaves like its previous ones.
- Items are scored independently. Stock levels and meal plans are not modelled.
- Quantities are not used: partly used items are not tracked.
- Used and thrown-away records are self-reported.
- The risk labels (Low, Moderate, High at 4 and 6) are for display only and are not used in the evaluation.
- The score ranks items for household decisions. It never says food is safe to eat.
