
library serbis.main;

import 'dart:async';

import 'package:flutter/material.dart';
import 'data/hotlines.dart';
import 'models/request_models.dart';
import 'screens/auth/forgot_password_screen.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/register_screen.dart';
import 'screens/auth/verify_phone_screen.dart';
import 'screens/auth/verify_login_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/library_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/ambulance_screen.dart';
import 'screens/awaiting_approval_screen.dart';
import 'screens/borrow_equipment_screen.dart';
import 'screens/service_drafts.dart';
import 'screens/service_request_form.dart';
import 'screens/services_screen.dart';
import 'screens/track_screen.dart';
import 'screens/unavailable_tab_screen.dart';
import 'state/translations.dart';
import 'state/api_service.dart';
import 'state/app_log.dart';
import 'state/push_messaging.dart';
import 'state/request_store.dart';
import 'state/account_store.dart';
import 'state/hotline_cache.dart';
import 'theme/app_theme.dart';
import 'widgets/app_bottom_nav.dart';
import 'widgets/hotline_list.dart';
import 'widgets/motion.dart';
import 'widgets/offline_banner.dart';
import 'widgets/shared_widgets.dart';

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

  // Deliberately after runApp and deliberately not awaited: registering with
  // FCM takes a network round trip, and nothing on screen depends on it. It
  // handles its own failures — see initPushMessaging.
  unawaited(initPushMessaging());
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
  /// Overridable so a test can restore a session against a fake service
  /// instead of the real network and secure storage.
  final ApiService? api;

  const AuthGate({super.key, this.api});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

enum _AuthView { login, register, verifyPhone, verifyLogin, forgotPassword }

class _AuthGateState extends State<AuthGate> {
  late final ApiService _api = widget.api ?? ApiService();
  late final UserStore _userStore = UserStore(_api);

  bool _ready = false;
  AppUser? _currentUser;
  _AuthView _view = _AuthView.login;
  String? _loginInfoMessage;

  /// The number whose sign-in or registration is waiting on a code. Set by
  /// registering, by a login the server refused as unverified, and by a login
  /// that reached its code prompt. Also seeds the forgot-password screen with
  /// whatever the resident had typed.
  String? _pendingPhone;

  /// Where the code the resident is about to type was sent, and how much of
  /// the resend cooldown is left. Null only if the server did not say.
  VerificationDelivery? _pendingDelivery;

  /// The login MFA challenge id, set when the password was right and a code
  /// is what's left. Opaque — held only to hand to VerifyLoginScreen.
  String? _pendingLoginChallengeId;

  /// For the login screen's hotlines link: built-in list until the cache or a
  /// fetch replaces it. The fetch also warms the cache the Library reads.
  List<Hotline> _hotlines = kHotlines;

  @override
  void initState() {
    super.initState();
    _api.onUnauthorized = _onSessionExpired;
    _restoreSession();
    _loadHotlines();
  }

  Future<void> _loadHotlines() async {
    final cache = HotlineCache();
    final cached = await cache.load();
    if (cached != null && mounted) setState(() => _hotlines = cached);
    final fresh = await cache.refresh(_api.getHotlines);
    if (fresh != null && mounted) setState(() => _hotlines = fresh);
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
        unawaited(registerDeviceToken(_api));
        listenForTokenRefresh(_api);
        return;
      } on ApiException catch (e) {
        // Before `mounted` is checked: a failure that happens as the widget is
        // going away is still a failure, and returning early would drop it.
        AppLog.warn('session', 'restore from stored token',
            reason: e.isUnauthorized ? 'token rejected' : 'server unreachable');

        // isNetwork, not merely !isUnauthorized: ApiService._send() wraps
        // every transport failure (no signal, DNS, a timeout, the server
        // unreachable) into an ApiException with no status code, so this
        // catches exactly those — and nothing else. A real error response
        // (500, say) still carries a status: the server answered, which is a
        // different fact than "lost signal", and is not this app's cue to
        // boot a resident into a profile that may already be stale.
        if (e.isNetwork) {
          final cached = await _userStore.cachedUser();
          if (cached != null) {
            if (!mounted) {
              return;
            }
            setState(() {
              _currentUser = cached;
              _ready = true;
            });
            unawaited(registerDeviceToken(_api));
            listenForTokenRefresh(_api);
            return;
          }
        }

        if (!mounted) {
          return;
        }

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
    unawaited(registerDeviceToken(_api));
    listenForTokenRefresh(_api);
  }

