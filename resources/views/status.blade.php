@extends('statamic::layout')
@section('title', 'Search Service')

@section('content')
    <ui-header title="Search Service" icon="magnifying-glass">
        @if ($settingsUrl)
            <template #actions>
                <ui-button href="{{ $settingsUrl }}" text="Settings" icon="cog"></ui-button>
            </template>
        @endif
    </ui-header>

    <ui-card>
        <div class="flex items-center gap-3">
            <ui-badge color="{{ $ok ? 'green' : 'red' }}">{{ $ok ? 'Connected' : 'Not connected' }}</ui-badge>
            {{-- v-pre: the CP compiles this page as a Vue template, so service-supplied text must not be parsed --}}
            <span v-pre>{{ $message }}</span>
        </div>

        @if ($url)
            <p class="mt-3 text-sm text-gray-500" v-pre>{{ $url }}</p>
        @endif
    </ui-card>
@endsection
