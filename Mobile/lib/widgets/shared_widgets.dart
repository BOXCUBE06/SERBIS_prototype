import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../models/advisory.dart';
import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'borrow_request_widgets.dart' show StatusBox, serviceStatusBox;

class AppHeader extends StatelessWidget {
  final VoidCallback? onNotificationsTap;
  final VoidCallback? onProfileTap;

  /// Set on a page opened from a tab (Profile, Safety guides, a service form):
  /// draws a back button ahead of the logo. Such a page shows no profile icon —
  /// there is not room for both on a 360dp phone, and the resident is already
  /// one tap from where they came from.
  final VoidCallback? onBack;

  /// Only the button labels read this; the header has no other text that
  /// changes with the language.
  final bool filipino;

  const AppHeader({
    super.key,
    this.onNotificationsTap,
    this.onProfileTap,
    this.onBack,
    this.filipino = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(AppLayout.gutter, AppLayout.headerTop, 14, 20),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.xxl)),
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
              decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: .05)),
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
                border: Border.all(color: Colors.white.withValues(alpha: .08)),
              ),
            ),
          ),
          Row(
            children: [
              if (onBack != null) ...[
                HeaderButton(
                  icon: Icons.arrow_back_rounded,
                  label: tr(filipino, 'nav.back'),
                  onTap: onBack!,
                ),
                const SizedBox(width: 4),
              ],
              Expanded(
                child: Row(
                  children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: .12),
                        borderRadius: BorderRadius.circular(AppRadius.md),
                        border: Border.all(color: Colors.white.withValues(alpha: .14)),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.shield_outlined, color: Colors.white, size: 19),
                    ),
                    const SizedBox(width: 11),
                    Flexible(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('SERBIS', style: AppText.display(size: AppTextSize.title, color: Colors.white, letterSpacing: .5)),
                          const SizedBox(height: 2),
                          Text(
                            'ECHAGUE MDRRMO',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppText.display(
                              size: AppTextSize.caption,
                              weight: FontWeight.w500,
                              color: Colors.white.withValues(alpha: .85),
                              letterSpacing: 2,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              // No unread dot: it was hardcoded on, so it announced unread news
              // on a launch where nothing had happened and stayed on after the
              // sheet was read. There is no feed to count against yet, and a
              // permanent indicator teaches residents to ignore the one that
              // will matter.
              if (onNotificationsTap != null)
                HeaderButton(
                  icon: Icons.notifications_outlined,
                  label: tr(filipino, 'nav.notifications'),
                  onTap: onNotificationsTap!,
                ),
              if (onProfileTap != null)
                HeaderButton(
                  icon: Icons.person_outline_rounded,
                  label: tr(filipino, 'nav.profile'),
                  onTap: onProfileTap!,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// A 36dp glass circle inside a 48dp touch target — the drawn size is what the
/// header was designed around, the target is what a thumb needs.
class HeaderButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  /// Small amber dot on the circle: something new behind this button. The
  /// caller decides from real state; [label] should say so too.
  final bool dot;

  const HeaderButton({super.key, required this.icon, required this.label, required this.onTap, this.dot = false});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      onTap: onTap,
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: onTap,
        child: SizedBox(
          width: 48,
          height: 48,
          child: Center(
            child: Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withValues(alpha: .10),
                border: Border.all(color: Colors.white.withValues(alpha: .30)),
              ),
              alignment: Alignment.center,
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  Icon(icon, size: 18, color: Colors.white),
                  if (dot)
                    Positioned(
                      key: const ValueKey('header-button-dot'),
                      top: -2,
                      right: -2,
                      child: Container(
                        width: 9,
                        height: 9,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: AppColors.amberDot,
                          border: Border.all(color: AppColors.green700, width: 1.5),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The green header of the tab screens: a title with a one-line subtitle that
/// folds away as the list scrolls, plus bell and profile. A page opened from a
/// tab passes [onBack] for a back arrow ahead of the title.
class TabHeader extends SliverPersistentHeaderDelegate {
  static const maxHeight = 128.0;
  static const minHeight = 96.0;

  final String title;
  final String subtitle;
  final bool filipino;
  final VoidCallback? onNotifications;
  final VoidCallback? onProfile;
  final VoidCallback? onBack;

  const TabHeader({
    required this.title,
    required this.subtitle,
    required this.filipino,
    this.onNotifications,
    this.onProfile,
    this.onBack,
  });

  @override
  double get maxExtent => maxHeight;

  @override
  double get minExtent => minHeight;

  @override
  bool shouldRebuild(TabHeader old) => old.title != title || old.subtitle != subtitle || old.filipino != filipino;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return TabHeaderBar(
      title: title,
      subtitle: subtitle,
      filipino: filipino,
      onNotifications: onNotifications,
      onProfile: onProfile,
      onBack: onBack,
      // 0 fully open, 1 fully collapsed.
      collapse: (shrinkOffset / (maxHeight - minHeight)).clamp(0.0, 1.0),
    );
  }
}

/// [TabHeader] as a plain widget, for a screen that is a column rather than a
/// scrolling list (a form with a pinned footer). [collapse] is the delegate's
/// scroll fold, 0 open to 1 folded; a column leaves it at 0.
class TabHeaderBar extends StatelessWidget {
  final String title;
  final String subtitle;
  final bool filipino;
  final VoidCallback? onNotifications;
  final VoidCallback? onProfile;
  final VoidCallback? onBack;
  final double collapse;

  const TabHeaderBar({
    super.key,
    required this.title,
    required this.subtitle,
    required this.filipino,
    this.onNotifications,
    this.onProfile,
    this.onBack,
    this.collapse = 0,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final t = collapse;
    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(onBack == null ? AppLayout.gutter : 8, AppLayout.headerTop, 14, 20 - 12 * t),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.xxl)),
      ),
      child: Row(
        children: [
          if (onBack != null) HeaderButton(icon: Icons.arrow_back_rounded, label: tr(f, 'nav.back'), onTap: onBack!),
          Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.display(
                      size: AppTextSize.headline - (AppTextSize.headline - AppTextSize.title) * t,
                      color: Colors.white,
                    ),
                  ),
                ),
                ClipRect(
                  child: Align(
                    alignment: Alignment.topLeft,
                    heightFactor: 1 - t,
                    child: Opacity(
                      opacity: 1 - t,
                      child: Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(
                          subtitle,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppText.body(size: AppTextSize.small, color: Colors.white.withValues(alpha: .85)),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (onNotifications != null)
            HeaderButton(icon: Icons.notifications_outlined, label: tr(f, 'nav.notifications'), onTap: onNotifications!),
          if (onProfile != null)
            HeaderButton(icon: Icons.person_outline_rounded, label: tr(f, 'nav.profile'), onTap: onProfile!),
        ],
      ),
    );
  }
}

/// What SERBIS is, on the two screens where the reader does not know yet.
///
/// Both auth screens explained what the button in front of them would do —
/// "log in to submit and track your service requests" — and neither said what
/// the service is. Somebody handed this app at a barangay hall arrives at a
/// login form for a system nobody has described to them.
///
/// One sentence and three capabilities, deliberately: the adviser asked for a
/// short line, not a page. It names the office, because the trust question a
/// resident has about an app asking for their address and a photo of their ID
/// is "who is this".
///
/// Both languages are printed, rather than one chosen. The language toggle
/// lives on AppState, which does not exist until a resident is logged in —
/// these two screens are the only ones in the app with no locale to read, and
/// wiring a second toggle onto them to translate one card would be the larger
/// change. A barangay hall notice is bilingual for the same reason.
///
/// The wording follows the brief: SERBIS is a **coordination system**, not an
/// emergency line. The previous copy called it "the disaster and emergency
/// service line", which promised a dispatcher on the other end of the button
/// and is not what this system is.
class ServicePurposeNote extends StatelessWidget {
  const ServicePurposeNote({super.key});

  static const _lead = 'Ang SERBIS ay isang coordination system kasama ang '
      'MDRRMO Echague.';
  static const _leadEnglish =
      'SERBIS is a coordination system run with the Echague MDRRMO.';

  /// (icon, Filipino, English) — Filipino first on the row, matching the lead.
  static const _capabilities = [
    (
      Icons.assignment_outlined,
      'Humiling ng serbisyo sa MDRRMO',
      'Request a service from the MDRRMO',
    ),
    (
      Icons.menu_book_rounded,
      'Mga gabay pangkaligtasan, offline',
      'Safety guides, available offline',
    ),
    (
      Icons.sms_rounded,
      'Tumanggap ng SMS announcements mula sa MDRRMO',
      'Receive SMS announcements from the MDRRMO',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.green50,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _lead,
            style: AppText.body(
                size: AppTextSize.small, color: AppColors.green900, height: 1.45),
          ),
          const SizedBox(height: 3),
          Text(
            _leadEnglish,
            style: AppText.body(
                size: AppTextSize.small, color: AppColors.inkMuted, height: 1.4),
          ),
          const SizedBox(height: 10),
          for (final (icon, filipino, english) in _capabilities)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Nudged down by the line's own leading so it sits with the
                  // first line of a label that wraps to two.
                  Padding(
                    padding: const EdgeInsets.only(top: 1),
                    child: Icon(icon, size: 15, color: AppColors.green700),
                  ),
                  const SizedBox(width: 8),
                  // Expanded, not a bare Text: the longest label wraps at 360
                  // and an unbounded Row overflows.
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          filipino,
                          style: AppText.body(
                              size: AppTextSize.small, color: AppColors.ink, height: 1.35),
                        ),
                        Text(
                          english,
                          style: AppText.body(
                              size: AppTextSize.caption, color: AppColors.inkFaint, height: 1.35),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class SectionHeader extends StatelessWidget {
  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  /// A small status after the title, e.g. the "Saved" pill. Ignored when an
  /// [actionLabel] is set.
  final Widget? trailing;

  const SectionHeader({super.key, required this.title, this.actionLabel, this.onAction, this.trailing});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          // Flexible, not Expanded: a short title still sits next to its action
          // instead of being pushed apart. Without it the Row demanded the
          // title's natural width and "Subaybayan ang Iyong mga Kahilingan"
          // overflowed by 22px at 360, striped banner and all.
          Flexible(
            child: Semantics(
              header: true,
              child: Text(
                title,
                style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: AppColors.sectionInk),
              ),
            ),
          ),
          if (actionLabel != null) ...[
            const SizedBox(width: 12),
            // The action is the smaller target and must stay tappable, so it
            // keeps its width and the title wraps around it.
            TextButton(
              onPressed: onAction,
              style: TextButton.styleFrom(
                minimumSize: const Size(44, 44),
                // Tighter than the default 12: the Filipino "Tingnan lahat (5)"
                // overflowed this row by 3px at 320dp.
                padding: const EdgeInsets.symmetric(horizontal: 8),
                foregroundColor: AppColors.green700,
                textStyle: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
              ),
              child: Text(actionLabel!),
            ),
          ] else if (trailing != null) ...[
            const SizedBox(width: 12),
            trailing!,
          ],
        ],
      ),
    );
  }
}
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
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: AppColors.line),
        boxShadow: AppShadow.card,
      ),
      child: leftAccent == null
          ? Padding(padding: padding, child: child)
          : Row(
              children: [
                Container(width: 4, decoration: BoxDecoration(
                  color: leftAccent,
                  borderRadius: const BorderRadius.horizontal(left: Radius.circular(AppRadius.xl)),
                )),
                Expanded(child: Padding(padding: padding, child: child)),
              ],
            ),
    );
  }
}

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
    this.radius = AppRadius.md,
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

