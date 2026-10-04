
library serbis.screens.profile;

import 'dart:typed_data';

import 'package:file_picker/file_picker.dart' as fp;
import 'package:flutter/material.dart';
import '../data/hotlines.dart';
import '../data/safety_files.dart';
import '../state/account_store.dart';
import '../state/api_service.dart';
import '../state/material_cache.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/barangay_field.dart';
import '../widgets/borrow_request_widgets.dart' show mdrrmoNumber;
import '../widgets/form_inputs.dart';
import '../widgets/request_summary.dart' show SummaryCard;
import '../widgets/shared_widgets.dart';
import 'change_phone_sheet.dart';
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

  /// Set when Profile is opened as a page over the tabs (from the header's
  /// profile icon); draws the header's back button.
  final VoidCallback? onBack;

  const ProfileScreen({
    super.key,
    required this.appState,
    required this.userStore,
    required this.user,
    required this.onUserChanged,
    required this.onLogout,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    this.onBack,
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
      builder: (ctx) => SheetFrame(
        title: tr(filipino, 'profile.photo'),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AppButton(
              label: tr(filipino, 'profile.photo_choose'),
              onPressed: () {
                Navigator.pop(ctx);
                _pickPhoto(filipino);
              },
            ),
            if (widget.user.hasPhoto) ...[
              const SizedBox(height: AppSpacing.sm),
              AppButton(
                label: tr(filipino, 'profile.photo_remove'),
                style: AppButtonStyle.outline,
                onPressed: () {
                  Navigator.pop(ctx);
                  _removePhoto(filipino);
                },
              ),
            ],
            const SizedBox(height: AppSpacing.sm),
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
    final mdrrmo = mdrrmoNumber(widget.appState.hotlines);
    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        SliverPersistentHeader(
          pinned: true,
          delegate: TabHeader(
            title: tr(filipino, 'profile.title'),
            subtitle: tr(filipino, 'profile.subtitle'),
            filipino: filipino,
            onNotifications: widget.onOpenNotifications,
            onProfile: widget.onBack == null ? widget.onOpenProfile : null,
            onBack: widget.onBack,
          ),
        ),
        SliverToBoxAdapter(
          // Phone-width on a tablet, the web build or a desktop window.
          child: Align(
            alignment: Alignment.topCenter,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 600),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: AppColors.surface,
                        border: Border.all(color: AppColors.cardBorder),
                        borderRadius: BorderRadius.circular(18),
                      ),
                      child: Column(
                        children: [
                          _Avatar(
                            photo: _photo,
                            initials: widget.user.initials,
                            busy: _photoBusy,
                            filipino: filipino,
                            onTap: () => _showPhotoActions(context, filipino),
                          ),
                          const SizedBox(height: AppSpacing.md),
                          Text(
                            _name.isEmpty ? tr(filipino, 'profile.value_missing') : _name,
                            textAlign: TextAlign.center,
                            style: AppText.display(
                              size: AppTextSize.cardName,
                              color: _name.isEmpty ? AppColors.inkMuted : AppColors.ink,
                            ),
                          ),
                          const SizedBox(height: AppSpacing.xs),
                          Text(
                            _contactLine(filipino),
                            textAlign: TextAlign.center,
                            style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted),
                          ),
                          const SizedBox(height: AppSpacing.lg),
                          AppButton(
                            label: tr(filipino, 'profile.edit_details'),
                            style: AppButtonStyle.outline,
                            onPressed: () => _showAccountDetails(context, filipino),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    // One switch, not the two that used to be here. Both of those
                    // wrote to local bools that reset on rebuild while
                    // SmsController blasted every Active resident regardless, so a
                    // resident who turned SMS alerts off still got the paid SMS
                    // having been told they had opted out. The SMS half now has a
                    // column behind it and is below. Push is still absent — there
                    // is no FCM and no firebase_messaging anywhere in the app — so
                    // it stays deleted rather than coming back as a second fake
                    // control.
                    SectionHeader(title: tr(filipino, 'profile.settings')),
                    SummaryCard(children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _SettingsRow(
                            icon: Icons.sms_outlined,
                            title: tr(filipino, 'profile.sms_alerts'),
                            subtitle: tr(
                              filipino,
                              widget.user.smsOptIn ? 'profile.sms_alerts_on' : 'profile.sms_alerts_off',
                            ),
                            // The row is tappable as well as the switch: the switch
                            // is a small target and the row is already the app's tap
                            // surface for everything else in this list.
                            onTap: _smsBusy ? null : () => _setSmsOptIn(!widget.user.smsOptIn),
                            trailing: _smsBusy
                                ? const SizedBox(
                                    width: 24,
                                    height: 24,
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : _AlertsSwitch(value: widget.user.smsOptIn, onChanged: _setSmsOptIn),
                          ),
                          if (!widget.user.smsOptIn)
                            _OffNote(text: tr(filipino, 'profile.sms_alerts_off_note')),
                        ],
                      ),
                      _SettingsRow(
                        icon: Icons.language_outlined,
                        title: tr(filipino, 'profile.language'),
                        subtitle: widget.appState.language.label,
                        trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
                        onTap: () => _pickLanguage(context),
                      ),
                      _SettingsRow(
                        icon: Icons.download_outlined,
                        title: tr(filipino, 'profile.offline_materials'),
                        // Counted off the cache index. It used to read a hardcoded
                        // "5 saved · 4.2 MB used" on a device with nothing saved.
                        subtitle: _offlineSummary(filipino),
                        trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => _OfflineMaterialsPage(
                              appState: widget.appState,
                              filipino: filipino,
                            ),
                          ),
                        ),
                      ),
                      // Hidden rather than dead when the hotline list has no
                      // MDRRMO entry to dial.
                      if (mdrrmo != null)
                        _SettingsRow(
                          icon: Icons.call_outlined,
                          title: tr(filipino, 'profile.contact_mdrrmo'),
                          subtitle: tr(filipino, 'profile.contact_mdrrmo_desc'),
                          trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
                          onTap: () => callHotlineNumber(mdrrmo),
                        ),
                    ]),
                    const SizedBox(height: AppSpacing.xl),
                    // Out of the list so it is never tapped on the way to a setting.
                    SizedBox(
                      height: 52,
                      child: OutlinedButton.icon(
                        onPressed: () => _confirmLogout(context),
                        icon: const Icon(Icons.logout_rounded, size: 20),
                        label: Text(
                          tr(filipino, 'profile.logout'),
                          style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.red600),
                        ),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AppColors.red600,
                          backgroundColor: AppColors.surface,
                          side: const BorderSide(color: AppColors.redBorder, width: 1.5),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.lg)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// "0917… · San Fabian". A missing half says so rather than vanishing: the
  /// barangay is what requests are dispatched on. Both missing is one mark.
  String _contactLine(bool filipino) {
    final missing = tr(filipino, 'profile.value_missing');
    final phone = widget.user.phoneDisplay;
    if (phone.isEmpty && _address.isEmpty) return missing;
    return '${phone.isEmpty ? missing : phone} · ${_address.isEmpty ? missing : _address}';
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
      builder: (ctx) => SheetFrame(
        title: f ? 'Pumili ng wika' : 'Choose language',
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              f
                  ? 'Ang mga materyal sa Safety Library ay ipapakita sa wikang ito.'
                  : 'Materials in the Safety Library will be shown in this language.',
              style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
            ),
            const SizedBox(height: AppSpacing.lg),
            AppChoiceList(
              items: [for (final o in options) o.label],
              value: current.label,
              onChanged: (label) => Navigator.pop(ctx, options.firstWhere((o) => o.label == label)),
            ),
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
    final confirmed = await showConfirmDialog(
      context,
      title: f ? 'Mag-log out?' : 'Log out?',
      body: f
          ? 'Kailangan mong mag-log in muli para magsumite o subaybayan ang mga kahilingan.'
          : 'You will need to log in again to submit or track requests.',
      keepLabel: f ? 'Manatili' : 'Stay logged in',
      confirmLabel: f ? 'Mag-log out' : 'Log out',
    );

    if (confirmed) {
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
      body: Column(
        children: [
          TabHeaderBar(
            title: tr(filipino, 'profile.offline_materials'),
            subtitle: tr(filipino, 'profile.offline_subtitle'),
            filipino: filipino,
            onBack: () => Navigator.pop(context),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              children: [
                Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 600),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        SectionHeader(
                          title: filipino ? 'Mga na-download na dokumento' : 'Downloaded documents',
                        ),
                        if (downloaded.isEmpty)
                          SummaryCard(children: [
                            Padding(
                              padding: const EdgeInsets.all(16),
                              child: Text(
                                filipino
                                    ? 'Wala pang na-download. Buksan ang Aklatan at pindutin ang I-download.'
                                    : 'Nothing downloaded yet. Open the Library and tap Download.',
                                style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.4),
                              ),
                            ),
                          ])
                        else
                          SummaryCard(children: [
                            for (final entry in downloaded) _downloadedRow(entry),
                          ]),
                        const SizedBox(height: AppSpacing.xl),
                        SectionHeader(
                          title: filipino ? 'Kasama sa app' : 'Included in the app',
                          trailing: OfflinePill(saved: true, filipino: filipino),
                        ),
                        SummaryCard(children: [
                          for (final key in articleKeys) _articleRow(libraryArticles[key]!),
                        ]),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _downloadedRow(CachedMaterial entry) {
    final material = entry.toMaterial();
    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 72),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        child: Row(
          children: [
            IconBadge(icon: material.icon, bg: AppColors.green50, fg: AppColors.green700, size: 44, iconSize: 20),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(entry.title, style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
                  const SizedBox(height: 2),
                  Text(
                    [material.typeLabel, if (material.sizeLabel.isNotEmpty) material.sizeLabel].join(' · '),
                    style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                  ),
                ],
              ),
            ),
            IconButton(
              onPressed: () => _remove(entry),
              tooltip: filipino ? 'Tanggalin' : 'Remove',
              icon: const Icon(Icons.delete_outline_rounded, size: 24, color: AppColors.inkMuted),
            ),
          ],
        ),
      ),
    );
  }

  Widget _articleRow(LibraryArticle article) {
    return InkWell(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => ArticleReaderScreen(article: article, filipino: filipino)),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 72),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              IconBadge(icon: article.icon, bg: article.iconBg, fg: article.iconFg, size: 44, iconSize: 20),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      article.titleFor(filipino: filipino),
                      style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      article.subtitleFor(filipino: filipino),
                      style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
            ],
          ),
        ),
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
                      color: AppColors.green900.withValues(alpha: .06),
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
      style: AppText.display(size: AppTextSize.display, color: AppColors.green700),
    );
  }
}

