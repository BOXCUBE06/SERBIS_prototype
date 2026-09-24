<?php

namespace App\Support;

/**
 * The admin panel's sections: one stable key per sidebar item, in sidebar
 * order. Access is granted by key, never by the label, so renaming a page does
 * not move anyone's permissions.
 *
 * The panel keeps its own copy of this list (src/composables/adminSections.ts)
 * for the menu and the router. The server is the one that enforces it.
 */
final class AdminSections
{
    public const DASHBOARD = 'dashboard';

    public const ANALYTICS = 'analytics';

    public const REQUESTS = 'requests';

    public const AMBULANCE = 'ambulance';

    public const BORROWINGS = 'borrowings';

    public const VEHICLES = 'vehicles';

    public const INVENTORY = 'inventory';

    public const PROCUREMENT = 'procurement';

    public const SMS = 'sms';

    public const SERVICES = 'services';

    public const SERVICE_AUDIENCE = 'service_audience';

    public const SERVICE_VEHICLES = 'service_vehicles';

    public const RESIDENTS = 'residents';

    public const STAFF = 'staff';

    public const FILES = 'files';

    public const LOGS = 'logs';

    public const RESPONDERS = 'responders';

    /** Every section, in sidebar order. */
    public const ALL = [
        self::DASHBOARD,
        self::ANALYTICS,
        self::REQUESTS,
        self::AMBULANCE,
        self::BORROWINGS,
        self::VEHICLES,
        self::INVENTORY,
        self::PROCUREMENT,
        self::SMS,
        self::SERVICES,
        self::SERVICE_AUDIENCE,
        self::SERVICE_VEHICLES,
        self::RESIDENTS,
        self::STAFF,
        self::FILES,
        self::LOGS,
        self::RESPONDERS,
    ];

    /**
     * What a super admin may hand to another account. Staff Accounts is not on
     * it: the page creates accounts, resets passwords and edits access, so
     * holding it is what being a super admin means. Handing it to an ordinary
     * admin would let them reset a super admin's password and sign in as one.
     */
    public const ASSIGNABLE = [
        self::DASHBOARD,
        self::ANALYTICS,
        self::REQUESTS,
        self::AMBULANCE,
        self::BORROWINGS,
        self::VEHICLES,
        self::INVENTORY,
        self::PROCUREMENT,
        self::SMS,
        self::SERVICES,
        self::SERVICE_AUDIENCE,
        self::SERVICE_VEHICLES,
        self::RESIDENTS,
        self::FILES,
        self::LOGS,
        self::RESPONDERS,
    ];

    /**
     * The service whose requests belong to the Ambulance section. Every other
     * request, including the "Others" request that has no service row, belongs
     * to Resident Requests. The same literal ServiceRequestController and the
     * panel key off; a fixed service identifier, not config that could drift.
     */
    public const AMBULANCE_SERVICE_CODE = 'ambulance-medical-response';

    /** Which section a service request falls under, decided by its service code. */
    public static function forServiceCode(?string $code): string
    {
        return $code === self::AMBULANCE_SERVICE_CODE ? self::AMBULANCE : self::REQUESTS;
    }

    public static function isAssignable(string $section): bool
    {
        return in_array($section, self::ASSIGNABLE, true);
    }
}
