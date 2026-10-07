// M18: `service_widgets.dart` was 19.8% covered — the whole file except
// `SafetyNotice`, which `hotlines_test.dart` reaches through `ServicesScreen`.
// Every other class in it is on the request path a resident actually walks:
// attach a file, pick a service, fail to submit, succeed and read the reference
// number.
//
// The tests worth having here are the ones that pin a decision the widget's
// shape depends on, and the file documents four of them:
//
//  * the clear button is its own tap target, so clearing does not also reopen
//    the file picker;
//  * an optional attachment gets a way back to "none", a required one does not;
//  * the service subtitle has no `maxLines` and no ellipsis, so a long Tagalog
//    blurb grows the tile instead of being cut;
//  * an odd-length catalogue leaves a hole in the last row rather than a
//    double-width tile.
//
// Each of those has a test that fails if it is undone.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/service_widgets.dart';
import 'package:serbis/widgets/status_line.dart';

Future<void> _pump(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(1080, 4800);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(body: SingleChildScrollView(child: child)),
  ));
  await tester.pumpAndSettle();
}

/// The outermost `Container` a widget draws, which is the one carrying the
/// selected/unselected decoration.
BoxDecoration _outerDecoration(WidgetTester tester, Type widget) {
  final container = tester.widget<Container>(
    find.descendant(of: find.byType(widget), matching: find.byType(Container)).first,
  );
  return container.decoration! as BoxDecoration;
}

