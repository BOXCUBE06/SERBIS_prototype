import 'package:flutter/material.dart';

import '../state/account_store.dart' show AppUser;
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';

/// Shown in place of the service list to an organization that registered
/// itself and has not been activated by MDRRMO yet. The server refuses its
/// requests with 403 `account_pending` either way; this says why, before the
/// organization tries.
///
/// [onCheckAgain] reloads the profile and answers whether the account is still
/// waiting. When it is not, the shell rebuilds with the real service list and
/// this screen is simply gone, so the only state worth showing here is "still
/// waiting" and "could not check".
class AwaitingApprovalScreen extends StatefulWidget {
  final AppUser user;
  final bool filipino;
  final Future<bool> Function() onCheckAgain;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  const AwaitingApprovalScreen({
    super.key,
    required this.user,
    required this.filipino,
    required this.onCheckAgain,
    required this.onOpenNotifications,
    required this.onOpenProfile,
  });

  @override
  State<AwaitingApprovalScreen> createState() => _AwaitingApprovalScreenState();
}

class _AwaitingApprovalScreenState extends State<AwaitingApprovalScreen> {
  bool _checking = false;
  String? _result;

  Future<void> _check() async {
    setState(() {
      _checking = true;
      _result = null;
    });

    String? message;
    try {
      final stillWaiting = await widget.onCheckAgain();
      if (stillWaiting) message = tr(widget.filipino, 'awaiting.still');
    } catch (_) {
      message = tr(widget.filipino, 'awaiting.failed');
    }

    if (!mounted) return;
    setState(() {
      _checking = false;
      _result = message;
    });
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.filipino;

    return ListView(
      padding: EdgeInsets.zero,
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        AppHeader(
          onNotificationsTap: widget.onOpenNotifications,
          onProfileTap: widget.onOpenProfile,
        ),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: AppCard(
            leftAccent: AppColors.amber600,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const IconBadge(
                  icon: Icons.hourglass_top_rounded,
                  bg: AppColors.amber50,
                  fg: AppColors.amber600,
                ),
                const SizedBox(height: 14),
                Text(tr(f, 'awaiting.title'), style: AppText.display(size: 17)),
                const SizedBox(height: 6),
                Text(
                  widget.user.accountName,
                  style: AppText.body(size: 13, color: AppColors.ink, height: 1.5),
                ),
                const SizedBox(height: 10),
                Text(
                  tr(f, 'awaiting.body'),
                  style: AppText.body(size: 12.5, color: AppColors.inkMuted, height: 1.6),
                ),
                if (_result != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _result!,
                    style: AppText.body(size: 12, color: AppColors.amber600, height: 1.5),
                  ),
                ],
                const SizedBox(height: 16),
                AppButton(
                  label: tr(f, 'awaiting.check'),
                  onPressed: _checking ? null : _check,
                  loading: _checking,
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 110),
      ],
    );
  }
}
