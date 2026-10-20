# Draw Box

Laravel 13 app for Fast Track EMS, deployed on a WHM/cPanel server (PHP + MySQL). Read README.md for how the draw works and how it is deployed.

- There is no frontend build step on purpose. The CSS is plain `public/css/app.css` and the views are Blade, because the cPanel host has no Node.js. Don't add Vite or npm assets.
- Mail is sent synchronously (no queue worker on cPanel). Only `schedule:run` runs from cron.
- Draws copy the prize's name, value, PTO hours and taxable flag, so catalog edits never change history. Keep it that way, because the values are used for tax reporting.
- Run `php artisan test` before pushing.
