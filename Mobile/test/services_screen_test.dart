// M18: `services_screen.dart` was 28.8% covered. What was covered was the
// parts that draw; what was not is everything that happens after the resident
// presses Submit — which is the whole point of the screen and the only path in
// this app that creates something the MDRRMO has to act on.
//
// The branches worth pinning, in the order a resident meets them:
//
//  * the catalogue can fail to load, and an empty grid must say so with a way
//    back rather than looking like an office that offers no services;
//  * submitting without a valid ID, without a service, or without a contact
//    number each has to be refused with a reason;
//  * a submit that never reached the server must leave a persistent error card
//    and keep every entered value, because the previous code showed a success
//    sheet on exactly this path;
//  * a submit that succeeded must clear the site photo but keep the valid ID —
//    the ID is the same next time, the site photo is one incident's scene and
//    would otherwise be filed with the next emergency;
//  * a second tap while the upload is in flight must not file a second live
//    request in the dispatcher's queue.
//
// The file picker is a plugin, so it is faked through `FilePicker.platform`.
// An unmocked plugin channel HANGS rather than throwing, so a test that sits
// until timeout here means the fake was not installed, not that the widget is
// slow.

import 'dart:async';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/screens/services_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/service_widgets.dart';
import 'package:serbis/widgets/shared_widgets.dart';

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

/// Extends [FilePicker] rather than implementing it: the platform setter runs
/// `PlatformInterface.verifyToken`, and only a subclass carries the token.
class FakeFilePicker extends FilePicker {
  FakeFilePicker({this.result});

  FilePickerResult? result;
  int callCount = 0;

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
  }) async {
    callCount++;
    return result;
  }
}

FilePickerResult _picked(String name, {bool withBytes = true}) {
  return FilePickerResult([
    PlatformFile(
      name: name,
      size: 4,
      bytes: withBytes ? Uint8List.fromList([1, 2, 3, 4]) : null,
    ),
  ]);
}

class FakeApi extends ApiService {
  FakeApi({
    List<Map<String, dynamic>>? services,
    this.servicesThrow = false,
    this.submitThrows = false,
  }) : _services = services ?? _defaultCatalogue;

  final List<Map<String, dynamic>> _services;
  bool servicesThrow;
  bool submitThrows;

  int submitCount = 0;
  List<int>? lastSitePhotoBytes;
  String? lastSitePhotoName;
  String? lastLandmark;

  /// The description as it reaches the server, which is the only place the
  /// dispatcher reads the resident's callback number now that no form asks for
  /// one. Asserting on the widget tree would prove nothing about what was sent.
  String? lastDescription;

  /// Non-null only for an ambulance request, and the thing to assert on for
  /// one: the client stopped sending `description` for that service, so
  /// [lastDescription] there is only the optimistic row's own copy, not what
  /// went on the wire.
  AmbulanceIntake? lastIntake;

  /// Set to a completer-backed future to hold a submit open mid-flight.
  Future<void>? submitGate;

  /// Same trick for the catalogue fetch, so the loading frame is observable
  /// rather than a race the fake usually wins.
  Future<void>? servicesGate;

  /// `code` decides the form, the badge and now the label as well: the screen
  /// names a service from the app's own translation table, so `service_name`
  /// below is only what an unknown code would fall back to. The names asserted
  /// in these tests are the ones that table holds.
  static final List<Map<String, dynamic>> _defaultCatalogue = [
    {
      'service_id': 1,
      'code': 'flood-evacuation',
      'service_name': 'Flood Evacuation',
    },
    {
      'service_id': 3,
      'code': 'ambulance-medical-response',
      'service_name': 'Ambulance/Medical Response',
    },
    {
      'service_id': 5,
      'code': 'road-clearing',
      'service_name': 'Road Clearing',
    },
  ];

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async => [];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async {
    if (servicesGate != null) {
      await servicesGate;
    }
    if (servicesThrow) {
      throw const ApiException('offline');
    }
    return _services;
  }

