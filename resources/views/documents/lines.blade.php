@foreach ($lines as $line)
    @if ($line['type'] === 'name')
        <h1>{{ $line['text'] }}</h1>
    @elseif ($line['type'] === 'heading')
        <h2>{{ $line['text'] }}</h2>
    @elseif ($line['type'] === 'space')
        <div class="space"></div>
    @else
        <p class="{{ $line['type'] }}">{{ $line['text'] }}</p>
    @endif
@endforeach
