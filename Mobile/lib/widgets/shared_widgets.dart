import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../models/advisory.dart';
import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'borrow_request_widgets.dart' show serviceStatus;
import 'request_summary.dart' show SummaryCard;
import 'loading.dart' show AppSpinner;
import 'status_line.dart';

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
        color: AppColors.header,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.header)),
      ),
      child: Row(
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
                          border: Border.all(color: AppColors.header, width: 1.5),
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
/// tab passes [onBack] for a back arrow ahead of the title. [bottom] (a search
/// box, a segmented control) sits under the title and stays pinned with it.
class TabHeader extends SliverPersistentHeaderDelegate {
  static const maxHeight = 128.0;
  static const minHeight = 96.0;

  /// What a [bottom] adds: 52 for the control, 14 above it.
  static const bottomHeight = 66.0;

  final String title;
  final String subtitle;
  final bool filipino;
  final VoidCallback? onNotifications;
  final VoidCallback? onProfile;
  final VoidCallback? onBack;
  final Widget? bottom;

  const TabHeader({
    required this.title,
    required this.subtitle,
    required this.filipino,
    this.onNotifications,
    this.onProfile,
    this.onBack,
    this.bottom,
  });

  double get _extra => bottom == null ? 0 : bottomHeight;

  @override
  double get maxExtent => maxHeight + _extra;

  @override
  double get minExtent => minHeight + _extra;

  @override
  bool shouldRebuild(TabHeader old) =>
      old.title != title || old.subtitle != subtitle || old.filipino != filipino || old.bottom != bottom;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return TabHeaderBar(
      title: title,
      subtitle: subtitle,
      filipino: filipino,
      onNotifications: onNotifications,
      onProfile: onProfile,
      onBack: onBack,
      bottom: bottom,
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

  /// Only inside a [TabHeader], which gives the bar a fixed height.
  final Widget? bottom;

  const TabHeaderBar({
    super.key,
    required this.title,
    required this.subtitle,
    required this.filipino,
    this.onNotifications,
    this.onProfile,
    this.onBack,
    this.collapse = 0,
    this.bottom,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final t = collapse;
    // The gradient runs edge to edge; the content keeps to the same 600dp
    // column as the cards below, so on a wide screen the title lines up with them.
    final inset = ((MediaQuery.sizeOf(context).width - 600) / 2).clamp(0.0, double.infinity);
    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(inset + (onBack == null ? AppLayout.gutter : 8), AppLayout.headerTop, inset + 14, 20 - 12 * t),
      decoration: const BoxDecoration(
        color: AppColors.header,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.header)),
      ),
      child: _withBottom(Row(
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
                      size: AppTextSize.pageTitle - (AppTextSize.pageTitle - AppTextSize.title) * t,
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
      )),
    );
  }

  /// The title row over [bottom]; the bottom lines up with the gutter on the
  /// right, where the row leaves room for the 48dp header buttons.
  Widget _withBottom(Widget row) {
    if (bottom == null) return row;
    return Column(
      children: [
        Expanded(child: row),
        const SizedBox(height: 14),
        Padding(padding: const EdgeInsets.only(right: AppLayout.gutter - 14), child: SizedBox(height: 52, child: bottom)),
      ],
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

  /// How many rows the section holds, in muted figures after the title.
  final int? count;

  const SectionHeader({super.key, required this.title, this.actionLabel, this.onAction, this.trailing, this.count});

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
              child: Text.rich(
                TextSpan(children: [
                  TextSpan(text: title),
                  if (count != null)
                    TextSpan(
                      text: '  $count',
                      style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted)
                          .copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
                    ),
                ]),
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
        style: AppText.display(size: AppTextSize.caption, weight: FontWeight.w600, color: fg ?? status.fg, letterSpacing: .5),
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
              style: AppText.display(size: AppTextSize.caption, weight: FontWeight.w600, color: AppColors.green700),
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

/// The app's full-width 52dp button.
///
/// While [loading] it keeps its colours and its words ([label], or
/// [loadingLabel] such as "Sending request…") beside a spinner, and taps are
/// blocked. A button disabled for any other reason says why in [disabledHint],
/// printed above it: a grey button with no reason reads as broken.
class AppButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final AppButtonStyle style;
  final IconData? icon;
  final bool loading;
  final String? loadingLabel;
  final String? disabledHint;

  const AppButton({
    super.key,
    required this.label,
    this.onPressed,
    this.style = AppButtonStyle.primary,
    this.icon,
    this.loading = false,
    this.loadingLabel,
    this.disabledHint,
  });

  @override
  Widget build(BuildContext context) {
    final (Color bg, Color fg, BorderSide? side) = switch (style) {
      AppButtonStyle.primary => (AppColors.green700, Colors.white, null),
      AppButtonStyle.destructive => (AppColors.red600, Colors.white, null),
      AppButtonStyle.outline => (AppColors.surface, AppColors.ink, const BorderSide(color: AppColors.fieldBorder)),
      AppButtonStyle.cancel => (AppColors.surface, AppColors.red600, const BorderSide(color: AppColors.redBorder)),
    };
    final filled = side == null;
    // Sending keeps full colour; only a real "can't" fades.
    final offBg = loading || !filled ? bg : bg.withValues(alpha: .45);
    final offFg = loading || filled ? fg : fg.withValues(alpha: .45);

    final content = Row(
      mainAxisAlignment: MainAxisAlignment.center,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (loading)
          Padding(
            padding: const EdgeInsets.only(right: 10),
            child: AppSpinner(color: fg, size: 18),
          )
        else if (icon != null)
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Icon(icon, size: 18, color: onPressed == null ? offFg : fg),
          ),
        // Flexible so a long Filipino label wraps instead of overflowing;
        // centred so a wrapped second line sits under the first.
        Flexible(
          child: Text(loading ? (loadingLabel ?? label) : label, textAlign: TextAlign.center),
        ),
      ],
    );

    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md));
    final textStyle = AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600);
    final effectiveOnPressed = loading ? null : onPressed;

    final Widget button = filled
        ? ElevatedButton(
            onPressed: effectiveOnPressed,
            style: ElevatedButton.styleFrom(
              backgroundColor: bg,
              foregroundColor: fg,
              disabledBackgroundColor: offBg,
              disabledForegroundColor: offFg,
              minimumSize: const Size.fromHeight(52),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              shape: shape,
              elevation: 0,
              textStyle: textStyle,
            ),
            child: content,
          )
        : OutlinedButton(
            onPressed: effectiveOnPressed,
            style: OutlinedButton.styleFrom(
              backgroundColor: bg,
              foregroundColor: fg,
              disabledForegroundColor: offFg,
              side: side,
              minimumSize: const Size.fromHeight(52),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              shape: shape,
              textStyle: textStyle,
            ),
            child: content,
          );

    final sized = SizedBox(width: double.infinity, child: button);
    if (loading || onPressed != null || disabledHint == null) return sized;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Text(
            disabledHint!,
            textAlign: TextAlign.center,
            style: AppText.body(size: AppTextSize.detail, color: AppColors.inkMuted, height: 1.4),
          ),
        ),
        sized,
      ],
    );
  }
}