class _SettingsRow extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;

  const _SettingsRow({
    required this.icon,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 72),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              IconBadge(icon: icon, bg: AppColors.green50, fg: AppColors.green700, size: 44, iconSize: 20),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
                    if (subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(subtitle!, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[const SizedBox(width: 8), trailing!],
            ],
          ),
        ),
      ),
    );
  }
}

/// The text-alerts switch with both states drawn as controls. Material's off
/// state is a pale outline on white that reads as disabled; this one keeps a
/// dark outline and thumb so Off still looks like something to tap.
class _AlertsSwitch extends StatelessWidget {
  final bool value;
  final ValueChanged<bool> onChanged;

  const _AlertsSwitch({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    WidgetStateProperty<Color> pick(Color on, Color off) =>
        WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? on : off);

    return Switch(
      value: value,
      onChanged: onChanged,
      thumbColor: pick(AppColors.surface, AppColors.inkMuted),
      trackColor: pick(AppColors.green700, AppColors.surface),
      trackOutlineColor: pick(AppColors.green700, AppColors.inkMuted),
      trackOutlineWidth: const WidgetStatePropertyAll(2),
    );
  }
}

/// Under an Off switch: what turning alerts off costs, in amber.
class _OffNote extends StatelessWidget {
  final String text;

