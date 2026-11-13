# Draw Box

Fast Track EMS's draw box, online. Dispatch credits a crew for a solicited call, each crew member signs in with an emailed code and draws a prize, and supervisors hand prizes out, manage the box, and run reports. The reports include per-employee prize value for taxes.

## How the draw works

- Each diminishing prize has two numbers, shown as **draws left / on hand** (for example 2/3 left). Every draw lowers draws left by one, even when the winner takes a cash bonus. On hand goes down only when the prize itself is taken. A prize's envelopes are its draws left, capped at what's on hand, so a prize at 0 of either is out of the drawing. The chance of drawing a prize is its envelopes divided by all envelopes left, so as valuable prizes run out during the month, candy and snack become more likely.
- On hand is the whole inventory in the safe (for gift cards, the brand counts). Each prize also has a **monthly** amount, and **Reset available** on the Inventory page sets every prize's draws left back to it. For example, 32 $10 cards on hand with a monthly amount of 8 resets to 8/32 left. Draws left and the monthly amount can be edited on each prize.
- Each prize has a **Diminishing** checkbox. Non-diminishing prizes (candy, snack) never run out, so their count only sets their odds and reset skips them.
- The home page, dashboard and inventory page all show the current dollar value of the box: every envelope left times its prize value. Prizes already won are not included.
- Prizes with **Cash bonus OK** checked let the winner choose "Issue as taxable cash bonus" instead, any time before the prize is handed out. The bonus equals the prize's value and is always taxable. The item or gift card goes back on hand, but the draw still counts against the prize's draws left. Supervisors mark it paid, and the tax report shows cash bonuses in their own column.
- Crew members aren't emailed the moment dispatch credits them. Every 2 hours, each team member with new draws gets one email listing them, so a credit undone in the meantime never sends anything.
- A credited draw can't be drawn right away: it waits 2 hours (Settings > Wait before drawing), showing on Home as "Available 3:15 PM". Its email goes out with the first batch after it opens.
- When the setting is on, each employee gets one email listing all of their draws that expire within the next 24 hours (checked hourly).
- Any other prize can optionally have **choices** (for example the kinds of candy bar in the Candy prize). The winner picks one after drawing, and that choice's count goes down by one. A diminishing prize with choices counts its choices as its envelopes. A non-diminishing prize with choices keeps its own count for the odds, but can't be drawn once every choice is out.
- Each gift card value (for example $50) is on hand as many times as there are brand cards in stock at that value. After winning a value, the employee picks a brand. Their card is held for them until they choose, so nobody else can take it.
- Draws happen on the server, are final, and are logged in the inventory change log.
- A prize moves from **won** to **received** when a supervisor marks it handed out. Tax totals count prizes by the date they were received, at the value they had when drawn.

## Permission profiles

Each team member has one permission profile, and admins edit profiles under Admin > Permission Profiles. Everyone can draw, pick a gift card brand and see their own prizes. A profile adds any of: Credit a call, Dashboard, Reports, Inventory, Locker map, Change or remove won prizes, Scope Check, Team Members, Settings, Audit log and Permission Profiles. A profile can have sub-profiles (EMT under Team Member, say), one level deep: a sub-profile gets everything its main profile allows plus what's checked for it. New features add a permission key in `PermissionProfile::GROUPS`; every profile starts without it. The starting profiles are Team Member, Dispatcher, Supervisor and Admin, matching the old fixed roles. `drawbox:make-admin` puts someone on the Admin profile and gives that profile every permission.

## Forms and Slack

Dispatch forms replace Google Forms, the Google Sheet and Zapier. An admin builds each form under Admin > Forms (questions, choices, required or not) and picks the Slack channel it posts to. A form can also have its own Slack message, typed freely with `$answer1`, `$answer2` ... (and `$sender`, `$form`, `$date`, `$time`) filled in from the answers. When someone sends a form, Pulse saves it, then posts it to that channel right away. If Slack doesn't take it, the submission stays saved and `pulse:slack-retry` (every five minutes, from `schedule:run`) tries again up to five times; an admin can also press Post now under Admin > Forms > All submissions.

Slack is connected once, under Admin > Slack, with a Slack app's bot token (xoxb-...). The page has the app manifest and step-by-step setup. The token is stored encrypted with `APP_KEY`, so if the app key ever changes the token has to be pasted again. Public channels work straight away; private channels need `/invite @Fast Track Pulse` once. The server needs outbound HTTPS to slack.com.

Three permissions control it: Forms (fill out and send), Form reports (Dispatch > Form reports: filter by period, form and sender, and once a form is picked by any of its own questions; total by any question, optionally split by a second one into a grid; Team Member and position only appear when the form asks about a Team Member; CSV) and Forms and Slack (build forms, see every submission, connect Slack). All start on the Admin profile only. Every send, test, edit and retry is in the audit log; the token never is.

Shared lists (Admin > Shared lists, under the Forms and Slack permission) hold choices that grow over time, starting with Facilities. A "Shared list" question lets people pick an entry or type a new one, which is added to the list (matched ignoring capitals and extra spaces) unless the list is set to pick-only. Admins can bulk-add, rename, hide and remove entries; past submissions keep their own copy of the answer.

## Protocols

The Protocols menu holds the protocol book. Each protocol is a flowchart (boxes and arrows, drawn in the browser by Mermaid from a CDN, so there's still no build step) plus color-coded sections like Goals, Treatment, Special Considerations and Pediatrics. Boxes have a type (red action, white step, yes/no diamond, blue "Treatment per", yellow note, green Exit, black Transport), optional level badges (E, A, I, P, the pediatric stroller, M for Medical Control) and can open another protocol. Arrows go one way, both ways, or are a dotted line with no arrowhead, with an optional label (YES, NO, or anything). Text takes `**bold**`, `__underline__` and `- ` bullets; everything else is escaped.

Every change is a version. Builders (Build protocols) save drafts and send them for approval; the Medical Director (Medical Director permission) approves by typing their name, or sends it back with a note. Team Members (Protocol book) only ever see the approved version, which stays in the book while a revision is worked on, and approved versions are never edited. The book can be opened whole, by category, by age group (Infant, Pediatric, Adult) or one protocol at a time, searched, and printed one protocol per page. All three permissions start on the Admin profile only. `ProtocolVersion::toApi()` is the shape the future mobile API will serve.

`php artisan db:seed --class=ProtocolSampleSeeder` loads the Cardiac Arrest protocol as a draft example.

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
- `app/Services/Inventory.php`: envelope counts, odds, manual changes and reset
- `app/Services/Credits.php`: crediting a call, expiry and reminders
- `app/Services/Reports.php`: dashboard periods and per-employee totals
- `routes/console.php`: scheduled jobs and the `drawbox:make-admin` command
