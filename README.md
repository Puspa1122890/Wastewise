# WasteWise

An Adaptive Household Food Expiry Risk-Prioritisation and Waste Analytics System.
Final year project, BSc Computer Science (De Montfort University, taught at Niels Brock).
Student: Puspa Bhujel (P2863762). Supervisor: Eugene Louis Batie Badzongoly.

WasteWise is a household web system (PHP 8, MySQL, HTML/CSS/JavaScript). It ranks food by an adaptive
waste-risk score and compares that ranking with a fixed-date reminder baseline. It supports household
decisions about what to use first. It does **not** judge whether food is safe to eat.

Live site: https://wastewise-puspa.infinityfree.io (free host, for demonstration only)

## Requirements

- PHP 8.2 or later with PDO MySQL, and MySQL or MariaDB (XAMPP provides both)
- A web browser. Barcode and receipt features need an internet connection (see below)
- Optional, for the tests and the evaluation: Node.js 18+ and Python 3.10+

## Install and run (XAMPP, the main way)

1. Start Apache and MySQL in the XAMPP control panel.
2. Copy this folder to `C:\xampp\htdocs\Wastewise`.
3. Open phpMyAdmin, choose **Import**, select `wastewise_db.sql` and click Go.
   This creates the database `wastewise_db` and all tables, and resets any existing data.
4. Open `http://localhost/Wastewise/register.php` and create an account. A household is created for you.
   Another account can join it with the invite code shown on the dashboard.

The default settings (`root`, empty password) suit XAMPP. On a host, set the environment variables
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` and `APP_ENV=production`, or edit
`includes/config.php`. Do not commit real passwords.

Docker is also supported: `docker compose up --build`, then open `http://localhost:8080`.
More detail is in `docs/DEPLOYMENT.md`.

## Features

- Accounts with hashed passwords, CSRF tokens and login throttling; households shared by invite code
- Product management with category, purchase date, expiry date, quantity and price
- Adaptive waste-risk score (0 to 10) and fixed-date baseline alert, shown side by side
- Outcome recording: used, thrown away (with a date) or unknown; expired items wait for confirmation
- Summary page and activity log
- Receipt import (photo read in the browser with Tesseract.js, or pasted text) with a review step
- Barcode scanning with an Open Food Facts lookup
- Expiry date reader from a photo of the printed date. It reads clear labels but often fails on
  dot-matrix prints, and then the date is typed by hand
- Anonymous CSV export of a household's data for the evaluation
- Pages resize for phone browsers. Installing it as a phone app is planned, not done

## Folder guide

| Path | Purpose |
|---|---|
| `register.php`, `login.php`, `logout.php` | Accounts |
| `dashboard.php` | Household, invite code, household size, highest-risk items |
| `products.php`, `edit_product.php`, `delete_product.php` | Product management, ranking, expired-item confirmation |
| `confirm_outcome.php` | Record used / thrown away / unknown |
| `join_household.php` | Join a household by invite code |
| `import_receipt.php` | Receipt photo or pasted text to reviewed rows |
| `activity_log.php`, `summary.php` | History and recorded totals |
| `export_dataset.php` | Anonymous CSV export |
| `includes/` | Scoring engine, security helpers, settings, page header and footer |
| `assets/js/` | Receipt parser, expiry date reader, barcode scanner |
| `wastewise_db.sql` | Database script (use this one). `schema_v2_upgrade.sql` only upgrades an older database |
| `evaluation/` | Simulator, evaluation scripts, tests, the simulated data set and the results |
| `tests/` | PHP, JavaScript and shared scoring test files |
| `docs/` | Evaluation protocol, design and security notes, test plan, usability protocol, deployment |

## Tests

```
php tests/run_tests.php                  # 49 checks: scoring against an independent Python copy, security helpers
node tests/receipt_parser_test.js        # 70 checks: receipt parsing, category guessing, expiry suggestion
node tests/expiry_date_test.js           # 21 checks: expiry date reader
python3 evaluation/test_evaluation.py    # 12 checks: evaluation code, including the label-shuffle test
```

All four passed on the submitted code.

## Reproducing the evaluation

```
cd evaluation
python3 simulate.py --out data/simulated_items.csv
python3 evaluate.py --data data/simulated_items.csv --out results --simulated --replications 20
python3 test_evaluation.py
```

The simulator uses seed 1 and the replications use seeds 1 to 20. The full settings are in Appendix B
of the report and in `docs/EVALUATION_PROTOCOL.md`.

## Limits

- The evaluation uses **simulated** households. It is a system check, not evidence about real homes.
- Category weights and default usage times are my own design choices, not sourced facts.
- The simulated tests found that the category weights and the household-size fallback did not help.
- Receipts show what was bought, not when or how much was eaten.
- Barcode lookup, receipt reading and the chart need an internet connection (Open Food Facts,
  Tesseract.js, the scanner library and the chart library load from public servers).
- No email verification, password reset, content security policy or two factor authentication.
- There is no account deletion and no automatic deletion of old login attempts.
