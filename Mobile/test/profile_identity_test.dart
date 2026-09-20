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
import 'package:serbis/state/account_store.dart';
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

  @override
  Future<List<int>?> fetchProfilePhoto(String residentId) async => null;
}

/// The screen takes the whole resident now, so the old `name` string is split
/// back into the two columns it comes from.
AppUser _userFrom(String? name, String? phone, String? address) {
  final parts = (name ?? '').trim().split(RegExp(r'\s+'))
    ..removeWhere((part) => part.isEmpty);

  return AppUser(
    id: '1',
    firstName: parts.isEmpty ? '' : parts.first,
    lastName: parts.length > 1 ? parts.sublist(1).join(' ') : '',
    phone: phone ?? '',
    address: address ?? '',
  );
}

Future<void> _pumpProfile(
  WidgetTester tester, {
  String? name,
  String? phone,
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
        userStore: UserStore(_FakeApi()),
        user: _userFrom(name, phone, address),
        onUserChanged: (_) {},
        onLogout: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
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
    // Name, number and barangay all say so rather than guessing.
    expect(find.text('Not on file'), findsNWidgets(3));
  });

  testWidgets('real values render as themselves', (tester) async {
    await _pumpProfile(
      tester,
      name: 'Maria Santos',
      phone: '+639171234567',
      address: 'San Fabian',
    );

    expect(find.text('Maria Santos'), findsOneWidget);
    // Stored as E.164, shown the way a person writes it.
    expect(find.text('09171234567'), findsOneWidget);
    expect(find.text('San Fabian'), findsOneWidget);
    expect(find.text('Not on file'), findsNothing);
  });

  testWidgets('the details sheet edits the name and street but never the barangay',
      (tester) async {
    // This sheet was read-only while PATCH /me did not exist — offering an edit
    // the backend could not perform is what the original bug was. The endpoint
    // shipped, so the edit is real now; what must not come back is a writable
    // barangay, which is the field a request is dispatched on.
    await _pumpProfile(
      tester,
      name: 'Maria Santos',
      phone: '+639171234567',
      address: 'San Fabian',
    );

    await tester.tap(find.text('Account details'));
    await tester.pumpAndSettle();

    // First, middle, last, and the purok picker — and nothing for the barangay
    // or the number, which moves through its own two-step flow.
    expect(find.byType(TextField), findsNWidgets(3));
    expect(find.byType(DropdownButton<String>), findsOneWidget);
    expect(find.text('Save changes'), findsOneWidget);

    // The barangay is still shown, still read-only, and now says why.
    expect(find.text('San Fabian'), findsWidgets);
    expect(
      find.text(
        'Contact MDRRMO to change your barangay — it is what your requests are dispatched on.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('the sheet marks a missing barangay rather than blanking it',
      (tester) async {
    // Only the barangay is missing, so it is the only thing that can produce a
    // "Not on file" — twice, once on the card and once in the sheet. A default
    // creeping back in for this field alone drops the count to zero.
    await _pumpProfile(
      tester,
      name: 'Maria Santos',
      phone: '+639171234567',
    );

    await tester.tap(find.text('Account details'));
    await tester.pumpAndSettle();

    expect(find.text('Barangay'), findsOneWidget);
    expect(find.text('Not on file'), findsNWidgets(2));
  });

  testWidgets('the avatar edit badge opens a real photo sheet', (tester) async {
    // M32 removed this badge because it only opened a snackbar reading "Photo
    // picker would open here." There is an upload behind it now, so the badge
    // is back — and it must lead somewhere that acts.
    await _pumpProfile(tester, name: 'Maria Santos');

    expect(find.byIcon(Icons.edit_rounded), findsOneWidget);

    await tester.tap(find.byIcon(Icons.edit_rounded));
    await tester.pumpAndSettle();

    expect(find.text('Choose a photo'), findsOneWidget);
    expect(find.text('Photo picker would open here.'), findsNothing);
  });

  testWidgets('remove is offered only when there is a photo to remove',
      (tester) async {
    await _pumpProfile(tester, name: 'Maria Santos');

    await tester.tap(find.byIcon(Icons.edit_rounded));
    await tester.pumpAndSettle();

    expect(find.text('Remove photo'), findsNothing);
  });

  testWidgets('a named resident with no photo gets their initials',
      (tester) async {
    await _pumpProfile(tester, name: 'Maria Santos');

    expect(find.text('MS'), findsOneWidget);
  });

  testWidgets('an empty profile gets the neutral icon, not a monogram',
      (tester) async {
    // The whole point of the identity work: nothing invented in the circle.
    // Scoped by size — the header carries the same icon at 17.
    await _pumpProfile(tester);

    expect(
      find.byWidgetPredicate((w) =>
          w is Icon && w.icon == Icons.person_outline_rounded && w.size == 36),
      findsOneWidget,
    );
  });

  testWidgets('the profile offers no notification consent it cannot honour',
      (tester) async {
    // M25. Two switches used to sit here, both defaulting to on and writing to
    // local bools that reset on rebuild, while SmsController kept blasting
    // every Active resident regardless — so switching SMS alerts off told a
    // resident they had opted out of a paid message they went on receiving.
    //
    // The SMS half now has tbl_residents.sms_opt_in behind it and the blast
    // query filters on it, so that switch is back and is exercised in
    // sms_preference_test.dart. This test is now the guard on the *other*
    // half: exactly one switch, and nothing offering push.
    await _pumpProfile(tester, name: 'Maria Santos');

    expect(find.byType(Switch), findsOneWidget);
    expect(find.text('MDRRMO text alerts'), findsOneWidget);

    // Still nothing behind push — no FCM, no firebase_messaging anywhere in
    // the app — so a control for it would be the original bug again.
    expect(find.text('Push notifications'), findsNothing);
    expect(find.text('Notifications'), findsNothing);

    // The section the pair lived in is still gone, not emptied — the surviving
    // switch is a row inside Account settings, not a Notifications section.
    expect(find.text('Account settings'), findsOneWidget);
  });
}
