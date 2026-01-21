@php
    $layout = \Illuminate\Support\Facades\View::exists('settings.layout')
        ? 'settings.layout'
        : (\Illuminate\Support\Facades\View::exists('layouts.settings') ? 'layouts.settings' : 'layouts.app');
@endphp

@extends($layout)

@section('content')
    <div class="container small">
        <h1 class="mb-m">OpenWebUI Sync</h1>

        @if(session('success'))
            <div class="notification success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="notification error">{{ session('error') }}</div>
        @endif
        @if(session('warning'))
            <div class="notification warning">{{ session('warning') }}</div>
        @endif

        <form method="post" action="{{ route('openwebui.settings.update') }}" class="form">
            @csrf

            <div class="form-group">
                <label for="enabled">Enable sync</label>
                <input type="checkbox" id="enabled" name="enabled" value="1" {{ $enabled ? 'checked' : '' }}>
            </div>

            <div class="form-group">
                <label for="instance_name">Instance name</label>
                <input type="text" id="instance_name" name="instance_name" value="{{ $instance_name }}" class="input">
            </div>

            <div class="form-group">
                <label for="base_url">OpenWebUI base URL</label>
                <input type="url" id="base_url" name="base_url" value="{{ $base_url }}" class="input">
            </div>

            <div class="form-group">
                <label for="api_key">API key</label>
                <input type="password" id="api_key" name="api_key" value="" placeholder="{{ $has_api_key ? 'Saved (enter to replace)' : 'Not set' }}" class="input">
            </div>

            <div class="grid half">
                <div class="form-group">
                    <label for="timeout">Timeout (seconds)</label>
                    <input type="number" id="timeout" name="timeout" value="{{ $timeout }}" min="1" max="120" class="input">
                </div>
                <div class="form-group">
                    <label for="verify_tls">Verify TLS</label>
                    <input type="checkbox" id="verify_tls" name="verify_tls" value="1" {{ $verify_tls ? 'checked' : '' }}>
                </div>
            </div>

            <div class="grid half">
                <div class="form-group">
                    <label for="polling_enabled">Polling enabled</label>
                    <input type="checkbox" id="polling_enabled" name="polling_enabled" value="1" {{ $polling_enabled ? 'checked' : '' }}>
                </div>
                <div class="form-group">
                    <label for="polling_interval_minutes">Polling interval (minutes)</label>
                    <input type="number" id="polling_interval_minutes" name="polling_interval_minutes" value="{{ $polling_interval_minutes }}" min="1" max="120" class="input">
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="button primary">Save settings</button>
            </div>
        </form>

        <form method="post" action="{{ route('openwebui.settings.test') }}" class="form mt-m">
            @csrf
            <button type="submit" class="button">Test connection</button>
        </form>

        <h2 class="mt-l">Sync actions</h2>

        <form method="post" action="{{ route('openwebui.settings.sync_all') }}" class="form mb-s">
            @csrf
            <button type="submit" class="button">Sync all books</button>
        </form>

        <form method="post" action="{{ route('openwebui.settings.sync_book') }}" class="form mb-s">
            @csrf
            <div class="form-group">
                <label for="sync_book_id">Sync book</label>
                <select id="sync_book_id" name="book_id" class="input">
                    <option value="">Select book</option>
                    @foreach($books as $book)
                        <option value="{{ $book->id }}">{{ $book->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="button">Sync selected book</button>
        </form>

        <form method="post" action="{{ route('openwebui.settings.rebuild_book') }}" class="form mb-l">
            @csrf
            <div class="form-group">
                <label for="rebuild_book_id">Rebuild book</label>
                <select id="rebuild_book_id" name="book_id" class="input">
                    <option value="">Select book</option>
                    @foreach($books as $book)
                        <option value="{{ $book->id }}">{{ $book->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="button">Rebuild selected book</button>
        </form>

        <h2 class="mt-l">Queue status</h2>
        <p>Pending: {{ $queue_pending }} | Failed: {{ $queue_failed }}</p>

        @if($recent_failures->count() > 0)
            <h3 class="mt-m">Recent failures</h3>
            <ul class="list">
                @foreach($recent_failures as $failure)
                    <li>
                        <strong>#{{ $failure->id }}</strong> {{ $failure->task_type }} - {{ $failure->last_error }}
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
