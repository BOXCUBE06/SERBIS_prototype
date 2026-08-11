
library serbis.main;

import 'dart:async';

import 'package:flutter/material.dart';
import 'models/request_models.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/register_screen.dart';
import 'screens/auth/verify_email_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/library_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/services_screen.dart';
import 'screens/track_screen.dart';
import 'state/api_service.dart';
import 'state/app_log.dart';
import 'state/request_store.dart';
import 'state/account_store.dart';
import 'theme/app_theme.dart';
import 'widgets/offline_banner.dart';
import 'widgets/shared_widgets.dart';
import 'widgets/sos_button.dart';

void main() {
  // A build with no `--dart-define=API_BASE_URL` has nowhere to send its calls.
  // Stop here rather than letting every screen fail one request at a time with
  // "Cannot connect to server", which looks like a dead network and sends the
  // resident to reboot their phone. See `Mobile/README.md`.
  if (!ApiService.isConfigured) {
    runApp(const _MisconfiguredApp());
    return;
  }

  runApp(const SerbisApp());
}

/// Shown instead of the app when the build is missing its API base URL. Not
/// styled with the app theme on purpose: this is a message to whoever produced
/// the build, and it has to render even if everything else is broken.
class _MisconfiguredApp extends StatelessWidget {
  const _MisconfiguredApp();

