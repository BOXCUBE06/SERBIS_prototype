import 'package:url_launcher/url_launcher.dart';

/// One phone number for a contact, with an optional carrier/line label.
///
/// Only the Echague Rescue Hotline has more than one carrier reaching the
/// same desk — landline, Globe, Smart, Sun each ring a different line, so a
/// resident on Smart cannot tell which of four bare numbers to dial without
/// the label. Every other contact's numbers are unlabeled.
typedef HotlineNumber = ({String? label, String number});

/// One emergency contact, with every number that reaches it.
///
/// There used to be three independent literal copies of this list — the SOS
/// sheet, the Library's hotline card and the Services safety notice — and they
/// had already drifted apart. Only the SOS sheet carried MDRRMO's second duty
/// line and the PNP/BFP mobile numbers; the other two showed a shorter list
/// without saying it was shorter. One list now feeds all three, so a number
/// changes in one place.
///
/// The SOS sheet itself is gone (removed app-wide) — this now feeds the
/// Library's hotline card and the Services safety notice.
///
/// There is no "summarise these onto one line" helper any more. Both surfaces
/// render one number per row: the joined `Label — n1 · n2 · n3` form the
/// safety notice used wrapped into an unreadable paragraph, and a resident
/// cannot tap a number that is part of a sentence.
class Hotline {
  final String label;
  final String labelFil;

  /// Every number that reaches this contact, most direct first. Surfaces render
  /// the whole list rather than picking one, so none of them can quietly show a
  /// subset of what is reachable again.
  final List<HotlineNumber> numbers;

  const Hotline({
    required this.label,
    required this.labelFil,
    required this.numbers,
  });

  String labelFor({required bool filipino}) => filipino ? labelFil : label;

  /// One row of `GET /api/hotlines` or of the cache. Null when unusable (no
  /// name, or no number to dial), so a bad row is skipped, not shown blank.
  static Hotline? fromJson(Object? json) {
    if (json is! Map) return null;
    final label = (json['label'] as String?)?.trim() ?? '';
    final numbers = <HotlineNumber>[];
    for (final n in (json['numbers'] as List?) ?? const []) {
      if (n is! Map) continue;
      final number = (n['number'] as String?)?.trim() ?? '';
      final line = (n['label'] as String?)?.trim() ?? '';
      if (number.isEmpty) continue;
      numbers.add((label: line.isEmpty ? null : line, number: number));
    }
    if (label.isEmpty || numbers.isEmpty) return null;

    final labelFil = (json['label_fil'] as String?)?.trim() ?? '';
    return Hotline(
      label: label,
      labelFil: labelFil.isEmpty ? label : labelFil,
      numbers: numbers,
    );
  }

  /// Same shape as the API row, so the cache reads back through [fromJson].
  Map<String, dynamic> toCacheJson() => {
        'label': label,
        'label_fil': labelFil,
        'numbers': [
          for (final n in numbers) {'label': n.label, 'number': n.number},
        ],
      };
}

/// Dials a hotline number directly. One number at a time — a contact with
/// several lines is not asking which one to ring in aggregate, a resident
/// picks the one that reaches them (their own carrier, or the landline).
Future<void> callHotlineNumber(String number) async {
  await launchUrl(Uri(scheme: 'tel', path: number));
}

/// The emergency contacts shown on every surface that lists them.
///
/// **The built-in fallback.** The live list comes from `GET /api/hotlines`
/// (editable in the admin panel) and is cached by `HotlineCache`; this one is
/// shown only until a first fetch succeeds, so a fresh install with no signal
/// still has numbers to dial. Keep it in step with the seed migration
/// (2026_09_30_100000_create_tbl_emergency_hotlines).
const List<Hotline> kHotlines = [
  Hotline(
    label: 'Echague Rescue Hotline',
    labelFil: 'Echague Rescue Hotline',
    numbers: [
      (label: 'Landline', number: '(078) 324-5410'),
      (label: 'Globe', number: '0917-626-2352'),
      (label: 'Smart', number: '0919-991-7115'),
      (label: 'Sun', number: '0933-868-2526'),
    ],
  ),
  Hotline(
    label: 'PDRRMO',
    labelFil: 'PDRRMO',
    numbers: [
      (label: null, number: '(078) 323-0416'),
      (label: null, number: '0921-585-2341'),
    ],
  ),
  Hotline(
    label: 'ISELCO I',
    labelFil: 'ISELCO I',
    numbers: [(label: null, number: '0955-698-1059')],
  ),
  Hotline(
    label: 'BFP',
    labelFil: 'BFP',
    numbers: [(label: null, number: '(02) 426-3812')],
  ),
  Hotline(
    label: 'National Emergency',
    labelFil: 'Pambansang Emerhensiya',
    numbers: [(label: null, number: '911')],
  ),
];
