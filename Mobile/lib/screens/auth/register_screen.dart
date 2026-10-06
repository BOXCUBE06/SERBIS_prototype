
library serbis.screens.auth.register;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../models/phone_number.dart';
import '../../state/account_store.dart';
import '../../state/api_service.dart' show VerificationDelivery;
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/auth_layout.dart';
import '../../widgets/barangay_field.dart';
import '../../widgets/form_section.dart' show SegmentedChoice;
import '../../widgets/form_steps.dart';
import '../../widgets/shared_widgets.dart';


class RegisterScreen extends StatefulWidget {
  final UserStore userStore;
  /// Carries the address the account was created with, and where the first
  /// code was sent — registration is not finished until that code comes back.
  final void Function(String phone, VerificationDelivery? delivery)
      onRegisterSuccess;
  final VoidCallback onGoToLogin;

  const RegisterScreen({
    super.key,
    required this.userStore,
    required this.onRegisterSuccess,
    required this.onGoToLogin,
  });

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey       = GlobalKey<FormState>();
  final _firstNameCtrl = TextEditingController();
  final _lastNameCtrl  = TextEditingController();
  final _streetCtrl    = TextEditingController();
  final _phoneCtrl     = TextEditingController();
  final _passwordCtrl  = TextEditingController();
  final _confirmCtrl   = TextEditingController();
  final _orgNameCtrl   = TextEditingController();

  /// Individual (a household's head, the default) or Organization. There is no
  /// third choice: a barangay hall's account is made by MDRRMO staff.
  bool    _organization = false;
  bool    _agreed     = false;
  int     _step       = 0;
  String? _agreeError;
  bool    _loading    = false;
  String? _formError;

  // barangay_id is a required non-null FK on a resident, so the list has to be
  // in hand before anyone can sign up. Fetched from the public GET /barangays.
  List<BarangayOption> _barangays = [];
  bool    _loadingBarangays = true;
  bool    _barangaysFailed  = false;
  int?    _barangayId;

  static final RegExp _upper = RegExp(r'[A-Z]');
  static final RegExp _lower = RegExp(r'[a-z]');
  static final RegExp _digit = RegExp(r'[0-9]');

  /// What may be typed into the number field. The validator is what decides
  /// whether it is a real one — this only keeps the letters and punctuation
  /// out, so a resident cannot compose something the server was always going
  /// to refuse.
  static final _phoneInput =
      FilteringTextInputFormatter.allow(RegExp(r'[0-9+]'));

  @override
  void initState() {
    super.initState();
    _loadBarangays();
  }

  Future<void> _loadBarangays() async {
    setState(() {
      _loadingBarangays = true;
      _barangaysFailed  = false;
    });

    try {
      final list = await widget.userStore.barangays();
      if (!mounted) return;
      setState(() {
        _barangays        = list;
        _loadingBarangays = false;
      });
    } catch (error) {
      // `barangay_id` is required by the backend, so an unloadable picker means
      // nobody can register at all — the highest-stakes silent failure in the
      // app, and it renders as one retry panel.
      AppLog.error('auth', 'load barangays', error: error,
          reason: 'registration blocked');
      if (!mounted) return;
      setState(() {
        _loadingBarangays = false;
        _barangaysFailed  = true;
      });
    }
  }

  @override
  void dispose() {
    _firstNameCtrl.dispose();
    _lastNameCtrl.dispose();
    _streetCtrl.dispose();
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    _confirmCtrl.dispose();
    _orgNameCtrl.dispose();
    super.dispose();
  }

  /// Mirrors `Password::min(8)->mixedCase()->numbers()` in AuthController. The
  /// old rule demanded exactly 8 characters and a symbol, which rejected valid
  /// passwords and accepted all-caps ones the server then refused.
  String? _validatePassword(String? v) {
    final value = v ?? '';
    if (value.isEmpty)     return 'Enter a password';
    if (value.length < 8)  return 'Password must be at least 8 characters';
    if (!_upper.hasMatch(value)) return 'Include at least one uppercase letter (A-Z)';
    if (!_lower.hasMatch(value)) return 'Include at least one lowercase letter (a-z)';
    if (!_digit.hasMatch(value)) return 'Include at least one number (0-9)';
    return null;
  }

  /// The three steps, in order. English keys, as everywhere before sign-in.
  static const _steps = ['Who you are', 'Where you live', 'Password and review'];

  void _goTo(int step) => setState(() {
        _step = step;
        _formError = null;
        _agreeError = null;
      });

  void _back() => _step == 0 ? widget.onGoToLogin() : _goTo(_step - 1);

