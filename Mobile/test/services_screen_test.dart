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
// Since the redesign the screen is two: the Services tab is a grid of tiles and
// each tile opens the service's form on its own page, and the Ambulance tab is
// the ambulance form with nothing in front of it. The helpers below reach the
// same form either way, so the assertions about what happens on Submit are
// unchanged.
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
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/screens/ambulance_screen.dart';
import 'package:serbis/screens/service_drafts.dart';
import 'package:serbis/screens/services_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/feedback.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/loading.dart';
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

/// Pumps the Ambulance tab, or — with [open] — the Services grid with that
/// tile already tapped, so the form under test is on screen either way.
Future<void> _pump(
  WidgetTester tester,
  AppState state, {
  String? open,
  VoidCallback? onSubmitted,
  AppUser? user,
  ServiceDrafts? drafts,
  VoidCallback? onExit,
}) async {
  // A tall phone. The default 800x600 surface clips this screen badly enough
  // that the submit button never builds, which would make it unfindable for a
  // reason that has nothing to do with the code under test.
  //
  // 8600, not 8000: the "Same as my address" checkboxes on patient address
  // and pickup (MDRRMO feedback, 2026-09-19) pushed Submit below the fold
  // again at 8000. ensureVisible() ought to scroll to it regardless of
  // height, but this screen was deliberately sized to avoid depending on
  // that in the first place — keep it that way rather than debug why only
  // some tests need the scroll to work. Previously 8000, not 5600: the
  // ambulance form grew from four inputs to nine plus a relatives repeater,
  // and 5600 was already tuned tightly enough that the schedule picker
  // before that had pushed Submit below the fold too.
  tester.view.physicalSize = const Size(1080, 8600);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final resident = user ?? _testUser;
  final kept = drafts ?? ServiceDrafts(resident);
  if (drafts == null) addTearDown(kept.dispose);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: open == null
          ? AmbulanceScreen(
              appState: state,
              user: resident,
              drafts: kept,
              onSubmitted: onSubmitted ?? () {},
              onOpenNotifications: () {},
              onOpenProfile: () {},
              onBack: onExit,
            )
          : ServicesScreen(
              appState: state,
              user: resident,
              drafts: kept,
              onSubmitted: onSubmitted ?? () {},
              onOpenNotifications: () {},
              onOpenProfile: () {},
            ),
    ),
  ));
  await tester.pumpAndSettle();

  if (open != null) {
    await _openTile(tester, open);
  }
}

/// The Services grid alone, with no tile tapped.
Future<void> _pumpGrid(WidgetTester tester, AppState state) async {
  tester.view.physicalSize = const Size(1080, 8600);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

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
  await tester.pumpAndSettle();
}

