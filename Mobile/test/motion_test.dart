import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/motion.dart';

Widget _app(Widget home, {bool reduce = false}) => MaterialApp(
      theme: buildAppTheme(),
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: reduce),
        child: child!,
      ),
      home: home,
    );

class _Opener extends StatelessWidget {
  const _Opener();

  @override
  Widget build(BuildContext context) => Scaffold(
        body: Center(
          child: TextButton(
            onPressed: () => Navigator.of(context).push(
              serbisRoute<void>((_) => const Scaffold(body: Center(child: Text('form page')))),
            ),
            child: const Text('open'),
          ),
        ),
      );
}

double _opacityOf(WidgetTester tester, Finder of) {
  final fade = tester.widget<FadeTransition>(
    find.ancestor(of: of, matching: find.byType(FadeTransition)).first,
  );
  return fade.opacity.value;
}

void main() {
  test('every duration sits inside the 200-300ms window, and leaving is quicker than arriving', () {
    for (final d in [kMotionEnter, kMotionExit]) {
      expect(d.inMilliseconds, inInclusiveRange(200, 300));
    }
    expect(kMotionExit, lessThan(kMotionEnter));
    expect(kMotionTap.inMilliseconds, lessThan(200));
  });

  group('page transition', () {
    testWidgets('a forward page fades in over 280ms and lands fully opaque', (tester) async {
      await tester.pumpWidget(_app(const _Opener()));

      await tester.tap(find.text('open'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 40));

      // Mid-flight: present but not yet solid.
      expect(_opacityOf(tester, find.text('form page')), lessThan(1));

      await tester.pumpAndSettle();
      expect(_opacityOf(tester, find.text('form page')), 1);
    });

    testWidgets('it is done inside 300ms', (tester) async {
      await tester.pumpWidget(_app(const _Opener()));

      await tester.tap(find.text('open'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('going back leaves quicker than it arrived', (tester) async {
      await tester.pumpWidget(_app(const _Opener()));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      Navigator.of(tester.element(find.text('form page'))).pop();
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 220));

      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('with animations removed the page is there at once, with no fade at all', (tester) async {
      await tester.pumpWidget(_app(const _Opener(), reduce: true));

      await tester.tap(find.text('open'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 20));

      expect(find.text('form page'), findsOneWidget);
      expect(
        find.ancestor(of: find.text('form page'), matching: find.byType(SlideTransition)),
        findsNothing,
      );
    });
  });

  group('tab fade', () {
    Widget host(int index, {bool reduce = false}) => _app(
          TabFade(
            index: index,
            children: const [
              Center(child: Text('home tab')),
              Center(child: Text('services tab')),
            ],
          ),
          reduce: reduce,
        );

    testWidgets('the old tab fades out, then the new one fades in', (tester) async {
      await tester.pumpWidget(host(0));
      expect(find.text('home tab'), findsOneWidget);

      await tester.pumpWidget(host(1));
      await tester.pump(const Duration(milliseconds: 30));
      // Still the tab being left, part way faded.
      expect(find.text('home tab'), findsOneWidget);
      expect(find.text('services tab'), findsNothing);

      await tester.pump(const Duration(milliseconds: 120));
      expect(find.text('services tab'), findsOneWidget);
      expect(find.text('home tab'), findsNothing);

      await tester.pumpAndSettle();
      expect(tester.widget<Opacity>(find.byType(Opacity).first).opacity, 1);
    });

    testWidgets('the whole change takes about a quarter of a second', (tester) async {
      await tester.pumpWidget(host(0));
      await tester.pumpWidget(host(1));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 270));

      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('with animations removed the tab changes in the same frame', (tester) async {
      await tester.pumpWidget(host(0, reduce: true));
      await tester.pumpWidget(host(1, reduce: true));

      expect(find.text('services tab'), findsOneWidget);
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('a tab keeps its state while another is showing', (tester) async {
      final controller = TextEditingController(text: 'typed');
      addTearDown(controller.dispose);

      Widget with_(int index) => _app(TabFade(
            index: index,
            children: [
              Scaffold(body: Center(child: TextField(controller: controller))),
              const Center(child: Text('other')),
            ],
          ));

      await tester.pumpWidget(with_(0));
      await tester.pumpWidget(with_(1));
      await tester.pumpAndSettle();
      await tester.pumpWidget(with_(0));
      await tester.pumpAndSettle();

      expect(find.text('typed'), findsOneWidget);
    });
  });

  group('tap feedback', () {
    Widget tile({bool reduce = false}) => _app(
          Scaffold(
            body: Center(
              child: PressableScale(
                child: SizedBox(
                  width: 100,
                  height: 100,
                  child: Material(child: InkWell(onTap: () {}, child: const Text('tile'))),
                ),
              ),
            ),
          ),
          reduce: reduce,
        );

    double scale(WidgetTester tester) =>
        tester.widget<AnimatedScale>(find.byType(AnimatedScale)).scale;

    testWidgets('a finger down shrinks the tile slightly and lifting restores it', (tester) async {
      await tester.pumpWidget(tile());
      expect(scale(tester), 1);

      final gesture = await tester.startGesture(tester.getCenter(find.text('tile')));
      await tester.pump();
      expect(scale(tester), 0.97);

      await gesture.up();
      await tester.pumpAndSettle();
      expect(scale(tester), 1);
    });

    testWidgets('the tap still reaches the child', (tester) async {
      var taps = 0;
      await tester.pumpWidget(_app(
        Scaffold(
          body: Center(
            child: PressableScale(
              child: Material(child: InkWell(onTap: () => taps++, child: const SizedBox(width: 80, height: 80, child: Text('tile')))),
            ),
          ),
        ),
      ));

      await tester.tap(find.text('tile'));
      expect(taps, 1);
    });

    testWidgets('with animations removed there is no shrink', (tester) async {
      await tester.pumpWidget(tile(reduce: true));

      final gesture = await tester.startGesture(tester.getCenter(find.text('tile')));
      await tester.pump();
      expect(scale(tester), 1);
      await gesture.up();
    });
  });
}
