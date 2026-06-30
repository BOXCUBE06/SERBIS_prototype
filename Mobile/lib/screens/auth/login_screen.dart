import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../state/user_store.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

/// Login screen.
///
/// Validates credentials against locally-registered accounts in
/// [userStore] (see `lib/state/user_store.dart`). There is still no remote
/// backend — accounts are created via Register and persisted on-device —
/// but login now only succeeds for a phone number + password that were
/// actually registered.
class LoginScreen extends StatefulWidget {
  final UserStore userStore;
  final void Function(AppUser user) onLoginSuccess;
  final VoidCallback onGoToRegister;

  /// Optional banner shown after a successful registration, e.g.
  /// "Account created! Please log in."
  final String? infoMessage;

  const LoginScreen({
    super.key,
    required this.userStore,
    required this.onLoginSuccess,
    required this.onGoToRegister,
    this.infoMessage,
  });

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _loading = false;
  String? _formError;

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _formError = null);
    if (!_formKey.currentState!.validate()) return;

    setState(() => _loading = true);
    // Simulated lookup delay — replace with a real sign-in API call.
    await Future.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;

    final user = widget.userStore.validate(_phoneCtrl.text, _passwordCtrl.text);
    setState(() => _loading = false);

    if (user == null) {
      setState(() => _formError = 'Incorrect mobile number or password.');
      return;
    }

    widget.onLoginSuccess(user);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            const AppHeader(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        Text('Welcome back', style: AppText.display(size: 21), textAlign: TextAlign.center),
                        const SizedBox(height: 4),
                        Text(
                          'Log in to submit and track your service requests with Echague MDRRMO.',
                          textAlign: TextAlign.center,
                          style: AppText.body(size: 12.5, color: AppColors.inkMuted, height: 1.5),
                        ),
                      ],
                    ),
                    if (widget.infoMessage != null) ...[
                      const SizedBox(height: 14),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.green50,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.check_circle_outline_rounded, size: 18, color: AppColors.green700),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                widget.infoMessage!,
                                style: AppText.body(size: 12.5, color: AppColors.green900, height: 1.5),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    const SizedBox(height: 24),
                    AuthTextField(
                      label: 'Mobile number',
                      hint: '09XXXXXXXXX',
                      controller: _phoneCtrl,
                      keyboard: TextInputType.phone,
                      prefixIcon: Icons.phone_outlined,
                      maxLength: 11,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      validator: (v) {
                        final digits = (v ?? '').replaceAll(RegExp(r'[^0-9]'), '');
                        if (digits.isEmpty) return 'Enter your mobile number';
                        if (digits.length != 11) return 'Mobile number must be 11 digits';
                        return null;
                      },
                    ),
                    AuthTextField(
                      label: 'Password',
                      hint: 'Enter your password',
                      controller: _passwordCtrl,
                      obscure: true,
                      prefixIcon: Icons.lock_outline_rounded,
                      maxLength: 8,
                      validator: (v) => (v ?? '').isEmpty ? 'Enter your password' : null,
                    ),
                    if (_formError != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.error_outline_rounded, size: 16, color: AppColors.red600),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _formError!,
                                style: AppText.body(size: 12.5, color: AppColors.red600, height: 1.4),
                              ),
                            ),
                          ],
                        ),
                      ),
                    Align(
                      alignment: Alignment.centerRight,
                      child: GestureDetector(
                        onTap: () => showAppSnackBar(context, 'Password reset is not available in this preview.'),
                        child: Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Text(
                            'Forgot password?',
                            style: AppText.display(size: 12, weight: FontWeight.w600, color: AppColors.green700),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    AppButton(label: 'Log in', loading: _loading, onPressed: _submit),
                    const SizedBox(height: 18),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text("Don't have an account?", style: AppText.body(size: 12.5, color: AppColors.inkMuted)),
                        const SizedBox(width: 4),
                        GestureDetector(
                          onTap: widget.onGoToRegister,
                          child: Text(
                            'Register',
                            style: AppText.display(size: 12.5, weight: FontWeight.w700, color: AppColors.green700),
                          ),
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

