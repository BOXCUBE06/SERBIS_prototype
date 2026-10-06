import 'package:flutter/material.dart';

import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'motion.dart' show reduceMotion;

/// The shape of what is coming instead of a spinner in the middle of the
/// screen. A light sweep runs across the blocks; with "reduce motion" on they
/// stand still.
///
/// The sweep repeats forever, so a widget test that shows one must `pump`, not
/// `pumpAndSettle`, unless it sets `disableAnimations`.
class SkeletonRows extends StatelessWidget {
  final int count;
  final bool filipino;

  const SkeletonRows({super.key, this.count = 3, this.filipino = false});

  @override
  Widget build(BuildContext context) {
    return _Loading(
      filipino: filipino,
      child: Container(
        decoration: _cardDecoration,
        clipBehavior: Clip.antiAlias,
        child: Column(
          children: [
            for (var i = 0; i < count; i++)
              Container(
                constraints: const BoxConstraints(minHeight: 72),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: i == 0
                    ? null
                    : const BoxDecoration(border: Border(top: BorderSide(color: AppColors.divider))),
                child: const Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _Block(widthFactor: .6, height: 14),
                    SizedBox(height: 10),
                    _Block(widthFactor: .35, height: 12),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// A request card while it loads: title, meta, status, two lines of detail.
class SkeletonCard extends StatelessWidget {
  final bool filipino;
  const SkeletonCard({super.key, this.filipino = false});

  @override
  Widget build(BuildContext context) {
    return _Loading(
      filipino: filipino,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.lg),
        decoration: _cardDecoration,
        child: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Block(widthFactor: .7, height: 18),
            SizedBox(height: 14),
            _Block(widthFactor: .5, height: 12),
            SizedBox(height: 20),
            _Block(widthFactor: .4, height: 16),
            SizedBox(height: 14),
            _Block(widthFactor: .9, height: 12),
            SizedBox(height: 14),
            _Block(widthFactor: .75, height: 12),
          ],
        ),
      ),
    );
  }
}

/// A photo on its way up: what, how much, a Cancel, and a bar.
class UploadProgressRow extends StatelessWidget {
  /// What is being sent, e.g. "valid ID".
  final String name;
  final int sentBytes;
  final int totalBytes;
  final VoidCallback? onCancel;
  final bool filipino;

  const UploadProgressRow({
    super.key,
    required this.name,
    required this.sentBytes,
    required this.totalBytes,
    this.onCancel,
    this.filipino = false,
  });

  /// "1.2 MB", or "350 KB" under a megabyte.
  static String formatBytes(int bytes) {
    if (bytes < 1024 * 1024) return '${(bytes / 1024).round()} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final fraction = totalBytes <= 0 ? 0.0 : (sentBytes / totalBytes).clamp(0.0, 1.0);
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: _cardDecoration,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const AppSpinner(color: AppColors.green700),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      tr(f, 'upload.uploading').replaceAll('{name}', name),
                      style: AppText.display(size: AppTextSize.body),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      tr(f, 'upload.progress')
                          .replaceAll('{done}', formatBytes(sentBytes))
                          .replaceAll('{total}', formatBytes(totalBytes)),
                      style: AppText.detail(),
                    ),
                  ],
                ),
              ),
              if (onCancel != null)
                TextButton(
                  onPressed: onCancel,
                  style: TextButton.styleFrom(
                    foregroundColor: AppColors.red600,
                    minimumSize: const Size(44, 44),
                    textStyle: AppText.display(size: AppTextSize.body),
                  ),
                  child: Text(tr(f, 'common.cancel')),
                ),
            ],
          ),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(3),
            child: LinearProgressIndicator(
              value: fraction,
              minHeight: 6,
              color: AppColors.green700,
              backgroundColor: AppColors.skeleton,
            ),
          ),
        ],
      ),
    );
  }
}

/// The small "Updating…" pill at the top of a list being pulled to refresh.
class RefreshPill extends StatelessWidget {
  final bool filipino;
  const RefreshPill({super.key, this.filipino = false});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Semantics(
        liveRegion: true,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(AppRadius.pill),
            border: Border.all(color: AppColors.line),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const AppSpinner(color: AppColors.green700, size: 18),
              const SizedBox(width: 10),
              Text(tr(filipino, 'loading.updating'), style: AppText.body(size: AppTextSize.detail, color: AppColors.inkMuted)),
            ],
          ),
        ),
      ),
    );
  }
}

/// A small ring spinner that stands still when the device asks for less motion.
class AppSpinner extends StatelessWidget {
  final Color color;
  final double size;
  const AppSpinner({super.key, required this.color, this.size = 20});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CircularProgressIndicator(
        strokeWidth: 2.5,
        color: color,
        backgroundColor: color.withValues(alpha: .2),
        value: reduceMotion(context) ? .25 : null,
      ),
    );
  }
}

const _cardDecoration = BoxDecoration(
  color: AppColors.surface,
  borderRadius: BorderRadius.all(Radius.circular(AppRadius.lg)),
  border: Border.fromBorderSide(BorderSide(color: AppColors.line)),
);

class _Block extends StatelessWidget {
  final double widthFactor;
  final double height;
  const _Block({required this.widthFactor, required this.height});

  @override
  Widget build(BuildContext context) {
    return FractionallySizedBox(
      widthFactor: widthFactor,
      child: Container(
        height: height,
        decoration: BoxDecoration(color: AppColors.skeleton, borderRadius: BorderRadius.circular(6)),
      ),
    );
  }
}

/// Announces "Loading" once and runs one sweep over every block inside it.
class _Loading extends StatefulWidget {
  final Widget child;
  final bool filipino;
  const _Loading({required this.child, required this.filipino});

  @override
  State<_Loading> createState() => _LoadingState();
}

class _LoadingState extends State<_Loading> with SingleTickerProviderStateMixin {
  late final AnimationController _sweep =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (reduceMotion(context)) {
      _sweep.stop();
    } else if (!_sweep.isAnimating) {
      _sweep.repeat();
    }
  }

  @override
  void dispose() {
    _sweep.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final still = reduceMotion(context);
    return Semantics(
      label: tr(widget.filipino, 'loading.label'),
      container: true,
      child: ExcludeSemantics(
        child: still
            ? widget.child
            : AnimatedBuilder(
                animation: _sweep,
                child: widget.child,
                builder: (context, child) => ShaderMask(
                  // srcATop: the light only lands on the blocks, not around them.
                  blendMode: BlendMode.srcATop,
                  shaderCallback: (rect) => LinearGradient(
                    colors: [
                      Colors.white.withValues(alpha: 0),
                      Colors.white.withValues(alpha: .55),
                      Colors.white.withValues(alpha: 0),
                    ],
                    stops: const [.35, .5, .65],
                  ).createShader(rect.shift(Offset((_sweep.value * 2 - 1) * rect.width, 0))),
                  child: child,
                ),
              ),
      ),
    );
  }
}
