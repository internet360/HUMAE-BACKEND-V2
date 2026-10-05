<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus;
use App\Enums\UserRole;
use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvoiceIssuedNotification;
use App\Services\InvoiceRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

const FILES_RFC = 'FILE010101AAA';
const FILES_UUID = '6128396f-c09b-4ec6-8699-43c5f7e3b230';
const FILES_ADMIN = '/api/v1/admin/invoice-requests';
const FILES_ME = '/api/v1/me/invoice-requests';

function cfdiXml(string $rfc = FILES_RFC, ?string $uuid = FILES_UUID, string $prolog = ''): string
{
    $stamp = $uuid === null ? '' : '<tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.$uuid.'"/>';

    return '<?xml version="1.0" encoding="UTF-8"?>'.$prolog
        .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Total="499.00">'
        .'<cfdi:Emisor Rfc="EMIS010101AAA" Nombre="HUMAE"/>'
        .'<cfdi:Receptor Rfc="'.$rfc.'" Nombre="Ana Fiscal" UsoCFDI="G03"/>'
        .'<cfdi:Complemento>'.$stamp.'</cfdi:Complemento>'
        .'</cfdi:Comprobante>';
}

/** @return array{pdf: UploadedFile, xml: UploadedFile} */
function cfdiFiles(?string $xml = null): array
{
    return [
        'pdf' => UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'),
        'xml' => UploadedFile::fake()->createWithContent('factura.xml', $xml ?? cfdiXml()),
    ];
}

function uploadFiles(InvoiceRequest $request, array $files): TestResponse
{
    return test()->post(FILES_ADMIN.'/'.$request->id.'/files', $files, ['Accept' => 'application/json']);
}

function issueRequest(InvoiceRequest $request): void
{
    uploadFiles($request, cfdiFiles())->assertOk();
}

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'America/Mexico_City'));
    $this->admin = User::factory()->create()->assignRole(UserRole::Admin->value);
    $this->owner = User::factory()->create()->assignRole(UserRole::Candidate->value);
    $this->payment = Payment::factory()->create(['user_id' => $this->owner->id, 'paid_at' => now()->subDay()]);
    $this->request = app(InvoiceRequestService::class)->claim($this->owner, [$this->payment->id], [
        'rfc' => FILES_RFC, 'legal_name' => 'Ana Fiscal', 'tax_regime' => '612',
        'postal_code' => '06600', 'cfdi_use' => 'G03', 'email' => 'fiscal@example.com',
    ]);
    Sanctum::actingAs($this->admin);
});

