# Security measures and their limits

| Threat | Measure | Where |
|---|---|---|
| Stolen password database | Passwords stored only as `password_hash()` hashes (bcrypt by default) | register.php, login.php |
| SQL injection | Every query uses prepared statements with bound values | all pages |
| Cross-site scripting | All output escaped with `e()` (`htmlspecialchars`) | all pages |
| Cross-site request forgery | Per-session token on every state-changing form, checked with `hash_equals` | includes/security.php |
| Changing data with a link | Delete, outcome changes and logout are POST only | delete_product.php, confirm_outcome.php, logout.php |
| One household reading another's data | Every query on products is restricted by `household_id` from the session | all product pages |
| Brute-force login | 5 failed attempts per email, or 30 per IP, in 15 minutes blocks further attempts | includes/security.php, LoginAttempts table |
| Account enumeration | Same message for wrong email and wrong password | login.php |
| Session fixation | `session_regenerate_id(true)` after login; HttpOnly, SameSite cookies; Secure flag under HTTPS | login.php, includes/config.php |
| Information leaks | Errors hidden when `APP_ENV=production`; `includes/` blocked by `.htaccess` | config.php, .htaccess |
| Clickjacking, MIME sniffing | `X-Frame-Options`, `X-Content-Type-Options` headers | includes/header.php |
| Bad input | Server-side validation of dates, numbers, categories, barcode, lengths | all forms |
| Privacy | Receipt photos are read in the browser and not uploaded. Data exports contain no names or emails. | receipt.js, export_dataset.php |

## Not implemented (state these honestly in the report)

- No email verification or password reset.
- No Content-Security-Policy (the pages load scripts from CDNs).
- No two-factor authentication, and no audit trail beyond the product activity log.
- Rate limiting is per email and per IP address using the database, not a full web application firewall.
- A barcode number is sent to Open Food Facts when a lookup is made.
- Tesseract.js and html5-qrcode are loaded from public CDNs. For a production system they would be
  self-hosted with integrity hashes.
