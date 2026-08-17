{{--
    One template renders every report.

    DomPDF only supports a subset of CSS — no flexbox, no grid, no CSS variables
    — so the layout is deliberately table and float based. Anything more modern
    silently renders as an unstyled stack, which is why this file looks older
    than the rest of the codebase.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 26mm 14mm 20mm 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #1a2231;
            margin: 0;
        }

        /* Repeated on every page by DomPDF's fixed positioning, so a report
           running to twenty pages stays identifiable when printed and stapled. */
        .page-header {
            position: fixed;
            top: -18mm; left: 0; right: 0;
            height: 16mm;
            border-bottom: 0.6pt solid #1b4b8f;
        }

        .agency { font-size: 13pt; font-weight: bold; color: #1b4b8f; letter-spacing: -0.2pt; }
        .agency-sub { font-size: 7.5pt; color: #5a6b85; }

        .page-footer {
            position: fixed;
            bottom: -14mm; left: 0; right: 0;
            height: 10mm;
            font-size: 7pt;
            color: #7b8ba3;
            border-top: 0.4pt solid #d9e2f0;
            padding-top: 3mm;
        }

        .page-number:after { content: counter(page); }

        h1 { font-size: 14pt; margin: 0 0 1mm; color: #101b2d; }
        .subtitle { font-size: 8.5pt; color: #5a6b85; margin: 0 0 4mm; }

        .meta-bar {
            width: 100%;
            background: #f2f6fc;
            border: 0.4pt solid #d9e2f0;
            padding: 2.5mm 3mm;
            margin-bottom: 4mm;
            font-size: 7.5pt;
            color: #47566e;
        }
        .meta-bar td { padding: 0.4mm 0; }
        .meta-label { color: #7b8ba3; width: 18mm; }

        table.data { width: 100%; border-collapse: collapse; }

        table.data thead th {
            background: #1b4b8f;
            color: #fff;
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            padding: 2mm 1.6mm;
            text-align: left;
            font-weight: bold;
        }

        table.data tbody td {
            padding: 1.6mm;
            border-bottom: 0.3pt solid #e4eaf4;
            font-size: 7.8pt;
            vertical-align: top;
        }

        /* Zebra striping survives photocopying, which the agency does. */
        table.data tbody tr:nth-child(even) td { background: #f7f9fc; }

        .summary { margin-top: 6mm; page-break-inside: avoid; }
        .summary-title {
            font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt;
            color: #7b8ba3; margin-bottom: 2mm;
        }
        .summary-box {
            border: 0.5pt solid #d9e2f0;
            background: #f7f9fc;
            padding: 3mm;
        }
        .summary-box td { padding: 1mm 3mm 1mm 0; font-size: 8.5pt; }
        .summary-value { font-weight: bold; font-size: 11pt; color: #1b4b8f; }

        .empty {
            padding: 12mm; text-align: center; color: #7b8ba3;
            border: 0.5pt dashed #c9d5e8; font-size: 9pt;
        }

        .signature { margin-top: 14mm; page-break-inside: avoid; }
        .signature td { font-size: 8pt; padding-top: 10mm; }
        .sig-line { border-top: 0.5pt solid #47566e; padding-top: 1mm; width: 60mm; }
        .sig-role { color: #7b8ba3; font-size: 7pt; }
    </style>
</head>
<body>

<div class="page-header">
    <table style="width:100%">
        <tr>
            {{--
                The agency letterhead. These reports are printed and handed to
                client companies, so the mark belongs at the top of the page the
                same way it would on the agency's own stationery.

                Guarded by file_exists: DomPDF raises on a missing image rather
                than skipping it, and a missing logo must never be the reason a
                report fails to generate.
            --}}
            @php($logo = public_path('logo/cde-manpower-mark.png'))
            @if (file_exists($logo))
                <td style="width:16mm; vertical-align:middle;">
                    <img src="{{ $logo }}" alt="" style="width:13mm; height:13mm;">
                </td>
            @endif
            <td>
                <div class="agency">{{ strtoupper(config('empower.organisation.name')) }}</div>
                <div class="agency-sub">
                    {{ config('empower.organisation.address') }} &middot; Recruitment and Deployment Management
                </div>
            </td>
            <td style="text-align:right; font-size:7pt; color:#7b8ba3;">
                EMPOWER System<br>
                {{ $generated_at }}
            </td>
        </tr>
    </table>
</div>

<div class="page-footer">
    <table style="width:100%">
        <tr>
            <td>Generated from the EMPOWER system &middot; {{ $generated_at }}</td>
            <td style="text-align:center;">Confidential — contains personal information protected under RA 10173</td>
            <td style="text-align:right;">Page <span class="page-number"></span></td>
        </tr>
    </table>
</div>

<h1>{{ $title }}</h1>
<p class="subtitle">{{ $subtitle }}</p>

<table class="meta-bar">
    <tr>
        <td class="meta-label">Period</td>
        <td>{{ $period }}</td>
        <td class="meta-label">Records</td>
        <td>{{ count($rows) }}</td>
        <td class="meta-label">Prepared by</td>
        <td>{{ $prepared_by }}</td>
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

    <div class="summary">
        <div class="summary-title">Summary</div>
        <table class="summary-box" style="width:100%">
            <tr>
                @foreach ($summary as $item)
                    <td>
                        <div style="color:#7b8ba3; font-size:7.5pt">{{ $item['label'] }}</div>
                        <div class="summary-value">{{ $item['value'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>
@endif

{{-- The agency circulates printed reports internally and to clients, so the
     signature block exists on the page rather than being added by hand. --}}
<table class="signature" style="width:100%">
    <tr>
        <td>
            <div class="sig-line">{{ $prepared_by }}</div>
            <div class="sig-role">Prepared by</div>
        </td>
        <td>
            <div class="sig-line">&nbsp;</div>
            <div class="sig-role">Reviewed by</div>
        </td>
        <td>
            <div class="sig-line">&nbsp;</div>
            <div class="sig-role">Approved by</div>
        </td>
    </tr>
</table>

</body>
</html>
