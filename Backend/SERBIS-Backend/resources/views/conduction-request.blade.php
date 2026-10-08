{{--
    MDRRMO Conduction Request Form — printable, A4 portrait, one page.

    Laid out as label/value grids like the office's paper form. Only fields the
    system already records are printed; nothing is added for layout's sake.

    Logos live in the admin panel's public/logos/ and are loaded from the
    page's own origin: the panel opens this HTML as a blob, which inherits the
    panel's origin. Printing waits until each logo loads or fails.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Conduction Request Form</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }

        * { box-sizing: border-box; }

        html {
            background: #fff;
            /* Gray label cells must print, not be dropped as "background". */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            width: 186mm; /* A4 width minus the @page margins */
            margin: 0 auto;
            font-family: "Times New Roman", Times, "Liberation Serif", serif;
            font-size: 10pt;
            line-height: 1.25;
            color: #000;
            background: #fff;
        }

        /* ---- header ---- */
        .letterhead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4mm;
        }

        .logos { display: flex; gap: 2mm; flex: 0 0 44mm; }
        .logos.right { justify-content: flex-end; }
        .logos img { height: 20mm; width: auto; }

        .titles { flex: 1; text-align: center; }
        .titles p { margin: 0; }
        .titles .lgu { font-size: 11pt; }
        .titles .municipality { font-size: 12pt; font-weight: bold; text-transform: uppercase; }

        .unit {
            margin: 2mm 0 0;
            text-align: center;
            font-size: 11pt;
            font-style: italic;
            text-decoration: underline;
            color: #1f4e9c;
        }

        .form-title {
            margin: 3mm 0;
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        /* ---- label/value grids ---- */
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 3mm; /* the gap between blocks */
        }

        th, td {
            border: 1px solid #000;
            padding: 1.2mm 1.6mm;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        th {
            background: #bfbfbf;
            font-weight: bold;
            text-align: right;
        }

        td { background: #fff; height: 6.5mm; }

        /* Column headers of the names block read left to right. */
        .names th { text-align: center; }
        .names .num { width: 8mm; text-align: center; }

        .others td { height: 14mm; }

        /* ---- signatures ---- */
        .footer { margin-top: 2mm; page-break-inside: avoid; }
        .footer p { margin: 0; }
        .footer .heading { font-weight: bold; }
        .undertaking { margin-top: 1mm !important; text-align: justify; }

        .signatures {
            display: flex;
            gap: 5mm;
            margin-top: 6mm;
        }

        .sign { flex: 1; text-align: center; font-size: 9pt; }
        .sign .caption { text-align: left; margin-bottom: 9mm !important; }
        .sign .line { border-top: 1px solid #000; padding-top: 1mm; }
        .sign .name { font-weight: bold; text-transform: uppercase; }
    </style>
</head>
<body>
    <header>
        <div class="letterhead">
            <div class="logos">
                <img data-logo="bagong-pilipinas.png" alt="Bagong Pilipinas">
                <img data-logo="echague-seal.png" alt="Municipality of Echague seal">
            </div>
            <div class="titles">
                <p class="lgu">Republic of the Philippines</p>
                <p class="lgu">Province of Isabela</p>
                <p class="municipality">Municipality of Echague</p>
            </div>
            <div class="logos right">
                <img data-logo="echague-rescue.png" alt="Echague Rescue">
            </div>
        </div>
        <p class="unit">Echague Rescue Emergency Medical Services</p>
        <p class="form-title">Conduction Request Form</p>
    </header>

    @php
        /**
         * Expects $trip, an App\Models\ConductionRequest, ideally loaded with
         * ['people', 'serviceRequest.relatives', 'serviceRequest.ambulanceBooking'].
         *
         * Field sources, in the order the form asks for them:
         *   patient block, From/To, scheduled date  tbl_ambulance_bookings
         *   relatives                                tbl_service_request_relatives
         *   drivers / authorized passengers          tbl_conduction_request_people
         *   timestamps, odometers, others            tbl_conduction_requests
         *
         * The booking is preferred and the trip's own columns are the
         * fallback, because a trip filed at the counter with no prior booking
         * has no service request at all — tbl_conduction_requests carries its
         * own NOT NULL copies of the patient block precisely for that case.
         */
        $request = $trip->serviceRequest;
        $booking = $request?->ambulanceBooking;

        /** Blank, never "N/A" — an empty rule is the space someone writes in. */
        $val = static fn ($value) => filled($value) ? $value : '';

        /** Office wall clock. The columns hold UTC instants. */
        $when = static function ($instant) {
            return $instant
                ? \Illuminate\Support\Carbon::parse($instant)
                    ->timezone('Asia/Manila')->format('M j, Y  g:i A')
                : '';
        };

        $patientName    = $val($booking?->patient_name    ?: $trip->patient_name);
        $patientAge     = $val($booking?->patient_age     ?: $trip->patient_age);
        $patientAddress = $val($booking?->patient_address ?: $trip->patient_address);
        $patientContact = \App\Support\PhoneNumber::display((string) $val($booking?->patient_contact_number ?: $trip->patient_contact_number));
        $diagnosis      = $val($booking?->condition_notes ?: $trip->medical_diagnosis);
        $from           = $val($booking?->pickup_location ?: $trip->origin);
        $to             = $val($booking?->destination     ?: $trip->destination);
        $scheduled      = $when($booking?->scheduled_at);

        /**
         * Relatives named at intake. createConductionStub copies these into
         * tbl_conduction_request_people as role='relative', so reading the
         * request first and only falling through to the trip's own rows keeps
         * a bridged trip from printing every name twice — while a manually
         * filed trip, whose relatives were typed into the trip dialog and
         * never existed on a request, still prints.
         */
        $relatives = $request?->relatives->pluck('name')->all() ?: [];
        if (! $relatives) {
            $relatives = $trip->people->where('role', 'relative')->pluck('name')->values()->all();
        }

        $drivers    = $trip->people->where('role', 'driver')->pluck('name')->values()->all();
        $passengers = $trip->people->where('role', 'passenger')->pluck('name')->values()->all();
    @endphp

    @php
        // One numbered row per name, at least two, so staff can add by hand.
        $nameRows = max(2, count($drivers), count($passengers), count($relatives));
    @endphp

    {{-- Block 1: patient and trip --}}
    <table>
        <colgroup>
            <col style="width: 22%"><col style="width: 38%"><col style="width: 16%"><col style="width: 24%">
        </colgroup>
        <tr>
            <th>Patient Name</th><td>{{ $patientName }}</td>
            <th>Age</th><td>{{ $patientAge }}</td>
        </tr>
        <tr>
            <th>Contact Number</th><td>{{ $patientContact }}</td>
            <th>Date and Time</th><td>{{ $scheduled }}</td>
        </tr>
        <tr><th>Patient Address</th><td colspan="3">{{ $patientAddress }}</td></tr>
        <tr><th>Medical Diagnosis</th><td colspan="3">{{ $diagnosis }}</td></tr>
        <tr><th>From</th><td colspan="3">{{ $from }}</td></tr>
        <tr><th>To</th><td colspan="3">{{ $to }}</td></tr>
    </table>

    {{-- Block 2: people, numbered --}}
    <table class="names">
        <tr>
            <th class="num">#</th>
            <th>Driver's Name/s</th>
            <th>Name of Authorized Passenger/s</th>
            <th>Relatives of the Patient</th>
        </tr>
        @for ($i = 0; $i < $nameRows; $i++)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $drivers[$i] ?? '' }}</td>
                <td>{{ $passengers[$i] ?? '' }}</td>
                <td>{{ $relatives[$i] ?? '' }}</td>
            </tr>
        @endfor
    </table>

    {{-- Block 3: trip log --}}
    <table>
        <colgroup><col style="width: 45%"><col style="width: 55%"></colgroup>
        <tr><th>Departure from the Office</th><td>{{ $when($trip->departed_office_at) }}</td></tr>
        <tr><th>Arrival at Destination</th><td>{{ $when($trip->arrived_destination_at) }}</td></tr>
        <tr><th>Departure from Destination</th><td>{{ $when($trip->departed_destination_at) }}</td></tr>
        <tr><th>Arrival back to Office</th><td>{{ $when($trip->returned_office_at) }}</td></tr>
        <tr><th>Meter Reading before Departure</th><td>{{ $val($trip->odometer_start) }}</td></tr>
        <tr><th>Meter Reading at Arrival</th><td>{{ $val($trip->odometer_end) }}</td></tr>
        <tr class="others"><th>Others</th><td>{{ $val($trip->others) }}</td></tr>
    </table>

    {{-- The sign-off this form has always carried, as one row of blocks. --}}
    <footer class="footer">
        <p class="heading">PARA SA PARTIDONG NAGREQUEST:</p>
        <p class="heading">PAGPAPATUNAY:</p>
        <p class="undertaking">
            &ldquo;Naipaliwanag sa akin ng mabuti ang mga kondisyon sa pagre-request ng
            pagdadala, paglilipat o paghahatid ng pasyente at akin itong
            naiintindihan&rdquo;.
        </p>

        <div class="signatures">
            <div class="sign">
                <p class="caption">&nbsp;</p>
                <div class="line">Pangalan at lagda ng nag-request</div>
                <div>Petsa/oras: ____________</div>
            </div>
            <div class="sign">
                <p class="caption">Noted by:</p>
                <div class="line name">Cyrus A. Angoluan, RN</div>
                <div>Operations and Warning Officer</div>
            </div>
            <div class="sign">
                <p class="caption">Approved by:</p>
                <div class="line name">Melissa G. Corpuz, RSW</div>
                <div>Department Head, MDRRMO</div>
            </div>
            <div class="sign">
                <p class="caption">&nbsp;</p>
                <div class="line name">Carmelo Lou B. Lim</div>
                <div>Rescue Chief</div>
            </div>
        </div>
    </footer>

    <script>
        // Absolute logo URLs from this page's origin, then print once every
        // logo has loaded or failed. A missing file is hidden, not shown broken.
        (function () {
            var imgs = Array.prototype.slice.call(document.querySelectorAll('img[data-logo]'));
            var base = location.origin && location.origin !== 'null' ? location.origin : '';
            var waits = imgs.map(function (img) {
                return new Promise(function (done) {
                    img.onload = done;
                    img.onerror = function () { img.style.display = 'none'; done(); };
                    img.src = base + '/logos/' + img.dataset.logo;
                });
            });
            Promise.all(waits).then(function () { window.print(); });
        })();
    </script>
</body>
</html>
