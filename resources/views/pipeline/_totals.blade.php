{{-- Pipeline column counts and totals, fetched by the kanban board after a drag (PipelineController). --}}
@foreach ($columns as $column)
    <x-money :totals="$column['totals']" empty="" data-column="{{ $column['status']->id }}" data-count="{{ $column['count'] }}" />
@endforeach