  const _OffNote({required this.text});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 14),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.amber50,
        border: Border.all(color: AppColors.amberBorder),
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(top: 1),
            child: Icon(Icons.warning_amber_rounded, size: 18, color: AppColors.amberInk),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text, style: AppText.body(size: AppTextSize.body, color: AppColors.amberInk, height: 1.45)),
          ),
        ],
      ),
    );
  }
}

/// Keeps a sheet's content to the 600dp column the screens use.
Widget _capped(Widget child) => Align(
      alignment: Alignment.topCenter,
      heightFactor: 1,
      child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 600), child: child),
    );

/// "Edit my details": the editable name, barangay and street, the number shown
/// locked, and Save pinned under a scrolling body.
///
/// Its own widget rather than a `StatefulBuilder` inside the sheet: it owns four
/// controllers, per-field errors and an in-flight flag, and a sheet that rebuilt
/// from the parent would drop what was being typed.
class _EditDetailsSheet extends StatefulWidget {
  final AppUser user;
  final UserStore userStore;
  final bool filipino;

  /// The barangay's name on file, for the confirm dialog and the locked row.
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
  late final TextEditingController _street =
      TextEditingController(text: widget.user.streetAddress);

  final Map<String, String> _errors = {};
  String? _formError;
  bool _saving = false;

  late int? _barangayId = widget.user.barangayId;
  List<BarangayOption> _barangays = [];
  bool _loadingBarangays = false;
  bool _barangaysFailed = false;

