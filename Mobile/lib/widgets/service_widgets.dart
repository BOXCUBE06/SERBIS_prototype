import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'borrow_request_widgets.dart' show serviceStatus;
import 'feedback.dart' show FieldError;
import 'form_inputs.dart' show RequiredMark;
import 'shared_widgets.dart';
import 'status_line.dart';

/// An upload slot on the request form: label, what it takes, a Choose file
/// button and, once attached, the file. The file itself is picked by the
/// screen — this only reports what is attached.
///
/// Was `ValidIdUploadField`, with the label and the hint written into it. A
/// second, optional upload (the site photo) needs the same control with
/// different words and a way to take the file back off again, and a
/// hand-written copy of this is where the two would drift apart.
class AttachmentUploadField extends StatelessWidget {
  final String label;
  final String hint;
  final String? fileName;
  final VoidCallback onTap;

  /// Supplied only for an optional attachment. A resident who attaches the
  /// wrong photo to a required field replaces it; on an optional one there is
  /// no other way back to "none", short of abandoning the form.
  final VoidCallback? onClear;

  /// Only the clear button's tooltip reads it; the caller translates the rest.
  final bool filipino;

  /// Shown under the slot, which then draws a red border.
  final String? errorText;

  /// The camera path, where the caller has one (the ambulance's ID): a Take
  /// photo button beside Choose file, which [onTap] keeps.
  final VoidCallback? onTakePhoto;

  /// A red * after [label]. Display only; the caller validates.
  final bool isRequired;

  const AttachmentUploadField({
    super.key,
    required this.label,
    required this.hint,
    required this.fileName,
    required this.onTap,
    this.onClear,
    this.filipino = false,
    this.errorText,
    this.onTakePhoto,
    this.isRequired = false,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final hasFile = fileName != null;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Flexible(child: Text(label, style: AppText.fieldLabel())),
              if (isRequired) const RequiredMark(),
            ],
          ),
          const SizedBox(height: 2),
          Text(hint, style: AppText.detail()),
          const SizedBox(height: 10),
          // The pickers come first: a test (and a resident) tapping the slot
          // reaches them before the attached file's own remove button.
          Row(
            children: [
              if (onTakePhoto != null) ...[
                Expanded(child: _button(Icons.photo_camera_outlined, trEn(f, 'Take photo'), onTakePhoto!)),
                const SizedBox(width: AppSpacing.sm),
              ],
              Expanded(
                child: _button(
                  Icons.folder_open_rounded,
                  // Beside Take photo there is no room for "another".
                  trEn(f, hasFile && onTakePhoto == null ? 'Choose another file' : 'Choose file'),
                  onTap,
                ),
              ),
            ],
          ),
          if (hasFile)
            Container(
              margin: const EdgeInsets.only(top: AppSpacing.sm),
              padding: EdgeInsets.fromLTRB(12, onClear == null ? 12 : 2, onClear == null ? 12 : 2, onClear == null ? 12 : 2),
              decoration: BoxDecoration(
                color: AppColors.green50,
                borderRadius: BorderRadius.circular(AppRadius.md),
                border: Border.all(color: AppColors.greenNoticeBorder),
              ),
              child: Row(
                children: [
                  const Icon(Icons.check_circle_rounded, color: AppColors.green700, size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      fileName!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppText.body(color: AppColors.green900, weight: FontWeight.w500),
                    ),
                  ),
                  if (onClear != null)
                    IconButton(
                      onPressed: onClear,
                      icon: const Icon(Icons.close_rounded, size: 20),
                      color: AppColors.inkMuted,
                      constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
                      tooltip: trEn(f, 'Remove {label}').replaceAll('{label}', label),
                    ),
                ],
              ),
            ),
          if (errorText != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: FieldError(errorText!),
            ),
        ],
      ),
    );
  }

  Widget _button(IconData icon, String text, VoidCallback onPressed) => SizedBox(
        height: 48,
        child: OutlinedButton.icon(
          onPressed: onPressed,
          icon: Icon(icon, size: 18),
          label: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis),
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.ink,
            backgroundColor: AppColors.surface,
            textStyle: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
            side: BorderSide(color: errorText != null ? AppColors.red600 : AppColors.fieldBorder, width: errorText != null ? 2 : 1),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
          ),
        ),
      );
}

