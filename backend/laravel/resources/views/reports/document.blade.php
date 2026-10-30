{{--
    One template renders every report, laid out like CDE's own Manpower
    Requisition Form (received 29 October 2026): a ruled box with the logo on
    the left, the agency name and address centred, the document's title in
    bold capitals beneath; then "Label: value" lines on the left and a boxed
    list on the right; then signature lines with the name over the line and
    the agency beneath it.

    The ruling is black, as on the paper form. EMPOWER's own navy stays where
    it was - the logo and the table's heading row.

    DomPDF only supports a subset of CSS — no flexbox, no grid, no CSS variables
    — so the layout is deliberately table based. Anything more modern silently
    renders as an unstyled stack, which is why this file looks older than the
    rest of the codebase.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* The top margin holds the letterhead, which repeats on every page. */
        @page { margin: 35mm 12mm 15mm 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #000;
            margin: 0;
        }

        /* Repeated on every page by DomPDF's fixed positioning, so a report
           running to twenty pages stays identifiable when printed and stapled. */
        .letterhead {
            position: fixed;
            top: -29mm; left: 0; right: 0;
            padding: 1.5mm 0;
            border: 1.5pt solid #000;
        }
        .letterhead table { width: 100%; border-collapse: collapse; }
        .letterhead td { vertical-align: middle; text-align: center; padding: 0; }
        .lh-agency { font-size: 12pt; }
        .lh-address { font-size: 9pt; margin-top: 0.6mm; }
        .lh-title { font-size: 11.5pt; font-weight: bold; letter-spacing: 0.5pt; margin-top: 2.5mm; }

        .page-footer {
            position: fixed;
            bottom: -10mm; left: 0; right: 0;
            height: 8mm;
            font-size: 6.8pt;
            color: #333;
        }
        .page-number:after { content: counter(page); }

        /* The form's body: labelled lines on the left, a boxed list on the
           right, divided by a heavy rule - CDE's "Reason of Request" column. */
        table.form {
            width: 100%;
            border-collapse: collapse;
            border: 1.5pt solid #000;
            margin-bottom: 4mm;
            page-break-inside: avoid;
        }
        table.form td.left { width: 64%; border-right: 1.5pt solid #000; vertical-align: top; padding: 0; }
        table.form td.right { vertical-align: top; padding: 2mm 5mm 2mm; }

        table.lines { width: 100%; border-collapse: collapse; }
        table.lines td { border-bottom: 0.6pt solid #000; padding: 1.3mm 3mm; font-size: 9pt; }
        table.lines tr.last td { border-bottom: none; }
        .label { white-space: nowrap; width: 1%; padding-right: 2mm; }

        .box-title { text-align: center; font-weight: bold; font-size: 9.5pt; margin-bottom: 1.5mm; }
        table.figures { width: 100%; border-collapse: collapse; }
        table.figures td { padding: 1mm 0; font-size: 8.5pt; vertical-align: bottom; }
        .figure-value {
            text-align: right; font-weight: bold; white-space: nowrap;
            border-bottom: 0.6pt solid #000; padding-left: 3mm;
        }

        table.data { width: 100%; border-collapse: collapse; border: 1pt solid #000; }

        table.data thead th {
            background: #1b4b8f;
            color: #fff;
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            padding: 2mm 1.6mm;
            text-align: left;
            font-weight: bold;
            border: 0.5pt solid #000;
        }

        table.data tbody td {
            padding: 1.2mm 1.6mm;
            border: 0.4pt solid #555;
            font-size: 7.8pt;
            vertical-align: top;
        }

        /* Zebra striping survives photocopying, which the agency does. */
        table.data tbody tr:nth-child(even) td { background: #f2f4f8; }

        .empty {
            padding: 12mm; text-align: center;
            border: 1pt solid #000; font-size: 9pt;
        }

        /* Signature lines as on CDE's form: the label, then the name printed
           in capitals over the line, with the agency beneath. */
        table.signature {
            width: 100%;
            border-collapse: collapse;
            border: 1.5pt solid #000;
            margin-top: 4mm;
            page-break-inside: avoid;
        }
        td.sig-cell { padding: 9mm 4mm 3mm; vertical-align: bottom; font-size: 9pt; width: 33%; }
        .sig-label { white-space: nowrap; padding-right: 2mm; }
        .sig-name {
            border-bottom: 0.75pt solid #000; text-align: center;
            font-weight: bold; text-transform: uppercase; height: 4.5mm;
        }
        .sig-under { text-align: center; font-size: 7.5pt; padding-top: 0.6mm; }
    </style>
</head>
<body>

@php($agency = config('empower.organisation.name'))
@php($logo = public_path('logo/cde-manpower.png'))

<div class="letterhead">
    <table>
        <tr>
            {{--
                Guarded by file_exists: DomPDF raises on a missing image rather
                than skipping it, and a missing logo must never be the reason a
                report fails to generate.
            --}}
            <td style="width:30mm;">
                @if (file_exists($logo))
                    <img src="{{ $logo }}" alt="" style="width:20mm; height:20mm;">
                @endif
            </td>
            <td>
                <div class="lh-agency">{{ $agency }}</div>
                <div class="lh-address">{{ config('empower.organisation.address') }}</div>
                <div class="lh-title">{{ mb_strtoupper($title) }}</div>
            </td>
            {{-- Balances the logo so the lettering sits in the middle of the page. --}}
            <td style="width:30mm;"></td>
        </tr>
    </table>
</div>

<div class="page-footer">
    <table style="width:100%">
        <tr>
            <td>Generated from EMPOWER &middot; {{ $generated_at }}</td>
            <td style="text-align:center;">Confidential — contains personal information protected under RA 10173</td>
            <td style="text-align:right;">Page <span class="page-number"></span></td>
        </tr>
    </table>
</div>

<table class="form">
    <tr>
        <td class="left">
            <table class="lines">
                <tr><td class="label">Report:</td><td>{{ $subtitle }}</td></tr>
                <tr><td class="label">Period:</td><td>{{ $period }}</td></tr>
                <tr><td class="label">Number of Records:</td><td>{{ number_format(count($rows)) }}</td></tr>
                <tr><td class="label">Date generated:</td><td>{{ $generated_at }}</td></tr>
                <tr class="last"><td class="label">Prepared by:</td><td>{{ $prepared_by }}</td></tr>
            </table>
        </td>
        <td class="right">
            <div class="box-title">Summary</div>
            @if (count($summary))
                <table class="figures">
                    @foreach ($summary as $item)
                        <tr>
                            <td>{{ $item['label'] }}</td>
                            <td class="figure-value">{{ $item['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <div style="text-align:center;">&mdash;</div>
            @endif
        </td>
    </tr>
</table>

@if (count($rows) === 0)
    <div class="empty">
        No records match the selected filters.<br>
        <span style="font-size:7.5pt">Try widening the date range or clearing a filter.</span>
    </div>
@else
    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $index => $column)
                    <th @if(isset($widths[$index])) style="width: {{ $widths[$index] }}" @endif>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- The agency circulates printed reports internally and to clients, so the
     signature lines exist on the page rather than being added by hand. --}}
<table class="signature">
    <tr>
        @foreach (['Prepared by:' => $prepared_by, 'Reviewed by:' => null, 'Approved by:' => null] as $label => $name)
            <td class="sig-cell">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td class="sig-label" style="padding:0; width:1%;">{{ $label }}</td>
                        <td style="padding:0;">
                            <div class="sig-name">{{ $name }}</div>
                            <div class="sig-under">{{ $agency }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        @endforeach
    </tr>
</table>

</body>
</html>