/// [primary] green fill; [outline] the secondary choice; [cancel] a red
/// outline (Cancel request, Log out); [destructive] a red fill, dialogs only.
enum AppButtonStyle { primary, outline, cancel, destructive }

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

/// The app's confirm-or-keep dialog: an icon, a question, one line on what
/// happens, then the main action on top and the safe choice under it, both
/// full width. While [busy] the confirm button keeps its label and gains a
/// spinner, so the resident still reads what they tapped.
class ConfirmDialog extends StatelessWidget {
  final String title;
  final String body;
  final String keepLabel;
  final String confirmLabel;
  final bool busy;
  final VoidCallback? onKeep;
  final VoidCallback? onConfirm;

  /// Red confirm for something that cannot be taken back (cancel, discard);
  /// green for a change that is just worth a second look.
  final bool destructive;

  /// Drawn in a tinted circle above the title. Defaults to a warning sign for
  /// [destructive], an info mark otherwise.
  final IconData? icon;

  const ConfirmDialog({
    super.key,
    required this.title,
    required this.body,
    required this.keepLabel,
    required this.confirmLabel,
    this.busy = false,
    this.onKeep,
    this.onConfirm,
    this.destructive = true,
    this.icon,
  });

  @override
  Widget build(BuildContext context) {
    final tint = destructive ? AppColors.red50 : AppColors.green50;
    final fg = destructive ? AppColors.red600 : AppColors.green700;
    return AlertDialog(
      backgroundColor: AppColors.surface,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      insetPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
      contentPadding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
      scrollable: true,
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: tint, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: Icon(
              icon ?? (destructive ? Icons.warning_amber_rounded : Icons.info_outline_rounded),
              size: 22,
              color: fg,
            ),
          ),
          const SizedBox(height: 12),
          Semantics(header: true, child: Text(title, style: AppText.cardTitle())),
          const SizedBox(height: 8),
          Text(body, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
          const SizedBox(height: 18),
          AppButton(
            label: confirmLabel,
            style: destructive ? AppButtonStyle.destructive : AppButtonStyle.primary,
            loading: busy,
            onPressed: onConfirm,
          ),
          const SizedBox(height: AppSpacing.sm),
          AppButton(label: keepLabel, style: AppButtonStyle.outline, onPressed: onKeep),
        ],
      ),
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
  bool destructive = true,
  IconData? icon,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (ctx) => ConfirmDialog(
      title: title,
      body: body,
      keepLabel: keepLabel,
      confirmLabel: confirmLabel,
      destructive: destructive,
      icon: icon,
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

/// The app's toast: dark, at the bottom, four seconds, with an optional action
/// ("View", "Try again").
void showAppSnackBar(
  BuildContext context,
  String message, {
  bool isError = false,
  String? actionLabel,
  VoidCallback? onAction,
}) {
  showAppSnackBarOn(ScaffoldMessenger.of(context), message,
      isError: isError, actionLabel: actionLabel, onAction: onAction);
}

/// Same toast, addressed to a messenger captured before an await. A caller
/// that pops its own route first has no usable BuildContext left.
void showAppSnackBarOn(
  ScaffoldMessengerState messenger,
  String message, {
  bool isError = false,
  String? actionLabel,
  VoidCallback? onAction,
}) {
  messenger.clearSnackBars();
  messenger.showSnackBar(
    SnackBar(
      content: Row(
        children: [
          Icon(
            isError ? Icons.warning_amber_rounded : Icons.check_rounded,
            size: 20,
            color: isError ? AppColors.toastWarn : AppColors.toastAction,
          ),
          const SizedBox(width: 12),
          Expanded(child: Text(message, style: AppText.body(color: Colors.white, height: 1.4))),
        ],
      ),
      action: actionLabel == null
          ? null
          : SnackBarAction(label: actionLabel, textColor: AppColors.toastAction, onPressed: onAction ?? () {}),
      // With an action Flutter would otherwise keep it up until tapped.
      persist: false,
      backgroundColor: AppColors.toast,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
      margin: const EdgeInsets.fromLTRB(AppLayout.gutter, 0, AppLayout.gutter, AppLayout.snackBarClearance),
      duration: const Duration(seconds: 4),
    ),
  );
}

/// The frame every bottom sheet shares: rounded top, a grab handle, a heading, a
/// 600dp column and the bottom safe area. [scrollable] wraps the content in a
/// scroll view, for a sheet that holds a form.
class SheetFrame extends StatelessWidget {
  final String? title;
  final Widget child;
  final bool scrollable;

  const SheetFrame({super.key, this.title, required this.child, this.scrollable = false});

  @override
  Widget build(BuildContext context) {
    final body = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(
          child: Container(
            width: 36,
            height: 4,
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(color: AppColors.line, borderRadius: BorderRadius.circular(AppRadius.pill)),
          ),
        ),
        if (title != null) ...[
          Semantics(
            header: true,
            child: Text(title!, style: AppText.display(size: AppTextSize.headline, color: AppColors.sectionInk)),
          ),
          const SizedBox(height: AppSpacing.md),
        ],
        child,
      ],
    );

    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
      ),
      padding: EdgeInsets.fromLTRB(AppLayout.gutter, 14, AppLayout.gutter, 16 + MediaQuery.paddingOf(context).bottom),
      child: Align(
        alignment: Alignment.topCenter,
        heightFactor: 1,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: scrollable ? SingleChildScrollView(child: body) : body,
        ),
      ),
    );
  }
}

