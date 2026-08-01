import 'package:flutter/material.dart';
import '../data/hotlines.dart';
import '../theme/app_theme.dart';
import 'shared_widgets.dart';


class SosSheet extends StatelessWidget {
  const SosSheet({super.key});

  static void show(BuildContext context) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => const SosSheet(),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: const EdgeInsets.fromLTRB(22, 14, 22, 28),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 36,
              height: 4,
              margin: const EdgeInsets.only(bottom: 16),
              decoration: BoxDecoration(color: AppColors.line, borderRadius: BorderRadius.circular(4)),
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              // Expanded for the same reason SectionHeader and AppButton got it:
              // a Row of Text + trailing button clips the moment the text is
              // wider than the row. Surfaced here by the widget test's fallback
              // font, which is far wider than the real typeface — so this is
              // hardening, not a confirmed overflow on a device.
              Expanded(
                child: Text('Emergency hotlines', style: AppText.display(size: 18)),
              ),
              IconButton(
                onPressed: () => Navigator.pop(context),
                icon: const Icon(Icons.close_rounded, size: 18, color: AppColors.green700),
                style: IconButton.styleFrom(backgroundColor: AppColors.green50, padding: const EdgeInsets.all(6)),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            "For life-threatening emergencies, call directly — don't wait for an in-app response.",
            style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.6),
          ),
          const SizedBox(height: 14),
          // English labels because this whole sheet is untranslated — its title
          // and its instruction above are hardcoded English too, and a list of
          // Filipino labels under an English heading reads worse than either.
          for (var i = 0; i < kHotlines.length; i++) ...[
            if (i > 0) const SizedBox(height: 8),
            _hotlineRow(context, kHotlines[i]),
          ],
        ],
      ),
    );
  }

  Widget _hotlineRow(BuildContext context, Hotline hotline) {
    final title = hotline.label;
    final sub = hotline.numbersLine;
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () {
        showAppSnackBar(context, 'Calling $title · $sub');
        Navigator.pop(context);
      },
      child: Container(
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          color: AppColors.surface,
          border: Border.all(color: AppColors.line),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          children: [
            IconBadge(
              icon: hotline.icon,
              bg: hotline.iconBg,
              fg: hotline.iconFg,
              size: 36,
              iconSize: 17,
              radius: 10,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: AppText.display(size: 13)),
                  const SizedBox(height: 2),
                  Text(sub, style: AppText.body(size: 11.5, color: AppColors.inkMuted)),
                ],
              ),
            ),
            const Icon(Icons.call_rounded, size: 18, color: AppColors.green700),
          ],
        ),
      ),
    );
  }
}


class SosFab extends StatefulWidget {
  const SosFab({super.key});

  @override
  State<SosFab> createState() => _SosFabState();
}

class _SosFabState extends State<SosFab> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: const Duration(milliseconds: 2200))
      ..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => SosSheet.show(context),
      child: Stack(
        alignment: Alignment.center,
        clipBehavior: Clip.none,
        children: [
          AnimatedBuilder(
            animation: _controller,
            builder: (_, __) {
              final t = _controller.value;
              return Opacity(
                opacity: (1 - t) * .5,
                child: Transform.scale(
                  scale: 1 + t * .25,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 11),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(30),
                      border: Border.all(color: AppColors.red600, width: 2),
                    ),
                    child: const Opacity(opacity: 0, child: _SosLabel()),
                  ),
                ),
              );
            },
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 11),
            decoration: BoxDecoration(
              color: AppColors.red600,
              borderRadius: BorderRadius.circular(30),
              boxShadow: [
                BoxShadow(color: AppColors.red600.withOpacity(.45), blurRadius: 18, offset: const Offset(0, 8)),
              ],
            ),
            child: const _SosLabel(),
          ),
        ],
      ),
    );
  }
}

class _SosLabel extends StatelessWidget {
  const _SosLabel();

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.call_rounded, size: 16, color: Colors.white),
        const SizedBox(width: 7),
        Text('SOS', style: AppText.display(size: 13, weight: FontWeight.w700, color: Colors.white, letterSpacing: 1)),
      ],
    );
  }
}
