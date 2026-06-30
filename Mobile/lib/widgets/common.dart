import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../models/models.dart';
import '../theme/app_theme.dart';

/// Compact branded header bar shown at the top of every screen
/// (Home, Services, Track, Library, Profile).
class AppHeader extends StatelessWidget {
  final VoidCallback? onNotificationsTap;
  final VoidCallback? onProfileTap;

  const AppHeader({super.key, this.onNotificationsTap, this.onProfileTap});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(22, 48, 22, 24),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            right: -60,
            top: -90,
            child: Container(
              width: 200,
              height: 200,
              decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withOpacity(.05)),
            ),
          ),
          Positioned(
            left: -60,
            bottom: -110,
            child: Container(
              width: 160,
              height: 160,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white.withOpacity(.08)),
              ),
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Container(
                    width: 38,
                    height: 38,
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(.12),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.white.withOpacity(.14)),
                    ),
                    alignment: Alignment.center,
                    child: const Icon(Icons.shield_outlined, color: Colors.white, size: 19),
                  ),
                  const SizedBox(width: 11),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('SERBIS', style: AppText.display(size: 17, color: Colors.white, letterSpacing: .5)),
                      const SizedBox(height: 2),
                      Text(
                        'ECHAGUE MDRRMO',
                        style: AppText.display(
                          size: 10,
                          weight: FontWeight.w500,
                          color: Colors.white.withOpacity(.65),
                          letterSpacing: 2,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
              if (onNotificationsTap != null || onProfileTap != null)
                Row(
                  children: [
                    if (onNotificationsTap != null)
                      GestureDetector(onTap: onNotificationsTap, child: _circleIcon(Icons.notifications_outlined, badge: true)),
                    if (onNotificationsTap != null && onProfileTap != null) const SizedBox(width: 8),
                    if (onProfileTap != null)
                      GestureDetector(onTap: onProfileTap, child: _circleIcon(Icons.person_outline_rounded)),
                  ],
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _circleIcon(IconData icon, {bool badge = false}) {
    return Container(
      width: 36,
      height: 36,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: Colors.white.withOpacity(.06),
        border: Border.all(color: Colors.white.withOpacity(.16)),
      ),
      child: Stack(
        alignment: Alignment.center,
        children: [
          Icon(icon, size: 17, color: Colors.white),
          if (badge)
            Positioned(
              top: 7,
              right: 8,
              child: Container(
                width: 7,
                height: 7,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.amber600,
                  border: Border.all(color: AppColors.green900, width: 1.5),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Section title with an optional trailing text action ("View all" etc).
class SectionHeader extends StatelessWidget {
  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  const SectionHeader({super.key, required this.title, this.actionLabel, this.onAction});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(title, style: AppText.display(size: 16, color: AppColors.green900)),
          if (actionLabel != null)
            GestureDetector(
              onTap: onAction,
              child: Text(
                actionLabel!,
                style: AppText.display(size: 12, weight: FontWeight.w600, color: AppColors.green700),
              ),
            ),
        ],
      ),
    );
  }
}

/// Generic white rounded card with the app's soft shadow & border.
class AppCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final Color? leftAccent;

  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.leftAccent,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.line),
        boxShadow: [
          BoxShadow(
            color: AppColors.green900.withOpacity(.04),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: leftAccent == null
          ? Padding(padding: padding, child: child)
          : Row(
              children: [
                Container(width: 4, decoration: BoxDecoration(
                  color: leftAccent,
                  borderRadius: const BorderRadius.horizontal(left: Radius.circular(20)),
                )),
                Expanded(child: Padding(padding: padding, child: child)),
              ],
            ),
    );
  }
}

/// Small rounded icon container, used for request/announcement/category icons.
class IconBadge extends StatelessWidget {
  final IconData icon;
  final Color bg;
  final Color fg;
  final double size;
  final double iconSize;
  final double radius;

  const IconBadge({
    super.key,
    required this.icon,
    required this.bg,
    required this.fg,
    this.size = 38,
    this.iconSize = 18,
    this.radius = 11,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(radius)),
      alignment: Alignment.center,
      child: Icon(icon, size: iconSize, color: fg),
    );
  }
}

/// Pill-shaped status badge (Under review / Scheduled / Completed / Cancelled).
class StatusBadge extends StatelessWidget {
  final ReqStatus status;
  final bool filipino;
  const StatusBadge(this.status, {super.key, this.filipino = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: status.bg, borderRadius: BorderRadius.circular(30)),
      child: Text(
        status.labelFor(filipino).toUpperCase(),
        style: AppText.display(size: 10.5, weight: FontWeight.w700, color: status.fg, letterSpacing: .5),
      ),
    );
  }
}

