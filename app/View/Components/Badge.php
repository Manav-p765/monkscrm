<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Small coloured label. Colour carries meaning only:
 * - status badges pass the status colour from the database (`color="#2563eb"`)
 * - fixed meanings pass a tone: neutral, red (urgent/overdue), amber (high), green (success), blue
 *
 * Database colours cannot go in style="" attributes (blocked by our CSP), so each distinct
 * colour gets one tiny nonce'd <style> rule, emitted the first time it is used in a request.
 */
class Badge extends Component
{
    private const TONES = [
        'neutral' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'red' => 'bg-red-50 text-red-800 ring-red-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'green' => 'bg-green-50 text-green-800 ring-green-200',
        'blue' => 'bg-blue-50 text-blue-800 ring-blue-200',
    ];

    public ?string $hex;

    public string $colorClass = '';

    public bool $emitStyle = false;

    public function __construct(?string $color = null, public string $tone = 'neutral')
    {
        // Only a strict hex value may reach the stylesheet; anything else falls back to the tone.
        $this->hex = $color && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? strtolower($color) : null;

        if ($this->hex) {
            $this->colorClass = 'badge-'.ltrim($this->hex, '#');
            $emitted = request()->attributes->get('badge_colors', []);
            $this->emitStyle = ! in_array($this->colorClass, $emitted, true);
            request()->attributes->set('badge_colors', [...$emitted, $this->colorClass]);
        }

        if (! array_key_exists($this->tone, self::TONES)) {
            $this->tone = 'neutral';
        }
    }

    public function toneClasses(): string
    {
        return self::TONES[$this->tone];
    }

    public function render(): View
    {
        return view('components.badge');
    }
}
