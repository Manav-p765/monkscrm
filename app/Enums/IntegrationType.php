<?php

namespace App\Enums;

/**
 * Where an integration's leads come from. Website covers any form that can POST: WordPress plugins,
 * Next.js/Node servers, plain HTML, Zapier/Make (LinkedIn Lead Gen goes through those for now).
 */
enum IntegrationType: string
{
    case Website = 'website';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website form',
        };
    }
}