class StatusBadge extends StatelessWidget {
  final ReqStatus status;
  final bool filipino;

  /// Overrides the wording, not the colours: a request that words its own
  /// status (an approved program says "Approved") passes it in.
  final String? label;

  /// Colour overrides for a request whose status reads differently from its
  /// enum (a trip that never arrived is amber, not Completed green).
  final Color? bg;
  final Color? fg;
  const StatusBadge(this.status, {super.key, this.filipino = false, this.label, this.bg, this.fg});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: bg ?? status.bg, borderRadius: BorderRadius.circular(AppRadius.pill)),
      child: Text(
        (label ?? status.labelFor(filipino)).toUpperCase(),
        style: AppText.display(size: AppTextSize.caption, weight: FontWeight.w700, color: fg ?? status.fg, letterSpacing: .5),
      ),
    );
  }
}

/// Shows whether a material is available offline, and offers to download it
/// when it is not.
///
/// Deliberately stateless: it used to own a `_saved` bool that a 700 ms
/// `Future.delayed` flipped, so the pill reported "Saved" over an empty disk and
/// forgot even that on the next rebuild. The truth now lives in the cache index
/// (`AppState.savedMaterials`), and this widget only draws it. [onTap] null
/// means there is nothing to download — either the content is already saved, or
/// it ships inside the app.
class OfflinePill extends StatelessWidget {
  final bool saved;
  final bool loading;
  final bool filipino;
  final VoidCallback? onTap;