  @override
  Widget build(BuildContext context) {
    return const MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        backgroundColor: Color(0xFF7F1D1D),
        body: Center(
          child: Padding(
            padding: EdgeInsets.all(28),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.build_circle_outlined, color: Colors.white, size: 56),
                SizedBox(height: 16),
                Text(
                  'This build has no API address',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                SizedBox(height: 12),
                Text(
                  'It was compiled without API_BASE_URL, so it cannot reach the '
                  'server. Rebuild with:\n\n'
                  'flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white, fontSize: 14, height: 1.5),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class SerbisApp extends StatelessWidget {
  const SerbisApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SERBIS — Echague MDRRMO',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      home: const AuthGate(),
    );
  }
}

class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

enum _AuthView { login, register, verifyEmail }

class _AuthGateState extends State<AuthGate> {
  final ApiService _api = ApiService();
  late final UserStore _userStore = UserStore(_api);

  bool _ready = false;
  AppUser? _currentUser;
  _AuthView _view = _AuthView.login;
  String? _loginInfoMessage;

  /// The address whose registration is waiting on a code. Set by registering,
  /// and by a login the server refused as unverified.
  String? _pendingVerificationEmail;

  @override
  void initState() {
    super.initState();
    _api.onUnauthorized = _onSessionExpired;
    _restoreSession();
  }

  /// A stored token carries no profile with it, so it has to be exchanged for
  /// one on every relaunch. Skipping this is what left the app authenticated
  /// with a blank name, email and address until the resident logged out and
  /// back in.
  Future<void> _restoreSession() async {
    await _api.loadToken();

    if (_api.isLoggedIn) {
      try {
        final user = await _userStore.currentUser();
        if (!mounted) {
          return;
        }

        setState(() {
          _currentUser = user;
          _ready = true;
        });
        return;
      } on ApiException catch (e) {
        // Before `mounted` is checked: a failure that happens as the widget is
        // going away is still a failure, and returning early would drop it.
        AppLog.warn('session', 'restore from stored token',
            reason: e.isUnauthorized ? 'token rejected' : 'server unreachable');

        if (!mounted) {
          return;
        }

        // A rejected token has already been cleared by ApiService. If the server
        // was merely unreachable the token is left alone, so logging in again
        // once there is a connection will work.
        _loginInfoMessage = e.isUnauthorized
            ? 'Your session expired. Please log in again.'
            : e.message;
      }
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _ready = true;
      _currentUser = null;
    });
  }

  /// Fired by ApiService when a stored token is rejected mid-session.
  void _onSessionExpired() {
    if (!mounted || _currentUser == null) {
      return;
    }

    setState(() {
      _currentUser = null;
      _view = _AuthView.login;
      _loginInfoMessage = 'Your session expired. Please log in again.';
    });
  }

  void _login(AppUser user) {
    setState(() {
      _currentUser = user;
      _ready = true;
    });
  }

  /// Registration now ends at the code screen rather than at the login form:
  /// the account is not usable until the emailed code comes back, and verifying
  /// issues a token, so a resident who finishes never sees a login screen at
  /// all on their first run.
  void _afterRegister(String email) {
    setState(() {
      _view = _AuthView.verifyEmail;
      _pendingVerificationEmail = email;
      _loginInfoMessage = null;
    });
  }

  void _logout() {
    _userStore.logout();
    setState(() {
      _currentUser = null;
      _view = _AuthView.login;
      _loginInfoMessage = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) {
      return const Scaffold(
        backgroundColor: AppColors.paper,
        body: Center(
          child: CircularProgressIndicator(color: AppColors.green700),
        ),
      );
    }

    if (_currentUser != null) {
      return RootShell(
        api: _api,
        userStore: _userStore,
        user: _currentUser!,
        // The profile screen can now change the resident row (their photo), so
        // the copy held here has to move with it or the next rebuild reinstates
        // the old avatar.
        onUserChanged: (user) => setState(() => _currentUser = user),
        onLogout: _logout,
      );
    }

    if (_view == _AuthView.login) {
      return LoginScreen(
        userStore: _userStore,
        onLoginSuccess: _login,
        onGoToRegister: () {
          setState(() {
            _view = _AuthView.register;
            _loginInfoMessage = null;
          });
        },
        onEmailUnverified: (email) {
          setState(() {
            _view = _AuthView.verifyEmail;
            _pendingVerificationEmail = email;
            _loginInfoMessage = null;
          });
        },
        infoMessage: _loginInfoMessage,
      );
    }

    if (_view == _AuthView.verifyEmail && _pendingVerificationEmail != null) {
      return VerifyEmailScreen(
        userStore: _userStore,
        email: _pendingVerificationEmail!,
        // Verifying issues a token, so this is a real sign-in, not a hand-off
        // back to the login form.
        onVerified: _login,
        onGoToLogin: () {
          setState(() {
            _view = _AuthView.login;
            _pendingVerificationEmail = null;
          });
        },
      );
    }

    return RegisterScreen(
      userStore: _userStore,
      onRegisterSuccess: _afterRegister,
      onGoToLogin: () {
        setState(() {
          _view = _AuthView.login;
        });
      },
    );
  }
}

class RootShell extends StatefulWidget {
  final ApiService api;
  final UserStore userStore;
  final AppUser user;
  final ValueChanged<AppUser> onUserChanged;
  final VoidCallback onLogout;

  const RootShell({
    super.key,
    required this.api,
    required this.userStore,
    required this.user,
    required this.onUserChanged,
    required this.onLogout,
  });

  @override
  State<RootShell> createState() => _RootShellState();
}

class _RootShellState extends State<RootShell> with WidgetsBindingObserver {
  /// Tabs whose content goes stale on its own, because the dispatcher moves a
  /// request through its statuses server-side. Home shows the active request
  /// card; Track shows the list.
  static const Set<int> _statusTabs = {0, 2};

  /// Long enough not to hammer a rural connection, short enough that a resident
  /// watching for the ambulance sees the change without doing anything. An
  /// interim measure — this is what push notifications are for.
  static const Duration _pollInterval = Duration(seconds: 45);

  /// Arriving on a status tab refetches, but not if the list is this fresh.
  /// Otherwise tapping between Home and Track is a request each way.
  static const Duration _tabRefreshMaxAge = Duration(seconds: 15);

  int _index = 0;
  ServiceType _serviceType = ServiceType.ambulance;
  late final AppState _appState = AppState(widget.api);

  Timer? _poll;
  bool _foreground = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _appState.addListener(_onAppStateChanged);
    // The rows the server last sent, straight off the device. Instant, works
    // with no signal, and gives the fetch below something to replace instead of
    // an empty Track screen saying "No requests yet" during a flood.
    _appState.hydrateRequests();
    _appState.loadRequests();
    // Fetched on launch rather than when the bell is tapped: an advisory is
    // worth having in hand before the resident goes looking for it, and the
    // response is a handful of rows.
    _appState.loadAdvisories();
    _syncPolling();
    // Service names are server-side, so Track and the Home card cannot label
    // themselves in the resident's language until the catalogue is in hand.
    // Waiting for a visit to the Services tab would show English until then.
    _appState.loadServices();
    // Loads the offline index too, so Profile can report what is on the device
    // even if the Library tab is never opened this launch.
    _appState.loadMaterials();
  }

  @override
  void dispose() {
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    _appState.removeListener(_onAppStateChanged);
    super.dispose();
  }

  /// A backgrounded app must not keep polling — it drains a battery a resident
  /// may need for a phone call. Coming back refetches immediately rather than
  /// waiting out the interval, because time spent away is exactly when the
  /// status is most likely to have changed.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final foreground = state == AppLifecycleState.resumed;

    if (foreground && !_foreground) {
      _appState.loadRequests(silent: true);
      // Time spent away is exactly when a blast is most likely to have gone
      // out, and unlike the request poll this does not run on a timer.
      _appState.loadAdvisories();
    }

    _foreground = foreground;
    _syncPolling();
  }

