{{--
    Estilos compartidos por el contrato maestro y la adenda de honorarios.

    Viven aparte porque los dos documentos deben verse como emitidos por la
    misma casa: lo que cambia entre ellos es el TEXTO, no la identidad. Con el
    bloque duplicado, el primer ajuste de márgenes en uno los separaba.
--}}
    <style>
        @page { margin: 34px 38px 46px 38px; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            line-height: 1.55;
            color: #081828;
            margin: 0;
        }

        .header {
            border-bottom: 2px solid #314259;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .header .logo { text-align: right; vertical-align: middle; }
        .header .logo img { width: 78px; }
        .header .doc-kind {
            font-size: 8.5px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .header .folio {
            font-size: 9px;
            color: #314259;
            margin-top: 3px;
        }

        h1.contract-title {
            font-size: 13px;
            font-weight: bold;
            color: #081828;
            text-align: center;
            line-height: 1.4;
            margin: 0 0 14px 0;
        }

        p { margin: 0 0 7px 0; text-align: justify; }

        .parties { margin-bottom: 12px; }

        .clauses-title {
            font-size: 11.5px;
            font-weight: bold;
            color: #314259;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 16px 0 10px 0;
        }

        .clause {
            margin-bottom: 11px;
            page-break-inside: avoid;
        }
        .clause-header {
            font-size: 10px;
            font-weight: bold;
            color: #314259;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 4px;
        }
        .clause-term { font-weight: bold; color: #081828; }

        /* Datos vinculados desde la base (empresa, montos, plazos). Se marcan en
           semibold para que en la lectura impresa se distingan del texto plantilla. */
        .bound { font-weight: bold; }

        .closing {
            margin-top: 14px;
            page-break-inside: avoid;
        }

        .signatures {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
            page-break-inside: avoid;
            page-break-before: avoid;
        }
        .signatures td {
            width: 50%;
            vertical-align: bottom;
            text-align: center;
            padding: 0 12px;
        }
        .signatures .party {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 4px;
        }
        .signatures .ink {
            height: 62px;
            vertical-align: bottom;
        }
        .signatures .ink img {
            max-height: 58px;
            max-width: 200px;
        }
        .signatures .rule {
            border-top: 1px solid #081828;
            margin-top: 2px;
            padding-top: 4px;
        }
        .signatures .signer-name { font-size: 9.5px; font-weight: bold; color: #081828; }
        .signatures .signer-title { font-size: 9px; color: #374151; }
        .signatures .signer-org { font-size: 9px; color: #6b7280; }

        .evidence {
            margin-top: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 9px 11px;
            page-break-inside: avoid;
        }
        .evidence-title {
            font-size: 9px;
            font-weight: bold;
            color: #314259;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 5px;
        }
        .evidence table { width: 100%; border-collapse: collapse; }
        .evidence td {
            font-size: 8.5px;
            color: #374151;
            padding: 1.5px 0;
            vertical-align: top;
        }
        .evidence td.k { width: 130px; color: #6b7280; }
        .evidence .note {
            font-size: 8px;
            color: #6b7280;
            margin-top: 6px;
            line-height: 1.45;
            text-align: justify;
        }

        .footer {
            position: fixed;
            bottom: -30px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7.5px;
            color: #9ca3af;
        }
    </style>
