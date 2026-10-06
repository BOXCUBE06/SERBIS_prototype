import 'package:flutter/material.dart';

import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';
import '../widgets/status_line.dart';

/// What a dedicated tab shows when the account's audience does not include the
/// service behind it (an organization has no ambulance booking, say) or the
/// catalogue could not be loaded to find out. The tab stays where it is so the
/// bar is the same on every account; the page says why it is empty.
class UnavailableTabScreen extends StatelessWidget {
  final String title;
  final String message;
  final bool filipino;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  /// Shown as a button when the cause may be temporary (a failed load).
  final String? retryLabel;
  final VoidCallback? onRetry;

  const UnavailableTabScreen({
    super.key,
    required this.title,
    required this.message,
    required this.filipino,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    this.retryLabel,
    this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: EdgeInsets.zero,
      children: [
        TabHeaderBar(
          title: title,
          subtitle: tr(filipino, 'tab.unavailable_subtitle'),
          filipino: filipino,
          onNotifications: onOpenNotifications,
          onProfile: onOpenProfile,
        ),
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 600),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, AppLayout.navClearance),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    decoration: BoxDecoration(
                      color: AppColors.surface,
                      borderRadius: BorderRadius.circular(AppRadius.lg),
                      border: Border.all(color: AppColors.line),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        StatusLine(label: tr(filipino, 'tab.unavailable_title'), tone: StatusTone.grey, large: true),
                        const SizedBox(height: AppSpacing.sm),
                        Text(message, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
                      ],
                    ),
                  ),
                  if (onRetry != null && retryLabel != null) ...[
                    const SizedBox(height: AppSpacing.lg),
                    AppButton(label: retryLabel!, onPressed: onRetry),
                  ],
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}
