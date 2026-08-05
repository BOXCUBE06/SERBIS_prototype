
library serbis.screens.auth.register;

import 'package:flutter/material.dart';
import '../../state/account_store.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/shared_widgets.dart';


class RegisterScreen extends StatefulWidget {
  final UserStore userStore;
  final VoidCallback onRegisterSuccess;
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
  final _phoneCtrl     = TextEditingController();
  final _emailCtrl     = TextEditingController();
  final _passwordCtrl  = TextEditingController();
  final _confirmCtrl   = TextEditingController();
  bool    _agreed     = false;
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
  static final RegExp _phone = RegExp(r'^[0-9+][0-9 \-]{6,19}$');

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
    _phoneCtrl.dispose();
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    _confirmCtrl.dispose();
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

  Future<void> _submit() async {
    setState(() => _formError = null);

    final formValid = _formKey.currentState!.validate();
    if (!_agreed) {
      showAppSnackBar(
          context, 'Please agree to the data privacy notice to continue.');
    }
    if (!formValid || !_agreed) return;

    final barangayId = _barangayId;
    if (barangayId == null) {
      setState(() => _formError = 'Select your barangay.');
      return;
    }

    setState(() => _loading = true);

    try {
      final error = await widget.userStore.register(
        firstName:   _firstNameCtrl.text.trim(),
        lastName:    _lastNameCtrl.text.trim(),
        barangayId:  barangayId,
        phoneNumber: _phoneCtrl.text.trim(),
        email:       _emailCtrl.text.trim(),
        password:    _passwordCtrl.text,
      );

      if (!mounted) return;

      if (error != null) {
        setState(() => _formError = error);
        return;
      }
      widget.onRegisterSuccess();
    } catch (error) {
      // `UserStore.register` converts an ApiException into a returned message
      // rather than throwing, so anything arriving here escaped the HTTP layer
      // and has not been logged. No field values: this method holds the
      // password, the phone number and the email.
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
    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            _Header(onBack: widget.onGoToLogin),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 24, 24, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text('Create your account',
                        style: AppText.display(size: 20)),
                    const SizedBox(height: 4),
                    Text(
                      'Register to submit service requests and receive '
                      'updates from MDRRMO. You will log in afterwards '
                      'to verify your account.',
                      style: AppText.body(
                          size: 12.5, color: AppColors.inkMuted, height: 1.5),
                    ),
                    const SizedBox(height: 22),

                    AuthTextField(
                      label: 'First name',
                      hint: 'e.g. Juan',
                      controller: _firstNameCtrl,
                      prefixIcon: Icons.person_outline_rounded,
                      validator: (v) => (v ?? '').trim().isEmpty
                          ? 'Enter your first name'
                          : null,
                    ),

                    AuthTextField(
                      label: 'Last name',
                      hint: 'e.g. Delacruz',
                      controller: _lastNameCtrl,
                      prefixIcon: Icons.person_outline_rounded,
                      validator: (v) => (v ?? '').trim().isEmpty
                          ? 'Enter your last name'
                          : null,
                    ),

                    AuthTextField(
                      label: 'Mobile number',
                      hint: 'e.g. 09171234567',
                      controller: _phoneCtrl,
                      keyboard: TextInputType.phone,
                      prefixIcon: Icons.phone_outlined,
                      validator: (v) {
                        final val = (v ?? '').trim();
                        if (val.isEmpty) return 'Enter your mobile number';
                        if (!_phone.hasMatch(val)) {
                          return 'Enter a valid mobile number';
                        }
                        return null;
                      },
                    ),

                    _BarangayField(
                      barangays: _barangays,
                      value: _barangayId,
                      loading: _loadingBarangays,
                      failed: _barangaysFailed,
                      onRetry: _loadBarangays,
                      onChanged: (id) => setState(() => _barangayId = id),
                    ),

                    AuthTextField(
                      label: 'Email address',
                      hint: 'yourname@email.com',
                      controller: _emailCtrl,
                      keyboard: TextInputType.emailAddress,
                      prefixIcon: Icons.email_outlined,
                      validator: (v) {
                        final val = (v ?? '').trim();
                        if (val.isEmpty) return 'Enter your email address';
                        if (!val.contains('@') || !val.contains('.')) {
                          return 'Enter a valid email address';
                        }
                        return null;
                      },
                    ),

                    AuthTextField(
                      label: 'Password',
                      hint: 'At least 8 characters',
                      controller: _passwordCtrl,
                      obscure: true,
                      prefixIcon: Icons.lock_outline_rounded,
                      validator: _validatePassword,
                    ),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 13),
                      child: Text(
                        'Must be at least 8 characters with upper and lower '
                        'case letters and at least one number — e.g. Pasada123',
                        style: AppText.body(
                            size: 11, color: AppColors.inkMuted, height: 1.5),
                      ),
                    ),

