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
    private const CFDI_NS = 'http://www.sat.gob.mx/cfd/4';

    private const TFD_NS = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array{rfc: string, uuid: string} rfc uppercased, uuid lowercased
     *
     * @throws InvalidCfdiXmlException
     */
    public function inspect(string $xml): array
    {
        // The DTD pre-filter below works on raw bytes, so anything that is not
        // plain ASCII-compatible text (UTF-16/32 with a BOM, NUL bytes) would
        // slip past it and still be decoded by libxml.
        if (str_contains($xml, "\0") || preg_match('/^(\xFE\xFF|\xFF\xFE|\x00\x00\xFE\xFF)/', $xml) === 1) {
            throw new InvalidCfdiXmlException('El XML debe estar codificado en UTF-8.');
        }

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

        $root = $dom->documentElement;

        if ($dom->doctype !== null
            || $root?->localName !== 'Comprobante'
            || $root->namespaceURI !== self::CFDI_NS
            || $root->getAttribute('Version') !== '4.0') {
            throw new InvalidCfdiXmlException('El archivo no es un CFDI 4.0 (se esperaba el nodo cfdi:Comprobante, versión 4.0).');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cfdi', self::CFDI_NS);
        $xpath->registerNamespace('tfd', self::TFD_NS);

        $rfc = $this->attribute($xpath, '/cfdi:Comprobante/cfdi:Receptor/@Rfc');
        $uuid = $this->attribute($xpath, '/cfdi:Comprobante/cfdi:Complemento/tfd:TimbreFiscalDigital/@UUID');

        if ($rfc === null) {
            throw new InvalidCfdiXmlException('El XML no trae el RFC del receptor.');
        }

        if ($uuid === null || preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new InvalidCfdiXmlException('El XML no trae un UUID de timbre fiscal válido (¿está timbrado?).');
        }

        return ['rfc' => mb_strtoupper(trim($rfc)), 'uuid' => strtolower($uuid)];
    }

    /** Null unless the query matches exactly one attribute (an ambiguous document is not trusted). */
    private function attribute(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length !== 1) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMAttr ? trim($node->value) : null;
    }
}
