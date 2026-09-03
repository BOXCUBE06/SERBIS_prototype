import 'package:flutter/material.dart';

import '../data/hotlines.dart';
import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
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

  const AttachmentUploadField({
    super.key,
    required this.label,
    required this.hint,
    required this.fileName,
    required this.onTap,
    this.onClear,
  });

  @override
  Widget build(BuildContext context) {
    final hasFile = fileName != null;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(10),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 14),
              decoration: BoxDecoration(
                color: hasFile ? AppColors.green50 : AppColors.surface,
                border: Border.all(color: hasFile ? AppColors.green700 : AppColors.line, width: 1.5),
                borderRadius: BorderRadius.circular(10),
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
                      style: AppText.body(size: 12, color: hasFile ? AppColors.green900 : AppColors.inkMuted),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  if (hasFile && onClear != null)
                    // Its own tap target, outside the InkWell that reopens the
                    // picker — nested inside it, clearing would also relaunch
                    // the file browser.
                    IconButton(
                      onPressed: onClear,
                      icon: const Icon(Icons.close_rounded, size: 18),
                      color: AppColors.inkMuted,
                      visualDensity: VisualDensity.compact,
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                      tooltip: 'Remove $label',
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// "Non-life-threatening use only", with the hotlines to call instead. The
/// numbers come from `data/hotlines.dart` — M28 removed the three disagreeing
/// copies, of which one lived in this notice.
///
/// One organisation per line, and one number per line under it. This used to
/// print each contact as `Label — n1 · n2 · n3 · n4` on a single `Text`, which
/// wrapped into an unbroken red paragraph: the Echague Rescue Hotline alone is
/// four carrier lines, so the block a resident had to read mid-emergency ran
/// four wrapped lines with no boundary between one office's number and the
/// next one's.
///
/// The numbers dial, the same as the Library card's — the notice tells a
/// resident to call instead of filing, and printing an unreachable number
/// under that instruction is the one thing it must not do. Each number is its
/// own tap target for the reason the Library rows are: a resident on Smart
/// cannot use the Globe line, so "call this contact" is not one action.
class SafetyNotice extends StatelessWidget {
  final bool filipino;

  const SafetyNotice({super.key, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.red50,
        border: Border.all(color: const Color(0xFFF4D9D2)),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const IconBadge(icon: Icons.warning_amber_rounded, bg: AppColors.surface, fg: AppColors.red600),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  tr(f, 'services.notice_title'),
                  style: AppText.display(size: 13, weight: FontWeight.w700, color: AppColors.red600),
                ),
                const SizedBox(height: 4),
                Text(
                  tr(f, 'services.notice_body'),
                  style: AppText.body(size: 12, color: const Color(0xFF7A3527), height: 1.6),
                ),
                const SizedBox(height: 10),
                for (final hotline in kHotlines) _hotlineBlock(hotline, f),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// The organisation once, then its numbers under it — never joined onto one
  /// line, which is what turned five contacts into a paragraph.
  Widget _hotlineBlock(Hotline hotline, bool f) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              hotline.labelFor(filipino: f),
              style: AppText.display(
                  size: 12, weight: FontWeight.w700, color: const Color(0xFF7A3527)),
            ),
            for (final number in hotline.numbers) _hotlineNumberRow(number),
          ],
        ),
      );

  /// `Landline · (078) 324-5410` where the contact has more than one carrier
  /// reaching the same desk, the bare number where it does not — a resident
  /// cannot pick between four unlabeled numbers, and every other contact's
  /// numbers are interchangeable.
  Widget _hotlineNumberRow(HotlineNumber number) => InkWell(
        borderRadius: BorderRadius.circular(6),
        onTap: () => callHotlineNumber(number.number),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  number.label == null
                      ? number.number
                      : '${number.label} · ${number.number}',
                  style: AppText.display(
                      size: 12.5, weight: FontWeight.w700, color: AppColors.red600),
                ),
              ),
              const SizedBox(width: 6),
              const Icon(Icons.call_rounded, size: 13, color: AppColors.red600),
            ],
          ),
        ),
      );
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
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? AppColors.green50 : AppColors.surface,
          border: Border.all(color: selected ? AppColors.green700 : AppColors.line, width: 1.5),
          borderRadius: BorderRadius.circular(14),
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
              radius: 10,
            ),
            const SizedBox(height: 8),
            Text(
              title,
              style: AppText.display(size: 12, weight: FontWeight.w600, height: 1.25),
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
                style: AppText.body(size: 10.5, color: AppColors.inkMuted),
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
        borderRadius: BorderRadius.circular(12),
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
                  style: AppText.body(size: 12, color: AppColors.red600, height: 1.5),
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
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: const EdgeInsets.fromLTRB(22, 32, 22, 28),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: const BoxDecoration(color: AppColors.green50, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: const Icon(Icons.check_rounded, size: 26, color: AppColors.green700),
          ),
          const SizedBox(height: 14),
          Text(tr(f, 'services.confirm.title'), style: AppText.display(size: 17)),
          const SizedBox(height: 6),
          Text(
            body,
            textAlign: TextAlign.center,
            style: AppText.body(size: 12.5, color: AppColors.inkMuted, height: 1.6),
          ),
          if (scheduled != null) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: AppColors.green50,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                '${tr(f, 'services.confirm.scheduled_for')} '
                '${formatBookingConfirmationTime(scheduled, f)}',
                textAlign: TextAlign.center,
                style: AppText.display(size: 12.5, weight: FontWeight.w600, color: AppColors.green900),
              ),
            ),
          ],
          const SizedBox(height: 18),
          AppButton(label: tr(f, 'services.confirm.view_track'), onPressed: onViewTrack),
        ],
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
