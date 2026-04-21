<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentType: string
{
    case CvPdfExterno = 'cv_pdf_externo';
    case Foto = 'foto';
    case Certificado = 'certificado';
    case Otro = 'otro';
}
