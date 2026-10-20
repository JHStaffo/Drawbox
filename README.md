# Draw Box

Fast Track EMS's draw box, online. Dispatch credits a crew for a solicited call, each crew member signs in with an emailed code and draws a prize, and supervisors hand prizes out, restock the box, and run reports. The reports include per-employee prize value for taxes.

## How the draw works

- Each prize has a number of envelopes in the box. The chance of drawing a prize is its envelopes divided by all envelopes left. As valuable prizes run out during the month, candy and snack become more likely.
- "Always in the box" prizes (candy, snack) never run out. Their count only sets their odds.
- Each gift card value (for example $50) gets as many envelopes as there are brand cards in stock at that value. After winning a value, the employee picks a brand. Their card is held for them until they choose, so nobody else can take it.
- Draws happen on the server, are final, and are logged in the inventory change log.
- A prize moves from **won** to **received** when a supervisor marks it handed out. Tax totals count prizes by the date they were received, at the value they had when drawn.

## Roles

| Role | Can |
|---|---|
| employee | Draw, pick a gift card brand, see their own prizes |
| dispatcher | Everything an employee can, plus the call credit form |
| supervisor | Everything a dispatcher can, plus the dashboard, marking prizes received, reports, inventory, restock and settings |
| admin | Everything a supervisor can, plus managing the employee roster |

## Run locally

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed                 # starting prize catalog
php artisan db:seed --class=DemoSeeder     # optional sample employees, stock and draws
php artisan serve
```

Sign in as `admin@example.com` (from the demo seeder). With `MAIL_MAILER=log`, the sign-in code is written to `storage/logs/laravel.log`.

Run the tests with `php artisan test`.

## Deploy on cPanel

1. **PHP:** in WHM's MultiPHP Manager, set the subdomain to PHP 8.3 or newer. It needs the extensions `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo` and `bcmath`.
2. **Subdomain:** create `drawbox.fasttrackems.com` in cPanel and set its document root to `drawbox/public`. Run AutoSSL so it gets a certificate.
3. **Database:** create a MySQL database and user in cPanel, and give the user all privileges on the database.
4. **Mailbox:** create `drawbox@fasttrackems.com` (or use an existing mailbox) for sending sign-in codes and reminders.
5. **Code:** in cPanel's Git Version Control, clone this repository into `~/drawbox`. Then, in cPanel's Terminal:
   ```sh
   cd ~/drawbox
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   ```
   Edit `.env`:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://drawbox.fasttrackems.com

   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=cpaneluser_drawbox
   DB_USERNAME=cpaneluser_drawbox
   DB_PASSWORD=...

   MAIL_MAILER=smtp
   MAIL_HOST=mail.fasttrackems.com
   MAIL_PORT=465
   MAIL_SCHEME=smtps
   MAIL_USERNAME=drawbox@fasttrackems.com
   MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS=drawbox@fasttrackems.com
   ```
   Then:
   ```sh
   php artisan migrate --force --seed
   php artisan drawbox:make-admin you@fasttrackems.com "Your Name"
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. **Cron:** in cPanel's Cron Jobs, add a job that runs every minute. It expires overdue draws and sends reminder emails.
   ```
   * * * * * cd ~/drawbox && php artisan schedule:run >> /dev/null 2>&1
   ```

### Updating

Use **Update from Remote** in Git Version Control, then:

```sh
cd ~/drawbox
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Where things are

- `app/Services/DrawMachine.php`: the draw and the brand pick
- `app/Services/Inventory.php`: envelope counts, odds, manual changes and restock
- `app/Services/Credits.php`: crediting a call, expiry and reminders
- `app/Services/Reports.php`: dashboard periods and per-employee totals
- `routes/console.php`: scheduled jobs and the `drawbox:make-admin` command