  void _syncPolling() {
    final wanted = _foreground && _statusTabs.contains(_index);

    if (!wanted) {
      _poll?.cancel();
      _poll = null;
      return;
    }

    _poll ??= Timer.periodic(
      _pollInterval,
      // Silent: a poll the resident did not ask for must not stack snackbars
      // over the screen every interval while the signal is out.
      (_) => _appState.loadRequests(silent: true),
    );
  }

  void _onAppStateChanged() {
    setState(() {});

    // Single drain point for store failures, so every screen reports them the
    // same way instead of each one swallowing its own.
    final error = _appState.takeError();
    if (error == null) {
      return;
    }

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        showAppSnackBar(context, error, isError: true);
      }
    });
  }

  /// The screens live in an `IndexedStack`, so switching tabs never remounts
  /// them and an `initState` fetch fires once per launch. Refetching here is
  /// what makes a tab switch mean anything.
  void _goTo(int index) {
    setState(() => _index = index);

    if (_statusTabs.contains(index)) {
      _appState.loadRequests(silent: true, maxAge: _tabRefreshMaxAge);
    }

    _syncPolling();
  }

  void _openService(ServiceType type) {
    setState(() {
      _serviceType = type;
      _index = 1;
    });
    _syncPolling();
  }

  @override
  Widget build(BuildContext context) {
    final onOpenNotifications = () => NotificationsSheet.show(
          context,
          filipino: _appState.language == AppLanguage.filipino,
          // A copy: the sheet must not hold the store's mutable list, which a
          // poll landing behind the sheet would mutate underneath it (M30).
          requests: [..._appState.requests],
          advisories: [..._appState.advisories],
          advisoriesError: _appState.advisoriesError,
        );
    final onOpenProfile = () => _goTo(4);

    final screens = [
      HomeScreen(
        appState: _appState,
        onOpenTrack: () => _goTo(2),
        onOpenLibrary: () => _goTo(3),
        onOpenProfile: onOpenProfile,
        onOpenNotifications: onOpenNotifications,
        onOpenServices: () => _goTo(1),
        onOpenService: _openService,
      ),
      ServicesScreen(
        key: ValueKey(_serviceType),
        appState: _appState,
        user: widget.user,
        initialType: _serviceType,
        onSubmitted: () => _goTo(2),
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
      ),
      TrackScreen(
        appState: _appState,
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
      ),
      LibraryScreen(
        appState: _appState,
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
      ),
      ProfileScreen(
        appState: _appState,
        // The cached rows name this resident's own requests. The next person to
        // use the phone must not open the app onto them.
        onLogout: () {
          _appState.clearRequestCache();
          widget.onLogout();
        },
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
        userStore: widget.userStore,
        user: widget.user,
        onUserChanged: widget.onUserChanged,
      ),
    ];

    return Scaffold(
      extendBody: true,
      body: SafeArea(
        top: false,
        bottom: false,
        child: Column(
          children: [
            // Above every screen, not inside one: being unable to reach MDRRMO
            // is true of the whole app, and the resident must see it wherever
            // they happen to be standing.
            if (_appState.isOffline)
              OfflineBanner(
                filipino: _appState.language == AppLanguage.filipino,
                lastUpdated: _appState.requestsFetchedAt,
              ),
            Expanded(child: IndexedStack(index: _index, children: screens)),
          ],
        ),
      ),
      floatingActionButton: const SosFab(),
      floatingActionButtonLocation: FloatingActionButtonLocation.endFloat,
      bottomNavigationBar: _BottomNav(index: _index, onTap: _goTo),
    );
  }
}

class _BottomNav extends StatelessWidget {
  final int index;
  final ValueChanged<int> onTap;

  const _BottomNav({required this.index, required this.onTap});

  static const _items = [
    (Icons.home_rounded, Icons.home_outlined, 'Home'),
    (Icons.assignment_rounded, Icons.assignment_outlined, 'Services'),
    (Icons.fact_check_rounded, Icons.fact_check_outlined, 'Track'),
    (Icons.menu_book_rounded, Icons.menu_book_outlined, 'Library'),
    (Icons.person_rounded, Icons.person_outline_rounded, 'Profile'),
  ];

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.line)),
      ),
      padding: const EdgeInsets.fromLTRB(8, 10, 8, 18),
      child: SafeArea(
        top: false,
        child: Row(
          children: List.generate(_items.length, (i) {
            final (filled, outline, label) = _items[i];
            final active = i == index;
            return Expanded(
              child: InkWell(
                onTap: () => onTap(i),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(active ? filled : outline, size: 22, color: active ? AppColors.green700 : AppColors.inkFaint),
                    const SizedBox(height: 4),
                    Text(
                      label,
                      style: AppText.display(
                        size: 11,
                        weight: active ? FontWeight.w600 : FontWeight.w500,
                        color: active ? AppColors.green700 : AppColors.inkFaint,
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
        ),
      ),
    );
  }
}
