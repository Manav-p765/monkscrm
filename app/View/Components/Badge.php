<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Prototype badges.
 * - Status/tag badge: `color="#hex"` from the database. Palette colours (config/crm.php) render with
 *   the prototype's exact classes (tinted background, border, dot). Any other hex falls back to a
 *   colour-mix of that hex, via one nonce'd <style> rule per colour (style="" is blocked by our CSP).
 * - Fixed-meaning chip: `tone` = neutral | red | amber | green | blue | indigo (e.g. Overdue, Due today).
 */
class Badge extends Component
{
    /** Prototype chip classes ("Overdue", "Due Today", "Completed" chips). */
    private const TONES = [
        'neutral' => 'bg-slate-100 text-slate-700 border-slate-200',
        'red' => 'bg-rose-50 text-rose-700 border-rose-200',
        'amber' => 'bg-amber-50 text-amber-700 border-amber-200',
        'green' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'blue' => 'bg-blue-50 text-blue-700 border-blue-200',
        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
    ];

    public ?string $hex;

    /** @var array{badge: string, dot: string}|null */
    public ?array $palette = null;

    public string $colorClass = '';

    public bool $emitStyle = false;

    public function __construct(?string $color = null, public string $tone = 'neutral', public ?bool $dot = null)
    {
        // Only a strict hex value may reach the stylesheet; anything else falls back to the tone.
        $this->hex = $color && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? strtolower($color) : null;

        if ($this->hex) {
            $this->palette = collect(config('crm.palette'))->first(fn (array $c) => $c['hex'] === $this->hex);
            $this->dot ??= true;

            if (! $this->palette) {
                $this->colorClass = 'badge-'.ltrim($this->hex, '#');
                $emitted = request()->attributes->get('badge_colors', []);
                $this->emitStyle = ! in_array($this->colorClass, $emitted, true);
                request()->attributes->set('badge_colors', [...$emitted, $this->colorClass]);
            }
        }

        $this->dot ??= false;

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
