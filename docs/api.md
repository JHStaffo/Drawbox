# Fast Track Pulse API, version 1

The API is for phone apps and device syncs (the Apple Health Shortcut now, native iPhone and Android apps later). It uses the same sign-in, permissions and scoring as the website, so the numbers always match.

Base URL: `https://drawbox.fasttrackems.com/api/v1`. Send `Accept: application/json` on every request. Dates are `YYYY-MM-DD` in Eastern time.

## Signing in

Apps sign in like the website: email first, then the 6-digit code from the email.

```http
POST /auth/code
{"email": "medic@fasttrackems.com"}
→ 200 {"message": "If that email belongs to a Team Member, a 6-digit code is on its way..."}

POST /auth/token
{"email": "medic@fasttrackems.com", "code": "123456", "device_name": "Jamie's iPhone"}
→ 201 {"token": "12|abc...", "user": {...}}
```

Send the token on every other request as `Authorization: Bearer 12|abc...`. `DELETE /auth/token` signs the app out. Team Members can see and remove their signed-in apps under Wellness > Devices. Sign-in is limited to 6 code requests and 10 token attempts a minute; other calls to 120 a minute.

There are two kinds of token:

| Token | Made by | Can |
| --- | --- | --- |
| App token (`wellness`, `training`) | `POST /auth/token` | Everything below. Tokens made before Training was added have only `wellness`; sign in again for Training |
| Sync key (`wellness:import`) | Wellness > Devices > Make a sync key | Only `POST /wellness/readings` and `POST /wellness/apple-health` |

## Endpoints

| Method and path | What it does |
| --- | --- |
| `GET /me` | The signed-in Team Member, their permissions, and whether they've joined Wellness |
| `GET /wellness/rules` | Current point rules, daily maximum (8), weekly target (56), grace days, official start date, and the metrics with their units and limits |
| `POST /wellness/join` | Join with `{"consent": true}` after showing the rules and privacy notice |
| `GET /wellness/summary` | Today, this week, streak, totals, the last 30 days, and last device sync |
| `GET /wellness/days?from=&to=` | Each day's habits, values, sources and points (up to 93 days) |
| `GET /wellness/days/{day}` | One day |
| `PUT /wellness/days/{day}` | Typed-in values for a day, e.g. `{"meals": 3, "water_oz": 64, "meal_plan": 1}`. A `null` clears a value. Steps, sleep and workout only if typing them is allowed (testing setting or an exception) |
| `POST /wellness/readings` | Device data (below) |
| `POST /wellness/apple-health` | The Apple Health Shortcut's simpler form of device data (below) |
| `GET /wellness/leaderboard?period=week\|month\|quarter\|year` | Names, points and ranks only |
| `GET /wellness/submissions` | Their visits, InBody scans and weigh-ins, with status, points and any reason it was sent back |
| `POST /wellness/submissions` | Multipart form: `activity` (a rule key such as `dentist`), `day`, `note` (photo or PDF, up to 10 MB, needed for visits and InBody), `body_fat_pct` and `muscle_lb` for InBody, `comment`, and `fix` (the id of a sent-back submission being corrected). Weigh-ins score right away; the rest wait for review |
| `POST /wellness/submissions/{id}/withdraw` | Withdraw one still waiting for review |
| `GET /wellness/awards` | Their weekly rewards and the gift card choices |
| `PUT /wellness/awards/{id}/brand` | Pick a gift card: `{"brand": "Target"}` |

Until the Team Member has signed up, only `/me`, `/wellness/rules` and `/wellness/join` work. Show the agreement from the website's sign-up page and the rules before calling join.

Errors: `401` bad or removed token, `403` no Wellness access (profile permission or tester) or wrong token kind, `404` Wellness is turned off, `409` with `"code": "not_joined"` before joining, `422` validation errors in Laravel's usual `{"message", "errors"}` shape.

## Training

