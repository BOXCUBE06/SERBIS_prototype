// M13 (interim): the profile screen used to invent an identity and then lie
// about saving it. Both fictions are on the same field the dispatcher acts on —
// a request is located through the resident's barangay, so a plausible-looking
// default and a "Profile information updated." that wrote nothing are triage
// risks, not cosmetic ones.
//
// These cover the two halves that can regress silently: a missing value must
// never render as a plausible one, and the sheet must not offer an edit the
// backend cannot perform. Restoring either old behaviour fails a test here.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/state/api_service.dart';
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

Future<void> _pumpProfile(
  WidgetTester tester, {
  String? name,
  String? email,
  String? address,
}) async {
  // A phone-shaped viewport: the default 800x600 clips a screen this tall and
  // the offscreen rows never build, so a value under test would go unfound for
  // the wrong reason.
  tester.view.physicalSize = const Size(1080, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: ProfileScreen(
        appState: AppState(_FakeApi()),
        onLogout: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
        initialName: name,
        initialEmail: email,
        initialAddress: address,
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('a profile with no values shows no invented identity',
      (tester) async {
    await _pumpProfile(tester);

    expect(find.text('Juan Delacruz'), findsNothing);
    expect(find.text('Echague, Isabela'), findsNothing);
    // Name, email and barangay all say so rather than guessing.
    expect(find.text('Not on file'), findsNWidgets(3));
  });

  testWidgets('real values render as themselves', (tester) async {
    await _pumpProfile(
      tester,
      name: 'Maria Santos',
      email: 'maria@example.com',
      address: 'San Fabian',
    );

    expect(find.text('Maria Santos'), findsOneWidget);
    expect(find.text('maria@example.com'), findsOneWidget);
    expect(find.text('San Fabian'), findsOneWidget);
    expect(find.text('Not on file'), findsNothing);
  });

  testWidgets('the details sheet is read-only and offers no save',
      (tester) async {
    await _pumpProfile(
      tester,
      name: 'Maria Santos',
      email: 'maria@example.com',
      address: 'San Fabian',
    );

    await tester.tap(find.text('Account details'));
    await tester.pumpAndSettle();

    // Nothing editable, nothing that claims a write.
    expect(find.byType(TextField), findsNothing);
    expect(find.text('Save changes'), findsNothing);
    expect(find.text('Contact MDRRMO to update your details.'), findsOneWidget);

    // The values are still shown — read-only, not hidden.
    expect(find.text('San Fabian'), findsWidgets);
  });

  testWidgets('the sheet marks a missing barangay rather than blanking it',
      (tester) async {
    // Only the barangay is missing, so it is the only thing that can produce a
    // "Not on file" — twice, once on the card and once in the sheet. A default
    // creeping back in for this field alone drops the count to zero.
    await _pumpProfile(
      tester,
      name: 'Maria Santos',
      email: 'maria@example.com',
    );

    await tester.tap(find.text('Account details'));
    await tester.pumpAndSettle();

    expect(find.text('Barangay'), findsOneWidget);
    expect(find.text('Not on file'), findsNWidgets(2));
  });

  testWidgets('the avatar carries no edit affordance without a picker',
      (tester) async {
    // It used to open a snackbar reading "Photo picker would open here."
    await _pumpProfile(tester, name: 'Maria Santos');

    expect(find.byIcon(Icons.edit_rounded), findsNothing);
  });

  testWidgets('the profile offers no notification consent it cannot honour',
      (tester) async {
    // M25. Both switches defaulted to on and wrote to local bools that reset on
    // rebuild, while SmsController kept blasting every Active resident in the
    // selected barangays — so switching SMS alerts off told a resident they had
    // opted out of a paid message they went on receiving. There is no Switch
    // anywhere else on this screen, so byType is the whole guard.
    await _pumpProfile(tester, name: 'Maria Santos');

    expect(find.byType(Switch), findsNothing);
    expect(find.text('SMS alerts'), findsNothing);
    expect(find.text('Push notifications'), findsNothing);
    expect(find.text('Notifications'), findsNothing);

    // The section it lived in is gone, not emptied — Account settings is the
    // first section under the card now.
    expect(find.text('Account settings'), findsOneWidget);
  });
}
