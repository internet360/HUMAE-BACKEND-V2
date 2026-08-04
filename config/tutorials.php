<?php

declare(strict_types=1);

// Fase 16 §5.1 — un tutorial de bienvenida por rol, resuelto en el home de
// cada uno. `video: null` apaga la opción de video en el modal: el frontend
// ofrece únicamente el recorrido guiado mientras el valor sea null. Publicar
// un video es cambiar null por la ruta servida desde /storage/tutorials/...,
// sin migración ni deploy de frontend. Subir `version` re-dispara el
// tutorial para todo usuario que ya lo resolvió en una versión anterior —
// TutorialService::present() es el único lugar que evalúa esta regla.
return [
    'candidate_home' => ['version' => 1, 'video' => null],
    'recruiter_home' => ['version' => 1, 'video' => null],
    'company_home' => ['version' => 1, 'video' => null],
];
