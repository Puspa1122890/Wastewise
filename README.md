# WasteWise

A household web system that ranks food by waste risk using an adaptive score, and compares it with a
fixed-date reminder baseline. Student project (PHP, MySQL, HTML/CSS/JavaScript).

The system supports household decisions about what to use first. It does **not** judge whether food
is safe to eat.

## Quick start (XAMPP)

1. Start Apache and MySQL. Copy this folder to `C:\xampp\htdocs\Wastewise`.
2. phpMyAdmin > Import > `wastewise_db.sql` (this resets all data).
3. Open `http://localhost/Wastewise/test_connection.php`, then `register.php`.

More options (Docker, public hosting) are in `docs/DEPLOYMENT.md`.

## What is in the folder

| Path | Purpose |
|---|---|
| `register.php`, `login.php`, `logout.php` | Accounts (hashed passwords, CSRF tokens, login throttling) |
| `dashboard.php` | Household info, invite code, household size, highest-risk items |
| `products.php` | Add (manual or barcode), list with adaptive risk and fixed-date alert, confirm expired items |
| `confirm_outcome.php` | Record used / thrown away (with the real date) / unknown |
| `edit_product.php`, `delete_product.php` | Edit and remove items |
| `join_household.php` | Join another household by invite code |
| `import_receipt.php` | Receipt photo (OCR) or pasted text to reviewed rows |
| `activity_log.php`, `summary.php` | Visible history; recorded used / thrown-away values with a chart |
| `export_dataset.php` | Anonymous CSV of the household's data for the evaluation |
| `includes/scoring.php` | The scoring engine (pure functions plus database helpers) |
| `includes/security.php`, `config.php` | CSRF, escaping, throttling, sessions, settings |
| `assets/js/` | Receipt parser and category mapping, barcode scanner, receipt page |
| `evaluation/` | Simulator, evaluation harness, its tests, and generated results |
| `tests/` | PHP and Node test runners and the shared scoring vectors |
| `docs/` | Evaluation protocol, design notes, security notes, test plan, usability protocol, deployment |

## Running the tests

```
php tests/run_tests.php                  # PHP scoring vs independent Python, security helpers
node tests/receipt_parser_test.js        # receipt parsing and category mapping
python3 evaluation/test_evaluation.py    # evaluation has no leakage (includes label-shuffle test)
```

## Status against the 16 objectives in Chapter 1

"Checked here" means I ran it in a Python/Node environment. **PHP could not be run where this was built**,
so every PHP page is written but **not yet executed**. Run the PHP tests and the manual tests in
`docs/TEST_PLAN.md` first, and fix anything that fails.

| # | Objective | Where | Status |
|---|---|---|---|
| 1 | Literature review | Thesis Chapter 2 | Writing task, not software |
| 2 | Adaptive score | `includes/scoring.php` | Written. Python twin checked here; PHP parity needs `tests/run_tests.php` |
| 3 | Fixed-date baseline | `includes/scoring.php`, shown in `products.php` | As above |
| 4 | OCR receipt import | `import_receipt.php`, `assets/js/` | Parser checked here (64 tests). OCR engine, browser page and server import not run |
| 5 | Barcode scanning | `assets/js/barcode.js` | Category mapping checked here. Camera, scanner library and the live lookup not run |
| 6 | Relational database | `wastewise_db.sql` | Written, not imported here |
| 7 | Full product management | `products.php` and related | Written, not run |
| 8 | Household sharing, invite code | `join_household.php`, `dashboard.php` | Written, not run |
| 9 | Visible savings/waste dashboard | `summary.php` | Written, not run. Wording is "recorded", not "saved" |
| 10 | Visible activity log | `activity_log.php` | Written, not run |
| 11 | Success-criteria definitions | `docs/EVALUATION_PROTOCOL.md` | Done |
| 12 | Evaluation dataset and comparison | `evaluation/` | **Run and checked here** on simulated data (12 tests incl. leakage check) |
| 13 | Security practices | `includes/security.php`, `docs/SECURITY_NOTES.md` | Written, not run. 12 security tests listed in the test plan |
| 14 | Ethics screening | You | **Your action.** Needed before any test with other people |
| 15 | Live deployment | `Dockerfile`, `docs/DEPLOYMENT.md` | Instructions only. Needs your hosting account |
| 16 | Testing and reflection | `tests/`, `docs/TEST_PLAN.md`, `docs/USABILITY_TEST.md` | Plans and automated tests ready. Running them and recording evidence is yours |

## Honest limits

- The evaluation results in `evaluation/results/` come from **simulated** households. They are a system
  check, not evidence about real homes (see `docs/EVALUATION_PROTOCOL.md`).
- Category weights and default usage times are author-assigned assumptions, not sourced facts.
- The simulated sensitivity tests found that the category-weight term and the "divide by household size"
  fallback did **not** help. These are reported in `docs/DESIGN_NOTES.md`. The design was fixed
  beforehand, so any change now is a post-hoc change and must be declared as one.
- Receipts show what was bought, not when or how much was eaten.
