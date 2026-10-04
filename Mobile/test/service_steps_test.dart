// The service form page: one shared title header, a pinned footer, inline
// errors, and the relief form as three steps (Household; Assistance and
// delivery; ID and review) in the pattern the ambulance flow uses.

import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/screens/service_drafts.dart';
import 'package:serbis/screens/service_form_page.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/ambulance_steps.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/service_widgets.dart';
import 'package:serbis/widgets/shared_widgets.dart';

class _Picker extends FilePicker {
  @override
  Future<FilePickerResult?> pickFiles({
    String? dialogTitle,
    String? initialDirectory,
    FileType type = FileType.any,
    List<String>? allowedExtensions,
    Function(FilePickerStatus)? onFileLoading,
    bool allowCompression = true,
    int compressionQuality = 30,
    bool allowMultiple = false,
    bool withData = false,
    bool withReadStream = false,
    bool lockParentWindow = false,
    bool readSequential = false,
  }) async =>
      FilePickerResult([PlatformFile(name: 'id.jpg', size: 4, bytes: Uint8List.fromList([1, 2, 3, 4]))]);
}

class _Api extends ApiService {
  int submits = 0;
  String? fulfillmentMethod;
  String? deliveryAddress;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async => [];

  @override
  Future<Map<String, dynamic>> submitRequest({
    required int? serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    String? landmark,
    String? fulfillmentMethod,
    String? deliveryAddress,
    DateTime? scheduledAt,
    AmbulanceIntake? intake,
    DateTime? preferredDate,
    List<int>? letterBytes,
    String? letterFileName,
  }) async {
    submits++;
    this.fulfillmentMethod = fulfillmentMethod;
    this.deliveryAddress = deliveryAddress;
    return {'request_id': 77, 'service_id': serviceId, 'description': description, 'status': 'pending'};
  }
}

const _user = AppUser(
  id: '31',
  firstName: 'Maria',
  lastName: 'Dela Cruz',
  email: '',
  phone: '09171234567',
  address: 'San Fabian',
);

const _relief = ServiceCatalogItem(id: 7, name: 'Relief goods distribution', code: 'relief-goods-distribution');
const _road = ServiceCatalogItem(id: 5, name: 'Road clearing', code: 'road-clearing');

/// Pushes the page over a home route, so Back has somewhere to land.
Future<_Api> _pump(
  WidgetTester tester,
  ServiceCatalogItem service, {
  bool filipino = false,
  Size size = const Size(1080, 3200),
  double ratio = 3,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = ratio;
  addTearDown(tester.view.reset);
  FilePicker.platform = _Picker();

  final api = _Api();
  final state = AppState(api);
  if (filipino) state.setLanguage(AppLanguage.filipino);
  final drafts = ServiceDrafts(_user);
  addTearDown(drafts.dispose);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Builder(
      builder: (context) => Scaffold(
        body: Center(
          child: TextButton(
            onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
              builder: (_) => ServiceFormPage(
                appState: state,
                user: _user,
                service: service,
                drafts: drafts,
                onSubmitted: () {},
                onOpenNotifications: () {},
              ),
            )),
            child: const Text('open'),
          ),
        ),
      ),
    ),
  ));
  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
  return api;
}

Future<void> _tapButton(WidgetTester tester, String label) async {
  final button = find.widgetWithText(AppButton, label);
  await tester.ensureVisible(button);
  await tester.tap(button);
  await tester.pumpAndSettle();
}