/// "Saved" / "Download" pill used in the offline library.
/// Tapping "Download" simulates saving the item for offline use.
class OfflinePill extends StatefulWidget {
  final bool saved;
  final String? label;
  final bool filipino;
  const OfflinePill({super.key, required this.saved, this.label, this.filipino = false});

  @override
  State<OfflinePill> createState() => _OfflinePillState();
}

class _OfflinePillState extends State<OfflinePill> {
  late bool _saved = widget.saved;
  bool _loading = false;

  Future<void> _handleTap() async {
    if (_saved || _loading) return;
    setState(() => _loading = true);
    await Future.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;
    setState(() {
      _loading = false;
      _saved = true;
    });
    showAppSnackBar(
      context,
      widget.filipino
          ? '${widget.label ?? 'Item'} ay na-save para sa offline na gamit.'
          : '${widget.label ?? 'Item'} saved for offline use.',
    );
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: _handleTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(color: AppColors.green50, borderRadius: BorderRadius.circular(20)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _loading
                ? const SizedBox(
                    width: 12,
                    height: 12,
                    child: CircularProgressIndicator(strokeWidth: 1.5, color: AppColors.green700),
                  )
                : Icon(_saved ? Icons.check_circle_rounded : Icons.download_rounded, size: 12, color: AppColors.green700),
            const SizedBox(width: 4),
            Text(
              _loading
                  ? (widget.filipino ? 'Sine-save...' : 'Saving...')
                  : (_saved ? (widget.filipino ? 'Na-save' : 'Saved') : (widget.filipino ? 'I-download' : 'Download')),
              style: AppText.display(size: 10, weight: FontWeight.w700, color: AppColors.green700),
            ),
          ],
        ),
      ),
    );
  }
}

/// Primary / outline / ghost buttons that match the mockup styling.
class AppButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final AppButtonStyle style;
  final IconData? icon;
  final bool loading;

  const AppButton({
    super.key,
    required this.label,
    this.onPressed,
    this.style = AppButtonStyle.primary,
    this.icon,
    this.loading = false,
  });

  @override
  Widget build(BuildContext context) {
    final Color fg = switch (style) {
      AppButtonStyle.primary => Colors.white,
      AppButtonStyle.outline => AppColors.ink,
      AppButtonStyle.ghostRed => AppColors.red600,
    };

    final child = loading
        ? SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(strokeWidth: 2, color: fg),
          )
        : Row(
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon != null) Padding(padding: const EdgeInsets.only(right: 7), child: Icon(icon, size: 16, color: fg)),
              Text(label, style: AppText.display(size: 13, weight: FontWeight.w600, color: fg)),
            ],
          );

    final effectiveOnPressed = loading ? null : onPressed;

    switch (style) {
      case AppButtonStyle.primary:
        return SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: effectiveOnPressed,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.green700,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              elevation: 0,
            ),
            child: child,
          ),
        );
      case AppButtonStyle.outline:
        return SizedBox(
          width: double.infinity,
          child: OutlinedButton(
            onPressed: effectiveOnPressed,
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.ink,
              side: const BorderSide(color: AppColors.line),
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            child: child,
          ),
        );
      case AppButtonStyle.ghostRed:
        return SizedBox(
          width: double.infinity,
          child: TextButton(
            onPressed: effectiveOnPressed,
            style: TextButton.styleFrom(
              backgroundColor: AppColors.red50,
              foregroundColor: AppColors.red600,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            child: child,
          ),
        );
    }
  }
}

enum AppButtonStyle { primary, outline, ghostRed }

/// Labeled text field with validation, used on the Login/Register screens.
class AuthTextField extends StatefulWidget {
  final String label;
  final String hint;
  final TextEditingController controller;
  final TextInputType keyboard;
  final bool obscure;
  final String? Function(String?)? validator;
  final IconData? prefixIcon;
  final int? maxLength;
  final List<TextInputFormatter>? inputFormatters;

  const AuthTextField({
    super.key,
    required this.label,
    required this.hint,
    required this.controller,
    this.keyboard = TextInputType.text,
    this.obscure = false,
    this.validator,
    this.prefixIcon,
    this.maxLength,
    this.inputFormatters,
  });

  @override
  State<AuthTextField> createState() => _AuthTextFieldState();
}