  /// Registration now ends at the code screen rather than at the login form:
  /// the account is not usable until the texted code comes back, and verifying
  /// issues a token, so a resident who finishes never sees a login screen at
  /// all on their first run.
  void _afterRegister(String phone, VerificationDelivery? delivery) {
    setState(() {
      _view = _AuthView.verifyPhone;
      _pendingPhone = phone;
      _pendingDelivery = delivery;
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
        onPhoneUnverified: (phone, delivery) {
          setState(() {
            _view = _AuthView.verifyPhone;
            _pendingPhone = phone;
            _pendingDelivery = delivery;
            _loginInfoMessage = null;
          });
        },
        onMfaRequired: (phone, challengeId, delivery) {
          setState(() {
            _view = _AuthView.verifyLogin;
            _pendingPhone = phone;
            _pendingLoginChallengeId = challengeId;
            _pendingDelivery = delivery;
            _loginInfoMessage = null;
          });
        },
        onForgotPassword: (phone) {
          setState(() {
            _view = _AuthView.forgotPassword;
            _pendingPhone = phone;
            _loginInfoMessage = null;
          });
        },
        infoMessage: _loginInfoMessage,
        onOpenHotlines: () => Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => HotlinesPage(hotlines: _hotlines),
        )),
      );
    }

    if (_view == _AuthView.forgotPassword) {
      return ForgotPasswordScreen(
        userStore: _userStore,
        initialPhone: _pendingPhone,
        onGoToLogin: () {
          setState(() {
            _view = _AuthView.login;
          });
        },
        // Not signed in: a reset ends every session, so the resident goes back
        // to the login form, told what happened, and signs in as usual.
        onReset: (message) {
          setState(() {
            _view = _AuthView.login;
            _loginInfoMessage = message;
          });
        },
      );
    }

    if (_view == _AuthView.verifyPhone && _pendingPhone != null) {
      return VerifyPhoneScreen(
        userStore: _userStore,
        phone: _pendingPhone!,
        delivery: _pendingDelivery,
        // Verifying issues a token, so this is a real sign-in, not a hand-off
        // back to the login form.
        onVerified: _login,
        onGoToLogin: () {
          setState(() {
            _view = _AuthView.login;
            _pendingPhone = null;
          });
        },
      );
    }

    if (_view == _AuthView.verifyLogin &&
        _pendingPhone != null &&
        _pendingLoginChallengeId != null) {
      return VerifyLoginScreen(
        userStore: _userStore,
        phone: _pendingPhone!,
        challengeId: _pendingLoginChallengeId!,
        delivery: _pendingDelivery,
        onVerified: _login,
        onGoToLogin: () {
          setState(() {
            _view = _AuthView.login;
            _pendingPhone = null;
            _pendingLoginChallengeId = null;
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
  static const int _homeTab = 0;
  static const int _ambulanceTab = 1;
  static const int _servicesTab = 2;
  static const int _borrowTab = 3;
  static const int _trackTab = 4;

  /// Tabs whose content goes stale on its own, because the dispatcher moves a
  /// request through its statuses server-side. Home shows the active request
  /// card; Track shows the list.
  static const Set<int> _statusTabs = {_homeTab, _trackTab};

  /// Long enough not to hammer a rural connection, short enough that a resident
  /// watching for the ambulance sees the change without doing anything. An
  /// interim measure — this is what push notifications are for.
  static const Duration _pollInterval = Duration(seconds: 45);

  /// Arriving on a status tab refetches, but not if the list is this fresh.
  /// Otherwise tapping between Home and Track is a request each way.
  static const Duration _tabRefreshMaxAge = Duration(seconds: 15);

  int _index = _homeTab;
  late final AppState _appState = AppState(widget.api);

  /// Profile and the Library open as pages above the tabs. A page is built once
  /// when it is pushed, so it reads the signed-in resident from here to stay in
  /// step with an edit made on that same page.
  late final ValueNotifier<AppUser> _user = ValueNotifier(widget.user);
  bool _profileOpen = false;

  /// What the resident has typed into the service forms, kept for the session so
  /// leaving a form page (or the Ambulance tab) and coming back loses nothing.
  late final ServiceDrafts _drafts = ServiceDrafts(widget.user);

  /// The ambulance flow, so Android back can step it back instead of leaving.
  final _ambulanceForm = GlobalKey<ServiceRequestFormState>();

  /// The Ambulance tab is showing its flow (not a loading or unavailable
  /// screen), which takes the whole screen: no bottom nav.
  bool get _ambulanceFlowShowing =>
      _index == _ambulanceTab &&
      !widget.user.isAwaitingApproval &&
      _appState.services.any((s) => s.formKind == ServiceFormKind.ambulance);

  Timer? _poll;
  bool _foreground = true;
  bool _showingOffline = false;

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
    // Cached list first, then the server; the built-in list until either lands.
    _appState.loadHotlines();
  }

  @override
  void didUpdateWidget(covariant RootShell old) {
    super.didUpdateWidget(old);
    // After the frame: a page pushed above the tabs listens to this, and it is
    // not a descendant of the shell that is building right now.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _user.value = widget.user;
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    _appState.removeListener(_onAppStateChanged);
    _user.dispose();
    _drafts.dispose();
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

  /// Screens rebuild through their own [_TabSlot]; the shell itself only has
  /// the offline banner to redraw, so it rebuilds only when that flips.
  void _onAppStateChanged() {
    if (_appState.isOffline != _showingOffline) {
      setState(() => _showingOffline = _appState.isOffline);
    }

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

    // The Borrow tab keeps its screen alive between visits, so a loan MDRRMO
    // approved while the resident was elsewhere shows up on arrival.
    if (index == _borrowTab &&
        !widget.user.isAwaitingApproval &&
        _appState.borrowingAllowed) {
      _appState.loadBorrowRequests(silent: true, maxAge: _tabRefreshMaxAge);
    }

    _syncPolling();
  }

  /// Home's shortcut tiles. Ambulance and hospital transfer have a tab of their
  /// own; every other service starts from the Services grid.
  void _openService(ServiceType type) {
    _goTo(switch (type) {
      ServiceType.ambulance || ServiceType.transfer => _ambulanceTab,
      _ => _servicesTab,
    });
  }

  void _openProfilePage() {
    if (_profileOpen) return;
    _profileOpen = true;

    Navigator.of(context)
        .push(serbisRoute<void>(
          (routeContext) => Scaffold(
            body: ListenableBuilder(
              listenable: Listenable.merge([_appState, _user]),
              builder: (_, __) => ProfileScreen(
                appState: _appState,
                // The cached rows name this resident's own requests. The next
                // person to use the phone must not open the app onto them.
                onLogout: () {
                  Navigator.of(routeContext).pop();
                  _appState.clearRequestCache();
                  _appState.clearBorrowCache();
                  widget.onLogout();
                },
                onOpenNotifications: _openNotifications,
                // Already here.
                onOpenProfile: () {},
                onBack: () => Navigator.of(routeContext).pop(),
                userStore: widget.userStore,
                user: _user.value,
                onUserChanged: widget.onUserChanged,
              ),
            ),
          ),
        ))
        .whenComplete(() => _profileOpen = false);
  }

  void _openLibraryPage() {
    Navigator.of(context).push(serbisRoute<void>(
      (routeContext) => Scaffold(
        body: ListenableBuilder(
          listenable: _appState,
          builder: (_, __) => LibraryScreen(
            appState: _appState,
            onOpenNotifications: _openNotifications,
            onOpenProfile: _openProfilePage,
            onBack: () => Navigator.of(routeContext).pop(),
          ),
        ),
      ),
    ));
  }

  void _openNotifications() => NotificationsSheet.show(
        context,
        filipino: _appState.language == AppLanguage.filipino,
        // A copy: the sheet must not hold the store's mutable list, which a
        // poll landing behind the sheet would mutate underneath it (M30).
        requests: [..._appState.requests],
        advisories: [..._appState.advisories],
        advisoriesError: _appState.advisoriesError,
      );

  @override
  Widget build(BuildContext context) {
    final onOpenNotifications = _openNotifications;
    final onOpenProfile = _openProfilePage;

    Widget slot(int index, WidgetBuilder builder, {Object? deps}) => _TabSlot(
          active: _index == index,
          listenable: _appState,
          deps: deps,
          builder: builder,
        );

    // An organization MDRRMO has not activated yet cannot file anything, so the
    // three tabs that file things give it the reason instead of a form it could
    // not use.
    Widget awaitingApproval() => AwaitingApprovalScreen(
          user: widget.user,
          filipino: _appState.language == AppLanguage.filipino,
          onOpenNotifications: onOpenNotifications,
          onOpenProfile: onOpenProfile,
          // Reloads the profile; the shell rebuilds with the real screens the
          // moment the account is Active.
          onCheckAgain: () async {
            final fresh = await widget.userStore.currentUser();
            widget.onUserChanged(fresh);
            return fresh.isAwaitingApproval;
          },
        );

    final screens = [
      slot(
          _homeTab,
          (_) => HomeScreen(
                appState: _appState,
                user: widget.user,
                onOpenTrack: () => _goTo(_trackTab),
                onOpenLibrary: _openLibraryPage,
                onOpenProfile: onOpenProfile,
                onOpenNotifications: onOpenNotifications,
                onOpenServices: () => _goTo(_servicesTab),
                onOpenService: _openService,
                onOpenBorrow: () => _goTo(_borrowTab),
              ),
          deps: widget.user),
      slot(
          _ambulanceTab,
          (_) => widget.user.isAwaitingApproval
              ? awaitingApproval()
              : AmbulanceScreen(
                  formKey: _ambulanceForm,
                  appState: _appState,
                  user: widget.user,
                  drafts: _drafts,
                  onSubmitted: () => _goTo(_trackTab),
                  onOpenNotifications: onOpenNotifications,
                  onOpenProfile: onOpenProfile,
                  onOpenLibrary: _openLibraryPage,
                  onBack: () => _goTo(_homeTab),
                ),
          deps: widget.user),
      slot(
          _servicesTab,
          (_) => widget.user.isAwaitingApproval
              ? awaitingApproval()
              : ServicesScreen(
                  appState: _appState,
                  user: widget.user,
                  drafts: _drafts,
                  onSubmitted: () => _goTo(_trackTab),
                  onOpenNotifications: onOpenNotifications,
                  onOpenProfile: onOpenProfile,
                ),
          deps: widget.user),
      slot(_borrowTab, (_) {
        final f = _appState.language == AppLanguage.filipino;
        if (widget.user.isAwaitingApproval) return awaitingApproval();
        if (!_appState.borrowingAllowed) {
          return UnavailableTabScreen(
            icon: Icons.inventory_2_outlined,
            title: tr(f, 'nav.borrow'),
            message: tr(f, 'tab.borrow_unavailable'),
            filipino: f,
            onOpenNotifications: onOpenNotifications,
            onOpenProfile: onOpenProfile,
          );
        }
        return BorrowEquipmentScreen(
          appState: _appState,
          user: widget.user,
          embedded: true,
          onOpenNotifications: onOpenNotifications,
          onOpenProfile: onOpenProfile,
        );
      }, deps: widget.user),
      slot(
          _trackTab,
          (_) => TrackScreen(
                appState: _appState,
                onOpenNotifications: onOpenNotifications,
                onOpenProfile: onOpenProfile,
              )),
    ];

    // Back from any other tab returns to Home before it leaves the app, as the
    // Material navigation guidance asks. A page pushed above the tabs (Profile,
    // a service form) takes the back press first, so this only sees it when the
    // tabs are showing. The ambulance flow steps back first.
    return PopScope(
      canPop: _index == _homeTab,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        final flow = _index == _ambulanceTab ? _ambulanceForm.currentState : null;
        flow != null ? flow.handleBack() : _goTo(_homeTab);
      },
      child: Scaffold(
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
              Expanded(child: TabFade(index: _index, children: screens)),
            ],
          ),
        ),
        // Its own listener: the shell does not rebuild on a language change, and
        // the labels are words.
        bottomNavigationBar: ListenableBuilder(
          listenable: _appState,
          builder: (_, __) => _ambulanceFlowShowing
              ? const SizedBox.shrink()
              : AppBottomNav(
                  index: _index,
                  onTap: _goTo,
                  filipino: _appState.language == AppLanguage.filipino,
                ),
        ),
      ),
    );
  }
}

