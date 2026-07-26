<p><strong>{{ $alert->name }}</strong> is now <strong>{{ $event->state->value }}</strong>.</p>

<ul>
    <li>Type: {{ $alert->type->value }}</li>
    <li>Measured: {{ $event->measured_value }} line(s) in the last {{ $alert->config['window_minutes'] }} minute(s)</li>
    @if ($alert->config['threshold'] !== null)
        <li>Threshold: {{ $alert->config['threshold'] }}</li>
    @endif
    @if (! empty($alert->config['search']))
        <li>Filter: "{{ $alert->config['search'] }}"</li>
    @endif
</ul>

<p><a href="{{ $deepLink }}">View matching logs</a></p>
