# Building the Fast Track Pulse Apple Health Shortcut

Apple doesn't let a website read Apple Health. Instead, a Shortcut on each Team Member's iPhone reads the day's totals and sends them to Pulse. You build it once on your own iPhone, test it, and share it as an iCloud link. Team Members install it from their Devices page and paste in their own sync key.

You need an iPhone with iOS 17 or newer and an Apple Watch that records steps, exercise and sleep. Plan on about 30 minutes.

## What it does

Each run sends two days, yesterday first and then today:

- **Steps:** the day's total. Grouping by day makes Apple Health merge iPhone and Watch steps, so they aren't counted twice.
- **Exercise minutes:** the Apple Watch green ring, summed for the day.
- **Sleep:** time asleep in the night that ended that morning. "In Bed" and "Awake" are left out.
- **Water:** optional. This is Dietary Water, if they log it in Health.

Each request goes to `POST https://drawbox.fasttrackems.com/api/v1/wellness/apple-health` with the Team Member's sync key. Pulse answers with a one-line message, which the Shortcut shows. Sending yesterday again means a night the phone missed is caught up the next night. Blank and zero values are ignored, so one bad read never wipes out good data.

## Before you start

1. Sign up for Wellness in Pulse if you haven't. Then go to **Wellness > Devices**, make a sync key and copy it.
2. On the iPhone, open **Shortcuts** and tap **+** to make a new shortcut. Name it **Fast Track Pulse**.

## The actions, in order

Search for each action by the name in bold. Text in `code` is what you type or pick. "Magic variable" means tapping the blue output of an earlier action.

### 1. The key

1. **Text**: paste your sync key. Rename the output (tap it, then Rename) to `Key`.

### 2. Start of today

2. **Date**: `Current Date`.
3. **Adjust Date**: `Get Start of Day` of the Date from step 2. Rename the output to `Today Start`.

### 3. Loop over yesterday and today

4. **List**: two items, `1` and `0`. These are "days ago", and yesterday goes first so today's message is the one shown at the end.
5. **Repeat with Each** item in List.

Everything below, until **End Repeat**, goes inside the loop.

### 4. The day's dates (inside the loop)

6. **Adjust Date**: `Subtract` `Repeat Item` `days` from `Today Start`. Rename to `Day Start`.
7. **Adjust Date**: `Add` `1` `days` to `Day Start`. Rename to `Day End`.
8. **Adjust Date**: `Subtract` `12` `hours` from `Day Start`. Rename to `Sleep From` (noon the day before).
9. **Adjust Date**: `Add` `12` `hours` to `Day Start`. Rename to `Sleep To` (noon that day).
10. **Format Date**: `Day Start`, Date Format `Custom`, `yyyy-MM-dd`, Time Format `None`. Rename to `Day`.

### 5. Steps

11. **Find Health Samples** where:
    - `Type` is `Steps`
    - `Start Date` is between `Day Start` and `Day End`
    - Group By `Day`, Fill Missing `off`
12. **Calculate Statistics**: `Sum` of the Health Samples. Rename to `Steps`.

### 6. Exercise minutes

13. **Find Health Samples** where `Type` is `Exercise Minutes` (Apple Exercise Time) and `Start Date` is between `Day Start` and `Day End`. Group By `Day`.
14. **Calculate Statistics**: `Sum`. Rename to `Exercise`.

### 7. Sleep

15. **Find Health Samples** where:
    - `Type` is `Sleep Analysis`
    - `Start Date` is between `Sleep From` and `Sleep To`
    - `Value` is not `In Bed`
    - `Value` is not `Awake`
16. **Get Details of Health Samples**: `Duration`.
17. **Calculate Statistics**: `Sum`.
18. **Convert Measurement**: the Sum to `hours`. Rename to `Sleep`.

### 8. Water (optional)

19. **Find Health Samples** where `Type` is `Dietary Water` and `Start Date` is between `Day Start` and `Day End`. Group By `Day`, unit `fl oz`.
20. **Calculate Statistics**: `Sum`. Rename to `Water`.

Skip steps 19 and 20 if nobody logs water in Health. Pulse ignores a missing field.

### 9. Send to Pulse

21. **Get Contents of URL**:
    - URL: `https://drawbox.fasttrackems.com/api/v1/wellness/apple-health`
    - Method: `POST`
    - Headers:
      - `Authorization`: `Bearer ` followed by the `Key` variable. Leave one space after Bearer.
      - `Accept`: `application/json`
    - Request Body: `JSON`, with these Text fields:
      - `day`: `Day`
      - `steps`: `Steps`
      - `exercise_minutes`: `Exercise`
      - `sleep_hours`: `Sleep`
      - `water_oz`: `Water` (only if you built steps 19 and 20)
22. **Get Dictionary Value**: `message` in Contents of URL.
23. **Set Variable**: `Message` to the Dictionary Value.
24. **End Repeat** (already there from step 5).

### 10. Show the result

25. **Show Notification**: `Message`.

## Test it

1. Tap the play button. The first time, iPhone asks to read Steps, Exercise Minutes, Sleep Analysis (and Dietary Water). Tap **Turn On All**, then **Allow**. It also asks to connect to drawbox.fasttrackems.com. Tap **Always Allow**.
2. The notification should read something like "Pulse: Today 8,432 steps, 7.2 h sleep, 41 min exercise. 3 of 8 points so far."
3. Compare with the Health app:
   - **Steps:** the Health app's Steps total for today should match. If Pulse shows about double, the grouping in step 11 isn't merging iPhone and Watch. Tell us and we'll switch to a different method.
   - **Sleep:** Health > Sleep should show about the same time asleep for last night.
   - **Exercise:** it should match the green ring in Fitness.
4. In Pulse, **Wellness > My Wellness** should show the values, with Apple Health as the source. **Wellness > Devices** shows the last sync time.

If the notification shows something else:

| Message | What it means |
| --- | --- |
| `Unauthenticated.` | The key is wrong or was removed. Make a new one and paste it into step 1. |
| `Sign up for Wellness and agree to the rules first.` | That Team Member hasn't joined yet. |
| Nothing, or an error about JSON | The URL or the Accept header is off. Check step 21. |
| `nothing to send` | Health had no data for that day. Check that the Watch is paired and syncing to Health. |

## Run it every night

1. In Shortcuts, tap **Automation**, then **+** (or New Automation).
2. Pick **Time of Day**, then `9:00 PM` and `Daily`.
3. Pick **Run Immediately** and turn off **Notify When Run**.
4. Pick the **Fast Track Pulse** shortcut.

The phone can be locked, but it has to be on. A missed night is caught up the next night, because each run also re-sends yesterday.

## Share it with Team Members

Your own key must not go out with the shortcut. Before sharing, turn the key into a question that each person answers when they install it:

1. Open the shortcut, tap the **i** (details) at the bottom, then **Setup**.
2. Tap **Add Import Question**. Pick the Text action from step 1 and ask: `Paste your sync key from Pulse > Wellness > Devices`.
3. Back in the shortcut, clear your key out of the Text action.
4. Tap **Share**, then **Copy iCloud Link**.
5. In Pulse, go to **Wellness > Wellness settings** and paste the link into **Shortcut link**, then Save.

Team Members now see a **Get the Fast Track Pulse shortcut** button on their Devices page, with the setup steps.

## If you change the shortcut later

Share a new iCloud link and update the setting. People who installed the old version keep it until they install the new one. Their key keeps working, so nobody has to make a new one.
