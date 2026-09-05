# Coveted database setup

`database/schema.sql` remains the base schema for a clean Coveted install. After the base schema is imported, apply every SQL file in `database/migrations/` in filename order so a fresh install reaches the same schema as a deployed environment.

For a first install:

1. Import `database/schema.sql`.
2. Apply the files in `database/migrations/` in filename order.
3. Copy `config-example.php` to `config.php`.
4. Configure the database credentials.
5. Create the first Coveted user.
6. Grant the initial Admin role from the server CLI:

```bash
php scripts/grant-admin.php admin@example.com
```

For an existing deployment, never re-import the base schema. Apply only new additive migrations that have not already been applied. Feature code may defensively create its own new tables when a migration has not yet been run, but the SQL migration remains the deployment record and should still be applied/recorded through the normal deployment process.

Do not store Microgifter API credentials in SQL, browser JavaScript, or committed configuration files.