class _AuthTextFieldState extends State<AuthTextField> {
  late bool _hidden = widget.obscure;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(widget.label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          TextFormField(
            controller: widget.controller,
            keyboardType: widget.keyboard,
            obscureText: _hidden,
            validator: widget.validator,
            maxLength: widget.maxLength,
            inputFormatters: widget.inputFormatters,
            style: AppText.body(size: 13),
            decoration: InputDecoration(
              hintText: widget.hint,
              hintStyle: AppText.body(size: 13, color: AppColors.inkFaint),
              counterText: '',
              prefixIcon: widget.prefixIcon != null
                  ? Icon(widget.prefixIcon, size: 18, color: AppColors.inkFaint)
                  : null,
              suffixIcon: widget.obscure
                  ? IconButton(
                      onPressed: () => setState(() => _hidden = !_hidden),
                      icon: Icon(
                        _hidden ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                        size: 18,
                        color: AppColors.inkFaint,
                      ),
                    )
                  : null,
              filled: true,
              fillColor: AppColors.surface,
              contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
              errorStyle: AppText.body(size: 11, color: AppColors.red600),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.green600, width: 1.5),
              ),
              errorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.red600, width: 1.5),
              ),
              focusedErrorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.red600, width: 1.5),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Shows a confirmation dialog before cancelling a request. If confirmed,
/// calls [onConfirmed] (which should update the underlying request status)
/// and then shows a snackbar. Used by the Cancel buttons on Home and Track.
void showCancelDialog(BuildContext context, String refNo, VoidCallback onConfirmed, {bool filipino = false}) {
  showDialog(
    context: context,
    builder: (ctx) => AlertDialog(
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      title: Text(
        filipino ? 'Kanselahin ang kahilingan?' : 'Cancel request?',
        style: AppText.display(size: 16),
      ),
      content: Text(
        filipino
            ? 'Sigurado ka bang ikakansela ang kahilingan #$refNo? Hindi na maibabalik ang aksyon na ito.'
            : 'Are you sure you want to cancel request #$refNo? This action cannot be undone.',
        style: AppText.body(size: 13, color: AppColors.inkMuted, height: 1.5),
      ),
      actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx),
          child: Text(
            filipino ? 'Panatilihin' : 'Keep request',
            style: AppText.display(size: 13, weight: FontWeight.w600, color: AppColors.inkMuted),
          ),
        ),
        TextButton(
          onPressed: () {
            Navigator.pop(ctx);
            onConfirmed();
            showAppSnackBar(
              context,
              filipino ? 'Nakansela na ang kahilingan #$refNo.' : 'Request #$refNo has been cancelled.',
            );
          },
          style: TextButton.styleFrom(backgroundColor: AppColors.red50, foregroundColor: AppColors.red600),
          child: Text(
            filipino ? 'Kanselahin' : 'Cancel request',
            style: AppText.display(size: 13, weight: FontWeight.w600, color: AppColors.red600),
          ),
        ),
      ],
    ),
  );
}

/// Small helper for consistent snackbar feedback across the app.
void showAppSnackBar(BuildContext context, String message) {
  ScaffoldMessenger.of(context).clearSnackBars();
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text(message, style: AppText.display(size: 12.5, weight: FontWeight.w600, color: Colors.white)),
      backgroundColor: AppColors.green900,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      margin: const EdgeInsets.fromLTRB(22, 0, 22, 90),
      duration: const Duration(seconds: 2),
    ),
  );
}

/// Bottom sheet listing recent announcements / notifications.
/// Opened from the bell icon on Home.
class NotificationsSheet extends StatelessWidget {
  final bool filipino;
  const NotificationsSheet({super.key, this.filipino = false});

  static void show(BuildContext context, {bool filipino = false}) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => NotificationsSheet(filipino: filipino),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: const EdgeInsets.fromLTRB(22, 14, 22, 32),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 36,
              height: 4,
              margin: const EdgeInsets.only(bottom: 16),
              decoration: BoxDecoration(color: AppColors.line, borderRadius: BorderRadius.circular(4)),
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(filipino ? 'Mga Abiso' : 'Notifications', style: AppText.display(size: 18)),
              IconButton(
                onPressed: () => Navigator.pop(context),
                icon: const Icon(Icons.close_rounded, size: 18, color: AppColors.green700),
                style: IconButton.styleFrom(backgroundColor: AppColors.green50, padding: const EdgeInsets.all(6)),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.green50,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.green50),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: const BoxDecoration(color: AppColors.surface, shape: BoxShape.circle),
                  alignment: Alignment.center,
                  child: const Icon(Icons.shield_outlined, size: 22, color: AppColors.green700),
                ),
                const SizedBox(height: 12),
                Text(
                  filipino ? 'Maligayang pagdating sa SERBIS!' : 'Welcome to SERBIS!',
                  style: AppText.display(size: 15, color: AppColors.green900),
                ),
                const SizedBox(height: 6),
                Text(
                  filipino
                      ? 'Natutuwa kaming maging bahagi ka ng komunidad ng Echague MDRRMO. '
                          'Dito mo makikita ang mga abiso at update tungkol sa iyong mga '
                          'kahilingan sa serbisyo.'
                      : "We're glad to have you with Echague MDRRMO. Updates about your "
                          'service requests and important advisories will appear here.',
                  style: AppText.body(size: 12.5, color: AppColors.green900, height: 1.6),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
