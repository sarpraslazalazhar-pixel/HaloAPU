<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\FormField;
use App\Models\Message;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Ai\Tools\GetTicketDetailTool;
use App\Services\Ai\Tools\ReadTicketAttachmentTool;
use App\Services\DocumentContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

class DocumentReaderAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unitA;

    private Unit $unitB;

    private SubUnit $subUnitA;

    private SubUnit $subUnitB;

    private User $requester;

    private User $otherUser;

    private Admin $operatorA;

    private Admin $operatorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Koordinator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        $this->unitA = Unit::create(['nama_unit' => 'Unit IT', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->unitB = Unit::create(['nama_unit' => 'Unit Sarpras', 'deskripsi' => 'Sarpras', 'aktif' => true]);

        $this->subUnitA = SubUnit::create([
            'unit_id' => $this->unitA->id,
            'nama_layanan' => 'Aplikasi Kampus',
            'deskripsi' => 'Layanan Aplikasi',
            'aktif' => true,
        ]);

        $this->subUnitB = SubUnit::create([
            'unit_id' => $this->unitB->id,
            'nama_layanan' => 'Perbaikan AC',
            'deskripsi' => 'Layanan Sarpras',
            'aktif' => true,
        ]);

        $this->requester = User::factory()->create(['username' => 'pemohon_doc']);
        $this->otherUser = User::factory()->create(['username' => 'pemohon_lain']);

        $this->operatorA = Admin::factory()->create(['username' => 'op_it_doc']);
        $this->operatorA->assignRole('Operator');
        $this->operatorA->units()->attach($this->unitA->id);

        $this->operatorB = Admin::factory()->create(['username' => 'op_sarpras_doc']);
        $this->operatorB->assignRole('Operator');
        $this->operatorB->units()->attach($this->unitB->id);
    }

    private function createTicket(User $user, ?Unit $unit = null, ?SubUnit $subUnit = null): Ticket
    {
        $targetUnit = $unit ?? $this->unitA;
        $targetSubUnit = $subUnit ?? $this->subUnitA;

        return Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $targetUnit->id,
            'sub_unit_id' => $targetSubUnit->id,
            'form_data' => ['judul' => 'Lampiran Uji Dokumen'],
            'status' => 'open',
            'priority' => 'Sedang',
        ]);
    }

    private function putDocxInPublicStorage(string $relativePath, string $documentXml): string
    {
        $fullPath = Storage::disk('public')->path($relativePath);
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        return $fullPath;
    }

    private function makeUploadedDocx(string $filename = 'laporan.docx'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'docx_test_').'.docx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('word/document.xml', $this->sampleWordXml());
        $zip->close();

        return new UploadedFile(
            $tmpPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true
        );
    }

    private function sampleWordXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>Paragraf Pembuka Audit</w:t></w:r></w:p>'
            .'<w:tbl>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Komponen</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Server API</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Normal</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
            .'<w:p><w:r><w:t>Paragraf Penutup</w:t></w:r></w:p>'
            .'</w:body>'
            .'</w:document>';
    }

    private function putSampleXlsxInPublicStorage(string $relativePath): string
    {
        $fullPath = Storage::disk('public')->path($relativePath);
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $spreadsheet = new Spreadsheet;
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Anggaran');
        $sheet1->setCellValue('A1', 'Item');
        $sheet1->setCellValue('B1', 'Biaya');
        $sheet1->setCellValue('C1', 'Catatan');
        $sheet1->setCellValue('A2', 'Lisensi Server');
        $sheet1->setCellValue('B2', 1500000);
        $sheet1->setCellValue('C2', 'Tahunan');
        $sheet1->setCellValue('A3', 'Total');
        $sheet1->setCellValue('B3', '=SUM(B2:B2)');

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Vendor');
        $sheet2->setCellValue('A1', 'Nama');
        $sheet2->setCellValue('A2', 'PT Nusantara');

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $fullPath;
    }

    private function makeUploadedXlsx(string $filename = 'rekap.xlsx'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_test_').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->setCellValue('A1', 'Nama');
        $sheet->setCellValue('B1', 'Nilai');
        $sheet->setCellValue('A2', 'Router');
        $sheet->setCellValue('B2', 42);
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return new UploadedFile(
            $tmpPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function makeUploadedTextFile(
        string $filename,
        string $content,
        string $mimeType = 'text/csv',
    ): UploadedFile {
        $tmpPath = tempnam(sys_get_temp_dir(), 'txt_test_').'_'.$filename;
        file_put_contents($tmpPath, $content);

        return new UploadedFile($tmpPath, $filename, $mimeType, null, true);
    }

    private function putZipWithEntries(string $relativePath, array $entries): string
    {
        $fullPath = Storage::disk('public')->path($relativePath);
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $fullPath;
    }

    public function test_document_content_service_parses_real_docx_paragraphs_and_tables(): void
    {
        $fullPath = $this->putDocxInPublicStorage('docs/audit.docx', $this->sampleWordXml());

        $result = DocumentContentService::extractDocxText($fullPath);

        $this->assertSame(2, $result['paragraphs']);
        $this->assertSame(1, $result['tables']);
        $this->assertFalse($result['truncated']);
        $this->assertStringContainsString('Paragraf Pembuka Audit', $result['text']);
        $this->assertStringContainsString('| Komponen | Status |', $result['text']);
        $this->assertStringContainsString('| Server API | Normal |', $result['text']);
        $this->assertStringContainsString('Paragraf Penutup', $result['text']);
    }

    public function test_document_content_service_rejects_xxe_declarations_in_docx(): void
    {
        $maliciousXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<!DOCTYPE foo [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body>'
            .'</w:document>';

        $fullPath = $this->putDocxInPublicStorage('docs/xxe.docx', $maliciousXml);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTD/ENTITY');

        DocumentContentService::extractDocxText($fullPath);
    }

    public function test_document_content_service_extracts_multisheet_xlsx_csv_and_enforces_truncation(): void
    {
        $xlsxPath = $this->putSampleXlsxInPublicStorage('sheets/multi.xlsx');

        $fullPreview = DocumentContentService::extractSpreadsheetPreview($xlsxPath, 'xlsx');
        $this->assertSame('spreadsheet', $fullPreview['type']);
        $this->assertSame(2, $fullPreview['total_sheets']);
        $this->assertFalse($fullPreview['truncated_sheets']);
        $this->assertSame('Anggaran', $fullPreview['sheets'][0]['name']);
        $this->assertSame(['A', 'B', 'C'], $fullPreview['sheets'][0]['columns']);
        $this->assertSame('Lisensi Server', $fullPreview['sheets'][0]['rows'][1][0]);
        $this->assertSame('Vendor', $fullPreview['sheets'][1]['name']);

        // Truncated bounds test (1 sheet, 2 rows, 2 cols)
        $bounded = DocumentContentService::extractSpreadsheetPreview($xlsxPath, 'xlsx', 1, 2, 2);
        $this->assertTrue($bounded['truncated_sheets']);
        $this->assertCount(1, $bounded['sheets']);
        $this->assertTrue($bounded['sheets'][0]['truncated_rows']);
        $this->assertTrue($bounded['sheets'][0]['truncated_cols']);
        $this->assertCount(2, $bounded['sheets'][0]['rows']);
        $this->assertSame(['A', 'B'], $bounded['sheets'][0]['columns']);

        // CSV test
        Storage::disk('public')->put('sheets/rekap.csv', "Nama,Unit\nAndi,IT\nBudi,Keuangan\n");
        $csvPreview = DocumentContentService::extractSpreadsheetPreview(
            Storage::disk('public')->path('sheets/rekap.csv'),
            'csv'
        );
        $this->assertSame('csv', $csvPreview['extension']);
        $this->assertSame(3, $csvPreview['sheets'][0]['row_count']);
        $this->assertSame('Andi', $csvPreview['sheets'][0]['rows'][1][0]);
    }

    public function test_ticket_and_admin_spreadsheet_preview_endpoints_enforce_auth_and_validate_type(): void
    {
        $ticket = $this->createTicket($this->requester, $this->unitA, $this->subUnitA);

        $this->putSampleXlsxInPublicStorage("ticket-attachments/{$ticket->id}/anggaran.xlsx");
        $xlsxAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/anggaran.xlsx",
            'original_name' => 'anggaran.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 4096,
            'wajib' => false,
        ]);

        Storage::disk('public')->put("ticket-attachments/{$ticket->id}/surat.pdf", '%PDF-1.4 mock');
        $pdfAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/surat.pdf",
            'original_name' => 'surat.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'wajib' => false,
        ]);

        // Authorized user preview
        $this->actingAs($this->requester)
            ->get(route('tiket.preview', $xlsxAttachment))
            ->assertOk()
            ->assertJsonPath('sheets.0.name', 'Anggaran')
            ->assertJsonPath('sheets.1.name', 'Vendor');

        // Another user forbidden
        $this->actingAs($this->otherUser)
            ->get(route('tiket.preview', $xlsxAttachment))
            ->assertForbidden();

        // Non-spreadsheet attachment returns 422 JSON
        $this->actingAs($this->requester)
            ->get(route('tiket.preview', $pdfAttachment))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        // Authorized unit admin preview
        $this->actingAs($this->operatorA, 'admin')
            ->get(route('admin.tiket.preview', $xlsxAttachment))
            ->assertOk()
            ->assertJsonPath('sheets.0.name', 'Anggaran');

        // Cross-unit admin forbidden
        $this->actingAs($this->operatorB, 'admin')
            ->get(route('admin.tiket.preview', $xlsxAttachment))
            ->assertForbidden();

        // Admin non-spreadsheet returns 422 JSON
        $this->actingAs($this->operatorA, 'admin')
            ->get(route('admin.tiket.preview', $pdfAttachment))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_chat_spreadsheet_preview_endpoints_work_for_participants_only(): void
    {
        $ticket = $this->createTicket($this->requester, $this->unitA, $this->subUnitA);

        $conversation = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $this->requester->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $this->requester->id,
            'ticket_id' => $ticket->id,
            'body' => 'Terlampir CSV jadwal.',
        ]);

        Storage::disk('public')->put('chat-attachments/jadwal.csv', "Hari,Ruang\nSenin,Lab 1\n");
        $csvAttachment = ChatAttachment::create([
            'message_id' => $message->id,
            'file_name' => 'jadwal.csv',
            'file_path' => 'chat-attachments/jadwal.csv',
            'file_type' => 'text/csv',
            'file_size' => 256,
        ]);

        $this->actingAs($this->requester)
            ->get(route('chat.preview', $csvAttachment))
            ->assertOk()
            ->assertJsonPath('sheets.0.rows.1.0', 'Senin');

        $this->actingAs($this->otherUser)
            ->get(route('chat.preview', $csvAttachment))
            ->assertForbidden();

        $this->actingAs($this->operatorA, 'admin')
            ->get(route('admin.chat.preview', $csvAttachment))
            ->assertOk()
            ->assertJsonPath('sheets.0.rows.1.1', 'Lab 1');

        $this->actingAs($this->operatorB, 'admin')
            ->get(route('admin.chat.preview', $csvAttachment))
            ->assertForbidden();
    }

    public function test_user_can_upload_xlsx_and_docx_when_creating_and_replying_ticket(): void
    {
        $textField = FormField::create([
            'sub_unit_id' => $this->subUnitA->id,
            'label' => 'Judul Kendala',
            'tipe_field' => 'teks_pendek',
            'wajib' => true,
            'urutan' => 1,
        ]);

        $uploadField = FormField::create([
            'sub_unit_id' => $this->subUnitA->id,
            'label' => 'Berkas Pendukung',
            'tipe_field' => 'upload_file',
            'wajib' => false,
            'urutan' => 2,
        ]);

        $xlsxFile = $this->makeUploadedXlsx('data-permintaan.xlsx');
        $docxFile = $this->makeUploadedDocx('kronologi.docx');

        $createResponse = $this->actingAs($this->requester)->post(route('tiket.store'), [
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => [
                (string) $textField->id => 'Permintaan Export & Rekap',
            ],
            'attachments' => [
                $uploadField->id => [$xlsxFile],
            ],
            'general_attachments' => [$docxFile],
        ]);

        $createResponse->assertSessionHasNoErrors();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $ticket->id,
            'original_name' => 'data-permintaan.xlsx',
        ]);
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $ticket->id,
            'original_name' => 'kronologi.docx',
        ]);

        $replyXlsx = $this->makeUploadedXlsx('balasan-data.xlsx');
        $replyResponse = $this->actingAs($this->requester)->post(route('tiket.reply', $ticket), [
            'catatan' => 'Berikut tambahan rekap Excel.',
            'general_attachments' => [$replyXlsx],
        ]);

        $replyResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $ticket->id,
            'original_name' => 'balasan-data.xlsx',
        ]);
    }

    public function test_ai_tools_list_and_read_ticket_attachments_with_unit_scoping(): void
    {
        $ticket = $this->createTicket($this->requester, $this->unitA, $this->subUnitA);

        $this->putDocxInPublicStorage("ticket-attachments/{$ticket->id}/spesifikasi.docx", $this->sampleWordXml());
        $docxAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/spesifikasi.docx",
            'original_name' => 'spesifikasi.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => 2048,
            'wajib' => false,
        ]);

        $this->putSampleXlsxInPublicStorage("ticket-attachments/{$ticket->id}/rab.xlsx");
        $xlsxAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/rab.xlsx",
            'original_name' => 'rab.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 4096,
            'wajib' => false,
        ]);

        Storage::disk('public')->put("ticket-attachments/{$ticket->id}/foto.png", 'fake-png');
        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/foto.png",
            'original_name' => 'foto.png',
            'mime_type' => 'image/png',
            'file_size' => 512,
            'wajib' => false,
        ]);

        // GetTicketDetailTool exposes readable attachments list
        $detailTool = app(GetTicketDetailTool::class);
        $detailPayload = $detailTool->execute($this->operatorA, ['ticket_id' => (string) $ticket->id])->data;

        $this->assertCount(3, $detailPayload['attachments']);
        $this->assertTrue($detailPayload['attachments'][0]['readable']);
        $this->assertTrue($detailPayload['attachments'][1]['readable']);
        $this->assertFalse($detailPayload['attachments'][2]['readable']);

        // ReadTicketAttachmentTool reads DOCX with untrusted notice
        $readerTool = app(ReadTicketAttachmentTool::class);
        $docxPayload = $readerTool->execute($this->operatorA, [
            'ticket_id' => (string) $ticket->id,
            'attachment_id' => $docxAttachment->id,
        ])->data;

        $this->assertTrue($docxPayload['supported']);
        $this->assertSame('docx', $docxPayload['format']);
        $this->assertArrayHasKey('untrusted_document_notice', $docxPayload);
        $this->assertStringContainsString('Paragraf Pembuka Audit', $docxPayload['document_content']);
        $this->assertStringContainsString('| Server API | Normal |', $docxPayload['document_content']);

        // ReadTicketAttachmentTool reads XLSX multi-sheet
        $xlsxPayload = $readerTool->execute($this->operatorA, [
            'ticket_id' => (string) $ticket->id,
            'attachment_id' => $xlsxAttachment->id,
        ])->data;

        $this->assertTrue($xlsxPayload['supported']);
        $this->assertSame('xlsx', $xlsxPayload['format']);
        $this->assertStringContainsString('Sheet: Anggaran', $xlsxPayload['document_content']);
        $this->assertStringContainsString('Lisensi Server', $xlsxPayload['document_content']);
        $this->assertStringContainsString('PT Nusantara', $xlsxPayload['document_content']);

        // Cross-unit admin is denied (IDOR check)
        $deniedPayload = $readerTool->execute($this->operatorB, [
            'ticket_id' => (string) $ticket->id,
            'attachment_id' => $docxAttachment->id,
        ])->data;

        $this->assertArrayHasKey('error', $deniedPayload);
        $this->assertArrayNotHasKey('document_content', $deniedPayload);
    }

    public function test_real_csv_upload_succeeds_while_html_and_txt_are_rejected(): void
    {
        $textField = FormField::create([
            'sub_unit_id' => $this->subUnitA->id,
            'label' => 'Judul Permintaan',
            'tipe_field' => 'teks_pendek',
            'wajib' => true,
            'urutan' => 1,
        ]);

        // 1. User creates ticket with a genuine .csv file (detected as text/plain by finfo)
        $userCsv = $this->makeUploadedTextFile('rekap-user.csv', "Nama,Unit\nAndi,IT\n", 'text/csv');
        $userRes = $this->actingAs($this->requester)->post(route('tiket.store'), [
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => [(string) $textField->id => 'Upload CSV User'],
            'general_attachments' => [$userCsv],
        ]);
        $userRes->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_attachments', ['original_name' => 'rekap-user.csv']);

        // 2. Admin creates ticket with a genuine .csv file
        $adminCsv = $this->makeUploadedTextFile('rekap-admin.csv', "Nama,Unit\nBudi,Sarpras\n", 'text/csv');
        $adminRes = $this->actingAs($this->operatorA, 'admin')->post(route('admin.tiket.store'), [
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => [(string) $textField->id => 'Upload CSV Admin'],
            'general_attachments' => [$adminCsv],
        ]);
        $adminRes->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_attachments', ['original_name' => 'rekap-admin.csv']);

        // 3. Chat room upload with a genuine .csv file
        $ticket = $this->createTicket($this->requester, $this->unitA, $this->subUnitA);
        $conversation = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $this->requester->id,
        ]);
        $chatCsv = $this->makeUploadedTextFile('rekap-chat.csv', "Nama,Unit\nCitra,IT\n", 'text/csv');
        $chatRes = $this->actingAs($this->requester)
            ->postJson(route('chat.messages.store', $conversation), [
                'body' => 'File CSV chat',
                'attachment' => $chatCsv,
            ]);
        $chatRes->assertSuccessful();
        $this->assertDatabaseHas('chat_attachments', ['file_name' => 'rekap-chat.csv']);

        // 4. Dangerous/non-allowed extensions (.html, .txt) MUST still be rejected (422 / validation error)
        $htmlFile = $this->makeUploadedTextFile('evil.html', '<html><body><script>alert(1)</script></body></html>', 'text/html');
        $txtFile = $this->makeUploadedTextFile('notes.txt', 'Plain text attachment', 'text/plain');

        $this->actingAs($this->requester)
            ->post(route('tiket.store'), [
                'unit_id' => $this->unitA->id,
                'sub_unit_id' => $this->subUnitA->id,
                'form_data' => [(string) $textField->id => 'Reject HTML'],
                'general_attachments' => [$htmlFile],
            ])
            ->assertSessionHasErrors('general_attachments.0');

        $this->actingAs($this->requester)
            ->post(route('tiket.store'), [
                'unit_id' => $this->unitA->id,
                'sub_unit_id' => $this->subUnitA->id,
                'form_data' => [(string) $textField->id => 'Reject TXT'],
                'general_attachments' => [$txtFile],
            ])
            ->assertSessionHasErrors('general_attachments.0');

        $this->actingAs($this->requester)
            ->post(route('chat.messages.store', $conversation), [
                'body' => 'Reject HTML chat',
                'attachment' => $this->makeUploadedTextFile('xss.html', '<html><script>alert(1)</script></html>', 'text/html'),
            ])
            ->assertSessionHasErrors('attachment');

        $this->actingAs($this->requester)
            ->post(route('chat.messages.store', $conversation), [
                'body' => 'Reject TXT chat',
                'attachment' => $this->makeUploadedTextFile('catatan.txt', 'Plain text', 'text/plain'),
            ])
            ->assertSessionHasErrors('attachment');
    }

    public function test_spoofed_csv_upload_does_not_store_client_html_mime_and_view_forces_download_with_nosniff(): void
    {
        $ticket = $this->createTicket($this->requester, $this->unitA, $this->subUnitA);

        $conversation = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $this->requester->id,
        ]);

        // 1. Plain CSV uploaded with spoofed client multipart Content-Type = text/html
        $csvContent = "Nama,Catatan\nAndi,Jadwal Lab 1\n";
        $spoofedChatCsv = $this->makeUploadedTextFile('jadwal.csv', $csvContent, 'text/html');
        $this->actingAs($this->requester)
            ->post(route('chat.messages.store', $conversation), [
                'body' => 'Cek jadwal',
                'attachment' => $spoofedChatCsv,
            ])
            ->assertSuccessful();

        $chatAtt = ChatAttachment::where('file_name', 'jadwal.csv')->latest('id')->firstOrFail();
        $this->assertNotSame('text/html', strtolower((string) $chatAtt->file_type));

        $chatViewRes = $this->actingAs($this->operatorA, 'admin')
            ->get(route('admin.chat.view', $chatAtt));
        $chatViewRes->assertOk();
        $chatViewRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', strtolower((string) $chatViewRes->headers->get('Content-Disposition')));
        $this->assertStringNotContainsString('text/html', strtolower((string) $chatViewRes->headers->get('Content-Type')));

        // 2. .csv containing <script> tag is sniffed as text/html by finfo and blocked at upload
        $scriptCsv = $this->makeUploadedTextFile('xss.csv', "<script>alert('xss')</script>\n", 'text/csv');
        $this->actingAs($this->requester)
            ->post(route('chat.messages.store', $conversation), [
                'body' => 'XSS via CSV',
                'attachment' => $scriptCsv,
            ])
            ->assertSessionHasErrors('attachment');

        // 3. Revision upload with spoofed client MIME & ticket viewAttachment hardening
        $this->subUnitA->update(['is_revision_enabled' => true]);
        $ticket->update(['status' => 'solve']);
        $spoofedRevCsv = $this->makeUploadedTextFile('revisi.csv', $csvContent, 'text/html');
        $this->actingAs($this->requester)
            ->post(route('tiket.request-revision', $ticket), [
                'catatan' => 'Revisi terlampir',
                'general_attachments' => [$spoofedRevCsv],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $ticketAtt = TicketAttachment::where('original_name', 'revisi.csv')->latest('id')->firstOrFail();
        $this->assertNotSame('text/html', strtolower((string) $ticketAtt->mime_type));

        $ticketViewRes = $this->actingAs($this->operatorA, 'admin')
            ->get(route('admin.tiket.view', $ticketAtt));
        $ticketViewRes->assertOk();
        $ticketViewRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', strtolower((string) $ticketViewRes->headers->get('Content-Disposition')));
        $this->assertStringNotContainsString('text/html', strtolower((string) $ticketViewRes->headers->get('Content-Type')));

        // 4. Even if an existing DB row claims mime_type=text/html, viewAttachment refuses inline & forces download + nosniff
        Storage::disk('public')->put("ticket-attachments/{$ticket->id}/legacy.html", '<html><script>alert(1)</script></html>');
        $legacyHtmlAtt = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/legacy.html",
            'original_name' => 'legacy.html',
            'mime_type' => 'text/html',
            'file_size' => 38,
            'wajib' => false,
        ]);

        $legacyViewRes = $this->actingAs($this->requester)
            ->get(route('tiket.view', $legacyHtmlAtt));
        $legacyViewRes->assertOk();
        $legacyViewRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', strtolower((string) $legacyViewRes->headers->get('Content-Disposition')));
    }

    public function test_multi_entry_zip_bomb_docx_and_xlsx_are_rejected_safely(): void
    {
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'word/document.xml' => $this->sampleWordXml(),
        ];

        for ($i = 1; $i <= 305; $i++) {
            $entries["word/header{$i}.xml"] = $this->sampleWordXml();
        }

        $bombDocxPath = $this->putZipWithEntries('bombs/many-entries.docx', $entries);

        try {
            DocumentContentService::extractDocxText($bombDocxPath);
            $this->fail('Expected RuntimeException for multi-entry DOCX ZIP bomb.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('terlalu banyak entri', $e->getMessage());
        }

        // Also verify cumulative uncompressed byte limit via assertSafeZipArchive
        $bigDocxPath = $this->putDocxInPublicStorage('bombs/oversized-uncompressed.docx', $this->sampleWordXml());
        try {
            DocumentContentService::assertSafeZipArchive($bigDocxPath, 300, 128);
            $this->fail('Expected RuntimeException for cumulative uncompressed ZIP limit.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('melebihi batas aman', $e->getMessage());
        }

        // Malicious/excessive-entry .xlsx returns clean 422 on preview endpoint without 500/OOM
        $ticket = $this->createTicket($this->requester, $this->unitA, $this->subUnitA);
        $validXlsx = $this->putSampleXlsxInPublicStorage("ticket-attachments/{$ticket->id}/bomb.xlsx");

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($validXlsx) === true);
        for ($i = 1; $i <= 305; $i++) {
            $zip->addFromString("xl/extra/entry{$i}.xml", '<x/>');
        }
        $zip->close();

        $bombAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/bomb.xlsx",
            'original_name' => 'bomb.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 10240,
            'wajib' => false,
        ]);

        $this->actingAs($this->operatorA, 'admin')
            ->get(route('admin.tiket.preview', $bombAttachment))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }
}