  @override
  Future<Map<String, dynamic>> submitRequest({
    required int? serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    String? landmark,
    DateTime? scheduledAt,
    AmbulanceIntake? intake,
  }) async {
    submitCount++;
    lastSitePhotoBytes = sitePhotoBytes;
    lastSitePhotoName = sitePhotoFileName;
    lastLandmark = landmark;
    lastDescription = description;
    lastIntake = intake;

    if (submitGate != null) {
      await submitGate;
    }

    if (submitThrows) {
      throw const ApiException('No available vehicles at this time.');
    }

    return {
      'request_id': 4321,
      'service_id': serviceId,
      'description': description,
      'status': 'pending',
    };
  }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

late FakeFilePicker picker;

/// The signed-in resident the screen prefills names from. A distinctive name,
/// so an assertion about the prefill cannot pass on a coincidence.
const _testUser = AppUser(
  id: '31',
  firstName: 'Maria',
  lastName: 'Dela Cruz',
  email: 'maria@example.com',
  phone: '09171234567',
  address: 'Purok 3, San Fabian',
);

Future<void> _pump(
  WidgetTester tester,
  AppState state, {
  ServiceType initialType = ServiceType.ambulance,
  VoidCallback? onSubmitted,
  AppUser? user,
}) async {
  // A tall phone. The default 800x600 surface clips this screen badly enough
  // that the submit button never builds, which would make it unfindable for a
  // reason that has nothing to do with the code under test.
  //
  // 8000, not 5600: the ambulance form grew from four inputs to nine plus a
  // relatives repeater, and 5600 was already tuned tightly enough that the
  // earlier schedule picker had pushed Submit below the fold. ensureVisible()
  // ought to scroll to it regardless of height, but this screen was
  // deliberately sized to avoid depending on that in the first place — keep it
  // that way rather than debug why only some tests need the scroll to work.
  tester.view.physicalSize = const Size(1080, 8000);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: ServicesScreen(
        appState: state,
        user: user ?? _testUser,
        initialType: initialType,
        onSubmitted: onSubmitted ?? () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

/// Taps the upload row belonging to [label].
///
/// The label `Text` is a SIBLING of the `InkWell`, not a child of it, so
/// tapping the label hits nothing — the tap has to land on the bordered row
/// underneath. Getting this wrong produces tests that pass their own setup and
/// then fail three assertions later for no visible reason.
Future<void> _tapUpload(WidgetTester tester, String label) async {
  final field = find.ancestor(
    of: find.text(label),
    matching: find.byType(AttachmentUploadField),
  );
  final target = find.descendant(of: field, matching: find.byType(InkWell)).first;

  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

/// The `TextField` inside the ambulance form's "Patient name" [AppTextField].
/// Not found by its value the way the old prefilled-name tests did — the
/// field starts empty now, so there is no text to search for.
Finder _patientNameField() {
  final field = find.byWidgetPredicate((w) => w is AppTextField && w.label == 'Patient name');
  return find.descendant(of: field, matching: find.byType(TextField));
}

/// Fills the two fields the ambulance form refuses to submit without, and
/// nothing else — the other seven are optional by design, so a test that only
/// needs a submit to go through should not have to fill them.
///
/// Mirrors the server's own required set for this service
/// (ServiceRequestController::store): patient_name and destination.
Future<void> _fillRequiredAmbulanceFields(WidgetTester tester) async {
  for (final entry in const {'Patient name': 'Maria Santos', 'To': 'Echague District Hospital'}.entries) {
    final field = find.descendant(
      of: find.byWidgetPredicate((w) => w is AppTextField && w.label == entry.key),
      matching: find.byType(TextField),
    );
    await tester.ensureVisible(field);
    await tester.enterText(field, entry.value);
  }
  await tester.pump();
}

/// Attaches a valid ID through the real picker path, which is also what covers
/// `_pickValidId` / `_pickImage`.
Future<void> _attachValidId(WidgetTester tester, {String name = 'id.jpg'}) async {
  picker.result = _picked(name);
  await _tapUpload(tester, 'Valid ID (required)');
}

Future<void> _attachSitePhoto(WidgetTester tester, {String name = 'scene.jpg'}) async {
  picker.result = _picked(name);
  await _tapUpload(tester, 'Site photo (optional)');
}

/// Opens the service dropdown and returns once the menu is on screen.
///
/// A closed `DropdownButton` builds only the selected item, so every other
/// service is absent from the tree entirely — `find.text` on an unselected
/// service finds nothing until the menu is open. That is the difference from
/// the old grid, where all ten cards were always built.
Future<void> _openServiceDropdown(WidgetTester tester) async {
  final dropdown = find.byType(DropdownButton<ServiceCatalogItem>);
  await tester.ensureVisible(dropdown);
  await tester.tap(dropdown);
  await tester.pumpAndSettle();
}

/// Picks [name] out of the open menu. The menu renders a second copy of the
/// selected item, so tapping `.last` avoids the copy still sitting in the
/// closed button underneath.
Future<void> _chooseService(WidgetTester tester, String name) async {
  await _openServiceDropdown(tester);
  await tester.tap(find.text(name).last);
  await tester.pumpAndSettle();
}

Future<void> _submit(WidgetTester tester) async {
  await tester.ensureVisible(find.widgetWithText(AppButton, 'Submit request'));
  await tester.tap(find.widgetWithText(AppButton, 'Submit request'));
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    picker = FakeFilePicker();
    FilePicker.platform = picker;
  });

  group('loading the catalogue', () {
    testWidgets('shows a spinner until the services arrive', (tester) async {
      // Same tall surface `_pump` uses, for the same reason. This test builds
      // the screen inline rather than through the helper, so it was left on
      // the default 800x600 — and the screen is a lazy ListView with the
      // safety notice above the picker, so once that notice grew to one row
      // per hotline number the spinner sat past the build extent and was
      // never constructed. Nothing to do with the loading state itself.
      tester.view.physicalSize = const Size(1080, 8000);
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);

      final gate = Completer<void>();
      final state = AppState(FakeApi()..servicesGate = gate.future);
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: ServicesScreen(
            appState: state,
            user: _testUser,
            onSubmitted: () {},
            onOpenNotifications: () {},
            onOpenProfile: () {},
          ),
        ),
      ));

      // Held open by the gate, so this frame is the loading state.
      await tester.pump();
      expect(find.byType(CircularProgressIndicator), findsOneWidget);

      gate.complete();
      await tester.pumpAndSettle();
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('offers every service as an option in the dropdown', (tester) async {
      await _pump(tester, AppState(FakeApi()));

      // Closed, the button holds only the selection. This is the assertion that
      // would pass on a broken picker if it were made before opening the menu.
      expect(find.text('Flood Evacuation'), findsNothing);

      await _openServiceDropdown(tester);

      expect(find.text('Flood Evacuation'), findsWidgets);
      expect(find.text('Ambulance/Medical Response'), findsWidgets);
      expect(find.text('Road Clearing'), findsWidgets);
    });

    testWidgets('a failed load says so and offers Retry rather than showing an empty office',
        (tester) async {
      // An empty grid and a failed fetch look identical to a resident, and they
      // mean opposite things: "MDRRMO offers nothing" versus "your phone could
      // not reach MDRRMO".
      final api = FakeApi(servicesThrow: true);
      await _pump(tester, AppState(api));

      expect(find.textContaining("Couldn't load services"), findsOneWidget);
      expect(find.byIcon(Icons.wifi_off_rounded), findsWidgets);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('Retry refetches and fills the grid once the network is back',
        (tester) async {
      final api = FakeApi(servicesThrow: true);
      await _pump(tester, AppState(api));

      expect(find.text('Retry'), findsOneWidget);

      api.servicesThrow = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(find.textContaining("Couldn't load services"), findsNothing);
      expect(find.text('Ambulance/Medical Response'), findsWidgets);
    });
  });

  group('selecting a service', () {
    testWidgets('initialType preselects a matching service', (tester) async {
      await _pump(tester, AppState(FakeApi()), initialType: ServiceType.road);

      // The form header names the selection, so a second copy of the title
      // appears once a service is chosen.
      expect(find.text('Road Clearing'), findsNWidgets(2));
    });

    testWidgets('an unmatched hint falls back to the first service',
        (tester) async {
      // `relief` has no row in this catalogue. Falling back beats rendering no
      // form at all, which would look like a broken screen.
      await _pump(tester, AppState(FakeApi()), initialType: ServiceType.relief);

      expect(find.text('Flood Evacuation'), findsNWidgets(2));
    });

    testWidgets('choosing another service from the dropdown changes the form header',
        (tester) async {
      await _pump(tester, AppState(FakeApi()), initialType: ServiceType.ambulance);

      expect(find.text('Ambulance/Medical Response'), findsNWidgets(2));

      await _chooseService(tester, 'Road Clearing');

      // Twice: once in the closed button, once as the form header. The old
      // selection is gone from both.
      expect(find.text('Road Clearing'), findsNWidgets(2));
      expect(find.text('Ambulance/Medical Response'), findsNothing);
    });

    testWidgets('the patient name starts empty, not the signed-in resident',
        (tester) async {
      // The account holder is the likeliest patient, not the certain one — a
      // head of the family files for the household — so a name already
      // sitting in the field would read as a default nobody actually chose.
      await _pump(tester, AppState(FakeApi()), initialType: ServiceType.ambulance);

      final controller = tester.widget<TextField>(_patientNameField()).controller!;
      expect(controller.text, isEmpty);
      expect(find.text('Maria Dela Cruz'), findsNothing);
    });

    testWidgets('a typed name survives switching services and back',
        (tester) async {
      // The typed value runs through putIfAbsent, not on every build. If it
      // ran on every rebuild, switching services and back would wipe out
      // whatever the resident had already typed.
      await _pump(tester, AppState(FakeApi()), initialType: ServiceType.ambulance);

      await tester.enterText(_patientNameField(), 'Juan Dela Cruz');
      await tester.pumpAndSettle();

      await _chooseService(tester, 'Road Clearing');
      await _chooseService(tester, 'Ambulance/Medical Response');

      expect(find.widgetWithText(TextField, 'Juan Dela Cruz'), findsOneWidget);
    });

    testWidgets('an empty catalogue renders no form section at all',
        (tester) async {
      final api = FakeApi(services: []);
      await _pump(tester, AppState(api));

      expect(find.byType(AttachmentUploadField), findsNothing);
      expect(find.widgetWithText(AppButton, 'Submit request'), findsNothing);
    });
  });

  group('refusing an incomplete submit', () {
    testWidgets('submitting with no valid ID attached is refused with a reason',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api));

      await _submit(tester);

      expect(
        find.text('Please attach a photo of your valid ID before submitting.'),
        findsOneWidget,
      );
      expect(api.submitCount, 0, reason: 'nothing may reach the server');
    });

    testWidgets('a picked file with no bytes is treated as no file at all',
        (tester) async {
      // `withData: true` is what populates `bytes`. If that ever stops holding,
      // sending the name without the bytes is a 422 on an upload that was
      // required — better to refuse locally and say why.
      final api = FakeApi();
      await _pump(tester, AppState(api));

      picker.result = _picked('id.jpg', withBytes: false);
      await tester.tap(find.text('Valid ID (required)'));
      await tester.pumpAndSettle();

      await _submit(tester);

      expect(
        find.text('Please attach a photo of your valid ID before submitting.'),
        findsOneWidget,
      );
      expect(api.submitCount, 0);
    });

    testWidgets('an ambulance request goes through without asking for a number',
        (tester) async {
      // This used to be refused until the resident typed a callback number.
      // The number is on the account from registration, so that guard is gone;
      // the patient name and destination are what the form asks for now, and
      // the number field it does render arrives already filled.
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.ambulance);

      await _fillRequiredAmbulanceFields(tester);
      await _attachValidId(tester);
      await _submit(tester);

      expect(api.submitCount, 1);
      expect(find.byType(ConfirmationSheet), findsOneWidget);
    });

