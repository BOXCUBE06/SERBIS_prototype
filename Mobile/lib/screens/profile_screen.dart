
library serbis.screens.profile;

import 'dart:typed_data';

import 'package:file_picker/file_picker.dart' as fp;
import 'package:flutter/material.dart';
import '../data/safety_files.dart';
import '../state/account_store.dart';
import '../state/api_service.dart';
import '../state/material_cache.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/form_inputs.dart';
import '../widgets/shared_widgets.dart';
import 'library/article_reader_screen.dart';

class ProfileScreen extends StatefulWidget {
  final AppState appState;
  final UserStore userStore;
  final AppUser user;

  /// Fired when the resident changes their own photo, so the shell's copy of
  /// the profile does not go stale behind this screen.
  final ValueChanged<AppUser> onUserChanged;
  final VoidCallback onLogout;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  const ProfileScreen({
    super.key,
    required this.appState,
    required this.userStore,
    required this.user,
    required this.onUserChanged,
    required this.onLogout,
    required this.onOpenNotifications,
    required this.onOpenProfile,
  });

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  /// Straight off the signed-in resident, with no invented fallback. The
  /// defaults used to be `'Juan Delacruz'` and `'Echague, Isabela'`, so a
  /// profile that failed to load showed a plausible name and a municipality
  /// that is not a barangay — and the barangay on the resident row is what
  /// every request is dispatched on. A missing value now says so.
  String get _name => widget.user.fullName.trim();
  String get _email => widget.user.email.trim();
  String get _address => widget.user.address.trim();

  /// The decoded photo, held here rather than re-fetched on every rebuild. The
  /// route is authenticated, so this is bytes and not a URL — see
  /// [ApiService.fetchProfilePhoto].
  Uint8List? _photo;
  bool _photoBusy = false;

  /// In flight on `PATCH /me`. The switch's value is never held here — it is
  /// read from [widget.user], which the shell owns — so a rejected change has
  /// nothing local to roll back and the control simply stays where the server
  /// left it.
  bool _smsBusy = false;

  @override
  void initState() {
    super.initState();
    _loadPhoto();
  }

  @override
  void didUpdateWidget(ProfileScreen old) {
    super.didUpdateWidget(old);
    if (old.user.hasPhoto != widget.user.hasPhoto ||
        old.user.id != widget.user.id) {
      _loadPhoto();
    }
  }

  Future<void> _loadPhoto() async {
    if (!widget.user.hasPhoto || widget.user.id.isEmpty) {
      if (mounted) setState(() => _photo = null);
      return;
    }

    final bytes = await widget.userStore.profilePhoto(widget.user.id);
    if (!mounted) return;
    setState(() => _photo = bytes == null ? null : Uint8List.fromList(bytes));
  }

