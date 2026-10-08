# Capturing the mobile shots (MOB-01 to MOB-47) by hand

The 47 mobile screenshots are taken on the Android emulator, signed in to a local API that runs against the capture database `serbis_capture`. Playwright could not drive the Flutter web build: taps on the canvas never reached the app. The shot list (`../../SHOT-LIST.md`) gives the screen, the state and the boxes for each ID.

Nothing here touches `serbis_test_db` or production. SMS is faked locally, so no text is sent and nothing is billed.

## 1. Before you start

You need:

- XAMPP MySQL running. Check with `C:\xampp\mysql\bin\mysqladmin.exe -uroot ping`. If it is down, run `C:\xampp\mysql_start.bat`.
- The capture database already seeded. If it is not, follow the comment at the top of `seed-capture.php` (DemoSeeder, then InfoMaterialSeeder, then `seed-capture.php` with no arguments).
- The sample files in `samples/`. If they are missing, run `node docs/user-manuals/tools/capture/setup.js` from the repo root.
- The emulator `Pixel8_API36_PlayStore`, Flutter at `C:\flutter`, and the Android SDK at `C:\Users\Jamby Villarta\AppData\Local\Android\Sdk`.

In each new Git Bash window, put the tools on the PATH:

```
export PATH="/c/flutter/bin:/c/Users/Jamby Villarta/AppData/Local/Android/Sdk/platform-tools:$PATH"
```

## 2. Start the API on the capture database

From `Backend/SERBIS-Backend`, in its own window:

```
DB_DATABASE=serbis_capture php artisan serve --host=127.0.0.1 --port=8000
```

Check that it uses the capture database. This must print `serbis_capture local`:

```
DB_DATABASE=serbis_capture php artisan tinker --execute="echo config('database.connections.mysql.database'), ' ', app()->environment();"
```

The local `.env` already sets what the capture relies on:

- `SERBIS_SMS_FAKE=true`: every text is faked; nothing is sent.
- `SERBIS_OTP_BYPASS_CODE=555555`: every code screen (registration, login, forgot password, change number) accepts **555555**. It works only while `APP_ENV=local`; the server refuses to start with it set anywhere else.

Leave this window open. You will stop it once, for MOB-39.

## 3. Start the emulator and the app

1. Start the emulator:
   ```
   "/c/Users/Jamby Villarta/AppData/Local/Android/Sdk/emulator/emulator.exe" -avd Pixel8_API36_PlayStore -no-snapshot-load
   ```
   It takes about a minute. If no window appears, it opened off-screen: press Alt+Space, choose Move, then use the arrow keys.
2. Check it is connected: `adb devices` lists `emulator-5554`.
3. Let the emulator reach the API on your PC as `localhost:8000`:
   ```
   adb reverse tcp:8000 tcp:8000
   ```
4. Set the emulator to English, and the app to English on first launch.
5. From `Mobile`, run the app:
   ```
   flutter run -d emulator-5554 --dart-define=API_BASE_URL=http://localhost:8000/api
   ```
   The first build takes about two minutes. Afterwards, run `git checkout -- Mobile/pubspec.lock`, because `flutter run` rewrites it.

## 4. Put the sample files on the emulator

Use these files for every upload. Never use a real ID or a real photo.

| File | Use it for |
|---|---|
| `samples/sample-id.png` | Valid ID (ambulance step 4, MOB-21; and any form that asks for an ID) |
| `samples/site-photo.png` | Site photo (Road Clearing, MOB-12) |
| `samples/request-letter.pdf` | Request letter (DRRM Trainings, MOB-14; Certification, MOB-15) |
| `samples/profile-photo.png` | Profile photo (MOB-46) |

From the repo root:

```
adb push docs/user-manuals/tools/capture/samples/sample-id.png /sdcard/Pictures/
adb push docs/user-manuals/tools/capture/samples/site-photo.png /sdcard/Pictures/
adb push docs/user-manuals/tools/capture/samples/profile-photo.png /sdcard/Pictures/
adb push docs/user-manuals/tools/capture/samples/request-letter.pdf /sdcard/Download/
adb shell am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Pictures/sample-id.png
adb shell am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Pictures/site-photo.png
adb shell am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Pictures/profile-photo.png
```

## 5. How to save each screenshot

