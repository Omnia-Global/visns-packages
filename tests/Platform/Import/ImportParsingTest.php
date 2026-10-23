<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Controllers\ImportController;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The import wizard's read side: parsing CSV / XLSX files, previewing a
 * mapping and suggesting one. Reproduces the attendee-import failures (blank
 * emails, BOM headers, columns past Z, title rows, "Title" vs "Job Title").
 */
class ImportParsingTest extends TestCase
{
    /** @var string[] temp files written by a test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The package does not route the controller; an application does.
        Route::middleware('web')->post('/test-import/parse', [ImportController::class, 'parseFile']);
        Route::middleware('web')->post('/test-import/preview', [ImportController::class, 'previewMapping']);
        Route::middleware('web')->post('/test-import/suggest', [ImportController::class, 'getColumnSuggestions']);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function attendeeConfig(): array
    {
        return [
            ['id' => 'firstname', 'label' => 'First Name', 'type' => 'text', 'required' => true],
            ['id' => 'surname', 'label' => 'Surname', 'type' => 'text', 'required' => true],
            ['id' => 'email', 'label' => 'Email', 'type' => 'email'],
            ['id' => 'phone', 'label' => 'Phone', 'type' => 'text'],
        ];
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rows.csv', $content);
    }

    /**
     * An XLSX upload built from rows of cell values (row 1 first).
     */
    private function xlsx(array $rows, ?callable $tweak = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . ($r + 1), $value);
            }
        }

        if ($tweak) {
            $tweak($sheet);
        }

        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'rows.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function parse(UploadedFile $file): array
    {
        $response = $this->post('/test-import/parse', ['file' => $file], ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('success', true);

        return $response->json('data');
    }

    private function preview(UploadedFile $file, array $mapping, array $config)
    {
        return $this->post('/test-import/preview', [
            'file' => $file,
            'mapping' => json_encode($mapping),
            'model_config' => json_encode($config),
        ], ['Accept' => 'application/json']);
    }

    private function suggest(array $headers, array $fieldIds): array
    {
        $fields = array_map(fn ($id) => ['id' => $id, 'label' => ucfirst($id)], $fieldIds);

        return $this->postJson('/test-import/suggest', [
            'headers' => $headers,
            'model_fields' => $fields,
        ])->assertOk()->json('suggestions');
    }

    #[Test]
    public function rows_with_a_blank_email_preview_as_valid(): void
    {
        $csv = $this->csv("First Name,Surname,Email\nAnn,Lee,\nBob,Ng,  \nCat,Oh,Cat@Example.TEST\n");

        $response = $this->preview($csv, [
            'First Name' => 'firstname',
            'Surname' => 'surname',
            'Email' => 'email',
        ], $this->attendeeConfig());

        $response->assertOk()
            ->assertJsonPath('validation_summary.valid_rows', 3)
            ->assertJsonPath('validation_summary.invalid_rows', 0);

        $preview = $response->json('preview');
        $this->assertArrayNotHasKey('email', $preview[0]['mapped'], 'a blank cell sets nothing');
        $this->assertSame('cat@example.test', $preview[2]['mapped']['email'], 'valid emails are lowercased');
    }

    #[Test]
    public function a_required_email_still_fails_when_blank(): void
    {
        $config = $this->attendeeConfig();
        $config[2]['required'] = true;

        $this->preview($this->csv("First Name,Surname,Email\nAnn,Lee,\n"), [
            'First Name' => 'firstname', 'Surname' => 'surname', 'Email' => 'email',
        ], $config)
            ->assertOk()
            ->assertJsonPath('validation_summary.valid_rows', 0)
            ->assertJsonPath('validation_summary.invalid_rows', 1);
    }

    #[Test]
    public function a_bom_csv_header_is_clean_and_maps(): void
    {
        $content = "\xEF\xBB\xBFFirst Name ,Surname\nAnn,Lee\nBob,Ng\n";

        $data = $this->parse($this->csv($content));
        $this->assertSame(['First Name', 'Surname'], $data['headers']);

        $suggestions = $this->suggest($data['headers'], ['firstname', 'surname']);
        $this->assertSame(['First Name' => 'firstname', 'Surname' => 'surname'], $suggestions);

        $this->preview($this->csv($content), $suggestions, $this->attendeeConfig())
            ->assertOk()
            ->assertJsonPath('validation_summary.valid_rows', 2)
            ->assertJsonPath('validation_summary.invalid_rows', 0);
    }

    #[Test]
    public function csv_rows_are_fitted_to_the_header_and_blank_rows_dropped(): void
    {
        $data = $this->parse($this->csv("a,b,c\n1,2\n\n,,\n4,5,6,7\n"));

        $this->assertSame(['a', 'b', 'c'], $data['headers']);
        $this->assertSame([
            ['a' => '1', 'b' => '2', 'c' => ''],
            ['a' => '4', 'b' => '5', 'c' => '6'],
        ], $data['data']);
        $this->assertSame(2, $data['total_rows']);
    }

    #[Test]
    public function an_xlsx_with_28_columns_reads_column_ab(): void
    {
        $headers = [];
        $values = [];
        for ($i = 1; $i <= 28; $i++) {
            $headers[] = 'Col ' . Coordinate::stringFromColumnIndex($i);
            $values[] = 'v' . $i;
        }

        $data = $this->parse($this->xlsx([$headers, $values]));

        $this->assertCount(28, $data['headers']);
        $this->assertSame('Col AB', $data['headers'][27]);
        $this->assertSame('v28', $data['data'][0]['Col AB']);
        $this->assertSame('v26', $data['data'][0]['Col Z']);
    }

    #[Test]
    public function trailing_blank_xlsx_rows_are_dropped(): void
    {
        $build = fn () => $this->xlsx([
            ['First Name', 'Surname'],
            ['Ann', 'Lee'],
            ['Bob', 'Ng'],
        ], function ($sheet) {
            // Formatted-but-empty rows and an empty-string cell below the data.
            $sheet->getStyle('A4:B20')->getFont()->setBold(true);
            $sheet->setCellValue('A6', '');
            $sheet->setCellValue('B9', '   ');
        });

        $data = $this->parse($build());

        $this->assertSame(2, $data['total_rows']);
        $this->assertSame(['First Name' => 'Bob', 'Surname' => 'Ng'], $data['data'][1]);

        // parseFile() moved the first upload; the wizard re-sends the file.
        $this->preview($build(), ['First Name' => 'firstname', 'Surname' => 'surname'], $this->attendeeConfig())
            ->assertOk()
            ->assertJsonPath('validation_summary.valid_rows', 2)
            ->assertJsonPath('validation_summary.invalid_rows', 0);
    }

    #[Test]
    public function a_title_row_above_the_header_is_detected(): void
    {
        $data = $this->parse($this->xlsx([
            ['Attendee list - Expo 2026'],
            [],
            ['First Name', 'Surname', 'Email'],
            ['Ann', 'Lee', 'ann@example.test'],
        ]));

        $this->assertSame(['First Name', 'Surname', 'Email'], $data['headers']);
        $this->assertSame(1, $data['total_rows']);
        $this->assertSame('Ann', $data['data'][0]['First Name']);

        $csv = $this->parse($this->csv("Attendee list,,\nFirst Name,Surname,Email\nAnn,Lee,\n"));
        $this->assertSame(['First Name', 'Surname', 'Email'], $csv['headers']);
        $this->assertSame(1, $csv['total_rows']);
    }

    #[Test]
    public function a_single_column_sheet_keeps_its_first_row_as_the_header(): void
    {
        $data = $this->parse($this->csv("Email\na@example.test\nb@example.test\n"));

        $this->assertSame(['Email'], $data['headers']);
        $this->assertSame(2, $data['total_rows']);
    }

    #[Test]
    public function numeric_cells_render_as_integer_strings(): void
    {
        $data = $this->parse($this->xlsx([
            ['Phone', 'Count', 'Rate'],
            [400111222.0, 7, 2.5],
        ]));

        $this->assertSame('400111222', $data['data'][0]['Phone']);
        $this->assertSame('7', $data['data'][0]['Count']);
        $this->assertSame('2.5', $data['data'][0]['Rate']);
    }

    #[Test]
    public function title_maps_to_prefix_and_job_title_to_position_when_prefix_exists(): void
    {
        $suggestions = $this->suggest(
            ['Title', 'First Name', 'Last Name', 'Job Title', 'Organisation', 'E-mail', 'Mobile Number'],
            ['prefix', 'firstname', 'surname', 'position', 'company', 'email', 'phone']
        );

        $this->assertSame([
            'Title' => 'prefix',
            'First Name' => 'firstname',
            'Last Name' => 'surname',
            'Job Title' => 'position',
            'Organisation' => 'company',
            'E-mail' => 'email',
            'Mobile Number' => 'phone',
        ], $suggestions);
    }

    #[Test]
    public function title_maps_to_position_when_there_is_no_prefix(): void
    {
        $this->assertSame(
            ['Title' => 'position', 'first_name' => 'firstname'],
            $this->suggest(['Title', 'first_name'], ['firstname', 'position'])
        );

        // A real job title column still wins position over the bare "Title".
        $this->assertSame(
            ['Job Title' => 'position'],
            $this->suggest(['Title', 'Job Title'], ['firstname', 'position'])
        );
    }

    #[Test]
    public function no_field_is_suggested_twice_and_unknown_fields_are_never_suggested(): void
    {
        $suggestions = $this->suggest(
            ['Email', 'Email Address', 'Phone', 'Mobile', '', '  ', 'Country', 'Name', 'First Name'],
            ['email', 'phone', 'firstname']
        );

        $this->assertSame(array_unique($suggestions), $suggestions, 'one header per field');
        $this->assertSame(['Email' => 'email', 'Phone' => 'phone', 'First Name' => 'firstname'], $suggestions);
        $this->assertNotContains('nationality', $suggestions, 'not a model field');
    }

    #[Test]
    public function validation_messages_use_field_labels(): void
    {
        $response = $this->preview($this->csv("First Name,Surname,Email\n,Lee,not-an-email\n"), [
            'First Name' => 'firstname', 'Surname' => 'surname', 'Email' => 'email',
        ], $this->attendeeConfig());

        $response->assertOk()->assertJsonPath('validation_summary.invalid_rows', 1);
        $this->assertContains('The First Name field is required.', $response->json('preview.0.errors'));
    }

    #[Test]
    public function the_validation_summary_covers_every_row_while_the_preview_is_limited(): void
    {
        $lines = ["First Name,Surname"];
        for ($i = 1; $i <= 25; $i++) {
            $lines[] = $i === 20 ? ",Missing" : "Person{$i},Lee";
        }

        $response = $this->preview($this->csv(implode("\n", $lines) . "\n"), [
            'First Name' => 'firstname', 'Surname' => 'surname',
        ], $this->attendeeConfig());

        $response->assertOk()
            ->assertJsonPath('total_rows', 25)
            ->assertJsonPath('validation_summary.valid_rows', 24)
            ->assertJsonPath('validation_summary.invalid_rows', 1)
            ->assertJsonPath('validation_summary.errors.0.row', 20);
        $this->assertCount(10, $response->json('preview'));
    }
}
