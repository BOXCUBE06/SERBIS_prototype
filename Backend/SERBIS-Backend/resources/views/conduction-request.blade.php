{{--
    MDRRMO Conduction Request Form — printable letterhead.

    SEAL ASSETS — not committed yet. The two slots below are already sized and
    positioned; dropping the images in changes nothing about where the text
    sits, because the side columns are fixed-width and the centre column is
    what the type is centred in.

        public/img/seal-echague.png         → LEFT slot
            The municipal seal ("Bayan ng Echague, Isabela"), pairing with the
            MUNICIPALITY OF ECHAGUE line it sits beside.

        public/img/logo-echague-rescue.png  → RIGHT slot
            The issuing unit's own logo, pairing with the Echague Rescue EMS
            line.

        public/img/seal-philippines.png     → not placed
            Kept in the naming scheme because the national seal is the usual
            third mark on an LGU letterhead. Some offices run national left /
            municipal right and move the unit logo into the body. If that is
            wanted here, swap the LEFT slot's filename and give the unit logo
            its own row rather than widening this one — three marks across a
            single band crowds the type at this width.

    Already in the repo and possibly the same artwork:
    Web/serbis-admin-vue/src/assets/mdrrmo_logo.jpg is the MDRRMO Echague seal,
    carrying the municipal coat-of-arms as its own background. It is a JPEG
    with a white ground, so it needs a transparent PNG export before it sits
    cleanly on the page.

    To fill a slot, put the <img> inside the existing .seal div and give it
    width:100%;height:100%;object-fit:contain — the div is the reserved box,
    the image never decides the layout.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Conduction Request Form</title>
    <style>
        /* A4 rather than Letter: the office prints on A4, and a fixed page
           size keeps the seal boxes at their true millimetre dimensions
           instead of scaling with the viewport. */
        @page {
            size: A4;
            margin: 18mm 16mm;
        }

        html {
            /* Screen preview only. @page owns the real printed margins, and a
               page-sized sheet on screen is what makes the reserved seal
               space readable as reserved rather than as a gap. */
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

        /* Three columns: a fixed seal box, the type, a fixed seal box. The
           side columns are equal, so the centre column is centred on the page
           whether or not either image exists — which is the whole point of
           reserving them now. */
        .letterhead {
            display: grid;
            grid-template-columns: 28mm 1fr 28mm;
            column-gap: 8mm;
            align-items: center;
        }

        /* The reserved space. Deliberately empty: no border, no background,
           no placeholder text. 28mm is the conventional letterhead seal
           diameter (a shade over one inch).

           The height is fixed so the row cannot grow when an image lands in
           it. The centred type below runs taller than 28mm, so the row is
           governed by the text and adding a seal moves nothing. */
        .seal {
            width: 28mm;
            height: 28mm;
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
    <header class="letterhead">
        <div class="seal" aria-hidden="true"></div>

        <div class="titles">
            <p class="republic">Republic of the Philippines</p>
            <p class="province">Province of Isabela</p>
            <p class="municipality">MUNICIPALITY OF ECHAGUE</p>
            <p class="unit">Echague Rescue Emergency Medical Services</p>
            <p class="form-title">CONDUCTION REQUEST FORM</p>
        </div>

        <div class="seal" aria-hidden="true"></div>
    </header>

    {{-- The form body belongs here. Left out deliberately: the letterhead is
         the agreed piece, and inventing field rows before the layout is
         signed off would mean redrawing them. --}}
</body>
</html>
