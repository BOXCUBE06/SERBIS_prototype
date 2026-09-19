import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../state/account_store.dart';
import '../theme/app_theme.dart';
import 'form_inputs.dart';

/// Which of [suggestions] match [query] — a substring match, case-insensitive,
/// and nothing at all for a blank query (an empty field showing every purok
/// on record reads as a dropdown, not as "type to search"). Pulled out of
/// [PurokAutocompleteField] so the matching rule can be tested directly,
/// without needing RawAutocomplete's overlay to actually render.
@visibleForTesting
Iterable<String> matchingPuroks(List<String> suggestions, String query) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty || suggestions.isEmpty) return const Iterable<String>.empty();
  return suggestions.where((s) => s.toLowerCase().contains(q));
}

/// Free-text purok/street field with type-ahead suggestions drawn from what
/// other residents of the same barangay have already typed (MDRRMO feedback,
/// 2026-09-19). No seed data: [UserStore.puroks] reads real entries, so a
/// barangay nobody has registered a purok for yet simply offers nothing —
/// the list builds itself.
///
/// Shared between the register screen (barangay picked on the same form) and
/// the profile edit sheet (barangay fixed, read from the account) — both
/// just pass whichever [barangayId] they have.
class PurokAutocompleteField extends StatefulWidget {
  final TextEditingController controller;
  final UserStore userStore;

  /// Null before a barangay is chosen (register screen, before the picker
  /// above this field has a value) — suggestions stay empty until then.
  final int? barangayId;

  final String label;
  final bool enabled;

  const PurokAutocompleteField({
    super.key,
    required this.controller,
    required this.userStore,
    required this.barangayId,
    this.label = 'Street / Purok (optional)',
    this.enabled = true,
  });

  @override
  State<PurokAutocompleteField> createState() => _PurokAutocompleteFieldState();
}

class _PurokAutocompleteFieldState extends State<PurokAutocompleteField> {
  List<String> _suggestions = const [];
  int? _loadedForBarangayId;

  // Created once, not per build: RawAutocomplete tracks this node's identity
  // to know when the field is focused and the suggestions overlay should
  // open. A fresh FocusNode() on every rebuild (e.g. once suggestions finish
  // loading and setState fires) would silently break that.
  final FocusNode _focusNode = FocusNode();

  @override
  void initState() {
    super.initState();
    _loadIfNeeded();
  }

  @override
  void dispose() {
    _focusNode.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant PurokAutocompleteField old) {
    super.didUpdateWidget(old);
    // A resident who changes the barangay picker mid-form must see
    // suggestions for the newly chosen one, not the last one's leftovers.
    if (old.barangayId != widget.barangayId) {
      _suggestions = const [];
      _loadIfNeeded();
    }
  }

  void _loadIfNeeded() {
    final barangayId = widget.barangayId;
    if (barangayId == null || barangayId == _loadedForBarangayId) return;

    _loadedForBarangayId = barangayId;
    // Best-effort. A failed fetch just means no suggestions this time — the
    // field is free text either way, so there is nothing to block or retry.
    widget.userStore.puroks(barangayId).then((rows) {
      if (mounted && widget.barangayId == barangayId) {
        setState(() => _suggestions = rows);
      }
    }).catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    return RawAutocomplete<String>(
      textEditingController: widget.controller,
      focusNode: _focusNode,
      optionsBuilder: (value) => matchingPuroks(_suggestions, value.text),
      fieldViewBuilder: (context, controller, focusNode, onSubmitted) => AppTextField(
        label: widget.label,
        hint: 'e.g. Purok 3',
        controller: controller,
        focusNode: focusNode,
        enabled: widget.enabled,
      ),
      // A fixed width, not Align: Align gives its child unbounded width from
      // inside the overlay, and a vertical ListView needs a finite one. The
      // overlay has no direct line to the field's own width, so this matches
      // the screen with the same horizontal margin AppTextField's callers use
      // rather than trying to measure the field itself.
      optionsViewBuilder: (context, onSelected, options) => Material(
        elevation: 3,
        borderRadius: BorderRadius.circular(10),
        child: SizedBox(
          width: MediaQuery.sizeOf(context).width - 44,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 200),
            child: ListView.builder(
              padding: EdgeInsets.zero,
              shrinkWrap: true,
              itemCount: options.length,
              itemBuilder: (context, index) {
                final option = options.elementAt(index);
                return ListTile(
                  dense: true,
                  title: Text(option, style: AppText.body(size: 13)),
                  onTap: () => onSelected(option),
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}