  const OfflinePill({
    super.key,
    required this.saved,
    this.loading = false,
    this.filipino = false,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final String label;
    if (loading) {
      label = filipino ? 'Sine-save...' : 'Saving...';
    } else if (saved) {
      label = filipino ? 'Na-save' : 'Saved';
    } else {
      label = filipino ? 'I-download' : 'Download';
    }

    final pill = Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: AppColors.green50, borderRadius: BorderRadius.circular(AppRadius.xl)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          loading
              ? const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(strokeWidth: 1.5, color: AppColors.green700),
                )
              : Icon(saved ? Icons.check_circle_rounded : Icons.download_rounded, size: 14, color: AppColors.green700),
          const SizedBox(width: 4),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppText.display(size: AppTextSize.caption, weight: FontWeight.w700, color: AppColors.green700),
            ),
          ),
        ],
      ),
    );

    // Only a pill that does something needs the 44dp reach.
    if (onTap == null) return pill;
    return InkWell(
      onTap: loading ? null : onTap,
      borderRadius: BorderRadius.circular(AppRadius.xl),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
        child: Center(widthFactor: 1, child: pill),
      ),
    );
  }
}

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
              // Flexible so a label wider than the button wraps rather than
              // overflowing: two side-by-side buttons at 360 gave "Tingnan ang
              // Detalye" less width than it wanted. Centred so a wrapped second
              // line stays under the first rather than ragged left.
              Flexible(
                child: Text(
                  label,
                  textAlign: TextAlign.center,
                  style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: fg),
                ),
              ),
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
              minimumSize: const Size.fromHeight(48),
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
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
              minimumSize: const Size.fromHeight(48),
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
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
              minimumSize: const Size.fromHeight(48),
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
            ),
            child: child,
          ),
        );
    }
  }
}

