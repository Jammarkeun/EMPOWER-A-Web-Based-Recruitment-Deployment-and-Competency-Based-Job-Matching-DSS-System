{{--
    The applicant's Bio-Data as entered in EMPOWER.

    No official CDE Bio-Data template has been provided, so this follows the
    digital form section by section, in the same order, with the letterhead
    and black ruling of the report template (reports/document.blade.php).
    When CDE supplies its form, its layout replaces this one.

    It states what it is: a system-generated copy of what the applicant
    entered, with its review status. It carries no signature or approval
    block unless Settings says CDE wants a signed printed copy, and even then
    the line is for the applicant's own hand, not an electronic signature.

    DomPDF supports only a subset of CSS (no flexbox, grid or variables), so
    the layout is table based. A blank value prints as a dash, never as a
    guess.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bio-Data - {{ $profile['applicant_code'] ?? '' }}</title>
    <style>
        @page { margin: 34mm 14mm 18mm 14mm; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #000; margin: 0; }

        .letterhead {
            position: fixed; top: -28mm; left: 0; right: 0;
            padding: 1.5mm 0; border: 1.5pt solid #000;
        }
        .letterhead table { width: 100%; border-collapse: collapse; }
        .letterhead td { vertical-align: middle; text-align: center; padding: 0; }
        .lh-agency { font-size: 12pt; }
        .lh-address { font-size: 9pt; margin-top: 0.6mm; }
        .lh-title { font-size: 12pt; font-weight: bold; letter-spacing: 1pt; margin-top: 2.5mm; }

        .page-footer {
            position: fixed; bottom: -13mm; left: 0; right: 0;
            font-size: 6.8pt; color: #333;
        }
        .page-number:after { content: counter(page); }

        table.status { width: 100%; border-collapse: collapse; border: 1.5pt solid #000; margin-bottom: 4mm; }
        table.status td { padding: 2mm 3mm; vertical-align: top; font-size: 8.5pt; }
        .status-title { font-weight: bold; font-size: 10pt; letter-spacing: 0.4pt; }
        .meta { width: 40%; border-left: 1.5pt solid #000; }

        h2 {
            font-size: 9.5pt; text-transform: uppercase; letter-spacing: 0.4pt;
            background: #1b4b8f; color: #fff; margin: 0; padding: 1.6mm 3mm;
        }
        .section { border: 1pt solid #000; margin-bottom: 4mm; page-break-inside: avoid; }

        table.lines { width: 100%; border-collapse: collapse; }
        table.lines td { border-bottom: 0.5pt solid #555; padding: 1.4mm 3mm; vertical-align: top; }
        table.lines tr:last-child td { border-bottom: none; }
        .label { width: 24%; color: #333; font-size: 8pt; }
        .value { width: 26%; font-weight: bold; }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th {
            text-align: left; font-size: 7.2pt; text-transform: uppercase; letter-spacing: 0.3pt;
            padding: 1.6mm 2mm; border-bottom: 0.8pt solid #000; background: #eef1f6;
        }
        table.rows td { padding: 1.4mm 2mm; border-bottom: 0.4pt solid #888; vertical-align: top; font-size: 8.4pt; }
        .none { padding: 3mm; text-align: center; color: #333; font-size: 8.5pt; }

        .declaration { font-size: 8pt; margin-top: 2mm; }

        table.signature { width: 100%; border-collapse: collapse; margin-top: 10mm; page-break-inside: avoid; }
        table.signature td { width: 50%; padding: 0 6mm; vertical-align: bottom; font-size: 8.5pt; text-align: center; }
        .sig-line { border-top: 0.75pt solid #000; padding-top: 1mm; }
    </style>
</head>
<body>

@php
    $agency = config('empower.organisation.name');
    $logo = public_path('logo/cde-manpower.png');
    $dash = '—';
    $show = fn ($value) => filled($value) ? $value : $dash;
    $date = fn ($value) => filled($value) ? \Illuminate\Support\Carbon::parse($value)->format('F j, Y') : $dash;
@endphp

<div class="letterhead">
    <table>
        <tr>
            <td style="width:30mm;">
                @if (file_exists($logo))
                    <img src="{{ $logo }}" alt="" style="width:19mm; height:19mm;">
                @endif
            </td>
            <td>
                <div class="lh-agency">{{ $agency }}</div>
                <div class="lh-address">{{ config('empower.organisation.address') }}</div>
                <div class="lh-title">BIO-DATA</div>
            </td>
            <td style="width:30mm;"></td>
        </tr>
    </table>
</div>

<div class="page-footer">
    <table style="width:100%">
        <tr>
            <td style="width:35%;">Generated from EMPOWER &middot; {{ $generated_at }}</td>
            <td style="text-align:center;">
                System-generated copy of information entered by the applicant.<br>
                Confidential &mdash; personal information protected under RA 10173.
            </td>
            <td style="width:15%; text-align:right;">Page <span class="page-number"></span></td>
        </tr>
    </table>
</div>

<table class="status">
    <tr>
        <td>
            <div class="status-title">{{ $status[0] }}</div>
            <div>{{ $status[1] }}</div>
            <div style="margin-top:1mm;">Verification of this Bio-Data confirms the information entered here only. It does not verify any other requirement or document.</div>
        </td>
        <td class="meta">
            Applicant code: <strong>{{ $show($profile['applicant_code'] ?? null) }}</strong><br>
            @if ($version)
                Version: <strong>{{ $version->version_number }}</strong><br>
                Submitted: {{ $version->submitted_at?->format('F j, Y g:i A') }}<br>
            @endif
            Generated: {{ $generated_at }}
        </td>
    </tr>
</table>

<div class="section">
    <h2>I. Personal information</h2>
    <table class="lines">
        <tr>
            <td class="label">Last name</td><td class="value">{{ $show($profile['last_name'] ?? null) }}</td>
            <td class="label">First name</td><td class="value">{{ $show($profile['first_name'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="label">Middle name</td><td class="value">{{ $show($profile['middle_name'] ?? null) }}</td>
            <td class="label">Suffix</td><td class="value">{{ $show($profile['suffix'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="label">Date of birth</td><td class="value">{{ $date($profile['birth_date'] ?? null) }}</td>
            <td class="label">Age</td><td class="value">{{ $show($profile['age'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="label">Sex</td><td class="value">{{ $show($sexLabel) }}</td>
            <td class="label">Civil status</td><td class="value">{{ $show($civilStatusLabel) }}</td>
        </tr>
        <tr>
            <td class="label">Citizenship</td><td class="value">{{ $show($form['nationality']) }}</td>
            <td class="label">Height / weight</td>
            <td class="value">
                {{ filled($form['height_cm']) ? rtrim(rtrim(number_format($form['height_cm'], 2), '0'), '.').' cm' : $dash }}
                /
                {{ filled($form['weight_kg']) ? rtrim(rtrim(number_format($form['weight_kg'], 2), '0'), '.').' kg' : $dash }}
            </td>
        </tr>
    </table>
</div>

<div class="section">
    <h2>II. Address and contact</h2>
    <table class="lines">
        <tr><td class="label">Present address</td><td class="value" colspan="3">{{ $show($profile['present_address'] ?? null) }}</td></tr>
        <tr><td class="label">Provincial address</td><td class="value" colspan="3">{{ $show($form['provincial_address']) }}</td></tr>
        <tr>
            <td class="label">Contact number</td><td class="value">{{ $show($profile['contact_number'] ?? null) }}</td>
            <td class="label">Email address</td><td class="value">{{ $show($profile['email'] ?? null) }}</td>
        </tr>
    </table>
</div>

<div class="section">
    <h2>III. Educational background</h2>
    @if (count($form['educations']) === 0)
        <div class="none">No schools listed.</div>
    @else
        <table class="rows">
            <thead>
                <tr><th style="width:20%">Level</th><th style="width:30%">School</th><th style="width:24%">Course / program</th><th style="width:10%">Year</th><th>Honors</th></tr>
            </thead>
            <tbody>
                @foreach ($form['educations'] as $education)
                    <tr>
                        <td>{{ $show($educationLabel($education['education_level'])) }}</td>
                        <td>{{ $show($education['school_name']) }}</td>
                        <td>{{ $show($education['course_program']) }}</td>
                        <td>{{ $show($education['graduation_year']) }}</td>
                        <td>{{ $show($education['honors']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>IV. Employment record</h2>
    @if (count($form['experiences']) === 0)
        <div class="none">No previous employment listed.</div>
    @else
        <table class="rows">
            <thead>
                <tr><th style="width:24%">Employer</th><th style="width:20%">Position</th><th style="width:13%">From</th><th style="width:13%">To</th><th>Responsibilities</th></tr>
            </thead>
            <tbody>
                @foreach ($form['experiences'] as $experience)
                    <tr>
                        <td>{{ $show($experience['company_name']) }}</td>
                        <td>{{ $show($experience['position_title']) }}</td>
                        <td>{{ $date($experience['start_date']) }}</td>
                        <td>{{ filled($experience['end_date']) ? $date($experience['end_date']) : 'Present' }}</td>
                        <td>{{ $show($experience['responsibilities']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@if ($version)
    <p class="declaration">
        On submitting this Bio-Data, the applicant confirmed that the information above is true and correct.
    </p>
@endif

@if ($signed_copy_required)
    <table class="signature">
        <tr>
            <td><div class="sig-line">Signature of applicant over printed name</div></td>
            <td><div class="sig-line">Date signed</div></td>
        </tr>
    </table>
@endif

</body>
</html>
