# Coveted database setup

Coveted has not been installed in production yet, so `database/schema.sql` is the single authoritative database definition.

For the first install:

1. Import `database/schema.sql`.
2. Copy `config-example.php` to `config.php`.
3. Configure the database credentials.
4. Create the first Coveted user.
5. Grant the initial Admin role from the server CLI:

```bash
php scripts/grant-admin.php admin@example.com
```

Do not create migration files until after the first real deployment. Once production data exists, all subsequent schema changes must be additive migrations rather than edits that assume a clean database.

Do not store Microgifter API credentials in SQL, browser JavaScript, or committed configuration files.