  /// Checks only the step that is showing; the last one submits.
  void _next() {
    if (!_formKey.currentState!.validate()) return;

    if (_step == 1 && _barangayId == null) {
      // Reachable when the barangay list could not load: no picker, no validator.
      setState(() => _formError = 'Select your barangay.');
      return;
    }

    if (_step == _steps.length - 1) {
      _submit();
    } else {
      _goTo(_step + 1);
    }
  }

  Future<void> _submit() async {
    setState(() {
      _formError = null;
      _agreeError = _agreed ? null : 'Please agree to the data privacy notice to continue.';
    });
    if (!_agreed) return;

    final barangayId = _barangayId;
    if (barangayId == null) {
      setState(() => _formError = 'Select your barangay.');
      return;
    }

    setState(() => _loading = true);

    try {
      final outcome = await widget.userStore.register(
        firstName:     _firstNameCtrl.text.trim(),
        lastName:      _lastNameCtrl.text.trim(),
        barangayId:    barangayId,
        streetAddress: _streetCtrl.text.trim(),
        phoneNumber:   _phoneCtrl.text.trim(),
        password:      _passwordCtrl.text,
        accountType:   _organization ? 'organization' : 'head_of_family',
        organizationName: _organization ? _orgNameCtrl.text.trim() : null,
      );

      if (!mounted) return;

      if (outcome.failed) {
        setState(() => _formError = outcome.error);
        return;
      }
      // The account is not usable yet — the code finishes it. The number goes
      // with the callback so the verify screen never asks the resident to
      // retype what they just entered.
      widget.onRegisterSuccess(_phoneCtrl.text.trim(), outcome.delivery);
    } catch (error) {
      // `UserStore.register` converts an ApiException into a returned message
      // rather than throwing, so anything arriving here escaped the HTTP layer
      // and has not been logged. No field values: this method holds the
      // password and the phone number.
      AppLog.error('auth', 'register', error: error);
      if (mounted) {
        setState(() =>
            _formError = 'Cannot connect to server. Check your connection.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AuthPage(
      header: TabHeaderBar(
        title: 'Create your account',
        subtitle: 'Step ${_step + 1} of ${_steps.length} · ${_steps[_step]}',
        filipino: false,
        onBack: _loading ? null : _back,
      ),
      footer: FormStepFooter(
        step: _step,
        stepNames: _steps,
        filipino: false,
        submitting: _loading,
        submitLabel: 'Create account',
        onBack: _back,
        onNext: _next,
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            FormStepProgress(step: _step, total: _steps.length),
            const SizedBox(height: AppSpacing.lg),
            ...switch (_step) {
              0 => _whoYouAre(),
              1 => _whereYouLive(),
              _ => _passwordAndReview(),
            },
            if (_formError != null) InlineNotice(text: _formError!),
          ],
        ),
      ),
    );
  }