  /// A barangay hall or organization is tied to its barangay; the server
  /// refuses the change for them, so they get the locked row instead.
  bool get _canMove => !widget.user.isBarangay && !widget.user.isOrganization;

  @override
  void initState() {
    super.initState();
    // Save enables the moment something differs from what is on file.
    for (final c in [_first, _middle, _last, _street]) {
      c.addListener(() => setState(() {}));
    }
    if (_canMove) _loadBarangays();
  }

  Future<void> _loadBarangays() async {
    setState(() {
      _loadingBarangays = true;
      _barangaysFailed = false;
    });
    try {
      final list = await widget.userStore.barangays();
      if (mounted) setState(() => _barangays = list);
    } catch (_) {
      if (mounted) setState(() => _barangaysFailed = true);
    } finally {
      if (mounted) setState(() => _loadingBarangays = false);
    }
  }

  String _barangayName(int? id) {
    for (final b in _barangays) {
      if (b.id == id) return b.name;
    }
    return '';
  }

  @override
  void dispose() {
    _first.dispose();
    _middle.dispose();
    _last.dispose();
    _street.dispose();
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

    setState(() {
      _errors
        ..clear()
        ..addAll(errors);
    });

    return errors.isEmpty;
  }

  /// Only what actually changed is sent. PATCH leaves an absent key alone.
  Map<String, Object?> _changes() {
    final changed = <String, Object?>{};
    void diff(String key, Object? current, Object? original) {
      if (current != original) changed[key] = current;
    }

    diff('first', _first.text.trim(), widget.user.firstName);
    diff('middle', _middle.text.trim(), widget.user.middleName);
    diff('last', _last.text.trim(), widget.user.lastName);
    diff('street', _street.text.trim(), widget.user.streetAddress);
    if (_barangayId != null) diff('barangay', _barangayId, widget.user.barangayId);

    return changed;
  }

  /// Moving changes where new requests and texts go, so it is confirmed first.
  Future<bool> _confirmMove() {
    final missing = _tr('profile.value_missing');
    return showConfirmDialog(
      context,
      title: _tr('profile.barangay_confirm_title'),
      body: _tr('profile.barangay_confirm_body')
          .replaceAll('{old}', widget.barangay.isEmpty ? missing : widget.barangay)
          .replaceAll('{new}', _barangayName(_barangayId)),
      keepLabel: _tr('profile.barangay_confirm_keep'),
      confirmLabel: _tr('profile.barangay_confirm_go'),
      destructive: false,
    );
  }

