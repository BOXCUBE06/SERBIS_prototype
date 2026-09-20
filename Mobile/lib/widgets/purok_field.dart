import 'package:flutter/material.dart';

import 'form_inputs.dart';

/// The puroks offered in the dropdown. Only the chosen text is stored
/// (`tbl_residents.street_address`), so this list can grow or change without a
/// migration; a purok that is no longer listed simply reads as "Other".
const List<String> kPurokOptions = [
  'Purok 1',
  'Purok 2',
  'Purok 3',
  'Purok 4',
  'Purok 5',
  'Purok 6',
];

const String _notSpecified = 'Not specified';
const String _other = 'Other (type it in)';

/// Purok picker: the listed puroks, plus a free-text box for anything else
/// (MDRRMO feedback, 2026-09-19). The value lives in [controller] as plain
/// text, so callers read it the same way they read any other field.
///
/// Shared between the register screen and the profile edit sheet.
class PurokField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  final bool enabled;

  const PurokField({
    super.key,
    required this.controller,
    this.label = 'Street / Purok (optional)',
    this.enabled = true,
  });

  @override
  State<PurokField> createState() => _PurokFieldState();
}

class _PurokFieldState extends State<PurokField> {
  // Whether "Other" is chosen. Starts true for a saved value that is not on
  // the list, so an address typed before the dropdown existed is shown, not lost.
  late bool _typing;

  @override
  void initState() {
    super.initState();
    final text = widget.controller.text.trim();
    _typing = text.isNotEmpty && !kPurokOptions.contains(text);
  }

  String get _selected {
    if (_typing) return _other;
    final text = widget.controller.text.trim();
    return kPurokOptions.contains(text) ? text : _notSpecified;
  }

  void _onChanged(String choice) {
    setState(() {
      if (choice == _other) {
        // Start the box empty rather than leaving "Purok 3" in it to edit.
        if (kPurokOptions.contains(widget.controller.text.trim())) {
          widget.controller.clear();
        }
        _typing = true;
      } else {
        _typing = false;
        widget.controller.text = choice == _notSpecified ? '' : choice;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        IgnorePointer(
          ignoring: !widget.enabled,
          child: Opacity(
            opacity: widget.enabled ? 1 : 0.5,
            child: AppDropdown<String>(
              label: widget.label,
              items: const [_notSpecified, ...kPurokOptions, _other],
              value: _selected,
              onChanged: _onChanged,
            ),
          ),
        ),
        if (_typing)
          AppTextField(
            label: 'Street / purok',
            hint: 'e.g. Zone 2, Sitio Malaki',
            controller: widget.controller,
            enabled: widget.enabled,
          ),
      ],
    );
  }
}