                    AuthTextField(
                      label: 'Confirm password',
                      hint: 'Re-enter your password',
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

                    if (_formError != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.error_outline_rounded,
                                size: 16, color: AppColors.red600),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(_formError!,
                                  style: AppText.body(
                                      size: 12.5,
                                      color: AppColors.red600,
                                      height: 1.4)),
                            ),
                          ],
                        ),
                      ),

                    const SizedBox(height: 4),
                    InkWell(
                      onTap: () => setState(() => _agreed = !_agreed),
                      borderRadius: BorderRadius.circular(10),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Padding(
                              padding: const EdgeInsets.only(top: 1),
                              child: Icon(
                                _agreed
                                    ? Icons.check_box_rounded
                                    : Icons.check_box_outline_blank_rounded,
                                size: 19,
                                color: _agreed
                                    ? AppColors.green700
                                    : AppColors.inkFaint,
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                'I agree that my information will be used by '
                                'Echague MDRRMO to process service requests '
                                'and send announcements.',
                                style: AppText.body(
                                    size: 12,
                                    color: AppColors.inkMuted,
                                    height: 1.5),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),

                    const SizedBox(height: 14),
                    AppButton(
                        label: 'Create account',
                        loading: _loading,
                        onPressed: _submit),
                    const SizedBox(height: 18),

                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text('Already have an account?',
                            style: AppText.body(
                                size: 12.5, color: AppColors.inkMuted)),
                        const SizedBox(width: 4),
                        GestureDetector(
                          onTap: widget.onGoToLogin,
                          child: Text('Log in',
                              style: AppText.display(
                                  size: 12.5,
                                  weight: FontWeight.w700,
                                  color: AppColors.green700)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Barangay picker for the register form, styled to match [AuthTextField].
/// Sign-up is impossible without it, so a failed fetch gets its own retry
/// rather than a silently empty list.
class _BarangayField extends StatelessWidget {
  final List<BarangayOption> barangays;
  final int? value;
  final bool loading;
  final bool failed;
  final VoidCallback onRetry;
  final ValueChanged<int?> onChanged;

  const _BarangayField({
    required this.barangays,
    required this.value,
    required this.loading,
    required this.failed,
    required this.onRetry,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Barangay',
              style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          if (loading)
            _shell(
              child: Row(
                children: [
                  const SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                  const SizedBox(width: 10),
                  Text('Loading barangays…',
                      style:
                          AppText.body(size: 13, color: AppColors.inkFaint)),
                ],
              ),
            )
          else if (failed)
            _shell(
              child: Row(
                children: [
                  const Icon(Icons.wifi_off_rounded,
                      size: 16, color: AppColors.inkFaint),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      "Couldn't load barangays.",
                      style:
                          AppText.body(size: 12.5, color: AppColors.inkMuted),
                    ),
                  ),
                  TextButton(onPressed: onRetry, child: const Text('Retry')),
                ],
              ),
            )
          else
            DropdownButtonFormField<int>(
              initialValue: value,
              isExpanded: true,
              icon: const Icon(Icons.expand_more_rounded,
                  color: AppColors.inkFaint),
              style: AppText.body(size: 13, color: AppColors.ink),
              hint: Text('Select your barangay',
                  style: AppText.body(size: 13, color: AppColors.inkFaint)),
              validator: (v) => v == null ? 'Select your barangay' : null,
              items: barangays
                  .map((b) => DropdownMenuItem(value: b.id, child: Text(b.name)))
                  .toList(),
              onChanged: onChanged,
              decoration: InputDecoration(
                prefixIcon: const Icon(Icons.location_on_outlined,
                    size: 18, color: AppColors.inkFaint),
                filled: true,
                fillColor: AppColors.surface,
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
                errorStyle: AppText.body(size: 11, color: AppColors.red600),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide:
                      const BorderSide(color: AppColors.line, width: 1.5),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide:
                      const BorderSide(color: AppColors.line, width: 1.5),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide:
                      const BorderSide(color: AppColors.green600, width: 1.5),
                ),
                errorBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide:
                      const BorderSide(color: AppColors.red600, width: 1.5),
                ),
                focusedErrorBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide:
                      const BorderSide(color: AppColors.red600, width: 1.5),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _shell({required Widget child}) {
    return Container(
      height: 48,
      padding: const EdgeInsets.symmetric(horizontal: 13),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.line, width: 1.5),
      ),
      alignment: Alignment.centerLeft,
      child: child,
    );
  }
}

/// Compact gradient header with a back arrow — reused from the old
/// register screen.
class _Header extends StatelessWidget {
  final VoidCallback onBack;
  const _Header({required this.onBack});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(12, 14, 24, 28),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            right: -50,
            top: -70,
            child: Container(
              width: 160,
              height: 160,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withOpacity(.05),
              ),
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconButton(
                onPressed: onBack,
                icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
              ),
              Padding(
                padding: const EdgeInsets.only(left: 12, top: 4),
                child: Row(
                  children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(.12),
                        borderRadius: BorderRadius.circular(12),
                        border:
                            Border.all(color: Colors.white.withOpacity(.14)),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.shield_outlined,
                          color: Colors.white, size: 19),
                    ),
                    const SizedBox(width: 11),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('SERBIS',
                            style: AppText.display(
                                size: 17,
                                color: Colors.white,
                                letterSpacing: .5)),
                        Text('ECHAGUE MDRRMO',
                            style: AppText.display(
                                size: 10,
                                weight: FontWeight.w500,
                                color: Colors.white.withOpacity(.65),
                                letterSpacing: 2)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
