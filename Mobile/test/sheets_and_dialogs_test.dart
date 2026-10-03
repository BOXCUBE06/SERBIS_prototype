// The bell sheet and the confirm dialogs on the new scale: status boxes instead
// of badges, 44dp close, a width cap, and a cancel button that keeps its label
// while the request is in flight.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/advisory.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/borrow_request_widgets.dart' show StatusBox;
import 'package:serbis/widgets/shared_widgets.dart';

ServiceRequest _request(String status, {int id = 7, String? remarks}) => ServiceRequest.fromJson(<String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'status': status,
      'remarks': remarks,
      'service': {'service_name': 'Road clearing', 'code': 'road-clearing'},
      'created_at': '2026-08-01T05:04:00.000000Z',
      'updated_at': '2026-08-02T05:04:00.000000Z',
    });

Future<void> _pumpSheet(
  WidgetTester tester, {
  List<ServiceRequest> requests = const [],
  List<Advisory> advisories = const [],
  bool filipino = false,
  Size size = const Size(390, 1200),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(body: NotificationsSheet(requests: requests, advisories: advisories, filipino: filipino)),
  ));
}

void main() {
  group('the notifications sheet', () {
    testWidgets('draws each request with a status box, not a badge', (tester) async {
      await _pumpSheet(tester, requests: [_request('Pending', id: 1), _request('Resolved', id: 2)]);

      // Two request boxes, plus the "no advisories" box above them.
      expect(find.byType(StatusBox), findsNWidgets(3));
      expect(find.byType(StatusBadge), findsNothing);
      expect(find.text('Waiting for MDRRMO'), findsOneWidget);
      expect(find.text('Completed'), findsOneWidget);
      expect(find.text('Road clearing'), findsNWidgets(2));
    });

    testWidgets('a disapproved request says why in the box', (tester) async {
      await _pumpSheet(tester, requests: [_request('Disapproved', remarks: 'Outside our service area.')]);

      expect(find.text('Not approved'), findsOneWidget);
      expect(find.textContaining('Outside our service area.'), findsOneWidget);
    });

    testWidgets('the close button is a 44dp target', (tester) async {
      await _pumpSheet(tester);

      final close = find.ancestor(of: find.byIcon(Icons.close_rounded), matching: find.byType(IconButton));
      final size = tester.getSize(close);
      expect(size.width, greaterThanOrEqualTo(44));
      expect(size.height, greaterThanOrEqualTo(44));
    });

    testWidgets('section headings are sentence case', (tester) async {
      await _pumpSheet(tester, requests: [_request('Pending')]);

      expect(find.text('MDRRMO advisories'), findsOneWidget);
      expect(find.text('Your requests'), findsOneWidget);
      expect(find.text('MDRRMO ADVISORIES'), findsNothing);
    });

    testWidgets('keeps its content to 600dp on a wide screen', (tester) async {
      await _pumpSheet(tester, requests: [_request('Pending')], size: const Size(1400, 1000));

      expect(tester.getSize(find.byType(StatusBox).last).width, lessThanOrEqualTo(600));
    });

    testWidgets('an advisory is read in 15px or larger', (tester) async {
      await _pumpSheet(tester, advisories: [const Advisory(id: 1, message: 'Evacuate before 6 PM.')]);

      final text = tester.widget<Text>(find.text('Evacuate before 6 PM.'));
      expect(text.style!.fontSize, greaterThanOrEqualTo(15));
    });

    for (final width in [320.0, 390.0]) {
      testWidgets('fits ${width.toInt()}px in Filipino without overflow', (tester) async {
        await _pumpSheet(
          tester,
          filipino: true,
          size: Size(width, 1400),
          advisories: [const Advisory(id: 1, message: 'Lumikas bago mag-alas sais ng gabi.', barangay: 'San Fabian')],
          requests: [_request('Booked', id: 1), _request('Disapproved', id: 2, remarks: 'Labas sa saklaw ng serbisyo.')],
        );

        expect(tester.takeException(), isNull);
      });
    }
  });

  group('the cancel dialog', () {
    Future<Completer<bool>> open(WidgetTester tester, {bool filipino = false, Size size = const Size(390, 800)}) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final gate = Completer<bool>();
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => showCancelDialog(context, 'TXN-000007', () => gate.future, filipino: filipino),
              child: const Text('ask'),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('ask'));
      await tester.pumpAndSettle();
      return gate;
    }

    testWidgets('both actions are 48dp tall', (tester) async {
      await open(tester);

      for (final label in ['Keep request', 'Cancel request']) {
        final button = find.ancestor(of: find.text(label), matching: find.byType(TextButton));
        expect(tester.getSize(button.last).height, greaterThanOrEqualTo(48), reason: label);
      }
    });

    testWidgets('keeps its label while the request is in flight', (tester) async {
      final gate = await open(tester);

      await tester.tap(find.text('Cancel request').last);
      await tester.pump();

      // The spinner joins the label; it does not replace it.
      expect(find.text('Cancel request'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);

      gate.complete(true);
      await tester.pumpAndSettle();
      expect(find.byType(ConfirmDialog), findsNothing);
    });

    testWidgets('stacks its two actions on a 320px phone in Filipino without overflow', (tester) async {
      await open(tester, filipino: true, size: const Size(320, 640));

      expect(find.text('Panatilihin'), findsOneWidget);
      expect(find.text('Kanselahin'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('showConfirmDialog', () {
    testWidgets('resolves true on confirm and false on keep', (tester) async {
      bool? answer;
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () async => answer = await showConfirmDialog(
                context,
                title: 'Discard this request?',
                body: 'What you entered will be cleared.',
                keepLabel: 'Keep editing',
                confirmLabel: 'Discard',
              ),
              child: const Text('ask'),
            ),
          ),
        ),
      ));

      await tester.tap(find.text('ask'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Keep editing'));
      await tester.pumpAndSettle();
      expect(answer, isFalse);

      await tester.tap(find.text('ask'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Discard'));
      await tester.pumpAndSettle();
      expect(answer, isTrue);
    });
  });
}