/// "Non-life-threatening use only", above the ambulance form. The hotlines
/// themselves live in one place, the Library; [onViewHotlines] links there.
class SafetyNotice extends StatelessWidget {
  final bool filipino;

  /// Opens the Library's hotline card. Null draws no link.
  final VoidCallback? onViewHotlines;

  const SafetyNotice({super.key, required this.filipino, this.onViewHotlines});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.amber50,
        border: Border.all(color: const Color(0xFFF1DDC0)),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.warning_amber_rounded, size: 22, color: AppColors.amber600),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      tr(f, 'services.notice_title'),
                      style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.amber600),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      tr(f, 'services.notice_body'),
                      style: AppText.body(size: AppTextSize.body, color: AppColors.ink, height: 1.5),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (onViewHotlines != null) ...[
            const SizedBox(height: AppSpacing.md),
            Material(
              color: AppColors.amber600,
              borderRadius: BorderRadius.circular(AppRadius.md),
              child: InkWell(
                onTap: onViewHotlines,
                borderRadius: BorderRadius.circular(AppRadius.md),
                child: SizedBox(
                  height: 44,
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.call_rounded, size: 16, color: AppColors.surface),
                      const SizedBox(width: AppSpacing.sm),
                      Flexible(
                        child: Text(
                          tr(f, 'notice.view_hotlines'),
                          style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.surface),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// One service in the catalogue grid.
class ServiceTypeCard extends StatelessWidget {
  final String title;
  final String subtitle;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  const ServiceTypeCard({
    super.key,
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? AppColors.green50 : AppColors.surface,
          border: Border.all(color: selected ? AppColors.green700 : AppColors.line, width: 1.5),
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            IconBadge(
              icon: icon,
              bg: selected ? AppColors.green50 : AppColors.paper,
              fg: selected ? AppColors.green700 : AppColors.inkMuted,
              size: 34,
              iconSize: 16,
              radius: AppRadius.sm,
            ),
            const SizedBox(height: 8),
            Text(
              title,
              style: AppText.display(size: AppTextSize.small, weight: FontWeight.w600, height: 1.25),
            ),
            if (subtitle.isNotEmpty) ...[
              const SizedBox(height: 2),
              // No maxLines and no ellipsis on purpose. Capping at one line cut
              // every Tagalog blurb mid-word, and capping at two still truncates
              // the longest of them at 360 px. Yogad is coming and will be
              // longer again, so the tile grows to the text rather than the text
              // being cut to the tile.
              Text(
                subtitle,
                style: AppText.body(size: AppTextSize.caption, color: AppColors.inkMuted),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Shown in place of the confirmation sheet when the submit failed. Stays on
/// screen — unlike the error snackbar the shell drains — because the resident
/// has to know the request was never filed.
class SubmitErrorCard extends StatelessWidget {
  final bool filipino;
  final VoidCallback onRetry;

  const SubmitErrorCard({super.key, required this.filipino, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AppColors.red50,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.error_outline_rounded, size: 18, color: AppColors.red600),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  filipino
                      ? 'Hindi naipadala ang kahilingan. Nandito pa ang mga detalye mo — subukang muli.'
                      : "Your request wasn't sent. Your details are still here — tap Retry to send them again.",
                  style: AppText.body(size: AppTextSize.small, color: AppColors.red600, height: 1.5),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          AppButton(
            label: filipino ? 'Subukang muli' : 'Retry',
            style: AppButtonStyle.outline,
            onPressed: onRetry,
          ),
        ],
      ),
    );
  }
}

/// Shown only after the server confirms the request (C_Sent): what was sent,
/// its reference and time, where it stands in the same words Track uses, and
/// the way to follow it.
class ConfirmationSheet extends StatelessWidget {
  /// The request as the server returned it: its reference, times and status.
  final ServiceRequest request;

  /// The service's name, as the form was titled.
  final String title;
  final bool filipino;
  final VoidCallback onViewTrack;

  const ConfirmationSheet({
    super.key,
    required this.request,
    required this.title,
    required this.filipino,
    required this.onViewTrack,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final status = serviceStatus(r, f);
    final scheduled = r.scheduledAt;
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
      ),
      padding: EdgeInsets.fromLTRB(AppLayout.gutter, 12, 12, 24 + MediaQuery.paddingOf(context).bottom),
      child: Align(
        alignment: Alignment.topCenter,
        heightFactor: 1,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Semantics(
                      header: true,
                      child: Text(tr(f, 'services.confirm.title'), style: AppText.display(size: AppTextSize.pageTitle)),
                    ),
                  ),
                  IconButton(
                    onPressed: () => Navigator.of(context).pop(),
                    tooltip: tr(f, 'common.close'),
                    icon: const Icon(Icons.close_rounded, size: 20),
                    style: IconButton.styleFrom(
                      backgroundColor: AppColors.grey50,
                      foregroundColor: AppColors.ink,
                      fixedSize: const Size(44, 44),
                    ),
                  ),
                ],
              ),
              Padding(
                padding: const EdgeInsets.only(right: AppLayout.gutter - 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: AppSpacing.md),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          width: 44,
                          height: 44,
                          decoration: const BoxDecoration(color: AppColors.green50, shape: BoxShape.circle),
                          child: const Icon(Icons.check_rounded, color: AppColors.green700),
                        ),
                        const SizedBox(width: AppSpacing.md),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(title, style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, height: 1.3)),
                              const SizedBox(height: 2),
                              Text(
                                [
                                  r.refNo.isEmpty ? tr(f, 'track.ref_pending') : r.refNo,
                                  if (r.createdAt != null) formatTimelineTime(r.createdAt!, f),
                                ].join(' · '),
                                style: AppText.detail(),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.lg),
                    StatusLine(
                      label: status.label,
                      tone: status.tone,
                      large: true,
                      updatedAt: r.updatedAt ?? r.createdAt,
                      filipino: f,
                    ),
                    const SizedBox(height: 6),
                    Text(status.next, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
                    // A Booked status already names the time in its own line.
                    if (scheduled != null && r.status != ReqStatus.booked) ...[
                      const SizedBox(height: 6),
                      Text(
                        '${tr(f, 'services.confirm.scheduled_for')} ${formatBookingConfirmationTime(scheduled, f)}',
                        style: AppText.body(color: AppColors.ink, weight: FontWeight.w500, height: 1.5),
                      ),
                    ],
                    const SizedBox(height: AppSpacing.xl),
                    AppButton(label: tr(f, 'services.confirm.view_track'), onPressed: onViewTrack),
                    const SizedBox(height: AppSpacing.sm),
                    // Only closes the sheet, as a swipe down does.
                    AppButton(
                      label: trEn(f, 'Done'),
                      style: AppButtonStyle.outline,
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The catalogue grid: rows of two rather than a `GridView`, because
/// `childAspectRatio` pinned every tile to one height and the longest label
/// decided what got clipped. A row is as tall as its taller tile and no
/// taller, and `IntrinsicHeight` keeps the pair matched so it still reads as a
/// grid.
class ServiceGrid extends StatelessWidget {
  final int count;
  final Widget Function(int index) cardBuilder;

  const ServiceGrid({super.key, required this.count, required this.cardBuilder});

  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[];
    for (var i = 0; i < count; i += 2) {
      final hasRight = i + 1 < count;

      rows.add(Padding(
        padding: EdgeInsets.only(bottom: i + 2 < count ? 10 : 0),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: cardBuilder(i)),
              const SizedBox(width: 10),
              // An odd-length catalogue leaves a hole rather than a
              // double-width tile.
              Expanded(child: hasRight ? cardBuilder(i + 1) : const SizedBox.shrink()),
            ],
          ),
        ),
      ));
    }

    return Column(children: rows);
  }
}
