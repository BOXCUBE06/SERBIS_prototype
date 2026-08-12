
library serbis.screens.auth.login;

import 'package:flutter/material.dart';
import '../../state/api_service.dart';
import '../../state/account_store.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/shared_widgets.dart';


class LoginScreen extends StatefulWidget {
  final UserStore userStore;
  final void Function(AppUser user) onLoginSuccess;
  final VoidCallback onGoToRegister;

  /// The credentials were right but the address is unverified. Carries it so
  /// the verify screen can resume a registration that was left half-finished.
  final void Function(String email) onEmailUnverified;
  final String? infoMessage;

  const LoginScreen({
    super.key,
    required this.userStore,
    required this.onLoginSuccess,
    required this.onGoToRegister,
    required this.onEmailUnverified,
    this.infoMessage,
  });

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey    = GlobalKey<FormState>();
  final _emailCtrl  = TextEditingController();
  final _passCtrl   = TextEditingController();
  bool    _loading   = false;
  String? _formError;

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _formError = null);
    if (!_formKey.currentState!.validate()) return;

    setState(() => _loading = true);

    try {
      final user = await widget.userStore.login(
        email:    _emailCtrl.text.trim(),
        password: _passCtrl.text,
      );
      if (!mounted) return;
      widget.onLoginSuccess(user);
    } on ApiException catch (e) {
      // An abandoned registration: the password was right, the address was
      // never verified. Sending them to the code screen is the only useful
      // answer — an error on this form leaves them with nothing to do.
      if (e.isEmailUnverified) {
        widget.onEmailUnverified(_emailCtrl.text.trim());
        return;
      }
      // Already resident-readable: "Invalid resident credentials." on a bad
      // password, the connection message when the server is unreachable.
      // Already logged by ApiService with its status; the email is deliberately
      // not added here.
      setState(() => _formError = e.message);
    } catch (error) {
      // Anything that is not an ApiException got past the HTTP layer's own
      // normalisation, so nothing has logged it yet.
      AppLog.error('auth', 'resident login', error: error);
      setState(() => _formError =
          'Cannot connect to server. Check your connection.');
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
            const AppHeader(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'Welcome back',
                      style: AppText.display(size: 21),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Log in to submit and track your service requests.',
                      textAlign: TextAlign.center,
                      style: AppText.body(
                          size: 12.5, color: AppColors.inkMuted, height: 1.5),
                    ),
                    const SizedBox(height: 16),
                    const ServicePurposeNote(),
                    if (widget.infoMessage != null) ...[
                      const SizedBox(height: 14),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.green50,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.check_circle_outline_rounded,
                                size: 18, color: AppColors.green700),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(widget.infoMessage!,
                                  style: AppText.body(
                                      size: 12.5,
                                      color: AppColors.green900,
                                      height: 1.5)),
                            ),
                          ],
                        ),
                      ),
                    ],

                    const SizedBox(height: 24),
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
                      hint: 'Enter your password',
                      controller: _passCtrl,
                      obscure: true,
                      prefixIcon: Icons.lock_outline_rounded,
                      validator: (v) =>
                          (v ?? '').isEmpty ? 'Enter your password' : null,
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
                    Align(
                      alignment: Alignment.centerRight,
                      child: GestureDetector(
                        onTap: () => showAppSnackBar(
                            context, 'Contact MDRRMO to reset your password.'),
                        child: Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Text('Forgot password?',
                              style: AppText.display(
                                  size: 12,
                                  weight: FontWeight.w600,
                                  color: AppColors.green700)),
                        ),
                      ),
                    ),

                    const SizedBox(height: 8),
                    AppButton(
                        label: 'Log in',
                        loading: _loading,
                        onPressed: _submit),
                    const SizedBox(height: 18),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text("Don't have an account?",
                            style: AppText.body(
                                size: 12.5, color: AppColors.inkMuted)),
                        const SizedBox(width: 4),
                        GestureDetector(
                          onTap: widget.onGoToRegister,
                          child: Text('Register',
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