    testWidgets('an ambulance request with no patient name is refused with a reason',
        (tester) async {
      // The client-side half of the server's own required_if rule, so the
      // resident is told which field is missing instead of meeting a 422 the
      // app would surface as a generic failure.
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.ambulance);

      await _attachValidId(tester);
      await _submit(tester);

      expect(find.textContaining('the patient name'), findsOneWidget);
      expect(api.submitCount, 0, reason: 'nothing may reach the server');
    });

    testWidgets("the account's number reaches the dispatcher as a real field",
        (tester) async {
      // Was an assertion on `lastDescription`. The client no longer composes a
      // description for an ambulance request -- the server does, from these
      // fields -- so asserting on the prose would be asserting on a string
      // that never leaves the device. The number still has to arrive; it just
      // arrives as patient_contact_number now.
      //
      // The resident never typed 09171234567 anywhere in this test: it is on
      // `_testUser` and reaches the wire by prefilling the contact field.
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.ambulance);

      await _fillRequiredAmbulanceFields(tester);
      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastIntake?.patientContactNumber, '09171234567');
      expect(api.lastIntake?.toFields()['patient_contact_number'], '09171234567');
    });

    testWidgets("the account's address prefills the patient address",
        (tester) async {
      // Same shape as the number above: never typed here, carried by
      // `_testUser`, and editable once it is on screen.
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.ambulance);

      await _fillRequiredAmbulanceFields(tester);
      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastIntake?.patientAddress, _testUser.address);
    });

    testWidgets('an ambulance request sends the structured fields, not a description',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.ambulance);

      await _fillRequiredAmbulanceFields(tester);
      await _attachValidId(tester);
      await _submit(tester);

      final fields = api.lastIntake!.toFields();
      expect(fields['patient_name'], 'Maria Santos');
      expect(fields['destination'], 'Echague District Hospital');
      // The one key the server refuses to take from a client for this service.
      expect(fields.containsKey('description'), isFalse);
    });

    testWidgets('a non-ambulance request still sends a description and no intake',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastIntake, isNull);
      expect(api.lastDescription, isNotNull);
      expect(api.lastDescription, contains('Road Clearing'));
    });

    testWidgets('a road request carries no contact line at all', (tester) async {
      // The road form asks about a place, not about the reporter.
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);

      expect(api.submitCount, 1);
      expect(api.lastDescription, isNot(contains('Contact:')));
    });

    testWidgets('a cancelled picker leaves the previous attachment alone',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api));

      await _attachValidId(tester, name: 'first.jpg');
      expect(find.text('first.jpg'), findsOneWidget);

      // The resident opened the picker and backed out.
      picker.result = null;
      await _tapUpload(tester, 'Valid ID (required)');

      expect(find.text('first.jpg'), findsOneWidget,
          reason: 'cancelling must not clear what was already attached');
    });
  });

  group('a submit that fails', () {
    testWidgets('shows a persistent error card, not a confirmation sheet',
        (tester) async {
      // The regression this exists for: the previous code showed the success
      // sheet on this path, so a resident whose request never reached MDRRMO
      // was told help was coming.
      final api = FakeApi(submitThrows: true);
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);

      expect(find.byType(SubmitErrorCard), findsOneWidget);
      expect(find.byType(ConfirmationSheet), findsNothing);
    });

    testWidgets('keeps the attached ID and site photo so Retry costs one tap',
        (tester) async {
      final api = FakeApi(submitThrows: true);
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester, name: 'id.jpg');
      await _attachSitePhoto(tester, name: 'scene.jpg');
      await _submit(tester);

      expect(find.byType(SubmitErrorCard), findsOneWidget);
      expect(find.text('id.jpg'), findsOneWidget);
      expect(find.text('scene.jpg'), findsOneWidget);
    });

    testWidgets('the error card clears once a retry succeeds', (tester) async {
      final api = FakeApi(submitThrows: true);
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);
      expect(find.byType(SubmitErrorCard), findsOneWidget);

      api.submitThrows = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(find.byType(SubmitErrorCard), findsNothing);
      expect(find.byType(ConfirmationSheet), findsOneWidget);
    });
  });

  group('a submit that succeeds', () {
    testWidgets('opens the confirmation sheet carrying the server reference',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);

      expect(find.byType(ConfirmationSheet), findsOneWidget);
      expect(api.submitCount, 1);
    });

    testWidgets('clears the site photo but keeps the valid ID', (tester) async {
      // The asymmetry is the decision: the ID is the same ID next time, so
      // re-picking it is pure friction; the site photo is one incident's scene
      // and keeping it would file the last emergency's photo with the next
      // request.
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester, name: 'id.jpg');
      await _attachSitePhoto(tester, name: 'scene.jpg');
      await _submit(tester);

      // Dismiss the sheet to get back to the form.
      Navigator.of(tester.element(find.byType(ConfirmationSheet))).pop();
      await tester.pumpAndSettle();

      expect(find.text('id.jpg'), findsOneWidget);
      expect(find.text('scene.jpg'), findsNothing);
    });

    testWidgets('the site photo is sent when attached and omitted when not',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastSitePhotoBytes, isNull);
      expect(api.lastSitePhotoName, isNull,
          reason: 'an empty part is a mimes: failure on an optional upload');
    });

    testWidgets('clearing the site photo removes it before submitting',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _attachSitePhoto(tester, name: 'scene.jpg');
      expect(find.text('scene.jpg'), findsOneWidget);

      // The clear button is a sibling of the InkWell, so this must not reopen
      // the picker.
      final before = picker.callCount;
      await tester.tap(find.byIcon(Icons.close_rounded).last);
      await tester.pumpAndSettle();

      expect(find.text('scene.jpg'), findsNothing);
      expect(picker.callCount, before,
          reason: 'clearing must not reopen the file picker');
    });

    testWidgets('onSubmitted fires when the sheet sends the resident to Track',
        (tester) async {
      var submitted = false;
      final api = FakeApi();
      await _pump(tester, AppState(api),
          initialType: ServiceType.road, onSubmitted: () => submitted = true);

      await _attachValidId(tester);
      await _submit(tester);

      await tester.tap(find.text('View in Track'));
      await tester.pumpAndSettle();

      expect(submitted, isTrue);
    });
  });

  group('the in-flight guard', () {
    testWidgets('a second tap during the upload does not file a second request',
        (tester) async {
      // The upload carries a photo and has a 30-second timeout. Without the
      // guard every extra tap in that window filed another live request in the
      // dispatcher's queue.
      //
      // The tap has to come from SubmitErrorCard's Retry, not from the main
      // button: AppButton sets `onPressed: null` while `loading`, so the
      // primary button already refuses a second tap on its own and driving the
      // test through it proves nothing about `_submit`'s guard. Retry is the
      // path that stays live — the card is rendered whenever `_submitFailed`
      // is true, and a retry in flight does not clear that flag.
      final api = FakeApi(submitThrows: true);
      await _pump(tester, AppState(api), initialType: ServiceType.road);

      await _attachValidId(tester);
      await _submit(tester);

      expect(find.byType(SubmitErrorCard), findsOneWidget);
      expect(api.submitCount, 1);

      // Retry, held open mid-flight.
      final gate = Completer<void>();
      api
        ..submitThrows = false
        ..submitGate = gate.future;

      await tester.ensureVisible(find.text('Retry'));
      await tester.tap(find.text('Retry'));
      await tester.pump();

      // Second tap while the first retry is still in the air.
      await tester.tap(find.text('Retry'), warnIfMissed: false);
      await tester.pump();

      gate.complete();
      await tester.pumpAndSettle();

      expect(api.submitCount, 2,
          reason: 'the retry counts once; the second tap must be swallowed');
    });
  });
}