void main() {
  group('AttachmentUploadField', () {
    testWidgets('with nothing attached it says what it takes and offers Choose file',
        (tester) async {
      await _pump(
        tester,
        AttachmentUploadField(
          label: 'Valid ID',
          hint: 'Tap to attach a photo of your ID',
          fileName: null,
          onTap: () {},
        ),
      );

      expect(find.text('Valid ID'), findsOneWidget);
      expect(find.text('Tap to attach a photo of your ID'), findsOneWidget);
      expect(find.text('Choose file'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle_rounded), findsNothing);
      // Nothing to take back off yet.
      expect(find.byIcon(Icons.close_rounded), findsNothing);
    });

    testWidgets('with a file attached it names the file and offers another',
        (tester) async {
      await _pump(
        tester,
        AttachmentUploadField(
          label: 'Valid ID',
          hint: 'Tap to attach a photo of your ID',
          fileName: 'drivers-licence.jpg',
          onTap: () {},
        ),
      );

      expect(find.text('drivers-licence.jpg'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle_rounded), findsOneWidget);
      expect(find.text('Choose another file'), findsOneWidget);
      expect(find.text('Choose file'), findsNothing);
    });

    testWidgets('a required attachment offers no way to clear it',
        (tester) async {
      // No `onClear`: the valid ID has to be there, so the only move is to
      // replace it. An X here would let a resident empty a field the form
      // refuses to submit without.
      await _pump(
        tester,
        AttachmentUploadField(
          label: 'Valid ID',
          hint: 'hint',
          fileName: 'id.jpg',
          onTap: () {},
        ),
      );

      expect(find.byIcon(Icons.close_rounded), findsNothing);
    });

    testWidgets('an optional attachment can be taken back off', (tester) async {
      await _pump(
        tester,
        AttachmentUploadField(
          label: 'Photo of the scene',
          hint: 'hint',
          fileName: 'flooded-road.jpg',
          onTap: () {},
          onClear: () {},
        ),
      );

      expect(find.byIcon(Icons.close_rounded), findsOneWidget);
      expect(find.byTooltip('Remove Photo of the scene'), findsOneWidget);
    });

    testWidgets('tapping the field asks for a file', (tester) async {
      var taps = 0;
      await _pump(
        tester,
        AttachmentUploadField(
          label: 'Valid ID',
          hint: 'hint',
          fileName: null,
          onTap: () => taps++,
        ),
      );

      await tester.tap(find.byType(InkWell));
      await tester.pumpAndSettle();

      expect(taps, 1);
    });

    testWidgets('clearing does not also reopen the picker', (tester) async {
      // The clear button sits inside the field's InkWell but is its own tap
      // target, so the tap stops there instead of falling through to the
      // picker. If it is ever demoted to a bare Icon, this test sees both
      // callbacks fire and the file browser opens on top of the form the
      // resident was trying to correct.
      var taps = 0;
      var clears = 0;
      await _pump(
        tester,
        AttachmentUploadField(
          label: 'Photo of the scene',
          hint: 'hint',
          fileName: 'wrong-photo.jpg',
          onTap: () => taps++,
          onClear: () => clears++,
        ),
      );

      await tester.tap(find.byIcon(Icons.close_rounded));
      await tester.pumpAndSettle();

      expect(clears, 1);
      expect(taps, 0, reason: 'clearing reopened the file picker');
    });
  });

  group('ServiceTypeCard', () {
    Widget card({
      String title = 'Ambulance',
      String subtitle = 'Medical transport',
      bool selected = false,
      VoidCallback? onTap,
    }) =>
        ServiceTypeCard(
          title: title,
          subtitle: subtitle,
          icon: Icons.local_hospital_outlined,
          selected: selected,
          onTap: onTap ?? () {},
        );

    testWidgets('shows its name and blurb', (tester) async {
      await _pump(tester, card());

      expect(find.text('Ambulance'), findsOneWidget);
      expect(find.text('Medical transport'), findsOneWidget);
      expect(find.byIcon(Icons.local_hospital_outlined), findsOneWidget);
    });

    testWidgets('a service with no blurb renders no empty second line',
        (tester) async {
      await _pump(tester, card(subtitle: ''));

      expect(find.text('Ambulance'), findsOneWidget);
      expect(find.text(''), findsNothing);
    });

    testWidgets('selection is carried by the border and fill, not by the text',
        (tester) async {
      await _pump(tester, card(selected: false));
      final unselected = _outerDecoration(tester, ServiceTypeCard);

      await _pump(tester, card(selected: true));
      final selected = _outerDecoration(tester, ServiceTypeCard);

      expect(unselected.color, AppColors.surface);
      expect(selected.color, AppColors.green50);
      expect(
        (selected.border! as Border).top.color,
        isNot((unselected.border! as Border).top.color),
      );
    });

    testWidgets('tapping it reports the choice', (tester) async {
      var taps = 0;
      await _pump(tester, card(onTap: () => taps++));

      await tester.tap(find.byType(ServiceTypeCard));
      await tester.pumpAndSettle();

      expect(taps, 1);
    });

    testWidgets('a long blurb grows the tile instead of being cut',
        (tester) async {
      // Deliberate, and commented in the widget: capping at one line cut every
      // Tagalog blurb mid-word and two still truncated the longest. Yogad is
      // coming and will be longer again.
      const long = 'Paghahatid ng pasyente sa ospital at iba pang pangangailangang '
          'medikal na kailangan ng agarang transportasyon mula sa barangay.';
      await _pump(tester, card(subtitle: long));

      final blurb = tester.widget<Text>(find.text(long));
      expect(blurb.maxLines, isNull);
      expect(blurb.overflow, isNull);
    });
  });

  group('SubmitErrorCard', () {
    testWidgets('says the request was never filed and keeps the details',
        (tester) async {
      await _pump(tester, SubmitErrorCard(filipino: false, onRetry: () {}));

      expect(find.textContaining("wasn't sent"), findsOneWidget);
      expect(find.textContaining('still here'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('speaks Filipino when the app does', (tester) async {
      await _pump(tester, SubmitErrorCard(filipino: true, onRetry: () {}));

      expect(find.textContaining('Hindi naipadala'), findsOneWidget);
      expect(find.text('Subukang muli'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);
    });

    testWidgets('Retry asks the screen to send it again', (tester) async {
      var retries = 0;
      await _pump(tester, SubmitErrorCard(filipino: false, onRetry: () => retries++));

      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(retries, 1);
    });
  });

  group('ConfirmationSheet', () {
    ServiceRequest filed({String refNo = 'SR-2026-0042', ReqStatus status = ReqStatus.review, DateTime? scheduledAt}) =>
        ServiceRequest(
          serviceId: 1,
          description: '',
          type: ServiceType.road,
          refNo: refNo,
          status: status,
          metaLines: const [],
          createdAt: DateTime(2026, 9, 30, 9, 14),
          scheduledAt: scheduledAt,
        );

    testWidgets("names what was sent, the server's reference and its status", (tester) async {
      await _pump(
        tester,
        ConfirmationSheet(request: filed(), title: 'Road clearing', filipino: false, onViewTrack: () {}),
      );

      expect(find.text('Request sent'), findsOneWidget);
      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.textContaining('SR-2026-0042'), findsOneWidget);
      // The same words Track will show for it.
      expect(find.byType(StatusLine), findsOneWidget);
      expect(find.text('Under review'), findsOneWidget);
      expect(find.textContaining('Updated'), findsOneWidget);
      expect(find.text('View in Track'), findsOneWidget);
      expect(find.text('Done'), findsOneWidget);
    });

    testWidgets('reads in Filipino too', (tester) async {
      await _pump(
        tester,
        ConfirmationSheet(request: filed(refNo: 'SR-2026-0043'), title: 'Paglinis ng daan', filipino: true, onViewTrack: () {}),
      );

      expect(find.text('Naipadala ang kahilingan'), findsOneWidget);
      expect(find.textContaining('SR-2026-0043'), findsOneWidget);
      expect(find.text('Sinusuri'), findsOneWidget);
      expect(find.text('Tingnan sa Track'), findsOneWidget);
      expect(find.text('Tapos na'), findsOneWidget);
    });

    testWidgets('a booking still under review adds its time', (tester) async {
      await _pump(
        tester,
        ConfirmationSheet(
          request: filed(scheduledAt: DateTime(2026, 10, 12, 15, 30)),
          title: 'Ambulance',
          filipino: false,
          onViewTrack: () {},
        ),
      );

      expect(find.text('Scheduled for Oct 12, 2026, 3:30 PM'), findsOneWidget);
    });

    testWidgets('a Booked status names the time once, in its own line', (tester) async {
      await _pump(
        tester,
        ConfirmationSheet(
          request: filed(status: ReqStatus.booked, scheduledAt: DateTime(2026, 10, 12, 15, 30)),
          title: 'Ambulance',
          filipino: false,
          onViewTrack: () {},
        ),
      );

      expect(find.text('Booked'), findsOneWidget);
      expect(find.textContaining('Oct 12, 2026, 3:30 PM'), findsOneWidget);
    });

    testWidgets('keeps its content to 600dp on a wide screen', (tester) async {
      tester.view.physicalSize = const Size(1600, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: ConfirmationSheet(request: filed(), title: 'Road clearing', filipino: false, onViewTrack: () {}),
        ),
      ));

      expect(tester.getSize(find.byType(StatusLine)).width, lessThanOrEqualTo(600));
    });

    testWidgets('View in Track hands the resident to the Track tab', (tester) async {
      var opened = 0;
      await _pump(
        tester,
        ConfirmationSheet(request: filed(), title: 'Road clearing', filipino: false, onViewTrack: () => opened++),
      );

      await tester.tap(find.text('View in Track'));
      await tester.pumpAndSettle();

      expect(opened, 1);
    });

    for (final filipino in [false, true]) {
      testWidgets('fits a 320px phone without overflow (${filipino ? 'Filipino' : 'English'})', (tester) async {
        tester.view.physicalSize = const Size(320, 900);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        await tester.pumpWidget(MaterialApp(
          theme: buildAppTheme(),
          home: Scaffold(
            body: ConfirmationSheet(
              request: filed(scheduledAt: DateTime(2026, 10, 12, 15, 30)),
              title: 'Simulation drills / NSED',
              filipino: filipino,
              onViewTrack: () {},
            ),
          ),
        ));

        expect(tester.takeException(), isNull);
      });
    }
  });

  group('ServiceGrid', () {
    /// Records which indices the grid asked for, so a grid that quietly builds
    /// an extra card to fill a row cannot pass.
    late List<int> built;

    Widget grid(int count) {
      built = <int>[];
      return ServiceGrid(
        count: count,
        cardBuilder: (i) {
          built.add(i);
          return Text('card $i');
        },
      );
    }

    testWidgets('an even catalogue fills both columns of every row',
        (tester) async {
      await _pump(tester, grid(6));

      expect(built, [0, 1, 2, 3, 4, 5]);
      for (var i = 0; i < 6; i++) {
        expect(find.text('card $i'), findsOneWidget);
      }
      expect(find.byType(IntrinsicHeight), findsNWidgets(3));
    });

    testWidgets('an odd catalogue leaves a hole, not a double-width tile',
        (tester) async {
      await _pump(tester, grid(5));

      // Five cards, five builder calls. A sixth would mean the grid invented a
      // tile to fill the gap.
      expect(built, [0, 1, 2, 3, 4]);
      expect(find.text('card 4'), findsOneWidget);
      expect(find.text('card 5'), findsNothing);

      // The last row still has two columns — the odd card keeps its half and
      // the other half is empty. A row that dropped the empty Expanded would
      // stretch the last tile across the full width.
      final lastRow = find.byType(IntrinsicHeight).last;
      expect(
        find.descendant(of: lastRow, matching: find.byType(Expanded)),
        findsNWidgets(2),
      );
    });

    testWidgets('a single service still renders as one half-row',
        (tester) async {
      await _pump(tester, grid(1));

      expect(built, [0]);
      expect(find.text('card 0'), findsOneWidget);
      expect(find.byType(IntrinsicHeight), findsOneWidget);
    });

    testWidgets('an empty catalogue renders no rows at all', (tester) async {
      await _pump(tester, grid(0));

      expect(built, isEmpty);
      expect(find.byType(IntrinsicHeight), findsNothing);
    });
  });
}
