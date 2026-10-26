# Test plan and evidence sheet

Fill in **Actual**, **Pass/Fail** and **Date** as you run each test, and keep screenshots for the
report (Chapter 4, Testing and Validation). Use dummy data only.

## A. Automated tests (run these first)

| Command (from the project folder) | What it checks | Needs |
|---|---|---|
| `php tests/run_tests.php` | PHP scoring against 25 cases from the independent Python version; CSRF, escaping, validation helpers | PHP (XAMPP: `C:\xampp\php\php.exe tests\run_tests.php`) |
| `node tests/receipt_parser_test.js` | Receipt parsing, category guessing, expiry suggestion, Open Food Facts category mapping (64 checks) | Node.js |
| `python3 evaluation/test_evaluation.py` | Evaluation has no future leakage; label-shuffle null test; baseline reproduced (12 checks) | Python 3 |

Record the pass counts here: PHP ____  Node ____  Python ____

## B. Manual functional tests

Prepare: a fresh database (import `wastewise_db.sql`).

| ID | Test | Steps | Expected | Actual | Pass/Fail | Date |
|---|---|---|---|---|---|---|
| FT-01 | Register | Register with valid details | Redirected to login with a success message; household created | | | |
| FT-02 | Duplicate email | Register again with the same email | Error: account exists | | | |
| FT-03 | Weak password | Register with a 5-character password | Error shown, no account | | | |
| FT-04 | Log in | Log in with correct details | Dashboard shows household name and invite code | | | |
| FT-05 | Wrong password | Log in with a wrong password | Generic "Incorrect email or password" | | | |
| FT-06 | Add item | Add Milk, Dairy, today, expiry in 3 days, price 12.95 | Item listed with a risk score and "Alert" | | | |
| FT-07 | Date validation | Add an item with expiry before purchase | Error, nothing saved | | | |
| FT-08 | Edit | Edit the milk's price | Change saved, activity log shows "edited" | | | |
| FT-09 | Record used | Mark an item used with a date 2 days ago | Item leaves the active list; log shows "recorded as used" | | | |
| FT-10 | Record thrown away | Mark another item thrown away | Counted under "thrown away" on Summary | | | |
| FT-11 | Date range | Try an outcome date before the purchase date or in the future | Error shown | | | |
| FT-12 | Expired unconfirmed | Add an item whose expiry date was yesterday | Appears under "Needs confirmation"; **not** counted as thrown away on Summary | | | |
| FT-13 | Unknown outcome | Choose "I am not sure" for it | Appears under unknown; counted separately; not in used or wasted totals | | | |
| FT-14 | Resolve unknown | Open the unknown item and record it as used | Moves into the used total | | | |
| FT-15 | Delete | Delete a mistaken item | Item removed after confirmation | | | |
| FT-16 | Join household | Register a second user; join with the first user's invite code | Second user sees the first household's items | | | |
| FT-17 | Bad code | Enter an invalid invite code | Error shown | | | |
| FT-18 | One household only | After joining, check the second user's old household | Old empty household removed; user listed once in the new one | | | |
| FT-19 | Activity log | Open Activity | Shows who did what and when, newest first | | | |
| FT-20 | Summary | Open Summary | Totals match the items you recorded; wording says "recorded", not "saved" | | | |
| FT-21 | Export | Download my household's data | CSV has items, dates, outcomes; no names or emails | | | |
| FT-22 | Ranking differs | Add Milk (dairy) and Canned beans (canned), both 3 days to expiry | Milk ranks above canned beans under "Adaptive risk". Under "Soonest expiry" they tie | | | |
| FT-23 | Learns the household | Record 3 Dairy items used 6 days after purchase, then add new Dairy with 4 days left | The risk note changes from "starting estimate" to "based on 3 past items", and the score rises | | | |
| FT-24 | Household size | Change household size on the dashboard | Saved; fallback scores change for categories with fewer than 3 used items | | | |
| FT-25 | Barcode by hand | Type a barcode from a food package and press Look up | Name and category filled in, or "not found" message | | | |
| FT-26 | Barcode camera | Press Scan barcode (on localhost or https) and scan a package | Barcode filled and looked up; if camera blocked, a clear message appears | | | |
| FT-27 | Receipt text | Paste `docs/sample_receipt.txt` and press Find products | 7 products found; totals, discount and payment lines ignored | | | |
| FT-28 | Receipt photo | Upload a photo of a real receipt and press Read photo | Text appears and rows are suggested; you can correct before saving | | | |
| FT-29 | Receipt review | Untick two rows and press Add checked items | Only ticked rows saved, with the purchase date you set | | | |
| FT-30 | Receipt validation | Clear an expiry date in a row and submit | Error naming the row; nothing saved | | | |

