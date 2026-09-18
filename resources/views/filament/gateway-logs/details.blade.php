<div style="display: grid; gap: 0.75rem; font-size: 0.875rem;">
    <dl style="display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem;">
        <dt style="font-weight: 600;">Time</dt><dd>{{ $record->created_at->toDateTimeString() }} UTC</dd>
        <dt style="font-weight: 600;">Level</dt><dd>{{ $record->level }}</dd>
        <dt style="font-weight: 600;">Category</dt><dd>{{ $record->category }}</dd>
        <dt style="font-weight: 600;">Connection</dt><dd>{{ $record->connection?->name ?? '—' }}</dd>
        @if ($record->duration_ms !== null)
            <dt style="font-weight: 600;">Duration</dt><dd>{{ $record->duration_ms }} ms</dd>
        @endif
    </dl>
    <pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.75rem; padding: 0.75rem; border-radius: 0.5rem; background: rgba(128, 128, 128, 0.12); max-height: 60vh; overflow: auto;">{{ json_encode($record->context ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
</div>