describe('upload authorization', function (): void {
    it('denies every non-admin role without storing anything', function (UserRole $role): void {
        Sanctum::actingAs(User::factory()->create()->assignRole($role->value));

        uploadFiles($this->request, cfdiFiles())->assertForbidden();

        expect(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    })->with([UserRole::Candidate, UserRole::Recruiter, UserRole::CompanyUser]);

    it('denies an admin role without the permission', function (): void {
        $this->admin->roles->first()->revokePermissionTo('invoices.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        uploadFiles($this->request, cfdiFiles())->assertForbidden();
        $this->getJson(FILES_ADMIN.'/'.$this->request->id.'/files/pdf')->assertForbidden();
    });
});

describe('upload validation', function (): void {
    it('requires both files', function (string $missing): void {
        $files = cfdiFiles();
        unset($files[$missing]);

        uploadFiles($this->request, $files)->assertUnprocessable()->assertJsonValidationErrors([$missing]);

        expect(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    })->with(['pdf', 'xml']);

    it('rejects files of the wrong type or size', function (string $field, UploadedFile $file): void {
        uploadFiles($this->request, [...cfdiFiles(), $field => $file])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        expect(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    })->with([
        'exe as pdf' => ['pdf', fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        'text with pdf name' => ['pdf', fn () => UploadedFile::fake()->create('factura.pdf', 10, 'text/plain')],
        'pdf too big' => ['pdf', fn () => UploadedFile::fake()->create('factura.pdf', 6000, 'application/pdf')],
        'pdf as xml' => ['xml', fn () => UploadedFile::fake()->create('factura.xml', 10, 'application/pdf')],
        'exe as xml' => ['xml', fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        'xml too big' => ['xml', fn () => UploadedFile::fake()->createWithContent('factura.xml', cfdiXml().str_repeat(' ', 1100000))],
    ]);

    it('rejects malformed xml', function (): void {
        uploadFiles($this->request, cfdiFiles('<cfdi:Comprobante><unclosed>'))->assertUnprocessable()->assertJsonValidationErrors(['xml']);

        expect(Storage::disk('local')->allFiles())->toBeEmpty();
    });

    it('neutralizes an XXE payload and never echoes the local file', function (): void {
        $xxe = cfdiXml(prolog: '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>');
        $xxe = str_replace('Nombre="Ana Fiscal"', 'Nombre="&xxe;"', $xxe);

        $response = uploadFiles($this->request, cfdiFiles($xxe))->assertUnprocessable()->assertJsonValidationErrors(['xml']);

        expect($response->getContent())->not->toContain('root:')
            ->and(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    });

    it('rejects non-utf8 or binary xml before parsing', function (string $payload): void {
        $response = uploadFiles($this->request, cfdiFiles($payload))->assertUnprocessable()->assertJsonValidationErrors(['xml']);

        expect($response->getContent())->not->toContain('root:')
            ->and(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    })->with([
        'utf-16 le bom doctype' => [fn () => "\xFF\xFE".mb_convert_encoding(str_replace('UTF-8', 'UTF-16', cfdiXml(prolog: '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>')), 'UTF-16LE', 'UTF-8')],
        'utf-16 le bom valid cfdi' => [fn () => "\xFF\xFE".mb_convert_encoding(str_replace('UTF-8', 'UTF-16', cfdiXml()), 'UTF-16LE', 'UTF-8')],
        'utf-16 be bom' => [fn () => "\xFE\xFF".mb_convert_encoding('<a/>', 'UTF-16BE', 'UTF-8')],
        'utf-32 le bom' => [fn () => "\xFF\xFE\x00\x00".mb_convert_encoding('<a/>', 'UTF-32LE', 'UTF-8')],
        'utf-32 be bom' => [fn () => "\x00\x00\xFE\xFF".mb_convert_encoding('<a/>', 'UTF-32BE', 'UTF-8')],
        'nul byte' => [fn () => str_replace('Ana Fiscal', "Ana\0Fiscal", cfdiXml())],
    ]);

    it('anchors the checks to the sat 4.0 structure', function (string $xml): void {
        uploadFiles($this->request, cfdiFiles($xml))->assertUnprocessable()->assertJsonValidationErrors(['xml']);

        expect(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    })->with([
        'receptor outside the comprobante children' => fn () => str_replace(
            '<cfdi:Receptor Rfc="'.FILES_RFC.'" Nombre="Ana Fiscal" UsoCFDI="G03"/>',
            '<cfdi:Receptor Rfc="OTRO010101AAA" Nombre="X" UsoCFDI="G03"/><cfdi:Conceptos><cfdi:Concepto><cfdi:Receptor Rfc="'.FILES_RFC.'"/></cfdi:Concepto></cfdi:Conceptos>',
            cfdiXml(),
        ),
        'decoy stamp outside complemento' => fn () => str_replace(
            '<cfdi:Complemento>'.'<tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.FILES_UUID.'"/></cfdi:Complemento>',
            '<cfdi:Addenda><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="'.FILES_UUID.'"/></cfdi:Addenda><cfdi:Complemento/>',
            cfdiXml(),
        ),
        'wrong comprobante namespace' => fn () => str_replace('http://www.sat.gob.mx/cfd/4', 'http://example.com/cfd/4', cfdiXml()),
        'cfdi 3.3 namespace' => fn () => str_replace('http://www.sat.gob.mx/cfd/4', 'http://www.sat.gob.mx/cfd/3', cfdiXml()),
        'wrong stamp namespace' => fn () => str_replace('http://www.sat.gob.mx/TimbreFiscalDigital', 'http://example.com/tfd', cfdiXml()),
        'two receptores' => fn () => str_replace('<cfdi:Complemento>', '<cfdi:Receptor Rfc="'.FILES_RFC.'" Nombre="Dos" UsoCFDI="G03"/><cfdi:Complemento>', cfdiXml()),
        'two stamps' => fn () => str_replace('</cfdi:Complemento>', '<tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="11111111-2222-4333-8444-555555555555"/></cfdi:Complemento>', cfdiXml()),
        'missing version' => fn () => str_replace(' Version="4.0"', '', cfdiXml()),
        'version 3.3' => fn () => str_replace('Version="4.0"', 'Version="3.3"', cfdiXml()),
    ]);

    it('rejects an xml without the stamp uuid', function (): void {
        uploadFiles($this->request, cfdiFiles(cfdiXml(uuid: null)))->assertUnprocessable()->assertJsonValidationErrors(['xml']);
    });

    it('rejects an xml whose receptor RFC differs from the request', function (): void {
        $response = uploadFiles($this->request, cfdiFiles(cfdiXml(rfc: 'OTRO010101AAA')))
            ->assertUnprocessable()->assertJsonValidationErrors(['xml']);

        expect($response->getContent())->not->toContain(FILES_RFC)
            ->and(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    });

    it('rejects a uuid already used by another request', function (): void {
        InvoiceRequest::factory()->create(['status' => InvoiceRequestStatus::Issued, 'cfdi_uuid' => FILES_UUID]);

        uploadFiles($this->request, cfdiFiles())->assertUnprocessable()->assertJsonValidationErrors(['xml']);

        expect(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    });
});

describe('issuing', function (): void {
    it('issues a requested or in progress request, stores private files and audits', function (InvoiceRequestStatus $from): void {
        $this->request->update(['status' => $from]);

        $response = uploadFiles($this->request, cfdiFiles())->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.has_pdf', true)
            ->assertJsonPath('data.has_xml', true)
            ->assertJsonPath('data.cfdi_uuid', FILES_UUID);

        $fresh = $this->request->fresh();
        expect($fresh->status)->toBe(InvoiceRequestStatus::Issued)
            ->and($fresh->issued_at)->not->toBeNull()
            ->and($fresh->cfdi_uuid)->toBe(FILES_UUID);
        Storage::disk('local')->assertExists($fresh->pdf_path);
        Storage::disk('local')->assertExists($fresh->xml_path);
        expect($fresh->pdf_path)->toStartWith('invoices/2026/'.$fresh->id.'/')
            ->and($response->getContent())->not->toContain($fresh->pdf_path)
            ->and($response->getContent())->not->toContain('invoices/');

        $log = Activity::where('log_name', 'invoice-requests')->latest('id')->first();
        expect($log->properties['invoice_request_id'])->toBe($fresh->id)
            ->and($log->properties['from'])->toBe($from->value)
            ->and($log->properties['to'])->toBe('issued')
            ->and(json_encode($log->properties))->not->toContain(FILES_RFC);
    })->with([InvoiceRequestStatus::Requested, InvoiceRequestStatus::InProgress]);

    it('queues the notification to the cfdi email after commit', function (): void {
        issueRequest($this->request);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            InvoiceIssuedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'fiscal@example.com',
        );
        expect(new InvoiceIssuedNotification($this->request))->toBeInstanceOf(ShouldQueue::class)
            ->and((new InvoiceIssuedNotification($this->request))->afterCommit)->toBeTrue();
    });

    it('writes the notification in Spanish with the billing link and no attachments', function (): void {
        config(['app.frontend_url' => 'https://humae.test']);
        $this->request->update(['cfdi_uuid' => FILES_UUID]);

        $mail = (new InvoiceIssuedNotification($this->request))->toMail(new AnonymousNotifiable);

        expect($mail->actionUrl)->toBe('https://humae.test/dashboard/membresia/facturacion')
            ->and($mail->subject)->toContain('factura')
            ->and($mail->attachments)->toBeEmpty()
            ->and($mail->rawAttachments)->toBeEmpty();
    });

    it('does not notify when validation fails', function (): void {
        uploadFiles($this->request, ['pdf' => cfdiFiles()['pdf']])->assertUnprocessable();

        Notification::assertNothingSent();
    });

    it('refuses rejected and cancelled requests', function (InvoiceRequestStatus $status): void {
        $this->request->update(['status' => $status]);

        uploadFiles($this->request, cfdiFiles())->assertUnprocessable()->assertJsonValidationErrors(['status']);

        expect($this->request->fresh()->pdf_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBeEmpty();
        Notification::assertNothingSent();
    })->with([InvoiceRequestStatus::Rejected, InvoiceRequestStatus::Cancelled, InvoiceRequestStatus::CancellationPending]);

    it('never replaces the files of an issued request', function (): void {
        issueRequest($this->request);
        $before = $this->request->fresh();

        uploadFiles($this->request, cfdiFiles(cfdiXml(uuid: '11111111-2222-4333-8444-555555555555')))
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $after = $this->request->fresh();
        expect($after->pdf_path)->toBe($before->pdf_path)
            ->and($after->xml_path)->toBe($before->xml_path)
            ->and($after->cfdi_uuid)->toBe(FILES_UUID)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    });

    it('cannot be issued through the plain status endpoint', function (): void {
        $this->patchJson(FILES_ADMIN.'/'.$this->request->id.'/status', ['status' => 'issued'])->assertUnprocessable();

        expect($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);
    });
});

describe('downloads', function (): void {
    it('lets the owner download both files with private headers and safe names', function (string $kind, string $type): void {
        issueRequest($this->request);
        Sanctum::actingAs($this->owner);

        $response = $this->get(FILES_ME.'/'.$this->request->id.'/files/'.$kind)->assertOk();

        expect($response->headers->get('Content-Type'))->toContain($type)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('Content-Disposition'))->toContain('CFDI-'.FILES_UUID.'.'.$kind);

        $this->get(FILES_ME.'/'.$this->request->id.'/files/'.$kind)->assertOk();
    })->with([['pdf', 'application/pdf'], ['xml', 'xml']]);

    it('answers 404 to a foreign user exactly as to a missing request', function (): void {
        issueRequest($this->request);
        Sanctum::actingAs(User::factory()->create()->assignRole(UserRole::Candidate->value));

        $foreign = $this->getJson(FILES_ME.'/'.$this->request->id.'/files/pdf');
        $missing = $this->getJson(FILES_ME.'/999999/files/pdf');

        $foreign->assertNotFound();
        expect($foreign->getContent())->toBe($missing->getContent());
    });

    it('answers 404 when the request has no files yet', function (): void {
        Sanctum::actingAs($this->owner);

        $this->getJson(FILES_ME.'/'.$this->request->id.'/files/pdf')->assertNotFound();
    });

    it('answers 404 to an unknown kind', function (): void {
        issueRequest($this->request);
        Sanctum::actingAs($this->owner);

        $this->getJson(FILES_ME.'/'.$this->request->id.'/files/exe')->assertNotFound();
    });

    it('lets an admin download and audits it without the RFC', function (): void {
        issueRequest($this->request);

        $response = $this->get(FILES_ADMIN.'/'.$this->request->id.'/files/xml')->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        $log = Activity::where('log_name', 'invoice-requests')->latest('id')->first();
        expect($log->properties['invoice_request_id'])->toBe($this->request->id)
            ->and($log->properties['kind'])->toBe('xml')
            ->and(json_encode($log->properties))->not->toContain(FILES_RFC);
    });

    it('keeps the files readable after the request is cancelled', function (): void {
        issueRequest($this->request);
        $paths = [$this->request->fresh()->pdf_path, $this->request->fresh()->xml_path];

        $this->patchJson(FILES_ADMIN.'/'.$this->request->id.'/status', ['status' => 'cancellation_pending', 'reason' => 'Reembolso'])->assertOk();
        $this->patchJson(FILES_ADMIN.'/'.$this->request->id.'/status', ['status' => 'cancelled', 'reason' => 'Cancelada en el SAT'])->assertOk();

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        Sanctum::actingAs($this->owner);
        $this->get(FILES_ME.'/'.$this->request->id.'/files/pdf')->assertOk();
    });
});

describe('resources', function (): void {
    it('exposes availability, never paths, to the owner and the admin', function (): void {
        $this->getJson(FILES_ADMIN.'/'.$this->request->id)->assertOk()
            ->assertJsonPath('data.has_pdf', false)->assertJsonPath('data.has_xml', false)
            ->assertJsonPath('data.issued_at', null)->assertJsonPath('data.cfdi_uuid', null);

        issueRequest($this->request);

        foreach ([FILES_ADMIN.'/'.$this->request->id, FILES_ADMIN] as $url) {
            $json = $this->getJson($url)->assertOk();
            expect($json->getContent())->not->toContain('pdf_path')->not->toContain('xml_path');
        }

        Sanctum::actingAs($this->owner);
        $response = $this->getJson(FILES_ME.'/'.$this->request->id)->assertOk()
            ->assertJsonPath('data.has_pdf', true)->assertJsonPath('data.has_xml', true)
            ->assertJsonPath('data.cfdi_uuid', FILES_UUID);
        expect($response->json('data.issued_at'))->not->toBeNull()
            ->and($response->getContent())->not->toContain('pdf_path')->not->toContain('invoices/');
    });
});