/// A sheet's top: grab handle, title, an optional one-line subtitle and a 44dp
/// close. For the sheets whose body scrolls under a fixed header (the bell,
/// Edit my details), which [SheetFrame] cannot do.
class SheetHeader extends StatelessWidget {
  final String title;
  final String? subtitle;
  final bool filipino;

  const SheetHeader({super.key, required this.title, this.subtitle, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(
          child: Container(
            width: 36,
            height: 4,
            margin: const EdgeInsets.only(bottom: 10),
            decoration: BoxDecoration(color: AppColors.line, borderRadius: BorderRadius.circular(AppRadius.pill)),
          ),
        ),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Semantics(
                    header: true,
                    child: Text(title, style: AppText.display(size: AppTextSize.headline, color: AppColors.sectionInk)),
                  ),
                  if (subtitle != null) ...[
                    const SizedBox(height: 4),
                    Text(subtitle!, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
                  ],
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.md),
            IconButton(
              onPressed: () => Navigator.pop(context),
              tooltip: tr(filipino, 'common.close'),
              icon: const Icon(Icons.close_rounded, size: 22, color: AppColors.ink),
              style: IconButton.styleFrom(
                backgroundColor: AppColors.grey50,
                minimumSize: const Size(44, 44),
                fixedSize: const Size(44, 44),
              ),
            ),
          ],
        ),
      ],
    );
  }
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
///
/// Requests are the last 30 days only, split at [seenAt] into "New" and
/// "Earlier". Each row opens the request in Track, where the full card is.
class NotificationsSheet extends StatelessWidget {
  final bool filipino;
  final List<ServiceRequest> requests;
  final List<Advisory> advisories;

