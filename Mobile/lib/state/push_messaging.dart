library serbis.state.push_messaging;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'api_service.dart';
import 'app_log.dart';

const _logArea = 'push';

/// Registers the device with FCM and reads its token back. Uploading that
/// token to the backend is a separate step — see [registerDeviceToken] —
/// because there is no resident to attach it to yet this early; this call
/// only asks Play services for one.
///
/// Never throws. A device with no Play services, an emulator image without
/// them, or a missing google-services.json all end the same way: no token, one
/// log line, and an app that starts exactly as it did before. Push is an extra
/// channel on top of SMS, not the thing the app runs on.
Future<void> initPushMessaging() async {
  // Android and iOS only. The web build would need its own Firebase options
  // passed to initializeApp(), and desktop has no FCM at all — on both, this
  // would fail at the first call rather than degrade.
  if (kIsWeb ||
      (defaultTargetPlatform != TargetPlatform.android &&
          defaultTargetPlatform != TargetPlatform.iOS)) {
    return;
  }

  try {
    // Reads the platform config the google-services plugin generated from
    // android/app/google-services.json. No options are passed here for that
    // reason: a second copy of the project ids in Dart is a second thing to
    // keep in step with the Firebase console.
    await Firebase.initializeApp();

    // On Android 13+ POST_NOTIFICATIONS is a runtime permission, so this is
    // the call that raises the system prompt. Without it the device still
    // registers and still receives messages — they are simply never shown,
    // which is a silent failure rather than a visible one. On Android 12 and
    // below it resolves as granted with no dialog.
    final settings = await FirebaseMessaging.instance.requestPermission();

    final token = await FirebaseMessaging.instance.getToken();

    AppLog.info(
      _logArea,
      'FCM registration',
      reason: 'permission ${settings.authorizationStatus.name}, '
          'token ${token == null ? 'absent' : 'received'}',
    );

    // Debug builds only, and only for this step. A registration id is an
    // address for this handset — AppLog is copied out of the app by residents
    // and carries no tokens of any kind (see its docblock), so this is printed
    // rather than logged, and never in a release build.
    if (kDebugMode) {
      debugPrint('FCM permission: ${settings.authorizationStatus.name}');
      debugPrint('FCM token: ${token ?? '(none — no Play services?)'}');
    }
  } catch (error) {
    AppLog.error(_logArea, 'FCM registration', error: error,
        reason: 'push unavailable, app continues');
  }
}

/// Android only. There is no GoogleService-Info.plist yet, so iOS has
/// nothing configured to register a token against.
bool get _pushSupported =>
    !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

/// Uploads this device's current FCM token to the backend, tied to whichever
/// resident is signed in on [api]. Called after login and, on app start, when
/// a stored session is restored — both are "this device belongs to this
/// resident now" moments.
///
/// Never throws — a failed upload just leaves this device without push for
/// the resident to notice on their own, same as a failed [initPushMessaging].
Future<void> registerDeviceToken(ApiService api) async {
  if (!_pushSupported) {
    return;
  }

  try {
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null) {
      return;
    }

    await api.registerDeviceToken(token, 'android');
  } catch (error) {
    AppLog.error(_logArea, 'register device token', error: error);
  }
}

bool _listeningForTokenRefresh = false;

/// Starts re-uploading this device's token whenever FCM rotates it. [api] is
/// stable for the life of the app, so only the first successful call
/// actually subscribes — safe to call again from every place
/// [registerDeviceToken] is called from, including a retry if the first call
/// lost the race with [initPushMessaging]'s own Firebase.initializeApp().
///
/// FirebaseMessaging.instance throws synchronously, not just its Future-
/// returning methods, if no app is registered yet — that has to be inside
/// the try too, or this breaks the same "never throws" guarantee every other
/// push entry point holds.
void listenForTokenRefresh(ApiService api) {
  if (!_pushSupported || _listeningForTokenRefresh) {
    return;
  }

  try {
    FirebaseMessaging.instance.onTokenRefresh.listen((token) async {
      try {
        await api.registerDeviceToken(token, 'android');
      } catch (error) {
        AppLog.error(_logArea, 'device token refresh', error: error);
      }
    });
    _listeningForTokenRefresh = true;
  } catch (error) {
    AppLog.error(_logArea, 'listen for token refresh', error: error);
  }
}