void main() {
  group('relief goods, step by step', () {
    testWidgets('opens on Household under the shared header, with Next and no Back', (tester) async {
      await _pump(tester, _relief);

      expect(find.byType(TabHeaderBar), findsOneWidget);
      expect(find.text('Relief goods distribution'), findsOneWidget);
      expect(find.text('Step 1 of 3 · Household'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Next: Assistance and delivery'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Back'), findsNothing);
      expect(find.text('Household head name'), findsOneWidget);
      // Later steps' fields are not on this one.
      expect(find.text('Type of assistance needed'), findsNothing);
    });

    testWidgets('step 2 shows its choices open, and Delivery asks for an address', (tester) async {
      await _pump(tester, _relief);
      await _tapButton(tester, 'Next: Assistance and delivery');

      expect(find.text('Step 2 of 3 · Assistance and delivery'), findsOneWidget);
      expect(find.byType(DropdownButton<String>), findsNothing);
      expect(find.text('Food packs'), findsOneWidget);
      expect(find.text('Hygiene kits'), findsOneWidget);
      expect(find.text('Delivery address'), findsNothing);

      await tester.tap(find.text('Delivery'));
      await tester.pumpAndSettle();

      expect(find.text('Delivery address'), findsOneWidget);
    });

    testWidgets('step 3 asks for the ID and reviews steps 1 and 2, each with Edit', (tester) async {
      await _pump(tester, _relief);
      await _tapButton(tester, 'Next: Assistance and delivery');
      await _tapButton(tester, 'Next: ID and review');

      expect(find.text('Step 3 of 3 · ID and review'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Submit request'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Back'), findsOneWidget);
      expect(find.text('Valid ID (required)'), findsOneWidget);
      expect(find.byType(AmbulanceReviewCard), findsNWidgets(2));
      // The household head is prefilled from the account.
      expect(find.text('Maria Dela Cruz'), findsOneWidget);

      await tester.tap(find.text('Edit').first);
      await tester.pumpAndSettle();

      expect(find.text('Step 1 of 3 · Household'), findsOneWidget);
    });

    testWidgets('submitting without an ID shows the error under the upload, not in a snackbar', (tester) async {
      final api = await _pump(tester, _relief);
      await _tapButton(tester, 'Next: Assistance and delivery');
      await _tapButton(tester, 'Next: ID and review');
      await _tapButton(tester, 'Submit request');

      expect(find.text('Attach a photo of a valid ID.'), findsOneWidget);
      expect(find.byType(SnackBar), findsNothing);
      expect(api.submits, 0);
    });

    testWidgets('a full run sends the delivery choice and address with the request', (tester) async {
      final api = await _pump(tester, _relief);
      await _tapButton(tester, 'Next: Assistance and delivery');
      await tester.tap(find.text('Delivery'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.descendant(
          of: find.byWidgetPredicate((w) => w is AppTextField && w.label == 'Delivery address'),
          matching: find.byType(TextField),
        ),
        'Purok 2, San Fabian',
      );
      await _tapButton(tester, 'Next: ID and review');

      final upload = find.descendant(
        of: find.ancestor(of: find.text('Valid ID (required)'), matching: find.byType(AttachmentUploadField)),
        matching: find.byType(InkWell),
      );
      await tester.tap(upload.first);
      await tester.pumpAndSettle();
      await _tapButton(tester, 'Submit request');

      expect(api.submits, 1);
      expect(api.fulfillmentMethod, 'Delivery');
      expect(api.deliveryAddress, 'Purok 2, San Fabian');
      expect(find.byType(ConfirmationSheet), findsOneWidget);
    });

    testWidgets('Back steps to the previous step, and leaves the page from the first', (tester) async {
      await _pump(tester, _relief);
      await _tapButton(tester, 'Next: Assistance and delivery');

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      await tester.pumpAndSettle();
      expect(find.text('Step 1 of 3 · Household'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      await tester.pumpAndSettle();
      expect(find.text('open'), findsOneWidget);
    });

    testWidgets('the system back gesture steps back too', (tester) async {
      await _pump(tester, _relief);
      await _tapButton(tester, 'Next: Assistance and delivery');

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();

      expect(find.text('Step 1 of 3 · Household'), findsOneWidget);
      expect(find.text('open'), findsNothing);
    });

    for (final (width, name) in [(320.0, '320px'), (390.0, '390px')]) {
      testWidgets('every step fits $name in Filipino without overflow', (tester) async {
        await _pump(tester, _relief, filipino: true, size: Size(width, 2400), ratio: 1);

        expect(tester.takeException(), isNull);
        await _tapButton(tester, 'Susunod: Tulong at paghahatid');
        expect(tester.takeException(), isNull);
        await tester.ensureVisible(find.text('Ihahatid'));
        await tester.tap(find.text('Ihahatid'));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        await _tapButton(tester, 'Susunod: ID at pagsusuri');
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('a single-page form', () {
    testWidgets('has the shared header and a pinned submit bar', (tester) async {
      await _pump(tester, _road);

      expect(find.byType(TabHeaderBar), findsOneWidget);
      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Submit request'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Back'), findsNothing);
    });

    testWidgets('its obstruction choices are open on the page, not in a popup', (tester) async {
      await _pump(tester, _road);

      expect(find.byType(DropdownButton<String>), findsNothing);
      expect(find.text('Fallen tree / branches'), findsOneWidget);
      expect(find.text('Landslide debris'), findsOneWidget);
    });

    testWidgets('submitting without an ID marks the upload and sends nothing', (tester) async {
      final api = await _pump(tester, _road);
      await _tapButton(tester, 'Submit request');

      expect(find.text('Attach a photo of a valid ID.'), findsOneWidget);
      expect(find.byType(SnackBar), findsNothing);
      expect(api.submits, 0);
    });

    testWidgets('keeps the form to 600dp on a wide screen', (tester) async {
      await _pump(tester, _road, size: const Size(1280, 1600), ratio: 1);

      final width = tester.getSize(find.byType(AppTextField).first).width;
      expect(width, lessThanOrEqualTo(600));
    });

    testWidgets('fits 320px in Filipino without overflow', (tester) async {
      await _pump(tester, _road, filipino: true, size: const Size(320, 2400), ratio: 1);

      expect(tester.takeException(), isNull);
    });
  });
}