  /// Non-null when the advisory fetch failed. Rendered instead of "none sent" —
  /// those two look identical on screen and mean opposite things.
  final String? advisoriesError;

  /// When the sheet was last opened, read before this opening marks it. Null
  /// counts every request as new, the same rule the bell's dot uses.
  final DateTime? seenAt;

  /// Opens a request in Track. The sheet closes itself first.
  final ValueChanged<ServiceRequest>? onOpenRequest;

  /// The 30-day window is measured from this; tests pass a fixed one.
  final DateTime? now;

  static const recentWindow = Duration(days: 30);

  const NotificationsSheet({
    super.key,
    this.filipino = false,
    this.requests = const [],
    this.advisories = const [],
    this.advisoriesError,
    this.seenAt,
    this.onOpenRequest,
    this.now,
  });

  static void show(
    BuildContext context, {
    bool filipino = false,
    List<ServiceRequest> requests = const [],
    List<Advisory> advisories = const [],
    String? advisoriesError,
    DateTime? seenAt,
    ValueChanged<ServiceRequest>? onOpenRequest,
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
        seenAt: seenAt,
        onOpenRequest: onOpenRequest,
      ),
    );
  }

  static DateTime? _movedAt(ServiceRequest r) => r.updatedAt ?? r.createdAt;

  /// Moved within the window, newest movement first. A request with no
  /// timestamp cannot be placed in the window, so it is left to Track.
  List<ServiceRequest> get _recent {
    final since = (now ?? DateTime.now()).subtract(recentWindow);
    return requests.where((r) => _movedAt(r)?.isAfter(since) ?? false).toList()
      ..sort((a, b) => _movedAt(b)!.compareTo(_movedAt(a)!));
  }

  bool _isNew(ServiceRequest r) => seenAt == null || _movedAt(r)!.isAfter(seenAt!);

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final recent = _recent;
    final fresh = recent.where(_isNew).toList();
    final earlier = recent.where((r) => !_isNew(r)).toList();

    Widget capped(Widget child) => Align(
          alignment: Alignment.topCenter,
          heightFactor: 1,
          child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 600), child: child),
        );

    Widget rows(List<ServiceRequest> list, {required bool unread}) => SummaryCard(children: [
          for (final r in list)
            _RequestUpdateRow(
              request: r,
              filipino: f,
              unread: unread,
              onTap: onOpenRequest == null
                  ? null
                  : () {
                      Navigator.pop(context);
                      onOpenRequest!(r);
                    },
            ),
        ]);

    return Container(
      width: double.infinity,
      clipBehavior: Clip.antiAlias,
      decoration: const BoxDecoration(
        color: AppColors.paper,
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 12, AppLayout.gutter, 14),
            decoration: const BoxDecoration(
              color: AppColors.surface,
              border: Border(bottom: BorderSide(color: AppColors.cardBorder)),
            ),
            child: capped(SheetHeader(
              title: tr(f, 'nav.notifications'),
              subtitle: tr(f, 'notif.subtitle'),
              filipino: f,
            )),
          ),
          // Bounded so a long history scrolls inside the sheet instead of running
          // off the screen. Both sections share the one scroll view — two
          // independently scrolling lists in a sheet is how the advisory section
          // ends up a 40-pixel window.
          Flexible(
            child: ConstrainedBox(
              constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .6),
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.fromLTRB(AppLayout.gutter, AppSpacing.lg, AppLayout.gutter, AppSpacing.sm),
                children: [
                  capped(Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _SectionLabel(text: tr(f, 'notif.advisories')),
                      if (advisoriesError != null)
                        // Never "no advisories" on a failed fetch. The wording says
                        // outright that silence here is not an all-clear. No
                        // Try again: the sheet has no fetch of its own to rerun.
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
                              StatusLine(label: tr(f, 'notif.adv_failed_title'), tone: StatusTone.red),
                              const SizedBox(height: 6),
                              Text(tr(f, 'notif.adv_failed'), style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
                            ],
                          ),
                        )
                      else if (advisories.isEmpty)
                        _MutedLine(text: tr(f, 'notif.adv_none'))
                      else
                        for (final advisory in advisories) _AdvisoryTile(advisory: advisory, filipino: f),
                      const SizedBox(height: AppSpacing.lg),
                      if (recent.isEmpty) ...[
                        _SectionLabel(text: tr(f, 'notif.your_requests')),
                        _MutedLine(text: tr(f, 'notif.requests_none')),
                      ],
                      if (fresh.isNotEmpty) ...[
                        _SectionLabel(text: tr(f, 'notif.new')),
                        rows(fresh, unread: true),
                        const SizedBox(height: AppSpacing.lg),
                      ],
                      if (earlier.isNotEmpty) ...[
                        _SectionLabel(text: tr(f, 'notif.earlier')),
                        rows(earlier, unread: false),
                      ],
                    ],
                  )),
                ],
              ),
            ),
          ),
          // Says what this list covers and what it does not, so nobody reads it
          // as more than that.
          Padding(
            padding: EdgeInsets.fromLTRB(AppLayout.gutter, AppSpacing.sm, AppLayout.gutter, 16 + MediaQuery.paddingOf(context).bottom),
            child: capped(Text(
              tr(f, 'notif.scope_note'),
              textAlign: TextAlign.center,
              style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.5),
            )),
          ),
        ],
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
        child: Text(text, style: AppText.display(size: AppTextSize.section, weight: FontWeight.w600, color: AppColors.sectionInk)),
      ),
    );
  }
}

