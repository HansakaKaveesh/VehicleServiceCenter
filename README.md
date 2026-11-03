# Vehicle Service Center Management System (VSCMS)

A complete web-based system to manage a vehicle service center: customers, vehicles,
service history (by registration number), spare parts inventory, invoices, suppliers,
expenses, reports, users, and database backup/restore.

## Tech Stack
- **Backend:** PHP (PDO, prepared statements)
- **Database:** MySQL
- **Frontend:** HTML, Tailwind CSS (CDN), JavaScript
- **Icons:** Font Awesome (CDN)

## Installation
1. Copy the project folder to your web server (e.g. `htdocs/vscms` for XAMPP).
2. Create the database by importing `database.sql` via phpMyAdmin:
   - Open phpMyAdmin -> Import -> choose `database.sql` -> Go.
3. Edit `config/database.php` with your DB credentials (host, name, user, password).
4. Open `http://localhost/vscms/login.php` in your browser.

## Default Login
| Username | Password  | Role  |
|----------|-----------|-------|
| admin    | admin123  | admin |

> **Important:** After first login, change the admin password via **Users** page.
> (The seeded hash in `database.sql` is for `password`; run `users.php` "edit" to set `admin123`,
> or simply create a new admin user and delete the default one.)

## Modules
- **Dashboard** – daily activity, summaries, low-stock alerts, service reminders, recent invoices
- **Customers** – register/update customers, linked vehicles
- **Vehicles** – registration-number lookup, service history per vehicle
- **Services** – job records, labor charges, status tracking, next-service date/mileage reminders
- **Spare Parts** – stock levels, purchases/usage/adjustments, low-stock report, suppliers
- **Invoices** – labor + parts + discount + tax, payments, balances, printable invoice
- **Suppliers** – supplier records and linked parts
- **Expenses** – daily expense recording with monthly views
- **Reports** – daily/monthly/yearly income, expenses, service counts, stock value, outstanding payments
- **Users** – admin-only role management (passwords hashed with `password_hash`)
- **Backup/Restore** – download full SQL backup, restore from `.sql` file

## Security Notes
- All SQL uses PDO prepared statements (SQL-injection safe).
- Output is escaped with `htmlspecialchars` (XSS safe).
- Session-based auth with `session_regenerate_id` on login; admin-only pages guarded by `require_admin()`.
- Set a strong DB password and disable directory listing in production.
