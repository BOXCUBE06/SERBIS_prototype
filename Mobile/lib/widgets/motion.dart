import 'package:flutter/material.dart';

// How the app moves. Short and decelerating: a screen arrives quickly and
// settles, it does not bounce or linger. Every helper here honours the
// system's "remove animations" setting by cutting instead of moving.

/// A page or tab arriving.
const Duration kMotionEnter = Duration(milliseconds: 280);

/// A page leaving. Faster than the arrival: the resident has already decided.
const Duration kMotionExit = Duration(milliseconds: 200);

/// Feedback under a finger.
const Duration kMotionTap = Duration(milliseconds: 120);

/// Fast start, long soft landing (ease-out expo). No overshoot.
const Curve kEaseOut = Cubic(0.16, 1, 0.3, 1);

/// Accelerating, for things leaving.
const Curve kEaseIn = Cubic(0.7, 0, 0.84, 0);

/// True when the resident has asked the device to remove animations.
bool reduceMotion(BuildContext context) =>
    MediaQuery.maybeDisableAnimationsOf(context) ?? false;

/// A forward page: it fades in while sliding a short way from the right, and the
/// page it covers eases a little to the left. With animations removed the page
/// is simply there.
Route<T> serbisRoute<T>(WidgetBuilder builder) {
  return PageRouteBuilder<T>(
    transitionDuration: kMotionEnter,
    reverseTransitionDuration: kMotionExit,
    pageBuilder: (context, _, __) => builder(context),
    transitionsBuilder: (context, animation, secondaryAnimation, child) {
      if (reduceMotion(context)) return child;

      final incoming = CurvedAnimation(
          parent: animation, curve: kEaseOut, reverseCurve: kEaseIn);
      final covered = CurvedAnimation(
          parent: secondaryAnimation, curve: kEaseOut, reverseCurve: kEaseIn);

      return SlideTransition(
        position: Tween<Offset>(begin: Offset.zero, end: const Offset(-0.05, 0))
            .animate(covered),
        child: FadeTransition(
          opacity: incoming,
          child: SlideTransition(
            position:
                Tween<Offset>(begin: const Offset(0.08, 0), end: Offset.zero)
                    .animate(incoming),
            child: child,
          ),
        ),
      );
    },
  );
}

/// The shell's tab stack with a fade-through between tabs: the tab being left
/// fades out, then the new one fades in while rising a few pixels. Every tab
/// stays built, so scroll position and typed text survive a visit elsewhere.
class TabFade extends StatefulWidget {
  final int index;
  final List<Widget> children;

  const TabFade({super.key, required this.index, required this.children});

  @override
  State<TabFade> createState() => _TabFadeState();
}

class _TabFadeState extends State<TabFade> with SingleTickerProviderStateMixin {
  /// Where in the animation the outgoing tab has fully faded and the incoming
  /// one takes over.
  static const double _swap = 0.35;

  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 250),
  )..addListener(_maybeSwap);

  late int _shown = widget.index;

  late final Animation<double> _opacity = TweenSequence<double>([
    TweenSequenceItem(
      tween: Tween<double>(begin: 1, end: 0).chain(CurveTween(curve: kEaseIn)),
      weight: _swap * 100,
    ),
    TweenSequenceItem(
      tween: Tween<double>(begin: 0, end: 1).chain(CurveTween(curve: kEaseOut)),
      weight: (1 - _swap) * 100,
    ),
  ]).animate(_controller);

  late final Animation<double> _rise = TweenSequence<double>([
    TweenSequenceItem(tween: ConstantTween<double>(0), weight: _swap * 100),
    TweenSequenceItem(
      tween: Tween<double>(begin: 8, end: 0).chain(CurveTween(curve: kEaseOut)),
      weight: (1 - _swap) * 100,
    ),
  ]).animate(_controller);

  void _maybeSwap() {
    if (_shown != widget.index && _controller.value >= _swap) {
      setState(() => _shown = widget.index);
    }
  }

  @override
  void didUpdateWidget(covariant TabFade old) {
    super.didUpdateWidget(old);
    if (old.index == widget.index) return;

    if (reduceMotion(context)) {
      _controller.value = 0;
      setState(() => _shown = widget.index);
      return;
    }

    // A second tap before the first fade finishes goes straight to the arrival
    // half, so the bar never feels slower than the resident's thumb.
    if (_controller.isAnimating) {
      setState(() => _shown = widget.index);
      _controller.forward(from: _swap);
    } else {
      _controller.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) => IgnorePointer(
        // A tap on the tab that is fading out would land on a screen about to
        // be gone.
        ignoring: _controller.isAnimating && _controller.value < _swap,
        child: Opacity(
          opacity: _controller.isAnimating ? _opacity.value : 1,
          child: Transform.translate(
            offset: Offset(0, _controller.isAnimating ? _rise.value : 0),
            child: child,
          ),
        ),
      ),
      child: IndexedStack(index: _shown, children: widget.children),
    );
  }
}

/// Shrinks its child a little while a finger is down on it, and springs back on
/// release. It listens to raw pointer events rather than taking a gesture, so
/// the child's own ink splash and tap still work untouched.
class PressableScale extends StatefulWidget {
  final Widget child;

  const PressableScale({super.key, required this.child});

  @override
  State<PressableScale> createState() => _PressableScaleState();
}

class _PressableScaleState extends State<PressableScale> {
  bool _down = false;

  void _set(bool down) {
    if (_down != down && mounted) setState(() => _down = down);
  }

  @override
  Widget build(BuildContext context) {
    final still = reduceMotion(context);

    return Listener(
      onPointerDown: (_) => _set(true),
      onPointerUp: (_) => _set(false),
      onPointerCancel: (_) => _set(false),
      child: AnimatedScale(
        scale: _down && !still ? 0.97 : 1,
        duration: still ? Duration.zero : kMotionTap,
        curve: kEaseOut,
        child: widget.child,
      ),
    );
  }
}