/// Taps the upload row belonging to [label].
///
/// The label `Text` is a SIBLING of the `InkWell`, not a child of it, so
/// tapping the label hits nothing — the tap has to land on the bordered row
/// underneath. Getting this wrong produces tests that pass their own setup and
/// then fail three assertions later for no visible reason.
Future<void> _tapUpload(WidgetTester tester, String label) async {
  // The Ambulance tab's ID upload has Take photo beside it: "Choose file" is the
  // file-picker path.
  if (label == 'Valid ID (required)' && find.text('Take photo').evaluate().isNotEmpty) {
    final choose = find.text('Choose file');
    await tester.ensureVisible(choose);
    await tester.tap(choose);
    await tester.pumpAndSettle();
    return;
  }
  final field = find.ancestor(
    of: find.text(label),
    matching: find.byType(AttachmentUploadField),
  );
  final target = find.descendant(of: field, matching: find.byType(InkWell)).first;

  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

/// The `TextField` inside the ambulance form's "Full name" [AppTextField].
/// Not found by its value the way the old prefilled-name tests did — the
/// field starts empty now, so there is no text to search for.
Finder _patientNameField() {
  final field = find.byWidgetPredicate((w) => w is AppTextField && w.label == 'Full name');
  return find.descendant(of: field, matching: find.byType(TextField));
}

/// Types [value] into the [AppTextField] labelled [label].
Future<void> _type(WidgetTester tester, String label, String value) async {
  final field = find.descendant(
    of: find.byWidgetPredicate((w) => w is AppTextField && w.label == label),
    matching: find.byType(TextField),
  );
  await tester.ensureVisible(field);
  await tester.enterText(field, value);
  await tester.pump();
}

/// Taps the ambulance footer's "Next: …" button.
Future<void> _next(WidgetTester tester) async {
  final next = find.ancestor(of: find.textContaining('Next: '), matching: find.byType(AppButton));
  await tester.ensureVisible(next);
  await tester.tap(next);
  await tester.pumpAndSettle();
}

/// Fills the three fields the ambulance flow requires, and nothing else, one
/// step at a time, stopping on step 4 (Schedule and ID). [onPatientStep] runs
/// while step 1 is showing.
///
/// Mirrors the server's own required set for this service
/// (ServiceRequestController::store): patient_name, destination, a relative.
Future<void> _fillRequiredAmbulanceFields(WidgetTester tester, {Future<void> Function()? onPatientStep}) async {
  await _type(tester, 'Full name', 'Maria Santos');
  await onPatientStep?.call();
  await _next(tester);
  await _type(tester, 'Destination name', 'Echague District Hospital');
  await _next(tester);
  await _type(tester, 'Relative to contact', 'Lalaine Ferrer');
  await _next(tester);
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

/// Taps the grid tile named [name] and waits for its form page to open.
Future<void> _openTile(WidgetTester tester, String name) async {
  final tile = find.text(name);
  await tester.ensureVisible(tile);
  await tester.tap(tile);
  await tester.pumpAndSettle();
}

/// The header's back arrow, which is how a resident leaves a form page.
Future<void> _goBack(WidgetTester tester) async {
  await tester.tap(find.byIcon(Icons.arrow_back_rounded));
  await tester.pumpAndSettle();
}

Future<void> _submit(WidgetTester tester) async {
  // The ambulance flow submits from its Review step.
  if (find.text('Next: Review').evaluate().isNotEmpty) await _next(tester);
  await tester.ensureVisible(find.widgetWithText(AppButton, 'Send request'));
  await tester.tap(find.widgetWithText(AppButton, 'Send request'));
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    picker = FakeFilePicker();
    FilePicker.platform = picker;
  });

  group('loading the catalogue', () {
    testWidgets('shows the rows\' shape until the services arrive', (tester) async {
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
      expect(find.byType(SkeletonRows), findsOneWidget);

      gate.complete();
      await tester.pumpAndSettle();
      expect(find.byType(SkeletonRows), findsNothing);
    });

    testWidgets('groups services under category headings in the office order, Others last',
        (tester) async {
      final api = FakeApi(services: [
        {'service_id': 7, 'code': 'sandbagging', 'service_name': 'Sandbagging', 'category': 'rescue'},
        {'service_id': 5, 'code': 'road-clearing', 'service_name': 'Road Clearing', 'category': 'infrastructure'},
        {'service_id': 9, 'code': 'fire-watch', 'service_name': 'Fire Watch', 'category': 'fire'},
      ]);
      await _pumpGrid(tester, AppState(api));

      double top(String text) => tester.getTopLeft(find.text(text)).dy;
      expect(top('Infrastructure'), lessThan(top('Rescue')));
      expect(top('Rescue'), lessThan(top('Fire')));
      // An untranslated category shows its code in sentence case; "Others" has none and goes last.
      expect(top('Fire'), lessThan(top('Other requests')));
      expect(top('Road clearing'), lessThan(top('Sandbagging')));
    });

    testWidgets('offers every service as a tile, the ambulance excepted', (tester) async {
      await _pumpGrid(tester, AppState(FakeApi()));

      expect(find.text('Flood Evacuation'), findsOneWidget);
      expect(find.text('Road clearing'), findsOneWidget);
      // "Others" is appended by the screen: it has no catalogue row.
      expect(find.text('Others'), findsOneWidget);
      // The ambulance has its own tab, so it is not offered a second time here.
      expect(find.text('Ambulance/Medical Response'), findsNothing);
    });

    testWidgets('withholds Others when the audience does not include it', (tester) async {
      final api = FakeApi()..serviceAudience = (equipmentBorrowing: true, others: false);
      await _pumpGrid(tester, AppState(api));

      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.text('Others'), findsNothing);
    });

    testWidgets('a failed load says so and offers Try again rather than showing an empty office',
        (tester) async {
      // An empty grid and a failed fetch look identical to a resident, and they
      // mean opposite things: "MDRRMO offers nothing" versus "your phone could
      // not reach MDRRMO".
      final api = FakeApi(servicesThrow: true);
      await _pumpGrid(tester, AppState(api));

      expect(find.byType(LoadErrorBox), findsOneWidget);
      expect(find.textContaining("Couldn't load services"), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
    });

    testWidgets('Try again refetches and fills the grid once the network is back',
        (tester) async {
      final api = FakeApi(servicesThrow: true);
      await _pumpGrid(tester, AppState(api));

      expect(find.text('Try again'), findsOneWidget);

      api.servicesThrow = false;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();

      expect(find.textContaining("Couldn't load services"), findsNothing);
      expect(find.text('Road clearing'), findsOneWidget);
    });
  });

  group('opening a service', () {
    testWidgets('a tile opens that service on a page of its own', (tester) async {
      await _pump(tester, AppState(FakeApi()), open: 'Road clearing');

      // The form's own heading, with the grid no longer the visible route.
      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.text('Flood Evacuation'), findsNothing);
      expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Send request'), findsOneWidget);
    });

    testWidgets('the back arrow returns to the grid', (tester) async {
      await _pump(tester, AppState(FakeApi()), open: 'Road clearing');

      await _goBack(tester);

      expect(find.text('Flood Evacuation'), findsOneWidget);
      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Send request'), findsNothing);
    });

    testWidgets('the back arrow stays pinned while a long form scrolls', (tester) async {
      await _pump(tester, AppState(FakeApi()), open: 'Road clearing');

      // Down to a phone, so the form is longer than the screen and scrolls.
      tester.view.physicalSize = const Size(1080, 1600);
      await tester.pumpAndSettle();

      final back = find.byIcon(Icons.arrow_back_rounded);
      final before = tester.getTopLeft(back);

      // The list, not the form: the form's centre is below the fold, where a
      // drag lands on nothing and scrolls nothing.
      final scrollable = tester.state<ScrollableState>(find.byType(Scrollable).first);
      await tester.drag(find.byType(ListView).first, const Offset(0, -600));
      await tester.pumpAndSettle();

      expect(scrollable.position.pixels, greaterThan(0), reason: 'the form did not scroll');
      expect(tester.getTopLeft(back), before);
      await _goBack(tester);
      expect(find.text('Flood Evacuation'), findsOneWidget);
    });

    testWidgets('the Ambulance tab opens straight onto the ambulance form', (tester) async {
      await _pump(tester, AppState(FakeApi()));

      expect(find.text('Request an ambulance'), findsOneWidget);
      expect(find.text('Step 1 of 5 · Patient'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Next: Trip'), findsOneWidget);
      // No Back on the first step, and no Submit before the last.
      expect(find.widgetWithText(AppButton, 'Back'), findsNothing);
      expect(find.widgetWithText(AppButton, 'Send request'), findsNothing);
      // No list to pick from first.
      expect(find.text('Road clearing'), findsNothing);
    });

    testWidgets('Edit on Review and Back move between steps, keeping answers', (tester) async {
      await _pump(tester, AppState(FakeApi()));

      await _fillRequiredAmbulanceFields(tester);
      await _attachValidId(tester);
      await _next(tester);
      expect(find.text('Step 5 of 5 · Review'), findsOneWidget);
      // The Patient row's answers share one line: "Maria Santos · …".
      expect(find.textContaining('Maria Santos'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Send request'), findsOneWidget);

      // The first row's Edit goes back to Patient.
      await tester.tap(find.text('Edit').first);
      await tester.pumpAndSettle();
      expect(find.text('Step 1 of 5 · Patient'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'Maria Santos'), findsOneWidget);

      await _next(tester);
      await tester.tap(find.widgetWithText(AppButton, 'Back'));
      await tester.pumpAndSettle();
      expect(find.text('Step 1 of 5 · Patient'), findsOneWidget);
    });

    testWidgets('closing with answers entered asks first, then clears them', (tester) async {
      var exits = 0;
      await _pump(tester, AppState(FakeApi()), onExit: () => exits++);
      await _type(tester, 'Full name', 'Maria Santos');

      await tester.tap(find.byIcon(Icons.close_rounded));
      await tester.pumpAndSettle();
      expect(find.text('Discard this request?'), findsOneWidget);
      await tester.tap(find.text('Keep editing'));
      await tester.pumpAndSettle();
      expect(exits, 0);
      expect(find.widgetWithText(TextField, 'Maria Santos'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.close_rounded));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Discard'));
      await tester.pumpAndSettle();
      expect(exits, 1);
      expect(tester.widget<TextField>(_patientNameField()).controller!.text, isEmpty);
    });

    testWidgets('the patient name starts empty, not the signed-in resident',
        (tester) async {
      // The account holder is the likeliest patient, not the certain one — a
      // head of the family files for the household — so a name already
      // sitting in the field would read as a default nobody actually chose.
      await _pump(tester, AppState(FakeApi()));

      final controller = tester.widget<TextField>(_patientNameField()).controller!;
      expect(controller.text, isEmpty);
      expect(find.text('Maria Dela Cruz'), findsNothing);
    });

    testWidgets('a typed name survives leaving the form and coming back',
        (tester) async {
      // The answers live in the drafts the shell holds, not in the page, because
      // a page is rebuilt on every visit.
      final drafts = ServiceDrafts(_testUser);
      addTearDown(drafts.dispose);

      await _pump(tester, AppState(FakeApi()), drafts: drafts);
      await tester.enterText(_patientNameField(), 'Juan Dela Cruz');
      await tester.pumpAndSettle();

      // Away, and back to a fresh screen over the same drafts.
      await tester.pumpWidget(const SizedBox.shrink());
      await _pump(tester, AppState(FakeApi()), drafts: drafts);

      expect(find.widgetWithText(TextField, 'Juan Dela Cruz'), findsOneWidget);
    });

    testWidgets('the ID attached on one form is still attached on the next', (tester) async {
      final drafts = ServiceDrafts(_testUser);
      addTearDown(drafts.dispose);

      await _pump(tester, AppState(FakeApi()), open: 'Road clearing', drafts: drafts);
      await _attachValidId(tester, name: 'id.jpg');
      await _goBack(tester);

      await _openTile(tester, 'Flood Evacuation');

      expect(find.text('id.jpg'), findsOneWidget);
    });

    testWidgets('an empty catalogue renders no form section at all',
        (tester) async {
      final api = FakeApi(services: []);
      await _pump(tester, AppState(api));

      expect(find.byType(AttachmentUploadField), findsNothing);
      expect(find.widgetWithText(AppButton, 'Send request'), findsNothing);
      expect(find.text('Try again'), findsOneWidget);
    });

    testWidgets('an account not offered the ambulance says so, without a retry',
        (tester) async {
      final api = FakeApi(services: [
        {'service_id': 5, 'code': 'road-clearing', 'service_name': 'Road Clearing'},
      ]);
      await _pump(tester, AppState(api));

      expect(find.textContaining('not offered to this account type'), findsOneWidget);
      expect(find.text('Try again'), findsNothing);
      expect(find.widgetWithText(AppButton, 'Send request'), findsNothing);
    });
  });

  group('refusing an incomplete submit', () {
    testWidgets('submitting with no valid ID attached is refused with a reason',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api));

      await _fillRequiredAmbulanceFields(tester);
      await _next(tester);

      expect(find.text('Attach a photo of a valid ID.'), findsOneWidget);
      expect(find.text('Step 4 of 5 · Schedule and ID'), findsOneWidget);
      expect(api.submitCount, 0, reason: 'nothing may reach the server');
    });

    testWidgets('a picked file with no bytes is treated as no file at all',
        (tester) async {
      // `withData: true` is what populates `bytes`. If that ever stops holding,
      // sending the name without the bytes is a 422 on an upload that was
      // required — better to refuse locally and say why.
      final api = FakeApi();
      await _pump(tester, AppState(api));
      await _fillRequiredAmbulanceFields(tester);

      picker.result = _picked('id.jpg', withBytes: false);
      await _tapUpload(tester, 'Valid ID (required)');

      await _next(tester);

      expect(find.text('Attach a photo of a valid ID.'), findsOneWidget);
      expect(api.submitCount, 0);
    });

    testWidgets('an ambulance request goes through without asking for a number',
        (tester) async {
      // This used to be refused until the resident typed a callback number.
      // The number is on the account from registration, so that guard is gone;
      // the patient name and destination are what the form asks for now, and
      // the number field it does render arrives already filled.
      final api = FakeApi();
      await _pump(tester, AppState(api));

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
      await _pump(tester, AppState(api));

      await _next(tester);

      expect(find.text('Enter the patient name.'), findsOneWidget);
      expect(find.text('Step 1 of 5 · Patient'), findsOneWidget, reason: 'Next must not advance');
      expect(api.submitCount, 0, reason: 'nothing may reach the server');
    });

    testWidgets('an ambulance request naming no relative is refused with a reason',
        (tester) async {
      // MDRRMO, 2026-09-20: the hospital asks for a companion, so at least one
      // relative is required. The server refuses it too; this is the half that
      // tells the resident which field.
      final api = FakeApi();
      await _pump(tester, AppState(api));

      await _type(tester, 'Full name', 'Maria Santos');
      await _next(tester);
      await _type(tester, 'Destination name', 'Echague District Hospital');
      await _next(tester);
      await _next(tester);

      expect(find.text('Name at least one relative going with the patient.'), findsOneWidget);
      expect(find.text('Step 3 of 5 · Condition'), findsOneWidget);
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
      await _pump(tester, AppState(api));

      await _fillRequiredAmbulanceFields(tester);
      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastIntake?.patientContactNumber, '09171234567');
      expect(api.lastIntake?.toFields()['patient_contact_number'], '09171234567');
    });

    testWidgets(
        'checking "Same as my address" sends the account address as the patient address',
        (tester) async {
      // Unlike the callback number, the address is never prefilled
      // automatically (MDRRMO feedback, 2026-09-19) — the checkbox is what
      // carries `_testUser`'s address onto the field.
      final api = FakeApi();
      await _pump(tester, AppState(api));

      await _fillRequiredAmbulanceFields(tester, onPatientStep: () async {
        await tester.ensureVisible(find.text('Lives at my address'));
        await tester.tap(find.text('Lives at my address'));
        await tester.pump();
      });
      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastIntake?.patientAddress, _testUser.fullAddress);
    });

    testWidgets('an ambulance request sends the structured fields, not a description',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api));

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
      await _pump(tester, AppState(api), open: 'Road clearing');

      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastIntake, isNull);
      expect(api.lastDescription, isNotNull);
      expect(api.lastDescription, contains('Road Clearing'));
    });

    testWidgets('a road request carries no contact line at all', (tester) async {
      // The road form asks about a place, not about the reporter.
      final api = FakeApi();
      await _pump(tester, AppState(api), open: 'Road clearing');

      await _attachValidId(tester);
      await _submit(tester);

      expect(api.submitCount, 1);
      expect(api.lastDescription, isNot(contains('Contact:')));
    });

    testWidgets('a cancelled picker leaves the previous attachment alone',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api));
      await _fillRequiredAmbulanceFields(tester);

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
      await _pump(tester, AppState(api), open: 'Road clearing');

      await _attachValidId(tester);
      await _submit(tester);

      expect(find.byType(SubmitErrorCard), findsOneWidget);
      expect(find.byType(ConfirmationSheet), findsNothing);
    });

    testWidgets('keeps the attached ID and site photo so Retry costs one tap',
        (tester) async {
      final api = FakeApi(submitThrows: true);
      await _pump(tester, AppState(api), open: 'Road clearing');

      await _attachValidId(tester, name: 'id.jpg');
      await _attachSitePhoto(tester, name: 'scene.jpg');
      await _submit(tester);

      expect(find.byType(SubmitErrorCard), findsOneWidget);
      expect(find.text('id.jpg'), findsOneWidget);
      expect(find.text('scene.jpg'), findsOneWidget);
    });

    testWidgets('the error card clears once a retry succeeds', (tester) async {
      final api = FakeApi(submitThrows: true);
      await _pump(tester, AppState(api), open: 'Road clearing');

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
      await _pump(tester, AppState(api), open: 'Road clearing');

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
      await _pump(tester, AppState(api), open: 'Road clearing');

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
      await _pump(tester, AppState(api), open: 'Road clearing');

      await _attachValidId(tester);
      await _submit(tester);

      expect(api.lastSitePhotoBytes, isNull);
      expect(api.lastSitePhotoName, isNull,
          reason: 'an empty part is a mimes: failure on an optional upload');
    });

    testWidgets('clearing the site photo removes it before submitting',
        (tester) async {
      final api = FakeApi();
      await _pump(tester, AppState(api), open: 'Road clearing');

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
          open: 'Road clearing', onSubmitted: () => submitted = true);

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
      await _pump(tester, AppState(api), open: 'Road clearing');

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

  group('the restyled tab', () {
    testWidgets('the header names the tab; the old "Service Request" heading is gone', (tester) async {
      await _pumpGrid(tester, AppState(FakeApi()));

      expect(find.text('Services'), findsOneWidget);
      expect(find.text('Request help from Echague MDRRMO'), findsOneWidget);
      expect(find.text('Service Request'), findsNothing);
    });

    testWidgets('search filters by name', (tester) async {
      await _pumpGrid(tester, AppState(FakeApi()));

      await tester.enterText(find.byType(TextField), 'ROAD');
      await tester.pump();

      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.text('Flood Evacuation'), findsNothing);
      expect(find.text('Others'), findsNothing);
    });

    testWidgets('search filters by description', (tester) async {
      await _pumpGrid(tester, AppState(FakeApi()));

      // Only Road clearing's description mentions debris.
      await tester.enterText(find.byType(TextField), 'debris');
      await tester.pump();

      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.text('Flood Evacuation'), findsNothing);
    });

    testWidgets('no match shows the empty state', (tester) async {
      await _pumpGrid(tester, AppState(FakeApi()));

      await tester.enterText(find.byType(TextField), 'zzz');
      await tester.pump();

      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.text('No services match "zzz"'), findsOneWidget);
      expect(find.text('Road clearing'), findsNothing);
    });

    testWidgets('fits a 320px phone in Filipino without overflow', (tester) async {
      final state = AppState(FakeApi());
      await _pumpGrid(tester, state);
      tester.view.physicalSize = const Size(320, 2400);
      tester.view.devicePixelRatio = 1;
      state.setLanguage(AppLanguage.filipino);
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Paglinis ng daan'), findsOneWidget);
      expect(find.text('Maghanap ng serbisyo'), findsOneWidget);
    });
  });
}
