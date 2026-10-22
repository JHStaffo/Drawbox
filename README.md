# Draw Box

Fast Track EMS's draw box, online. Dispatch credits a crew for a solicited call, each crew member signs in with an emailed code and draws a prize, and supervisors hand prizes out, restock the box, and run reports. The reports include per-employee prize value for taxes.

## How the draw works

- Each prize has a number of envelopes in the box. The chance of drawing a prize is its envelopes divided by all envelopes left. As valuable prizes run out during the month, candy and snack become more likely.
- Each prize has a **Diminishing** checkbox. Drawing a diminishing prize uses up one envelope. Non-diminishing prizes (candy, snack) keep the same count, so the count only sets their odds, and restock skips them.
- The home page, dashboard and inventory page all show the current dollar value of the box: every envelope left times its prize value. Prizes already won are not included.
- Prizes with **Cash bonus OK** checked let the winner choose "Issue as taxable cash bonus" instead, any time before the prize is handed out. The bonus equals the prize's value and is always taxable. Whatever the prize took out of the box (an item or a gift card) goes back in. Supervisors mark it paid, and the tax report shows cash bonuses in their own column.
- Crew members aren't emailed the moment dispatch credits them. Every 2 hours, each team member with new draws gets one email listing them, so a credit undone in the meantime never sends anything.
- When the setting is on, each employee gets one email listing all of their draws that expire within the next 24 hours (checked hourly).
- Any other prize can optionally have **choices** (for example the kinds of candy bar in the Candy prize). The winner picks one after drawing, and that choice's count goes down by one. A diminishing prize with choices counts its choices as its envelopes. A non-diminishing prize with choices keeps its own count for the odds, but can't be drawn once every choice is out.
- Each gift card value (for example $50) gets as many envelopes as there are brand cards in stock at that value. After winning a value, the employee picks a brand. Their card is held for them until they choose, so nobody else can take it.
- Draws happen on the server, are final, and are logged in the inventory change log.
- A prize moves from **won** to **received** when a supervisor marks it handed out. Tax totals count prizes by the date they were received, at the value they had when drawn.

## Roles

| Role | Can |
|---|---|
| employee | Draw, pick a gift card brand, see their own prizes |
| dispatcher | Everything an employee can, plus the call credit form |
| supervisor | Everything a dispatcher can, plus the dashboard: marking prizes received, dispensing to lockers and checking overdue lockers |
| admin | Everything a supervisor can, plus reports, inventory, restock, settings, the locker map and managing the team member roster |

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
   > The document root must be the `public` folder, never the project folder itself. Otherwise `.env`, with its database and mail passwords, can be downloaded from the web.
3. **Database:** create a MySQL database and user in cPanel, and give the user all privileges on the database.
4. **Mail:** the app sends mail as `drawbox@fasttrackems.com` through Google Workspace. Sign in to that account, turn on 2-Step Verification, and create an App Password at myaccount.google.com/apppasswords. In WHM, go to Security Center, then SMTP Restrictions, and make sure it is **disabled**. When it's on, cPanel blocks connections to Gmail.
5. **Code:** in cPanel's Git Version Control, clone this repository into `~/drawbox`. Then, in cPanel's Terminal (cPanel's jailed shell has no `composer` command, so download a copy into the project; it's git-ignored):
   ```sh
   cd ~/drawbox
   php -v    # must say 8.3 or newer; if not, use the full path below in place of "php"
             # /opt/cpanel/ea-php83/root/usr/bin/php
   curl -sS https://getcomposer.org/installer | php
   php composer.phar install --no-dev --optimize-autoloader
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
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_SCHEME=null
   MAIL_USERNAME=drawbox@fasttrackems.com
   MAIL_PASSWORD="the 16-character app password"
   MAIL_FROM_ADDRESS=drawbox@fasttrackems.com
   ```
   Then:
   ```sh
   php artisan migrate --force --seed
   php artisan drawbox:make-admin you@fasttrackems.com "Your Name"
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. **Cron:** in cPanel's Cron Jobs, add a job that runs every minute. It expires overdue draws and sends the new-draw emails (every 2 hours) and reminder emails.
   ```
   * * * * * cd ~/drawbox && php artisan schedule:run >> /dev/null 2>&1
   ```
   If `php -v` showed an older version, use `/opt/cpanel/ea-php83/root/usr/bin/php` in place of `php` here too.

### Updating

Use **Update from Remote** in Git Version Control, then:

```sh
cd ~/drawbox
php composer.phar install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Where things are

- `app/Services/DrawMachine.php`: the draw and the brand pick
- `app/Services/Inventory.php`: envelope counts, odds, manual changes and restock
- `app/Services/Credits.php`: crediting a call, expiry and reminders
- `app/Services/Reports.php`: dashboard periods and per-employee totals
- `routes/console.php`: scheduled jobs and the `drawbox:make-admin` command