  List<Widget> _whoYouAre() => [
        // The old copy promised "you will log in afterwards to verify your
        // account". No verification step exists, and none is being built -- the
        // OTP columns it referred to were dead schema and have been dropped.
        // Telling a resident to expect one leaves them waiting for a screen that
        // never comes.
        Text(
          _organization
              ? 'For a school, office or other group. MDRRMO checks an '
                  'organization account before it can request services.'
              : 'One account per household, registered by the head of the family. '
                  'You can log in as soon as you have registered.',
          style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
        ),
        const SizedBox(height: AppSpacing.lg),
        const ServicePurposeNote(),
        const SizedBox(height: AppSpacing.xl),
        Text('Registering as', style: AppText.fieldLabel()),
        const SizedBox(height: AppSpacing.xs),
        SegmentedChoice(
          leftLabel: 'Head of the Family',
          rightLabel: 'Organization',
          rightSelected: _organization,
          onChanged: (organization) => setState(() => _organization = organization),
        ),
        const SizedBox(height: AppSpacing.lg),
        if (_organization)
          AuthTextField(
            label: 'Organization name',
            hint: 'e.g. Isabela State University',
            controller: _orgNameCtrl,
            prefixIcon: Icons.apartment_rounded,
            maxLength: 150,
            validator: (v) => (v ?? '').trim().isEmpty ? 'Enter the organization name' : null,
          ),
        AuthTextField(
          label: _organization ? 'Contact first name' : 'First name',
          hint: 'e.g. Juan',
          controller: _firstNameCtrl,
          prefixIcon: Icons.person_outline_rounded,
          validator: (v) => (v ?? '').trim().isEmpty
              ? (_organization ? 'Enter the contact person\'s first name' : 'Enter your first name')
              : null,
        ),
        AuthTextField(
          label: _organization ? 'Contact last name' : 'Last name',
          hint: 'e.g. Delacruz',
          controller: _lastNameCtrl,
          prefixIcon: Icons.person_outline_rounded,
          validator: (v) => (v ?? '').trim().isEmpty
              ? (_organization ? 'Enter the contact person\'s last name' : 'Enter your last name')
              : null,
        ),
        AuthTextField(
          label: 'Mobile number',
          hint: '09XXXXXXXXX',
          controller: _phoneCtrl,
          keyboard: TextInputType.phone,
          prefixIcon: Icons.phone_outlined,
          maxLength: PhoneNumber.maxLength,
          inputFormatters: [_phoneInput],
          validator: (v) {
            final val = (v ?? '').trim();
            if (val.isEmpty) return 'Enter your mobile number';
            // The server's own rule — see PhoneNumber. This field used to accept
            // anything vaguely numeric and hand the resident a 422 they could
            // not have predicted.
            if (!PhoneNumber.isValid(val)) {
              return 'Enter a valid mobile number';
            }
            return null;
          },
        ),
        const SizedBox(height: AppSpacing.sm),
        Wrap(
          alignment: WrapAlignment.center,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('Already have an account?', style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted)),
            AuthLink(label: 'Log in', onPressed: widget.onGoToLogin),
          ],
        ),
      ];

  List<Widget> _whereYouLive() => [
        Text(
          'Your barangay is where your requests are sent. The purok or street is optional.',
          style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
        ),
        const SizedBox(height: AppSpacing.lg),
        BarangayField(
          barangays: _barangays,
          value: _barangayId,
          loading: _loadingBarangays,
          failed: _barangaysFailed,
          onRetry: _loadBarangays,
          onChanged: (id) => setState(() => _barangayId = id),
        ),
        // Optional. The barangay picker above is required, but the purok/street
        // is exactly the detail a resident might not have memorized while filling
        // this in — editable later from the profile either way (MDRRMO feedback,
        // 2026-09-19).
        AuthTextField(
          label: 'Street / Purok (optional)',
          hint: 'e.g. Purok 3, Rizal St.',
          controller: _streetCtrl,
          prefixIcon: Icons.home_outlined,
        ),
      ];

  List<Widget> _passwordAndReview() {
    String or(String value) => value.trim().isEmpty ? 'Not given' : value.trim();
    String? barangayName;
    for (final b in _barangays) {
      if (b.id == _barangayId) barangayName = b.name;
    }

    return [
      AuthTextField(
        label: 'Password',
        hint: 'At least 8 characters',
        controller: _passwordCtrl,
        obscure: true,
        prefixIcon: Icons.lock_outline_rounded,
        validator: _validatePassword,
      ),
      Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.md),
        child: Text(
          'Must be at least 8 characters with upper and lower '
          'case letters and at least one number — e.g. Pasada123',
          style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
        ),
      ),
      AuthTextField(
        label: 'Confirm password',
        hint: '',
        controller: _confirmCtrl,
        obscure: true,
        prefixIcon: Icons.lock_outline_rounded,
        validator: (v) {
          if ((v ?? '').isEmpty) return 'Confirm your password';
          if (v != _passwordCtrl.text) {
            return 'Passwords do not match';
          }
          return null;
        },
      ),
      const SizedBox(height: AppSpacing.md),
      // C_Register3: one list, a row per answer, Edit back to its step.
      ReviewList(
        filipino: false,
        rows: [
          (
            label: _organization ? 'Organization' : 'Head of the Family',
            value: [
              if (_organization) or(_orgNameCtrl.text),
              or('${_firstNameCtrl.text.trim()} ${_lastNameCtrl.text.trim()}'),
            ].join(' · '),
            onEdit: () => _goTo(0),
          ),
          (label: 'Mobile number', value: or(_phoneCtrl.text), onEdit: () => _goTo(0)),
          (
            label: 'Address',
            value: [
              if (_streetCtrl.text.trim().isNotEmpty) _streetCtrl.text.trim(),
              or(barangayName ?? ''),
            ].join(', '),
            onEdit: () => _goTo(1),
          ),
        ],
      ),
      const SizedBox(height: AppSpacing.md),
      InkWell(
        onTap: () => setState(() {
          _agreed = !_agreed;
          _agreeError = null;
        }),
        borderRadius: BorderRadius.circular(AppRadius.sm),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 1),
                  child: Icon(
                    _agreed ? Icons.check_box_rounded : Icons.check_box_outline_blank_rounded,
                    size: 26,
                    color: _agreed ? AppColors.green700 : AppColors.inkMuted,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'I agree that my information will be used by '
                    'Echague MDRRMO to process service requests '
                    'and send announcements.',
                    style: AppText.body(size: AppTextSize.body, color: AppColors.ink, height: 1.5),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      if (_agreeError != null)
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.xs),
          child: Text(_agreeError!, style: AppText.body(size: AppTextSize.small, color: AppColors.red600)),
        ),
      const SizedBox(height: AppSpacing.md),
    ];
  }
}
