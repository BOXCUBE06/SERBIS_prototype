<?php

namespace App\Traits;

/**
 * Where uploads go, resolved in one place.
 *
 * `privateDisk()` was written out identically in ResidentController:16 and
 * ServiceRequestController:273, and `publicDisk()` once more in
 * InfoMaterialController:81. Each is a one-line `config()` read, which is
 * exactly why three copies survived — it never looked worth extracting.
 *
 * It is worth extracting for one reason: the private disk is where government
 * ID scans live. If that config key is ever renamed or split, a missed copy
 * does not fail loudly — `config()` returns null, `Storage::disk(null)` falls
 * back to the default disk, and ID photos start being written somewhere nobody
 * intended, with no error. One definition removes that failure mode.
 */
trait ResolvesUploadDisks
{
    /**
     * Government IDs, site photos, resident profile photos. Never reachable
     * by URL — everything here is served through a controller that checks
     * ownership first.
     */
    protected static function privateDisk(): string
    {
        return config('filesystems.uploads.private');
    }

    /**
     * Info materials only: advisories and infographics the MDRRMO publishes
     * and hands out by link.
     */
    protected static function publicDisk(): string
    {
        return config('filesystems.uploads.public');
    }
}
