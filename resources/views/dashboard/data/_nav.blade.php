<div class="card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills">
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('dashboard.data.index') ? 'active' : '' }}" href="{{ route('dashboard.data.index') }}">Import & export</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('dashboard.data.imports.*') ? 'active' : '' }}" href="{{ route('dashboard.data.imports.index') }}">Import history</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->routeIs('dashboard.data.exports.*') ? 'active' : '' }}" href="{{ route('dashboard.data.exports.index') }}">Export history</a></li>
        </ul>
    </div>
</div>
