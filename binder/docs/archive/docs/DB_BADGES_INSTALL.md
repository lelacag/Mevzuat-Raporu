# Installing Badges DB (Manual SQL)

If migrations were not applied or you prefer to run SQL manually, use the SQL file:

migrations/20260115_badges_migration.sql

Usage:

1. Backup your DB.
2. Run using your MySQL client:

   mysql -u <user> -p <database> < migrations/20260115_badges_migration.sql

3. The file creates `badges` and `user_badges` tables and attempts to add foreign keys. If you see errors about duplicate constraints, they can be ignored.

After running:
- Visit Admin → Rozetler to create badges and use "Tüm Kullanıcı Roztlerini Senkronize Et" to assign badges based on current likes.