enum AppButtonStyle { primary, outline, ghostRed }
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
          Text(widget.label, style: AppText.fieldLabel()),
          const SizedBox(height: 6),
          TextFormField(
            controller: widget.controller,
            keyboardType: widget.keyboard,
            obscureText: _hidden,
            validator: widget.validator,
            maxLength: widget.maxLength,
            inputFormatters: widget.inputFormatters,
            style: AppText.body(size: AppTextSize.bodyLg),
            decoration: InputDecoration(
              hintText: widget.hint,
              hintStyle: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkFaint),
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
              errorStyle: AppText.body(size: AppTextSize.small, color: AppColors.red600),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.sm),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.sm),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.sm),
                borderSide: const BorderSide(color: AppColors.green600, width: 1.5),
              ),
              errorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.sm),
                borderSide: const BorderSide(color: AppColors.red600, width: 1.5),
              ),
              focusedErrorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(AppRadius.sm),
                borderSide: const BorderSide(color: AppColors.red600, width: 1.5),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A request still in flight has no server reference number yet, so the copy
/// has to read naturally without one rather than printing a bare `#`.
String _refSuffix(String refNo) => refNo.isEmpty ? '' : ' #$refNo';

/// The app's confirm-or-keep dialog: a question, one line on what happens, and
/// two 48dp actions. While [busy] the confirm button keeps its label and gains
/// a spinner, so the resident still reads what they tapped.
class ConfirmDialog extends StatelessWidget {
  final String title;
  final String body;
  final String keepLabel;
  final String confirmLabel;
  final bool busy;
  final VoidCallback? onKeep;
  final VoidCallback? onConfirm;

