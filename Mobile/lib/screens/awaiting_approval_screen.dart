import 'package:flutter/material.dart';

import '../state/account_store.dart' show AppUser;
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/borrow_request_widgets.dart' show StatusBox;
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
        TabHeaderBar(
          title: tr(f, 'awaiting.header'),
          subtitle: widget.user.accountName,
          filipino: f,
          onNotifications: widget.onOpenNotifications,
          onProfile: widget.onOpenProfile,
        ),
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 600),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, AppLayout.navClearance),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  StatusBox(
                    icon: Icons.hourglass_top_rounded,
                    bg: AppColors.amber50,
                    fg: AppColors.amberInk,
                    title: tr(f, 'awaiting.title'),
                    next: tr(f, 'awaiting.body'),
                  ),
                  if (_result != null) ...[
                    const SizedBox(height: AppSpacing.md),
                    Text(
                      _result!,
                      style: AppText.body(size: AppTextSize.body, color: AppColors.amberInk, height: 1.5),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  AppButton(
                    label: tr(f, 'awaiting.check'),
                    onPressed: _checking ? null : _check,
                    loading: _checking,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}
