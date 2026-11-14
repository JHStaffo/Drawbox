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

## Social Security numbers and dates of birth

Team Members can have an SSN and date of birth on file, for checks such as the OIG exclusion list (LEIE), which matches on name and date of birth and confirms with the SSN. The date of birth is encrypted with the same key and follows the same rules below: same two permissions, shown as month and day only (04/12/XX) until Show, every look and change audit-logged without the date, never in the API or exports, and an optional Date of Birth CSV column (4/12/1985 or 1985-04-12) that fills blanks only.

- **Stored encrypted** with AES-256-GCM using its own key, `SSN_KEY` in `.env` (not `APP_KEY`, so replacing the app key never loses them, and a copy of the database alone shows nothing). Beside it are only the last 4 digits (for the masked •••-••-1234) and a keyed fingerprint (HMAC-SHA256) that finds a number without decrypting every row: duplicates are refused, and a later OIG check can match on it. See `app/Services/Ssn.php`.
- **Permissions**, both on Admin to start and nobody else: *See SSNs* shows the last 4 on Team Members and a Show button that displays the full number for 30 seconds; *Add, change or remove SSNs* adds the SSN box to Add a Team Member, a Change/Remove form on each row, and the optional SSN column in the CSV import (fills blanks only, like phone). Nobody can give these out without having them.
- **Audit log:** every look, add, change, removal and refusal is logged with who and which Team Member, never the number.
- **Never sent anywhere:** the SSN columns are hidden from every JSON and array (so never in `/api/v1`), are in no CSV export, and a failed save never puts the number back in the form.
- **The key:** make it once with `php artisan drawbox:ssn-key` (it won't replace an existing key), then `php artisan config:cache`. Keep a copy of `SSN_KEY` somewhere safe and separate from the database backups: without it the saved SSNs can't be read. Until the key is set, the SSN boxes don't show.

## OIG Exclusions

Admin > OIG Exclusions (permission OIG Exclusions, Admin only to start) checks every active Team Member against the OIG's List of Excluded Individuals/Entities. **Check now** downloads the OIG's current `UPDATED.csv` (`services.oig.url`; the server needs outbound HTTPS to oig.hhs.gov), and `php artisan oig:check` runs on its own on the 15th of each month from the cron. Every check is kept: the list shows each date with "0 exclusions found" or the count in red, and a date opens every Team Member checked with their date of birth as MM/DD/XX and Not excluded, Excluded or Needs manual check.

Matching (`app/Services/OigExclusions.php`): first and last name (compound last names and Jr/Sr/II/III are handled), then date of birth. Same name and date of birth is **Excluded**; same name with a different date of birth is Not excluded; same name with no date of birth on either side is **Needs manual check**. The OIG file has no SSNs, so a match is confirmed on exclusions.oig.hhs.gov (search the name, Verify with the SSN). The result is recorded on the check: outcome, how it was checked (an SSN in the note is refused), and optionally the OIG result page as a PDF or picture, stored encrypted. Recording it updates the counts, and every check, manual check and file opened is in the audit log.

## EMS Affiliations

Admin > EMS Affiliations (permission EMS Affiliations, Admin only to start) searches every active Team Member on the Virginia Office of EMS provider search (`services.va_ems.url`, https://vdhems.vdh.virginia.gov/emsapps/ProviderSearch.html; the server needs outbound HTTPS to it). **Check now** starts a check that `php artisan ems:run` picks up from the cron within a minute (a page can't wait for every search; the page shows progress), and `php artisan ems:check` runs one on its own on the 16th of each month. Searches are a second apart (`VA_EMS_PAUSE_MS`).

- **How it searches** (`app/Services/Ems/ProviderSearch.php`): the site is an Oracle APEX page with no API, so Pulse opens it, fills in the boxes it finds by their labels (Certification#, Last Name, First Name), presses Search, and reads the results table by its column headings. If the results have no agency column, it opens each matching provider's own page. **Try the search** on the page runs one search and shows what came back, including what Pulse found on the site, without saving anything. The site couldn't be reached while this was built, so try it first after deploying.
- **Matching:** by certification # when the Team Member has one, otherwise by first and last name. When a name search finds exactly one provider listed with our agency (Setting `ems_our_agency`, default FAST TRACK EMS, LLC, editable on the page), their number is saved and used from then on. Two or more matches with nobody at our agency show "More than one match" and save nothing; set the number on the Team Member's page.
- **Agencies:** names compare without punctuation or LLC/Inc endings. Every other agency a Team Member is listed with is kept with when it was first and last seen, and marked "No longer listed" when it disappears. A new one goes to **Needs review** (Acceptable or Unacceptable, with a note; Unacceptable can also add the agency to the list). Agencies on the **Unacceptable agencies** list are flagged without a review; removing one from the list sends those back to review. A person's own review always wins over the list.
- **Accepted exclusion:** a Team Member not expected on the provider search (with a reason). They're still searched every time, and the first time they turn up everyone with the permission is emailed and they're listed under Needs review.
- **Email:** after a check, everyone with the permission gets one email when there's anything to look at (unacceptable affiliations, not listed with us, new affiliations, an accepted exclusion found, not found, or couldn't be matched).
- If the first three searches all fail (the site is down or has changed), the check stops and says why instead of failing everyone. Every check, review, list change, number and exclusion is in the audit log.

## Forms and Slack

Dispatch forms replace Google Forms, the Google Sheet and Zapier. An admin builds each form under Admin > Forms (questions, choices, required or not) and picks the Slack channel it posts to. A form can also have its own Slack message, typed freely with `$answer1`, `$answer2` ... (and `$sender`, `$form`, `$date`, `$time`) filled in from the answers. When someone sends a form, Pulse saves it, then posts it to that channel right away. If Slack doesn't take it, the submission stays saved and `pulse:slack-retry` (every five minutes, from `schedule:run`) tries again up to five times; an admin can also press Post now under Admin > Forms > All submissions.

Slack is connected once, under Admin > Slack, with a Slack app's bot token (xoxb-...). The page has the app manifest and step-by-step setup. The token is stored encrypted with `APP_KEY`, so if the app key ever changes the token has to be pasted again. Public channels work straight away; private channels need `/invite @Fast Track Pulse` once. The server needs outbound HTTPS to slack.com.

Three permissions control it: Forms (fill out and send), Form reports (Dispatch > Form reports: filter by period, form and sender, and once a form is picked by any of its own questions; total by any question, optionally split by a second one into a grid; Team Member and position only appear when the form asks about a Team Member; CSV) and Forms and Slack (build forms, see every submission, connect Slack). All start on the Admin profile only. Every send, test, edit and retry is in the audit log; the token never is.

Shared lists (Admin > Shared lists, under the Forms and Slack permission) hold choices that grow over time, starting with Facilities. A "Shared list" question lets people pick an entry or type a new one, which is added to the list (matched ignoring capitals and extra spaces) unless the list is set to pick-only. Admins can bulk-add, rename, hide and remove entries; past submissions keep their own copy of the answer.

## RemoteLock

Admin > RemoteLock (permission "RemoteLock", Admin only at first) connects Pulse to RemoteLock with the client ID and secret of an OAuth application made in RemoteLock's developer portal (client credentials). They're stored encrypted with `APP_KEY`. The server needs outbound HTTPS to connect.remotelock.com and api.remotelock.com.

Pulse is the source of truth for each linked Team Member's name, email and whether they're active. Saving a Team Member sends the change right away: turning someone off deactivates them in RemoteLock (RemoteLock keeps the record and history), and deleting someone deactivates them first. RemoteLock can't reactivate a user, so turning someone back on makes a new access user with a new PIN. New Team Members (setting on the page, on by default) are added with their permission profile's door groups and the door PIN typed on the Add form (4 to 8 digits), or a random one when it's left blank; a linked Team Member's PIN can be changed from Admin > RemoteLock. PINs go to RemoteLock only and are never stored or logged in Pulse. if RemoteLock already has a user with the same email they're linked instead. Anything RemoteLock doesn't take is retried by `remotelock:sync` every five minutes from `schedule:run`.

The page also matches people already in RemoteLock to Team Members (suggested by email, then name), lists Team Members turned off in Pulse but still active in RemoteLock with one button to deactivate them, and sets door groups per profile. A sub-profile gets its main profile's door groups plus its own. Door groups are only given, never taken away; changing a profile's door groups doesn't touch people already in RemoteLock unless "Give profile doors" is pressed. Every action is in the audit log; the secret never is.

## Protocols

The Protocols menu holds the protocol book. Each protocol is a flowchart (boxes and arrows, drawn in the browser by Mermaid from a CDN, so there's still no build step) plus color-coded sections like Goals, Treatment, Special Considerations and Pediatrics. Boxes have a type (red action, white step, yes/no diamond, blue "Treatment per", yellow note, green Exit, black Transport), optional level badges (E, A, I, P, the pediatric stroller, M for Medical Control) and can open another protocol. Arrows go one way, both ways, or are a dotted line with no arrowhead, with an optional label (YES, NO, or anything). Mermaid lays the chart out automatically; when that doesn't line up, "Arrange boxes myself" in the builder switches the chart to hand placement: boxes are dragged in the preview (starting from where Mermaid put them) and each arrow can leave and enter a chosen side. Hand-arranged charts are drawn by `public/js/protocol-chart.js` with square-cornered lines. Text takes `**bold**`, `__underline__` and `- ` bullets; everything else is escaped.

Every change is a version. Builders (Build protocols) save drafts and send them for approval; the Medical Director (Medical Director permission) approves by typing their name, or sends it back with a note. Team Members (Protocol book) only ever see the approved version, which stays in the book while a revision is worked on, and approved versions are never edited. The book can be opened whole, by category, by age group (Infant, Pediatric, Adult) or one protocol at a time, searched, and printed one protocol per page. All three permissions start on the Admin profile only. Protocol categories (the book's chapters) are added, renamed, ordered and deleted in Admin > Settings; a category in use can't be deleted. `ProtocolVersion::toApi()` is the shape the future mobile API will serve.

Protocols > Build protocols > Import from PDF or photo takes one PDF or up to 10 photos (JPG, PNG, WebP; photos over 3.5 MB are shrunk with GD). The cron job runs `php artisan protocols:import` every minute: it sends the file to the Claude API (`anthropic-ai/sdk`, model `claude-opus-5-5`, answer held to a JSON schema) and asks for the protocols that start on the next two pages, so a whole book is read two pages a minute. The PDF is cached between requests, so the repeat reads cost a tenth. Each protocol found is saved as a version 1 draft laid out like the page (hand-arranged layout), categories are matched by name or added, and when the upload is finished, Exit and Treatment boxes are linked to the protocols they name. Anything Claude wasn't sure of shows as a note on the upload. A busy API is retried up to 5 times with a growing wait; other errors stop the upload with a Try again button. PHP's upload limit on cPanel defaults to 2 MB, so raise `upload_max_filesize` (25M) and `post_max_size` (30M) for the domain in cPanel > MultiPHP INI Editor. It needs an Anthropic API key: an admin pastes it in Admin > Settings (checked with Anthropic, stored encrypted with the app key), or `ANTHROPIC_API_KEY` goes in `.env`; a key saved in Settings wins. `php artisan protocols:import --all` reads everything waiting right away.

`php artisan db:seed --class=ProtocolSampleSeeder` loads the Cardiac Arrest protocol as a draft example. `php artisan protocols:load-sample` does the same, or, when Cardiac Arrest already exists, puts the arranged sample into its draft (starting a new version if it is approved, taking it back if it is waiting for approval) and keeps links builders set on its boxes.

## Wellness

The Wellness menu is its own module (`app/Wellness`, `routes/wellness.php`, tables starting `wellness_`). It only reads `users`, and nothing outside it reads its tables. Wellness settings can turn it off, which hides its menu, pages and API without touching the rest of Pulse.

- **Scoring:** up to 8 daily-habit points a day (meals 1 each up to 3, sleep 6 h, workout 30 min, 10,000 steps, 64 oz water, meal plan), 56 a Sunday to Saturday week. Readings are stored per day, metric and source. The best value per metric counts once, so a watch and a typed entry are never added together. Days can change until the grace period after their week (3 days by default), then they lock.
- **Rules** (Wellness > Point rules) are versioned. A change starts on a date you pick, today or later, and earlier days keep the rule they were scored under.
- **Who can use it:** anyone whose permission profile has Wellness, or anyone an admin adds as a tester under Wellness > Participants (no profile change, for trying it with a few people). Other permissions: Wellness review (device exceptions), Wellness admin (participants, testers, rules) and Wellness settings. Admin starts with those three.
- **Sign-up:** Wellness is opt-in. Until a Team Member agrees on the sign-up page, only that page and the rules open; everything else (and the API) waits.
- **Activities and rewards** (Wellness > Activities and rewards): visits need a photo or PDF of a signed attendance note, InBody scans need the printout plus body fat % and skeletal muscle (lb), and weigh-ins score straight away. Notes are stored encrypted in `storage/app/private/wellness/notes` and every view is in the audit log. Reviewers (Wellness > Review queue) approve, or send back with a reason that the Team Member can fix and resend; nobody decides their own. Approved points respect each activity's cap. InBody scores when it beats the last approved scan at least 28 days earlier by the thresholds in Wellness settings.
- **Weekly reward:** `wellness:close-weeks` runs daily at 6 AM and creates a gift card for everyone with 56 daily-habit points in a week that has closed, then emails them to pick a card. Admins mark them sent or void them under Wellness > Rewards, which also has the rewards CSV and a weekly points CSV.
- **Adjustments:** from a participant's page, with a reason; they're new ledger rows, never edits, and daily points still stay within 8.
- **Test period:** points before the official start date (Wellness settings, default January 1, 2027) don't count toward prizes.
- **API:** `/api/v1` for phone apps and the Apple Health Shortcut, documented in [docs/api.md](docs/api.md). Team Members make sync keys under Wellness > Devices.
- **Apple Health:** a Shortcut on each iPhone sends steps, sleep and exercise to `POST /api/v1/wellness/apple-health` every night. Build and share it once with [docs/apple-health-shortcut.md](docs/apple-health-shortcut.md), then paste its iCloud link into Wellness settings so the Devices page offers it. `wellness:sync-check` runs daily at 10 AM and emails anyone whose device hasn't synced in 2 days, once per gap.

## Training

The Training menu replaces TalentLMS (and Trainual before it). It lives in `app/Training`, `routes/training.php` and tables starting `training_`.

- **Courses** (Training > Courses, permission Build courses): a course is lessons in order. A lesson is a page from the course designer (a word-processor style editor, Quill 2.0.3 from jsDelivr like Mermaid, so there's still no build step), a PDF, a PowerPoint, a video or audio file, a YouTube/Vimeo/Loom link (played in the page) or other link, a file to download, or a quiz. Every lesson can have designer text above it and a minimum time before "Mark as done" works. The designer's HTML is cleaned on save (`App\Training\RichText`): only formatting, links, pictures and YouTube/Vimeo/Loom players survive. Pictures are uploaded to `storage/app/private/training/images` and served at unguessable addresses.
- **Sections** (optional) group a course's lessons under named headings, such as Regulations, Policies, Procedures and Risks for a driving course. Add, rename, reorder and remove them on the course's builder page; a lesson's section is picked when it's added or on its edit page. Removing a section keeps its lessons in the course. Lessons outside any section come first. Team Members see the headings with how many lessons in each they've finished, and the AI course writer groups its pages into sections too.
- **Versions**: once a course is published it is version 1, and Team Members only ever see published versions. Editing a published lesson (its page, file, settings or quiz questions) saves a pending edit on a copy of it; new lessons wait as "not published"; removing a lesson marks it to go. The course page lists the unpublished changes with "Publish version N" and an optional "What changed" note (Discard changes / Keep it undo single items). Lesson order, sections and course details apply straight away. Publishing copies the edits onto the published lessons, so progress and grades stay attached. Team Members who finished an earlier version keep their completion; their course page shows what changed and marks updated lessons. Every visit records the first and last time each Team Member opened each version, shown under Version history.
- **Training emails** (Admin > Settings > Training, off by default): `php artisan training:update-emails` runs daily at 8 AM and sends each Team Member one email listing finished courses that have a newer version. While the setting is off, updates are marked as handled and never emailed later, so turning it on doesn't send a backlog.
- **Files** are stored in `storage/app/private/training` and only served to people who have the course or run training. PowerPoints show in Microsoft's free Office viewer, which fetches the file through a two-hour signed link (so the site must be reachable from the internet, and decks over about 10 MB fall back to a download; a PDF of the deck always shows). Videos stream with seeking. PHP's upload limit decides the biggest file: raise `upload_max_filesize` and `post_max_size` in cPanel > MultiPHP INI Editor for videos.
- **Quizzes**: one answer, select all that apply (all-or-nothing), or true/false. Each quiz has a passing score (default 80%), attempts allowed (blank = no limit), whether to show the right answers afterwards, and question shuffling. Every submitted attempt keeps a copy of the questions as asked, so editing a quiz never changes a grade on record. A trainer can allow one more attempt to someone who ran out.
- **Time** is counted in the browser while a lesson is in front and in use (or a video plays), and sent every 15 seconds; ten idle minutes stop the count. Each report adds at most 60 seconds and never more than has passed, so two tabs don't double it. Time reports aren't in the audit log; everything else is.
- **Completion** is lessons done ÷ lessons in the course; a quiz is done when passed. The course grade is the best score on its quiz (the average with more than one).
- **Assigning** (Training > Assign, permission Assign training): courses or a training profile, to Team Members and/or everyone on chosen permission profiles, with a due date (or each course's days to finish) and a note. **Reassign** (from a course's Progress page or a Team Member's page) keeps the old assignment and its results as history and starts a fresh one.
- **Training profiles** are bundles of courses in order (New Hire Orientation, say), separate from permission profiles. One can be given automatically to permission profiles: new Team Members on them, and anyone moved onto them, get its published courses (a main profile includes its sub-profiles), skipping courses they already have. "Give to everyone on its profiles now" catches up existing Team Members.
- **Write a course with AI** (Training > Courses, under Build courses): give a topic, who it's for, goals, anything it must cover, and optionally a PDF or text file (the handbook section or SOP), plus how many lesson pages and quiz questions. The cron job runs `php artisan training:generate` every minute, which sends it to Claude (same Anthropic key and model as the protocol import, answer held to a JSON schema) and saves the result as a **draft** course: designer pages, a quiz with the chosen passing score, and optionally the uploaded PDF as the first lesson. Pages go through the same HTML cleaning as the designer, and quiz questions that don't hold up (no right answer, two right answers on a one-answer question) are dropped. Anything Claude couldn't know is marked "[Fast Track EMS to confirm: ...]" and listed on the request page. A busy API is retried up to 5 times with a growing wait.
- **Expected time** is worked out from each lesson's content (`App\Training\Estimate`), never typed in: 200 words a minute for page text (plus 12 seconds a picture), 2 minutes a PDF page, 1 minute a PowerPoint slide (counted inside the .pptx), a video or audio file's running time (read from the MP4/MOV/M4A header or WAV format, otherwise guessed from the file size) plus 10%, 5 minutes a link, 3 minutes a download, and 45 seconds a quiz question (75 for select-all). A lesson's minimum time wins when it's longer. The course's expected time is the sum, shown on My Training, the course, the builder, progress pages, the report CSV and the API. It's recalculated whenever a lesson or quiz question is saved.
- **Reference library**: every course a Team Member has finished stays on My Training to open again, and the search box there finds words in the titles and page text of their finished courses (quiz questions are not searched), with the matching passage highlighted.
- **Reports** (permission Training reports): filter by course, Team Member, position, status and assigned dates; CSV of assignments and of every quiz attempt.
- Permissions: Training (take courses), Build courses, Assign training, Training reports. Admin starts with all four; tick Training on Team Member (and other profiles) to open it to everyone.
- **API:** `/api/v1/training/...`, see [docs/api.md](docs/api.md).

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
   php artisan drawbox:ssn-key    # the key that encrypts SSNs; back it up apart from the database
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
- `app/Wellness/`: the Wellness module (`Scoring.php` for points, `Progress.php` for the dashboard, `Leaderboard.php`)
- `routes/api.php`: the API for phone apps and device syncs
- `routes/console.php`: scheduled jobs and the `drawbox:make-admin` command
