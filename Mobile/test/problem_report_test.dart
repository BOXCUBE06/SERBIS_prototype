// M17: the app had no logging of any kind, so "it didn't work" was the entire
// bug report and there was no artefact anywhere to diagnose from. AppLog records
// failures; this is the surface that gets them off the device.
//
// The unit rules live in app_log_test.dart. These cover the wiring — that the
// row reflects the buffer, that the sheet shows the resident what they are about
// to send, and that copying puts it on the clipboard.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/app_log.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];
}

Future<void> _pumpProfile(WidgetTester tester, {AppState? appState}) async {
  // Phone-shaped: the default 800x600 clips this screen and the Report row,
  // which sits near the bottom, would never build.
  tester.view.physicalSize = const Size(1080, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: ProfileScreen(
        appState: appState ?? AppState(_FakeApi()),
        onLogout: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

Future<void> _openReport(WidgetTester tester) async {
  final row = find.text('Report a problem');
  await tester.scrollUntilVisible(row, 220);
  // scrollUntilVisible stops as soon as the finder matches, and a ListView
  // builds a screenful past the viewport — so the row can be found while still
  // below the fold and the tap lands on nothing.
  await tester.ensureVisible(row);
  await tester.tap(row);
  await tester.pumpAndSettle();
}

void main() {
  setUp(AppLog.clear);
  tearDown(AppLog.clear);

  testWidgets('with an empty buffer the sheet says there is nothing to send',
      (tester) async {
    await _pumpProfile(tester);

    expect(find.text('Nothing recorded yet'), findsOneWidget);

    await _openReport(tester);

    expect(
      find.textContaining('Nothing has failed since you opened the app'),
      findsOneWidget,
    );
    // No copy affordance over an empty report: it would put a header and
    // nothing else on the clipboard and read as a working bug report.
    expect(find.text('Copy report'), findsNothing);
  });

  testWidgets('recorded failures are counted on the row and listed in the sheet',
      (tester) async {
    AppLog.error('api', 'GET /service-requests',
        status: 500, reason: 'The server had a problem.');
    AppLog.error('requests', 'submit request', reason: 'rolled back, not filed');

    await _pumpProfile(tester);

    expect(find.text('2 events recorded'), findsOneWidget);

    await _openReport(tester);

    // Newest first on screen: the resident is looking for what just failed.
    expect(find.textContaining('submit request'), findsOneWidget);
    expect(find.textContaining('GET /service-requests'), findsOneWidget);
  });

  testWidgets('copying puts the exported report on the clipboard',
      (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied = (call.arguments as Map)['text'] as String?;
        }
        return null;
      },
    );
    addTearDown(() => tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, null));

    AppLog.error('api', 'POST /service-requests',
        status: 422, reason: 'No available vehicles at this time.');

    await _pumpProfile(tester);
    await _openReport(tester);
    await tester.tap(find.text('Copy report'));
    await tester.pumpAndSettle();

    expect(copied, isNotNull);
    expect(copied, contains('SERBIS problem report'));
    expect(copied, contains('POST /service-requests'));
    expect(copied, contains('status 422'));

    // The sheet closes and says so, or a resident taps Copy repeatedly with no
    // sign it worked.
    expect(find.text('Report copied.'), findsOneWidget);
  });

  testWidgets('logging out clears the buffer with the cached rows',
      (tester) async {
    // Without this the call below reaches the real SharedPreferences channel,
    // gets no reply, and the test sits there until the 10-minute timeout rather
    // than failing.
    SharedPreferences.setMockInitialValues(<String, Object>{});

    AppLog.error('requests', 'cancel request 31', reason: 'rolled back');
    expect(AppLog.isEmpty, isFalse);

    // The same call the logout handler in main.dart makes. The log lines name
    // this resident's own request ids, so they go out with the rows.
    await AppState(_FakeApi()).clearRequestCache();

    expect(AppLog.isEmpty, isTrue);
  });
}