  Future<void> _pickPhoto(bool filipino) async {
    if (_photoBusy) return;

    final result = await fp.FilePicker.platform.pickFiles(
      type: fp.FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png'],
      withData: true, // ensures .bytes is populated (needed on web)
    );

    final file = result?.files.isNotEmpty == true ? result!.files.first : null;
    if (file?.bytes == null) {
      return;
    }

    setState(() => _photoBusy = true);
    try {
      final updated = await widget.userStore.setProfilePhoto(
        bytes: file!.bytes!,
        fileName: file.name,
      );
      if (!mounted) return;
      // Show the bytes that were just uploaded rather than fetching them back:
      // the resident already chose this image, and a round trip on a rural
      // connection is a visible delay for no new information.
      setState(() => _photo = Uint8List.fromList(file.bytes!));
      widget.onUserChanged(updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      _say(e.message);
    } finally {
      if (mounted) setState(() => _photoBusy = false);
    }
  }

  Future<void> _removePhoto(bool filipino) async {
    if (_photoBusy) return;

    setState(() => _photoBusy = true);
    try {
      final updated = await widget.userStore.removeProfilePhoto();
      if (!mounted) return;
      setState(() => _photo = null);
      widget.onUserChanged(updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      _say(e.message);
    } finally {
      if (mounted) setState(() => _photoBusy = false);
    }
  }

  /// Turns the MDRRMO's text blasts on or off for this resident.
  ///
  /// Same shape as the photo actions above: a busy flag, the store call, the
  /// refreshed profile handed up to the shell, and an [ApiException] shown in a
  /// snackbar. Nothing is written optimistically — the switch reads
  /// `widget.user.smsOptIn`, so on a failure it is already showing what the
  /// server still holds, which is the honest state. Telling a resident they
  /// have opted out when the request never landed is the exact failure the two
  /// deleted switches used to have.
  Future<void> _setSmsOptIn(bool value) async {
    if (_smsBusy) return;

    setState(() => _smsBusy = true);
    try {
      final updated = await widget.userStore.updateProfile(smsOptIn: value);
      if (!mounted) return;
      widget.onUserChanged(updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      _say(e.message);
    } finally {
      // In the finally rather than each branch: an exception the catch does not
      // name would otherwise leave the row spinning with no way back.
      if (mounted) setState(() => _smsBusy = false);
    }
  }

  void _say(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  /// One sheet for both actions, so "remove" is only offered when there is
  /// something to remove.
  Future<void> _showPhotoActions(BuildContext context, bool filipino) {
    return showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        padding: const EdgeInsets.fromLTRB(22, 14, 22, 28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 36,
                height: 4,
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: AppColors.line,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
            Text(tr(filipino, 'profile.photo'), style: AppText.display(size: 18)),
            const SizedBox(height: 14),
            AppButton(
              label: tr(filipino, 'profile.photo_choose'),
              onPressed: () {
                Navigator.pop(ctx);
                _pickPhoto(filipino);
              },
            ),
            if (widget.user.hasPhoto) ...[
              const SizedBox(height: 10),
              AppButton(
                label: tr(filipino, 'profile.photo_remove'),
                style: AppButtonStyle.outline,
                onPressed: () {
                  Navigator.pop(ctx);
                  _removePhoto(filipino);
                },
              ),
            ],
            const SizedBox(height: 10),
            AppButton(
              label: tr(filipino, 'common.close'),
              style: AppButtonStyle.outline,
              onPressed: () => Navigator.pop(ctx),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final filipino = widget.appState.language == AppLanguage.filipino;
    return ListView(
      padding: EdgeInsets.zero,
      children: [
        AppHeader(onNotificationsTap: widget.onOpenNotifications, onProfileTap: widget.onOpenProfile),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: SectionHeader(title: tr(filipino, 'profile.title')),
        ),
        Padding(
            padding: const EdgeInsets.symmetric(horizontal: 22),
            child: AppCard(
              child: Column(
                children: [
                  _Avatar(
                    photo: _photo,
                    initials: widget.user.initials,
                    busy: _photoBusy,
                    filipino: filipino,
                    onTap: () => _showPhotoActions(context, filipino),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    _name.isEmpty ? tr(filipino, 'profile.value_missing') : _name,
                    style: AppText.display(
                      size: 18,
                      color: _name.isEmpty ? AppColors.inkMuted : AppColors.ink,
                    ),
                  ),
                  const SizedBox(height: 5),
                  _detailRow(Icons.email_outlined, _email, filipino),
                  const SizedBox(height: 3),
                  _detailRow(Icons.place_outlined, _address, filipino),
                  const SizedBox(height: 16),
                  AppButton(
                    label: tr(filipino, 'profile.account_details'),
                    style: AppButtonStyle.outline,
                    onPressed: () => _showAccountDetails(context, filipino),
                  ),
                ],
              ),
            ),
          ),
        // One switch, not the two that used to be here. Both of those wrote to
        // local bools that reset on rebuild while SmsController blasted every
        // Active resident regardless, so a resident who turned SMS alerts off
        // still got the paid SMS having been told they had opted out. The SMS
        // half now has a column behind it and is below. Push is still absent —
        // there is no FCM and no firebase_messaging anywhere in the app — so it
        // stays deleted rather than coming back as a second fake control.
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: tr(filipino, 'profile.account_settings')),
              _SettingsRow(
                icon: Icons.sms_outlined,
                title: tr(filipino, 'profile.sms_alerts'),
                subtitle: tr(
                  filipino,
                  widget.user.smsOptIn
                      ? 'profile.sms_alerts_on'
                      : 'profile.sms_alerts_off',
                ),
                // The row is tappable as well as the switch: the switch is a
                // small target and the row is already the app's tap surface for
                // everything else in this list.
                onTap: _smsBusy ? null : () => _setSmsOptIn(!widget.user.smsOptIn),
                trailing: _smsBusy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Switch(
                        value: widget.user.smsOptIn,
                        onChanged: _setSmsOptIn,
                      ),
              ),
              _SettingsRow(
                icon: Icons.language_outlined,
                title: tr(filipino, 'profile.language'),
                subtitle: widget.appState.language.label,
                trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkFaint),
                onTap: () => _pickLanguage(context),
              ),
              _SettingsRow(
                icon: Icons.download_outlined,
                title: tr(filipino, 'profile.offline_materials'),
                // Counted off the cache index. It used to read a hardcoded
                // "5 saved · 4.2 MB used" on a device with nothing saved.
                subtitle: _offlineSummary(filipino),
                trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkFaint),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => _OfflineMaterialsPage(
                      appState: widget.appState,
                      filipino: filipino,
                    ),
                  ),
                ),
              ),
              _SettingsRow(
                icon: Icons.logout_rounded,
                title: tr(filipino, 'profile.logout'),
                titleColor: AppColors.red600,
                iconBg: AppColors.red50,
                iconFg: AppColors.red600,
                onTap: () => _confirmLogout(context),
              ),
            ],
          ),
        ),
        const SizedBox(height: 110),
      ],
    );
  }

  Widget _detailRow(IconData icon, String text, bool filipino) {
    final missing = text.isEmpty;
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Icon(icon, size: 14, color: AppColors.inkFaint),
        const SizedBox(width: 6),
        Flexible(
          child: Text(
            missing ? tr(filipino, 'profile.value_missing') : text,
            style: AppText.body(
              size: 12.5,
              color: missing ? AppColors.inkFaint : AppColors.inkMuted,
            ),
          ),
        ),
      ],
    );
  }

  /// The resident's own contact details, editable since `PATCH /me` shipped.
  ///
  /// This sheet was read-only for a while on purpose. Before that it held three
  /// `TextField`s and a "Save changes" button that wrote to three local
  /// `String`s and reported "Profile information updated." — no API call
  /// existed, the values were gone on the next launch, and the admin panel
  /// never saw them. The fake control was deleted rather than left in place,
  /// and the backend work filed; this is that work landing.
  ///
  /// The barangay stays read-only, and that is not an oversight: it is the
  /// field every service request is dispatched on, so a resident who could move
  /// themselves could redirect their own dispatch. The endpoint refuses it too.
  Future<void> _showAccountDetails(BuildContext context, bool filipino) {
    return showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        // Lifts the sheet clear of the keyboard: without this the field being
        // typed into sits under it, which on a short phone is every field.
        padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
        child: _EditDetailsSheet(
          user: widget.user,
          userStore: widget.userStore,
          filipino: filipino,
          barangay: _address,
          onSaved: (updated) {
            widget.onUserChanged(updated);
            _say(tr(filipino, 'profile.saved'));
          },
        ),
      ),
    );
  }

  String _offlineSummary(bool filipino) {
    final saved = widget.appState.savedMaterials.values;
    if (saved.isEmpty) {
      return filipino
          ? 'Mga artikulo lang ng app'
          : 'App articles only';
    }

    var bytes = 0;
    for (final entry in saved) {
      bytes += entry.sizeBytes;
    }

    final size = bytes < 1024 * 1024
        ? '${(bytes / 1024).round()} KB'
        : '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';

    return filipino
        ? '${saved.length} na-save · $size'
        : '${saved.length} saved · $size';
  }

  Future<void> _pickLanguage(BuildContext context) async {
    const options = [AppLanguage.english, AppLanguage.filipino];
    final current = widget.appState.language;
    final f = current == AppLanguage.filipino;

    final choice = await showModalBottomSheet<AppLanguage>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        padding: const EdgeInsets.fromLTRB(22, 14, 22, 28),
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
            Text(
              f ? 'Pumili ng wika' : 'Choose language',
              style: AppText.display(size: 18),
            ),
            const SizedBox(height: 4),
            Text(
              f
                  ? 'Ang mga materyal sa Safety Library ay ipapakita sa wikang ito.'
                  : 'Materials in the Safety Library will be shown in this language.',
              style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.5),
            ),
            const SizedBox(height: 10),
            ...options.map((o) => ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(o.label, style: AppText.display(size: 14, weight: FontWeight.w600)),
                  trailing: o == current ? const Icon(Icons.check_rounded, color: AppColors.green700) : null,
                  onTap: () => Navigator.pop(ctx, o),
                )),
          ],
        ),
      ),
    );

    if (choice != null && choice != current) {
      widget.appState.setLanguage(choice);
      final newFil = choice == AppLanguage.filipino;
      if (context.mounted) {
        showAppSnackBar(
          context,
          newFil ? 'Naitakda ang wika sa Filipino.' : 'Language set to English.',
        );
      }
    }
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final f = widget.appState.language == AppLanguage.filipino;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Text(f ? 'Mag-log out?' : 'Log out?', style: AppText.display(size: 16)),
        content: Text(
          f
              ? 'Kailangan mong mag-sign in muli para magsumite o subaybayan ang mga kahilingan.'
              : 'You will need to sign in again to submit or track requests.',
          style: AppText.body(size: 13, color: AppColors.inkMuted, height: 1.5),
        ),
        actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              f ? 'Manatili' : 'Stay logged in',
              style: AppText.display(size: 13, weight: FontWeight.w600, color: AppColors.inkMuted),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: TextButton.styleFrom(backgroundColor: AppColors.red50, foregroundColor: AppColors.red600),
            child: Text(
              f ? 'Mag-log Out' : 'Log out',
              style: AppText.display(size: 13, weight: FontWeight.w600, color: AppColors.red600),
            ),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      widget.onLogout();
    }
  }
}