/// One tab of the shell's IndexedStack, rebuilt only when its inputs change
/// and only while it is showing.
///
/// The shell used to setState on every AppState notification and every tab
/// switch, rebuilding all five screens — and on web, re-shaping every line of
/// their text. An unchanged tab now returns its last widget (an identical
/// instance, so Flutter skips the subtree); a tab whose data changed while it
/// was off screen rebuilds when it is next shown. [deps] names the shell
/// values a screen reads besides AppState; the callbacks it is handed are
/// stable in meaning, so they are not inputs.
class _TabSlot extends StatefulWidget {
  final bool active;
  final Listenable listenable;
  final Object? deps;
  final WidgetBuilder builder;

  const _TabSlot({
    required this.active,
    required this.listenable,
    required this.builder,
    this.deps,
  });

  @override
  State<_TabSlot> createState() => _TabSlotState();
}

class _TabSlotState extends State<_TabSlot> {
  Widget? _built;
  bool _stale = true;

  @override
  void initState() {
    super.initState();
    widget.listenable.addListener(_onChanged);
  }

  @override
  void didUpdateWidget(covariant _TabSlot old) {
    super.didUpdateWidget(old);
    if (old.listenable != widget.listenable) {
      old.listenable.removeListener(_onChanged);
      widget.listenable.addListener(_onChanged);
    }
    if (old.deps != widget.deps) {
      _stale = true;
    }
  }

  @override
  void dispose() {
    widget.listenable.removeListener(_onChanged);
    super.dispose();
  }

  void _onChanged() {
    _stale = true;
    if (widget.active && mounted) {
      setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    // Not built until first shown: a tab nobody opens costs no fetches.
    if (_built == null && !widget.active) {
      return const SizedBox.shrink();
    }
    if (_built == null || (_stale && widget.active)) {
      _built = widget.builder(context);
      _stale = false;
    }
    return _built!;
  }
}