Save every image as `MOB-xx.png` in `%USERPROFILE%\Downloads\SERBIS-screenshots\mobile\`, then copy it to `docs/user-manuals/screenshots/raw/`. With the screen in the right state, run this from the repo root (replace `MOB-05`):

```
ID=MOB-05; OUT="$USERPROFILE/Downloads/SERBIS-screenshots/mobile"; mkdir -p "$OUT"
adb exec-out screencap -p > "$OUT/$ID.png" && cp "$OUT/$ID.png" docs/user-manuals/screenshots/raw/
```

The images come out at the emulator's full resolution (1080 × 2400 on a Pixel 8). That is fine: the build scales them to the page.

Before each shot, close the keyboard unless the shot needs it, and wait until loading spinners are gone.

### Helper commands

These are in this folder. Run them from a Command Prompt here, or with their full path. What is still missing, and in what order to capture it, is in `MOBILE-TODO.md`.

| Command | What it does |
|---|---|
| `shot MOB-12` | Saves the emulator screen as `MOB-12.png` in `Downloads\SERBIS-screenshots\mobile\` and in `screenshots/raw/`. It refuses to replace an existing file; add `--force` to replace it. |
| `login-as resident`, `login-as hall`, `login-as pending-org`, `login-as org` | Prints that account's mobile number and password, and the code 555555. |
| `hide-ambulance` / `restore-audience` | MOB-42. Stops offering Ambulance to organization accounts, then offers it again. Run `restore-audience` straight after the shot, so ADM-40 stays true. |
| `airplane-on` / `airplane-off` | Offline shots (MOB-38). Turns airplane mode on and also removes the `adb reverse` tunnel, which would otherwise keep the API reachable. `airplane-off` undoes both. |
| `api-stop` / `api-start` | MOB-39. Stops the API on 127.0.0.1:8000. `api-start` starts it again on `serbis_capture` in its own window. |

The API must listen on **127.0.0.1**, not `localhost`: `adb reverse` connects to 127.0.0.1, and `--host=localhost` can bind only to `::1`. Then the app shows "Couldn't load barangays". The command in section 2 should read `--host=127.0.0.1`.

## 6. Accounts

All of these are on `serbis_capture` only. Every resident password is `Passw0rd!123`.

| Account | Mobile number | Used for |
|---|---|---|
| Head of the Family | the one you register in step 7 (for example 0917 555 0101) | most shots |
| Barangay hall, San Fabian (Rodolfo Agbayani) | 0917 200 0000 | MOB-14 |
| Activated organization (San Fabian Elementary School PTA) | 0927 211 1423 | MOB-42 |
| Organization awaiting approval (San Fabian Rural Health Volunteers Association) | 0975 225 9987 | MOB-08. Do not activate it: ADM-34 needs it pending. |

The admin panel login, if you need it, is `admin` / `password123`, code 555555. The text blast code is `123456`.

## 7. Shots in capture order

Each step says what state to set up; the boxes and captions are in `SHOT-LIST.md`.

### A. Signed out

1. **MOB-05** Log in screen. Open the app signed out.
   - **MOB-40** Emergency hotlines page. Tap **Emergency hotlines** on the log in screen, take the shot, then go back.
2. **MOB-01** Register step 1. Tap **Register**. Choose **Head of the Family**, type a first and last name and a new mobile number nobody has used (for example `09175550101`). Take the shot with the fields filled.
3. **MOB-02** Register step 2. Tap **Next**. Choose barangay **San Fabian** (the seeded data and the text blast use it). Type a street or purok.
4. **MOB-03** Register step 3. Tap **Next**. Type `Passw0rd!123` in both password fields. Take the shot with the review list showing.
5. **MOB-04** "Check your messages". Tap **Create account**. Take the shot while the resend countdown is still running. Then type **555555** and tap **Verify**. You are signed in.

### B. Give the new account history

6. In a Git Bash window at the repo root, using the number in +63 form:
   ```
   DB_DATABASE=serbis_capture php docs/user-manuals/tools/capture/seed-capture.php --resident=+639175550101
   ```
   This sets the account to Active, which text blasts need, and adds:
   - a Resolved Sandbagging request
   - a Cancelled request
   - a Booked Power Line Repair request
   - a Released Wheel Chair with a handover photo
7. Send a text blast to San Fabian, so the account gets an advisory for MOB-10. Use either of these:
   - In the admin panel at `http://localhost:3000`: **Text Blast (SMS)**, then barangay **San Fabian**, a short message, **Send blast**, code `123456`, **Send blast**.
   - Run `node docs/user-manuals/tools/capture/admin.js ADM-37 --force --send-blast`. This also retakes ADM-37.
8. In the app, pull down to refresh on Home and Track.

### C. Signed in as the Head of the Family

9. **MOB-09** Home with one open request. The Booked request from step 6 is the open one.
   - **MOB-41** Emergency bar. Scroll Home to the top so **Emergency? Call the hotline** shows. Do not tap it: it opens the phone dialer.