/// An empty section, said in one quiet line rather than a coloured box.
class _MutedLine extends StatelessWidget {
  final String text;

  const _MutedLine({required this.text});

  @override
  Widget build(BuildContext context) {
    return Text(text, style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5));
  }
}

/// One advisory the agency texted this resident: marked by its megaphone and
/// the heading above it, never by colour alone.
class _AdvisoryTile extends StatelessWidget {
  final Advisory advisory;
  final bool filipino;

  const _AdvisoryTile({required this.advisory, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final at = advisory.sentAt;
    final barangay = advisory.barangay?.trim() ?? '';
    final meta = [
      at == null ? tr(filipino, 'notif.adv_date_unknown') : formatTimelineTime(at, filipino),
      if (barangay.isNotEmpty) barangay,
    ].join(' · ');

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.fromLTRB(AppSpacing.lg, 14, AppSpacing.lg, 14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.cardBorder),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.campaign_rounded, size: 20, color: AppColors.amberInk),
              const SizedBox(width: AppSpacing.sm),
              Expanded(child: Text(meta, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted))),
            ],
          ),
          const SizedBox(height: 6),
          // The agency's own words, unmodified and never truncated: the
          // instruction a resident has to act on is often the last line.
          Text(
            advisory.message,
            style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.ink, height: 1.5),
          ),
        ],
      ),
    );
  }
}

/// One of the resident's own requests, compact: title, reference and when it
/// last moved, and its status in Track's wording.
class _RequestUpdateRow extends StatelessWidget {
  final ServiceRequest request;
  final bool filipino;
  final bool unread;
  final VoidCallback? onTap;

  const _RequestUpdateRow({required this.request, required this.filipino, required this.unread, this.onTap});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final at = request.updatedAt ?? request.createdAt;
    // Same words and colours as the Track card this row opens.
    final status = serviceStatus(request, f);

    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 72),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 14, 12, 14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: unread
                    ? Semantics(
                        label: tr(f, 'notif.unread'),
                        child: Container(
                          width: 10,
                          height: 10,
                          decoration: const BoxDecoration(color: AppColors.blue600, shape: BoxShape.circle),
                        ),
                      )
                    : const SizedBox(width: 10),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(request.displayTitle(f), style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
                    const SizedBox(height: 2),
                    Text(
                      [
                        if (request.refNo.isNotEmpty) request.refNo,
                        at == null ? tr(f, 'timeline.time_unknown') : formatTimelineTime(at, f),
                      ].join(' · '),
                      style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                    ),
                    const SizedBox(height: 6),
                    StatusLine(label: status.label, tone: status.tone),
                  ],
                ),
              ),
              if (onTap != null) ...[
                const SizedBox(width: AppSpacing.sm),
                const Padding(
                  padding: EdgeInsets.only(top: 2),
                  child: Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
