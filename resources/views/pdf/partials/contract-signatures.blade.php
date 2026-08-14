{{--
    Bloque de firmas: apoderado de HUMAE a la izquierda, representante del
    cliente a la derecha. Compartido por el contrato maestro y la adenda.

    Variables: $humaeSignature, $signatureSrc, $providerName, $signerName,
    $signerTitle, $companyName.
--}}
<table class="signatures">
    <tr>
        <td class="ink">
            @if (! empty($humaeSignature['src']))
                <img src="{{ $humaeSignature['src'] }}" alt="">
            @endif
        </td>
        <td class="ink">
            {{-- Nulo en el borrador de vista previa: se muestra sólo la línea. --}}
            @if (! empty($signatureSrc))
                <img src="{{ $signatureSrc }}" alt="">
            @endif
        </td>
    </tr>
    <tr>
        <td>
            <div class="rule">
                <div class="signer-name">{{ $humaeSignature['name'] ?? '' }}</div>
                <div class="signer-title">{{ $humaeSignature['title'] ?? '' }}</div>
                <div class="signer-org">{{ $providerName }}</div>
                <div class="party" style="margin-top:5px;">Por EL PRESTADOR</div>
            </div>
        </td>
        <td>
            <div class="rule">
                <div class="signer-name">{{ $signerName }}</div>
                <div class="signer-title">{{ $signerTitle }}</div>
                <div class="signer-org">{{ $companyName }}</div>
                <div class="party" style="margin-top:5px;">Por EL CLIENTE</div>
            </div>
        </td>
    </tr>
</table>
