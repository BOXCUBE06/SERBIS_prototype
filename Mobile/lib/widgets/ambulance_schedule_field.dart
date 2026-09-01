import 'dart:async';

import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'form_section.dart';

/// How soon a resident may book, mirrored from the server's own
/// `ServiceRequestController::MINIMUM_LEAD_TIME_HOURS`. Duplicated rather
/// than fetched: this is a fixed policy constant, not data, and the whole
/// point of checking it here is to catch an unreachable pick before the
/// round trip that would otherwise report it.
const int _minimumLeadTimeHours = 1;

/// Matches the server's own default booking window
/// (`ServiceRequestController::DEFAULT_BOOKING_HOURS`), used only to size the
/// advisory availability check below — the server decides the real
/// `scheduled_end` at approval, not this screen.
const int _defaultBookingHours = 2;

/// Lets a resident pick a date and time for an ambulance, or leave it unset
/// for "as soon as possible" — the behaviour this app has always had.
///
/// Ephemeral UI state (the availability check in flight, its result, a
/// lead-time error) lives in this widget's own State, the same way any
/// StatefulWidget's transient state does. [form.scheduledAt] itself lives on
/// the model, not here — it has to survive a rebuild and a language switch
/// the same way the text controllers beside it do, and the submit path reads
/// it from there.
class AmbulanceScheduleField extends StatefulWidget {
  final AmbulanceFormData form;
  final AppState appState;
  final bool filipino;
  final VoidCallback onChanged;

  const AmbulanceScheduleField({
    super.key,
    required this.form,
    required this.appState,
    required this.filipino,
    required this.onChanged,
  });

  @override
  State<AmbulanceScheduleField> createState() => _AmbulanceScheduleFieldState();
}

class _AmbulanceScheduleFieldState extends State<AmbulanceScheduleField> {
  bool _checking = false;

  /// `true`/`false` once a check has completed; `null` before the first one,
  /// or after a check that failed to reach the server — which must read as
  /// "not checked" on screen, not as "nothing is free".
  bool? _someUnitFree;

  String? _leadTimeError;

  Future<void> _pickDateTime() async {
    final now = DateTime.now();
    final earliest = now.add(const Duration(hours: _minimumLeadTimeHours));
    final existing = widget.form.scheduledAt;

    final pickedDate = await showDatePicker(
      context: context,
      initialDate: existing != null && existing.isAfter(now) ? existing : earliest,
      firstDate: now,
      lastDate: now.add(const Duration(days: 60)),
    );
    if (pickedDate == null || !mounted) {
      return;
    }

    final pickedTime = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(existing ?? earliest),
    );
    if (pickedTime == null || !mounted) {
      return;
    }

    final combined = DateTime(
      pickedDate.year,
      pickedDate.month,
      pickedDate.day,
      pickedTime.hour,
      pickedTime.minute,
    );

    // showTimePicker has no predicate to grey out individual clock positions
    // the way showDatePicker's selectableDayPredicate does for whole days, so
    // the 1-hour lead time cannot be disabled inside the picker itself for a
    // same-day pick. This is the closest equivalent the framework widget
    // allows: reject immediately after the pick completes, before it ever
    // reaches [form.scheduledAt] or a server round trip.
    if (combined.isBefore(earliest)) {
      setState(() {
        _leadTimeError = tr(widget.filipino, 'ambulance_schedule.lead_time_error');
        _someUnitFree = null;
      });
      return;
    }

    setState(() {
      widget.form.scheduledAt = combined;
      _leadTimeError = null;
      _someUnitFree = null;
    });
    widget.onChanged();
    unawaited(_checkAvailability(combined));
  }

  Future<void> _checkAvailability(DateTime start) async {
    setState(() => _checking = true);

    final end = start.add(const Duration(hours: _defaultBookingHours));
    final units = await widget.appState.checkAmbulanceAvailability(start, end);

    // The resident may have picked a different time — or cleared the pick
    // entirely — while this was in flight. A stale answer for an
    // already-abandoned time must not be shown as current.
    if (!mounted || widget.form.scheduledAt != start) {
      return;
    }

    setState(() {
      _checking = false;
      _someUnitFree = units?.isNotEmpty;
    });
  }

  void _clear() {
    setState(() {
      widget.form.scheduledAt = null;
      _leadTimeError = null;
      _someUnitFree = null;
      _checking = false;
    });
    widget.onChanged();
  }

  /// Both toggle segments are visible at once now, so the mode itself is
  /// never a mystery the way the old box-that-changes-shape was. Picking
  /// "Scheduled" opens the picker immediately rather than requiring a second
  /// tap; cancelling it at any step leaves [form.scheduledAt] null, which the
  /// toggle reads straight off — so it snaps back to "As soon as possible" on
  /// its own, with nothing extra to reset here.
  void _onModeChanged(bool wantsScheduled) {
    if (wantsScheduled) {
      _pickDateTime();
    } else {
      _clear();
    }
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.filipino;
    final scheduled = widget.form.scheduledAt;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ModeToggle(
          leftLabel: tr(f, 'ambulance_schedule.asap'),
          rightLabel: tr(f, 'ambulance_schedule.mode_scheduled'),
          rightSelected: scheduled != null,
          onChanged: _onModeChanged,
        ),
        if (scheduled != null) ...[
          const SizedBox(height: AppSpacing.sm),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
            decoration: BoxDecoration(
              color: AppColors.green50,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.green600, width: 1.5),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.event_available_rounded, size: 18, color: AppColors.green700),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    formatBookingConfirmationTime(scheduled, f),
                    style: AppText.display(size: 13, weight: FontWeight.w600, color: AppColors.green900),
                  ),
                ),
                TextButton(
                  style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 0)),
                  onPressed: _pickDateTime,
                  child: Text(tr(f, 'ambulance_schedule.change')),
                ),
              ],
            ),
          ),
        ],
        if (_leadTimeError != null)
            Padding(
              padding: const EdgeInsets.only(top: 4, left: 2),
              child: Text(
                _leadTimeError!,
                style: AppText.body(size: 11, color: AppColors.red600),
              ),
            ),
          if (_checking)
            Padding(
              padding: const EdgeInsets.only(top: 6, left: 2),
              child: Row(
                children: [
                  const SizedBox(
                    width: 12,
                    height: 12,
                    child: CircularProgressIndicator(strokeWidth: 1.5),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    tr(f, 'ambulance_schedule.checking'),
                    style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                  ),
                ],
              ),
            )
          else if (_someUnitFree != null)
            Padding(
              padding: const EdgeInsets.only(top: 6, left: 2),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    _someUnitFree! ? Icons.check_circle_outline_rounded : Icons.info_outline_rounded,
                    size: 14,
                    color: _someUnitFree! ? AppColors.green700 : AppColors.amber600,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      tr(
                        f,
                        _someUnitFree!
                            ? 'ambulance_schedule.some_free'
                            : 'ambulance_schedule.none_free',
                      ),
                      style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                    ),
                  ),
                ],
              ),
            ),
      ],
    );
  }
}
