{{--
    MDRRMO Conduction Request Form — printable letterhead.

    The letterhead is type only. An earlier draft reserved two 28mm boxes for
    seal images that were never produced, which printed as empty space either
    side of the heading. The boxes are gone rather than left waiting: the type
    is centred on the page by text-align, so it sits in the same place it
    always did and now has the full measure to wrap in.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Conduction Request Form</title>
    <style>
        /* A4 rather than Letter: the office prints on A4, and a fixed page
           size keeps every millimetre dimension in this sheet true instead of
           scaling with the viewport. */
        @page {
            size: A4;
            margin: 18mm 16mm;
        }

        html {
            /* Screen preview only. @page owns the real printed margins; this
               is what makes the white sheet read as a sheet on screen. */
            background: #f4f4f5;
        }

        body {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 16mm;
            box-sizing: border-box;
            background: #fff;
            /* A serif stack, no webfont: this prints on machines with no
               network, and a missing webfont falls back silently to something
               that is not a government letterhead. */
            font-family: "Times New Roman", Times, "Liberation Serif", serif;
            color: #000;
            /* 12pt base, the size the paper form is typed at. */
            font-size: 12pt;
            line-height: 1.35;
        }

        .titles {
            text-align: center;
        }

        .titles p {
            margin: 0;
        }

        .republic,
        .province {
            font-size: 12pt;
        }

        .municipality {
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        /* Underlined, as on the paper form. text-decoration rather than a
           border-bottom so the rule tracks the text's own width when the
           name wraps at a narrower page. */
        .unit {
            font-size: 12pt;
            font-style: italic;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .form-title {
            margin-top: 6mm !important;
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 0.08em;
        }

        /* ---- body -------------------------------------------------------
           11pt, not the 12pt letterhead: the whole record plus the footer has
           to land on one sheet, and this is the smallest step that does it
           while staying comfortably readable in print. Anything under 10pt
           starts to fail for the people signing it. */
        .body {
            margin-top: 5mm;
            font-size: 11pt;
            line-height: 1.3;
        }

        .row {
            display: flex;
            gap: 5mm;
            margin-bottom: 2.4mm;
        }

        .row > * {
            flex: 1;
        }

        /* A label sitting on the same baseline as the rule it introduces. The
           rule is the writable space: it is drawn whether or not there is a
           value on it, because a field with no rule is a field nobody can
           complete by hand. */
        .field {
            display: flex;
            align-items: baseline;
            gap: 2mm;
        }

        .field .label {
            flex: none;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .field .rule {
            flex: 1;
            min-width: 0;
            border-bottom: 0.4pt solid #000;
            /* Holds the line open at a writable height when the value is
               empty. Without it an unfilled rule collapses to the text
               baseline and there is nowhere to write. */
            min-height: 4.6mm;
            padding: 0 1mm;
            /* A long diagnosis wraps rather than pushing the rule off the
               page; the row grows and the sheet still holds. */
            overflow-wrap: anywhere;
        }

        .w-narrow { flex: 0 0 22mm; }
        .w-half   { flex: 1; }

        .group {
            margin-bottom: 2.4mm;
        }

        .group > .group-label {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 1.2mm;
        }

        /* Names go in two columns, so the two blank rows every list is
           guaranteed cost one line rather than two. */
        .names {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 5mm;
            row-gap: 2.4mm;
        }

        .name-rule {
            border-bottom: 0.4pt solid #000;
            min-height: 4.6mm;
            padding: 0 1mm;
        }

        .others .rule {
            min-height: 7.5mm;
        }

        /* ---- footer ------------------------------------------------------ */
        .footer {
            margin-top: 4mm;
            font-size: 10.5pt;
            line-height: 1.3;
        }

        .footer .heading {
            font-weight: 700;
            margin: 0;
        }

        .undertaking {
            margin: 1.5mm 0 0;
            text-align: justify;
        }

        .requester {
            margin-top: 4.5mm;
            display: flex;
            gap: 8mm;
            align-items: flex-end;
        }

        .sign-slot {
            flex: 1;
        }

        /* The line people actually sign on. Tall enough for a wet signature,
           which is the only kind this form takes. */
        .sign-line {
            border-bottom: 0.4pt solid #000;
            height: 8mm;
        }

        .sign-caption {
            font-size: 8.5pt;
            text-align: center;
            margin-top: 1mm;
        }

        .datetime-slot {
            flex: 0 0 55mm;
        }

        .approvals {
            margin-top: 4.5mm;
        }

        .approval-label {
            font-size: 10.5pt;
            margin: 0 0 3.5mm;
        }

        .signatories {
            display: flex;
            gap: 10mm;
        }

        .signatory {
            flex: 1;
            text-align: center;
        }

        .signatory .name {
            font-weight: 700;
            text-transform: uppercase;
            border-top: 0.4pt solid #000;
            padding-top: 1mm;
            font-size: 10.5pt;
        }

        .signatory .role {
            font-size: 8.5pt;
        }

        /* Keeps the undertaking and the signatures together: a footer split
           across a page break is a form nobody can sign. */
        .footer {
            page-break-inside: avoid;
        }

        @media print {
            html {
                background: none;
            }

            body {
                width: auto;
                min-height: 0;
                margin: 0;
                /* @page already applies the margins; repeating them here
                   would indent the content a second time inside them. */
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="titles">
            <p class="republic">Republic of the Philippines</p>
            <p class="province">Province of Isabela</p>
            <p class="municipality">MUNICIPALITY OF ECHAGUE</p>
            <p class="unit">Echague Rescue Emergency Medical Services</p>
            <p class="form-title">CONDUCTION REQUEST FORM</p>
        </div>
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
        $patientContact = $val($booking?->patient_contact_number ?: $trip->patient_contact_number);
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

        /**
         * Pads a name list out to a minimum number of rows. Staff add names by
         * hand on the printed sheet, so a list shorter than the minimum is
         * given ruled space rather than ending where the data ends.
         */
        $rows = static function (array $names, int $minimum = 2): array {
            return array_pad($names, max($minimum, count($names)), '');
        };
    @endphp

    <section class="body">
        <div class="row">
            <div class="field">
                <span class="label">Patient Name</span>
                <span class="rule">{{ $patientName }}</span>
            </div>
            <div class="field w-narrow">
                <span class="label">Age</span>
                <span class="rule">{{ $patientAge }}</span>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">Patient Address</span>
                <span class="rule">{{ $patientAddress }}</span>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">Contact Number</span>
                <span class="rule">{{ $patientContact }}</span>
            </div>
            <div class="field" style="flex: 2;">
                <span class="label">Medical Diagnosis</span>
                <span class="rule">{{ $diagnosis }}</span>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">From</span>
                <span class="rule">{{ $from }}</span>
            </div>
            <div class="field">
                <span class="label">To</span>
                <span class="rule">{{ $to }}</span>
            </div>
        </div>

        <div class="group">
            <div class="group-label">Relatives of the Patient</div>
            <div class="names">
                @foreach ($rows($relatives) as $name)
                    <div class="name-rule">{{ $name }}</div>
                @endforeach
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">Date and Time</span>
                <span class="rule">{{ $scheduled }}</span>
            </div>
        </div>

        <div class="group">
            <div class="group-label">Driver's Name/s</div>
            <div class="names">
                @foreach ($rows($drivers) as $name)
                    <div class="name-rule">{{ $name }}</div>
                @endforeach
            </div>
        </div>

        <div class="group">
            <div class="group-label">Name of Authorized Passenger/s</div>
            <div class="names">
                @foreach ($rows($passengers) as $name)
                    <div class="name-rule">{{ $name }}</div>
                @endforeach
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">Departure from the Office</span>
                <span class="rule">{{ $when($trip->departed_office_at) }}</span>
            </div>
            <div class="field">
                <span class="label">Arrival at Destination</span>
                <span class="rule">{{ $when($trip->arrived_destination_at) }}</span>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">Departure from Destination</span>
                <span class="rule">{{ $when($trip->departed_destination_at) }}</span>
            </div>
            <div class="field">
                <span class="label">Arrival back to Office</span>
                <span class="rule">{{ $when($trip->returned_office_at) }}</span>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <span class="label">Meter Reading before Departure</span>
                <span class="rule">{{ $val($trip->odometer_start) }}</span>
            </div>
            <div class="field">
                <span class="label">Meter Reading at Arrival</span>
                <span class="rule">{{ $val($trip->odometer_end) }}</span>
            </div>
        </div>

        <div class="row others">
            <div class="field">
                <span class="label">Others</span>
                <span class="rule">{{ $val($trip->others) }}</span>
            </div>
        </div>
    </section>

    <footer class="footer">
        <p class="heading">PARA SA PARTIDONG NAGREQUEST:</p>
        <p class="heading">PAGPAPATUNAY:</p>
        <p class="undertaking">
            &ldquo;Naipaliwanag sa akin ng mabuti ang mga kondisyon sa pagre-request ng
            pagdadala, paglilipat o paghahatid ng pasyente at akin itong
            naiintindihan&rdquo;.
        </p>

        <div class="requester">
            <div class="sign-slot">
                <div class="sign-line"></div>
                <div class="sign-caption">Pangalan at lagda ng nag-request</div>
            </div>
            <div class="datetime-slot field">
                <span class="label">Petsa/oras:</span>
                <span class="rule"></span>
            </div>
        </div>

        <div class="approvals">
            <p class="approval-label">Noted by:</p>
            <div class="signatories">
                <div class="signatory">
                    <div class="name">Cyrus A. Angoluan, RN</div>
                    <div class="role">Operations and Warning Officer</div>
                </div>
                <div class="signatory"></div>
            </div>
        </div>

        <div class="approvals">
            <p class="approval-label">Approved by:</p>
            <div class="signatories">
                <div class="signatory">
                    <div class="name">Melissa G. Corpuz, RSW</div>
                    <div class="role">Department Head, MDRRMO</div>
                </div>
                <div class="signatory">
                    <div class="name">Carmelo Lou B. Lim</div>
                    <div class="role">Rescue Chief</div>
                </div>
            </div>
        </div>
    </footer>
</body>
<script>window.print()</script>
</html>