  const ConfirmDialog({
    super.key,
    required this.title,
    required this.body,
    required this.keepLabel,
    required this.confirmLabel,
    this.busy = false,
    this.onKeep,
    this.onConfirm,
  });

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.xl)),
      title: Semantics(
        header: true,
        child: Text(title, style: AppText.display(size: AppTextSize.title, color: AppColors.sectionInk)),
      ),
      content: Text(body, style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5)),
      actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      // Two long Filipino labels stack rather than overflow on a narrow phone.
      actionsOverflowDirection: VerticalDirection.down,
      actions: [
        TextButton(
          onPressed: onKeep,
          style: TextButton.styleFrom(minimumSize: const Size(88, 48)),
          child: Text(
            keepLabel,
            style: AppText.display(
              size: AppTextSize.body,
              weight: FontWeight.w600,
              color: onKeep == null ? AppColors.inkFaint : AppColors.inkMuted,
            ),
          ),
        ),
        TextButton(
          onPressed: onConfirm,
          style: TextButton.styleFrom(
            minimumSize: const Size(88, 48),
            backgroundColor: AppColors.red50,
            foregroundColor: AppColors.red600,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (busy) ...[
                const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.red600),
                ),
                const SizedBox(width: 8),
              ],
              Flexible(
                child: Text(
                  confirmLabel,
                  style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.red600),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Asks [title] and resolves to true only when the resident confirms.
Future<bool> showConfirmDialog(
  BuildContext context, {
  required String title,
  required String body,
  required String keepLabel,
  required String confirmLabel,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (ctx) => ConfirmDialog(
      title: title,
      body: body,
      keepLabel: keepLabel,
      confirmLabel: confirmLabel,
      onKeep: () => Navigator.pop(ctx, false),
      onConfirm: () => Navigator.pop(ctx, true),
    ),
  );
  return confirmed == true;
}

/// [onConfirmed] must resolve to `true` only once the server has accepted the
/// cancellation. The success message waits for it — the previous version fired
/// the "has been cancelled" snackbar the instant the button was tapped, before
/// any HTTP call had run and regardless of its outcome.
void showCancelDialog(
  BuildContext context,
  String refNo,
  Future<bool> Function() onConfirmed, {
  bool filipino = false,
}) {
  showDialog(
    context: context,
    // The request is mid-flight once confirm is tapped; dismissing the dialog
    // under it would leave the resident with no answer either way.
    barrierDismissible: false,
    builder: (ctx) => _CancelDialog(
      refNo: refNo,
      filipino: filipino,
      onConfirmed: onConfirmed,
    ),
  );
}

class _CancelDialog extends StatefulWidget {
  final String refNo;
  final bool filipino;
  final Future<bool> Function() onConfirmed;

  const _CancelDialog({
    required this.refNo,
    required this.filipino,
    required this.onConfirmed,
  });

  @override
  State<_CancelDialog> createState() => _CancelDialogState();
}

class _CancelDialogState extends State<_CancelDialog> {
  bool _busy = false;

  Future<void> _confirm() async {
    if (_busy) {
      return;
    }
    setState(() => _busy = true);

    // Resolved before the await: the messenger lives above this dialog's route
    // and survives the pop, while this State's context does not.
    final messenger = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);

    final cancelled = await widget.onConfirmed();

    if (!mounted) {
      return;
    }
    navigator.pop();

    if (cancelled) {
      final f = widget.filipino;
      showAppSnackBarOn(
        messenger,
        f
            ? 'Nakansela na ang kahilingan${_refSuffix(widget.refNo)}.'
            : 'Request${_refSuffix(widget.refNo)} has been cancelled.',
      );
    }
    // On failure the store has already rolled the row back and set lastError,
    // which the shell drains into a red snackbar. Saying anything here would
    // duplicate it.
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.filipino;
    return PopScope(
      canPop: !_busy,
      child: ConfirmDialog(
        title: f ? 'Kanselahin ang kahilingan?' : 'Cancel request?',
        body: f
            ? 'Sigurado ka bang ikakansela ang kahilingan${_refSuffix(widget.refNo)}? Hindi na maibabalik ang aksyon na ito.'
            : 'Are you sure you want to cancel request${_refSuffix(widget.refNo)}? This action cannot be undone.',
        keepLabel: f ? 'Panatilihin' : 'Keep request',
        confirmLabel: f ? 'Kanselahin' : 'Cancel request',
        busy: _busy,
        onKeep: _busy ? null : () => Navigator.pop(context),
        onConfirm: _busy ? null : _confirm,
      ),
    );
  }
}

void showAppSnackBar(BuildContext context, String message, {bool isError = false}) {
  showAppSnackBarOn(ScaffoldMessenger.of(context), message, isError: isError);
}

/// Same snackbar, addressed to a messenger captured before an await. A caller
/// that pops its own route first has no usable BuildContext left.
void showAppSnackBarOn(ScaffoldMessengerState messenger, String message, {bool isError = false}) {
  messenger.clearSnackBars();
  messenger.showSnackBar(
    SnackBar(
      content: Text(message, style: AppText.display(size: AppTextSize.small, weight: FontWeight.w600, color: Colors.white)),
      backgroundColor: isError ? AppColors.red600 : AppColors.green900,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
      margin: const EdgeInsets.fromLTRB(AppLayout.gutter, 0, AppLayout.gutter, AppLayout.snackBarClearance),
      // Failures need longer on screen than confirmations.
      duration: Duration(seconds: isError ? 4 : 2),
    ),
  );
}

/// The bell sheet.
///
/// It used to hold one hardcoded welcome message and nothing else, so a
/// resident who tapped the bell during a flood read "We're glad to have you
/// with Echague MDRRMO". It then showed the resident's own request updates and
/// said plainly that MDRRMO advisories were not sent here.
///
/// They are now. The blasts the agency texted this resident come first, above
/// the request updates: a warning outranks a status change, and burying one
/// under "Request resolved" is the failure mode this sheet exists to avoid.
class NotificationsSheet extends StatelessWidget {
  final bool filipino;
  final List<ServiceRequest> requests;
  final List<Advisory> advisories;

  /// Non-null when the advisory fetch failed. Rendered instead of "none sent" —
  /// those two look identical on screen and mean opposite things.
  final String? advisoriesError;

  const NotificationsSheet({
    super.key,
    this.filipino = false,
    this.requests = const [],
    this.advisories = const [],
    this.advisoriesError,
  });

  static void show(
    BuildContext context, {
    bool filipino = false,
    List<ServiceRequest> requests = const [],
    List<Advisory> advisories = const [],
    String? advisoriesError,
  }) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => NotificationsSheet(
        filipino: filipino,
        requests: requests,
        advisories: advisories,
        advisoriesError: advisoriesError,
      ),
    );
  }

  /// Newest movement first — that is the order a notification list is read in.
  /// A request with no timestamps sorts last rather than to the top.
  List<ServiceRequest> get _ordered {
    final epoch = DateTime.fromMillisecondsSinceEpoch(0);
    return [...requests]..sort((a, b) {
        final left = a.updatedAt ?? a.createdAt ?? epoch;
        final right = b.updatedAt ?? b.createdAt ?? epoch;
        return right.compareTo(left);
      });
  }

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
      ),
      padding: EdgeInsets.fromLTRB(AppLayout.gutter, 14, AppLayout.gutter, 16 + MediaQuery.paddingOf(context).bottom),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 36,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(color: AppColors.line, borderRadius: BorderRadius.circular(AppRadius.pill)),
                ),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Flexible(
                    child: Semantics(
                      header: true,
                      child: Text(
                        f ? 'Mga abiso' : 'Notifications',
                        style: AppText.display(size: AppTextSize.headline, color: AppColors.sectionInk),
                      ),
                    ),
                  ),
                  IconButton(
                    onPressed: () => Navigator.pop(context),
                    tooltip: tr(f, 'common.close'),
                    icon: const Icon(Icons.close_rounded, size: 22, color: AppColors.green700),
                    style: IconButton.styleFrom(
                      backgroundColor: AppColors.green50,
                      minimumSize: const Size(44, 44),
                      fixedSize: const Size(44, 44),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              // Bounded so a resident with a long history gets a scrollable sheet
              // instead of one that runs off the screen. Both sections share the
              // one scroll view — two independently scrolling lists in a sheet is
              // how the advisory section ends up a 40-pixel window.
              ConstrainedBox(
                constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .6),
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    _SectionLabel(text: tr(f, 'notif.advisories')),
                    if (advisoriesError != null)
                      // Never "no advisories" on a failed fetch. The wording says
                      // outright that silence here is not an all-clear.
                      StatusBox(
                        icon: Icons.wifi_off_rounded,
                        bg: AppColors.red50,
                        fg: AppColors.red600,
                        title: tr(f, 'notif.adv_failed_title'),
                        next: tr(f, 'notif.adv_failed'),
                      )
                    else if (advisories.isEmpty)
                      StatusBox(
                        icon: Icons.campaign_outlined,
                        bg: AppColors.green50,
                        fg: AppColors.green700,
                        title: tr(f, 'notif.adv_none_title'),
                        next: tr(f, 'notif.adv_none'),
                      )
                    else
                      for (final advisory in advisories) _AdvisoryTile(advisory: advisory, filipino: f),
                    const SizedBox(height: AppSpacing.lg),
                    _SectionLabel(text: tr(f, 'notif.your_requests')),
                    if (requests.isEmpty)
                      StatusBox(
                        icon: Icons.notifications_none_rounded,
                        bg: AppColors.green50,
                        fg: AppColors.green700,
                        title: tr(f, 'notif.empty_title'),
                        next: tr(f, 'notif.empty_body'),
                      )
                    else
                      for (final request in _ordered) _RequestUpdateTile(request: request, filipino: f),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              // Says what this list is and is not, so nobody reads it as more than
              // it covers.
              Text(
                tr(f, 'notif.scope_note'),
                style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.5),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  final String text;

  const _SectionLabel({required this.text});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Semantics(
        header: true,
        child: Text(text, style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: AppColors.sectionInk)),
      ),
    );
  }
}