/// What is actually available with no signal: the articles compiled into the
/// app, and the MDRRMO documents downloaded to this device.
///
/// The list used to be eight hardcoded `(article, pages, saved)` tuples — a
/// second copy of the Library's own literals, kept in sync by hand, with
/// "saved" flags that described nothing on disk.
class _OfflineMaterialsPage extends StatefulWidget {
  final AppState appState;
  final bool filipino;

  const _OfflineMaterialsPage({required this.appState, required this.filipino});

  @override
  State<_OfflineMaterialsPage> createState() => _OfflineMaterialsPageState();
}

class _OfflineMaterialsPageState extends State<_OfflineMaterialsPage> {
  bool get filipino => widget.filipino;

  @override
  void initState() {
    super.initState();
    widget.appState.addListener(_onChanged);
  }

  @override
  void dispose() {
    widget.appState.removeListener(_onChanged);
    super.dispose();
  }

  void _onChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _remove(CachedMaterial entry) async {
    await widget.appState.removeMaterialOffline(entry.id);
    if (!mounted) {
      return;
    }

    showAppSnackBar(
      context,
      filipino
          ? 'Tinanggal ang ${entry.title} sa device na ito.'
          : '${entry.title} removed from this device.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final downloaded = widget.appState.savedMaterials.values.toList()
      ..sort((a, b) => b.savedAt.compareTo(a.savedAt));

    // Every bundled article ships inside the binary, so all of them are
    // offline-available — there is no per-article saved state to track.
    final articleKeys = libraryArticles.keys.toList();

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(22, 56, 22, 20),
            decoration: const BoxDecoration(
              gradient: AppColors.headerGradient,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
            ),
            child: Row(
              children: [
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
                ),
                const SizedBox(width: 4),
                Text(
                  filipino ? 'Mga Offline na Materyal' : 'Offline Materials',
                  style: AppText.display(size: 19, color: Colors.white),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 6),
            child: SectionHeader(
              title: filipino
                  ? 'Mga Na-download na Dokumento'
                  : 'Downloaded Documents',
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 22),
            child: downloaded.isEmpty
                ? Container(
                    padding: const EdgeInsets.all(13),
                    decoration: BoxDecoration(
                      color: AppColors.surface,
                      border: Border.all(color: AppColors.line),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Text(
                      filipino
                          ? 'Wala pang na-download. Buksan ang Aklatan at pindutin ang I-download.'
                          : 'Nothing downloaded yet. Open the Library and tap Download.',
                      style: AppText.body(size: 12, color: AppColors.inkMuted),
                    ),
                  )
                : Column(
                    children: downloaded.map((entry) {
                      final material = entry.toMaterial();

                      return Container(
                        margin: const EdgeInsets.only(bottom: 9),
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          border: Border.all(color: AppColors.line),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(13),
                          child: Row(
                            children: [
                              IconBadge(
                                icon: material.icon,
                                bg: AppColors.green50,
                                fg: AppColors.green700,
                                size: 36,
                                iconSize: 17,
                                radius: 10,
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      entry.title,
                                      style: AppText.display(size: 13, weight: FontWeight.w600),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      [
                                        material.typeLabel,
                                        if (material.sizeLabel.isNotEmpty) material.sizeLabel,
                                      ].join(' · '),
                                      style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                                    ),
                                  ],
                                ),
                              ),
                              IconButton(
                                onPressed: () => _remove(entry),
                                tooltip: filipino ? 'Tanggalin' : 'Remove',
                                icon: const Icon(
                                  Icons.delete_outline_rounded,
                                  size: 19,
                                  color: AppColors.inkMuted,
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    }).toList(),
                  ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 22, 22, 6),
            child: SectionHeader(
              title: filipino ? 'Kasama sa App' : 'Included in the App',
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 0, 22, 22),
            child: Column(
              children: articleKeys.map((key) {
                final article = libraryArticles[key]!;
                final title = article.titleFor(filipino: filipino);
                final subtitle = article.subtitleFor(filipino: filipino);

                return Container(
                  margin: const EdgeInsets.only(bottom: 9),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    border: Border.all(color: AppColors.line),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Material(
                    color: Colors.transparent,
                    borderRadius: BorderRadius.circular(14),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(14),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => ArticleReaderScreen(article: article, filipino: filipino)),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.all(13),
                        child: Row(
                          children: [
                            IconBadge(icon: article.icon, bg: article.iconBg, fg: article.iconFg, size: 36, iconSize: 17, radius: 10),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(title, style: AppText.display(size: 13, weight: FontWeight.w600)),
                                  const SizedBox(height: 2),
                                  Text(
                                    subtitle,
                                    style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            OfflinePill(saved: true, filipino: filipino),
                            const SizedBox(width: 6),
                            const Icon(Icons.chevron_right_rounded, size: 18, color: AppColors.inkFaint),
                          ],
                        ),
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }
}

/// The profile photo, its initials fallback, and the badge that opens the
/// picker. The badge is the affordance M32 removed when it led to a snackbar
/// reading "Photo picker would open here."; there is a picker behind it now.
class _Avatar extends StatelessWidget {
  final Uint8List? photo;
  final String initials;
  final bool busy;
  final bool filipino;
  final VoidCallback onTap;

  const _Avatar({
    required this.photo,
    required this.initials,
    required this.busy,
    required this.filipino,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: tr(filipino, 'profile.photo_change'),
      child: InkWell(
        onTap: busy ? null : onTap,
        customBorder: const CircleBorder(),
        child: SizedBox(
          width: 92,
          height: 92,
          child: Stack(
            children: [
              Container(
                width: 84,
                height: 84,
                decoration: BoxDecoration(
                  color: AppColors.green50,
                  shape: BoxShape.circle,
                  border: Border.all(color: AppColors.surface, width: 3),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.green900.withOpacity(.06),
                      blurRadius: 12,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                alignment: Alignment.center,
                clipBehavior: Clip.antiAlias,
                child: _face(),
              ),
              Positioned(
                right: 0,
                bottom: 0,
                child: Container(
                  width: 28,
                  height: 28,
                  decoration: BoxDecoration(
                    color: AppColors.green700,
                    shape: BoxShape.circle,
                    border: Border.all(color: AppColors.surface, width: 2),
                  ),
                  alignment: Alignment.center,
                  child: busy
                      ? const SizedBox(
                          width: 13,
                          height: 13,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: AppColors.surface,
                          ),
                        )
                      : const Icon(Icons.edit_rounded,
                          size: 14, color: AppColors.surface),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _face() {
    if (photo != null) {
      return Image.memory(
        photo!,
        width: 84,
        height: 84,
        fit: BoxFit.cover,
        // A corrupt or truncated image must not take the profile screen down;
        // fall back to the same placeholder an empty profile gets.
        errorBuilder: (_, __, ___) => _placeholder(),
      );
    }

    return _placeholder();
  }

  Widget _placeholder() {
    // Initials only when there is a real name behind them. A profile that
    // failed to load shows the neutral icon rather than an invented monogram.
    if (initials.isEmpty) {
      return const Icon(Icons.person_outline_rounded,
          size: 36, color: AppColors.green700);
    }

    return Text(
      initials,
      style: AppText.display(size: 28, color: AppColors.green700),
    );
  }
}

class _SettingsRow extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final Color? titleColor;
  final Color iconBg;
  final Color iconFg;
  final VoidCallback? onTap;

  const _SettingsRow({
    required this.icon,
    required this.title,
    this.subtitle,
    this.trailing,
    this.titleColor,
    this.iconBg = AppColors.green50,
    this.iconFg = AppColors.green700,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Row(
              children: [
                IconBadge(icon: icon, bg: iconBg, fg: iconFg, size: 36, iconSize: 17, radius: 10),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: AppText.display(size: 13, weight: FontWeight.w600, color: titleColor ?? AppColors.ink)),
                      if (subtitle != null) ...[
                        const SizedBox(height: 1),
                        Text(subtitle!, style: AppText.body(size: 11.5, color: AppColors.inkMuted)),
                      ],
                    ],
                  ),
                ),
                if (trailing != null) trailing!,
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The editable half of "Account details".
///
/// Its own widget rather than a `StatefulBuilder` inside the sheet: it owns five
/// controllers, per-field errors and an in-flight flag, and a sheet that rebuilt
/// from the parent would drop what was being typed.
class _EditDetailsSheet extends StatefulWidget {
  final AppUser user;
  final UserStore userStore;
  final bool filipino;

  /// Read-only, shown for context. Not editable here or on the endpoint.
  final String barangay;
  final ValueChanged<AppUser> onSaved;

  const _EditDetailsSheet({
    required this.user,
    required this.userStore,
    required this.filipino,
    required this.barangay,
    required this.onSaved,
  });

  @override
  State<_EditDetailsSheet> createState() => _EditDetailsSheetState();
}

class _EditDetailsSheetState extends State<_EditDetailsSheet> {
  late final TextEditingController _first =
      TextEditingController(text: widget.user.firstName);
  late final TextEditingController _middle =
      TextEditingController(text: widget.user.middleName);
  late final TextEditingController _last =
      TextEditingController(text: widget.user.lastName);
  late final TextEditingController _phone =
      TextEditingController(text: widget.user.phone);
  late final TextEditingController _email =
      TextEditingController(text: widget.user.email);
  final TextEditingController _password = TextEditingController();

  final Map<String, String> _errors = {};
  String? _formError;
  bool _saving = false;

  /// Whether the email or mobile number differs from what is on file. The
  /// endpoint requires the account password to move either — they are where a
  /// login code is delivered — so this decides both whether the password field
  /// is shown and whether it is required.
  bool _contactChanged = false;

  bool _passwordHidden = true;

  @override
  void initState() {
    super.initState();
    // Watched rather than checked at save time, so the field appears at the
    // moment it becomes required instead of after a rejected save.
    _phone.addListener(_syncContactChanged);
    _email.addListener(_syncContactChanged);
  }

  void _syncContactChanged() {
    final changed = _phone.text.trim() != widget.user.phone ||
        _email.text.trim() != widget.user.email;

    // Only on the transition. This runs on every keystroke in either field, and
    // an unconditional setState would rebuild the sheet for each one.
    if (changed == _contactChanged) return;

    setState(() {
      _contactChanged = changed;
      // Undoing the edit takes the requirement away with it. Without this a
      // "Required" would be left behind on a field that is no longer rendered,
      // and _validate() would go on reading it.
      if (!changed) _errors.remove('password');
    });
  }

  @override
  void dispose() {
    _phone.removeListener(_syncContactChanged);
    _email.removeListener(_syncContactChanged);
    _first.dispose();
    _middle.dispose();
    _last.dispose();
    _phone.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  String _tr(String key) => tr(widget.filipino, key);

  /// Mirrors the backend rules rather than trusting them to be reached: a round
  /// trip to be told a field is blank is a slow answer on a rural connection.
  /// The server still validates — this only saves the trip.
  bool _validate() {
    final errors = <String, String>{};

    if (_first.text.trim().isEmpty) errors['first'] = _tr('profile.required');
    if (_last.text.trim().isEmpty) errors['last'] = _tr('profile.required');

    final phone = _phone.text.trim();
    if (phone.isEmpty) {
      errors['phone'] = _tr('profile.required');
    } else if (phone.length != 11 || !phone.startsWith('09')) {
      // The field is digits-only and capped at 11 by AppTextField.phone, so the
      // only reachable failures are "too short" and "does not start 09".
      errors['phone'] = _tr('profile.phone_invalid');
    }

    final email = _email.text.trim();
    if (email.isEmpty) {
      errors['email'] = _tr('profile.required');
    } else if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) {
      errors['email'] = _tr('profile.email_invalid');
    }

    // Not trimmed, unlike every field above: a space is a legitimate password
    // character, and trimming here would reject a correct one.
    if (_contactChanged && _password.text.isEmpty) {
      errors['password'] = _tr('profile.password_required');
    }

    setState(() {
      _errors
        ..clear()
        ..addAll(errors);
    });

    return errors.isEmpty;
  }

  /// Only what actually changed is sent. PATCH leaves an absent key alone, so an
  /// unchanged email is never re-submitted — which matters because the backend's
  /// unique rule would otherwise be checked against the resident's own row on
  /// every save.
  Map<String, String?> _changes() {
    final changed = <String, String?>{};
    void diff(String key, String current, String original) {
      if (current != original) changed[key] = current;
    }

    diff('first', _first.text.trim(), widget.user.firstName);
    diff('middle', _middle.text.trim(), widget.user.middleName);
    diff('last', _last.text.trim(), widget.user.lastName);
    diff('phone', _phone.text.trim(), widget.user.phone);
    diff('email', _email.text.trim(), widget.user.email);

    return changed;
  }

  Future<void> _save() async {
    if (_saving) return;
    if (!_validate()) return;

    final changes = _changes();
    if (changes.isEmpty) {
      setState(() => _formError = _tr('profile.no_changes'));
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    try {
      final updated = await widget.userStore.updateProfile(
        firstName: changes['first'],
        middleName: changes['middle'],
        lastName: changes['last'],
        phoneNumber: changes['phone'],
        email: changes['email'],
        // Sent only when it is actually needed. Passing it on every save would
        // put the resident's password on the wire for a surname correction.
        currentPassword: _contactChanged ? _password.text : null,
      );

      if (!mounted) return;
      // Pop before reporting: the snackbar belongs to the screen underneath, and
      // one shown over a closing sheet is dismissed along with it.
      Navigator.pop(context);
      widget.onSaved(updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      // The server's message is shown as-is. A 422 here is a real rejection the
      // resident has to act on — a duplicate email is the common one — and the
      // field-level checks above cannot know about it.
      //
      // A rejected password is put under the password field instead of in the
      // banner, so the resident's eye lands on the input they have to fix. Only
      // when that field is on screen: a `current_password` error while the
      // field is hidden would otherwise be shown nowhere at all.
      final passwordError = e.fieldErrors['current_password'];

      setState(() {
        if (passwordError != null && _contactChanged) {
          _errors['password'] = passwordError;
          // Not also in the banner — the same sentence twice reads as two
          // separate problems.
          _formError = null;
        } else {
          _formError = e.message;
        }
      });
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: const EdgeInsets.fromLTRB(22, 14, 22, 28),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 36,
                height: 4,
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: AppColors.line,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
            Text(_tr('profile.account_details'), style: AppText.display(size: 18)),
            const SizedBox(height: 14),
            AppTextField(
              label: _tr('profile.first_name'),
              hint: '',
              controller: _first,
              errorText: _errors['first'],
              enabled: !_saving,
            ),
            AppTextField(
              label: _tr('profile.middle_name_optional'),
              hint: '',
              controller: _middle,
              enabled: !_saving,
            ),
            AppTextField(
              label: _tr('profile.last_name'),
              hint: '',
              controller: _last,
              errorText: _errors['last'],
              enabled: !_saving,
            ),
            AppTextField.phone(
              label: _tr('profile.phone'),
              controller: _phone,
              errorText: _errors['phone'],
              enabled: !_saving,
            ),
            AppTextField(
              label: _tr('profile.email'),
              hint: '',
              controller: _email,
              keyboard: TextInputType.emailAddress,
              errorText: _errors['email'],
              enabled: !_saving,
            ),
            // Appears the moment either contact field is edited, directly under
            // the two that triggered it, and disappears again if the edit is
            // undone.
            if (_contactChanged) ...[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.shield_outlined,
                      size: 15, color: AppColors.inkFaint),
                  const SizedBox(width: 7),
                  Expanded(
                    child: Text(
                      _tr('profile.password_why'),
                      style: AppText.body(
                          size: 12, color: AppColors.inkMuted, height: 1.5),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              AppTextField(
                label: _tr('profile.password_current'),
                hint: '',
                controller: _password,
                obscure: _passwordHidden,
                errorText: _errors['password'],
                enabled: !_saving,
                suffixIcon: IconButton(
                  onPressed: _saving
                      ? null
                      : () => setState(() => _passwordHidden = !_passwordHidden),
                  icon: Icon(
                    _passwordHidden
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                    size: 18,
                    color: AppColors.inkFaint,
                  ),
                ),
              ),
            ],
            _ReadOnlyField(
              label: _tr('profile.barangay'),
              value: widget.barangay,
              filipino: widget.filipino,
            ),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.lock_outline_rounded,
                    size: 15, color: AppColors.inkFaint),
                const SizedBox(width: 7),
                Expanded(
                  child: Text(
                    _tr('profile.barangay_locked'),
                    style: AppText.body(
                        size: 12, color: AppColors.inkMuted, height: 1.5),
                  ),
                ),
              ],
            ),
            if (_formError != null) ...[
              const SizedBox(height: 14),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 11),
                decoration: BoxDecoration(
                  color: AppColors.red50,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  _formError!,
                  style: AppText.body(size: 12, color: AppColors.red600, height: 1.4),
                ),
              ),
            ],
            const SizedBox(height: 16),
            AppButton(
              label: _tr('profile.save'),
              loading: _saving,
              onPressed: _saving ? null : _save,
            ),
            const SizedBox(height: 8),
            AppButton(
              label: _tr('common.cancel'),
              style: AppButtonStyle.outline,
              onPressed: _saving ? null : () => Navigator.pop(context),
            ),
          ],
        ),
      ),
    );
  }
}

/// A value the resident can see but not change. Kept visually distinct from an
/// editable field — paper fill, no focus colour — so "locked" reads before the
/// explanation under it does.
class _ReadOnlyField extends StatelessWidget {
  final String label;
  final String value;
  final bool filipino;

  const _ReadOnlyField({
    required this.label,
    required this.value,
    required this.filipino,
  });

  @override
  Widget build(BuildContext context) {
    final missing = value.isEmpty;
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
            decoration: BoxDecoration(
              color: AppColors.paper,
              border: Border.all(color: AppColors.line, width: 1.5),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Text(
              missing ? tr(filipino, 'profile.value_missing') : value,
              style: AppText.body(
                size: 13,
                color: missing ? AppColors.inkFaint : AppColors.ink,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
