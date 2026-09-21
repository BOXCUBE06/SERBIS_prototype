import 'package:flutter/material.dart';

import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'motion.dart';

/// The shell's bottom bar.
class AppBottomNav extends StatelessWidget {
  final int index;
  final ValueChanged<int> onTap;
  final bool filipino;

  const AppBottomNav({super.key, required this.index, required this.onTap, required this.filipino});

  /// Five destinations: Material 3's ceiling, and the most a 360dp phone can
  /// label in words without truncating ("Ambulance" is the widest).
  static const _items = [
    (Icons.home_rounded, Icons.home_outlined, 'nav.home'),
    (Icons.medical_services_rounded, Icons.medical_services_outlined, 'nav.ambulance'),
    (Icons.grid_view_rounded, Icons.grid_view_outlined, 'nav.services'),
    (Icons.inventory_2_rounded, Icons.inventory_2_outlined, 'nav.borrow'),
    (Icons.fact_check_rounded, Icons.fact_check_outlined, 'nav.track'),
  ];

  @override
  Widget build(BuildContext context) {
    final duration = reduceMotion(context) ? Duration.zero : kMotionExit;

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.line)),
      ),
      child: SafeArea(
        top: false,
        // The labels are sized to fit the bar, so system text scaling is capped
        // here rather than allowed to truncate a destination's name.
        child: MediaQuery.withClampedTextScaling(
          maxScaleFactor: 1.15,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(4, 6, 4, 6),
            child: Row(
              children: List.generate(_items.length, (i) {
                final (filled, outline, key) = _items[i];
                final active = i == index;
                final label = tr(filipino, key);
                return Expanded(
                  child: Semantics(
                    button: true,
                    selected: active,
                    label: label,
                    onTap: () => onTap(i),
                    excludeSemantics: true,
                    child: InkWell(
                      onTap: () => onTap(i),
                      borderRadius: BorderRadius.circular(16),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(minHeight: 56),
                        child: Column(
                          // Min, not the default max: a bar slot is laid out with
                          // the whole screen's height available, and a max Column
                          // filled all of it.
                          mainAxisSize: MainAxisSize.min,
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            // The indicator grows out of the icon rather than
                            // switching on, so the eye follows the selection.
                            AnimatedContainer(
                              duration: duration,
                              curve: kEaseOut,
                              width: active ? 52 : 28,
                              height: 28,
                              decoration: BoxDecoration(
                                color: active ? AppColors.green50 : Colors.transparent,
                                borderRadius: BorderRadius.circular(14),
                              ),
                              alignment: Alignment.center,
                              child: Icon(
                                active ? filled : outline,
                                size: 22,
                                color: active ? AppColors.green700 : AppColors.inkMuted,
                              ),
                            ),
                            const SizedBox(height: 3),
                            Padding(
                              padding: const EdgeInsets.symmetric(horizontal: 2),
                              child: FittedBox(
                                fit: BoxFit.scaleDown,
                                child: AnimatedDefaultTextStyle(
                                  duration: duration,
                                  curve: kEaseOut,
                                  style: AppText.display(
                                    size: 11.5,
                                    weight: active ? FontWeight.w700 : FontWeight.w500,
                                    color: active ? AppColors.green700 : AppColors.inkMuted,
                                  ),
                                  child: Text(label, maxLines: 1),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    );
  }
}
