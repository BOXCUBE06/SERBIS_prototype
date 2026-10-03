import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// The frame under every screen shown before sign-in: a header, then the body in
/// a 600dp column. The header scrolls away with the body, so a phone with the
/// keyboard open keeps the room for its fields. A [footer] is pinned under the
/// scroll instead (the register steps' Back / Next bar).
class AuthPage extends StatelessWidget {
  final Widget header;
  final Widget child;
  final Widget? footer;

  const AuthPage({super.key, required this.header, required this.child, this.footer});

  @override
  Widget build(BuildContext context) {
    final scroll = ListView(
      padding: EdgeInsets.zero,
      children: [
        header,
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 600),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 24, AppLayout.gutter, 32),
              child: child,
            ),
          ),
        ),
      ],
    );

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: footer == null ? scroll : Column(children: [Expanded(child: scroll), footer!]),
    );
  }
}

enum NoticeTone { error, success, warning }

/// One plain sentence in a tinted box, with an icon: the form-level message
/// under a form (a refusal, a confirmation, a wait). Never colour alone.
class InlineNotice extends StatelessWidget {
  final String text;
  final NoticeTone tone;
  final IconData? icon;

  /// Lets a test (or a screen reader hook) find the sentence itself.
  final Key? textKey;

  const InlineNotice({super.key, required this.text, this.tone = NoticeTone.error, this.icon, this.textKey});

  @override
  Widget build(BuildContext context) {
    final (Color bg, Color fg, IconData defaultIcon) = switch (tone) {
      NoticeTone.error => (AppColors.red50, AppColors.red600, Icons.error_outline_rounded),
      NoticeTone.success => (AppColors.green50, AppColors.green700, Icons.check_circle_outline_rounded),
      NoticeTone.warning => (AppColors.amber50, AppColors.amberInk, Icons.info_outline_rounded),
    };

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.md)),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon ?? defaultIcon, size: 22, color: fg),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              text,
              key: textKey,
              style: AppText.body(
                size: AppTextSize.body,
                color: tone == NoticeTone.success ? AppColors.green900 : fg,
                height: 1.4,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A text link as a real button: 48dp tall, so "Forgot password?" and "Log in"
/// can be hit with a thumb.
class AuthLink extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final Color color;

  const AuthLink({super.key, required this.label, required this.onPressed, this.color = AppColors.green700});

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onPressed,
      style: TextButton.styleFrom(minimumSize: const Size(64, 48), foregroundColor: color),
      child: Text(
        label,
        textAlign: TextAlign.center,
        style: AppText.display(
          size: AppTextSize.body,
          weight: FontWeight.w600,
          color: onPressed == null ? AppColors.inkFaint : color,
        ),
      ),
    );
  }
}
