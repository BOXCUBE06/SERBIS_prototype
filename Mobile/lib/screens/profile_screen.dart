import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../data/library_articles.dart';
import '../state/app_state.dart';
import '../state/strings.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'library/article_reader_screen.dart';

class ProfileScreen extends StatefulWidget {
  final AppState appState;
  final VoidCallback onLogout;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;
  final String? initialName;
  final String? initialPhone;
  final String? initialAddress;

  const ProfileScreen({
    super.key,
    required this.appState,
    required this.onLogout,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    this.initialName,
    this.initialPhone,
    this.initialAddress,
  });

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _smsAlerts = true;
  bool _pushNotifications = true;
  late String _name = widget.initialName ?? 'Juan Delacruz';
  late String _phone = widget.initialPhone ?? '+63 939-1145-133';
  late String _address = widget.initialAddress ?? 'Echague, Isabela';

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
                  Stack(
                    clipBehavior: Clip.none,
                    children: [
                      Container(
                        width: 84,
                        height: 84,
                        decoration: BoxDecoration(
                          color: AppColors.green50,
                          shape: BoxShape.circle,
                          border: Border.all(color: AppColors.surface, width: 3),
                          boxShadow: [
                            BoxShadow(color: AppColors.green900.withOpacity(.06), blurRadius: 12, offset: const Offset(0, 4)),
                          ],
                        ),
                        alignment: Alignment.center,
                        child: const Icon(Icons.person_outline_rounded, size: 36, color: AppColors.green700),
                      ),
                      Positioned(
                        bottom: -2,
                        right: -2,
                        child: GestureDetector(
                          onTap: () => showAppSnackBar(context, 'Photo picker would open here.'),
                          child: Container(
                            width: 28,
                            height: 28,
                            decoration: BoxDecoration(
                              color: AppColors.green700,
                              shape: BoxShape.circle,
                              border: Border.all(color: AppColors.surface, width: 2),
                            ),
                            alignment: Alignment.center,
                            child: const Icon(Icons.edit_rounded, size: 13, color: Colors.white),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Text(_name, style: AppText.display(size: 18)),
                  const SizedBox(height: 5),
                  _detailRow(Icons.phone_outlined, _phone),
                  const SizedBox(height: 3),
                  _detailRow(Icons.place_outlined, _address),
                  const SizedBox(height: 16),
                  AppButton(label: tr(filipino, 'profile.update_info'), onPressed: () => _editProfile(context)),
                ],
              ),
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 0, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: tr(filipino, 'profile.notifications')),
              _SettingsRow(
                icon: Icons.campaign_outlined,
                title: tr(filipino, 'profile.sms_alerts'),
                subtitle: tr(filipino, 'profile.sms_alerts_desc'),
                trailing: Switch(
                  value: _smsAlerts,
                  activeColor: Colors.white,
                  activeTrackColor: AppColors.green700,
                  onChanged: (v) => setState(() => _smsAlerts = v),
                ),
              ),
              _SettingsRow(
                icon: Icons.notifications_outlined,
                title: tr(filipino, 'profile.push'),
                subtitle: tr(filipino, 'profile.push_desc'),
                trailing: Switch(
                  value: _pushNotifications,
                  activeColor: Colors.white,
                  activeTrackColor: AppColors.green700,
                  onChanged: (v) => setState(() => _pushNotifications = v),
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: tr(filipino, 'profile.account_settings')),
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
                subtitle: '5 saved · 4.2 MB used',
                trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkFaint),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => _OfflineMaterialsPage(filipino: filipino)),
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

  Widget _detailRow(IconData icon, String text) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Icon(icon, size: 14, color: AppColors.inkFaint),
        const SizedBox(width: 6),
        Text(text, style: AppText.body(size: 12.5, color: AppColors.inkMuted)),
      ],
    );
  }

  Future<void> _editProfile(BuildContext context) async {
    final nameCtrl = TextEditingController(text: _name);
    final phoneCtrl = TextEditingController(text: _phone);
    final addressCtrl = TextEditingController(text: _address);

    final saved = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
        child: Container(
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
              Text('Update information', style: AppText.display(size: 18)),
              const SizedBox(height: 14),
              _editField('Full name', nameCtrl),
              _editField('Contact number', phoneCtrl, keyboard: TextInputType.phone, maxLength: 11, digitsOnly: true),
              _editField('Address', addressCtrl),
              const SizedBox(height: 6),
              AppButton(label: 'Save changes', onPressed: () => Navigator.pop(ctx, true)),
            ],
          ),
        ),
      ),
    );

    if (saved == true) {
      setState(() {
        _name = nameCtrl.text.trim().isEmpty ? _name : nameCtrl.text.trim();
        _phone = phoneCtrl.text.trim().isEmpty ? _phone : phoneCtrl.text.trim();
        _address = addressCtrl.text.trim().isEmpty ? _address : addressCtrl.text.trim();
      });
      if (context.mounted) showAppSnackBar(context, 'Profile information updated.');
    }
  }

  Widget _editField(
    String label,
    TextEditingController controller, {
    TextInputType keyboard = TextInputType.text,
    int? maxLength,
    bool digitsOnly = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          TextField(
            controller: controller,
            keyboardType: keyboard,
            maxLength: maxLength,
            inputFormatters: digitsOnly ? [FilteringTextInputFormatter.digitsOnly] : null,
            style: AppText.body(size: 13),
            decoration: InputDecoration(
              counterText: '',
              filled: true,
              fillColor: AppColors.surface,
              contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
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
            ),
          ),
        ],
      ),
    );
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

/// Simple list of saved/available offline materials, opened from the
/// "Offline materials" row on Profile.
class _OfflineMaterialsPage extends StatelessWidget {
  final bool filipino;
  const _OfflineMaterialsPage({required this.filipino});

  @override
  Widget build(BuildContext context) {
    // (article key, page count label override, saved)
    final items = [
      ('cpr', filipino ? '4 na pahina' : '4 pages', true),
      ('burns', filipino ? '3 pahina' : '3 pages', true),
      ('wound_care', filipino ? '2 pahina' : '2 pages', true),
      ('before', null, true),
      ('evacuation_map', null, true),
      ('during', null, false),
      ('after', null, false),
      ('drrm_plan', null, false),
    ];

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
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 22),
            child: Column(
              children: items.map((i) {
                final article = libraryArticles[i.$1]!;
                final title = article.titleFor(filipino: filipino);
                final subtitle = article.subtitleFor(filipino: filipino);
                final pages = i.$2;
                final saved = i.$3;

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
                                    pages == null ? subtitle : '$subtitle · $pages',
                                    style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            OfflinePill(saved: saved, label: title),
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