// Account types on the phone: who is signed in (individual, organization or a
// barangay hall) shows at the top of Home, and an organization that registered
// itself sees an "Awaiting MDRRMO approval" screen instead of the service list
// until an admin activates it.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/awaiting_approval_screen.dart';
import 'package:serbis/screens/dashboard_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async => <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async =>
      <Map<String, dynamic>>[];
}

Map<String, dynamic> _json({
  String? type,
  String? organization,
  String? status,
  String barangay = 'San Miguel',
}) =>
    {
      'resident_id': 7,
      'first_name': 'Ian',
      'last_name': 'Uy',
      'email_address': 'ian@example.com',
      'phone_number': '09171234567',
      'barangay_id': 2,
      'barangay': {'barangay_name': barangay},
      if (type != null) 'account_type': type,
      if (organization != null) 'organization_name': organization,
      if (status != null) 'status': status,
    };

Future<void> _pumpHome(WidgetTester tester, AppUser user) async {
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: HomeScreen(
        appState: AppState(_FakeApi()),
        user: user,
        onOpenTrack: () {},
        onOpenLibrary: () {},
        onOpenProfile: () {},
        onOpenNotifications: () {},
        onOpenServices: () {},
        onOpenService: (_) {},
      ),
    ),
  ));
}

void main() {
  group('reading the account from the server', () {
    test('an organization carries its type, name and status', () {
      final user = AppUser.fromJson(
        _json(type: 'organization', organization: 'Isabela State University', status: 'Inactive'),
      );

      expect(user.isOrganization, isTrue);
      expect(user.accountName, 'Isabela State University');
      expect(user.accountTypeKey, 'account.organization');
      expect(user.isAwaitingApproval, isTrue);
    });

    test('a barangay account is named after its barangay', () {
      final user = AppUser.fromJson(_json(type: 'barangay', status: 'Active'));

      expect(user.isBarangay, isTrue);
      expect(user.accountName, 'Barangay San Miguel');
      expect(user.accountTypeKey, 'account.barangay');
      expect(user.isAwaitingApproval, isFalse);
    });

    test('a payload with no account fields is an individual', () {
      final user = AppUser.fromJson(_json());

      expect(user.accountType, 'head_of_family');
      expect(user.accountName, 'Ian Uy');
      expect(user.accountTypeKey, 'account.individual');
      expect(user.isAwaitingApproval, isFalse);
    });

    test('only an organization can be awaiting approval', () {
      bool waiting(String type, String status) =>
          AppUser.fromJson(_json(type: type, organization: 'ISU', status: status))
              .isAwaitingApproval;

      expect(waiting('organization', 'Inactive'), isTrue);
      expect(waiting('organization', 'Active'), isFalse);
      expect(waiting('organization', 'Deactivated'), isFalse);
      // An individual is Inactive until activated too, and can file meanwhile.
      expect(waiting('head_of_family', 'Inactive'), isFalse);
      expect(waiting('barangay', 'Inactive'), isFalse);
    });

    test('copyWith keeps the account fields', () {
      final user = AppUser.fromJson(
        _json(type: 'organization', organization: 'ISU', status: 'Inactive'),
      ).copyWith(phone: '09170000000');

      expect(user.organizationName, 'ISU');
      expect(user.isAwaitingApproval, isTrue);
    });
  });

  group('the top of Home', () {
    testWidgets('names an individual and their type', (tester) async {
      await _pumpHome(tester, AppUser.fromJson(_json(status: 'Active')));

      expect(find.text('INDIVIDUAL'), findsOneWidget);
      expect(find.text('Ian Uy'), findsOneWidget);
    });

    testWidgets('names an organization, with its contact person underneath', (tester) async {
      await _pumpHome(
        tester,
        AppUser.fromJson(_json(type: 'organization', organization: 'Isabela State University', status: 'Active')),
      );

      expect(find.text('ORGANIZATION'), findsOneWidget);
      expect(find.text('Isabela State University'), findsOneWidget);
      expect(find.text('Ian Uy'), findsOneWidget);
      expect(find.text('Awaiting MDRRMO approval'), findsNothing);
    });

    testWidgets('a pending organization is told why and is not offered the help tiles', (tester) async {
      await _pumpHome(
        tester,
        AppUser.fromJson(_json(type: 'organization', organization: 'ISU', status: 'Inactive')),
      );

      expect(find.text('Awaiting MDRRMO approval'), findsOneWidget);
      expect(find.text('Borrow Equipment'), findsNothing);
    });

    testWidgets('an active user still gets the help tiles', (tester) async {
      await _pumpHome(tester, AppUser.fromJson(_json(status: 'Active')));

      expect(find.text('Borrow Equipment'), findsOneWidget);
    });
  });

  group('the awaiting approval screen', () {
    Future<void> pump(WidgetTester tester, Future<bool> Function() check) async {
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: AwaitingApprovalScreen(
            user: AppUser.fromJson(_json(type: 'organization', organization: 'ISU', status: 'Inactive')),
            filipino: false,
            onCheckAgain: check,
            onOpenNotifications: () {},
            onOpenProfile: () {},
          ),
        ),
      ));
    }

    testWidgets('says the organization is waiting and names it', (tester) async {
      await pump(tester, () async => true);

      expect(find.text('Awaiting MDRRMO approval'), findsOneWidget);
      expect(find.text('ISU'), findsOneWidget);
      expect(find.text('Check again'), findsOneWidget);
    });

    testWidgets('Check again reports it is still waiting', (tester) async {
      var calls = 0;
      await pump(tester, () async {
        calls++;
        return true;
      });

      await tester.tap(find.text('Check again'));
      await tester.pumpAndSettle();

      expect(calls, 1);
      expect(find.text('Still waiting for approval.'), findsOneWidget);
    });

    testWidgets('a failed check says so instead of throwing', (tester) async {
      await pump(tester, () async => throw const ApiException('offline'));

      await tester.tap(find.text('Check again'));
      await tester.pumpAndSettle();

      expect(find.text('Could not check right now. Try again.'), findsOneWidget);
    });
  });
}
