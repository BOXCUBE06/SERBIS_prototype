import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';

/// What a dedicated tab shows when the account's audience does not include the
/// service behind it (an organization has no ambulance booking, say) or the
/// catalogue could not be loaded to find out. The tab stays where it is so the
/// bar is the same on every account; the page says why it is empty.
class UnavailableTabScreen extends StatelessWidget {
  final IconData icon;
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
    required this.icon,
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
        AppHeader(
          onNotificationsTap: onOpenNotifications,
          onProfileTap: onOpenProfile,
          filipino: filipino,
        ),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppLayout.gutter),
          child: AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                IconBadge(icon: icon, bg: AppColors.green50, fg: AppColors.green700),
                const SizedBox(height: 14),
                Text(title, style: AppText.display(size: AppTextSize.title)),
                const SizedBox(height: 6),
                Text(
                  message,
                  style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkMuted, height: 1.5),
                ),
                if (onRetry != null && retryLabel != null) ...[
                  const SizedBox(height: 16),
                  AppButton(label: retryLabel!, onPressed: onRetry),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: AppLayout.navClearance),
      ],
    );
  }
}