Needs the Training permission on the Team Member's profile. Lessons are `text`, `pdf`, `slides`, `video`, `audio`, `link`, `file` or `quiz`. `html` is the course designer's page (already cleaned; pictures are paths like `/training/images/...` on the same host). File links are signed and work for two hours without the token, so a player can open them directly.

| Method and path | What it does |
| --- | --- |
| `GET /training/assignments` | Their current courses (each course with `estimated_seconds`): status (`not_started`, `in_progress`, `overdue`, `completed`), percent, seconds spent, quiz grade, due date |
| `GET /training/assignments/{id}` | The course's `sections` (`id`, `title`, in order) and each lesson in order: `section` (its section's title, or null), `html`, `file` (`name`, `mime`, `size`, `url`), `embed_url` for YouTube/Vimeo/Loom links, `min_seconds`, `estimated_seconds`, time spent, `completed_at`, and for quizzes `passing_score`, `best_score`, attempts `used`/`allowed`/`left` |
| `POST /training/assignments/{id}/lessons/{lesson}/time` | `{"seconds": 15}` while the lesson is open and in use. Each report adds at most 60 seconds and no more than has passed since the last one |
| `POST /training/assignments/{id}/lessons/{lesson}/done` | Finish a non-quiz lesson (`422` with a message if its minimum time isn't reached) |
| `POST /training/assignments/{id}/lessons/{lesson}/attempts` | Start (or resume) a quiz attempt. Returns the questions in order with choices as `{index, text}`, never the answers |
| `POST /training/attempts/{id}` | Submit `{"answers": {"<question id>": 0, "<question id>": [0, 2]}}`. Returns score, passed, and per-question results (with right answers when the quiz shows them) |

## Device readings

Each reading is a day's total for one metric. Sending the same day and metric again from the same source replaces the earlier value. If a value also comes from somewhere else, such as a typed entry, the higher one counts, never both.

```http
POST /wellness/readings
{
  "source": "apple_health",
  "device": "Apple Watch Series 10",
  "readings": [
    {"day": "2026-12-09", "metric": "steps", "value": 10422},
    {"day": "2026-12-09", "metric": "sleep_minutes", "value": 415},
    {"day": "2026-12-09", "metric": "exercise_minutes", "value": 34},
    {"day": "2026-12-09", "metric": "water_oz", "value": 70}
  ]
}
→ 200 {"accepted": [{"day": "2026-12-09", "points": 4, "changes": [...]}], "skipped": []}
```

Metrics: `steps`, `sleep_minutes` (the night ending that morning), `exercise_minutes`, `water_oz`. Up to 200 readings per call. Days in the future or in a closed week are skipped with a reason, `future` or `closed`, and the rest are saved. Every sync is in the audit log.

## Apple Health Shortcut

`POST /wellness/apple-health` takes flat fields, as JSON or a form, the way an iOS Shortcut builds them. It answers with a `message` the Shortcut shows as a notification.

```http
POST /wellness/apple-health
Authorization: Bearer <sync key>
Accept: application/json
{"day": "2026-12-09", "steps": "10,422", "sleep_hours": "6.9 hr", "exercise_minutes": 34}
→ 200 {"message": "Pulse: Today 10,422 steps, 6.9 h sleep, 34 min exercise. 3 of 8 points so far.", "days": [...]}
```

- `day`: `yyyy-MM-dd`, `today` or `yesterday`. Leave it out for today.
- `steps`, `exercise_minutes`, `water_oz`, and sleep as `sleep_minutes` or `sleep_hours` (`7.5`, `7:30` or `7 hr 30 min`). Commas and units are ignored.
- The same fields with a `yesterday_` prefix save the day before `day` in the same call.
- Blank and zero values are skipped, so a Shortcut that finds no data never wipes out a real value.
- Saved with source `apple_health`, the same as `POST /wellness/readings`.

How to build the Shortcut is in [apple-health-shortcut.md](apple-health-shortcut.md).
