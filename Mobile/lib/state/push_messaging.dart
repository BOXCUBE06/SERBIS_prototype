library serbis.state.push_messaging;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'app_log.dart';

const _logArea = 'push';

/// Step 1 of push notifications: register the device with FCM and read its
/// token back. Nothing is sent anywhere — there is no token column, no upload
/// call and no send path yet, deliberately.
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