/// One advisory the agency texted this resident: marked by its own border and
/// megaphone as well as its tint, never by colour alone.
class _AdvisoryTile extends StatelessWidget {
  final Advisory advisory;
  final bool filipino;

  const _AdvisoryTile({required this.advisory, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final at = advisory.sentAt;

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.red50,
        border: Border.all(color: AppColors.red600, width: 1.2),
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.campaign_rounded, size: 24, color: AppColors.red600),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  at == null ? tr(filipino, 'notif.adv_date_unknown') : formatTimelineTime(at, filipino),
                  style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                ),
                const SizedBox(height: 4),
                // The agency's own words, unmodified and never truncated: the
                // instruction a resident has to act on is often the last line.
                Text(
                  advisory.message,
                  style: AppText.body(size: AppTextSize.body, color: AppColors.ink, height: 1.5),
                ),
                if (advisory.barangay != null && advisory.barangay!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    advisory.barangay!,
                    style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// One of the resident's own requests: title, when it last moved, and the
/// plain-language status box Track uses.
class _RequestUpdateTile extends StatelessWidget {
  final ServiceRequest request;
  final bool filipino;

  const _RequestUpdateTile({required this.request, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final at = request.updatedAt ?? request.createdAt;

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.cardBorder),
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(request.displayTitle(filipino), style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
          const SizedBox(height: 2),
          Text(
            [
              if (request.refNo.isNotEmpty) request.refNo,
              at == null ? tr(filipino, 'timeline.time_unknown') : formatTimelineTime(at, filipino),
            ].join(' · '),
            style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
          ),
          const SizedBox(height: AppSpacing.sm),
          serviceStatusBox(request, filipino),
        ],
      ),
    );
  }
}
