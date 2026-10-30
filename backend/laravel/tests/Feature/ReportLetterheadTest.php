<?php

namespace Tests\Feature;

use App\Services\Reporting\ReportBuilder;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Every exported report is laid out like CDE's own Manpower Requisition Form
 * (received 29 October 2026): its letterhead - logo, agency, address, the
 * title in capitals - its "Label: value" lines and its signature lines.
 *
 * The PDF and the Excel sheet share one source for the agency's details, the
 * organisation settings, so correcting them in Settings changes every report.
 */
class ReportLetterheadTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_the_pdf_layout_follows_cdes_form_for_every_report(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr);

        foreach (array_keys(ReportBuilder::TYPES) as $type) {
            $report = app(ReportBuilder::class)->build($type, []);
            $html = view('reports.document', array_merge($report, ['prepared_by' => 'Maria Santos']))->render();

            $this->assertStringContainsString('CDE Manpower Services', $html, $type);
            $this->assertStringContainsString('Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna', $html, $type);
            $this->assertStringContainsString(e(mb_strtoupper($report['title'])), $html, $type);
            foreach (['Report:', 'Period:', 'Number of Records:', 'Date generated:', 'Prepared by:', 'Reviewed by:', 'Approved by:', 'Summary'] as $label) {
                $this->assertStringContainsString($label, $html, "{$type} lacks {$label}");
            }
            $this->assertStringContainsString('Maria Santos', $html);
        }
    }

    public function test_the_pdf_download_still_renders(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr);
        Sanctum::actingAs($hr);

        $response = $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'pdf'])->assertOk();

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_excel_sheet_opens_with_the_letterhead_and_stays_a_working_table(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr);
        Sanctum::actingAs($hr);

        $response = $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'xlsx'])->assertOk();
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();

        $this->assertSame('CDE Manpower Services', $sheet->getCell('A1')->getValue());
        $this->assertSame('Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna', $sheet->getCell('A2')->getValue());
        $this->assertSame('APPLICANT REPORT', $sheet->getCell('A3')->getValue());
        $this->assertStringStartsWith('Report: ', $sheet->getCell('A4')->getValue());
        $this->assertStringContainsString('Prepared by: '.$hr->full_name, $sheet->getCell('A5')->getValue());
        $this->assertStringContainsString('Number of Records: 1', $sheet->getCell('A5')->getValue());
        $this->assertCount(1, $sheet->getDrawingCollection(), 'The CDE logo is on the sheet.');

        // The table itself: headings on row 6, frozen beneath, filtered.
        $this->assertSame('Code', $sheet->getCell('A6')->getValue());
        $this->assertSame('Name', $sheet->getCell('B6')->getValue());
        $this->assertSame('A7', $sheet->getFreezePane());
        $this->assertStringStartsWith('A6:', $sheet->getAutoFilter()->getRange());
        $this->assertSame(['1', '6'], $sheet->getPageSetup()->getRowsToRepeatAtTop());

        // Signature lines below the summary, the name over the line.
        $values = [];
        foreach ($sheet->getRowIterator() as $row) {
            $values[] = [$sheet->getCell('A'.$row->getRowIndex())->getValue(), $sheet->getCell('B'.$row->getRowIndex())->getValue()];
        }
        $this->assertContains(['Prepared by:', mb_strtoupper($hr->full_name)], $values);
        $this->assertContains(['Approved by:', ''], array_map(fn ($pair) => [$pair[0], (string) $pair[1]], $values));
    }

    public function test_the_letterhead_follows_the_agency_details_in_settings(): void
    {
        $admin = $this->adminUser();
        app(SettingsService::class)->set('empower.organisation.address', 'Brgy. Bubukal, Santa Cruz, Laguna');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/reports/preview', ['report_type' => 'clients'])->assertOk()
            ->assertJsonPath('data.letterhead.agency', 'CDE Manpower Services')
            ->assertJsonPath('data.letterhead.address', 'Brgy. Bubukal, Santa Cruz, Laguna');

        $report = app(ReportBuilder::class)->build('clients', []);
        $html = view('reports.document', array_merge($report, ['prepared_by' => $admin->full_name]))->render();
        $this->assertStringContainsString('Brgy. Bubukal, Santa Cruz, Laguna', $html);
    }
}
