# SERBIS Mobile

Flutter client for residents of Echague, Isabela. Talks to the Laravel API in
`Backend/SERBIS-Backend`.

## The API address is a build-time flag

There is no default. The app reads its API root from `API_BASE_URL`, passed with
`--dart-define` when the build is produced:

```
--dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

The value is the API root **including `/api`**, with no trailing slash.

Forget the flag and the app does not start: it shows a red "This build has no
API address" screen naming the missing define. That is deliberate. The default
used to be `http://127.0.0.1:8000/api`, which on a handset is the handset
itself — a build made without the flag installed and launched normally, then
failed every call with a socket error that looks like a dead network. The
failure now belongs to whoever made the build instead of whoever installed it.

### Which address

| Where you are running it | Value |
| --- | --- |
| Android emulator | `http://10.0.2.2:8000/api` (`10.0.2.2` is the host machine) |
| iOS simulator | `http://localhost:8000/api` |
| Physical handset on the same Wi-Fi | `http://<your-machine-LAN-IP>:8000/api` |
| `flutter run -d chrome` | `http://localhost:8000/api` |
| Anything shipped to a resident | the deployed `https://…/api` |

Start the API first, bound so the device can reach it:

```
cd Backend/SERBIS-Backend
php artisan serve --host=0.0.0.0 --port=8000
```

## Running

```
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

Release build:

```
flutter build apk --release --dart-define=API_BASE_URL=https://<api-host>/api
```

A release build must use `https://`. Android 9+ and iOS App Transport Security
both block cleartext by default, and this app does not turn that off for
release — see below.

## Cleartext HTTP

The main manifest sets `android:usesCleartextTraffic="false"`. Plain HTTP is
re-enabled for development only, by
`android/app/src/debug/res/xml/network_security_config.xml`, which lives under
`src/debug/` and is therefore never merged into a profile or release build. It
exempts `10.0.2.2`, `localhost` and `127.0.0.1` and nothing else — add your
machine's LAN IP there if you test from a physical handset.

`flutter run --profile` and `--release` get no exemption, so pointing either at
an `http://` URL will fail. That is the intended signal, not a bug.

On iOS, loopback is exempt from ATS, so the simulator works against
`http://localhost:8000` with no change. Reaching a LAN IP from a physical iOS
device needs `NSAllowsLocalNetworking` in `ios/Runner/Info.plist`; add it
locally if you need it and do not commit it.

## Tests

```
flutter test
```

Tests do not pass `API_BASE_URL` and do not need it — nothing under `test/`
performs a real request.
