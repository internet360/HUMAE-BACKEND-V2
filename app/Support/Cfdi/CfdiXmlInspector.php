<?php

declare(strict_types=1);

namespace App\Support\Cfdi;

use DOMAttr;
use DOMDocument;
use DOMXPath;

/**
 * Reads the two facts the app needs from a stamped CFDI: the receptor RFC and
 * the SAT stamp UUID.
 *
 * The XML comes from an admin but is still untrusted input: a CFDI never has a
 * DOCTYPE, so any DTD (and with it every entity, internal or external) is
 * rejected before parsing; the parse itself also disables network access and
 * never substitutes entities (no LIBXML_NOENT).
 */
class CfdiXmlInspector
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array{rfc: string, uuid: string} rfc uppercased, uuid lowercased
     *
     * @throws InvalidCfdiXmlException
     */
    public function inspect(string $xml): array
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new InvalidCfdiXmlException('El XML no puede contener definiciones DTD ni entidades.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new InvalidCfdiXmlException('El XML no está bien formado.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($dom->doctype !== null || $dom->documentElement?->localName !== 'Comprobante') {
            throw new InvalidCfdiXmlException('El archivo no es un CFDI (se esperaba el nodo Comprobante).');
        }

        $xpath = new DOMXPath($dom);
        $rfc = $this->attribute($xpath, '//*[local-name()="Receptor"]/@Rfc');
        $uuid = $this->attribute($xpath, '//*[local-name()="TimbreFiscalDigital"]/@UUID');

        if ($rfc === null) {
            throw new InvalidCfdiXmlException('El XML no trae el RFC del receptor.');
        }

        if ($uuid === null || preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new InvalidCfdiXmlException('El XML no trae un UUID de timbre fiscal válido (¿está timbrado?).');
        }

        return ['rfc' => strtoupper(trim($rfc)), 'uuid' => strtolower($uuid)];
    }

    private function attribute(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMAttr ? trim($node->value) : null;
    }
}
