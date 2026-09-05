# Coveted First Install

Coveted V1 uses one canonical first-install schema: `database/schema.sql`.

## Preferred install

1. Upload and extract the Coveted deploy ZIP into the web root.
2. Create an **empty MySQL 8+ database** in your hosting control panel.
3. Visit `https://your-domain.example/setup.php`.
4. Enter only:
   - site name
   - base URL
   - timezone
   - database host, port, name, username and password
   - first System Admin name, email and password
5. Choose **Install Coveted**.
6. Sign in with the System Admin account you just created.

The setup page:
- validates PHP/MySQL and the database connection;
- accepts a clean empty database or a complete Coveted schema with no users;
- imports `database/schema.sql` automatically when the database is empty;
- creates `config.php`;
- generates internal security material automatically;
- creates the first account with `attendee` and `system_admin` roles;
- creates the admin profile and install audit event;
- locks itself after `config.php` exists.

There is **no app-key field**, Push/VAPID setup, proxy setup, or other infrastructure configuration on the first-install screen. Push remains disabled until configured later.

## Important

- Use HTTPS for the production base URL.
- Create the database before opening setup; Coveted does not create hosting-panel databases or database users.
- Do not point setup at a database that contains unrelated or partial Coveted tables.
- Keep `config.php` private and outside source control. It contains the database password.
- After install, restore normal production file permissions if you temporarily made the application root writable so PHP could create `config.php`.

## Optional command-line verification

After installation you may run:

```bash
php scripts/preflight.php --expect-installed --production
```

Before installation, advanced/manual deployments may create `config.php` from `config-example.php` and run:

```bash
php scripts/preflight.php --expect-empty --production
```

The browser installer is the normal V1 install path.

## First-install freeze point

Once the first real Coveted database is installed, treat `database/schema.sql` as the V1 baseline. Future database changes should use explicit migrations instead of silently rewriting a live installation.