  Future<void> _save() async {
    if (_saving) return;
    if (!_validate()) return;

    final changes = _changes();
    if (changes.isEmpty) return;
    if (changes.containsKey('barangay') && !await _confirmMove()) return;
    if (!mounted) return;

    setState(() {
      _saving = true;
      _formError = null;
    });

    try {
      final updated = await widget.userStore.updateProfile(
        firstName: changes['first'] as String?,
        middleName: changes['middle'] as String?,
        lastName: changes['last'] as String?,
        streetAddress: changes['street'] as String?,
        barangayId: changes['barangay'] as int?,
      );

      if (!mounted) return;
      // Pop before reporting: the snackbar belongs to the screen underneath, and
      // one shown over a closing sheet is dismissed along with it.
      Navigator.pop(context);
      widget.onSaved(updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      // The server's message is shown as-is: a 422 here is a real rejection
      // the resident has to act on, and the field-level checks above cannot
      // know about it.
      setState(() => _formError = e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  /// Opens the two-step number change on top of this sheet. It pops with the
  /// refreshed profile only once the code has been accepted, and this sheet
  /// closes with it: whatever was typed in the other fields is not carried, and
  /// the parent reports the save.
  Future<void> _changePhone() async {
    final updated = await showModalBottomSheet<AppUser>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
        child: ChangePhoneSheet(
          user: widget.user,
          userStore: widget.userStore,
          filipino: widget.filipino,
        ),
      ),
    );

    if (updated == null || !mounted) return;
    Navigator.pop(context);
    widget.onSaved(updated);
  }

  @override
  Widget build(BuildContext context) {
    final dirty = _changes().isNotEmpty;

    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * .9),
      child: Container(
        width: double.infinity,
        clipBehavior: Clip.antiAlias,
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 12, AppLayout.gutter, 12),
              decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: AppColors.cardDivider))),
              child: _capped(SheetHeader(title: _tr('profile.edit_details'), filipino: widget.filipino)),
            ),
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(AppLayout.gutter, AppSpacing.lg, AppLayout.gutter, AppSpacing.lg),
                child: _capped(Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _GroupLabel(text: _tr('profile.group_name')),
                    AppTextField(
                      label: _tr('profile.first_name'),
                      hint: 'e.g. Juan',
                      controller: _first,
                      errorText: _errors['first'],
                      enabled: !_saving,
                    ),
                    AppTextField(
                      label: _tr('profile.middle_name_optional'),
                      hint: 'e.g. Reyes',
                      controller: _middle,
                      enabled: !_saving,
                    ),
                    AppTextField(
                      label: _tr('profile.last_name'),
                      hint: 'e.g. Delacruz',
                      controller: _last,
                      errorText: _errors['last'],
                      enabled: !_saving,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    _GroupLabel(text: _tr('profile.group_contact')),
                    // The number is the login and where every code goes, so it is
                    // shown here and moved by its own two-step flow, not edited
                    // inline.
                    _LockedRow(
                      label: _tr('profile.phone'),
                      value: widget.user.phoneDisplay,
                      filipino: widget.filipino,
                      action: TextButton(
                        onPressed: _saving ? null : _changePhone,
                        style: TextButton.styleFrom(minimumSize: const Size(44, 44)),
                        child: Text(
                          _tr('profile.phone_change_link'),
                          semanticsLabel: _tr('phonechange.button'),
                          style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.green700),
                        ),
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    _GroupLabel(text: _tr('profile.group_address')),
                    if (_canMove)
                      BarangayField(
                        barangays: _barangays,
                        value: _barangayId,
                        loading: _loadingBarangays,
                        failed: _barangaysFailed,
                        onRetry: _loadBarangays,
                        onChanged: (id) => setState(() => _barangayId = id),
                        filipino: widget.filipino,
                        enabled: !_saving,
                      )
                    else ...[
                      _LockedRow(
                        label: _tr('barangay.label'),
                        value: widget.barangay,
                        filipino: widget.filipino,
                      ),
                      const SizedBox(height: AppSpacing.md),
                    ],
                    AppTextField(
                      label: _tr('profile.street_address'),
                      hint: 'e.g. Purok 3, Rizal St.',
                      controller: _street,
                      enabled: !_saving,
                    ),
                    if (_formError != null) ...[
                      const SizedBox(height: AppSpacing.sm),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(AppSpacing.md),
                        decoration: BoxDecoration(
                          color: AppColors.red50,
                          borderRadius: BorderRadius.circular(AppRadius.md),
                        ),
                        child: Text(
                          _formError!,
                          style: AppText.body(size: AppTextSize.body, color: AppColors.red600, height: 1.4),
                        ),
                      ),
                    ],
                  ],
                )),
              ),
            ),
            // Pinned, so Save is in reach however far the form is scrolled.
            Container(
              padding: EdgeInsets.fromLTRB(AppLayout.gutter, 12, AppLayout.gutter, 16 + MediaQuery.paddingOf(context).bottom),
              decoration: const BoxDecoration(border: Border(top: BorderSide(color: AppColors.cardDivider))),
              child: _capped(AppButton(
                label: _tr('profile.save'),
                loading: _saving,
                onPressed: _saving || !dirty ? null : _save,
              )),
            ),
          ],
        ),
      ),
    );
  }
}

class _GroupLabel extends StatelessWidget {
  final String text;

  const _GroupLabel({required this.text});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Semantics(
        header: true,
        child: Text(text, style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: AppColors.sectionInk)),
      ),
    );
  }
}

/// A value the resident can see but not change here: text with a lock, not an
/// input box, so it never looks editable. [action] sits at the end (Change).
class _LockedRow extends StatelessWidget {
  final String label;
  final String value;
  final bool filipino;
  final Widget? action;

  const _LockedRow({
    required this.label,
    required this.value,
    required this.filipino,
    this.action,
  });

  @override
  Widget build(BuildContext context) {
    final missing = value.isEmpty;
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: AppColors.cardDivider))),
      child: Row(
        children: [
          const Icon(Icons.lock_outline_rounded, size: 20, color: AppColors.inkMuted),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
                const SizedBox(height: 2),
                Text(
                  missing ? tr(filipino, 'profile.value_missing') : value,
                  style: AppText.display(
                    size: AppTextSize.bodyLg,
                    weight: FontWeight.w600,
                    color: missing ? AppColors.inkFaint : AppColors.ink,
                  ),
                ),
              ],
            ),
          ),
          if (action != null) action!,
        ],
      ),
    );
  }
}
