<?php

declare(strict_types=1);

namespace App\Enums;

enum CandidateState: string
{
    case RegistroIncompleto = 'registro_incompleto';
    case PendientePago = 'pendiente_pago';
    case Activo = 'activo';
    case MembresiaVencida = 'membresia_vencida';
    case EnProceso = 'en_proceso';
    case PresentadoEmpresa = 'presentado_empresa';
    case Entrevistado = 'entrevistado';
    case Contratado = 'contratado';
    case Inactivo = 'inactivo';

    public function label(): string
    {
        return match ($this) {
            self::RegistroIncompleto => 'Registro incompleto',
            self::PendientePago => 'Pendiente de pago',
            self::Activo => 'Activo',
            self::MembresiaVencida => 'Membresía vencida',
            self::EnProceso => 'En proceso',
            self::PresentadoEmpresa => 'Presentado a empresa',
            self::Entrevistado => 'Entrevistado',
            self::Contratado => 'Contratado',
            self::Inactivo => 'Inactivo',
        };
    }
}
