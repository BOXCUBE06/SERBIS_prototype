import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// One emergency contact, with every number that reaches it.
///
/// There used to be three independent literal copies of this list — the SOS
/// sheet, the Library's hotline card and the Services safety notice — and they
/// had already drifted apart. Only the SOS sheet carried MDRRMO's second duty
/// line and the PNP/BFP mobile numbers; the other two showed a shorter list
/// without saying it was shorter. One list now feeds all three, so a number
/// changes in one place.
class Hotline {
  final String label;
  final String labelFil;

  /// Every number that reaches this contact, most direct first. Surfaces render
  /// the whole list rather than picking one, so none of them can quietly show a
  /// subset of what is reachable again.
  final List<String> numbers;

  final IconData icon;
  final Color iconBg;
  final Color iconFg;

  const Hotline({
    required this.label,
    required this.labelFil,
    required this.numbers,
    required this.icon,
    required this.iconBg,
    required this.iconFg,
  });

  String labelFor({required bool filipino}) => filipino ? labelFil : label;

  /// e.g. `0917-123-4567 · 0943-132-0604`.
  String get numbersLine => numbers.join(' · ');
}

/// The emergency contacts shown on every surface that lists them.
///
/// **These are compiled into the app**, which is deliberate — they must be
/// readable with no signal — but it also means correcting a duty number needs a
/// new release, and until it ships the app confidently displays a number nobody
/// answers. M28's remaining half is a `GET /api/hotlines` cached through the
/// offline layer, with this list as the fallback when the cache is empty.
const List<Hotline> kHotlines = [
  Hotline(
    label: 'MDRRMO',
    labelFil: 'MDRRMO',
    numbers: ['0917-123-4567', '0943-132-0604'],
    icon: Icons.shield_outlined,
    iconBg: AppColors.red50,
    iconFg: AppColors.red600,
  ),
  Hotline(
    label: 'Police (PNP)',
    labelFil: 'Pulis (PNP)',
    numbers: ['0917-681-6913', '117'],
    icon: Icons.local_police_outlined,
    iconBg: AppColors.blue50,
    iconFg: AppColors.blue600,
  ),
  Hotline(
    label: 'Fire (BFP)',
    labelFil: 'Bumbero (BFP)',
    numbers: ['0917-500-2585', '116'],
    icon: Icons.local_fire_department_outlined,
    iconBg: AppColors.amber50,
    iconFg: AppColors.amber600,
  ),
  Hotline(
    label: 'National Emergency',
    labelFil: 'Pambansang Emerhensiya',
    numbers: ['911'],
    icon: Icons.warning_amber_rounded,
    iconBg: AppColors.red50,
    iconFg: AppColors.red600,
  ),
];
