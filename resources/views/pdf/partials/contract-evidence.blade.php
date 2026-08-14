{{--
    Constancia de firma electrónica. Es la parte que acredita el documento, así
    que es idéntica en el contrato maestro y en la adenda: los dos se sellan
    igual y los dos valen lo mismo como instrumento.

    Variables: $contract, $signerName, $signer, $signedAt, $evidence,
    $providerName.
--}}
<div class="evidence">
    <div class="evidence-title">Constancia de firma electrónica</div>
    <table>
        <tr>
            <td class="k">{{ $folioLabel ?? 'Folio del contrato' }}</td>
            <td>{{ $contract->folio }}</td>
        </tr>
        <tr>
            <td class="k">Firmante</td>
            <td>{{ $signerName }} &mdash; {{ $signer->email }}</td>
        </tr>
        <tr>
            <td class="k">Fecha y hora de firma</td>
            <td>{{ $signedAt->format('Y-m-d H:i:s') }} ({{ config('app.timezone') }})</td>
        </tr>
        @if (! empty($evidence['accepted_at']))
            <tr>
                <td class="k">Aceptación de términos</td>
                <td>{{ $evidence['accepted_at'] }}</td>
            </tr>
        @endif
        @if (! empty($evidence['ip']))
            <tr>
                <td class="k">Dirección IP de origen</td>
                <td>{{ $evidence['ip'] }}</td>
            </tr>
        @endif
        @if (! empty($evidence['user_agent']))
            <tr>
                <td class="k">Agente de usuario</td>
                <td>{{ Str::limit($evidence['user_agent'], 160) }}</td>
            </tr>
        @endif
    </table>
    <div class="note">
        Documento firmado electrónicamente en términos de los artículos 89 a 99 del Código de Comercio. La
        integridad de este archivo se acredita mediante constancia de conservación de mensajes de datos
        NOM-151-SCFI-2016 emitida por CINCEL, S.A.P.I. de C.V., resguardada por {{ $providerName }} y
        vinculada a este documento por su folio y su huella digital SHA-256.
    </div>
</div>
