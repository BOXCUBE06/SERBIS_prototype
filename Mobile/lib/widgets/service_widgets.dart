import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'borrow_request_widgets.dart' show StatusBox;
import 'shared_widgets.dart';

/// An upload slot on the request form. The file itself is picked by the screen
/// — this only reports what is attached.
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

  const AttachmentUploadField({
    super.key,
    required this.label,
    required this.hint,
    required this.fileName,
    required this.onTap,
    this.onClear,
    this.filipino = false,
    this.errorText,
  });

  @override
  Widget build(BuildContext context) {
    final hasFile = fileName != null;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.fieldLabel()),
          const SizedBox(height: 6),
          InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(AppRadius.sm),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 14),
              decoration: BoxDecoration(
                color: hasFile ? AppColors.green50 : AppColors.surface,
                border: Border.all(
                  color: errorText != null ? AppColors.red600 : (hasFile ? AppColors.green700 : AppColors.line),
                  width: 1.5,
                ),
                borderRadius: BorderRadius.circular(AppRadius.sm),
              ),
              child: Row(
                children: [
                  Icon(
                    hasFile ? Icons.check_circle_rounded : Icons.cloud_upload_outlined,
                    color: hasFile ? AppColors.green700 : AppColors.inkFaint,
                    size: 20,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      hasFile ? fileName! : hint,
                      style: AppText.body(
                          size: AppTextSize.body, color: hasFile ? AppColors.green900 : AppColors.inkMuted, height: 1.35),
                      // A file name is cut short; the instruction is not, or
                      // the resident cannot read which file is being asked for.
                      maxLines: hasFile ? 1 : 3,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  if (hasFile && onClear != null)
                    // Its own tap target, outside the InkWell that reopens the
                    // picker — nested inside it, clearing would also relaunch
                    // the file browser.
                    IconButton(
                      onPressed: onClear,
                      icon: const Icon(Icons.close_rounded, size: 20),
                      color: AppColors.inkMuted,
                      visualDensity: VisualDensity.compact,
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
                      tooltip: trEn(filipino, 'Remove {label}').replaceAll('{label}', label),
                    ),
                ],
              ),
            ),
          ),
          if (errorText != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.xs, left: 2),
              child: Text(errorText!, style: AppText.body(size: AppTextSize.small, color: AppColors.red600)),
            ),
        ],
      ),
    );
  }
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
                      style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w700, color: AppColors.amber600),
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

/// Shown only after the server confirms the request, and it carries the
/// server's reference number — M3 and M4 both live in that sentence.
class ConfirmationSheet extends StatelessWidget {
  final String refNo;
  final bool filipino;
  final VoidCallback onViewTrack;

  /// Set only for an ambulance booking. Shown with
  /// [formatBookingConfirmationTime], not [formatTimelineTime] — a booking
  /// confirmation is exactly the case that formatter's own doc comment says
  /// needs the year, since a resident can reopen this weeks after filing.
  final DateTime? scheduledAt;

  const ConfirmationSheet({
    super.key,
    required this.refNo,
    required this.filipino,
    required this.onViewTrack,
    this.scheduledAt,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final body = tr(f, 'services.confirm.body').replaceAll('{ref}', refNo);
    final scheduled = scheduledAt;
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
      ),
      padding: EdgeInsets.fromLTRB(AppLayout.gutter, 24, AppLayout.gutter, 24 + MediaQuery.paddingOf(context).bottom),
      child: Align(
        alignment: Alignment.topCenter,
        heightFactor: 1,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              StatusBox(
                icon: Icons.check_circle_rounded,
                bg: AppColors.green50,
                fg: AppColors.green700,
                title: tr(f, 'services.confirm.title'),
                next: body,
              ),
              if (scheduled != null) ...[
                const SizedBox(height: AppSpacing.sm),
                StatusBox(
                  icon: Icons.event_available_rounded,
                  bg: AppColors.green50,
                  fg: AppColors.green700,
                  title: tr(f, 'services.confirm.scheduled_for'),
                  next: formatBookingConfirmationTime(scheduled, f),
                ),
              ],
              const SizedBox(height: AppSpacing.lg),
              AppButton(label: tr(f, 'services.confirm.view_track'), onPressed: onViewTrack),
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

/// The valid-ID upload: a dashed-look drop zone with Take photo / Choose file.
class IdUploadCard extends StatelessWidget {
  final bool filipino;

  /// Name of the attached file, or null when nothing is attached yet.
  final String? fileName;
  final VoidCallback onTakePhoto;
  final VoidCallback onChooseFile;

  const IdUploadCard({
    super.key,
    required this.filipino,
    required this.fileName,
    required this.onTakePhoto,
    required this.onChooseFile,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final attached = fileName != null;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: attached ? AppColors.green50 : AppColors.paper,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: attached ? AppColors.green600 : AppColors.line, width: 1.5),
      ),
      child: Column(
        children: [
          Icon(
            attached ? Icons.check_circle_rounded : Icons.badge_outlined,
            size: 28,
            color: AppColors.green700,
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            attached ? fileName! : trEn(f, 'Upload a photo of a valid ID'),
            textAlign: TextAlign.center,
            style: AppText.body(size: AppTextSize.bodyLg, weight: FontWeight.w500),
          ),
          const SizedBox(height: 2),
          Text(
            trEn(f, 'JPG or PNG, up to 2 MB'),
            style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              Expanded(child: _button(Icons.photo_camera_outlined, trEn(f, 'Take photo'), onTakePhoto)),
              const SizedBox(width: AppSpacing.sm),
              Expanded(child: _button(Icons.folder_open_rounded, trEn(f, 'Choose file'), onChooseFile)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _button(IconData icon, String label, VoidCallback onTap) => SizedBox(
        height: 44,
        child: OutlinedButton.icon(
          onPressed: onTap,
          icon: Icon(icon, size: 18),
          label: Text(label, style: AppText.body(size: AppTextSize.body, weight: FontWeight.w500)),
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.ink,
            backgroundColor: AppColors.surface,
            side: const BorderSide(color: AppColors.line),
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.sm)),
          ),
        ),
      );
}
