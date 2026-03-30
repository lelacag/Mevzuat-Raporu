Event codes (premium users)

Feature: each premium user receives a persistent 6‑character alphanumeric `event_code` used to represent them at in-person events.

Behavior
- Codes are persistent and stored in `users.event_code`.
- Format: 6 characters (A–Z, 2–9), excludes ambiguous characters.
- Premium users can view/regenerate their code from `Profile → Edit`.
- Event listings show the creator's code when available.

Backfill
- Existing premium users are backfilled with unique codes by the migration `20260212_add_event_code`.

Security & privacy
- Codes are public (intended to be shown at events). Treat like a public handle — regenerate if compromised.

Developer notes
- Helpers: `get_or_create_event_code($user_id)` and `regenerate_event_code($user_id)` in `includes/functions.php`.
- DB column: `users.event_code VARCHAR(12)` (unique index added).