10. **MOB-10** Notifications. Tap the bell. You need both "New" and "Earlier" sections and the advisory.
11. **MOB-11** Services tab.
12. **MOB-12** Road Clearing. Open it and leave two required fields empty. Tap **Send request** once so the error summary shows. Add the site photo (`site-photo.png`) before the shot.
13. **MOB-13** Relief Goods Distribution, step 1 of 3. Open it from Services. Do not send it.
14. **MOB-15** MDRRMO Certification form. Type a purpose. You may attach `request-letter.pdf`.
15. **MOB-16** Others form. Type a description and pick a preferred date.
16. **MOB-17** Request sent sheet. Send the Others form, then take the shot of the sheet showing the reference number.
17. **MOB-18** Ambulance tab, step 1 Patient. Type a patient name and age.
18. **MOB-19** Step 2 Trip. Tap **Next**. Fill **From** and choose a hospital in **To**.
19. **MOB-20** Step 3 Condition. Tap **Next**. Type the condition and one relative.
20. **MOB-21** Step 4 Schedule and ID. Tap **Next**. Choose **later**, set a date and time, and attach `sample-id.png` as the valid ID. The date and time pickers are native; close them before the shot.
21. **MOB-22** Step 5 Review. Tap **Next**. Do not send.
22. **MOB-23** "Leave without sending?". Tap back or another tab while the form has answers. Take the shot, then tap **Discard answers**.
23. **MOB-24** Track tab with open and past requests.
   - **MOB-43** Borrowed items. Scroll Track to **Borrowed items**, which lists the Released Wheel Chair from step 6.
24. **MOB-25** An open request opened in Track. Open the Others request from step 16, which is Under review.
25. **MOB-26** "Cancel this request?". Tap **Cancel request**. Take the shot, then tap **Keep request**.
26. **MOB-27** Borrow tab, catalogue.
27. **MOB-28** Borrow sheet for **Wheel Chair**. Set quantity, pickup or delivery, and a purpose. Do not send.
28. **MOB-29** Sheet for an item not listed. Tap the "not listed" row, type an item name and a quantity. Do not send.
29. **MOB-30** Borrow, My requests. The Released Wheel Chair from step 6 shows its return date and handover photo.
30. **MOB-31** Safety library. First tap the download button on one guide so it shows **Saved**.
31. **MOB-32** Article reader. Open that guide.
32. **MOB-33** Emergency hotlines page.
33. **MOB-34** Profile. The saved guide makes "Offline materials" show a count.
   - **MOB-44** Offline materials. Tap **Offline materials**. **Downloaded documents** lists the guide saved in step 30. Go back.
   - **MOB-47** Contact MDRRMO. Scroll the profile until the **Contact MDRRMO** row shows. Do not tap it: it opens the dialer.
   - **MOB-46** Profile photo sheet. Tap the photo or initials at the top, tap **Choose a photo** and pick `profile-photo.png`. Tap the photo again: the **Profile photo** sheet now has **Choose a photo** and **Remove photo**. Take the shot, then close the sheet.
34. **MOB-35** Edit my details sheet.
35. **MOB-36** Change mobile number sheet. Type an unused number (for example `09175550199`) and the current password. Take the shot before tapping **Send code**.
   - **MOB-45** Code step. Tap **Send code**. On **Enter the code**, type **555555** but do not tap **Confirm new number**, or the account's login number changes. Take the shot, then close the sheet.
36. **MOB-37** Language sheet. Leave the app in English afterwards.
37. **MOB-38** Track while offline. Open Track once online, then turn on **airplane mode** (pull down the quick settings, or in the emulator's Extended controls set Cellular to "None" and Wi-Fi off). Wait for the offline banner, take the shot, then turn airplane mode off.
38. **MOB-39** Services when the list cannot load. Stop the API (Ctrl+C in its window). Do not use airplane mode here, or the offline banner shows instead. Open Services and pull to refresh until the error and **Try again** show. Take the shot, then start the API again (step 2).

### D. Signed out again

39. **MOB-06** "Finish signing in". Log out from Profile. Log in with the Head of the Family's number and password. Take the shot on the code screen, then type **555555** and tap **Verify**. Log out again.
40. **MOB-07** Forgot password, code and new password step. On the login screen tap **Forgot password?** and type the number. On the next step type **555555** and a new password. Take the shot before saving. If you do save, the password changes; note the new one.

### E. Other account types

- **MOB-42** "Not available" tab. Run `DB_DATABASE=serbis_capture php docs/user-manuals/tools/capture/seed-capture.php --hide-ambulance-for-organizations`. Log in as the activated organization (0927 211 1423, code 555555) and tap **Ambulance**. Take the shot, log out, then run the same command with `--restore-audience` so ADM-40 stays true.

41. **MOB-14** DRRM Trainings and Seminars form. Log in as the barangay hall (0917 200 0000, code 555555). Open DRRM Trainings and Seminars, pick a preferred date and attach `request-letter.pdf`. Do not send. Log out.
42. **MOB-08** Awaiting approval. Log in as the pending organization (0975 225 9987, code 555555). Take the shot of the awaiting approval screen with **Check again**. Log out.

## 8. When you are done

- Count the files: `Downloads\SERBIS-screenshots\mobile\` should hold 47 files, MOB-01.png to MOB-47.png.
- Stop the API window and the emulator.
- Run `git checkout -- Mobile/pubspec.lock` if `flutter run` changed it.
- Run `adb reverse --remove-all`.
- Requests and loans you sent during the capture stay in `serbis_capture`. That is fine. To start over, re-seed the capture database as in section 1, then repeat from step 7.A.
