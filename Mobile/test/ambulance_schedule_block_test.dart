// The schedule step (C_Amb4) blocks Next while the picked time has no free
// ambulance, says so in plain words, and offers the server's next free start.
// A check that fails to reach the server never blocks.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/ambulance_steps.dart';

/// Answers each availability check from a queue; `null` throws, like a dead network.
class _QueuedApi extends ApiService {
  final List<AmbulanceAvailabilityCheck?> answers;
  final List<DateTime> asked = [];

  _QueuedApi(this.answers);

  @override
  Future<AmbulanceAvailabilityCheck> getAmbulanceAvailability({
    required DateTime start,
    required DateTime end,
  }) async {
    asked.add(start);
    final answer = answers.removeAt(0);
    if (answer == null) throw Exception('offline');
    return answer;
  }
}

final _picked = DateTime(2030, 1, 1, 9, 0);
final _next = DateTime(2030, 1, 1, 9, 30);

const _free = AmbulanceAvailabilityCheck(units: [
  {'vehicle_id': 1}
]);

/// Step 4 as the screen composes it: the field and the footer, with Next
/// enabled off [AmbulanceFormData.scheduleBlocked].
Future<({AmbulanceFormData form, List<int> nexts})> _pump(WidgetTester tester, _QueuedApi api) async {
  tester.view.physicalSize = const Size(400, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final form = AmbulanceFormData()..setSchedule(_picked);
  addTearDown(form.dispose);
  final landmark = TextEditingController();
  addTearDown(landmark.dispose);
  final nexts = <int>[];

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: StatefulBuilder(
        builder: (context, setState) => Column(
          children: [
            Expanded(
              child: ListView(children: [
                AmbulanceStepFields(
                  step: 3,
                  form: form,
                  appState: AppState(api),
                  filipino: false,
                  onChanged: () => setState(() {}),
                  destinations: const [],
                  barangays: const [],
                  landmark: landmark,
                  errors: const {},
                  validIdName: null,
                  onTakePhoto: () {},
                  onChooseFile: () {},
                  onEdit: (_) {},
                ),
              ]),
            ),
            AmbulanceStepFooter(
              step: 3,
              filipino: false,
              submitting: false,
              onBack: () {},
              onNext: () => nexts.add(1),
              nextEnabled: !form.scheduleBlocked,
            ),
          ],
        ),
      ),
    ),
  ));
  await tester.pump(); // initState's post-frame check starts
  await tester.pump(); // ...and answers
  return (form: form, nexts: nexts);
}

void main() {
  test('setSchedule drops every answer given for the old time', () {
    final form = AmbulanceFormData()
      ..setSchedule(_picked)
      ..unitsFree = false
      ..nextFreeStart = _next
      ..scheduleRefusal = 'taken';
    addTearDown(form.dispose);

    expect(form.scheduleBlocked, isTrue);

    form.setSchedule(_next);

    expect(form.unitsFree, isNull);
    expect(form.nextFreeStart, isNull);
    expect(form.scheduleRefusal, isNull);
    expect(form.scheduleBlocked, isFalse);
  });

  testWidgets('blocks Next and says why when no unit is free', (tester) async {
    final api = _QueuedApi([const AmbulanceAvailabilityCheck(units: [], nextFreeStart: null)]);
    final (:form, :nexts) = await _pump(tester, api);

    expect(find.text('No ambulance is free then. Pick another time.'), findsOneWidget);
    expect(form.scheduleBlocked, isTrue);

    await tester.tap(find.textContaining('Next:'), warnIfMissed: false);
    expect(nexts, isEmpty);
  });

  testWidgets('offers the next free start, and taking it re-checks and unblocks Next', (tester) async {
    final api = _QueuedApi([
      AmbulanceAvailabilityCheck(units: const [], nextFreeStart: _next),
      _free,
    ]);
    final (:form, :nexts) = await _pump(tester, api);

    expect(find.text('Next free: 9:30 AM'), findsOneWidget);

    await tester.tap(find.text('Next free: 9:30 AM'));
    await tester.pump();
    await tester.pump();

    expect(form.scheduledAt, _next);
    expect(api.asked, [_picked, _next]);
    expect(form.scheduleBlocked, isFalse);

    await tester.tap(find.textContaining('Next:'));
    expect(nexts, hasLength(1));
  });

  testWidgets('a check that could not reach the server never blocks', (tester) async {
    final api = _QueuedApi([null]);
    final (:form, :nexts) = await _pump(tester, api);

    expect(form.unitsFree, isNull);
    expect(form.scheduleBlocked, isFalse);
    expect(find.text('No ambulance is free then. Pick another time.'), findsNothing);

    await tester.tap(find.textContaining('Next:'));
    expect(nexts, hasLength(1));
  });

  test('a submit refusal on the time blocks Next until the time changes', () {
    final form = AmbulanceFormData()..setSchedule(_picked);
    addTearDown(form.dispose);
    form.scheduleRefusal = 'No ambulance is available for that time. Try a different slot.';

    expect(form.scheduleBlocked, isTrue);

    form.setSchedule(_next);
    expect(form.scheduleBlocked, isFalse);
  });
}