## C. Security tests

| ID | Test | Steps | Expected | Actual | Pass/Fail |
|---|---|---|---|---|---|
| ST-01 | CSRF | In browser developer tools remove the `csrf_token` field from a form and submit | Rejected (HTTP 400) | | |
| ST-02 | SQL injection | Log in with email `' OR '1'='1` and any password | Login fails, no error details | | |
| ST-03 | XSS | Add an item named `<script>alert(1)</script>` | Shown as text, no alert | | |
| ST-04 | Household isolation | As household B, open `confirm_outcome.php?id=` using an item id from household A | "Item not found" | | |
| ST-05 | Login required | Log out, open `dashboard.php` directly | Redirected to login | | |
| ST-06 | Password storage | In phpMyAdmin view `users.password_hash` | Starts with `$2y$`, not the password | | |
| ST-07 | Rate limiting | Enter a wrong password 5 times for one email | Further attempts refused for 15 minutes | | |
| ST-08 | Session id | Note the session cookie before and after login | The value changes | | |
| ST-09 | GET cannot delete | Open `delete_product.php?id=1` in the address bar | "Must be submitted from the page" (405) | | |
| ST-10 | Logout by link | Open `logout.php` in the address bar while logged in | Still logged in | | |
| ST-11 | Config protected | Open `includes/config.php` in the browser | Blank page or 403 (never shows the password) | | |
| ST-12 | Error detail | Set `APP_ENV=production` and stop MySQL, reload | Generic message, no technical details | | |

## D. Test coverage (the measure named in the contract)

Defined here as: **requirements with at least one executed, passing test, divided by all requirements.**
Fill in "Passing tests" once the tables above are complete.

| Req | Requirement (objective) | Tests | Passing tests |
|---|---|---|---|
| R1 | Adaptive score defined and implemented (2) | Automated PHP, FT-22, FT-23 | |
| R2 | Fixed-date baseline implemented (3) | Automated PHP, FT-06, FT-22 | |
| R3 | OCR receipt import (4) | Node tests, FT-27, FT-28, FT-29, FT-30 | |
| R4 | Barcode scanning (5) | Node tests, FT-25, FT-26 | |
| R5 | Database stores users, households, products, history (6) | FT-01, FT-06, FT-19 | |
| R6 | Full product management (7) | FT-06, FT-08, FT-09, FT-10, FT-15 | |
| R7 | Household sharing by invite code (8) | FT-16, FT-17, FT-18 | |
| R8 | Visible savings and waste dashboard (9) | FT-20 | |
| R9 | Visible activity log (10) | FT-19 | |
| R10 | Outcome definitions and unknowns (11) | FT-11, FT-12, FT-13, FT-14 | |
| R11 | Evaluation dataset and comparison (12) | Python tests, export FT-21 | |
| R12 | Security practices (13) | ST-01 to ST-12 | |
| R13 | Deployment (15) | Deployment checklist in docs/DEPLOYMENT.md | |

Coverage = passing requirements / 13 = ____ %

Objectives 1, 14 and 16 (literature review, ethics screening, testing/reflection) are not software
features and are not counted here.
