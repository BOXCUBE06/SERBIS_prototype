// E2E smoke test: boots the real app (real widget tree, real navigation),
// not a widget in isolation like everything under test/. Needs a device,
// emulator, or Chrome — `flutter test` alone (no `integration_test` target)
// cannot run this.
//
// Requires --dart-define=API_BASE_URL, same as a normal run; without it
// main() shows the misconfigured-build screen instead of SerbisApp, which
// this test would then fail against on purpose (see Mobile/README.md).

import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:serbis/main.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('app boots to the login screen', (tester) async {
    await tester.pumpWidget(const SerbisApp());
    await tester.pumpAndSettle();

    expect(find.text('Log in'), findsWidgets);
  });
}
